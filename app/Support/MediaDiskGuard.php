<?php

namespace App\Support;

final class MediaDiskGuard
{
    public function refusalFor(string $disk): ?string
    {
        $driver = config("filesystems.disks.{$disk}.driver");
        $bucket = config("filesystems.disks.{$disk}.bucket");

        // Before touching the disk, because building an s3 disk with no bucket
        // throws a type error from inside Flysystem, and a stack trace is a
        // poor way to be told an environment variable is missing.
        if ($driver === 's3' && blank($bucket)) {
            return 'This environment has no bucket configured, so uploads cannot work at all.';
        }

        // The whole point of the command. A local driver anywhere but a
        // developer's machine means uploads are being written to a filesystem
        // that does not survive a deploy.
        if ($driver === 'local' && ! app()->environment('local', 'testing')) {
            return 'These files are going to this machine\'s own filesystem, which is wiped on every deploy. '
                .'This environment needs a bucket attached for them.';
        }

        return null;
    }
}
