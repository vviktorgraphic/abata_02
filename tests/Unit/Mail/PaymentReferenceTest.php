<?php
declare(strict_types=1);
namespace Tests\Unit\Mail;
use App\Application\Mail\PaymentReference;
use PHPUnit\Framework\TestCase;
final class PaymentReferenceTest extends TestCase
{
    public function testPadsButNeverTruncatesBookingId():void
    { self::assertSame('AB-000123',PaymentReference::forBookingId(123));self::assertSame('AB-1234567',PaymentReference::forBookingId(1234567)); }
}
