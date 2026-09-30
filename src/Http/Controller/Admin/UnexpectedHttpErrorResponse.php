<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final class UnexpectedHttpErrorResponse
{
    public static function create(): HtmlResponse
    {
        return new HtmlResponse(
            '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Átmeneti hiba – A Bata</title></head><body><main><h1>Átmeneti hiba</h1><p>Az oldal most nem tölthető be. Kérjük, próbálja újra később.</p></main></body></html>',
            500,
        );
    }
}
