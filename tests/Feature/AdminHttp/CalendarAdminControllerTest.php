<?php

declare(strict_types=1);

namespace Tests\Feature\AdminHttp;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Calendar\CalendarExportTokenRepository;
use App\Application\Calendar\CalendarFeedHttpClient;
use App\Application\Calendar\CalendarFeedResponse;
use App\Application\Calendar\CalendarHostResolver;
use App\Application\Calendar\CalendarImportService;
use App\Application\Calendar\CalendarSourceRepository;
use App\Application\Calendar\CalendarSyncClock;
use App\Application\Calendar\CalendarSyncLogRepository;
use App\Application\Calendar\ExternalCalendarEventRepository;
use App\Application\Calendar\IcsParser;
use App\Application\Calendar\ImportedEventPersistenceResult;
use App\Application\Calendar\SecureCalendarFeedFetcher;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\CalendarAdminController;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\RedirectResponse;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PHPUnit\Framework\TestCase;

final class CalendarAdminControllerTest extends TestCase
{
    private CalendarAdminSources $sources;
    private CalendarAdminTokens $tokens;
    private CalendarAdminAudit $audit;
    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $this->sources = new CalendarAdminSources();
        $this->tokens = new CalendarAdminTokens();
        $this->audit = new CalendarAdminAudit();
        $this->csrf = new CsrfTokenManager(new CalendarAdminSession());
    }

    public function test_pages_require_authentication(): void
    {
        $controller = $this->controller(null);
        foreach ([$controller->dashboard(), $controller->sources(), $controller->createForm(), $controller->editForm('1'), $controller->log()] as $response) {
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('/admin/login', $response->location);
        }
    }

    public function test_create_and_edit_accept_write_only_sync_token_and_enforce_database_name_limit(): void
    {
        $created = $this->controller()->create($this->form(['sync_token'=>'  source-secret  '])+['_csrf'=>$this->csrf->token()], 'application/x-www-form-urlencoded', 400);
        self::assertSame('/admin/calendar/sources?created=1', $created->location);
        self::assertSame('source-secret', $this->sources->lastToken);

        $edit = $this->controller()->editForm('1');
        self::assertStringContainsString('type="password" name="sync_token"', $edit->body);
        self::assertStringNotContainsString('source-secret', $edit->body);
        self::assertStringContainsString('nem olvasható vissza', $edit->body);

        $updated = $this->controller()->update('1', $this->form(['name'=>'Módosított','sync_token'=>''])+['_csrf'=>$this->csrf->token()], 'application/x-www-form-urlencoded', 400);
        self::assertSame('/admin/calendar/sources?updated=1', $updated->location);
        self::assertNull($this->sources->lastToken);
        self::assertSame(422, $this->controller()->update('1', $this->form(['name'=>str_repeat('x',151)])+['_csrf'=>$this->csrf->token()], 'application/x-www-form-urlencoded', 400)->status);
    }

    public function test_enable_disable_and_delete_use_prg_and_audit(): void
    {
        $controller=$this->controller(); $csrf=['_csrf'=>$this->csrf->token()];
        self::assertSame('/admin/calendar/sources?disabled=1',$controller->setEnabled('1',false,$csrf,'application/x-www-form-urlencoded',50)->location);
        self::assertFalse($this->sources->rows[1]['enabled']);
        self::assertSame('/admin/calendar/sources?enabled=1',$controller->setEnabled('1',true,$csrf,'application/x-www-form-urlencoded',50)->location);
        self::assertTrue($this->sources->rows[1]['enabled']);
        self::assertSame('/admin/calendar/sources?deleted=1',$controller->delete('1',$csrf,'application/x-www-form-urlencoded',50)->location);
        self::assertArrayNotHasKey(1,$this->sources->rows);
        self::assertSame(['calendar_source.disabled','calendar_source.enabled','calendar_source.deleted'],array_column($this->audit->events,'eventType'));
    }

    public function test_all_mutations_reject_missing_csrf(): void
    {
        $c=$this->controller();
        $responses=[
            $c->create($this->form(),'application/x-www-form-urlencoded',200),
            $c->update('1',$this->form(),'application/x-www-form-urlencoded',200),
            $c->setEnabled('1',false,[],'application/x-www-form-urlencoded',20),
            $c->delete('1',[],'application/x-www-form-urlencoded',20),
            $c->synchronize('1',[],'application/x-www-form-urlencoded',20),
            $c->rotateToken([],'application/x-www-form-urlencoded',20),
        ];
        foreach($responses as $response) self::assertSame(403,$response->status);
        self::assertSame([], $this->audit->events);
    }

    public function test_manual_sync_runs_import_and_records_audit(): void
    {
        $response=$this->controller()->synchronize('1',['_csrf'=>$this->csrf->token()],'application/x-www-form-urlencoded',50);
        self::assertSame('/admin/calendar?sync=success',$response->location);
        self::assertSame('calendar_source.synchronized',$this->audit->events[0]->eventType);
    }

    public function test_rotated_export_token_is_long_and_shown_exactly_once(): void
    {
        $response=$this->controller()->rotateToken(['_csrf'=>$this->csrf->token()],'application/x-www-form-urlencoded',50);
        self::assertInstanceOf(HtmlResponse::class,$response);
        self::assertGreaterThanOrEqual(43,strlen($this->tokens->plain));
        self::assertStringContainsString($this->tokens->plain,$response->body);
        self::assertStringNotContainsString($this->tokens->plain,$this->controller()->dashboard()->body);
        self::assertSame('calendar_export_token.rotated',$this->audit->events[0]->eventType);
    }

    public function test_source_lists_mask_urls_and_audit_never_contains_url_or_tokens(): void
    {
        $body=$this->controller()->sources()->body;
        self::assertStringContainsString('https://calendar.example/…',$body);
        self::assertStringNotContainsString('private/feed.ics?token=url-secret',$body);
        $this->controller()->create($this->form(['sync_token'=>'form-secret'])+['_csrf'=>$this->csrf->token()],'application/x-www-form-urlencoded',300);
        $serialized=serialize($this->audit->events);
        self::assertStringNotContainsString('form-secret',$serialized);
        self::assertStringNotContainsString('url-secret',$serialized);
    }

    private function controller(?array $admin=['id'=>7,'name'=>'Admin']): CalendarAdminController
    {
        $auth=new CalendarAdminAuth($admin); $logs=new CalendarAdminLogs();
        $fetcher=new SecureCalendarFeedFetcher(new CalendarAdminClient(),new CalendarAdminResolver());
        $importer=new CalendarImportService($this->sources,$logs,new CalendarAdminEvents(),$fetcher,new IcsParser(),new CalendarAdminClock());
        return new CalendarAdminController($auth,new AdminView(dirname(__DIR__,3).'/templates'),$this->csrf,new AdminActionGuard($auth,$this->csrf,new CalendarAdminLimiter()),$this->sources,$logs,$this->tokens,$importer,$this->audit);
    }
    private function form(array $replace=[]):array{return array_replace(['name'=>'Google','provider'=>'google_calendar','url'=>'https://calendar.example/private/feed.ics?token=url-secret','direction'=>'import','enabled'=>'1'],$replace);}
}

