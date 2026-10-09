<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Booking\BookingModificationPreview;
use App\Application\Booking\ConfirmedBookingModification;
use App\Domain\Booking\CancellationResult;
use App\Domain\Pricing\PricingResult;
use App\Http\Controller\Admin\AdminView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminBookingUiTest extends TestCase
{
    private AdminView $view;

    protected function setUp(): void
    {
        $this->view = new AdminView(dirname(__DIR__, 3) . '/templates');
    }

    public function test_booking_list_is_branded_accessible_escaped_and_minimizes_pii(): void
    {
        $html = $this->view->render('bookings', [
            'bookings' => [[
                'reference' => 'AB-<script>', 'contact_name' => 'Teszt Elek', 'arrival_date' => '2026-08-01',
                'departure_date' => '2026-08-03', 'nights' => 2, 'party_size' => 3, 'total_amount' => '60000.00',
                'currency' => 'HUF', 'status' => 'pending', 'created_at' => '2026-07-16 10:00:00',
            ]],
            'filters' => [], 'page' => 1, 'pageSize' => 20, 'total' => 1, 'pages' => 1,
        ]);
        self::assertStringContainsString('A Bata', $html);
        self::assertStringContainsString('aria-label="Foglalások szűrése"', $html);
        self::assertStringContainsString('<legend>Gyors szűrés</legend>', $html);
        self::assertStringContainsString('<legend>Érkezési időszak</legend>', $html);
        self::assertStringContainsString('<legend>Létrehozási időszak</legend>', $html);
        self::assertStringContainsString('Szűrés alkalmazása', $html);
        self::assertStringContainsString('href="/admin/bookings">Szűrők törlése</a>', $html);
        self::assertStringContainsString('role="region"', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('guest@example', $html);
        self::assertStringContainsString('60 000 Ft', $html);
        self::assertStringNotContainsString('60000.00', $html);
        self::assertStringContainsString('class="bookings-table"', $html);
        self::assertStringContainsString('class="bookings-action"', $html);
        self::assertStringContainsString('2026-08-01 → 2026-08-03', $html);
        self::assertStringContainsString('Megnyitás<span class="sr-only">: AB-&lt;script&gt;</span>', $html);
        self::assertStringContainsString('href="/admin/bookings/AB-%3Cscript%3E"', $html);
        self::assertStringContainsString('3', $html);
        self::assertStringContainsString('Függőben', $html);
        self::assertStringContainsString('status-pending', $html);
        self::assertStringContainsString('2026-07-16 10:00:00', $html);
    }

    #[DataProvider('snapshots')]
    public function test_detail_contains_csrf_status_history_pricing_and_email_state(array $snapshot): void
    {
        $html = $this->view->render('booking-detail', [
            'csrfToken' => 'safe-token',
            'cancellationPreview' => new CancellationResult(
                '2026-07-26T12:00:00+02:00',
                '0.0000',
                '0.00',
                'HUF',
                1,
                [
                    'free_cancellation_deadline' => '2026-07-25',
                    'accommodation_fee' => '40000.00',
                ],
            ),
            'booking' => [
            'reference'=>'AB-1','status'=>'pending','contact_name'=>'Vendég','email'=>'v@example.test','phone'=>'+36',
            'arrival_date'=>'2026-08-01','departure_date'=>'2026-08-03','nights'=>2,'adults'=>2,'children'=>0,
            'children_ages'=>[],'notes'=>null,'privacy_accepted_at'=>null,'total_amount'=>'40000','currency'=>'HUF',
            'booking_policy_accepted_at'=>'2026-07-16 10:00:00','booking_policy_version'=>'2026-07-16',
            'booking_policy_url'=>'/booking-policy',
            'pricing_snapshot'=>$snapshot,'status_history'=>[
                ['old_status'=>null,'status'=>'pending','created_at'=>'2026-07-16','admin_note'=>'Public booking request created'],
                ['old_status'=>'pending','status'=>'pending','created_at'=>'2026-07-16','admin_note'=>'<egyedi admin megjegyzés>'],
            ],
            'email_outbox'=>[['type'=>'booking_request_admin_notification','recipient'=>'admin+<one>@example.test','status'=>'failed','attempts'=>1]],'created_at'=>'2026-07-16','updated_at'=>'2026-07-16',
        ]]);
        self::assertStringContainsString('Ár-pillanatkép', $html);
        self::assertStringContainsString('Státusztörténet', $html);
        self::assertStringContainsString('Küldés sikertelen', $html);
        self::assertStringContainsString('Admin értesítés új foglalásról (admin+&lt;one&gt;@example.test)', $html);
        self::assertStringNotContainsString('admin+<one>@example.test', $html);
        self::assertStringContainsString('Foglalási szabályzat', $html);
        self::assertStringContainsString('2026-07-16', $html);
        self::assertStringContainsString('/booking-policy', $html);
        self::assertStringContainsString('Díjmentes lemondás határideje', $html);
        self::assertStringContainsString('2026-07-25', $html);
        self::assertStringContainsString('<dt>Kötbér mértéke</dt><dd>0%</dd>', $html);
        self::assertStringNotContainsString('0.0000', $html);
        self::assertStringContainsString('40 000 Ft', $html);
        self::assertStringNotContainsString('40000.00', $html);
        self::assertStringContainsString('Foglalási igény létrehozva a publikus felületen', $html);
        self::assertStringNotContainsString('Public booking request created', $html);
        self::assertStringContainsString('&lt;egyedi admin megjegyzés&gt;', $html);
        self::assertStringNotContainsString('<egyedi admin megjegyzés>', $html);
        if (isset($snapshot['line_items'])) {
            self::assertStringContainsString('Rögzített ártételek', $html);
            self::assertStringContainsString('10 000 Ft', $html);
            self::assertStringContainsString('Felnőtt', $html);
        }
        if (isset($snapshot['nightly_breakdown'])) {
            self::assertStringContainsString('Éjszakánkénti személyárak', $html);
            self::assertStringContainsString('2 fő × 10 000 Ft', $html);
            self::assertStringNotContainsString('Árkonfiguráció verziója', $html);
        }
        self::assertSame(3, substr_count($html, 'name="_csrf"'));
        self::assertStringContainsString('maxlength="500"', $html);
        self::assertStringContainsString('Technikai érvénytelenítés', $html);
        self::assertStringContainsString('Téves, teszt vagy duplikált foglalás lezárására. A vendég nem kap lemondási e-mailt.', $html);
    }

    public static function snapshots(): iterable
    {
        yield 'v1 whole integer snapshot' => [['version' => 1, 'pricing_base' => 'person_night', 'unit_price' => 40000, 'total' => 40000]];
        yield 'v2 decimal itemized snapshot' => [[
            'version' => 2,
            'line_items' => [['type' => 'accommodation', 'description' => 'Felnőtt', 'quantity' => 4, 'unit_amount' => '10000.00', 'total' => '40000.00']],
            'accommodation_fee' => '40000.00',
        ]];
        yield 'v3 nightly person snapshot' => [[
            'version' => 3,
            'line_items' => [['type' => 'accommodation', 'description' => 'Felnőtt', 'quantity' => 4, 'unit_amount' => '10000.00', 'total' => '40000.00']],
            'accommodation_fee' => '40000.00',
            'nightly_breakdown' => [[
                'date'=>'2026-08-01','weekend'=>true,'adults'=>2,'adult_unit_amount'=>'10000.00','adult_total'=>'20000.00',
                'children'=>[],'children_total'=>'0.00','total'=>'20000.00',
            ]],
        ]];
    }

    public function test_successful_modification_preview_targets_stable_anchor_and_remains_visible(): void
    {
        $preview = new BookingModificationPreview(
            42,
            3,
            new ConfirmedBookingModification('2026-09-05', '2026-09-08', 2, [2, 6, 11]),
            new PricingResult('96000.00', '90000.00', '6000.00', 'HUF', [], [], []),
            '45000.00',
            'pricing-hash',
        );

        $html = $this->renderModificationDetail([
            'modificationForm' => [
                'arrival_date' => '2026-09-05',
                'departure_date' => '2026-09-08',
                'adults' => '2',
                'children' => '3',
                'child_ages' => ['2', '6', '11'],
                'idempotency_key' => 'preview-key',
            ],
            'modificationPreview' => $preview,
            'modificationPreviewSignature' => 'preview-signature',
        ]);

        self::assertStringContainsString('id="booking-modification"', $html);
        self::assertStringContainsString('action="/admin/bookings/AB-MOD/modification-preview#booking-modification"', $html);
        self::assertStringContainsString('<dt>Gyermekek</dt><dd>3 (2, 6 és 11 éves)</dd>', $html);
        self::assertStringContainsString('<dt>Új végösszeg</dt><dd>96 000 Ft</dd>', $html);
        self::assertStringContainsString('value="preview-signature"', $html);
    }

    public function test_failed_modification_preview_targets_same_anchor_and_preserves_form_and_error(): void
    {
        $html = $this->renderModificationDetail([
            'modificationForm' => [
                'arrival_date' => '2026-09-05',
                'departure_date' => '2026-09-05',
                'adults' => '2',
                'children' => '2',
                'child_ages' => ['4', '8'],
                'idempotency_key' => 'failed-preview-key',
            ],
            'modificationErrors' => [
                'departure_date' => 'A távozásnak az érkezés után kell lennie.',
            ],
        ]);

        self::assertStringContainsString('id="booking-modification"', $html);
        self::assertStringContainsString('action="/admin/bookings/AB-MOD/modification-preview#booking-modification"', $html);
        self::assertStringContainsString('A módosítás nem készíthető elő.', $html);
        self::assertStringContainsString('A távozásnak az érkezés után kell lennie.', $html);
        self::assertStringContainsString('value="2026-09-05"', $html);
        self::assertStringContainsString('data-initial-ages="[&quot;4&quot;,&quot;8&quot;]"', $html);
        self::assertStringContainsString('value="failed-preview-key"', $html);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function statusRetryCases(): iterable
    {
        yield 'failed confirmation on confirmed booking' => ['confirmed', 'booking_confirmed', true];
        yield 'failed arrival information is unrelated' => ['confirmed', 'booking_arrival_information', false];
        yield 'failed review request is unrelated' => ['confirmed', 'booking_review_request', false];
        yield 'failed rejection on rejected booking' => ['rejected', 'booking_rejected', true];
        yield 'failed cancellation on cancelled booking' => ['cancelled', 'booking_cancelled', true];
    }

    #[DataProvider('statusRetryCases')]
    public function test_status_retry_button_only_tracks_failed_mail_for_current_workflow_status(string $status, string $messageType, bool $visible): void
    {
        $html = $this->renderDetail($status, [[
            'type' => $messageType,
            'status' => 'failed',
            'attempts' => 1,
        ]]);

        self::assertSame(
            $visible,
            str_contains($html, 'Sikertelen státuszlevél újraküldése'),
        );
    }

    public function test_cancel_note_is_explicitly_guest_facing_while_other_notes_remain_admin_notes(): void
    {
        $confirmed = $this->renderDetail('confirmed');
        self::assertStringContainsString('Indoklás a vendégnek (opcionális)', $confirmed);
        self::assertStringContainsString('Ez a szöveg megjelenik a vendégnek küldött törlési e-mailben.', $confirmed);
        self::assertSame(1, substr_count($confirmed, 'Admin megjegyzés (opcionális)'));

        $pending = $this->renderDetail('pending', [], ['status' => 'sent']);
        self::assertStringNotContainsString('Indoklás a vendégnek', $pending);
        self::assertSame(3, substr_count($pending, 'Admin megjegyzés (opcionális)'));
    }

    public function test_blocked_period_page_explains_half_open_dates_and_has_no_get_mutation(): void
    {
        $html = $this->view->render('blocked-periods', ['periods'=>[['id'=>4,'start_date'=>'2026-09-01','end_date'=>'2026-09-03','reason'=>'Karbantartás','internal_note'=>null]], 'csrfToken'=>'token']);
        self::assertStringContainsString('Fél-nyitott időszak', $html);
        self::assertSame(2, substr_count($html, 'method="post"'));
        self::assertStringNotContainsString('method="get" action="/admin/blocked-periods/', $html);
        self::assertStringContainsString('<label for="start_date">', $html);
    }

    /** @param list<array<string, mixed>> $emailOutbox */
    private function renderDetail(string $status, array $emailOutbox = [], ?array $paymentRequest = null): string
    {
        return $this->view->render('booking-detail', [
            'csrfToken' => 'safe-token',
            'booking' => [
                'reference' => 'AB-RETRY',
                'status' => $status,
                'total_amount' => '0.00',
                'pricing_snapshot' => [],
                'status_history' => [],
                'email_outbox' => $emailOutbox,
                'payment_request' => $paymentRequest,
            ],
        ]);
    }

    /** @param array<string, mixed> $variables */
    private function renderModificationDetail(array $variables): string
    {
        return $this->view->render('booking-detail', array_merge([
            'csrfToken' => 'safe-token',
            'booking' => [
                'id' => 42,
                'reference' => 'AB-MOD',
                'status' => 'confirmed',
                'contact_name' => 'Vendég',
                'email' => 'guest@example.test',
                'phone' => '+36 1 234 5678',
                'arrival_date' => '2026-09-01',
                'departure_date' => '2026-09-03',
                'nights' => 2,
                'adults' => 2,
                'children' => 0,
                'children_ages' => [],
                'total_amount' => '60000.00',
                'pricing_snapshot' => [],
                'status_history' => [],
                'email_outbox' => [],
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-01 10:00:00',
            ],
            'modificationForm' => [],
            'modificationPreview' => null,
            'modificationErrors' => [],
            'modificationPreviewSignature' => null,
        ], $variables));
    }
}
