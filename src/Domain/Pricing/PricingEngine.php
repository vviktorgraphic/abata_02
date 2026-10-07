<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class PricingEngine
{
    private const TIMEZONE = 'Europe/Budapest';

    /**
     * Occupancy model: the configured nightly amount is for the whole party,
     * never multiplied by the number of adults or children.
     */
    public function calculateOccupancy(PricingInput $input, OccupancyPricingConfiguration $configuration, array $rules = [], ?\DateTimeImmutable $calculatedAt = null): PricingResult
    {
        [$arrival, $departure, $nights] = $this->period($input);
        if ($input->adults < 1) throw new \InvalidArgumentException('Legalább egy felnőtt szükséges.');
        foreach ($input->childAges as $age) if ($age < 0 || $age > 17) throw new \InvalidArgumentException('A gyermek életkora 0 és 17 év közötti lehet.');
        $physical = $input->adults + count($input->childAges);
        $chargeable = $input->adults + count(array_filter($input->childAges, static fn (int $age): bool => $age >= 4));
        if ($physical > 5 || $chargeable > 4) throw new \InvalidArgumentException('A szállás legfeljebb 5 vendéget fogad, de az árazási létszám legfeljebb 4 fő lehet.');
        $arrivalOverride = $configuration->overrideForNight($input->arrivalDate);
        if ($arrivalOverride !== null) {
            if ($nights < $arrivalOverride->minNights) {
                throw new OccupancyStayLengthViolation(sprintf(
                    'Erre az érkezési dátumra minimum %d éjszaka foglalható.',
                    $arrivalOverride->minNights,
                ));
            }
            if ($arrivalOverride->maxNights !== null && $nights > $arrivalOverride->maxNights) {
                throw new OccupancyStayLengthViolation(sprintf(
                    'Erre az érkezési dátumra legfeljebb %d éjszaka foglalható.',
                    $arrivalOverride->maxNights,
                ));
            }
        }
        $band = $arrivalOverride === null ? $configuration->bandFor($chargeable, $nights) : null;
        $items = [];
        $nightly = [];
        $overrideIds = [];
        $accommodationHuf = 0;
        for ($day = $arrival; $day < $departure; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $override = $configuration->overrideForNight($date);
            if ($override !== null) $overrideIds[$override->id] = true;
            if ($override === null && $band === null) {
                $band = $configuration->bandFor($chargeable, $nights);
            }
            $rate = $override?->priceFor($chargeable) ?? $band->nightlyPrice;
            $rateHuf = (int) $rate;
            $accommodationHuf += $rateHuf;
            $sourceId = $override?->id ?? $band?->id;
            $nightly[] = ['date'=>$date,'source'=>$override === null ? 'base_band' : 'date_override','source_id'=>$sourceId,'chargeable_guests'=>$chargeable,'nightly_price'=>$rate];
            $items[] = ['type'=>'accommodation','description'=>'Szállásdíj '.$date,'source'=>$override === null ? 'base_band' : 'date_override','source_id'=>$sourceId,'quantity'=>1,'unit_amount'=>$rate,'total'=>$this->huf($rateHuf),'total_huf'=>$rateHuf];
        }
        $surcharge = $nights === 1 ? (int) $configuration->oneNightSurcharge : 0;
        if ($surcharge > 0) { $accommodationHuf += $surcharge; $items[] = ['type'=>'one_night_surcharge','description'=>'Egyéjszakás felár','quantity'=>1,'unit_amount'=>$configuration->oneNightSurcharge,'total'=>$this->huf($surcharge),'total_huf'=>$surcharge]; }
        $taxHuf = 0; $otherHuf = 0;
        $exempt = false;
        foreach ($rules as $rule) if ($rule instanceof PricingRule && $rule->active && $rule->type === 'exemption' && $rule->exemptionKey !== null && in_array($rule->exemptionKey, $input->exemptionKeys, true) && $this->coversPeriod($rule, $arrival, $departure)) { $exempt = true; break; }
        foreach ($rules as $rule) {
            if (!$rule instanceof PricingRule || !$rule->active || $rule->type === 'base' || $rule->type === 'stay_length' || $rule->type === 'weekend' || $rule->type === 'seasonal') continue;
            if ($rule->type === 'fixed_fee' && $this->overlaps($rule, $arrival, $departure)) {
                $fee = $this->wholeHuf($rule->amount); $otherHuf += $fee; $items[] = ['type'=>'fixed_fee','description'=>$rule->name,'rule_id'=>$rule->id,'quantity'=>1,'unit_amount'=>$this->huf($fee),'total'=>$this->huf($fee),'total_huf'=>$fee];
            }
        }
        $taxQuantity = $exempt ? 0 : $input->adults * $nights;
        $taxHuf = (int) $configuration->tourismTaxPerPersonPerNight * $taxQuantity;
        $items[] = ['type'=>'tourism_tax','description'=>'Idegenforgalmi adó (IFA)','quantity'=>$taxQuantity,'unit_amount'=>$configuration->tourismTaxPerPersonPerNight,'total'=>$this->huf($taxHuf),'total_huf'=>$taxHuf];
        $now = ($calculatedAt ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $totalHuf = $accommodationHuf + $otherHuf + $taxHuf;
        $snapshot = ['version'=>5,'pricing_mode'=>'occupancy','calculated_at'=>$now->format(DATE_ATOM),'arrival_date'=>$input->arrivalDate,'departure_date'=>$input->departureDate,'nights'=>$nights,'adults'=>$input->adults,'child_ages'=>$input->childAges,'physical_guests'=>$physical,'chargeable_guests'=>$chargeable,'free_children'=>array_values(array_filter($input->childAges, static fn (int $age): bool => $age <= 3)),'chargeable_children'=>array_values(array_filter($input->childAges, static fn (int $age): bool => $age >= 4)),'occupancy_configuration_version'=>$configuration->version,'matched_base_band'=>$band?->snapshot(),'arrival_override_id'=>$arrivalOverride?->id,'arrival_override_min_nights'=>$arrivalOverride?->minNights,'arrival_override_max_nights'=>$arrivalOverride?->maxNights,'governing_stay_rule'=>$arrivalOverride === null ? ['type'=>'base_band','id'=>$band?->id,'min_nights'=>$band?->minNights,'max_nights'=>$band?->maxNights] : ['type'=>'date_override','id'=>$arrivalOverride->id,'min_nights'=>$arrivalOverride->minNights,'max_nights'=>$arrivalOverride->maxNights],'one_night_surcharge'=>$this->huf($surcharge),'nightly_breakdown'=>$nightly,'applied_date_override_ids'=>array_map('intval', array_keys($overrideIds)), 'line_items'=>$items,'accommodation_fee'=>$this->huf($accommodationHuf),'taxes'=>$this->huf($taxHuf),'other_fees'=>$this->huf($otherHuf),'total'=>$this->huf($totalHuf),'currency'=>'HUF'];
        $snapshot['tourism_tax_per_person_per_night'] = $configuration->tourismTaxPerPersonPerNight;
        $snapshot['tourism_tax_quantity'] = $taxQuantity;
        return new PricingResult($this->huf($totalHuf), $this->huf($accommodationHuf), $this->huf($taxHuf), 'HUF', $items, [], $snapshot);
    }

    /** @param list<PricingRule> $rules */
    public function calculate(PricingInput $input, array $rules, ?\DateTimeImmutable $calculatedAt = null, ?PersonPricingConfiguration $personConfiguration = null): PricingResult
    {
        [$arrival, $departure, $nights] = $this->period($input);
        $people = $input->adults + count($input->childAges);
        $active = array_values(array_filter($rules, static fn (PricingRule $r): bool => $r->active));
        $personSnapshot = null;
        if ($personConfiguration?->mode === 'person') {
            [$accommodation, $items, $applied, $personSnapshot] = $this->personAccommodation($input, $personConfiguration, $active, $arrival, $departure);
            $baseUnit = 'per_person_per_night';
        } else {
        $stay = array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'stay_length'
            && ($r->minimumNights === null || $nights >= $r->minimumNights)
            && ($r->maximumNights === null || $nights <= $r->maximumNights)
            && $this->coversPeriod($r, $arrival, $departure)));
        $baseCandidates = $stay !== [] ? $stay : array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'base' && $this->coversPeriod($r, $arrival, $departure)));
        $base = $this->winner($baseCandidates, 'base price');
        if ($base->baseUnit === null) {
            throw new PricingConfigurationError('The winning base rule has no base unit.');
        }
        $baseUnit = $base->baseUnit;

        $baseMinor = $this->minor($base->amount);
        $baseQuantity = match ($base->baseUnit) {
            'per_person_per_night' => $people * $nights,
            'per_night' => $nights,
            'per_booking' => 1,
        };
        $accommodation = $this->multiply($baseMinor, $baseQuantity);
        $items = [$this->item('accommodation', $base, $baseQuantity, $baseMinor, $accommodation)];
        $applied = [$base->id];

        foreach (['seasonal', 'weekend'] as $type) {
            $categoryBasis = $accommodation;
            $matchingNights = [];
            for ($day = $arrival; $day < $departure; $day = $day->modify('+1 day')) {
                $candidates = array_values(array_filter($active, function (PricingRule $r) use ($type, $day): bool {
                    if ($r->type !== $type || !$this->onDate($r, $day)) {
                        return false;
                    }
                    if ($type === 'weekend') {
                        if ($r->applicableWeekdays === []) {
                            throw new PricingConfigurationError('An active weekend rule has no explicitly configured weekdays.');
                        }
                        return in_array((int) $day->format('N'), $r->applicableWeekdays, true);
                    }
                    return true;
                }));
                if ($candidates !== []) {
                    $winner = $this->winner($candidates, $type.' adjustment');
                    $matchingNights[$winner->id] = ($matchingNights[$winner->id] ?? ['rule' => $winner, 'count' => 0]);
                    ++$matchingNights[$winner->id]['count'];
                }
            }
            foreach ($matchingNights as $entry) {
                /** @var PricingRule $rule */ $rule = $entry['rule'];
                $count = $entry['count'];
                $adjustment = $this->adjustment($rule, $categoryBasis, $count, $nights, $people, $base->baseUnit);
                $accommodation += $adjustment;
                $items[] = $this->item($type, $rule, $count, $this->minor($rule->amount), $adjustment);
                $applied[] = $rule->id;
            }
        }
        }

        $fixedFees = array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'fixed_fee' && $this->overlaps($r, $arrival, $departure)));
        $this->assertNoPriorityTies($fixedFees, 'fixed fee');
        foreach ($fixedFees as $rule) {
            $fee = $this->minor($rule->amount);
            $items[] = $this->item('fixed_fee', $rule, 1, $fee, $fee);
            $accommodation += $fee;
            $applied[] = $rule->id;
        }

        $tax = 0;
        $taxCandidates = array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'tourism_tax' && $this->coversPeriod($r, $arrival, $departure)));
        $exempt = array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'exemption' && $r->exemptionKey !== null
            && in_array($r->exemptionKey, $input->exemptionKeys, true) && $this->coversPeriod($r, $arrival, $departure)));
        $this->assertNoPriorityTies($exempt, 'exemption');
        if ($taxCandidates !== []) {
            $taxRule = $this->winner($taxCandidates, 'tourism tax');
            if ($exempt === []) {
                $quantity = match ($taxRule->baseUnit) {
                    'per_person_per_night' => $input->adults * $nights,
                    'per_night' => $nights,
                    'per_booking' => 1,
                    default => throw new PricingConfigurationError('Tourism tax has no valid base unit.'),
                };
                $tax = $this->multiply($this->minor($taxRule->amount), $quantity);
                $items[] = $this->item('tourism_tax', $taxRule, $quantity, $this->minor($taxRule->amount), $tax);
            } else {
                foreach ($exempt as $rule) { $applied[] = $rule->id; }
            }
            $applied[] = $taxRule->id;
        }

        // Sprint 6 owner instruction: displayed HUF line items use mathematical HALF_UP whole-forint rounding.
        $roundedItems = array_map(fn (array $item): array => $this->roundItem($item), $items);
        $accommodationHuf = array_sum(array_column(array_filter($roundedItems, static fn (array $i): bool => in_array($i['type'], ['accommodation', 'seasonal', 'weekend'], true)), 'total_huf'));
        $taxHuf = array_sum(array_column(array_filter($roundedItems, static fn (array $i): bool => $i['type'] === 'tourism_tax'), 'total_huf'));
        $totalHuf = array_sum(array_column($roundedItems, 'total_huf'));
        $now = ($calculatedAt ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $snapshot = [
            'version' => $personSnapshot === null ? 2 : 3, 'calculated_at' => $now->format(DATE_ATOM),
            'arrival_date' => $input->arrivalDate, 'departure_date' => $input->departureDate, 'nights' => $nights,
            'adults' => $input->adults, 'children' => array_map(static fn (int $age): array => ['age' => $age], $input->childAges),
            'pricing_rule_ids' => array_values(array_unique($applied)), 'base_unit' => $baseUnit,
            'line_items' => $roundedItems, 'accommodation_fee' => $this->huf($accommodationHuf),
            'taxes' => $this->huf($taxHuf), 'total' => $this->huf($totalHuf), 'currency' => 'HUF',
            'rounding' => ['mode' => 'HALF_UP', 'scale' => 0, 'stage' => 'line_item'],
        ];
        if ($personSnapshot !== null) {
            $snapshot += $personSnapshot;
            $snapshot['other_fees'] = $this->huf($totalHuf - $accommodationHuf - $taxHuf);
            $snapshot['applied_rules'] = array_map(static fn (PricingRule $rule): array => get_object_vars($rule),
                array_values(array_filter($active, static fn (PricingRule $rule): bool => in_array($rule->id, $applied, true))));
        }
        if ($totalHuf > 9999999999) { throw new PricingConfigurationError('Pricing total exceeds supported storage range.'); }
        return new PricingResult($this->huf($totalHuf), $this->huf($accommodationHuf), $this->huf($taxHuf), 'HUF', $roundedItems, $snapshot['pricing_rule_ids'], $snapshot);
    }

    /** @param list<PricingRule> $active @return array{int,list<array<string,mixed>>,list<int>,array<string,mixed>} */
    private function personAccommodation(PricingInput $input, PersonPricingConfiguration $configuration, array $active, \DateTimeImmutable $arrival, \DateTimeImmutable $departure): array
    {
        if ($configuration->adultWeekdayPrice === null || $configuration->adultWeekendPrice === null) {
            throw new PersonPricingNotConfigured('A felnőtt hétköznapi és hétvégi személyár még nincs beállítva.');
        }
        $childBands = [];
        foreach ($input->childAges as $age) {
            if ($age > 17) { throw new \InvalidArgumentException('Children must be aged 0 through 17; adults are 18 or older.'); }
            $childBands[] = $configuration->bandForAge($age);
        }
        $accommodation = 0;
        $stayBand = $configuration->adultBandForNights((int)$arrival->diff($departure)->format('%a'));
        $items = [];
        $applied = [];
        $nightly = [];
        for ($day = $arrival; $day < $departure; $day = $day->modify('+1 day')) {
            $weekend = in_array((int) $day->format('N'), [5, 6], true);
            $adultRate = $stayBand?->pricePerPersonPerNight ?? ($weekend ? $configuration->adultWeekendPrice : $configuration->adultWeekdayPrice);
            $adultTotal = $this->multiply($this->minor($adultRate), $input->adults);
            $childrenTotal = 0;
            $children = [];
            foreach ($childBands as $index => $band) {
                $rate = $weekend ? $band->weekendPrice : $band->weekdayPrice;
                $childrenTotal += $this->minor($rate);
                $children[] = ['age'=>$input->childAges[$index], 'band'=>$band->snapshot(), 'unit_amount'=>$rate, 'total'=>$rate];
            }
            $nightAmount = $adultTotal + $childrenTotal;
            $date = $day->format('Y-m-d');
            $items[] = ['type'=>'accommodation', 'description'=>'Személyalapú szállásdíj '.$date,
                'date'=>$date, 'configuration_version'=>$configuration->version,
                'quantity'=>1, 'unit_minor'=>$nightAmount, 'total_minor'=>$nightAmount];
            $seasonalItems = [];
            $seasonal = array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'seasonal' && $this->onDate($r, $day)));
            if ($seasonal !== []) {
                $rule = $this->winner($seasonal, 'seasonal adjustment');
                $adjustment = $this->adjustment($rule, $nightAmount, 1, 1, $input->adults + count($input->childAges), 'per_person_per_night');
                $item = $this->item('seasonal', $rule, 1, $this->minor($rule->amount), $adjustment);
                $item['date'] = $date;
                $items[] = $item;
                $seasonalItems[] = $this->roundItem($item);
                $nightAmount += $adjustment;
                $applied[] = $rule->id;
            }
            $accommodation += $nightAmount;
            $nightly[] = ['date'=>$date, 'weekend'=>$weekend, 'adults'=>$input->adults,
                'adult_rate_source'=>$stayBand === null ? ($weekend ? 'weekend' : 'weekday') : 'stay_length_band',
                'adult_stay_length_band_id'=>$stayBand?->id,
                'adult_unit_amount'=>$adultRate, 'adult_total'=>$this->huf(intdiv($adultTotal, 100)),
                'children'=>$children, 'children_total'=>$this->huf(intdiv($childrenTotal, 100)),
                'seasonal_adjustments'=>$seasonalItems, 'total'=>$this->huf(intdiv($adultTotal + $childrenTotal, 100) + array_sum(array_column($seasonalItems, 'total_huf')))];
        }
        return [$accommodation, $items, $applied, [
            'pricing_mode'=>'person', 'pricing_configuration_version'=>$configuration->version,
            'adult_weekday_price'=>$configuration->adultWeekdayPrice, 'adult_weekend_price'=>$configuration->adultWeekendPrice,
            'adult_stay_length_band'=>$stayBand?->snapshot(), 'adult_stay_length_bands'=>array_map(static fn ($b): array => $b->snapshot(), $configuration->adultStayLengthBands),
            'child_bands'=>array_map(static fn (ChildPriceBand $band): array => $band->snapshot(), $configuration->childBands),
            'child_ages'=>$input->childAges, 'child_maximum_age'=>17, 'adult_minimum_age'=>18,
            'weekend_iso_weekdays'=>[5,6], 'nightly_breakdown'=>$nightly,
            'compatibility'=>['replaced_rule_types'=>['base','stay_length','weekend'], 'retained_rule_types'=>['seasonal','fixed_fee','tourism_tax','exemption']],
        ]];
    }

    /** @return array{\DateTimeImmutable,\DateTimeImmutable,int} */
    private function period(PricingInput $input): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $arrival = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->arrivalDate, $tz);
        $departure = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->departureDate, $tz);
        if (!$arrival || !$departure || $arrival->format('Y-m-d') !== $input->arrivalDate || $departure->format('Y-m-d') !== $input->departureDate) {
            throw new \InvalidArgumentException('Pricing dates must use YYYY-MM-DD.');
        }
        $nights = (int) $arrival->diff($departure)->format('%r%a');
        if ($nights < 1) { throw new \InvalidArgumentException('Departure must be later than arrival.'); }
        return [$arrival, $departure, $nights];
    }

    /** @param list<PricingRule> $rules */
    private function winner(array $rules, string $context): PricingRule
    {
        if ($rules === []) { throw new PricingConfigurationError('No active rule resolves '.$context.'.'); }
        usort($rules, static fn (PricingRule $a, PricingRule $b): int => $b->priority <=> $a->priority ?: $a->id <=> $b->id);
        if (isset($rules[1]) && $rules[0]->priority === $rules[1]->priority) {
            throw new PricingConfigurationError('Multiple '.$context.' rules have the same winning priority.');
        }
        return $rules[0];
    }

    /** @param list<PricingRule> $rules */
    private function assertNoPriorityTies(array $rules, string $context): void
    {
        foreach ($rules as $index => $rule) {
            foreach (array_slice($rules, $index + 1) as $other) {
                if ($rule->priority === $other->priority && $this->ruleIntervalsOverlap($rule, $other)) {
                    throw new PricingConfigurationError('Multiple '.$context.' rules have the same priority.');
                }
            }
        }
    }

    private function ruleIntervalsOverlap(PricingRule $first, PricingRule $second): bool
    {
        $firstUntil = $first->validUntil ?? '9999-12-31';
        $secondUntil = $second->validUntil ?? '9999-12-31';
        return $first->validFrom < $secondUntil && $second->validFrom < $firstUntil;
    }

    private function coversPeriod(PricingRule $r, \DateTimeImmutable $from, \DateTimeImmutable $to): bool { return $this->onDate($r, $from) && ($r->validUntil === null || $r->validUntil >= $to->format('Y-m-d')); }
    private function overlaps(PricingRule $r, \DateTimeImmutable $from, \DateTimeImmutable $to): bool { return $r->validFrom < $to->format('Y-m-d') && ($r->validUntil === null || $r->validUntil > $from->format('Y-m-d')); }
    private function onDate(PricingRule $r, \DateTimeImmutable $day): bool { $date = $day->format('Y-m-d'); return $r->validFrom <= $date && ($r->validUntil === null || $date < $r->validUntil); }
    private function minor(string $value): int { [$whole, $fraction] = explode('.', $value); return $this->multiply((int) $whole, 100) + (int) $fraction; }
    private function multiply(int $a, int $b): int { if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) { throw new PricingConfigurationError('Pricing arithmetic overflow.'); } return $a * $b; }
    private function adjustment(PricingRule $r, int $current, int $matching, int $nights, int $people, string $unit): int
    {
        if ($r->adjustmentMode === 'percent') {
            $basisPoints = $this->minor($r->amount); // 20.00% = 2000 basis points
            return intdiv($this->multiply(intdiv($this->multiply($current, $matching), $nights), $basisPoints) + 5000, 10000);
        }
        if ($r->adjustmentMode !== 'fixed') { throw new PricingConfigurationError('Adjustment rule has no valid mode.'); }
        $quantity = match ($unit) { 'per_person_per_night' => $matching * $people, 'per_night' => $matching, 'per_booking' => 1 };
        return $this->multiply($this->minor($r->amount), $quantity);
    }
    /** @return array<string,mixed> */
    private function item(string $type, PricingRule $r, int $quantity, int $unit, int $total): array { return ['type'=>$type,'description'=>$r->name,'rule_id'=>$r->id,'quantity'=>$quantity,'unit_minor'=>$unit,'total_minor'=>$total]; }
    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function roundItem(array $item): array
    {
        $huf = intdiv((int) $item['total_minor'] + 50, 100);
        $quantity = (int) $item['quantity'];
        $unitMinor = $quantity === 0 ? 0 : intdiv($this->multiply($huf, 100) + intdiv($quantity, 2), $quantity);
        unset($item['unit_minor'], $item['total_minor']);
        $item['unit_amount'] = intdiv($unitMinor, 100).'.'.str_pad((string) ($unitMinor % 100), 2, '0', STR_PAD_LEFT);
        $item['total'] = $this->huf($huf);
        $item['total_huf'] = $huf;
        return $item;
    }
    private function huf(int $whole): string { return $whole.'.00'; }
    private function wholeHuf(string $value): int
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,9})\.00$/D', $value)) {
            throw new PricingConfigurationError('A konfigurált díj csak egész forint lehet.');
        }
        return (int) substr($value, 0, -3);
    }
}
