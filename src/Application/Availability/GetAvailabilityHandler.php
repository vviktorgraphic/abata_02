<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Domain\Availability\AvailabilityCalendarService;
use DateTimeImmutable;
use DateTimeZone;

final readonly class GetAvailabilityHandler
{
    private const TIMEZONE = 'Europe/Budapest';

    public function __construct(
        private BookingReadRepository $bookings,
        private BlockedPeriodReadRepository $blockedPeriods,
        private int $maximumQueryDays = 93,
        private int $minimumNights = 1,
        private int $maximumNights = 30,
        private int $bookingHorizonDays = 365,
        private ?DateTimeImmutable $today = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function handle(string $fromInput, string $toInput): array
    {
        $from = $this->parseDate($fromInput, 'from');
        $to = $this->parseDate($toInput, 'to');
        $days = (int) $from->diff($to)->format('%r%a');

        if ($days <= 0) {
            throw new InvalidAvailabilityRange('The to date must be later than the from date.');
        }
        if ($days > $this->maximumQueryDays) {
            throw new InvalidAvailabilityRange(sprintf('The requested range cannot exceed %d days.', $this->maximumQueryDays));
        }

        $today = $this->today ?? new DateTimeImmutable('today', new DateTimeZone(self::TIMEZONE));
        $calendar = new AvailabilityCalendarService($today);
        $dayItems = $calendar->build(
            $from,
            $to,
            $this->bookings->findBlockingBetween($from, $to),
            $this->blockedPeriods->findBetween($from, $to),
        );

        $horizon = $today->modify(sprintf('+%d days', $this->bookingHorizonDays));
        $serializedDays = array_map(static function ($day) use ($horizon): array {
            $item = $day->toArray();
            if ($day->date > $horizon) {
                $item['selectable_as_arrival'] = false;
                $item['selectable_as_departure'] = false;
            }
            return $item;
        }, $dayItems);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => self::TIMEZONE,
            'rules' => [
                'minimum_nights' => $this->minimumNights,
                'maximum_nights' => $this->maximumNights,
                'booking_horizon_days' => $this->bookingHorizonDays,
            ],
            'days' => $serializedDays,
        ];
    }

    private function parseDate(string $input, string $field): DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $input) {
            throw new InvalidAvailabilityRange(sprintf('The %s parameter must be a valid YYYY-MM-DD date.', $field));
        }

        return $date;
    }
}
