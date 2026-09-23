<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Pricing\PersonPricingRepository;
use App\Application\Pricing\PricingVersionConflict;
use App\Domain\Pricing\ChildPriceBand;
use App\Domain\Pricing\PersonPricingConfiguration;
use App\Domain\Pricing\PricingConfigurationError;
use App\Security\Csrf\CsrfTokenManager;

final readonly class PersonPricingAdminController
{
    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private PersonPricingRepository $repository,
    ) {}

    public function index(?string $error = null, int $status = 200): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) {
            return new RedirectResponse('/admin/login');
        }
        return new HtmlResponse($this->view->render('person-pricing', [
            'configuration' => $this->repository->get(),
            'csrfToken' => $this->csrf->token(),
            'error' => $error,
        ]), $status);
    }

    /** @param array<string,mixed> $form */
    public function save(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization = $this->guard->authorizeForm('person_pricing.update', $form, $contentType, $contentLength);
        if (!$authorization->allowed()) {
            return $authorization->rejection;
        }
        try {
            $version = $this->integer($form['version'] ?? null, 1, PHP_INT_MAX);
            $current = $this->repository->get();
            if ($version !== $current->version) {
                return $this->index('Az árakat közben módosították. Ellenőrizze az aktuális adatokat, majd mentse újra.', 409);
            }
            $bands = $current->childBands;
            if (($form['action'] ?? null) === 'settings') {
                $mode = $form['mode'] ?? null;
                if (!is_string($mode) || !in_array($mode, ['legacy', 'person'], true)) {
                    throw new \InvalidArgumentException();
                }
                $configuration = new PersonPricingConfiguration(
                    $version, $mode,
                    $this->amount($form['adult_weekday_price'] ?? null, true),
                    $this->amount($form['adult_weekend_price'] ?? null, true),
                    $bands,
                );
                if ($mode === 'person' && ($configuration->adultWeekdayPrice === null || $configuration->adultWeekendPrice === null)) {
                    throw new \InvalidArgumentException();
                }
            } elseif (($form['action'] ?? null) === 'band') {
                $id = $this->integer($form['band_id'] ?? '0', 0, PHP_INT_MAX);
                $band = new ChildPriceBand(
                    $this->integer($form['min_age'] ?? null, 0, 17),
                    $this->integer($form['max_age'] ?? null, 0, 17),
                    $this->amount($form['weekday_price'] ?? null),
                    $this->amount($form['weekend_price'] ?? null),
                    isset($form['active']),
                    $this->integer($form['sort_order'] ?? null, 0, 32767),
                    $id,
                );
                $found = false;
                foreach ($bands as $index => $existing) {
                    if ($id !== 0 && $existing->id === $id) {
                        $bands[$index] = $band;
                        $found = true;
                        break;
                    }
                }
                if ($id === 0) {
                    $bands[] = $band;
                } elseif (!$found) {
                    return $this->index('A gyermek ársáv nem található.', 404);
                }
                $configuration = new PersonPricingConfiguration(
                    $version, $current->mode, $current->adultWeekdayPrice, $current->adultWeekendPrice, $bands,
                );
            } else {
                throw new \InvalidArgumentException();
            }
            $this->repository->save($configuration, $version, $authorization->admin['id']);
            return new RedirectResponse('/admin/pricing/person?saved=1');
        } catch (PricingVersionConflict) {
            return $this->index('Az árakat közben módosították. Ellenőrizze az aktuális adatokat, majd mentse újra.', 409);
        } catch (PricingConfigurationError|\InvalidArgumentException) {
            return $this->index('Nem menthető: egész, nem negatív forintárak, 0–17 év közötti rendezett korhatárok és egymást nem fedő aktív ársávok szükségesek. Személyalapú módhoz mindkét felnőttár kötelező.', 422);
        }
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > strlen((string) $max)
            || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)) {
            throw new \InvalidArgumentException();
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException();
        }
        return $number;
    }

    private function amount(mixed $value, bool $nullable = false): ?string
    {
        if ($nullable && ($value === '' || $value === null)) {
            return null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.00)?$/D', $value) !== 1) {
            throw new \InvalidArgumentException();
        }
        return str_contains($value, '.') ? $value : $value . '.00';
    }
}
