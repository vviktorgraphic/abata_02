<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final class PricingEngine
{
    private const TIMEZONE = 'Europe/Budapest';

    /** @param list<PricingRule> $rules */
    public function calculate(PricingInput $input, array $rules, ?\DateTimeImmutable $calculatedAt = null): PricingResult
    {
        [$arrival, $departure, $nights] = $this->period($input);
        $people = $input->adults + count($input->childAges);
        $active = array_values(array_filter($rules, static fn (PricingRule $r): bool => $r->active));
        $stay = array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'stay_length'
            && ($r->minimumNights === null || $nights >= $r->minimumNights)
            && ($r->maximumNights === null || $nights <= $r->maximumNights)
            && $this->coversPeriod($r, $arrival, $departure)));
        $baseCandidates = $stay !== [] ? $stay : array_values(array_filter($active, fn (PricingRule $r): bool => $r->type === 'base' && $this->coversPeriod($r, $arrival, $departure)));
        $base = $this->winner($baseCandidates, 'base price');
        if ($base->baseUnit === null) {
            throw new PricingConfigurationError('The winning base rule has no base unit.');
        }

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
                    'per_person_per_night' => $people * $nights,
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
            'version' => 2, 'calculated_at' => $now->format(DATE_ATOM),
            'arrival_date' => $input->arrivalDate, 'departure_date' => $input->departureDate, 'nights' => $nights,
            'adults' => $input->adults, 'children' => array_map(static fn (int $age): array => ['age' => $age], $input->childAges),
            'pricing_rule_ids' => array_values(array_unique($applied)), 'base_unit' => $base->baseUnit,
            'line_items' => $roundedItems, 'accommodation_fee' => $this->huf($accommodationHuf),
            'taxes' => $this->huf($taxHuf), 'total' => $this->huf($totalHuf), 'currency' => 'HUF',
            'rounding' => ['mode' => 'HALF_UP', 'scale' => 0, 'stage' => 'line_item'],
        ];
        return new PricingResult($this->huf($totalHuf), $this->huf($accommodationHuf), $this->huf($taxHuf), 'HUF', $roundedItems, $snapshot['pricing_rule_ids'], $snapshot);
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
    private function roundItem(array $item): array { $huf = intdiv((int) $item['total_minor'] + 50, 100); unset($item['unit_minor'], $item['total_minor']); $item['unit_amount'] = number_format(((int) $item['quantity'] === 0 ? 0 : $huf / (int) $item['quantity']), 2, '.', ''); $item['total'] = $this->huf($huf); $item['total_huf'] = $huf; return $item; }
    private function huf(int $whole): string { return $whole.'.00'; }
}
