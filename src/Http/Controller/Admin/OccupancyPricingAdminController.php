<?php
declare(strict_types=1);
namespace App\Http\Controller\Admin;

use App\Application\Pricing\PricingVersionConflict;
use App\Domain\Pricing\OccupancyDateOverride;
use App\Domain\Pricing\OccupancyStayLengthBand;
use App\Infrastructure\Persistence\Pricing\PdoOccupancyPricingRepository;
use App\Security\Csrf\CsrfTokenManager;

final readonly class OccupancyPricingAdminController
{
    public function __construct(private AdminAuthWorkflow $auth, private AdminView $view, private CsrfTokenManager $csrf, private AdminActionGuard $guard, private PdoOccupancyPricingRepository $repository) {}
    public function index(?string $error=null, int $status=200): AdminResponse
    {
        if ($this->auth->currentAdmin()===null) return new RedirectResponse('/admin/login');
        $configuration=$this->repository->get();
        return new HtmlResponse($this->view->render('occupancy-pricing',['configuration'=>$configuration,'csrfToken'=>$this->csrf->token(),'error'=>$error]),$status);
    }
    public function save(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization=$this->guard->authorizeForm('occupancy_pricing.update',$form,$contentType,$contentLength);
        if(!$authorization->allowed()) return $authorization->rejection;
        $admin=(int)$authorization->admin['id'];
        try {
            $version=$this->integer($form['version']??null,1,PHP_INT_MAX); $action=(string)($form['action']??'');
            if($action==='surcharge') $this->repository->saveSurcharge($this->amount($form['amount']??null),$version,$admin);
            elseif($action==='band') $this->repository->saveBand(new OccupancyStayLengthBand((int)($form['band_id']??0),$this->integer($form['guest_count']??null,1,4),$this->integer($form['min_nights']??null,1,65535),($form['max_nights']??'')===''?null:$this->integer($form['max_nights'],1,65535),$this->amount($form['nightly_price']??null),($form['active']??'1')==='1'),$version,$admin);
            elseif($action==='band_toggle') $this->repository->setBandActive($this->integer($form['band_id']??null,1,PHP_INT_MAX),($form['active']??'')==='1',$version,$admin);
            elseif($action==='override') $this->repository->saveOverride(new OccupancyDateOverride((int)($form['override_id']??0),(string)($form['start_date']??''),(string)($form['end_date']??''),[1=>$this->amount($form['price_1']??null),2=>$this->amount($form['price_2']??null),3=>$this->amount($form['price_3']??null),4=>$this->amount($form['price_4']??null)],($form['active']??'1')==='1'),$version,$admin);
            elseif($action==='override_toggle') $this->repository->setOverrideActive($this->integer($form['override_id']??null,1,PHP_INT_MAX),($form['active']??'')==='1',$version,$admin);
            else throw new \InvalidArgumentException();
            return new RedirectResponse('/admin/pricing?saved=1');
        } catch(PricingVersionConflict) { return $this->index('Az árképzést időközben egy másik admin módosította. Frissítse az oldalt, majd próbálja újra.',409); }
          catch(\Throwable $e) { return $this->index($e->getMessage(),422); }
    }
    private function integer(mixed $v,int $min,int $max):int { if(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]*)$/D',$v)) throw new \InvalidArgumentException('Érvénytelen egész érték.'); $n=(int)$v; if($n<$min||$n>$max) throw new \InvalidArgumentException('Érvénytelen tartomány.'); return $n; }
    private function amount(mixed $v):string { if(!is_string($v)||!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.00)?$/D',$v)) throw new \InvalidArgumentException('Egész, nem negatív forintár szükséges.'); return str_contains($v,'.')?$v:$v.'.00'; }
}
