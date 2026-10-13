<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Application\Availability\BlockedPeriodReadRepository;
use App\Application\Availability\BookingReadRepository;
use App\Application\Booking\AdminManualBookingService;
use App\Application\Booking\AdminCustomPrice;
use App\Application\Booking\BookingClock;
use App\Application\Booking\BookingConflict;
use App\Application\Booking\BookingPersistenceCommand;
use App\Application\Booking\BookingPricing;
use App\Application\Booking\BookingPricingProvider;
use App\Application\Pricing\PricingPreviewer;
use App\Domain\Booking\BookingCreateRequest;
use App\Domain\Booking\BookingPeriod;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingResult;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AdminManualBookingServiceTest extends TestCase
{
    public function testPreviewUsesReadOnlyAvailabilityAndSharedPricingInput(): void
    {
        $pricing = new ManualPricingPreviewerSpy();
        $service = $this->service([], [], $pricing);

        $preview = $service->preview($this->request('2047-06-10', '2047-06-12'));

        self::assertSame('50000.00', $preview->pricing->totalAmount);
        self::assertNotNull($pricing->input);
        self::assertSame('2047-06-10', $pricing->input->arrivalDate);
        self::assertSame('2047-06-12', $pricing->input->departureDate);
        self::assertSame(2, $pricing->input->adults);
        self::assertSame([3], $pricing->input->childAges);
    }

    public function testPreviewRetainsCalculatedPricingAndBuildsEffectiveCustomSnapshot(): void
    {
        $preview = $this->service([], [], new ManualPricingPreviewerSpy())->preview(
            $this->request('2047-06-10', '2047-06-12'),
            AdminCustomPrice::fromCanonical('48000.00'),
        );

        self::assertSame('50000.00', $preview->calculatedPricing->totalAmount);
        self::assertSame('48000.00', $preview->pricing->totalAmount);
        self::assertSame('43000.00', $preview->pricing->accommodationFee);
        self::assertSame('5000.00', $preview->pricing->tourismTax);
        self::assertSame('48000.00', $preview->customTotal);
        self::assertSame('admin_custom_total', $preview->pricing->snapshot['price_override']['type']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview->pricingHash);
    }

    public function testPreviewRejectsBookingOrBlockedPeriodOverlapBeforePricing(): void
    {
        $zone = new DateTimeZone('Europe/Budapest');
        foreach ([
            [[new BookingPeriod(new DateTimeImmutable('2047-06-11', $zone), new DateTimeImmutable('2047-06-13', $zone))], []],
            [[], [new BookingPeriod(new DateTimeImmutable('2047-06-09', $zone), new DateTimeImmutable('2047-06-11', $zone))]],
        ] as [$bookings, $blocks]) {
            $pricing = new ManualPricingPreviewerSpy();
            try {
                $this->service($bookings, $blocks, $pricing)->preview($this->request('2047-06-10', '2047-06-12'));
                self::fail('Overlapping period was accepted.');
            } catch (BookingConflict) {
                self::assertNull($pricing->input);
            }
        }
    }

    public function testPreviewPreservesHalfOpenTurnoverSemantics(): void
    {
        $zone = new DateTimeZone('Europe/Budapest');
        $pricing = new ManualPricingPreviewerSpy();
        $service = $this->service([
            new BookingPeriod(new DateTimeImmutable('2047-06-08', $zone), new DateTimeImmutable('2047-06-10', $zone)),
            new BookingPeriod(new DateTimeImmutable('2047-06-12', $zone), new DateTimeImmutable('2047-06-14', $zone)),
        ], [], $pricing);

        self::assertSame('50000.00', $service->preview($this->request('2047-06-10', '2047-06-12'))->pricing->totalAmount);
    }

    /** @param list<BookingPeriod> $bookings @param list<BookingPeriod> $blocks */
    private function service(array $bookings, array $blocks, PricingPreviewer $previewer): AdminManualBookingService
    {
        $repository = (new ReflectionClass(TransactionalBookingRepository::class))->newInstanceWithoutConstructor();
        $pricingProvider = new class implements BookingPricingProvider {
            public function calculate(PDO $pdo, BookingPersistenceCommand $command): BookingPricing
            {
                throw new \LogicException('Preview must not call persistence pricing.');
            }
        };

        return new AdminManualBookingService(
            $repository,
            $pricingProvider,
            $previewer,
            new ManualBookingReadRepository($bookings),
            new ManualBlockedReadRepository($blocks),
            new ManualServiceClock(),
        );
    }

    private function request(string $arrival, string $departure): BookingCreateRequest
    {
        $zone = new DateTimeZone('Europe/Budapest');

        return new BookingCreateRequest(
            new BookingPeriod(new DateTimeImmutable($arrival, $zone), new DateTimeImmutable($departure, $zone)),
            'Teszt Vendég', 'guest@example.invalid', '+36301234567', 2, 1, [3], '',
            false, false, false, 'admin-service-key-1234',
        );
    }
}

final readonly class ManualBookingReadRepository implements BookingReadRepository
{
    /** @param list<BookingPeriod> $periods */
    public function __construct(private array $periods) {}
    public function findBlockingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array { return $this->periods; }
}

final readonly class ManualBlockedReadRepository implements BlockedPeriodReadRepository
{
    /** @param list<BookingPeriod> $periods */
    public function __construct(private array $periods) {}
    public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array { return $this->periods; }
}

final class ManualPricingPreviewerSpy implements PricingPreviewer
{
    public ?PricingInput $input = null;
    public function preview(PricingInput $input): PricingResult
    {
        $this->input = $input;
        return new PricingResult('50000.00', '45000.00', '5000.00', 'HUF', [], [], ['version' => 3]);
    }
}

final readonly class ManualServiceClock implements BookingClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2047-06-10 09:00:00', new DateTimeZone('Europe/Budapest'));
    }
}
