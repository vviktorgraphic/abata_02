<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Application\Authentication\AdminCredentialRepository;
use App\Application\Authentication\AuthenticationService;
use App\Domain\Authentication\AdminCredential;
use App\Domain\Authentication\EmailNormalizer;
use App\Domain\Authentication\NativePasswordVerifier;
use App\Domain\Authentication\PasswordVerifier;
use PHPUnit\Framework\TestCase;

final class AuthenticationServiceTest extends TestCase
{
    public function testCorrectPasswordIsAcceptedAndEmailIsNormalized(): void
    {
        $repository = new InMemoryAdminCredentialRepository(
            new AdminCredential(7, 'admin@example.com', password_hash('correct horse', PASSWORD_DEFAULT), true),
        );

        $result = $this->service($repository)->checkCredentials('  ADMIN@Example.COM ', 'correct horse');

        self::assertTrue($result->accepted);
        self::assertSame(7, $result->adminId);
        self::assertSame('admin@example.com', $result->normalizedEmail);
        self::assertSame('admin@example.com', $repository->lastLookup);
    }

    public function testWrongPasswordIsRejectedWithoutIdentityDetails(): void
    {
        $repository = new InMemoryAdminCredentialRepository(
            new AdminCredential(7, 'admin@example.com', password_hash('correct horse', PASSWORD_DEFAULT), true),
        );

        $result = $this->service($repository)->checkCredentials('admin@example.com', 'wrong');

        self::assertFalse($result->accepted);
        self::assertNull($result->adminId);
        self::assertNull($result->normalizedEmail);
    }

    public function testUnknownAndInactiveAccountsUseTheSameGenericRejection(): void
    {
        $inactive = new InMemoryAdminCredentialRepository(
            new AdminCredential(7, 'admin@example.com', password_hash('correct horse', PASSWORD_DEFAULT), false),
        );

        $inactiveResult = $this->service($inactive)->checkCredentials('admin@example.com', 'correct horse');
        $unknownResult = $this->service(new InMemoryAdminCredentialRepository())->checkCredentials('missing@example.com', 'anything');

        self::assertEquals($unknownResult, $inactiveResult);
        self::assertFalse($unknownResult->accepted);
    }

    public function testUnknownAccountStillPerformsPasswordVerificationWithDummyHash(): void
    {
        $passwords = new RecordingPasswordVerifier();
        $service = new AuthenticationService(
            new InMemoryAdminCredentialRepository(),
            $passwords,
            new EmailNormalizer(),
            password_hash('dummy-only-value', PASSWORD_DEFAULT),
        );

        $service->checkCredentials('missing@example.com', 'candidate');

        self::assertSame(1, $passwords->verificationCount);
    }

    public function testInvalidEmailIsRejectedWithoutRepositoryLookupButStillVerifiesDummyHash(): void
    {
        $repository = new InMemoryAdminCredentialRepository();
        $passwords = new RecordingPasswordVerifier();
        $service = new AuthenticationService(
            $repository,
            $passwords,
            new EmailNormalizer(),
            password_hash('dummy-only-value', PASSWORD_DEFAULT),
        );

        $result = $service->checkCredentials(str_repeat('a', 191), 'candidate');

        self::assertFalse($result->accepted);
        self::assertNull($repository->lastLookup);
        self::assertSame(1, $passwords->verificationCount);
    }

    private function service(AdminCredentialRepository $repository): AuthenticationService
    {
        return new AuthenticationService(
            $repository,
            new NativePasswordVerifier(),
            new EmailNormalizer(),
            password_hash('dummy-only-value', PASSWORD_DEFAULT),
        );
    }
}

final class InMemoryAdminCredentialRepository implements AdminCredentialRepository
{
    public ?string $lastLookup = null;

    public function __construct(private readonly ?AdminCredential $admin = null)
    {
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?AdminCredential
    {
        $this->lastLookup = $normalizedEmail;

        return $this->admin?->email === $normalizedEmail ? $this->admin : null;
    }

    public function updatePasswordHash(int $adminId, string $passwordHash): void
    {
    }
}

final class RecordingPasswordVerifier implements PasswordVerifier
{
    public int $verificationCount = 0;

    public function verify(string $plainPassword, string $passwordHash): bool
    {
        ++$this->verificationCount;

        return false;
    }

    public function needsRehash(string $passwordHash): bool
    {
        return false;
    }

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }
}
