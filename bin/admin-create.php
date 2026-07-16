<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$email = trim((string) (getenv('ADMIN_CREATE_EMAIL') ?: ''));
$passwordFile = (string) (getenv('ADMIN_CREATE_PASSWORD_FILE') ?: '');
if ($email === '') {
    fwrite(STDOUT, 'Admin e-mail: ');
    $email = trim((string) fgets(STDIN));
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Invalid admin e-mail address.\n");
    exit(1);
}

if ($passwordFile === '' || !is_readable($passwordFile)) {
    fwrite(STDERR, "Set ADMIN_CREATE_PASSWORD_FILE to a readable, temporary file containing the password. Do not pass passwords as command arguments.\n");
    exit(1);
}
$password = rtrim((string) file_get_contents($passwordFile), "\r\n");
if (strlen($password) < 12) {
    fwrite(STDERR, "The admin password must contain at least 12 characters.\n");
    exit(1);
}

$pdo = ConnectionFactory::create(require dirname(__DIR__) . '/config/database.php');
$statement = $pdo->prepare('INSERT INTO admins (email, password_hash, name, is_active) VALUES (:email, :password_hash, :name, TRUE)');
try {
    $statement->execute([
        'email' => mb_strtolower($email, 'UTF-8'),
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'name' => strstr($email, '@', true) ?: 'Admin',
    ]);
    fwrite(STDOUT, "Admin account created.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, $exception->getCode() === '23000' ? "Admin account already exists.\n" : "Admin account could not be created.\n");
    exit(1);
} finally {
    $password = str_repeat("\0", strlen($password));
}
