<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Booking\AdminMonthlyOccupancyQuery;
use App\Application\Booking\AdminMonthlyOccupancyRepository;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class MonthlyOccupancyController
{
    private Closure $now;

    public function __construct(
        private readonly AdminAuthWorkflow $auth,
        private readonly AdminView $view,
        private readonly AdminMonthlyOccupancyRepository $repository,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone(AdminMonthlyOccupancyQuery::TIMEZONE));
    }

    /** @param array<string, mixed> $query */
    public function index(array $query): AdminResponse
    {
        $admin = $this->auth->currentAdmin();
        if ($admin === null) {
            return new RedirectResponse('/admin/login');
        }
        try {
            $month = new AdminMonthlyOccupancyQuery(
                array_key_exists('month', $query) ? $query['month'] : null,
                ($this->now)(),
            );
        } catch (\InvalidArgumentException) {
            return new HtmlResponse($this->view->render('error', ['message' => 'A megadott hónap érvénytelen.']), 422);
        }

        return new HtmlResponse($this->view->render('bookings-monthly', [
            'admin' => $admin,
            'occupancy' => $this->repository->fetch($month),
        ]));
    }
}
