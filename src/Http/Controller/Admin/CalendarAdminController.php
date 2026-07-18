<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use App\Application\Calendar\CalendarExportTokenRepository;
use App\Application\Calendar\CalendarImportService;
use App\Application\Calendar\CalendarSourceRepository;
use App\Application\Calendar\CalendarSyncLogRepository;
use App\Security\Csrf\CsrfTokenManager;

final readonly class CalendarAdminController
{
    private const PROVIDERS = ['google_calendar', 'szallas_hu'];
    private const DIRECTIONS = ['import', 'export', 'bidirectional'];

    public function __construct(
        private AdminAuthWorkflow $auth,
        private AdminView $view,
        private CsrfTokenManager $csrf,
        private AdminActionGuard $guard,
        private CalendarSourceRepository $sources,
        private CalendarSyncLogRepository $logs,
        private CalendarExportTokenRepository $tokens,
        private CalendarImportService $importer,
        private AuditLog $audit,
    ) {}

    public function dashboard(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('calendar-dashboard', [
            'sources' => $this->maskedSources(), 'logs' => $this->logs->recent(null, 10),
            'tokenMetadata' => $this->tokens->metadata(), 'csrfToken' => $this->csrf->token(),
            'plainToken' => null,
        ]));
    }

    public function sources(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('calendar-sources', [
            'sources' => $this->maskedSources(), 'csrfToken' => $this->csrf->token(),
        ]));
    }

    public function createForm(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('calendar-source-form', [
            'source' => ['name'=>'','provider'=>'google_calendar','url'=>'','direction'=>'import','enabled'=>1],
            'editing' => false, 'csrfToken' => $this->csrf->token(),
        ]));
    }

    public function editForm(string $id): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        $source = $this->lookup($id);
        if ($source === null) return $this->error(404, 'A naptárforrás nem található.');
        return new HtmlResponse($this->view->render('calendar-source-form', [
            'source'=>$source, 'editing'=>true, 'csrfToken'=>$this->csrf->token(),
        ]));
    }

    public function log(): AdminResponse
    {
        if ($this->auth->currentAdmin() === null) return new RedirectResponse('/admin/login');
        return new HtmlResponse($this->view->render('calendar-log', ['logs'=>$this->logs->recent(), 'sources'=>$this->sourceNames()]));
    }

    /** @param array<string,mixed> $form */
    public function create(array $form, ?string $contentType, ?int $contentLength): AdminResponse
    {
        $authorization=$this->guard->authorizeForm('calendar_source.create',$form,$contentType,$contentLength);
        if(!$authorization->allowed()) return $authorization->rejection;
        try {
            $v=$this->validated($form); $id=$this->sources->create($v['name'],$v['provider'],$v['url'],$v['direction'],$v['enabled'],$v['sync_token']);
            $this->audit('calendar_source.created',$authorization->admin['id'],$id);
            return new RedirectResponse('/admin/calendar/sources?created=1');
        } catch (\InvalidArgumentException) { return $this->error(422,'A naptárforrás adatai érvénytelenek.'); }
    }

    /** @param array<string,mixed> $form */
    public function update(string $id,array $form,?string $contentType,?int $contentLength):AdminResponse
    {
        $authorization=$this->guard->authorizeForm('calendar_source.update',$form,$contentType,$contentLength);
        if(!$authorization->allowed()) return $authorization->rejection;
        $source=$this->lookup($id); if($source===null)return $this->error(404,'A naptárforrás nem található.');
        try { $v=$this->validated($form); $this->sources->update((int)$id,$v['name'],$v['provider'],$v['url'],$v['direction'],$v['enabled'],$v['sync_token']);
            $this->audit('calendar_source.updated',$authorization->admin['id'],(int)$id); return new RedirectResponse('/admin/calendar/sources?updated=1');
        } catch (\InvalidArgumentException) { return $this->error(422,'A naptárforrás adatai érvénytelenek.'); }
    }

    /** @param array<string,mixed> $form */
    public function setEnabled(string $id,bool $enabled,array $form,?string $contentType,?int $contentLength):AdminResponse
    {
        $authorization=$this->guard->authorizeForm('calendar_source.'.($enabled?'enabled':'disabled'),$form,$contentType,$contentLength);
        if(!$authorization->allowed())return $authorization->rejection;
        $s=$this->lookup($id); if($s===null)return $this->error(404,'A naptárforrás nem található.');
        $this->sources->update((int)$id,(string)$s['name'],(string)$s['provider'],(string)$s['url'],(string)$s['direction'],$enabled);
        $this->audit('calendar_source.'.($enabled?'enabled':'disabled'),$authorization->admin['id'],(int)$id);
        return new RedirectResponse('/admin/calendar/sources?'.($enabled?'enabled':'disabled').'=1');
    }

    /** @param array<string,mixed> $form */
    public function delete(string $id,array $form,?string $contentType,?int $contentLength):AdminResponse
    {
        $authorization=$this->guard->authorizeForm('calendar_source.delete',$form,$contentType,$contentLength);
        if(!$authorization->allowed())return $authorization->rejection;
        if($this->lookup($id)===null)return $this->error(404,'A naptárforrás nem található.');
        $this->sources->delete((int)$id); $this->audit('calendar_source.deleted',$authorization->admin['id'],(int)$id);
        return new RedirectResponse('/admin/calendar/sources?deleted=1');
    }

    /** @param array<string,mixed> $form */
    public function synchronize(string $id,array $form,?string $contentType,?int $contentLength):AdminResponse
    {
        $authorization=$this->guard->authorizeForm('calendar_source.synchronize',$form,$contentType,$contentLength);
        if(!$authorization->allowed())return $authorization->rejection;
        if($this->lookup($id)===null)return $this->error(404,'A naptárforrás nem található.');
        try { $result=$this->importer->import((int)$id); $this->audit('calendar_source.synchronized',$authorization->admin['id'],(int)$id);
            return new RedirectResponse('/admin/calendar?sync='.rawurlencode($result->status));
        } catch (\InvalidArgumentException|\LogicException) { return $this->error(422,'Ez a naptárforrás nem szinkronizálható.'); }
    }

    /** @param array<string,mixed> $form */
    public function rotateToken(array $form,?string $contentType,?int $contentLength):AdminResponse
    {
        $authorization=$this->guard->authorizeForm('calendar_export_token.rotate',$form,$contentType,$contentLength);
        if(!$authorization->allowed())return $authorization->rejection;
        $plain=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $this->tokens->rotate($plain,new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')));
        $this->audit('calendar_export_token.rotated',$authorization->admin['id']);
        return new HtmlResponse($this->view->render('calendar-dashboard',[
            'sources'=>$this->maskedSources(),'logs'=>$this->logs->recent(null,10),'tokenMetadata'=>$this->tokens->metadata(),
            'csrfToken'=>$this->csrf->token(),'plainToken'=>$plain,
        ]));
    }

    /** @param array<string,mixed> $form @return array{name:string,provider:string,url:string,direction:string,enabled:bool,sync_token:?string} */
    private function validated(array $form):array
    {
        foreach(['name','provider','url','direction'] as $key)if(!is_string($form[$key]??null))throw new \InvalidArgumentException();
        if (isset($form['sync_token']) && !is_string($form['sync_token'])) throw new \InvalidArgumentException();
        $name=trim($form['name']);$url=trim($form['url']);$syncToken=trim((string)($form['sync_token']??''));
        $parts=parse_url($url);
        if($name===''||mb_strlen($name)>150||mb_strlen($syncToken)>255||!in_array($form['provider'],self::PROVIDERS,true)||!in_array($form['direction'],self::DIRECTIONS,true)
            ||filter_var($url,FILTER_VALIDATE_URL)===false||!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'
            ||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new \InvalidArgumentException();
        return ['name'=>$name,'provider'=>$form['provider'],'url'=>$url,'direction'=>$form['direction'],'enabled'=>isset($form['enabled']),'sync_token'=>$syncToken===''?null:$syncToken];
    }
    /** @return array<string,mixed>|null */ private function lookup(string $id):?array{return ctype_digit($id)&&(int)$id>0?$this->sources->find((int)$id):null;}
    /** @return list<array<string,mixed>> */ private function maskedSources():array{$items=$this->sources->all();foreach($items as &$s)$s['masked_url']=$this->maskUrl((string)$s['url']);return $items;}
    /** @return array<int,string> */ private function sourceNames():array{$out=[];foreach($this->sources->all() as $s)$out[(int)$s['id']]=(string)$s['name'];return $out;}
    private function maskUrl(string $url):string{$p=parse_url($url);return isset($p['host'])?(($p['scheme']??'https').'://'.$p['host'].'/…'):'••••••';}
    private function audit(string $type,int $adminId,?int $id=null):void{$m=['target_type'=>'calendar_source'];if($id!==null)$m['target_id']=(string)$id;$this->audit->append(new AuditEvent($type,'success',new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')),new AuditMetadata($m),$adminId));}
    private function error(int $status,string $message):HtmlResponse{return new HtmlResponse($this->view->render('error',['message'=>$message]),$status);}
}
