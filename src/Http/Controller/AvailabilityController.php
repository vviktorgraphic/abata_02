<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Availability\GetAvailabilityHandler;
use App\Application\Availability\InvalidAvailabilityRange;
use App\Http\JsonResponse;

final readonly class AvailabilityController
{
    public function __construct(private GetAvailabilityHandler $handler)
    {
    }

    /** @param array<string, mixed> $query */
    public function index(array $query): void
    {
        try {
            $result = $this->handler->handle(
                is_string($query['from'] ?? null) ? $query['from'] : '',
                is_string($query['to'] ?? null) ? $query['to'] : '',
            );
            JsonResponse::send($result);
        } catch (InvalidAvailabilityRange $exception) {
            JsonResponse::send(['error' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            JsonResponse::send(['error' => 'Availability is temporarily unavailable.'], 500);
        }
    }
}

