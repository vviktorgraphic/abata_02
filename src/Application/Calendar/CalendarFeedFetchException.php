<?php

declare(strict_types=1);

namespace App\Application\Calendar;

final class CalendarFeedFetchException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly string $category = 'fetch_rejected')
    {
        parent::__construct($message);
    }
}
