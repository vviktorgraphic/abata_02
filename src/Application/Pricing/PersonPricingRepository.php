<?php
declare(strict_types=1);
namespace App\Application\Pricing;
use App\Domain\Pricing\PersonPricingConfiguration;
interface PersonPricingRepository
{
    public function get(): PersonPricingConfiguration;
    public function save(PersonPricingConfiguration $configuration, int $expectedVersion, int $adminId): PersonPricingConfiguration;
    public function deleteBand(int $bandId, int $expectedVersion, int $adminId): PersonPricingConfiguration;
}
