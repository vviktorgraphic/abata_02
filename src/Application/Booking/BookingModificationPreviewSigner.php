<?php
declare(strict_types=1);
namespace App\Application\Booking;
final readonly class BookingModificationPreviewSigner
{
    public function __construct(private string $secret)
    {
        if ($secret === '') throw new \InvalidArgumentException('A preview aláírókulcsa kötelező.');
    }
    public function sign(string $reference, int $version, ConfirmedBookingModification $request, string $pricingHash): string
    {
        return hash_hmac('sha256', $this->payload($reference, $version, $request, $pricingHash), $this->secret);
    }
    public function verify(string $signature, string $reference, int $version, ConfirmedBookingModification $request, string $pricingHash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $pricingHash) === 1
            && hash_equals($this->sign($reference, $version, $request, $pricingHash), $signature);
    }
    private function payload(string $reference, int $version, ConfirmedBookingModification $request, string $pricingHash): string
    {
        return json_encode([$reference, $version, $request->canonicalPayload(), $pricingHash], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
