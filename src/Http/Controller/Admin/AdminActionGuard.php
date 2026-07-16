<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Domain\Booking\AdminNote;
use App\Security\Csrf\CsrfTokenManager;

/** Common fail-closed validation performed before an admin write transaction starts. */
final readonly class AdminActionGuard
{
    public const MAX_BODY_BYTES = 8192;

    public function __construct(
        private AdminAuthWorkflow $auth,
        private CsrfTokenManager $csrf,
        private AdminActionRateLimiter $rateLimiter,
    ) {
    }

    /** @param array<string, mixed> $form */
    public function authorizeForm(string $action, array $form, ?string $contentType, ?int $contentLength): AdminActionGuardResult
    {
        $admin = $this->auth->currentAdmin();
        if ($admin === null) {
            return $this->reject(new RedirectResponse('/admin/login'));
        }

        if (!$this->isFormContentType($contentType)) {
            return $this->reject($this->error(415, 'A kérés formátuma nem támogatott.'));
        }
        if ($contentLength === null || $contentLength < 0 || $contentLength > self::MAX_BODY_BYTES) {
            return $this->reject($this->error(413, 'A kérés túl nagy vagy a mérete nem ellenőrizhető.'));
        }
        if (!$this->csrf->isValid($form['_csrf'] ?? null)) {
            return $this->reject($this->error(403, 'A művelet nem hajtható végre.'));
        }

        $rawNote = $form['admin_note'] ?? null;
        if ($rawNote !== null && !is_string($rawNote)) {
            return $this->reject($this->error(422, 'Az admin megjegyzés érvénytelen.'));
        }
        $note = is_string($rawNote) ? trim($rawNote) : null;
        $note = $note === '' ? null : $note;
        try {
            $validatedNote = (new AdminNote($note))->value;
        } catch (\InvalidArgumentException) {
            return $this->reject($this->error(422, 'Az admin megjegyzés túl hosszú.'));
        }

        if (!$this->rateLimiter->allow($admin['id'], $action)) {
            return $this->reject($this->error(429, 'Túl sok művelet. Próbálja újra később.'));
        }

        return new AdminActionGuardResult($admin, $validatedNote);
    }

    private function isFormContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        return $mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data';
    }

    private function error(int $status, string $message): HtmlResponse
    {
        return new HtmlResponse('<!doctype html><html lang="hu"><meta charset="utf-8"><title>Admin hiba</title><p>'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></html>', $status);
    }

    private function reject(AdminResponse $response): AdminActionGuardResult
    {
        return new AdminActionGuardResult(null, null, $response);
    }
}
