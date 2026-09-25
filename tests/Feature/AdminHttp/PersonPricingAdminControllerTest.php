<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Pricing\PersonPricingRepository;
use App\Domain\Pricing\ChildPriceBand;
use App\Domain\Pricing\PersonPricingConfiguration;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\PersonPricingAdminController;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PHPUnit\Framework\TestCase;

final class PersonPricingAdminControllerTest extends TestCase
{
    private PersonUiRepository $repository;
    private CsrfTokenManager $csrf;
    private PersonPricingAdminController $controller;

    protected function setUp(): void
    {
        $this->repository = new PersonUiRepository();
        $storage = new class implements SessionStorage {
            private array $data = [];
            public function start(): void {}
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function remove(string $key): void { unset($this->data[$key]); }
            public function destroy(): void { $this->data = []; }
        };
        $this->csrf = new CsrfTokenManager($storage);
        $this->controller = $this->controller(true);
    }

    public function testRequiresAuthenticationAndCsrfBeforeChangingAnyPrice(): void
    {
        self::assertSame('/admin/login', $this->controller(false)->index()->location);
        self::assertSame(403, $this->controller->save([], 'application/x-www-form-urlencoded', 100)->status);
        self::assertSame(0, $this->repository->writes);
    }

    public function testSavesWholeAdultRatesEnablesPersonPricingWithPrg(): void
    {
        $response = $this->submit([
            'action' => 'settings',
            'adult_weekday_price' => '20000', 'adult_weekend_price' => '25000',
        ]);
        self::assertSame('/admin/pricing?saved=1', $response->location);
        self::assertSame('person', $this->repository->configuration->mode);
        self::assertSame('20000.00', $this->repository->configuration->adultWeekdayPrice);
        self::assertStringContainsString('Felnőtt', $this->controller->index()->body);
        self::assertStringContainsString('value="20000"', $this->controller->index()->body);
        self::assertStringNotContainsString('20000.00', $this->controller->index()->body);
        self::assertStringContainsString('A péntek és szombat éjszaka hétvégi árnak számít.', $this->controller->index()->body);
        self::assertStringNotContainsString('Árazási mód', $this->controller->index()->body);
    }

    public function testAddsEditsAndDeletesAgeBand(): void
    {
        $form = ['action' => 'band', 'band_id' => '0', 'min_age' => '0', 'max_age' => '2',
            'weekday_price' => '0', 'weekend_price' => '0'];
        self::assertSame('/admin/pricing?saved=1', $this->submit($form)->location);
        self::assertCount(1, $this->repository->configuration->childBands);
        $form['band_id'] = '1';
        $form['max_age'] = '3';
        self::assertSame('/admin/pricing?saved=1', $this->submit($form)->location);
        self::assertCount(1, $this->repository->configuration->childBands);
        self::assertTrue($this->repository->configuration->childBands[0]->active);
        self::assertSame(3, $this->repository->configuration->childBands[0]->maxAge);
        $html = $this->controller->index()->body;
        self::assertStringContainsString('data-confirm="Biztosan törli ezt a gyermek ársávot?"', $html);
        self::assertStringNotContainsString('Sorrend', $html);
        self::assertStringNotContainsString('name="active"', $html);
        self::assertSame('/admin/pricing?deleted=1', $this->submit(['action'=>'delete','band_id'=>'1'])->location);
        self::assertCount(0, $this->repository->configuration->childBands);
        self::assertStringContainsString('Nincs minden gyermek életkorhoz ár beállítva. Az érintett életkorral foglalás addig nem küldhető be.', $this->controller->index()->body);
    }

    public function testCannotEnablePersonModeWithEitherAdultRateMissing(): void
    {
        foreach ([['', '10000'], ['10000', ''], ['', '']] as [$weekday, $weekend]) {
            self::assertSame(422, $this->submit(['action' => 'settings',
                'adult_weekday_price' => $weekday, 'adult_weekend_price' => $weekend])->status);
        }
        self::assertSame(0, $this->repository->writes);
        self::assertSame('legacy', $this->repository->configuration->mode);
    }

