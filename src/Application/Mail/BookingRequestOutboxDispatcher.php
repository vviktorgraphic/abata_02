<?php

declare(strict_types=1);

namespace App\Application\Mail;

use Throwable;

final readonly class BookingRequestOutboxDispatcher
{
    public function __construct(
        private BookingRequestOutbox $outbox,
        private BookingRequestMailRenderer $renderer,
        private Mailer $mailer,
    ) {
    }

    public function dispatchForBooking(int $bookingId): OutboxDeliveryResult
    {
        $guest = $this->outbox->findForDelivery($bookingId, 'booking_request_received');
        if ($guest !== null) {
            $this->deliver($guest);
        }
        while (($admin = $this->outbox->findForDelivery($bookingId, 'booking_request_admin_notification')) !== null) {
            $this->deliver($admin);
        }
        return new OutboxDeliveryResult($this->outbox->statusForBooking($bookingId));
    }

    /** @param array{id:int,data:BookingRequestMailData} $item */
    private function deliver(array $item): void
    {
        try {
            $this->mailer->send($this->renderer->render($item['data']));
            $this->outbox->markSent($item['id']);
        } catch (Throwable) {
            // Never persist transport exception text: it may contain hosts, credentials or PII.
            $this->outbox->markFailed($item['id'], 'E-mail transport failure.');
        }
    }
}
