<?php

namespace App\Enums;

/**
 * Where a card's Living Tarot video is in its journey to being watchable.
 *
 * A real column driving real behaviour, not a calculation, per
 * .claude/database.md. Nothing else here is stored rather than worked out, and
 * the reason this is different is that only Bunny knows the answer: the
 * application cannot look at a row and decide whether a transcode finished.
 *
 * No video at all is the absence of a status, not a case here.
 */
enum VideoStatus: string
{
    /** The master is uploaded and the job has not sent it to Bunny yet. */
    case Pending = 'pending';

    /** Bunny has it and is transcoding. */
    case Processing = 'processing';

    /** Watchable. */
    case Ready = 'ready';

    /** Bunny refused it, or the upload never completed. */
    case Failed = 'failed';

    /**
     * What Jennifer sees, since none of the words above are hers.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to be sent',
            self::Processing => 'Being prepared',
            self::Ready => 'Ready to watch',
            self::Failed => 'Something went wrong',
        };
    }

    /**
     * The colour of the badge in the panel. Status has to be obvious at a
     * glance, per .claude/filament.md.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Pending, self::Processing => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
