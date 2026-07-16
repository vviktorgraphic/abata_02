<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use App\Application\Pricing\PricingPreviewer;
use App\Application\Pricing\PricingConfigurationException;
use App\Application\Pricing\PricingRuleRepository;
use App\Domain\Pricing\PricingConfigurationError;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingRule;
use App\Security\Csrf\CsrfTokenManager;

final readonly class PricingAdminController
{
    private const TYPES = ['stay_length', 'base', 'seasonal', 'weekend', 'fixed_fee', 'tourism_tax', 'exemption'];

    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private PricingRuleRepository $rules,
        private PricingPreviewer $previewer,
        private AuditLog $audit,
    ) {}

    public function index(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('pricing', [
            'rules' => $this->rules->listAll(), 'csrfToken' => $this->csrf->token(),
        ]));
    }

    public function createForm(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('pricing-form', [
            'rule' => $this->defaults(), 'csrfToken' => $this->csrf->token(), 'editing' => false,
        ]));
    }

    public function editForm(string $id): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        $rule = $this->lookup($id);
        if ($rule === null) return $this->error(404, 'Az árszabály nem található.');
        return new HtmlResponse($this->view->render('pricing-form', [
            'rule' => $rule, 'csrfToken' => $this->csrf->token(), 'editing' => true,
        ]));
    }

    /** @param array<string,mixed> $form */
    public function create(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('pricing_rule.create', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        try {
            $values = $this->validated($form);
            if ((int) $values['is_active'] === 1 && $this->conflicts($values)) return $this->conflict($authorization->admin['id']);
            $id = $this->rules->create($values, $authorization->admin['id']);
            $this->audit('pricing_rule.created', $authorization->admin['id'], $id);
            return new RedirectResponse('/admin/pricing?created=1');
        } catch (\InvalidArgumentException) { return $this->error(422, 'Az árszabály adatai érvénytelenek.'); }
    }

    /** @param array<string,mixed> $form */
    public function update(string $id, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('pricing_rule.update', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        $existing = $this->lookup($id);
        if ($existing === null) return $this->error(404, 'Az árszabály nem található.');
        try {
            $values = $this->validated($form);
            if ((int) $values['is_active'] === 1 && $this->conflicts($values, (int) $id)) return $this->conflict($authorization->admin['id'], (int) $id);
            // The record was already IDOR-safely resolved; an unchanged PDO update may report zero affected rows.
            $this->rules->update((int) $id, $values, $authorization->admin['id']);
            $this->audit('pricing_rule.updated', $authorization->admin['id'], (int) $id);
            return new RedirectResponse('/admin/pricing?updated=1');
        } catch (\InvalidArgumentException) { return $this->error(422, 'Az árszabály adatai érvénytelenek.'); }
    }

    /** @param array<string,mixed> $form */
    public function activate(string $id, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    { return $this->setActive($id, true, $form, $contentType, $contentLength); }

    /** @param array<string,mixed> $form */
    public function deactivate(string $id, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    { return $this->setActive($id, false, $form, $contentType, $contentLength); }

    /** @param array<string,mixed> $form */
    public function preview(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('pricing.preview', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        try {
            foreach (['arrival_date','departure_date','adults'] as $key) if (!is_string($form[$key] ?? null)) throw new \InvalidArgumentException();
            $adults = $this->integer($form['adults'], 0, 30);
            $ages = $this->integerList($form['child_ages'] ?? '', 0, 17, 30);
            $keys = $this->keyList($form['exemption_keys'] ?? '');
            $result = $this->previewer->preview(new PricingInput($form['arrival_date'], $form['departure_date'], $adults, $ages, $keys));
            $this->audit('pricing.previewed', $authorization->admin['id']);
            return new HtmlResponse($this->view->render('pricing-preview', [
                'result' => $result, 'input' => $form, 'csrfToken' => $this->csrf->token(), 'error' => null,
            ]));
        } catch (PricingConfigurationError|PricingConfigurationException $e) {
            $this->audit('pricing.configuration_conflict', $authorization->admin['id']);
            return new HtmlResponse($this->view->render('pricing-preview', [
                'result' => null, 'input' => $form, 'csrfToken' => $this->csrf->token(),
                'error' => 'Az árkonfiguráció ellentmondásos; az előnézet nem számítható ki.',
            ]), 409);
        } catch (\InvalidArgumentException) { return $this->error(422, 'Az előnézet adatai érvénytelenek.'); }
    }

    /** @param array<string,mixed> $form */
    private function setActive(string $id, bool $active, array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $event = $active ? 'pricing_rule.activated' : 'pricing_rule.deactivated';
        $authorization = $this->guard->authorizeForm($event, $form, $contentType, $contentLength);
        if (!$authorization->allowed()) return $authorization->rejection;
        $rule = $this->lookup($id);
        if ($rule === null) return $this->error(404, 'Az árszabály nem található.');
        if ($active && $this->conflicts($rule, (int) $id)) return $this->conflict($authorization->admin['id'], (int) $id);
        // Idempotent activation/deactivation is a successful PRG action.
        $this->rules->setActive((int) $id, $active, $authorization->admin['id']);
        $this->audit($event, $authorization->admin['id'], (int) $id);
        return new RedirectResponse('/admin/pricing?' . ($active ? 'activated' : 'deactivated') . '=1');
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    private function validated(array $form): array
    {
        $allowed = ['name','rule_type','valid_from','valid_until','amount','adjustment_mode','base_unit','minimum_nights','maximum_nights','applicable_weekdays','exemption_key','priority','is_active'];
        $form = array_intersect_key($form, array_flip($allowed));
        foreach (['name','rule_type','valid_from','valid_until','amount','priority'] as $key) if (!is_string($form[$key] ?? null)) throw new \InvalidArgumentException();
        $name = trim($form['name']);
        if ($name === '' || mb_strlen($name) > 190 || !in_array($form['rule_type'], self::TYPES, true)) throw new \InvalidArgumentException();
        $from = $this->date($form['valid_from']); $until = $this->date($form['valid_until']);
        if ($from >= $until || !preg_match('/^(?:0|[1-9]\d{0,9})\.\d{2}$/', $form['amount'])) throw new \InvalidArgumentException();
        $type = $form['rule_type'];
        $base = is_string($form['base_unit'] ?? null) && in_array($form['base_unit'], PricingRule::BASE_UNITS, true) ? $form['base_unit'] : null;
        $mode = is_string($form['adjustment_mode'] ?? null) && in_array($form['adjustment_mode'], PricingRule::ADJUSTMENT_MODES, true) ? $form['adjustment_mode'] : null;
        if (in_array($type, ['base','stay_length','tourism_tax'], true) && $base === null) throw new \InvalidArgumentException();
        if (in_array($type, ['seasonal','weekend'], true) && $mode === null) throw new \InvalidArgumentException();
        $min = $this->optionalInteger($form['minimum_nights'] ?? null, 1, 3650);
        $max = $this->optionalInteger($form['maximum_nights'] ?? null, 1, 3650);
        if ($min !== null && $max !== null && $min > $max) throw new \InvalidArgumentException();
        $weekdays = $this->integerList($form['applicable_weekdays'] ?? '', 1, 7, 7);
        if ($type === 'weekend' && $weekdays === []) throw new \InvalidArgumentException();
        $exemption = is_string($form['exemption_key'] ?? null) ? trim($form['exemption_key']) : '';
        if (($type === 'exemption' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $exemption)) || ($type !== 'exemption' && $exemption !== '')) throw new \InvalidArgumentException();
        return ['name'=>$name,'rule_type'=>$type,'valid_from'=>$from,'valid_until'=>$until,'nightly_price'=>$form['amount'],
            'amount'=>$form['amount'],'adjustment_mode'=>$mode ?? 'fixed','base_unit'=>$base ?? 'per_booking','currency'=>'HUF',
            'minimum_nights'=>$min ?? 1,'maximum_nights'=>$max,'applicable_weekdays'=>$weekdays === [] ? null : json_encode(array_values(array_unique($weekdays)), JSON_THROW_ON_ERROR),
            'exemption_key'=>$exemption === '' ? null : $exemption,'priority'=>$this->integer($form['priority'], 0, 32767),'is_active'=>isset($form['is_active']) ? 1 : 0];
    }

    /** @param array<string,mixed> $values */
    private function conflicts(array $values, ?int $exceptId = null): bool
    { return $this->rules->hasEqualPriorityConflict((string)$values['rule_type'], (string)$values['valid_from'], (string)$values['valid_until'], (int)$values['priority'], $exceptId); }
    private function conflict(int $adminId, ?int $id = null): HtmlResponse { $this->audit('pricing.configuration_conflict', $adminId, $id); return $this->error(409, 'Azonos prioritású, átfedő aktív árszabály már létezik.'); }
    /** @return array<string,mixed>|null */
    private function lookup(string $id): ?array { return ctype_digit($id) && (int)$id > 0 ? $this->rules->find((int)$id) : null; }
    private function date(string $value): string { $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Budapest')); if (!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException(); return $value; }
    private function integer(string $value, int $min, int $max): int { if (!preg_match('/^(?:0|[1-9]\d*)$/',$value)) throw new \InvalidArgumentException(); $n=(int)$value; if ($n<$min||$n>$max) throw new \InvalidArgumentException(); return $n; }
    private function optionalInteger(mixed $value,int $min,int $max): ?int { return $value === null || $value === '' ? null : (is_string($value) ? $this->integer($value,$min,$max) : throw new \InvalidArgumentException()); }
    /** @return list<int> */ private function integerList(mixed $value,int $min,int $max,int $limit): array { if (!is_string($value)) throw new \InvalidArgumentException(); if (trim($value)==='') return []; $parts=array_map('trim',explode(',',$value)); if(count($parts)>$limit) throw new \InvalidArgumentException(); return array_map(fn(string $v):int=>$this->integer($v,$min,$max),$parts); }
    /** @return list<string> */ private function keyList(mixed $value): array { if(!is_string($value)) throw new \InvalidArgumentException(); if(trim($value)==='') return []; $keys=array_map('trim',explode(',',$value)); if(count($keys)>30) throw new \InvalidArgumentException(); foreach($keys as $key) if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/',$key)) throw new \InvalidArgumentException(); return array_values(array_unique($keys)); }
    private function audit(string $type,int $adminId,?int $id=null):void { $metadata=['target_type'=>'pricing_rule']; if($id!==null)$metadata['target_id']=(string)$id; $this->audit->append(new AuditEvent($type,'success',new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')),new AuditMetadata($metadata),$adminId)); }
    /** @return array<string,mixed> */ private function defaults():array { return ['name'=>'','rule_type'=>'base','valid_from'=>'','valid_until'=>'','amount'=>'0.00','adjustment_mode'=>'fixed','base_unit'=>'per_person_per_night','minimum_nights'=>'1','maximum_nights'=>'','applicable_weekdays'=>'','exemption_key'=>'','priority'=>'0','is_active'=>1]; }
    private function error(int $status,string $message):HtmlResponse { return new HtmlResponse($this->view->render('error',['message'=>$message]),$status); }
}
