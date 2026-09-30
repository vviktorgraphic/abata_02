<?php

declare(strict_types=1);

namespace App\Bootstrap;

/** Loads the project .env without overriding variables supplied by the process. */
final class EnvironmentBootstrap
{
    public static function load(string $projectRoot): void
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }
            $value = trim($value);
            $processValue = getenv($name);
            if ($processValue !== false) {
                $_ENV[$name] = (string) $processValue;
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}
