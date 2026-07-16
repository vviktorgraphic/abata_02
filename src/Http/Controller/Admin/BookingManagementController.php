<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Booking\AdminBookingDetailQuery;
use App\Application\Booking\AdminBookingListQuery;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingNotFound;
use App\Domain\Booking\BookingTransitionNotAllowed;
use App\Domain\Booking\CancellationPolicy;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use DateTimeImmutable;
use DateTimeZone;

final readonly class BookingManagementController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private \App\Security\Csrf\CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private PdoAdminBookingQueryRepository $queries,
        private TransactionalBookingRepository $transitions,
        private \App\Application\Mail\BookingStatusNotificationDispatcher $notifications,
    ) {}

    /** @param array<string, mixed> $query */
    public function index(array $query): AdminResponse
    {
        $admin = $this->auth->currentAdmin();
        if ($admin === null) return new RedirectResponse('/admin/login');
        try {
            $filters = $this->listQuery($query);
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'A megadott szűrés érvénytelen.');
        }
        $total = $this->queries->countBookings($filters);
        return new HtmlResponse($this->view->render('bookings', [
            'admin' => $admin, 'bookings' => $this->queries->fetchBookingList($filters),
            'filters' => $query, 'page' => $filters->page, 'pageSize' => $filters->pageSize,
            'total' => $total, 'pages' => max(1, (int) ceil($total / $filters->pageSize)),
        ]));
    }

    public function detail(string $identifier): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        $booking = $this->queries->fetchBookingDetail(new AdminBookingDetailQuery($identifier));
        if ($booking === null) return $this->error(404, 'A foglalás nem található.');
        $snapshot = $booking['pricing_snapshot'] ?? [];
        $accommodationFee = is_array($snapshot) ? ($snapshot['accommodation_fee'] ?? $snapshot['total'] ?? null) : null;
        $cancellationPreview = is_string($accommodationFee)
            ? (new CancellationPolicy())->calculate(
                (string) $booking['arrival_date'],
                $accommodationFee,
                new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')),
                (string) $booking['currency'],
            )
            : null;
        return new HtmlResponse($this->view->render('booking-detail', [
            'booking' => $booking, 'csrfToken' => $this->csrf->token(), 'cancellationPreview' => $cancellationPreview,
        ]));
    }

    /** @param array<string, mixed> $form */
    public function transition(string $reference, string $action, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $targets = ['confirm' => 'confirmed', 'reject' => 'rejected', 'cancel' => 'cancelled', 'invalidate' => 'invalidated'];
        if (!isset($targets[$action])) return $this->error(404, 'A művelet nem található.');
        $authorization = $this->guard->authorizeForm('booking.' . $targets[$action], $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        try {
            $result = $this->transitions->transition($reference, $targets[$action], $authorization->admin['id'], $authorization->adminNote);
            if ($result->notificationQueued) {
                $this->notifications->dispatch($result->bookingId, $result->newStatus, $authorization->admin['id']);
            }
            return new RedirectResponse('/admin/bookings/' . rawurlencode($reference) . '?result=' . $targets[$action]);
        } catch (BookingNotFound) {
            return $this->error(404, 'A foglalás nem található.');
        } catch (BookingConflict|BookingTransitionNotAllowed) {
            return $this->error(409, 'A státuszváltás ütközés vagy az aktuális státusz miatt nem hajtható végre.');
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'A státuszváltás adatai érvénytelenek.');
        }
    }

    /** @param array<string, mixed> $form */
    public function retryNotification(string $reference, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('email.status_notification_retry', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        $booking = $this->queries->fetchBookingDetail(new AdminBookingDetailQuery($reference));
        if ($booking === null) return $this->error(404, 'A foglalás nem található.');
        if (!in_array($booking['status'], ['confirmed', 'rejected', 'cancelled'], true)) {
            return $this->error(409, 'Ehhez a státuszhoz nincs újraküldhető értesítés.');
        }
        $result = $this->notifications->dispatch((int) $booking['id'], (string) $booking['status'], $authorization->admin['id']);
        return new RedirectResponse('/admin/bookings/' . rawurlencode($reference) . '?email=' . rawurlencode($result->status));
    }

    /** @param array<string, mixed> $query */
    private function listQuery(array $query): AdminBookingListQuery
    {
        $date = static function (mixed $value, bool $endOfDay = false): ?DateTimeImmutable {
            if ($value === null || $value === '') return null;
            if (!is_string($value)) throw new \InvalidArgumentException();
            $format = $endOfDay ? '!Y-m-d 23:59:59' : '!Y-m-d';
            $input = $endOfDay ? $value . ' 23:59:59' : $value;
            $parsed = DateTimeImmutable::createFromFormat($format, $input, new DateTimeZone('Europe/Budapest'));
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) throw new \InvalidArgumentException();
            return $parsed;
        };
        return new AdminBookingListQuery([
            'status' => isset($query['status']) && $query['status'] !== '' && is_string($query['status']) ? $query['status'] : null,
            'search' => is_string($query['q'] ?? null) ? mb_substr($query['q'], 0, 100) : null,
            'arrivalFrom' => $date($query['arrival_from'] ?? null), 'arrivalUntil' => $date($query['arrival_until'] ?? null),
            'createdFrom' => $date($query['created_from'] ?? null), 'createdUntil' => $date($query['created_until'] ?? null, true),
            'page' => filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1,
            'pageSize' => min(50, filter_var($query['per_page'] ?? 20, FILTER_VALIDATE_INT) ?: 20),
        ]);
    }

    private function error(int $status, string $message): HtmlResponse
    {
        return new HtmlResponse($this->view->render('error', ['message' => $message]), $status);
    }
}
