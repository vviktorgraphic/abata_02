<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Application\Mail\BookingNotificationRecipientProvider;
use App\Application\Mail\BookingNotificationRecipientResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingNotificationRecipientResolverTest extends TestCase
{
    /** @param list<string> $selected @param list<string> $expected */
    #[DataProvider('recipientCases')]
    public function test_selected_recipients_override_fallback_and_are_deduplicated(array $selected, array $expected): void
    {
        $provider = new class($selected) implements BookingNotificationRecipientProvider {
            /** @param list<string> $recipients */
            public function __construct(private array $recipients) {}
            public function bookingNotificationRecipients(): array { return $this->recipients; }
        };

        self::assertSame($expected, (new BookingNotificationRecipientResolver($provider, 'fallback@example.test'))->recipients());
    }

    public static function recipientCases(): iterable
    {
        yield 'fallback when none selected' => [[], ['fallback@example.test']];
        yield 'one selected excludes fallback' => [['Admin1@Example.test'], ['admin1@example.test']];
        yield 'two selected exclude fallback' => [['admin1@example.test', 'admin2@example.test'], ['admin1@example.test', 'admin2@example.test']];
        yield 'case equivalent duplicate' => [['Admin@Example.test', 'admin@example.test'], ['admin@example.test']];
    }
}
