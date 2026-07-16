<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Application\Audit\AuditMetadataSanitizer;
use App\Application\Audit\UnsafeAuditMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditMetadataSanitizerTest extends TestCase
{
    public function testAllowsOnlyStructuredNonPiiMetadata(): void
    {
        $metadata = (new AuditMetadataSanitizer())->sanitize([
            'reason_code' => 'invalid_credentials',
            'attempt_count' => 3,
            'auth_stage' => 'password',
        ]);
        self::assertSame('invalid_credentials', $metadata->values['reason_code']);
    }

    #[DataProvider('unsafeMetadata')]
    public function testRejectsSecretsAndRawPii(array $metadata): void
    {
        $this->expectException(UnsafeAuditMetadata::class);
        (new AuditMetadataSanitizer())->sanitize($metadata);
    }

    public static function unsafeMetadata(): iterable
    {
        yield 'password' => [['password' => 'canary-secret']];
        yield 'two factor code' => [['code' => '123456']];
        yield 'token' => [['session_token' => 'canary-token']];
        yield 'csrf' => [['csrf' => 'canary-csrf']];
        yield 'smtp secret' => [['smtp_password' => 'canary-smtp']];
        yield 'email value' => [['target_id' => 'admin@example.test']];
        yield 'ip value' => [['target_id' => '192.0.2.1']];
    }
}
