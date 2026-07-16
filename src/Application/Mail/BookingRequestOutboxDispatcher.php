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
        $item = $this->outbox->findForDelivery($bookingId);
        if ($item === null) {
            return new OutboxDeliveryResult($this->outbox->statusForBooking($bookingId));
        }

        try {
            $this->mailer->send($this->renderer->render($item['data']));
            $this->outbox->markSent($item['id']);

            return new OutboxDeliveryResult('sent');
        } catch (Throwable) {
            // Never persist transport exception text: it may contain hosts, credentials or PII.
            $this->outbox->markFailed($item['id'], 'E-mail transport failure.');

            return new OutboxDeliveryResult('failed');
        }
    }
}
