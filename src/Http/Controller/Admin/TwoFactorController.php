<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Security\Csrf\CsrfTokenManager;

final readonly class TwoFactorController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
    )
    {
    }

    public function show(?string $message = null): AdminResponse
    {
        return new HtmlResponse($this->view->render('verify', [
            'message' => $message,
            'csrfToken' => $this->csrf->token(),
        ]));
    }

    /** @param array<string, mixed> $input @param array<string, string> $context */
    public function verify(array $input, array $context = []): AdminResponse
    {
        if (!$this->csrf->isValid($input['_csrf'] ?? null)) {
            return $this->csrfFailure();
        }
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        if (!$this->auth->verify($code, $context)) {
            return new HtmlResponse($this->view->render('verify', [
                'error' => 'A kód nem fogadható el. Kérj új kódot, vagy jelentkezz be újra.',
                'csrfToken' => $this->csrf->token(),
            ]), 422);
        }
        return new RedirectResponse('/admin');
    }

    /** @param array<string, string> $context */
    public function resend(array $input = [], array $context = []): AdminResponse
    {
        if (!$this->csrf->isValid($input['_csrf'] ?? null)) {
            return $this->csrfFailure();
        }
        if (!$this->auth->resend($context)) {
            return new HtmlResponse($this->view->render('verify', [
                'error' => 'Most nem küldhető új kód. Próbáld meg később.',
                'csrfToken' => $this->csrf->token(),
            ]), 429);
        }
        return $this->show('Ha a bejelentkezés folytatható, új kódot küldtünk.');
    }

    private function csrfFailure(): HtmlResponse
    {
        return new HtmlResponse($this->view->render('error', [
            'message' => 'A kérés nem hajtható végre. Frissítsd az oldalt, és próbáld újra.',
        ]), 403);
    }
}
