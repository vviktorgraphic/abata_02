<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Booking\AdminManualBookingPreview;
use App\Application\Booking\AdminManualBookingPreviewSigner;
use App\Application\Booking\AdminManualBookingWorkflow;
use App\Application\Booking\BookingClock;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingPreviewStale;
use App\Application\Booking\IdempotencyConflict;
use App\Application\Pricing\MissingChildPriceBandException;
use App\Application\Pricing\PersonPricingNotConfiguredException;
use App\Application\Pricing\PricingConfigurationException;
use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Booking\BookingValidationFailed;
use App\Domain\Pricing\OccupancyStayLengthViolation;
use App\Security\Csrf\CsrfTokenManager;

final readonly class AdminManualBookingController
{
    private const INPUT_FIELDS = [
        'arrival_date', 'departure_date', 'contact_name', 'email', 'phone',
        'adults', 'children', 'child_ages', 'notes', 'idempotency_key',
    ];

    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private BookingCreateRequestValidator $validator,
        private AdminManualBookingWorkflow $workflow,
        private AdminManualBookingPreviewSigner $signer,
        private BookingClock $clock,
        private AdminBookingFlashStore $flash,
    ) {
    }

    public function show(): AdminResponse
    {
        $admin = $this->auth->currentAdmin();
        if ($admin === null) {
            return new RedirectResponse('/admin/login');
        }

        return $this->render($admin, [
            'arrival_date' => '', 'departure_date' => '', 'contact_name' => '', 'email' => '',
            'phone' => '', 'adults' => '1', 'children' => '0', 'child_ages' => [], 'notes' => '',
            'idempotency_key' => bin2hex(random_bytes(16)),
        ]);
    }

    /** @param array<string,mixed> $form */
    public function preview(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('booking.admin_create_preview', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) {
            return $authorization->rejection;
        }
        $values = $this->values($form);
        if ($this->hasUnexpectedFields($form, false)) {
            return $this->render($authorization->admin, $values, ['form' => 'Az űrlap nem támogatott mezőt tartalmaz.'], 422);
        }

        try {
            $request = $this->validator->validate($values);
            $preview = $this->workflow->preview($request);
            $signature = $this->signer->sign($request, $preview->pricing->totalAmount, $preview->pricing->currency);

            return $this->render($authorization->admin, $values, [], 200, $preview, $signature);
        } catch (BookingValidationFailed $error) {
            return $this->render($authorization->admin, $values, $error->errors(), 422);
        } catch (BookingConflict) {
            return $this->render($authorization->admin, $values, ['dates' => 'A megadott időszak foglalt vagy aktív blokkolással ütközik.'], 409);
        } catch (OccupancyStayLengthViolation $error) {
            return $this->render($authorization->admin, $values, ['departure_date' => $error->getMessage()], 422);
        } catch (PricingConfigurationException|MissingChildPriceBandException|PersonPricingNotConfiguredException) {
            return $this->render($authorization->admin, $values, ['pricing' => 'A megadott adatokhoz jelenleg nincs érvényes árbeállítás.'], 422);
        } catch (\Throwable) {
            return UnexpectedHttpErrorResponse::create();
        }
    }

    /** @param array<string,mixed> $form */
    public function save(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('booking.admin_create', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) {
            return $authorization->rejection;
        }
        $values = $this->values($form);
        if ($this->hasUnexpectedFields($form, true)) {
            return $this->render($authorization->admin, $values, ['form' => 'Az űrlap nem támogatott mezőt tartalmaz.'], 422);
        }

        try {
            $request = $this->validator->validate($values);
            $signature = is_string($form['preview_signature'] ?? null) ? $form['preview_signature'] : '';
            $expectedTotal = is_string($form['expected_total'] ?? null) ? $form['expected_total'] : '';
            $expectedCurrency = is_string($form['expected_currency'] ?? null) ? $form['expected_currency'] : '';
            if (!$this->signer->verify($signature, $request, $expectedTotal, $expectedCurrency)) {
                throw new BookingPreviewStale('Az előnézet lejárt vagy az adatok megváltoztak. Kérj új előnézetet.');
            }
            $result = $this->workflow->create(
                $request,
                (int) $authorization->admin['id'],
                $expectedTotal,
                $expectedCurrency,
            );
            $this->flash->put($result->reference, 'A foglalás rögzítve.');

            return new RedirectResponse('/admin/bookings/' . rawurlencode($result->reference));
        } catch (BookingValidationFailed $error) {
            return $this->render($authorization->admin, $values, $error->errors(), 422);
        } catch (BookingPreviewStale $error) {
            return $this->render($authorization->admin, $values, ['stale' => $error->getMessage()], 409);
        } catch (IdempotencyConflict) {
            return $this->render($authorization->admin, $values, ['stale' => 'Az űrlap azonosítója már más adatokkal fel lett használva. Kérj új előnézetet.'], 409);
        } catch (BookingConflict) {
            return $this->render($authorization->admin, $values, ['dates' => 'A foglaltság az előnézet óta megváltozott. Kérj új előnézetet.'], 409);
        } catch (OccupancyStayLengthViolation $error) {
            return $this->render($authorization->admin, $values, ['departure_date' => $error->getMessage()], 422);
        } catch (PricingConfigurationException|MissingChildPriceBandException|PersonPricingNotConfiguredException) {
            return $this->render($authorization->admin, $values, ['pricing' => 'Az ár időközben megváltozott vagy nem számítható. Kérj új előnézetet.'], 409);
        } catch (\Throwable) {
            return UnexpectedHttpErrorResponse::create();
        }
    }

    /** @param array{id:int,name:string} $admin @param array<string,mixed> $values @param array<string,string> $errors */
    private function render(array $admin, array $values, array $errors = [], int $status = 200, ?AdminManualBookingPreview $preview = null, ?string $signature = null): HtmlResponse
    {
        return new HtmlResponse($this->view->render('booking-create', [
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'values' => $values,
            'errors' => $errors,
            'preview' => $preview,
            'previewSignature' => $signature,
            'today' => $this->clock->now()->setTime(0, 0),
        ]), $status);
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    private function values(array $form): array
    {
        $values = [];
        foreach (self::INPUT_FIELDS as $field) {
            $values[$field] = $form[$field] ?? ($field === 'child_ages' ? [] : '');
        }

        return $values;
    }

    /** @param array<string,mixed> $form */
    private function hasUnexpectedFields(array $form, bool $save): bool
    {
        $allowed = ['_csrf', ...self::INPUT_FIELDS];
        if ($save) {
            $allowed = [...$allowed, 'preview_signature', 'expected_total', 'expected_currency'];
        }

        return array_diff(array_keys($form), $allowed) !== [];
    }
}
