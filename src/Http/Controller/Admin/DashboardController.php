<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final readonly class DashboardController
{
    public function __construct(private AdminAuthWorkflow $auth, private AdminView $view, private \App\Security\Csrf\CsrfTokenManager $csrf)
    {
    }

    public function show(): AdminResponse
    {
        $admin = $this->auth->currentAdmin();
        if ($admin === null) {
            return new RedirectResponse('/admin/login');
        }
        return new HtmlResponse($this->view->render('dashboard', ['admin' => $admin, 'csrfToken' => $this->csrf->token()]));
    }
}
