<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Domain\Booking\CancellationResult;
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
        self::assertStringContainsString('pending', $html);
        self::assertStringContainsString('2026-07-16 10:00:00', $html);
    }

    #[DataProvider('snapshots')]
    public function test_detail_contains_csrf_status_history_pricing_and_email_state(array $snapshot): void
    {
        $html = $this->view->render('booking-detail', [
            'csrfToken' => 'safe-token',
            'cancellationPreview' => new CancellationResult(
                '2026-07-26T12:00:00+02:00',
                '0.5000',
                '20000.00',
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
            'pricing_snapshot'=>$snapshot,'status_history'=>[['old_status'=>null,'status'=>'pending','created_at'=>'2026-07-16','admin_note'=>null]],
            'email_outbox'=>[['type'=>'booking_request','status'=>'failed','attempts'=>1]],'created_at'=>'2026-07-16','updated_at'=>'2026-07-16',
        ]]);
        self::assertStringContainsString('Ár-pillanatkép', $html);
        self::assertStringContainsString('Státusztörténet', $html);
        self::assertStringContainsString('Küldés sikertelen', $html);
        self::assertStringContainsString('Foglalási szabályzat', $html);
        self::assertStringContainsString('2026-07-16', $html);
        self::assertStringContainsString('/booking-policy', $html);
        self::assertStringContainsString('Díjmentes lemondás határideje', $html);
        self::assertStringContainsString('2026-07-25', $html);
        self::assertStringContainsString('20 000 Ft', $html);
        self::assertStringContainsString('40 000 Ft', $html);
        self::assertStringNotContainsString('40000.00', $html);
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

    public function test_blocked_period_page_explains_half_open_dates_and_has_no_get_mutation(): void
    {
        $html = $this->view->render('blocked-periods', ['periods'=>[['id'=>4,'start_date'=>'2026-09-01','end_date'=>'2026-09-03','reason'=>'Karbantartás','internal_note'=>null]], 'csrfToken'=>'token']);
        self::assertStringContainsString('Fél-nyitott időszak', $html);
        self::assertSame(2, substr_count($html, 'method="post"'));
        self::assertStringNotContainsString('method="get" action="/admin/blocked-periods/', $html);
        self::assertStringContainsString('<label for="start_date">', $html);
    }
}
