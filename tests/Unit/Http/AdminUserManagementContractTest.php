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
        $form = (string) file_get_contents($root . '/templates/admin/user-create.php');
        $users = (string) file_get_contents($root . '/templates/admin/users.php');
        $migration = (string) file_get_contents($root . '/database/migrations/026_add_admin_booking_notification_recipients.sql');
        self::assertStringContainsString("get('/admin/users'", $routes);
        self::assertStringContainsString("post('/admin/users/{id}/{action}'", $routes);
        self::assertStringContainsString('password_hash($password, PASSWORD_DEFAULT)', $controller);
        self::assertStringContainsString('revokeAllForAdmin', $controller);
        self::assertStringContainsString('invalidateAllForAdmin', $controller);
        self::assertStringContainsString('autocomplete="new-password"', $form);
        self::assertStringContainsString('name="receives_booking_notifications"', $form);
        self::assertStringContainsString('name="enabled" value="1"', $users);
        self::assertStringContainsString('Foglalási értesítések', $users);
        self::assertStringContainsString('Inaktív felhasználó nem kap értesítést.', $users);
        self::assertStringContainsString('/booking-notifications', $users);
        self::assertStringContainsString("authorizeForm('admin_user.booking_notifications'", $controller);
        self::assertStringContainsString('admin_user.booking_notifications_', $controller);
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
            'users' => [
                ['id'=>1,'name'=>'Aktív','email'=>'active@example.test','is_active'=>true,'receives_booking_notifications'=>true,'created_at'=>'2026-10-06'],
                ['id'=>2,'name'=>'Inaktív','email'=>'inactive@example.test','is_active'=>false,'receives_booking_notifications'=>true,'created_at'=>'2026-10-06'],
            ],
        ]);

        self::assertSame(2, substr_count($html, 'name="enabled" value="1" checked'));
        self::assertStringContainsString('Inaktív felhasználó nem kap értesítést.', $html);
        self::assertSame(4, substr_count($html, 'name="_csrf"'));

        $unchecked = $view->render('user-create', ['csrfToken'=>'safe-token','error'=>null,'old'=>[]]);
        self::assertStringContainsString('name="receives_booking_notifications" value="1"', $unchecked);
        self::assertStringNotContainsString('name="receives_booking_notifications" value="1" checked', $unchecked);
        $checked = $view->render('user-create', ['csrfToken'=>'safe-token','error'=>null,'old'=>['receives_booking_notifications'=>true]]);
        self::assertStringContainsString('name="receives_booking_notifications" value="1" checked', $checked);
    }
}
