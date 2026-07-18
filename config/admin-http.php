<?php

declare(strict_types=1);

use App\Application\Audit\AuditMetadataSanitizer;
use App\Application\Authentication\AuthenticationService;
use App\Application\Mail\TwoFactorMailRenderer;
use App\Application\TwoFactor\IssueTwoFactorCode;
use App\Application\TwoFactor\VerifyTwoFactorCode;
use App\Domain\Authentication\EmailNormalizer;
use App\Domain\Authentication\NativePasswordVerifier;
use App\Domain\TwoFactor\TwoFactorClock;
use App\Domain\TwoFactor\TwoFactorCodeGenerator;
use App\Http\Controller\Admin\AdminView;
use App\Http\Controller\Admin\DashboardController;
use App\Http\Controller\Admin\DefaultAdminAuthWorkflow;
use App\Http\Controller\Admin\LoginController;
use App\Http\Controller\Admin\LogoutController;
use App\Http\Controller\Admin\TwoFactorController;
use App\Http\Controller\Admin\BookingManagementController;
use App\Http\Controller\Admin\BlockedPeriodController;
use App\Http\Controller\Admin\PricingAdminController;
use App\Http\Controller\Admin\AdminActionGuard;
use App\Http\Controller\Admin\CalendarAdminController;
use App\Http\Controller\Admin\SecurityAdminActionRateLimiter;
use App\Infrastructure\Persistence\Booking\PdoAdminBookingQueryRepository;
use App\Infrastructure\Persistence\Booking\PdoBlockedPeriodRepository;
use App\Infrastructure\Persistence\Booking\TransactionalBookingRepository;
use App\Infrastructure\Persistence\Pricing\PdoPricingEngineAdapter;
use App\Infrastructure\Persistence\Pricing\PdoPricingRuleRepository;
use App\Application\Booking\BlockedPeriodService;
use App\Application\Mail\BookingStatusNotificationDispatcher;
use App\Application\Mail\BookingStatusMailRenderer;
use App\Infrastructure\Persistence\Booking\PdoBookingStatusNotificationOutbox;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Mail\SmtpConfiguration;
use App\Infrastructure\Mail\SmtpMailer;
use App\Infrastructure\Persistence\Auth\AdminSessionRepository;
use App\Infrastructure\Persistence\Auth\PdoAdminCredentialRepository;
use App\Infrastructure\Persistence\Auth\PdoAuditLog;
use App\Infrastructure\Persistence\Auth\PdoRateLimitRepository;
use App\Infrastructure\Persistence\Auth\PdoTwoFactorCodeStore;
use App\Security\Csrf\CsrfTokenManager;
use App\Security\RateLimit\AuthenticationRateLimitPolicies;
use App\Security\RateLimit\RateLimitClock;
use App\Security\RateLimit\RateLimiter;
use App\Security\RateLimit\RateLimitPolicy;
use App\Security\Session\AdminSession;
use App\Security\Session\NativeSessionIdRotator;
use App\Security\Session\NativeSessionStorage;
use App\Security\Session\SessionCookieOptions;
use App\Security\Session\SystemClock;
use App\Application\Calendar\BudapestCalendarSyncClock;
use App\Application\Calendar\CalendarImportService;
use App\Application\Calendar\IcsParser;
use App\Application\Calendar\SecureCalendarFeedFetcher;
use App\Infrastructure\Calendar\CurlCalendarFeedHttpClient;
use App\Infrastructure\Calendar\NativeCalendarHostResolver;
use App\Infrastructure\Persistence\Calendar\PdoCalendarExportTokenRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarSourceRepository;
use App\Infrastructure\Persistence\Calendar\PdoCalendarSyncLogRepository;
use App\Infrastructure\Persistence\Calendar\PdoExternalCalendarEventRepository;

