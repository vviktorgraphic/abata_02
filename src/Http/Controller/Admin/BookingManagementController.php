<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Booking\AdminBookingDetailQuery;
use App\Application\Booking\AdminBookingListQuery;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingModificationNotAllowed;
use App\Application\Booking\BookingModificationPreviewSigner;
use App\Application\Booking\BookingModificationStale;
use App\Application\Booking\BookingNotFound;
use App\Application\Booking\ConfirmedBookingModificationValidator;
use App\Application\Booking\IdempotencyConflict;
use App\Application\Mail\BookingModificationNotificationDispatcher;
use App\Application\Pricing\PricingConfigurationException;
use App\Domain\Booking\BookingValidationFailed;
use App\Domain\Pricing\OccupancyStayLengthViolation;
use App\Domain\Booking\BookingTransitionNotAllowed;
use App\Domain\Booking\CancellationPolicy;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Booking\TransactionalConfirmedBookingModificationService;
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
        private ?\App\Application\Mail\BookingPaymentRequestDispatcher $paymentRequests = null,
        private ?\App\Application\Mail\BookingPaymentRequestConfiguration $paymentConfiguration = null,
        private ?\App\Application\Mail\BookingManualCommunicationDispatcher $manualCommunications = null,
        private ?TransactionalConfirmedBookingModificationService $modifications = null,
        private ?ConfirmedBookingModificationValidator $modificationValidator = null,
        private ?BookingModificationPreviewSigner $modificationSigner = null,
        private ?BookingModificationNotificationDispatcher $modificationNotifications = null,
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
        return $this->renderDetail($identifier);
    }

    /** @param array<string,mixed> $form */
    public function previewModification(string $reference, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('booking.modification_preview', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        if ($this->modifications === null || $this->modificationValidator === null || $this->modificationSigner === null) {
            return $this->error(503, 'A foglalásmódosítás jelenleg nem érhető el.');
        }
        try {
            $request = $this->modificationValidator->validate($form);
            $preview = $this->modifications->preview($reference, $request);
            return $this->renderDetail($reference, 200, $form, $preview, [],
                $this->modificationSigner->sign($reference, $preview->version, $request, $preview->pricingHash));
        } catch (BookingValidationFailed $error) {
            return $this->renderDetail($reference, 422, $form, null, $error->errors());
        } catch (BookingNotFound) {
            return $this->error(404, 'A foglalás nem található.');
        } catch (BookingModificationNotAllowed $error) {
            return $this->renderDetail($reference, 409, $form, null, ['form' => $error->getMessage()]);
        } catch (BookingConflict) {
            return $this->renderDetail($reference, 409, $form, null, ['dates' => 'A megadott időszak foglalt vagy aktív blokkolással ütközik.']);
        } catch (OccupancyStayLengthViolation $error) {
            return $this->renderDetail($reference, 422, $form, null, ['departure_date' => $error->getMessage()]);
        } catch (PricingConfigurationException|\App\Application\Pricing\MissingChildPriceBandException|\App\Application\Pricing\PersonPricingNotConfiguredException) {
            return $this->renderDetail($reference, 422, $form, null, ['pricing' => 'A megadott adatokhoz jelenleg nincs érvényes árbeállítás.']);
        }
    }

    /** @param array<string,mixed> $form */
    public function saveModification(string $reference, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('booking.modify', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        if ($this->modifications === null || $this->modificationValidator === null || $this->modificationSigner === null || $this->modificationNotifications === null) {
            return $this->error(503, 'A foglalásmódosítás jelenleg nem érhető el.');
        }
        try {
            $request = $this->modificationValidator->validate($form);
            $version = filter_var($form['expected_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $signature = is_string($form['preview_signature'] ?? null) ? $form['preview_signature'] : '';
            $pricingHash = is_string($form['expected_pricing_hash'] ?? null) ? $form['expected_pricing_hash'] : '';
            $idempotencyKey = is_string($form['idempotency_key'] ?? null) ? $form['idempotency_key'] : '';
            if ($version === false || !$this->modificationSigner->verify($signature, $reference, (int) $version, $request, $pricingHash)) {
                throw new BookingModificationStale('Az előnézet lejárt vagy megváltozott. Készítsen új előnézetet.');
            }
            $result = $this->modifications->modify($reference, $request, (int) $version, $pricingHash, $idempotencyKey, (int) $authorization->admin['id']);
            $delivery = $this->modificationNotifications->dispatch($result->modificationId, (int) $authorization->admin['id']);
            return new RedirectResponse('/admin/bookings/' . rawurlencode($reference) . '?modified=' . rawurlencode($delivery->status));
        } catch (BookingValidationFailed $error) {
            return $this->renderDetail($reference, 422, $form, null, $error->errors());
        } catch (BookingNotFound) {
            return $this->error(404, 'A foglalás nem található.');
        } catch (BookingModificationNotAllowed $error) {
            return $this->renderDetail($reference, 409, $form, null, ['form' => $error->getMessage()]);
        } catch (BookingModificationStale|IdempotencyConflict $error) {
            return $this->renderDetail($reference, 409, $form, null, ['stale' => $error->getMessage()]);
        } catch (BookingConflict) {
            return $this->renderDetail($reference, 409, $form, null, ['dates' => 'A foglaltság az előnézet óta megváltozott. Készítsen új előnézetet.']);
        } catch (OccupancyStayLengthViolation $error) {
            return $this->renderDetail($reference, 422, $form, null, ['departure_date' => $error->getMessage()]);
        } catch (PricingConfigurationException|\App\Application\Pricing\MissingChildPriceBandException|\App\Application\Pricing\PersonPricingNotConfiguredException) {
            return $this->renderDetail($reference, 422, $form, null, ['pricing' => 'Az árbeállítás az előnézet óta megváltozott vagy hiányos. Készítsen új előnézetet.']);
        } catch (\InvalidArgumentException $error) {
            return $this->renderDetail($reference, 422, $form, null, ['form' => $error->getMessage()]);
        }
    }

    /** @param array<string,mixed> $form */
    public function retryModificationNotification(string $reference, string $modificationId, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('email.booking_modified_retry', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        if ($this->modificationNotifications === null || !ctype_digit($modificationId)) return $this->error(404, 'A módosítás nem található.');
        $booking = $this->queries->fetchBookingDetail(new AdminBookingDetailQuery($reference));
        if ($booking === null) return $this->error(404, 'A foglalás nem található.');
        $known = array_filter($booking['modifications'] ?? [], static fn (array $item): bool => $item['id'] === (int) $modificationId);
        if ($known === []) return $this->error(404, 'A módosítás nem található.');
        $result = $this->modificationNotifications->dispatch((int) $modificationId, (int) $authorization->admin['id']);
        return new RedirectResponse('/admin/bookings/' . rawurlencode($reference) . '?modified=' . rawurlencode($result->status));
    }

    /** @param array<string,mixed> $modificationForm @param array<string,string> $modificationErrors */
    private function renderDetail(
        string $identifier,
        int $status = 200,
        array $modificationForm = [],
        ?\App\Application\Booking\BookingModificationPreview $modificationPreview = null,
        array $modificationErrors = [],
        ?string $modificationPreviewSignature = null,
    ): AdminResponse {
        $booking = $this->queries->fetchBookingDetail(new AdminBookingDetailQuery($identifier));
        if ($booking === null) return $this->error(404, 'A foglalás nem található.');
        $snapshot = $booking['pricing_snapshot'] ?? [];
        $accommodationFee = is_array($snapshot) ? ($snapshot['accommodation_fee'] ?? null) : null;
        $cancellationPreview = is_string($accommodationFee) || is_int($accommodationFee)
            ? (new CancellationPolicy())->calculate(
                (string) $booking['arrival_date'],
                (string) $accommodationFee,
                new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')),
                (string) $booking['currency'],
            )
            : null;
        $paymentAdvance = $booking['payment_request']['advance_amount'] ?? null;
        $paymentConfigurationError = null;
        if ($paymentAdvance === null && empty($booking['legacy_pricing_unavailable']) && $booking['status'] === 'pending') {
            try {
                $paymentAdvance = $this->paymentConfiguration?->advanceFor((string) $accommodationFee);
            } catch (\InvalidArgumentException) {
                $paymentConfigurationError = 'A díjbekérő előlegbeállítása vagy az immutable szállásdíj-pillanatkép érvénytelen.';
            }
        }
        if ($booking['status'] === 'confirmed' && $modificationForm === []) {
            $modificationForm = [
                'arrival_date' => $booking['arrival_date'], 'departure_date' => $booking['departure_date'],
                'adults' => $booking['adults'], 'children' => $booking['children'],
                'child_ages' => $booking['children_ages'], 'idempotency_key' => bin2hex(random_bytes(16)),
            ];
        }
        return new HtmlResponse($this->view->render('booking-detail', [
            'booking' => $booking, 'csrfToken' => $this->csrf->token(), 'cancellationPreview' => $cancellationPreview,
            'paymentAdvance' => $paymentAdvance, 'paymentConfigurationError' => $paymentConfigurationError,
            'paymentReference' => $booking['payment_request']['payment_reference'] ?? \App\Application\Mail\PaymentReference::forBookingId((int)$booking['id']),
            'modificationForm' => $modificationForm, 'modificationPreview' => $modificationPreview,
            'modificationErrors' => $modificationErrors, 'modificationPreviewSignature' => $modificationPreviewSignature,
        ]), $status);
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
        } catch (\App\Application\Booking\PaymentRequestRequired) {
            return $this->error(409, 'A foglalás a díjbekérő sikeres elküldése után erősíthető meg.');
        } catch (BookingConflict|BookingTransitionNotAllowed) {
            return $this->error(409, 'A státuszváltás ütközés vagy az aktuális státusz miatt nem hajtható végre.');
        } catch (\InvalidArgumentException) {
            return $this->error(422, 'A státuszváltás adatai érvénytelenek.');
        }
    }

    /** @param array<string, mixed> $form */
    public function paymentRequest(string $reference, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('email.payment_request', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        if ($this->paymentRequests === null) return $this->error(422, 'A díjbekérő levelezési konfigurációja hiányzik.');
        try {
            $result = $this->paymentRequests->dispatch($reference, $authorization->admin['id']);
            return new RedirectResponse('/admin/bookings/' . rawurlencode($reference) . '?payment=' . rawurlencode($result->status));
        } catch (\OutOfBoundsException) {
            return $this->error(404, 'A foglalás nem található.');
        } catch (\DomainException) {
            return $this->error(409, 'Díjbekérő csak függőben lévő foglaláshoz küldhető.');
        } catch (\InvalidArgumentException $error) {
            return $this->error(422, $error->getMessage());
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

    /** @param array<string,mixed> $form */
    public function paymentReminder(string $reference, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization=$this->guard->authorizeForm('email.payment_reminder',$form,$contentType,$contentLength);
        if(!$authorization->allowed())return $authorization->rejection;
        if($this->manualCommunications===null)return $this->error(422,'A vendégkommunikáció nincs konfigurálva.');
        try{$result=$this->manualCommunications->paymentReminder($reference,$authorization->admin['id']);
            return new RedirectResponse('/admin/bookings/'.rawurlencode($reference).'?reminder='.rawurlencode($result->status));
        }catch(\OutOfBoundsException){return $this->error(404,'A foglalás nem található.');}
        catch(\DomainException $e){return $this->error(409,$e->getMessage());}
    }

    /** @param array<string,mixed> $form */
    public function arrivalInformation(string $reference, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization=$this->guard->authorizeForm('email.arrival_information',$form,$contentType,$contentLength);
        if(!$authorization->allowed())return $authorization->rejection;
        if($this->manualCommunications===null)return $this->error(422,'A vendégkommunikáció nincs konfigurálva.');
        try{$result=$this->manualCommunications->arrivalInformation($reference,$authorization->admin['id']);
            return new RedirectResponse('/admin/bookings/'.rawurlencode($reference).'?arrival='.rawurlencode($result->status));
        }catch(\OutOfBoundsException){return $this->error(404,'A foglalás nem található.');}
        catch(\DomainException $e){return $this->error(409,$e->getMessage());}
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
