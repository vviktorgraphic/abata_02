<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final readonly class AdminView
{
    public function __construct(private string $templateDirectory, private ?\App\Security\Csrf\CsrfTokenManager $csrf = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $path = $this->templateDirectory . '/admin/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Admin template not found.');
        }

        if ($this->csrf !== null && !in_array($template, ['login','verify','error'], true)) {
            $data['csrfToken'] ??= $this->csrf->token();
            $data['showLogout'] = true;
        }
        extract($data, EXTR_SKIP);
        $initialBufferLevel = ob_get_level();
        ob_start();
        try {
            require $path;
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            while (ob_get_level() > $initialBufferLevel) {
                ob_end_clean();
            }
            throw $error;
        }
    }
}
