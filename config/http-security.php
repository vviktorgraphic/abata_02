<?php

declare(strict_types=1);

return App\Http\HttpSecurityConfiguration::fromValues(
    getenv('APP_ENV') ?: 'production',
    getenv('HSTS_MAX_AGE_SECONDS'),
    getenv('TRUSTED_PROXY_IPS'),
);
