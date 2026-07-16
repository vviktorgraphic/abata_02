<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Security\Csrf\CsrfTokenManager;

final readonly class LoginController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
    )
    {
    }

    public function show(): AdminResponse
    {
        if ($this->auth->currentAdmin() !== null) {
            return new RedirectResponse('/admin');
        }
        return new HtmlResponse($this->view->render('login', ['csrfToken' => $this->csrf->token()]));
    }

    /** @param array<string, mixed> $input @param array<string, string> $context */
    public function submit(array $input, array $context = []): AdminResponse
    {
        if (!$this->csrf->isValid($input['_csrf'] ?? null)) {
            return $this->csrfFailure();
        }
        $accepted = $this->auth->login(
            is_string($input['email'] ?? null) ? $input['email'] : '',
            is_string($input['password'] ?? null) ? $input['password'] : '',
            $context,
        );
        if (!$accepted) {
            return new HtmlResponse($this->view->render('login', [
                'error' => 'A megadott adatokkal nem sikerült bejelentkezni.',
                'csrfToken' => $this->csrf->token(),
            ]), 422);
        }
        return new RedirectResponse('/admin/2fa');
    }

    private function csrfFailure(): HtmlResponse
    {
        return new HtmlResponse($this->view->render('error', [
            'message' => 'A kérés nem hajtható végre. Frissítsd az oldalt, és próbáld újra.',
        ]), 403);
    }
}
