<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class AdminUserManagementContractTest extends TestCase
{
    public function testAdminUserRoutesAndSafeFormsArePresent(): void
    {
        $root = dirname(__DIR__, 3);
        $routes = (string) file_get_contents($root . '/public/index.php');
        $controller = (string) file_get_contents($root . '/src/Http/Controller/Admin/AdminUserController.php');
        $auditEvents = (string) file_get_contents($root . '/src/Application/Authentication/AdminBookingNotificationAuditEvents.php');
        $form = (string) file_get_contents($root . '/templates/admin/user-create.php');
        $users = (string) file_get_contents($root . '/templates/admin/users.php');
        $css = (string) file_get_contents($root . '/public/static/css/admin.css');
        $migration = (string) file_get_contents($root . '/database/migrations/026_add_admin_booking_notification_recipients.sql');
        self::assertStringContainsString("get('/admin/users'", $routes);
        self::assertStringContainsString("post('/admin/users/booking-notifications'", $routes);
        self::assertStringContainsString("post('/admin/users/{id}/{action}'", $routes);
        self::assertStringContainsString('password_hash($password, PASSWORD_DEFAULT)', $controller);
        self::assertStringContainsString('revokeAllForAdmin', $controller);
        self::assertStringContainsString('invalidateAllForAdmin', $controller);
        self::assertStringContainsString('autocomplete="new-password"', $form);
        self::assertStringContainsString('name="receives_booking_notifications"', $form);
        self::assertStringContainsString('name="notification_admin_ids[]"', $users);
        self::assertStringContainsString('form="booking-notification-preferences-form"', $users);
        self::assertStringContainsString('Foglalási értesítések', $users);
        self::assertStringContainsString('Inaktív – jelenleg nem kap értesítést', $users);
        self::assertStringContainsString("authorizeForm('admin_user.booking_notifications_bulk'", $controller);
        self::assertStringContainsString('replaceBookingNotificationRecipients($selectedIds)', $controller);
        self::assertStringContainsString('AdminBookingNotificationAuditEvents::forChanges($changes', $controller);
        self::assertStringContainsString('admin_user.booking_notifications_', $auditEvents);
        self::assertStringContainsString('.user-notification-checkbox { width:1.25rem; height:1.25rem; min-height:0;', $css);
        self::assertStringContainsString('.users-table { min-width:64rem;', $css);
        self::assertStringContainsString('receives_booking_notifications BOOLEAN NOT NULL DEFAULT FALSE', $migration);
        self::assertStringContainsString('uq_email_outbox_booking_type_recipient', $migration);
        self::assertStringNotContainsString('password_hash', $form);
    }

    public function test_notification_preferences_render_checked_state_and_inactive_explanation(): void
    {
        $view = new AdminView(dirname(__DIR__, 3) . '/templates');
        $html = $view->render('users', [
            'csrfToken' => 'safe-token',
            'error' => null,
            'notificationsUpdated' => true,
            'users' => [
                ['id'=>1,'name'=>'Aktív','email'=>'active@example.test','is_active'=>true,'receives_booking_notifications'=>true,'created_at'=>'2026-10-06'],
                ['id'=>2,'name'=>'Inaktív','email'=>'inactive@example.test','is_active'=>false,'receives_booking_notifications'=>true,'created_at'=>'2026-10-06'],
            ],
        ]);

        self::assertSame(2, substr_count($html, 'name="notification_admin_ids[]"'));
        self::assertSame(2, substr_count($html, 'class="user-notification-checkbox"'));
        self::assertSame(1, substr_count($html, '>Mentés</button>'));
        self::assertSame(1, substr_count($html, 'id="booking-notification-preferences-form"'));
        self::assertSame(3, substr_count($html, 'form="booking-notification-preferences-form"'));
        self::assertStringNotContainsString('/admin/users/1/booking-notifications', $html);
        self::assertStringContainsString('/admin/users/1/deactivate', $html);
        self::assertStringContainsString('/admin/users/2/activate', $html);
        self::assertStringContainsString('Inaktív – jelenleg nem kap értesítést', $html);
        self::assertStringContainsString('A foglalási értesítések beállításai elmentve.', $html);
        self::assertSame(3, substr_count($html, 'name="_csrf"'));

        $unchecked = $view->render('user-create', ['csrfToken'=>'safe-token','error'=>null,'old'=>[]]);
        self::assertStringContainsString('name="receives_booking_notifications" value="1"', $unchecked);
        self::assertStringNotContainsString('name="receives_booking_notifications" value="1" checked', $unchecked);
        $checked = $view->render('user-create', ['csrfToken'=>'safe-token','error'=>null,'old'=>['receives_booking_notifications'=>true]]);
        self::assertStringContainsString('name="receives_booking_notifications" value="1" checked', $checked);
    }
}
