<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/** Detects optional mysqldump flags without opening a database connection. */
final class MysqldumpCapabilities
{
    public static function supportsSetGtidPurgedFromHelp(string $helpOutput): bool
    {
        return preg_match('/(?:^|\s)--set-gtid-purged(?:[=\s\[]|$)/mi', $helpOutput) === 1;
    }

    public static function supportsSetGtidPurged(string $binary, string $workingDirectory): bool
    {
        $process = proc_open([$binary, '--help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new \RuntimeException('mysqldump capability detection could not start the configured binary.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException('mysqldump capability detection failed.');
        }
        return self::supportsSetGtidPurged((string) $stdout . "\n" . (string) $stderr);
    }
}
