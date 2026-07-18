<?php

declare(strict_types=1);

namespace App\Application\Calendar;

use App\Domain\Calendar\IcsEvent;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class CalendarImportService
{
    private const PROVIDERS = ['google_calendar', 'szallas_hu'];

    public function __construct(
        private CalendarSourceRepository $sources,
        private CalendarSyncLogRepository $logs,
        private ExternalCalendarEventRepository $events,
        private SecureCalendarFeedFetcher $fetcher,
        private IcsParser $parser,
        private CalendarSyncClock $clock,
    ) {
    }

    public function import(int $sourceId): CalendarImportResult
    {
        $source = $this->sources->find($sourceId);
        if ($source === null) {
            throw new \InvalidArgumentException('Calendar source was not found.');
        }
        if (!(bool) ($source['enabled'] ?? false)) {
            throw new \LogicException('Disabled calendar source cannot be synchronized.');
        }
        if (!in_array((string) ($source['provider'] ?? ''), self::PROVIDERS, true)) {
            throw new \LogicException('Calendar source provider is not supported for import.');
        }
        if (!in_array((string) ($source['direction'] ?? ''), ['import', 'bidirectional'], true)) {
            throw new \LogicException('Calendar source direction does not allow import.');
        }

        $startedAt = $this->clock->now();
        $logId = $this->logs->start($sourceId, $startedAt);
        $imported = 0;
        $duplicates = 0;
        $warnings = [];
        $errors = [];
        try {
            $calendar = $this->parser->parse($this->fetcher->fetch((string) $source['url']));
            foreach ($calendar->events as $event) {
                try {
                    [$start, $end] = $this->calendarDates($event);
                    $result = $this->events->importEvent(
                        $sourceId,
                        $event->uid,
                        $event->summary === '' ? null : $event->summary,
                        $event->description === '' ? null : $event->description,
                        $start,
                        $end,
                        $this->payloadHash($event, $start, $end),
                        $this->clock->now(),
                        $event->status === 'CANCELLED',
                    );
                    if ($result->outcome === ImportedEventPersistenceResult::BLOCKED) {
                        ++$imported;
                    } elseif ($result->outcome === ImportedEventPersistenceResult::DUPLICATE) {
                        ++$duplicates;
                    } elseif ($result->outcome === ImportedEventPersistenceResult::CONFLICT) {
                        $warnings[] = sprintf('External event [%s] overlaps a confirmed booking; no blocked period was created.', $this->fingerprint($event->uid));
                    }
                } catch (Throwable $error) {
                    $safeError = str_replace($event->uid, '[redacted UID]', $error->getMessage());
                    $errors[] = sprintf('External event [%s] could not be imported: %s', $this->fingerprint($event->uid), $safeError);
                }
            }
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }

        $status = $errors !== [] ? 'failed' : ($warnings !== [] ? 'warning' : 'success');
        $finishedAt = $this->clock->now();
        $this->logs->finish($logId, $status, $finishedAt, $imported, 0, $warnings, $errors);
        if ($errors === []) {
            $this->sources->markSuccess($sourceId, $finishedAt);
        } else {
            $this->sources->markError($sourceId, $finishedAt);
        }
        return new CalendarImportResult($status, $imported, $duplicates, $warnings, $errors);
    }

    private function fingerprint(string $uid): string
    {
        return substr(hash('sha256', $uid), 0, 12);
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function calendarDates(IcsEvent $event): array
    {
        $zone = new DateTimeZone('Europe/Budapest');
        $start = $event->startsAt->setTimezone($zone)->setTime(0, 0);
        $localEnd = $event->endsAt->setTimezone($zone);
        $end = $localEnd->setTime(0, 0);
        if (!$event->allDay && $localEnd->format('H:i:s') !== '00:00:00') {
            $end = $end->modify('+1 day');
        }
        if ($end <= $start) {
            // A non-all-day event occupies its local Budapest calendar day.
            $end = $start->modify('+1 day');
        }
        return [$start, $end];
    }

    private function payloadHash(IcsEvent $event, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        return hash('sha256', json_encode([
            'uid' => $event->uid, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
            'summary' => $event->summary, 'description' => $event->description, 'status' => $event->status,
            'sequence' => $event->sequence, 'last_modified' => $event->lastModified?->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
    }
}