final class CalendarAdminAuth implements AdminAuthWorkflow {public function __construct(private ?array $admin){} public function currentAdmin():?array{return $this->admin;} public function login(string $email,string $password,array $requestContext=[]):bool{return false;} public function verify(string $code,array $requestContext=[]):bool{return false;} public function resend(array $requestContext=[]):bool{return false;} public function logout(array $requestContext=[]):void{}}
final class CalendarAdminLimiter implements AdminActionRateLimiter {public function allow(int $adminId,string $action):bool{return true;}}
final class CalendarAdminSession implements SessionStorage {private array $data=[];public function start():void{} public function get(string $key,mixed $default=null):mixed{return $this->data[$key]??$default;} public function set(string $key,mixed $value):void{$this->data[$key]=$value;} public function remove(string $key):void{unset($this->data[$key]);} public function destroy():void{$this->data=[];}}
final class CalendarAdminAudit implements AuditLog {/** @var list<AuditEvent> */public array $events=[];public function append(AuditEvent $event):void{$this->events[]=$event;}}
final class CalendarAdminTokens implements CalendarExportTokenRepository {public string $plain='';private ?array $meta=null;public function rotate(string $plainToken,\DateTimeImmutable $at):void{$this->plain=$plainToken;$this->meta=['created_at'=>$at->format('c'),'rotated_at'=>null];}public function verify(string $plainToken):bool{return hash_equals($this->plain,$plainToken);}public function metadata():?array{return $this->meta;}}
final class CalendarAdminSources implements CalendarSourceRepository {
    public array $rows=[1=>['id'=>1,'name'=>'Google','provider'=>'google_calendar','url'=>'https://calendar.example/private/feed.ics?token=url-secret','direction'=>'import','enabled'=>true]];public ?string $lastToken=null;
    public function all():array{return array_values($this->rows);} public function find(int $id):?array{return $this->rows[$id]??null;}
    public function create(string $name,string $provider,string $url,string $direction,bool $enabled,?string $syncToken=null):int{$this->lastToken=$syncToken;$this->rows[2]=compact('name','provider','url','direction','enabled')+['id'=>2];return 2;}
    public function update(int $id,string $name,string $provider,string $url,string $direction,bool $enabled,?string $syncToken=null):void{$this->lastToken=$syncToken;$this->rows[$id]=compact('name','provider','url','direction','enabled')+['id'=>$id];}
    public function delete(int $id):void{unset($this->rows[$id]);} public function markSuccess(int $id,\DateTimeImmutable $at):void{} public function markError(int $id,\DateTimeImmutable $at):void{}
}
final class CalendarAdminLogs implements CalendarSyncLogRepository {public function start(int $sourceId,\DateTimeImmutable $startedAt):int{return 1;}public function finish(int $id,string $status,\DateTimeImmutable $finishedAt,int $imported,int $exported,array $warnings,array $errors):void{}public function recent(?int $sourceId=null,int $limit=100):array{return [];}}
final class CalendarAdminClock implements CalendarSyncClock {public function now():\DateTimeImmutable{return new \DateTimeImmutable('2026-07-16 12:00:00',new \DateTimeZone('Europe/Budapest'));}}
final class CalendarAdminResolver implements CalendarHostResolver {public function resolve(string $host):array{return ['93.184.216.34'];}}
final class CalendarAdminClient implements CalendarFeedHttpClient {public function get(string $url,string $resolvedIp,int $timeoutSeconds,int $maxBytes):CalendarFeedResponse{return new CalendarFeedResponse(200,"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");}}
final class CalendarAdminEvents implements ExternalCalendarEventRepository {public function findBySourceAndUid(int $sourceId,string $externalUid):?array{return null;}public function upsert(int $sourceId,string $externalUid,?string $summary,?string $description,\DateTimeImmutable $startDate,\DateTimeImmutable $endDate,string $payloadHash,string $status,\DateTimeImmutable $seenAt,?int $blockedPeriodId=null):int{return 1;}public function linkBlockedPeriod(int $eventId,int $blockedPeriodId):void{}public function importEvent(int $sourceId,string $externalUid,?string $summary,?string $description,\DateTimeImmutable $startDate,\DateTimeImmutable $endDate,string $payloadHash,\DateTimeImmutable $seenAt,bool $cancelled=false):ImportedEventPersistenceResult{return new ImportedEventPersistenceResult(ImportedEventPersistenceResult::BLOCKED,1,1);}}