$root = dirname(__DIR__);
$authConfig = require $root . '/config/auth.php';
$mailConfig = require $root . '/config/mail.php';
if ($authConfig['rate_limit_pepper'] === '') {
    throw new RuntimeException('AUTH_RATE_LIMIT_PEPPER is required.');
}
if ((getenv('APP_ENV') ?: 'production') === 'production' && !$authConfig['cookie_secure']) {
    throw new RuntimeException('SESSION_COOKIE_SECURE=true is required in production.');
}
$pdo = ConnectionFactory::create(require $root . '/config/database.php');
$dateClock = new class implements TwoFactorClock, RateLimitClock {
    public function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('Europe/Budapest')); }
};
$storage = new NativeSessionStorage(new SessionCookieOptions($authConfig['cookie_secure'], true, 'Lax'));
$session = new AdminSession($storage, new NativeSessionIdRotator(), new SystemClock(), $authConfig['session_idle_timeout_seconds']);
$csrf = new CsrfTokenManager($storage);
$admins = new PdoAdminCredentialRepository($pdo);
$codes = new PdoTwoFactorCodeStore($pdo);
$rateLimiter = new RateLimiter(new PdoRateLimitRepository($pdo), $dateClock, $authConfig['rate_limit_pepper']);
$policies = new AuthenticationRateLimitPolicies(
    new RateLimitPolicy('login_ip', $authConfig['login_ip_limit'], $authConfig['login_window_seconds'], $authConfig['lockout_seconds']),
    new RateLimitPolicy('login_account', $authConfig['login_account_limit'], $authConfig['login_window_seconds'], $authConfig['lockout_seconds']),
    new RateLimitPolicy('two_factor_verify', 5, 600, $authConfig['lockout_seconds']),
    new RateLimitPolicy('two_factor_resend', 1, 60, 60),
);
$username = $mailConfig['username'] === '' ? null : $mailConfig['username'];
$password = $mailConfig['password'] === '' ? null : $mailConfig['password'];
$workflow = new DefaultAdminAuthWorkflow(
    new AuthenticationService($admins, new NativePasswordVerifier(), new EmailNormalizer(), password_hash('non-account-timing-placeholder', PASSWORD_DEFAULT)),
    $admins,
    new IssueTwoFactorCode($codes, new TwoFactorCodeGenerator($dateClock), $dateClock),
    new VerifyTwoFactorCode($codes, $dateClock),
    new SmtpMailer(new SmtpConfiguration($mailConfig['host'], $mailConfig['port'], $mailConfig['encryption'], $username, $password, production: $mailConfig['production'])),
    new TwoFactorMailRenderer($root . '/templates/email', $mailConfig['from_email']),
    $session,
    new AdminSessionRepository($pdo, $authConfig['session_absolute_timeout_seconds']),
    $rateLimiter,
    $policies,
    new PdoAuditLog($pdo),
    new AuditMetadataSanitizer(),
    $pdo,
    $authConfig['rate_limit_pepper'],
    $authConfig['session_idle_timeout_seconds'],
);
$view = new AdminView($root . '/templates');
$audit = new PdoAuditLog($pdo);
$queries = new PdoAdminBookingQueryRepository($pdo);
$actionGuard = new AdminActionGuard($workflow, $csrf, new SecurityAdminActionRateLimiter($rateLimiter, new RateLimitPolicy('admin_action', 20, 60, 60)));
$transitions = new TransactionalBookingRepository($pdo);
$blockedRepository = new PdoBlockedPeriodRepository($pdo, $audit);
$statusNotifications = new BookingStatusNotificationDispatcher(
    new PdoBookingStatusNotificationOutbox($pdo),
    new BookingStatusMailRenderer($root . '/templates/email', $mailConfig['from_email']),
    new SmtpMailer(new SmtpConfiguration($mailConfig['host'], $mailConfig['port'], $mailConfig['encryption'], $username, $password, production: $mailConfig['production'])),
    $audit,
);
$calendarSources = new PdoCalendarSourceRepository($pdo);
$calendarLogs = new PdoCalendarSyncLogRepository($pdo);
$calendarImporter = new CalendarImportService(
    $calendarSources,
    $calendarLogs,
    new PdoExternalCalendarEventRepository($pdo),
    new SecureCalendarFeedFetcher(new CurlCalendarFeedHttpClient(), new NativeCalendarHostResolver()),
    new IcsParser(),
    new BudapestCalendarSyncClock(),
);

return [
    'login' => new LoginController($workflow, $view, $csrf),
    'two_factor' => new TwoFactorController($workflow, $view, $csrf),
    'dashboard' => new DashboardController($workflow, $view, $csrf, $queries),
    'bookings' => new BookingManagementController($workflow, $view, $csrf, $actionGuard, $queries, $transitions, $statusNotifications),
    'blocked_periods' => new BlockedPeriodController($workflow, $view, $csrf, $actionGuard, new BlockedPeriodService($blockedRepository), $blockedRepository),
    'pricing' => new PricingAdminController(
        $workflow,
        $view,
        $csrf,
        $actionGuard,
        new PdoPricingRuleRepository($pdo),
        new PdoPricingEngineAdapter($pdo),
        $audit,
    ),
    'calendar' => new CalendarAdminController(
        $workflow, $view, $csrf, $actionGuard, $calendarSources, $calendarLogs,
        new PdoCalendarExportTokenRepository($pdo), $calendarImporter, $audit,
    ),
    'logout' => new LogoutController($workflow, $csrf, $view),
];
