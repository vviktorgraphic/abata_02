<?php

declare(strict_types=1);

namespace Tests\Integration\Booking;

use App\Application\Mail\BookingModificationMailRenderer;
use App\Application\Mail\BookingModificationNotificationDispatcher;
use App\Application\Mail\BookingStatusMailRenderer;
use App\Application\Mail\BookingStatusNotificationDispatcher;
use App\Application\Mail\InMemoryMailer;
use App\Application\Booking\BookingModificationPreviewSigner;
use App\Application\Booking\ConfirmedBookingModification;
use App\Application\Booking\ConfirmedBookingModificationValidator;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\AdminActionRateLimiter;
use App\Http\Controller\Admin\AdminAuthWorkflow;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\BookingManagementController;
use App\Http\Controller\Admin\AdminBookingFlashStore;
use App\Http\Controller\Admin\HtmlResponse;
use App\Http\Controller\Admin\RedirectResponse;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\PdoBookingModificationOutbox;
use App\Infrastructure\Persistence\Booking\PdoBookingStatusNotificationOutbox;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Booking\TransactionalConfirmedBookingModificationService;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\Session\SessionStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class BookingModificationNotificationControllerTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    private int $bookingId;
    private string $reference;
    /** @var list<int> */
    private array $modificationIds=[];

    protected function setUp():void
    {
        if(getenv('DB_HOST')===false)self::markTestSkipped('Database environment is not configured.');
        $this->pdo=ConnectionFactory::create(require dirname(__DIR__,3).'/config/database.php');
        $this->pdo->prepare("INSERT INTO admins(email,password_hash,name) VALUES(:email,'hash','Controller Admin')")
            ->execute(['email'=>'notification-controller-'.bin2hex(random_bytes(5)).'@example.invalid']);
        $this->adminId=(int)$this->pdo->lastInsertId();
        $this->reference='NOTIFY-'.strtoupper(bin2hex(random_bytes(5)));
        $this->pdo->prepare("INSERT INTO bookings(reference,status,arrival_date,departure_date,guest_name,guest_email,adults,children,total_amount,currency,modification_version)
            VALUES(:reference,'confirmed','2045-01-10','2045-01-14','Controller Guest','controller-guest@example.invalid',2,0,60000.00,'HUF',2)")
            ->execute(['reference'=>$this->reference]);
        $this->bookingId=(int)$this->pdo->lastInsertId();
        $pricingSnapshot=json_encode(['version'=>4,'arrival_date'=>'2045-01-10','departure_date'=>'2045-01-14','accommodation_fee'=>'60000.00','taxes'=>'0.00','total'=>'60000.00','currency'=>'HUF','line_items'=>[]],JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO booking_pricing_snapshots(booking_id,snapshot) VALUES(:booking_id,:snapshot)')
            ->execute(['booking_id'=>$this->bookingId,'snapshot'=>$pricingSnapshot]);
        foreach([1=>['2045-01-10','2045-01-14'],2=>['2045-01-11','2045-01-15']] as $version=>$dates){
            $snapshot=json_encode(['arrival_date'=>$dates[0],'departure_date'=>$dates[1],'nights'=>4,'adults'=>2,'children'=>0,'child_ages'=>[],'accommodation_fee'=>'60000.00','tourism_tax'=>'0.00','total_amount'=>'60000.00','currency'=>'HUF'],JSON_THROW_ON_ERROR);
            $this->pdo->prepare('INSERT INTO booking_modifications
                (booking_id,version,changed_by_admin_id,idempotency_key_hash,request_hash,before_snapshot,after_snapshot,currency)
                VALUES(:booking_id,:version,:admin_id,UNHEX(:key_hash),UNHEX(:request_hash),:before_snapshot,:after_snapshot,\'HUF\')')
                ->execute(['booking_id'=>$this->bookingId,'version'=>$version,'admin_id'=>$this->adminId,
                    'key_hash'=>hash('sha256','key-'.$version),'request_hash'=>hash('sha256','request-'.$version),
                    'before_snapshot'=>$snapshot,'after_snapshot'=>$snapshot]);
            $this->modificationIds[]=(int)$this->pdo->lastInsertId();
        }
    }

    protected function tearDown():void
    {
        if(isset($this->bookingId))$this->pdo->prepare('DELETE FROM bookings WHERE id=:id')->execute(['id'=>$this->bookingId]);
        if(isset($this->adminId))$this->pdo->prepare('DELETE FROM admins WHERE id=:id')->execute(['id'=>$this->adminId]);
    }

    public function testActionRequiresAuthenticationAndCsrf():void
    {
        [$anonymous,$anonymousCsrf]=$this->controller(null);
        $redirect=$anonymous->sendModificationNotification($this->reference,(string)$this->modificationIds[1],['_csrf'=>$anonymousCsrf->token()],'application/x-www-form-urlencoded',100);
        self::assertInstanceOf(RedirectResponse::class,$redirect);
        self::assertSame('/admin/login',$redirect->location);

        [$authenticated]=$this->controller(['id'=>$this->adminId,'name'=>'Admin']);
        $forbidden=$authenticated->sendModificationNotification($this->reference,(string)$this->modificationIds[1],['_csrf'=>'invalid'],'application/x-www-form-urlencoded',100);
        self::assertInstanceOf(HtmlResponse::class,$forbidden);
        self::assertSame(403,$forbidden->status);
    }

    public function testOlderModificationIsConflictAndLatestIsSentOnce():void
    {
        [$controller,$csrf,$mailer]=$this->controller(['id'=>$this->adminId,'name'=>'Admin']);
        $form=['_csrf'=>$csrf->token()];
        $conflict=$controller->sendModificationNotification($this->reference,(string)$this->modificationIds[0],$form,'application/x-www-form-urlencoded',100);
        self::assertInstanceOf(HtmlResponse::class,$conflict);
        self::assertSame(409,$conflict->status);
        self::assertStringContainsString('Csak a legutóbbi módosításról küldhető értesítés.',$conflict->body);
        self::assertCount(0,$mailer->messages());

        $sent=$controller->sendModificationNotification($this->reference,(string)$this->modificationIds[1],$form,'application/x-www-form-urlencoded',100);
        $replay=$controller->sendModificationNotification($this->reference,(string)$this->modificationIds[1],$form,'application/x-www-form-urlencoded',100);
        self::assertInstanceOf(RedirectResponse::class,$sent);
        self::assertInstanceOf(RedirectResponse::class,$replay);
        self::assertCount(1,$mailer->messages());
        $count=$this->pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:booking_id AND message_type='booking_modified'");
        $count->execute(['booking_id'=>$this->bookingId]);
        self::assertSame(1,(int)$count->fetchColumn());
    }

    public function testSavingModificationNeverCallsMailerOrCreatesOutboxAndShowsManualNotice():void
    {
        [$controller,$csrf,$mailer,$service,$signer,$flash]=$this->controller(['id'=>$this->adminId,'name'=>'Admin']);
        $request=new ConfirmedBookingModification('2045-01-12','2045-01-16',2,[]);
        $preview=$service->preview($this->reference,$request);
        $form=[
            '_csrf'=>$csrf->token(),'arrival_date'=>$request->arrivalDate,'departure_date'=>$request->departureDate,
            'adults'=>'2','children'=>'0','child_ages'=>[],'expected_version'=>'2',
            'expected_pricing_hash'=>$preview->pricingHash,
            'preview_signature'=>$signer->sign($this->reference,2,$request,$preview->pricingHash),
            'idempotency_key'=>'controller-save-key-0001',
        ];

        $response=$controller->saveModification($this->reference,$form,'application/x-www-form-urlencoded',600);

        self::assertInstanceOf(RedirectResponse::class,$response,$response instanceof HtmlResponse ? $response->status.' '.$response->body : $response::class);
        self::assertSame('/admin/bookings/'.rawurlencode($this->reference),$response->location);
        self::assertCount(0,$mailer->messages());
        $count=$this->pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE booking_id=:booking_id AND message_type='booking_modified'");
        $count->execute(['booking_id'=>$this->bookingId]);
        self::assertSame(0,(int)$count->fetchColumn());
        self::assertSame('A módosítás elmentve. A vendég még nem kapott értesítést.',$flash->consume($this->reference));
        $version=$this->pdo->prepare('SELECT modification_version FROM bookings WHERE id=:id');
        $version->execute(['id'=>$this->bookingId]);
        self::assertSame(3,(int)$version->fetchColumn());
    }

    /** @param array{id:int,name:string}|null $admin @return array{BookingManagementController,CsrfTokenManager,InMemoryMailer,TransactionalConfirmedBookingModificationService,BookingModificationPreviewSigner,AdminBookingFlashStore} */
    private function controller(?array $admin):array
    {
        $auth=new NotificationControllerAuth($admin);
        $session=new NotificationControllerSession();
        $csrf=new CsrfTokenManager($session);
        $flash=new AdminBookingFlashStore($session);
        $limiter=new NotificationControllerLimiter();
        $mailer=new InMemoryMailer();
        $templates=dirname(__DIR__,3).'/templates';
        $statusNotifications=new BookingStatusNotificationDispatcher(
            new PdoBookingStatusNotificationOutbox($this->pdo),
            new BookingStatusMailRenderer($templates.'/email','noreply@example.test','A Bata','info@example.test','A Bata'),
            $mailer,
        );
        $modificationNotifications=new BookingModificationNotificationDispatcher(
            new PdoBookingModificationOutbox($this->pdo),
            new BookingModificationMailRenderer($templates.'/email','noreply@example.test','A Bata','info@example.test','A Bata'),
            $mailer,
        );
        $pricing=new PdoPricingEngineAdapter($this->pdo);
        $modificationService=new TransactionalConfirmedBookingModificationService($this->pdo,$pricing);
        $signer=new BookingModificationPreviewSigner('controller-preview-secret');
        return [new BookingManagementController(
            auth:$auth,
            view:new AdminView($templates,$csrf,static fn():bool=>$auth->currentAdmin()!==null),
            csrf:$csrf,
            guard:new AdminActionGuard($auth,$csrf,$limiter),
            queries:new PdoAdminBookingQueryRepository($this->pdo),
            transitions:new TransactionalBookingRepository($this->pdo),
            notifications:$statusNotifications,
            modifications:$modificationService,
            modificationValidator:new ConfirmedBookingModificationValidator(new \DateTimeImmutable('2045-01-01',new \DateTimeZone('Europe/Budapest'))),
            modificationSigner:$signer,
            modificationNotifications:$modificationNotifications,
            flash:$flash,
        ),$csrf,$mailer,$modificationService,$signer,$flash];
    }
}

final readonly class NotificationControllerAuth implements AdminAuthWorkflow
{
    /** @param array{id:int,name:string}|null $admin */
    public function __construct(private ?array $admin){}
    public function login(string $email,string $password,array $requestContext=[]):bool{return false;}
    public function verify(string $code,array $requestContext=[]):bool{return false;}
    public function resend(array $requestContext=[]):bool{return false;}
    public function logout(array $requestContext=[]):void{}
    public function currentAdmin():?array{return $this->admin;}
}

final class NotificationControllerSession implements SessionStorage
{
    /** @var array<string,mixed> */ private array $data=[];
    public function start():void{}
    public function get(string $key,mixed $default=null):mixed{return $this->data[$key]??$default;}
    public function set(string $key,mixed $value):void{$this->data[$key]=$value;}
    public function remove(string $key):void{unset($this->data[$key]);}
    public function destroy():void{$this->data=[];}
}

final class NotificationControllerLimiter implements AdminActionRateLimiter
{
    public function allow(int $adminId,string $action):bool{return in_array($action,['email.booking_modified_send','booking.modify'],true);}
}
