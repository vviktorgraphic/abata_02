<?php

declare(strict_types=1);

/**
 * Production-compatible MySQL backup entry point.
 * Secrets are read from the environment and passed through a temporary option file.
 */

$root = dirname(__DIR__);

$required = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(sprintf('Required environment variable %s is missing or empty.', $name));
    }

    return trim($value);
};

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
};

try {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open is required to execute mysqldump.');
    }

    $backupDirectory = rtrim($required('BACKUP_DIRECTORY'), DIRECTORY_SEPARATOR);
    $database = $required('DB_DATABASE');
    $host = $required('DB_HOST');
    $port = $required('DB_PORT');
    $username = $required('DB_USERNAME');
    $password = $required('DB_PASSWORD');
    $binary = getenv('MYSQLDUMP_BINARY') ?: 'mysqldump';

    if (!preg_match('/^[1-9][0-9]{0,4}$/', $port) || (int) $port > 65535) {
        throw new RuntimeException('DB_PORT must be an integer between 1 and 65535.');
    }
    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
        throw new RuntimeException('DB_DATABASE contains unsupported characters.');
    }
    if (!is_dir($backupDirectory) || !is_writable($backupDirectory)) {
        throw new RuntimeException('BACKUP_DIRECTORY must be an existing writable directory.');
    }

    $resolvedBackup = realpath($backupDirectory);
    $resolvedRoot = realpath($root);
    if ($resolvedBackup === false || $resolvedRoot === false) {
        throw new RuntimeException('Backup or project directory could not be resolved.');
    }
    $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
    $backupPath = $normalize($resolvedBackup);
    $rootPath = $normalize($resolvedRoot);
    if ($backupPath === $rootPath || str_starts_with($backupPath . '/', $rootPath . '/')) {
        throw new RuntimeException('BACKUP_DIRECTORY must be outside the repository and its public webroot.');
    }

    $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')))->format('Ymd_His');
    $random = bin2hex(random_bytes(4));
    $baseName = sprintf('%s_%s_%s.sql', preg_replace('/[^A-Za-z0-9_-]/', '_', $database), $timestamp, $random);
    $temporaryDump = $resolvedBackup . DIRECTORY_SEPARATOR . '.' . $baseName . '.tmp';
    $finalDump = $resolvedBackup . DIRECTORY_SEPARATOR . $baseName;
    $temporaryChecksum = $temporaryDump . '.sha256';
    $finalChecksum = $finalDump . '.sha256';
    $optionFile = tempnam(sys_get_temp_dir(), 'mysql-backup-');
    if ($optionFile === false) {
        throw new RuntimeException('Could not create the temporary MySQL option file.');
    }

    $cleanup = static function () use (&$optionFile, &$temporaryDump, &$temporaryChecksum): void {
        foreach ([$optionFile, $temporaryDump, $temporaryChecksum] as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    };
    register_shutdown_function($cleanup);

    $escapeOption = static fn (string $value): string => '"' . str_replace(
        ['\\', '"', "\n", "\r"],
        ['\\\\', '\\"', '\\n', '\\r'],
        $value,
    ) . '"';
    $optionContents = "[client]\n"
        . 'host=' . $escapeOption($host) . "\n"
        . 'port=' . $port . "\n"
        . 'user=' . $escapeOption($username) . "\n"
        . 'password=' . $escapeOption($password) . "\n";
    if (file_put_contents($optionFile, $optionContents, LOCK_EX) === false || !chmod($optionFile, 0600)) {
        throw new RuntimeException('Could not secure the temporary MySQL option file.');
    }

    $output = fopen($temporaryDump, 'xb');
    if ($output === false || !chmod($temporaryDump, 0600)) {
        throw new RuntimeException('Could not create a restrictive temporary backup file.');
    }

    $command = [
        $binary,
        '--defaults-extra-file=' . $optionFile,
        '--single-transaction',
        '--quick',
        '--triggers',
        '--no-tablespaces',
        '--set-gtid-purged=OFF',
        '--default-character-set=utf8mb4',
        $database,
    ];
    $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $output, 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        fclose($output);
        throw new RuntimeException('Could not start mysqldump.');
    }
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    fclose($output);
    @unlink($optionFile);

    if ($exitCode !== 0) {
        throw new RuntimeException('mysqldump failed: ' . trim((string) $errorOutput));
    }
    if (!is_file($temporaryDump) || filesize($temporaryDump) === 0) {
        throw new RuntimeException('mysqldump produced an empty backup.');
    }

    $checksum = hash_file('sha256', $temporaryDump);
    if (!is_string($checksum)) {
        throw new RuntimeException('Could not calculate backup checksum.');
    }
    if (file_put_contents($temporaryChecksum, $checksum . '  ' . $baseName . PHP_EOL, LOCK_EX) === false
        || !chmod($temporaryChecksum, 0600)) {
        throw new RuntimeException('Could not write the backup checksum.');
    }
    if (!rename($temporaryChecksum, $finalChecksum)) {
        throw new RuntimeException('Could not finalize the backup checksum.');
    }
    if (!rename($temporaryDump, $finalDump)) {
        @unlink($finalChecksum);
        throw new RuntimeException('Could not atomically finalize the backup.');
    }

    printf("Backup completed: %s%sSHA-256: %s%s", $finalDump, PHP_EOL, $checksum, PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $fail('Backup failed: ' . $exception->getMessage());
}
