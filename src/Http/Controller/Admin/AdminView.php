<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final readonly class AdminView
{
    public function __construct(private string $templateDirectory)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $path = $this->templateDirectory . '/admin/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('Admin template not found.');
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
