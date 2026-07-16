<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingCreateWorkflow;
use App\Application\Booking\IdempotencyConflict;
use App\Application\Pricing\PricingConfigurationException;
use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Booking\BookingValidationFailed;
use App\Http\BookingApiResponse;
use App\Http\BookingRequestRateLimiter;
use JsonException;
use Throwable;

final readonly class BookingCreateController
{
    /** @param list<string> $trustedOrigins */
    public function __construct(
        private BookingCreateRequestValidator $validator,
        private BookingCreateWorkflow $workflow,
        private BookingRequestRateLimiter $rateLimiter,
        private array $trustedOrigins,
        private int $maximumBodyBytes = 32768,
        private ?AuditLog $auditLog = null,
    ) {
    }

    /** @param array<string, string> $headers */
    public function create(string $body, array $headers, string $clientAddress): BookingApiResponse
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            return $this->error(415, 'A kérés Content-Type értéke application/json legyen.');
        }
        if (strlen($body) > $this->maximumBodyBytes
            || (isset($headers['content-length']) && (int) $headers['content-length'] > $this->maximumBodyBytes)) {
            return $this->error(413, 'A kérés túl nagy.');
        }
        if (!$this->originAllowed($headers)) {
            return $this->error(403, 'A kérés eredete nem engedélyezett.');
        }
        if (!$this->rateLimiter->allow($clientAddress !== '' ? $clientAddress : 'unknown')) {
            return new BookingApiResponse(['error' => 'Túl sok kérés. Kérjük, próbálja újra később.'], 429, ['Retry-After' => '60']);
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(400, 'Érvénytelen JSON kérés.');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->error(422, 'A kérésnek JSON objektumnak kell lennie.');
        }

        try {
            $request = $this->validator->validate($payload);
            $outcome = $this->workflow->create($request);
        } catch (BookingValidationFailed $error) {
            return new BookingApiResponse(['error' => 'A megadott adatok hibásak.', 'errors' => $error->errors()], 422);
        } catch (BookingConflict) {
            return $this->error(409, 'A kiválasztott időszak már nem foglalható.');
        } catch (IdempotencyConflict) {
            return $this->error(409, 'Ez a kérésazonosító már más adatokkal felhasználásra került.');
        } catch (PricingConfigurationException) {
            $this->auditPricingFailure();
            return $this->error(503, 'A foglalási ár jelenleg nem számítható ki. Kérjük, próbálja újra később.');
        } catch (Throwable) {
            return $this->error(503, 'A foglalási kérés átmenetileg nem dolgozható fel.');
        }

        return new BookingApiResponse([
            'reference' => $outcome->reference,
            'status' => $outcome->status,
            'total_amount' => $outcome->totalAmount,
            'currency' => $outcome->currency,
            'email_status' => $outcome->emailStatus,
            'next_step' => 'A foglalás az adminisztrátori jóváhagyás után válik véglegessé.',
        ], $outcome->replayed ? 200 : 201);
    }

    /** @param array<string, string> $headers */
    private function originAllowed(array $headers): bool
    {
        $source = $headers['origin'] ?? $headers['referer'] ?? null;
        if ($source === null || $source === '') {
            return true; // Non-browser API clients do not necessarily send either header.
        }
        $parts = parse_url($source);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $origin = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        foreach ($this->trustedOrigins as $trusted) {
            if (hash_equals(strtolower(rtrim($trusted, '/')), $origin)) {
                return true;
            }
        }
        return false;
    }

    private function error(int $status, string $message): BookingApiResponse
    {
        return new BookingApiResponse(['error' => $message], $status);
    }

    private function auditPricingFailure(): void
    {
        if ($this->auditLog === null) {
            return;
        }
        try {
            $this->auditLog->append(new AuditEvent(
                'booking.pricing_unavailable',
                'failed',
                new \DateTimeImmutable('now', new \DateTimeZone('Europe/Budapest')),
                new AuditMetadata(['target_type' => 'booking_request']),
            ));
        } catch (Throwable) {
            // Audit persistence must not expose infrastructure details to the public API.
        }
    }
}
