<?php
declare(strict_types=1);
namespace Tests\Unit\Booking;
use PHPUnit\Framework\TestCase;

final class ConfirmedBookingModificationContractTest extends TestCase
{
    public function testMigrationAndTransactionalContractsArePresent(): void
    {
        $root=dirname(__DIR__,3);
        $migration=(string)file_get_contents($root.'/database/migrations/028_add_confirmed_booking_modifications.sql');
        $service=(string)file_get_contents($root.'/src/Infrastructure/Persistence/Booking/TransactionalConfirmedBookingModificationService.php');
        self::assertStringContainsString('modification_version', $migration);
        self::assertStringContainsString('booking_modifications', $migration);
        self::assertStringContainsString('deduplication_key', $migration);
        self::assertStringContainsString('booking_inventory_locks', $service);
        self::assertStringContainsString("id <> ? AND status IN", $service);
        $validator=(string)file_get_contents($root.'/src/Application/Booking/ConfirmedBookingModificationValidator.php');
        self::assertStringContainsString('GuestCapacityPolicy', $validator);
        self::assertStringNotContainsString('chargeableGuests', $validator);
        self::assertStringContainsString("event_type, admin_id", $service);
        self::assertStringContainsString('booking_modified', $service);
        self::assertStringNotContainsString('INSERT INTO email_outbox', $service);
        self::assertStringNotContainsString('booking_status_history', $service);
    }

    public function testAdminUiHasPreviewSaveDynamicAgesAndImmutableContactCopy(): void
    {
        $root=dirname(__DIR__,3);
        $template=(string)file_get_contents($root.'/templates/admin/booking-detail.php');
        $js=(string)file_get_contents($root.'/public/static/js/admin-auth.js');
        foreach(['Foglalás módosítása','Új ár előnézete','Módosítás mentése','Korábban megfizetett előleg','kapcsolattartási adatok'] as $copy) self::assertStringContainsString($copy,$template);
        self::assertStringContainsString('data-modification-child-count',$template);
        self::assertStringContainsString("input.name = 'child_ages[]'",$js);
        self::assertStringNotContainsString('name="contact_name"',$template);
        self::assertStringNotContainsString('name="email"',$template);
        self::assertStringNotContainsString('name="phone"',$template);
    }

    public function testModificationNotificationIsExplicitAndManualBookingPreviewUsesNativeFragment():void
    {
        $root=dirname(__DIR__,3);
        $controller=(string)file_get_contents($root.'/src/Http/Controller/Admin/BookingManagementController.php');
        $routes=(string)file_get_contents($root.'/public/index.php');
        $worker=(string)file_get_contents($root.'/src/Infrastructure/Persistence/Booking/PdoEmailOutboxWorkerRepository.php');
        $create=(string)file_get_contents($root.'/templates/admin/booking-create.php');
        self::assertStringContainsString('A módosítás elmentve. A vendég még nem kapott értesítést.',$controller);
        self::assertStringContainsString("authorizeForm('email.booking_modified_send'",$controller);
        self::assertStringContainsString('/modifications/{modificationId}/send-notification',$routes);
        self::assertStringNotContainsString('/modifications/{modificationId}/retry-email',$routes);
        self::assertStringContainsString("message_type <> 'booking_modified'",$worker);
        self::assertStringContainsString('/admin/bookings/create/preview#booking-create-submit',$create);
        self::assertStringContainsString('id="booking-create-submit"',$create);
    }
}
