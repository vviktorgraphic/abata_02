<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Calendar\CalendarExportFeed;
use App\Application\Calendar\CalendarExportTokenRepository;
use App\Http\CalendarResponse;
use DateTimeImmutable;
use DateTimeZone;

final readonly class CalendarExportController
{
    public function __construct(
        private CalendarExportTokenRepository $tokens,
        private CalendarExportFeed $feed,
    ) {
    }

    /** @param array<string, mixed> $query */
    public function export(array $query): CalendarResponse
    {
        $token = $query['token'] ?? null;
        if (!is_string($token) || !$this->tokens->verify($token)) {
            return new CalendarResponse('', 404, $this->headers('text/plain; charset=utf-8'));
        }

        return new CalendarResponse(
            $this->feed->render(new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest'))),
            200,
            $this->headers('text/calendar; charset=utf-8'),
        );
    }

    /** @return array<string, string> */
    private function headers(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="calendar.ics"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
