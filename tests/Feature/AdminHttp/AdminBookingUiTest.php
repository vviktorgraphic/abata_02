<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Domain\Booking\CancellationResult;
use App\Http\Controller\Admin\AdminView;
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
    }

    public function test_detail_contains_csrf_status_history_pricing_and_email_state(): void
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
            'pricing_snapshot'=>['pricing_base'=>'person_night'],'status_history'=>[['old_status'=>null,'status'=>'pending','created_at'=>'2026-07-16','admin_note'=>null]],
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
        self::assertStringContainsString('20000.00 HUF', $html);
        self::assertStringContainsString('40000.00 HUF', $html);
        self::assertSame(3, substr_count($html, 'name="_csrf"'));
        self::assertStringContainsString('maxlength="500"', $html);
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
