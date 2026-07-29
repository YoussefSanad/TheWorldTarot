<?php

namespace App\Services\Bunny;

use App\Enums\VideoStatus;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Everything this application knows about Bunny Stream.
 *
 * Four things: create a video, upload its bytes, ask what became of it, and sign
 * a URL somebody may watch. Nothing else talks to Bunny, so when their API
 * changes there is one file to fix.
 */
class BunnyStream
{
    /**
     * A new video, created before any bytes are sent.
     *
     * Two calls rather than one, deliberately. The ingestion job stores this id
     * before uploading, so a job redelivered by the queue while still running
     * uploads to the same video instead of leaving a second copy in the library.
     *
     * @return string Bunny's id for the video
     */
    public function createVideo(string $title): string
    {
        $response = $this->request()
            ->post($this->endpoint('videos'), ['title' => $title]);

        $this->failIfRefused($response, 'create a video');

        $id = $response->json('guid');

        if (! is_string($id) || $id === '') {
            throw BunnyRequestFailed::for('create a video', $response->status(), 'it returned no id');
        }

        return $id;
    }

    /**
     * The bytes, streamed rather than loaded into memory.
     *
     * @param  resource  $contents
     */
    public function uploadVideo(string $videoId, $contents): void
    {
        $response = $this->request()
            ->timeout(config('services.bunny.upload_timeout'))
            ->withBody($contents, 'application/octet-stream')
            ->put($this->endpoint("videos/{$videoId}"));

        $this->failIfRefused($response, 'accept a video');
    }

    /**
     * What Bunny says has become of a video.
     *
     * This is what makes the transcode callback safe to trust: the callback is
     * treated as a hint that something changed, and the answer comes from here,
     * so a forged one achieves nothing.
     */
    public function fetchStatus(string $videoId): VideoStatus
    {
        $response = $this->request()->get($this->endpoint("videos/{$videoId}"));

        $this->failIfRefused($response, 'report on a video');

        return $this->statusFrom($response->json('status'));
    }

    /**
     * A URL somebody may watch, until it expires.
     *
     * **The signing scheme here is the one Bunny documents and it has never been
     * proved against a real library.** If playback answers 403 while the expiry
     * is plainly in the future, this method is where the fault is, and nothing
     * else has to change. Verified on staging before it is trusted.
     */
    public function playbackUrl(string $videoId, CarbonInterface $expiresAt): string
    {
        $hostname = $this->configured('cdn_hostname');
        $expires = $expiresAt->getTimestamp();

        // Signed over the video and the expiry together, so a token cannot be
        // moved to another video or have its deadline edited.
        $token = hash('sha256', $this->configured('token_key').$videoId.$expires);

        return "https://{$hostname}/{$videoId}/playlist.m3u8?token={$token}&expires={$expires}";
    }

    /**
     * Bunny answers with a number. Only two of them mean anything definite.
     *
     * Anything unrecognised counts as still working, never as ready and never as
     * failed, because guessing "ready" would put an unfinished video in front of
     * a paying customer and guessing "failed" would throw away a good one. New
     * values are logged so an unfamiliar one is visible rather than silent.
     */
    private function statusFrom(mixed $status): VideoStatus
    {
        return match ((int) $status) {
            0, 1, 2, 3 => VideoStatus::Processing,
            4 => VideoStatus::Ready,
            5, 6 => VideoStatus::Failed,
            default => $this->unrecognised($status),
        };
    }

    private function unrecognised(mixed $status): VideoStatus
    {
        Log::warning('Bunny reported a video status this application does not know.', [
            'status' => $status,
        ]);

        return VideoStatus::Processing;
    }

    private function request()
    {
        return Http::withHeaders([
            'AccessKey' => $this->configured('key'),
            'Accept' => 'application/json',
        ])->acceptJson();
    }

    private function endpoint(string $path): string
    {
        $base = rtrim((string) config('services.bunny.api_url'), '/');

        return "{$base}/library/{$this->configured('library_id')}/{$path}";
    }

    /**
     * A missing credential is a configuration failure, not a request failure.
     *
     * Said in words here, because the alternative is a 401 from Bunny that reads
     * like a revoked key and sends somebody looking in the wrong dashboard.
     */
    private function configured(string $key): string
    {
        $value = config("services.bunny.{$key}");

        if (blank($value)) {
            throw BunnyRequestFailed::misconfigured('BUNNY_'.strtoupper($key === 'key' ? 'api_key' : $key));
        }

        return (string) $value;
    }

    private function failIfRefused(Response $response, string $action): void
    {
        if ($response->failed()) {
            throw BunnyRequestFailed::for($action, $response->status(), $response->body());
        }
    }
}
