<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Pricing;

use App\Application\Audit\AuditEvent;
use App\Application\Audit\AuditLog;
use App\Application\Audit\AuditMetadata;
use App\Application\Pricing\PricingVersionConflict;
use App\Domain\Pricing\OccupancyDateOverride;
use App\Domain\Pricing\OccupancyPricingConfiguration;
use App\Domain\Pricing\OccupancyStayLengthBand;
use PDO;

/** Persistence boundary for the additive occupancy pricing model. */
final readonly class PdoOccupancyPricingRepository
{
    public function __construct(private PDO $pdo, private ?AuditLog $audit = null) {}

    public function get(): OccupancyPricingConfiguration
    {
        $row = $this->pdo->query('SELECT version, one_night_surcharge FROM occupancy_pricing_configuration WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException('Az occupancy árképzés konfigurációja hiányzik.');
        $bands = [];
        foreach ($this->pdo->query('SELECT id,guest_count,min_nights,max_nights,nightly_price,is_active,sort_order FROM occupancy_stay_length_bands ORDER BY guest_count,sort_order,id')->fetchAll(PDO::FETCH_ASSOC) as $b) $bands[] = new OccupancyStayLengthBand((int)$b['id'],(int)$b['guest_count'],(int)$b['min_nights'],$b['max_nights']===null?null:(int)$b['max_nights'],(string)$b['nightly_price'],(bool)$b['is_active'],(int)$b['sort_order']);
        $overrides = [];
        foreach ($this->pdo->query('SELECT id,start_date,end_date,price_1_guest,price_2_guests,price_3_guests,price_4_guests,is_active FROM occupancy_date_overrides ORDER BY start_date,id')->fetchAll(PDO::FETCH_ASSOC) as $o) $overrides[] = new OccupancyDateOverride((int)$o['id'],(string)$o['start_date'],(string)$o['end_date'],[1=>(string)$o['price_1_guest'],2=>(string)$o['price_2_guests'],3=>(string)$o['price_3_guests'],4=>(string)$o['price_4_guests']],(bool)$o['is_active']);
        return new OccupancyPricingConfiguration((int)$row['version'],(string)$row['one_night_surcharge'],$bands,$overrides);
    }

    public function saveSurcharge(string $amount, int $expectedVersion, int $adminId): void
    {
        $this->transaction(function () use ($amount,$expectedVersion,$adminId): void {
            $this->assertVersion($expectedVersion);
            $s=$this->pdo->prepare('UPDATE occupancy_pricing_configuration SET one_night_surcharge=:amount,version=version+1,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=1'); $s->execute(['amount'=>$amount,'admin'=>$adminId]);
            $this->event('occupancy_pricing.surcharge_updated',$adminId,'configuration','1');
        });
    }

    public function saveBand(OccupancyStayLengthBand $band, int $expectedVersion, int $adminId): int
    {
        return $this->mutate($expectedVersion,$adminId,function () use ($band,$adminId): int {
            if ($band->id > 0) { $s=$this->pdo->prepare('UPDATE occupancy_stay_length_bands SET guest_count=:guest,min_nights=:min,max_nights=:max,nightly_price=:price,is_active=:active,sort_order=:sort,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id'); $s->execute(['guest'=>$band->guestCount,'min'=>$band->minNights,'max'=>$band->maxNights,'price'=>$band->nightlyPrice,'active'=>$band->active?1:0,'sort'=>$band->sortOrder,'admin'=>$adminId,'id'=>$band->id]); $id=$band->id; } else { $s=$this->pdo->prepare('INSERT INTO occupancy_stay_length_bands (guest_count,min_nights,max_nights,nightly_price,is_active,sort_order,created_by_admin_id,updated_by_admin_id) VALUES (:guest,:min,:max,:price,:active,:sort,:admin,:admin)'); $s->execute(['guest'=>$band->guestCount,'min'=>$band->minNights,'max'=>$band->maxNights,'price'=>$band->nightlyPrice,'active'=>$band->active?1:0,'sort'=>$band->sortOrder,'admin'=>$adminId]); $id=(int)$this->pdo->lastInsertId(); }
            $this->event($band->id>0?'occupancy_band.updated':'occupancy_band.created',$adminId,'occupancy_stay_length_band',(string)$id); return $id;
        });
    }

    public function setBandActive(int $id, bool $active, int $expectedVersion, int $adminId): void { $this->mutate($expectedVersion,$adminId,function()use($id,$active,$adminId):int{$s=$this->pdo->prepare('UPDATE occupancy_stay_length_bands SET is_active=:active,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['active'=>$active?1:0,'admin'=>$adminId,'id'=>$id]);$this->event($active?'occupancy_band.activated':'occupancy_band.deactivated',$adminId,'occupancy_stay_length_band',(string)$id);return $id;}); }
    public function saveOverride(OccupancyDateOverride $override, int $expectedVersion, int $adminId): int { return $this->mutate($expectedVersion,$adminId,function()use($override,$adminId):int{$p=[1=>$override->priceFor(1),2=>$override->priceFor(2),3=>$override->priceFor(3),4=>$override->priceFor(4)];if($override->id>0){$s=$this->pdo->prepare('UPDATE occupancy_date_overrides SET start_date=:start,end_date=:end,price_1_guest=:p1,price_2_guests=:p2,price_3_guests=:p3,price_4_guests=:p4,is_active=:active,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['start'=>$override->startDate,'end'=>$override->endDate,'p1'=>$p[1],'p2'=>$p[2],'p3'=>$p[3],'p4'=>$p[4],'active'=>$override->active?1:0,'admin'=>$adminId,'id'=>$override->id]);$id=$override->id;}else{$s=$this->pdo->prepare('INSERT INTO occupancy_date_overrides(start_date,end_date,price_1_guest,price_2_guests,price_3_guests,price_4_guests,is_active,created_by_admin_id,updated_by_admin_id) VALUES(:start,:end,:p1,:p2,:p3,:p4,:active,:admin,:admin)');$s->execute(['start'=>$override->startDate,'end'=>$override->endDate,'p1'=>$p[1],'p2'=>$p[2],'p3'=>$p[3],'p4'=>$p[4],'active'=>$override->active?1:0,'admin'=>$adminId]);$id=(int)$this->pdo->lastInsertId();}$this->event($override->id>0?'occupancy_override.updated':'occupancy_override.created',$adminId,'occupancy_date_override',(string)$id);return$id;}); }
    public function setOverrideActive(int $id,bool $active,int $expectedVersion,int $adminId):void{$this->mutate($expectedVersion,$adminId,function()use($id,$active,$adminId):int{$s=$this->pdo->prepare('UPDATE occupancy_date_overrides SET is_active=:active,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['active'=>$active?1:0,'admin'=>$adminId,'id'=>$id]);$this->event($active?'occupancy_override.activated':'occupancy_override.deactivated',$adminId,'occupancy_date_override',(string)$id);return$id;});}

    private function mutate(int $expected,int $admin,callable $callback):int{$result=null;$this->transaction(function()use($expected,$admin,$callback,&$result):void{$this->assertVersion($expected);$result=$callback();$this->pdo->exec('UPDATE occupancy_pricing_configuration SET version=version+1,updated_by_admin_id='.(int)$admin.',updated_at=CURRENT_TIMESTAMP WHERE id=1');});return(int)$result;}
    private function assertVersion(int $expected):void{$actual=(int)$this->pdo->query('SELECT version FROM occupancy_pricing_configuration WHERE id=1 FOR UPDATE')->fetchColumn();if($actual!==$expected)throw new PricingVersionConflict();}
    private function transaction(callable $work):void{$this->pdo->beginTransaction();try{$work();$this->pdo->commit();}catch(\Throwable $e){$this->pdo->rollBack();throw $e;}}
    private function event(string $type,int $admin,string $target,string $id):void{$this->audit?->append(new AuditEvent($type,'success',new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')),new AuditMetadata(['target_type'=>$target,'target_id'=>$id]),$admin));}
}
