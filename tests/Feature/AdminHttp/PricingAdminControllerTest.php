<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Pricing\PricingPreviewer;
use App\Application\Pricing\PricingRuleRepository;
use App\Domain\Pricing\PricingInput;
use App\Domain\Pricing\PricingResult;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\PricingAdminController;
use App\Http\Controller\Admin\RedirectResponse;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PHPUnit\Framework\TestCase;

final class PricingAdminControllerTest extends TestCase
{
    private PricingFakeRepository $repository;
    private PricingFakeAudit $audit;
    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $this->repository = new PricingFakeRepository(); $this->audit = new PricingFakeAudit();
        $this->csrf = new CsrfTokenManager(new PricingSession());
    }

    public function test_list_requires_authentication_and_form_is_branded_and_escaped(): void
    {
        self::assertSame('/admin/login', $this->controller(null)->index()->location);
        $this->repository->rows[] = $this->rule(['name'=>'<script>alert(1)</script>']);
        $response = $this->controller()->index();
        self::assertInstanceOf(HtmlResponse::class, $response);
        self::assertStringContainsString('A Bata', $response->body);
        self::assertStringNotContainsString('<script>alert', $response->body);
        self::assertStringContainsString('/admin/pricing/1/edit', $response->body);
        self::assertStringContainsString('Árkalkuláció előnézet', $response->body);
    }

    public function test_create_whitelists_validates_audits_and_uses_prg(): void
    {
        $response = $this->controller()->create($this->form()+['_csrf'=>$this->csrf->token(),'deleted_at'=>'attack'], 'application/x-www-form-urlencoded', 300);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/pricing?created=1', $response->location);
        self::assertArrayNotHasKey('deleted_at', $this->repository->created);
        self::assertSame('pricing_rule.created', $this->audit->events[0]->eventType);
    }

    public function test_weekend_rule_defaults_to_friday_and_saturday_nights(): void
    {
        $form = $this->form(['rule_type' => 'weekend', 'applicable_weekdays' => '']);
        $response = $this->controller()->create($form + ['_csrf' => $this->csrf->token()], 'application/x-www-form-urlencoded', 300);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('[5,6]', $this->repository->created['applicable_weekdays']);
    }

    public function test_csrf_invalid_number_and_equal_priority_conflict_are_rejected(): void
    {
        self::assertSame(403, $this->controller()->create($this->form(), 'application/x-www-form-urlencoded', 200)->status);
        self::assertSame(422, $this->controller()->create($this->form(['amount'=>'-1.00'])+['_csrf'=>$this->csrf->token()], 'application/x-www-form-urlencoded', 200)->status);
        $this->repository->conflict=true;
        self::assertSame(409, $this->controller()->create($this->form()+['_csrf'=>$this->csrf->token()], 'application/x-www-form-urlencoded', 200)->status);
        self::assertSame('pricing.configuration_conflict', end($this->audit->events)->eventType);
    }

    public function test_edit_deactivate_and_idor_safe_missing_lookup(): void
    {
        $this->repository->rows[]=$this->rule();
        self::assertSame(404, $this->controller()->editForm('../1')->status);
        self::assertStringContainsString('Árszabály szerkesztése', $this->controller()->editForm('1')->body);
        $response=$this->controller()->deactivate('1',['_csrf'=>$this->csrf->token()],'application/x-www-form-urlencoded',50);
        self::assertSame('/admin/pricing?deactivated=1',$response->location);
        self::assertFalse($this->repository->active);
        self::assertSame('pricing_rule.deactivated',end($this->audit->events)->eventType);
    }

    public function test_preview_uses_shared_boundary_and_renders_line_items(): void
    {
        $response=$this->controller()->preview(['_csrf'=>$this->csrf->token(),'arrival_date'=>'2026-08-01','departure_date'=>'2026-08-03','adults'=>'2','child_ages'=>'4, 9','exemption_keys'=>'configured_key'],'application/x-www-form-urlencoded',200);
        self::assertSame(200,$response->status);
        self::assertStringContainsString('60.00 HUF',$response->body);
        self::assertSame([4,9],PricingFakePreviewer::$input->childAges);
        self::assertSame('pricing.previewed',end($this->audit->events)->eventType);
    }

    private function controller(?array $admin=['id'=>7,'name'=>'Admin']): PricingAdminController
    {
        $auth=new PricingAuth($admin); $guard=new AdminActionGuard($auth,$this->csrf,new PricingLimiter());
        return new PricingAdminController($auth,new AdminView(dirname(__DIR__,3).'/templates'),$this->csrf,$guard,$this->repository,new PricingFakePreviewer(),$this->audit);
    }
    /** @param array<string,mixed> $replace @return array<string,mixed> */
    private function form(array $replace=[]):array { return array_replace(['name'=>'Nyári alapár','rule_type'=>'base','valid_from'=>'2026-06-01','valid_until'=>'2026-09-01','amount'=>'10000.00','adjustment_mode'=>'fixed','base_unit'=>'per_person_per_night','minimum_nights'=>'1','maximum_nights'=>'','applicable_weekdays'=>'','exemption_key'=>'','priority'=>'10','is_active'=>'1'],$replace); }
    /** @param array<string,mixed> $replace @return array<string,mixed> */
    private function rule(array $replace=[]):array { return array_replace($this->form(),['id'=>1,'is_active'=>1],$replace); }
}

final class PricingAuth implements AdminAuthWorkflow { public function __construct(private ?array $admin){} public function currentAdmin():?array{return $this->admin;} public function login(string $email,string $password,array $requestContext=[]):bool{return false;} public function verify(string $code,array $requestContext=[]):bool{return false;} public function resend(array $requestContext=[]):bool{return false;} public function logout(array $requestContext=[]):void{} }
final class PricingLimiter implements AdminActionRateLimiter { public function allow(int $adminId,string $action):bool{return true;} }
final class PricingSession implements SessionStorage { private array $data=[]; public function start():void{} public function get(string $key,mixed $default=null):mixed{return $this->data[$key]??$default;} public function set(string $key,mixed $value):void{$this->data[$key]=$value;} public function remove(string $key):void{unset($this->data[$key]);} public function destroy():void{$this->data=[];} }
final class PricingFakeAudit implements AuditLog { /** @var list<AuditEvent> */ public array $events=[]; public function append(AuditEvent $event):void{$this->events[]=$event;} }
final class PricingFakePreviewer implements PricingPreviewer { public static PricingInput $input; public function preview(PricingInput $input):PricingResult { self::$input=$input; return new PricingResult('60.00','50.00','10.00','HUF',[['description'=>'Alapár','quantity'=>2,'total'=>'50.00']],[1],[]); } }
final class PricingFakeRepository implements PricingRuleRepository {
    public array $rows=[]; public array $created=[]; public bool $conflict=false; public bool $active=true;
    public function create(array $values,int $adminId):int{$this->created=$values;return 9;} public function update(int $id,array $values,int $adminId):bool{return $this->find($id)!==null;}
    public function find(int $id):?array{foreach($this->rows as $row)if((int)$row['id']===$id)return $row;return null;} public function listAll(bool $includeInactive=true):array{return $this->rows;}
    public function setActive(int $id,bool $active,int $adminId):bool{if($this->find($id)===null)return false;$this->active=$active;return true;} public function findApplicable(string $type,string $arrivalDate,string $departureDate,int $nights):array{return [];}
    public function hasEqualPriorityConflict(string $type,string $validFrom,string $validUntil,int $priority,?int $exceptId=null):bool{return $this->conflict;}
}
