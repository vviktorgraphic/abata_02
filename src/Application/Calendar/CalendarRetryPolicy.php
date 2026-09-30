<?php
declare(strict_types=1);
namespace App\Application\Calendar;

final readonly class CalendarRetryPolicy
{
    public function __construct(public int $maxRetries = 2, public int $baseDelaySeconds = 1)
    {
        if ($maxRetries < 0 || $maxRetries > 5 || $baseDelaySeconds < 1 || $baseDelaySeconds > 30) {
            throw new \InvalidArgumentException('Invalid calendar retry limits.');
        }
    }

    public function delay(int $retry): int
    {
        return min(60, $this->baseDelaySeconds * (2 ** ($retry - 1)));
    }
}
