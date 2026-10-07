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
        $row = $this->pdo->query('SELECT version, one_night_surcharge, tourism_tax_per_person_per_night FROM occupancy_pricing_configuration WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException('Az occupancy árképzés konfigurációja hiányzik.');
        $bands = [];
        foreach ($this->pdo->query('SELECT id,guest_count,min_nights,max_nights,nightly_price,is_active,sort_order FROM occupancy_stay_length_bands ORDER BY guest_count,sort_order,id')->fetchAll(PDO::FETCH_ASSOC) as $b) $bands[] = new OccupancyStayLengthBand((int)$b['id'],(int)$b['guest_count'],(int)$b['min_nights'],$b['max_nights']===null?null:(int)$b['max_nights'],(string)$b['nightly_price'],(bool)$b['is_active'],(int)$b['sort_order']);
        $overrides = [];
        foreach ($this->pdo->query('SELECT id,start_date,end_date,min_nights,max_nights,price_1_guest,price_2_guests,price_3_guests,price_4_guests,is_active FROM occupancy_date_overrides ORDER BY start_date,id')->fetchAll(PDO::FETCH_ASSOC) as $o) $overrides[] = new OccupancyDateOverride((int)$o['id'],(string)$o['start_date'],(string)$o['end_date'],[1=>(string)$o['price_1_guest'],2=>(string)$o['price_2_guests'],3=>(string)$o['price_3_guests'],4=>(string)$o['price_4_guests']],(bool)$o['is_active'],(int)$o['min_nights'],$o['max_nights']===null?null:(int)$o['max_nights']);
        return new OccupancyPricingConfiguration((int)$row['version'],(string)$row['one_night_surcharge'],$bands,$overrides,(string)$row['tourism_tax_per_person_per_night']);
    }

    public function saveTourismTax(string $amount, int $expectedVersion, int $adminId): void
    {
        $amount = \App\Domain\Pricing\WholeHuf::normalize($amount);
        $this->transaction(function () use ($amount, $expectedVersion, $adminId): void {
            $this->assertVersion($expectedVersion);
            $statement = $this->pdo->prepare('UPDATE occupancy_pricing_configuration SET tourism_tax_per_person_per_night=:amount,version=version+1,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=1');
            $statement->execute(['amount' => $amount, 'admin' => $adminId]);
            $this->event('occupancy_pricing.tourism_tax_updated', $adminId, 'configuration', '1');
        });
    }

    public function saveSurcharge(string $amount, int $expectedVersion, int $adminId): void
    {
        $this->transaction(function () use ($amount,$expectedVersion,$adminId): void {
            $this->assertVersion($expectedVersion);
            $s=$this->pdo->prepare('UPDATE occupancy_pricing_configuration SET one_night_surcharge=:amount,version=version+1,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=1'); $s->execute(['amount'=>$amount,'admin'=>$adminId]);
            $this->event('occupancy_pricing.one_night_surcharge_updated',$adminId,'configuration','1');
        });
    }

    public function saveBand(OccupancyStayLengthBand $band, int $expectedVersion, int $adminId): int
    {
        return $this->mutate($expectedVersion,$adminId,function () use ($band,$adminId): int {
            $this->assertBandNoOverlap($band);
            if ($band->id > 0) { $this->band($band->id); }
            if ($band->id > 0) { $s=$this->pdo->prepare('UPDATE occupancy_stay_length_bands SET guest_count=:guest,min_nights=:min,max_nights=:max,nightly_price=:price,is_active=:active,sort_order=:sort,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id'); $s->execute(['guest'=>$band->guestCount,'min'=>$band->minNights,'max'=>$band->maxNights,'price'=>$band->nightlyPrice,'active'=>$band->active?1:0,'sort'=>$band->sortOrder,'admin'=>$adminId,'id'=>$band->id]); $id=$band->id; } else { $s=$this->pdo->prepare('INSERT INTO occupancy_stay_length_bands (guest_count,min_nights,max_nights,nightly_price,is_active,sort_order,created_by_admin_id,updated_by_admin_id) VALUES (:guest,:min,:max,:price,:active,:sort,:created_admin,:admin)'); $s->execute(['guest'=>$band->guestCount,'min'=>$band->minNights,'max'=>$band->maxNights,'price'=>$band->nightlyPrice,'active'=>$band->active?1:0,'sort'=>$band->sortOrder,'created_admin'=>$adminId,'admin'=>$adminId]); $id=(int)$this->pdo->lastInsertId(); }
            $this->event($band->id>0?'occupancy_pricing.band_updated':'occupancy_pricing.band_created',$adminId,'occupancy_stay_length_band',(string)$id); return $id;
        });
    }

    public function setBandActive(int $id, bool $active, int $expectedVersion, int $adminId): void { $this->mutate($expectedVersion,$adminId,function()use($id,$active,$adminId):int{$band=$this->band($id);$candidate=new OccupancyStayLengthBand($band->id,$band->guestCount,$band->minNights,$band->maxNights,$band->nightlyPrice,$active,$band->sortOrder);$this->assertBandNoOverlap($candidate);$s=$this->pdo->prepare('UPDATE occupancy_stay_length_bands SET is_active=:active,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['active'=>$active?1:0,'admin'=>$adminId,'id'=>$id]);$this->event($active?'occupancy_pricing.band_activated':'occupancy_pricing.band_deactivated',$adminId,'occupancy_stay_length_band',(string)$id);return $id;}); }
    public function saveOverride(OccupancyDateOverride $override, int $expectedVersion, int $adminId): int { return $this->mutate($expectedVersion,$adminId,function()use($override,$adminId):int{$this->assertOverrideNoOverlap($override);if($override->id>0){$this->override($override->id);}$p=[1=>$override->priceFor(1),2=>$override->priceFor(2),3=>$override->priceFor(3),4=>$override->priceFor(4)];if($override->id>0){$s=$this->pdo->prepare('UPDATE occupancy_date_overrides SET start_date=:start,end_date=:end,min_nights=:min,max_nights=:max,price_1_guest=:p1,price_2_guests=:p2,price_3_guests=:p3,price_4_guests=:p4,is_active=:active,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['start'=>$override->startDate,'end'=>$override->endDate,'min'=>$override->minNights,'max'=>$override->maxNights,'p1'=>$p[1],'p2'=>$p[2],'p3'=>$p[3],'p4'=>$p[4],'active'=>$override->active?1:0,'admin'=>$adminId,'id'=>$override->id]);$id=$override->id;}else{$s=$this->pdo->prepare('INSERT INTO occupancy_date_overrides(start_date,end_date,min_nights,max_nights,price_1_guest,price_2_guests,price_3_guests,price_4_guests,is_active,created_by_admin_id,updated_by_admin_id) VALUES(:start,:end,:min,:max,:p1,:p2,:p3,:p4,:active,:created_admin,:admin)');$s->execute(['start'=>$override->startDate,'end'=>$override->endDate,'min'=>$override->minNights,'max'=>$override->maxNights,'p1'=>$p[1],'p2'=>$p[2],'p3'=>$p[3],'p4'=>$p[4],'active'=>$override->active?1:0,'created_admin'=>$adminId,'admin'=>$adminId]);$id=(int)$this->pdo->lastInsertId();}$this->event($override->id>0?'occupancy_pricing.override_updated':'occupancy_pricing.override_created',$adminId,'occupancy_date_override',(string)$id);return$id;}); }
    public function setOverrideActive(int $id,bool $active,int $expectedVersion,int $adminId):void{$this->mutate($expectedVersion,$adminId,function()use($id,$active,$adminId):int{$o=$this->override($id);$candidate=new OccupancyDateOverride($o->id,$o->startDate,$o->endDate,$o->prices,$active,$o->minNights,$o->maxNights);$this->assertOverrideNoOverlap($candidate);$s=$this->pdo->prepare('UPDATE occupancy_date_overrides SET is_active=:active,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$s->execute(['active'=>$active?1:0,'admin'=>$adminId,'id'=>$id]);$this->event($active?'occupancy_pricing.override_activated':'occupancy_pricing.override_deactivated',$adminId,'occupancy_date_override',(string)$id);return$id;});}

    private function band(int $id): OccupancyStayLengthBand { foreach($this->get()->bands as $b) if($b->id===$id)return$b; throw new \InvalidArgumentException('Az ársáv nem található.'); }
    private function override(int $id): OccupancyDateOverride { foreach($this->get()->overrides as $o) if($o->id===$id)return$o; throw new \InvalidArgumentException('Az időszakos ár nem található.'); }
    private function assertBandNoOverlap(OccupancyStayLengthBand $candidate): void { if(!$candidate->active)return; foreach($this->get()->bands as $b) if($b->active&&$b->id!==$candidate->id&&$b->guestCount===$candidate->guestCount){$a=$candidate->maxNights??PHP_INT_MAX;$z=$b->maxNights??PHP_INT_MAX;if($candidate->minNights<=$z&&$b->minNights<=$a)throw new \InvalidArgumentException('Ez az ársáv átfedésben van egy már aktív, azonos létszámhoz tartozó ársávval. Előbb módosítsa vagy inaktiválja a meglévő ársávot. Ütköző ársáv: '.$b->guestCount.' fő, '.$b->minNights.' éjszakától '.($b->maxNights === null ? 'korlátlan ideig.' : $b->maxNights.' éjszakáig.'));} }
    private function assertOverrideNoOverlap(OccupancyDateOverride $candidate): void { if(!$candidate->active)return; foreach($this->get()->overrides as $o) if($o->active&&$o->id!==$candidate->id&&$candidate->startDate<=$o->endDate&&$o->startDate<=$candidate->endDate)throw new \InvalidArgumentException('Az aktív egyedi időszakok nem fedhetik át egymást.'); }

    private function mutate(int $expected,int $admin,callable $callback):int
    {
        $result = null;
        $this->transaction(function () use ($expected, $admin, $callback, &$result): void {
            $this->assertVersion($expected);
            $result = $callback();
            $statement = $this->pdo->prepare('UPDATE occupancy_pricing_configuration SET version=version+1,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=1');
            $statement->execute(['admin' => $admin]);
        });
        return (int) $result;
    }
    private function assertVersion(int $expected):void{$actual=(int)$this->pdo->query('SELECT version FROM occupancy_pricing_configuration WHERE id=1 FOR UPDATE')->fetchColumn();if($actual!==$expected)throw new PricingVersionConflict();}
    private function transaction(callable $work):void{$this->pdo->beginTransaction();try{$work();$this->pdo->commit();}catch(\Throwable $e){$this->pdo->rollBack();throw $e;}}
    private function event(string $type,int $admin,string $target,string $id):void{$this->audit?->append(new AuditEvent($type,'success',new \DateTimeImmutable('now',new \DateTimeZone('Europe/Budapest')),new AuditMetadata(['target_type'=>$target,'target_id'=>$id]),$admin));}
}
