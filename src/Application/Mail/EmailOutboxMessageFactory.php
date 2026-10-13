<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class EmailOutboxMessageFactory
{
    private const TYPES = [
        'booking_request_received',
        'booking_request_admin_notification',
        'booking_confirmed',
        'booking_rejected',
        'booking_cancelled',
        'booking_payment_request',
        'booking_payment_reminder',
        'booking_arrival_information',
        'booking_modified',
        'booking_review_request',
    ];

    public function __construct(
        private BookingRequestMailRenderer $requestRenderer,
        private BookingStatusMailRenderer $statusRenderer,
        private BookingPaymentRequestMailRenderer $paymentRenderer,
        private BookingManualCommunicationRenderer $manualRenderer,
        private BookingModificationMailRenderer $modificationRenderer,
        private BookingReviewMailRenderer $reviewRenderer,
    ) {
    }

    /** @return list<string> */
    public function supportedTypes(): array
    {
        return self::TYPES;
    }

    public function create(EmailOutboxWorkItem $item): Message
    {
        if (!in_array($item->messageType, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported outbox message type.');
        }
        $payload = json_decode($item->payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \UnexpectedValueException('Stored payload must be an object.');
        }

        $message = match ($item->messageType) {
            'booking_request_received', 'booking_request_admin_notification' =>
                $this->requestRenderer->render($this->requestData($item, $payload)),
            'booking_confirmed', 'booking_rejected', 'booking_cancelled' =>
                $this->statusRenderer->render($this->statusData($item, $payload)),
            'booking_payment_request' => $this->paymentRenderer->render($this->paymentData($payload)),
            'booking_payment_reminder', 'booking_arrival_information' =>
                $this->manualRenderer->render($item->messageType, $this->manualPayload($item->messageType, $payload)),
            'booking_modified' => $this->modificationRenderer->render($this->modificationData($item, $payload)),
            'booking_review_request' => $this->reviewRenderer->render($this->reviewPayload($payload)),
        };
        if (!hash_equals($item->recipient, $message->to)) {
            throw new \UnexpectedValueException('Stored recipient contracts do not match.');
        }

        return $message;
    }

    /** @param array<string, mixed> $payload */
    private function requestData(EmailOutboxWorkItem $item, array $payload): BookingRequestMailData
    {
        $this->requireKeys($payload, ['booking_reference', 'arrival_date', 'departure_date', 'adults', 'child_ages', 'total', 'currency']);
        if (!is_array($payload['child_ages'])) {
            throw new \UnexpectedValueException('Invalid child ages.');
        }

        return new BookingRequestMailData(
            $item->recipient,
            (string) $payload['booking_reference'],
            (string) $payload['arrival_date'],
            (string) $payload['departure_date'],
            (int) $payload['adults'],
            array_map('intval', $payload['child_ages']),
            (string) $payload['total'],
            (string) $payload['currency'],
            (string) ($payload['contact_name'] ?? ''),
            (string) ($payload['phone'] ?? ''),
            isset($payload['notes']) ? (string) $payload['notes'] : null,
            (string) ($payload['status'] ?? 'pending'),
            (string) ($payload['admin_url'] ?? ''),
            $item->messageType,
            (string) ($payload['guest_email'] ?? $item->recipient),
        );
    }

    /** @param array<string, mixed> $payload */
    private function statusData(EmailOutboxWorkItem $item, array $payload): BookingStatusMailData
    {
        $this->requireKeys($payload, ['booking_reference', 'arrival_date', 'departure_date', 'adults', 'children', 'total', 'currency']);

        return new BookingStatusMailData(
            $item->recipient,
            substr($item->messageType, strlen('booking_')),
            (string) $payload['booking_reference'],
            (string) $payload['arrival_date'],
            (string) $payload['departure_date'],
            (int) $payload['adults'],
            (int) $payload['children'],
            (string) $payload['total'],
            (string) $payload['currency'],
            isset($payload['cancellation_penalty_amount']) ? (string) $payload['cancellation_penalty_amount'] : null,
            isset($payload['cancellation_accommodation_fee']) ? (string) $payload['cancellation_accommodation_fee'] : null,
            (string) ($payload['contact_name'] ?? 'Vendégünk'),
            isset($payload['admin_note']) ? (string) $payload['admin_note'] : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function paymentData(array $payload): BookingPaymentRequestMailData
    {
        $this->requireKeys($payload, [
            'booking_reference', 'recipient', 'contact_name', 'arrival_date', 'departure_date',
            'currency', 'total', 'advance_percent', 'advance_amount', 'beneficiary', 'bank_account',
        ]);

        return BookingPaymentRequestMailData::fromPayload($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function manualPayload(string $type, array $payload): array
    {
        $this->requireKeys($payload, ['recipient', 'contact_name', 'template_version']);
        if ($type === 'booking_payment_reminder') {
            $this->requireKeys($payload, [
                'advance_amount', 'payment_reference', 'beneficiary', 'bank_account', 'bank_name', 'swift_bic',
            ]);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function modificationData(EmailOutboxWorkItem $item, array $payload): BookingModificationMailData
    {
        $this->requireKeys($payload, [
            'contact_name', 'booking_reference', 'arrival_date', 'departure_date', 'nights',
            'adults', 'child_ages', 'accommodation_fee', 'tourism_tax', 'total_amount', 'currency',
        ]);
        if (!is_array($payload['child_ages'])) {
            throw new \UnexpectedValueException('Invalid child ages.');
        }

        return new BookingModificationMailData(
            $item->recipient,
            (string) $payload['contact_name'],
            (string) $payload['booking_reference'],
            (string) $payload['arrival_date'],
            (string) $payload['departure_date'],
            (int) $payload['nights'],
            (int) $payload['adults'],
            array_map('intval', $payload['child_ages']),
            (string) $payload['accommodation_fee'],
            (string) $payload['tourism_tax'],
            (string) $payload['total_amount'],
            isset($payload['unchanged_deposit_amount']) ? (string) $payload['unchanged_deposit_amount'] : null,
            (string) $payload['currency'],
            isset($payload['custom_total']) ? (string) $payload['custom_total'] : null,
        );
    }

    /** @param array<string, mixed> $payload @return array{recipient:string} */
    private function reviewPayload(array $payload): array
    {
        $this->requireKeys($payload, ['recipient']);

        return ['recipient' => (string) $payload['recipient']];
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private function requireKeys(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new \UnexpectedValueException('Stored payload is incomplete.');
            }
        }
    }
}
