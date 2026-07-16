<?php

declare(strict_types=1);

namespace App\Application\Audit;

/** Builds metadata from a deliberately small, non-PII allowlist. */
final class AuditMetadataSanitizer
{
    private const ALLOWED_KEYS = [
        'correlation_id', 'reason_code', 'target_type', 'target_id',
        'attempt_count', 'limit', 'window_seconds', 'lockout_seconds',
        'transport', 'auth_stage',
    ];

    /** @param array<string, mixed> $metadata */
    public function sanitize(array $metadata): AuditMetadata
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                throw new UnsafeAuditMetadata(sprintf('Audit metadata key "%s" is not allowed.', $key));
            }
            if (!is_scalar($value) && $value !== null) {
                throw new UnsafeAuditMetadata('Audit metadata values must be scalar.');
            }
            if (is_string($value) && ($value === '' || strlen($value) > 128 || str_contains($value, '@') || filter_var($value, FILTER_VALIDATE_IP))) {
                throw new UnsafeAuditMetadata('Audit metadata contains empty, oversized, or raw PII data.');
            }
            $safe[$key] = $value;
        }
        return new AuditMetadata($safe);
    }
}
