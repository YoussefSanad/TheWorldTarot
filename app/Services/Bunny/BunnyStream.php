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
 * Five things: create a video, upload its bytes, ask what became of it, sign a
 * URL somebody may watch, and delete one. Nothing else talks to Bunny, so when
 * their API changes there is one file to fix.
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
     * A video, gone from their library for good.
     *
     * **A 404 counts as done.** The only caller is a queued job, the queue
     * delivers at least once, and "the video is not there" is precisely the
     * outcome being asked for. Treating it as a failure would make every
     * redelivery fail and leave a job in the dead letter queue describing work
     * that succeeded.
     *
     * Nothing else here is destructive, and nothing else here needs to be. This
     * is called when Jennifer removes a film and when a replacement takes over
     * from the one it replaced.
     */
    public function deleteVideo(string $videoId): void
    {
        $response = $this->request()->delete($this->endpoint("videos/{$videoId}"));

        if ($response->notFound()) {
            return;
        }

        $this->failIfRefused($response, 'delete a video');
    }

    /**
     * A URL one person may watch, until it expires.
     *
     * **Proved against the real library on 3 August 2026**, master manifest,
     * rendition and segment. The version before it was never proved and never
     * worked, so playback was broken from M4 until that date while the tests
     * stayed green. See `docs/plans/bunny-playback-fix-plan.md`.
     *
     * Three things have to be right at once, and getting any one wrong gives an
     * identical 403 with no clue which:
     *
     * - **The token is scoped to the directory, not the file.** HLS resolves
     *   renditions and segments relative to the manifest, so a file-scoped token
     *   loads `playlist.m3u8` and then 403s on everything it points at. That
     *   failure looks exactly like success from `curl`
     * - **The viewer's IP is part of the hash**, because the zone has
     *   `ZoneSecurityIncludeHashRemoteIP` on. A URL signed for one person does
     *   not work for anyone else, which is the point, and it also means signing
     *   with a proxy's address locks everybody out
     * - **`token_path` appears twice, encoded differently.** Raw inside the
     *   hash, percent-encoded in the query. Swapping them is a silent 403
     *
     * The zone additionally refuses requests with no `Referer`, so one of these
     * cannot be opened in a browser tab directly. It only works from a page.
     */
    public function playbackUrl(string $videoId, CarbonInterface $expiresAt, string $viewerIp): string
    {
        $hostname = $this->configured('cdn_hostname');
        $expires = $expiresAt->getTimestamp();

        // Everything belonging to this video, so the renditions and segments the
        // manifest names inherit the token instead of each needing their own.
        $directory = "/{$videoId}/";

        $token = $this->urlSafeBase64(hash(
            'sha256',
            $this->configured('token_key').$directory.$expires.$viewerIp.'token_path='.$directory,
            true,
        ));

        return "https://{$hostname}/bcdn_token=".rawurlencode($token)
            ."&expires={$expires}"
            .'&token_path='.rawurlencode($directory)
            ."{$directory}playlist.m3u8";
    }

    /**
     * Base64 as a URL wants it: no padding, and none of the two characters that
     * would otherwise have to be escaped.
     */
    private function urlSafeBase64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
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
