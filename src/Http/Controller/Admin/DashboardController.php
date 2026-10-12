<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Booking\AdminDashboardClock;
use App\Application\Booking\AdminDashboardRepository;
use App\Security\Csrf\CsrfTokenManager;
use DateTimeZone;

final readonly class DashboardController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
        private AdminDashboardRepository $dashboard,
        private AdminDashboardClock $clock,
    ) {
    }

    public function show(): AdminResponse
    {
        $admin = $this->auth->currentAdmin();
        if ($admin === null) {
            return new RedirectResponse('/admin/login');
        }
        $today = $this->clock->today()
            ->setTimezone(new DateTimeZone('Europe/Budapest'))
            ->setTime(0, 0);

        return new HtmlResponse($this->view->render('dashboard', [
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'today' => $today,
            'overview' => $this->dashboard->fetch($today),
        ]));
    }
}
