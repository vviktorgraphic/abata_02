<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

interface AdminResponse
{
    public function send(): void;
}
