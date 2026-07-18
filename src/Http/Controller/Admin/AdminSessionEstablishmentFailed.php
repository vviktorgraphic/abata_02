<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use RuntimeException;
use Throwable;

final class AdminSessionEstablishmentFailed extends RuntimeException
{
    public static function afterSuccessfulTwoFactor(?Throwable $previous = null): self
    {
        return new self('Authenticated admin session establishment failed.', 0, $previous);
    }
}
