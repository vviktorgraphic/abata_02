<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Booking\BlockedPeriodConflict;
use App\Application\Booking\BlockedPeriodNotFound;
use App\Application\Booking\BlockedPeriodService;
use App\Domain\Booking\BlockedPeriodInvalid;
use App\Infrastructure\Persistence\Booking\PdoBlockedPeriodRepository;

final readonly class BlockedPeriodController
{
    public function __construct(private AdminAuthWorkflow $auth, private AdminView $view, private \App\Security\Csrf\CsrfTokenManager $csrf, private AdminActionGuard $guard, private BlockedPeriodService $service, private PdoBlockedPeriodRepository $repository) {}

    public function index(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('blocked-periods', ['periods' => $this->repository->active(), 'csrfToken' => $this->csrf->token()]));
    }

    /** @param array<string, mixed> $form */
    public function create(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('blocked_period.create', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        foreach (['start_date', 'end_date', 'reason'] as $key) if (!is_string($form[$key] ?? null)) return $this->error(422, 'A blokkolt időszak adatai hiányosak.');
        $reason = trim($form['reason']);
        if ($reason === '' || mb_strlen($reason) > 255) return $this->error(422, 'Az indoklás 1–255 karakter lehet.');
        try {
            $created = $this->service->create($form['start_date'], $form['end_date'], $reason, $authorization->adminNote, $authorization->admin['id']);
            $warning = $created->overlappingPendingReferences === [] ? '' : '&warning=' . count($created->overlappingPendingReferences);
            return new RedirectResponse('/admin/blocked-periods?created=1' . $warning);
        } catch (BlockedPeriodConflict) { return $this->error(409, 'Az időszak megerősített foglalással ütközik.'); }
        catch (BlockedPeriodInvalid|\InvalidArgumentException) { return $this->error(422, 'A blokkolt időszak érvénytelen.'); }
    }

    /** @param array<string, mixed> $form */
    public function remove(string $id, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('blocked_period.remove', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        if (!ctype_digit($id) || (int) $id < 1) return $this->error(404, 'A blokkolt időszak nem található.');
        try { $this->service->remove((int) $id, $authorization->admin['id']); }
        catch (BlockedPeriodNotFound) { return $this->error(404, 'A blokkolt időszak nem található.'); }
        return new RedirectResponse('/admin/blocked-periods?removed=1');
    }

    private function error(int $status, string $message): HtmlResponse { return new HtmlResponse($this->view->render('error', ['message' => $message]), $status); }
}
