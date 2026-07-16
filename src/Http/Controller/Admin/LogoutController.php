<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Security\Csrf\CsrfTokenManager;

final readonly class LogoutController
{
    public function __construct(private AdminAuthWorkflow $auth, private CsrfTokenManager $csrf, private AdminView $view)
    {
    }

    /** @param array<string, mixed> $input @param array<string, string> $context */
    public function submit(array $input, array $context = []): AdminResponse
    {
        if (!$this->csrf->isValid($input['_csrf'] ?? null)) {
            return new HtmlResponse($this->view->render('error', ['message' => 'A kérés nem hajtható végre. Frissítsd az oldalt, és próbáld újra.']), 403);
        }
        $this->auth->logout($context);
        return new RedirectResponse('/admin/login');
    }
}
