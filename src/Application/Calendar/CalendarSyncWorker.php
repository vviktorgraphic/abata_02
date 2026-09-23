<?php
declare(strict_types=1);
namespace App\Application\Calendar;

final readonly class CalendarSyncWorker
{
    public function __construct(private CalendarSourceRepository $sources, private CalendarImportService $importer) {}

    /** @param callable(array<string,mixed>):void $emit */
    public function run(callable $emit): int
    {
        $success = $failed = $locked = 0;
        foreach ($this->sources->all() as $source) {
            if (!(bool) $source['enabled'] || !in_array($source['direction'], ['import', 'bidirectional'], true)) {
                continue;
            }
            $id = (int) $source['id'];
            try {
                $result = $this->importer->import($id);
                if ($result->status === 'locked') {
                    ++$locked;
                } elseif ($result->status === 'failed') {
                    ++$failed;
                } else {
                    ++$success;
                }
                $emit(['event' => 'ical_source_sync', 'source_id' => $id, 'status' => $result->status,
                    'imported' => $result->imported, 'updated' => $result->updated, 'duplicates' => $result->duplicates,
                    'inactivated' => $result->inactivated, 'grace_inactivated' => $result->graceInactivated,
                    'retries' => $result->retries, 'recovered_runs' => $result->recoveredRuns]);
            } catch (\Throwable) {
                ++$failed;
                $emit(['event' => 'ical_source_sync', 'source_id' => $id, 'status' => 'failed', 'error' => 'source_sync_failed']);
            }
        }
        $emit(['event' => 'ical_worker_complete', 'successful' => $success, 'failed' => $failed, 'locked' => $locked]);
        return $failed > 0 ? 1 : ($locked > 0 ? 2 : 0);
    }
}
