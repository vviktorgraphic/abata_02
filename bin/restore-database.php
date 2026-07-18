<?php

declare(strict_types=1);

/**
 * Explicit, checksum-verified restore. Never invoke from an unattended cron job.
 */

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
        throw new RuntimeException('proc_open is required to execute mysql.');
    }
    $database = $required('DB_DATABASE');
    $backupFile = $required('RESTORE_BACKUP_FILE');
    $confirmation = $required('RESTORE_CONFIRM');
    if ($confirmation !== 'RESTORE:' . $database) {
        throw new RuntimeException('Restore confirmation mismatch. Set RESTORE_CONFIRM exactly to RESTORE:<DB_DATABASE>.');
    }
    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
        throw new RuntimeException('DB_DATABASE contains unsupported characters.');
    }
    $backupFile = realpath($backupFile) ?: '';
    if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile)) {
        throw new RuntimeException('RESTORE_BACKUP_FILE must be a readable regular file.');
    }
    $checksumFile = $backupFile . '.sha256';
    if (!is_file($checksumFile) || !is_readable($checksumFile)) {
        throw new RuntimeException('The matching .sha256 checksum file is required.');
    }
    $checksumLine = trim((string) file_get_contents($checksumFile));
    if (!preg_match('/^([a-f0-9]{64})\s+/', $checksumLine, $matches)
        || !hash_equals($matches[1], (string) hash_file('sha256', $backupFile))) {
        throw new RuntimeException('Backup checksum verification failed.');
    }

    $host = $required('DB_HOST');
    $port = $required('DB_PORT');
    $username = $required('DB_USERNAME');
    $password = $required('DB_PASSWORD');
    $binary = getenv('MYSQL_BINARY') ?: 'mysql';
    $optionFile = tempnam(sys_get_temp_dir(), 'mysql-restore-');
    if ($optionFile === false) {
        throw new RuntimeException('Could not create the temporary MySQL option file.');
    }
    register_shutdown_function(static fn () => is_file($optionFile) ? @unlink($optionFile) : null);
    if (!preg_match('/^[1-9][0-9]{0,4}$/', $port) || (int) $port > 65535) {
        throw new RuntimeException('DB_PORT must be an integer between 1 and 65535.');
    }
    $escape = static fn (string $value): string => '"' . str_replace(
        ['\\', '"', "\n", "\r"],
        ['\\\\', '\\"', '\\n', '\\r'],
        $value,
    ) . '"';
    $contents = "[client]\n" . 'host=' . $escape($host) . "\n" . 'port=' . $port . "\n"
        . 'user=' . $escape($username) . "\n" . 'password=' . $escape($password) . "\n";
    if (file_put_contents($optionFile, $contents, LOCK_EX) === false || !chmod($optionFile, 0600)) {
        throw new RuntimeException('Could not secure the temporary MySQL option file.');
    }

    $input = fopen($backupFile, 'rb');
    $process = proc_open([$binary, '--defaults-extra-file=' . $optionFile, $database], [0 => $input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fclose($input);
        throw new RuntimeException('Could not start mysql restore.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($input);
    $exitCode = proc_close($process);
    @unlink($optionFile);
    if ($exitCode !== 0) {
        throw new RuntimeException('mysql restore failed: ' . trim((string) $stderr));
    }
    printf("Restore completed from checksum-verified backup: %s%s", $backupFile, PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $fail('Restore failed: ' . $exception->getMessage());
}