    public function testRejectsStaleVersionOverlapAndFractionalOrNegativePrice(): void
    {
        self::assertSame(409, $this->submit(['version' => '99', 'action' => 'settings'])->status);
        $settings = ['action' => 'settings', 'adult_weekday_price' => '100.50', 'adult_weekend_price' => '100'];
        self::assertSame(422, $this->submit($settings)->status);
        $settings['adult_weekday_price'] = '-1';
        self::assertSame(422, $this->submit($settings)->status);
        $band = ['action' => 'band', 'band_id' => '0', 'min_age' => '0', 'max_age' => '6',
            'weekday_price' => '5000', 'weekend_price' => '6000'];
        $this->submit($band);
        $band['min_age'] = '6';
        $band['max_age'] = '10';
        self::assertSame(422, $this->submit($band)->status);
        self::assertSame(1, $this->repository->writes);
    }

    private function submit(array $form): \App\Http\Controller\Admin\AdminResponse
    {
        return $this->controller->save($form + [
            '_csrf' => $this->csrf->token(), 'version' => (string) $this->repository->configuration->version,
        ], 'application/x-www-form-urlencoded', 400);
    }

    private function controller(bool $loggedIn): PersonPricingAdminController
    {
        $auth = new class($loggedIn) implements AdminAuthWorkflow {
            public function __construct(private bool $loggedIn) {}
            public function currentAdmin(): ?array { return $this->loggedIn ? ['id' => 1, 'name' => 'Test'] : null; }
            public function login(string $email, string $password, array $requestContext = []): bool { return false; }
            public function verify(string $code, array $requestContext = []): bool { return false; }
            public function resend(array $requestContext = []): bool { return false; }
            public function logout(array $requestContext = []): void {}
        };
        $limiter = new class implements AdminActionRateLimiter {
            public function allow(int $adminId, string $action): bool { return true; }
        };
        return new PersonPricingAdminController($auth, new AdminView(dirname(__DIR__, 3) . '/templates'),
            $this->csrf, new AdminActionGuard($auth, $this->csrf, $limiter), $this->repository);
    }
}

final class PersonUiRepository implements PersonPricingRepository
{
    public PersonPricingConfiguration $configuration;
    public int $writes = 0;
    public function __construct() { $this->configuration = new PersonPricingConfiguration(); }
    public function get(): PersonPricingConfiguration { return $this->configuration; }
    public function save(PersonPricingConfiguration $configuration, int $expectedVersion, int $adminId): PersonPricingConfiguration
    {
        ++$this->writes;
        $bands = array_map(static fn (ChildPriceBand $band): ChildPriceBand => new ChildPriceBand(
            $band->minAge, $band->maxAge, $band->weekdayPrice, $band->weekendPrice,
            $band->active, $band->sortOrder, $band->id ?: 1,
        ), $configuration->childBands);
        return $this->configuration = new PersonPricingConfiguration($expectedVersion + 1,
            $configuration->mode, $configuration->adultWeekdayPrice, $configuration->adultWeekendPrice, $bands);
    }
    public function deleteBand(int $bandId, int $expectedVersion, int $adminId): PersonPricingConfiguration
    {
        ++$this->writes;
        $bands = array_values(array_filter(
            $this->configuration->childBands,
            static fn (ChildPriceBand $band): bool => $band->id !== $bandId,
        ));
        if (count($bands) === count($this->configuration->childBands)) {
            throw new \InvalidArgumentException();
        }
        return $this->configuration = new PersonPricingConfiguration(
            $expectedVersion + 1,
            $this->configuration->mode,
            $this->configuration->adultWeekdayPrice,
            $this->configuration->adultWeekendPrice,
            $bands,
        );
    }
}
