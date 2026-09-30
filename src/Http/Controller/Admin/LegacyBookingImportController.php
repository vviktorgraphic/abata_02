<?php
declare(strict_types=1);
namespace App\Http\Controller\Admin;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditMetadata;
use App\Application\LegacyImport\{LegacyImportCsvParser, LegacyImportOptions, LegacyImportPreview, LegacyImportService};
use App\Security\Session\SessionStorage;
use DateTimeImmutable;
use DateTimeZone;

final readonly class LegacyBookingImportController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private \App\Security\Csrf\CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private SessionStorage $session,
        private LegacyImportService $service,
        private \App\Application\Audit\AuditLog $audit,
    ) {}

    public function show(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('legacy-booking-import', ['csrfToken'=>$this->csrf->token(), 'preview'=>null]));
    }

    /** @param array<string,mixed> $form @param array<string,mixed> $files */
    public function preview(array $form, array $files, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('legacy_booking_import.preview', $form, $contentType, $contentLength, 5 * 1024 * 1024);
        if (!$authorization->allowed()) return $authorization->rejection;
        try {
            $file = $files['csv'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new \InvalidArgumentException('Válasszon érvényes CSV fájlt.');
            }
            if (($file['size'] ?? 0) > 5 * 1024 * 1024 || strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
                throw new \InvalidArgumentException('Legfeljebb 5 MB méretű CSV fájl tölthető fel.');
            }
            $csv = file_get_contents($file['tmp_name']);
            if (!is_string($csv)) throw new \InvalidArgumentException('A fájl nem olvasható.');
            $preview = $this->service->preview($csv);
            $this->session->set('legacy_import_csv', $csv);
            $this->audit->append(new AuditEvent('legacy_booking_import.previewed','success',new DateTimeImmutable('now',new DateTimeZone('Europe/Budapest')),new AuditMetadata(['source_system'=>'wpbs','total_rows'=>(string)$preview->total()]),$authorization->admin['id']));
            $options = new LegacyImportOptions();
            return new HtmlResponse($this->view->render('legacy-booking-import',['csrfToken'=>$this->csrf->token(),'preview'=>$preview,'duplicates'=>$this->service->existingDuplicateCount($preview,$options),'conflicts'=>$this->service->conflictCount($preview,$options)]));
        } catch (\InvalidArgumentException $e) {
            return new HtmlResponse($this->view->render('legacy-booking-import',['csrfToken'=>$this->csrf->token(),'preview'=>null,'error'=>$e->getMessage()]),422);
        }
    }

    /** @param array<string,mixed> $form */
    public function commit(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('legacy_booking_import.commit', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        $csv = $this->session->get('legacy_import_csv');
        $this->session->remove('legacy_import_csv');
        try {
            if (!is_string($csv) || $csv === '') throw new \InvalidArgumentException('Az előnézeti fájl lejárt. Töltse fel újra.');
            $statuses = !empty($form['include_trash']) ? ['accepted', 'pending', 'trash'] : ['accepted', 'pending'];
            $result = $this->service->import($this->service->preview($csv), new LegacyImportOptions($statuses), (int)$authorization->admin['id']);
            $this->audit->append(new AuditEvent('legacy_booking_import.committed','success',new DateTimeImmutable('now',new DateTimeZone('Europe/Budapest')),new AuditMetadata(['source_system'=>'wpbs','batch_id'=>$result->batchId,'imported'=>(string)$result->imported,'duplicates'=>(string)$result->duplicates,'skipped'=>(string)$result->skipped,'invalid'=>(string)$result->invalid]),$authorization->admin['id']));
            return new HtmlResponse($this->view->render('legacy-booking-import-result',['result'=>$result]));
        } catch (\InvalidArgumentException $e) {
            return new HtmlResponse($this->view->render('legacy-booking-import',['csrfToken'=>$this->csrf->token(),'preview'=>null,'error'=>$e->getMessage()]),409);
        }
    }
}
