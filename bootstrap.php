<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

\App\Support\Env::load(__DIR__ . '/.env');

// Timestamps are stored and rendered as UTC (SQLite datetime('now')). Pin
// PHP's default timezone to UTC so those naive strings stay unambiguous on any
// host. Per-visitor localisation happens client-side (see assets/js/localtime).
date_default_timezone_set('UTC');

$container = [];
$container['settings'] = [
    'is_dev' => ($_ENV['IS_DEV'] ?? 'false') === 'true',
    'db_file' => $_ENV['DB_FILE'] ?? 'gpro_pilots.sqlite',
];

$container['config'] = [
    'app' => require __DIR__ . '/config/game_constants.php',
    'secrets' => file_exists(__DIR__ . '/config/secrets.php') ? require __DIR__ . '/config/secrets.php' : [],
];

$container['config']['settings'] = $container['settings'];

$container['version'] = \App\Support\Version::current();

$container['config']['api'] = [
    'base_url'        => $_ENV['GPRO_API_BASE_URL'] ?? 'https://gpro.net',
    'version'         => $container['version'],
    'connect_timeout' => (int) ($_ENV['GPRO_API_CONNECT_TIMEOUT'] ?? 10),
    'timeout'         => (int) ($_ENV['GPRO_API_TIMEOUT'] ?? 30),
    'market_timeout'  => (int) ($_ENV['GPRO_API_MARKET_TIMEOUT'] ?? 60),
];

use App\Database\Database;
use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;

$container['db'] = Database::getConnection();

$container['service.api_token_crypto'] = new ApiTokenCrypto($_ENV['APP_SECRET']);

$container['db.seeder'] = new DatabaseSeeder(
    $container['db'],
    $container['config']['app']['stats_schema'],
    $container['config']['app']['divisions'],
    $container['config']['secrets'],
    $container['service.api_token_crypto'],
);
$container['db.seeder']->migrate();

use App\Cache\CacheFactory;

$cacheConfig = [
    'CACHE_DRIVER'   => $_ENV['CACHE_DRIVER'] ?? 'none',
    'REDIS_HOST'     => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    'REDIS_PORT'     => $_ENV['REDIS_PORT'] ?? 6379,
    'REDIS_PASSWORD' => $_ENV['REDIS_PASSWORD'] ?? null,
];

$container['service.cache'] = CacheFactory::create($cacheConfig);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
$loader = new FilesystemLoader(__DIR__ . '/templates');
$container['twig'] = new Environment($loader, [
    'debug' => $container['settings']['is_dev'],
]);
if ($container['settings']['is_dev']) {
    $container['twig']->addExtension(new \Twig\Extension\DebugExtension());
}
$container['twig']->addGlobal('app_version', $container['version']);
// Canonical origin for SEO tags (rel=canonical, og:url, og:image). Override
// with APP_PUBLIC_URL when serving from a different domain (e.g. staging).
$container['twig']->addGlobal(
    'canonical_host',
    rtrim($_ENV['APP_PUBLIC_URL'] ?? 'https://gpro-pitwall.com', '/'),
);
$container['twig']->addGlobal('no_pilot_message', \App\Controller\StrategyController::NO_PILOT_MESSAGE);

use App\Repository\PilotRepository;
use App\Repository\DivisionMetadataRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Repository\TokenRepository;

$container['repo.pilot'] = new PilotRepository($container['db']);
$container['repo.metadata'] = new DivisionMetadataRepository($container['db']);
$container['repo.track'] = new TrackRepository($container['db']);
$container['repo.audit'] = new \App\Repository\AuditLogRepository($container['db']);

use App\Service\PilotCalculatorService;
use App\Service\IdealPilotService;
use App\Service\InsightService;
use App\Service\RecruitmentService;
use App\Service\CarWearService;
use App\Service\StrategyService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use App\Service\SetupCalculatorService;
use App\Service\TrainingService;

use App\Service\GproSyncService;



use App\Security\EmailCrypto;

$container['service.email_crypto'] = new EmailCrypto($_ENV['APP_SECRET']);

$container['service.user_repo'] = new \App\Repository\UserRepository(
    $container['db'],
    $container['service.email_crypto'],
    $container['service.api_token_crypto'],
);
$container['service.token_repo'] = new \App\Repository\TokenRepository($container['db']);
$container['service.pending_registration_repo'] = new \App\Repository\PendingRegistrationRepository(
    $container['db'],
    $container['service.email_crypto'],
);
$container['service.security_log'] = new \App\Service\SecurityLogger();

$container['service.persistent_token_repo'] =
    new \App\Repository\PersistentTokenRepository($container['db']);

// Secure cookie flag: true whenever the request is HTTPS, and always in prod
// (so a misconfigured proxy can never downgrade a long-lived remember cookie).
$cookiesSecure = \App\Support\RequestContext::isHttps($_SERVER)
    || !$container['settings']['is_dev'];
$container['service.persistent_login'] = new \App\Service\PersistentLoginService(
    $container['service.persistent_token_repo'],
    new \App\Service\PhpCookieJar(),
    $cookiesSecure,
    60 * 60 * 24 * 30,
    $container['service.security_log'],
);

$container['service.authorize'] = new \App\Security\Authorize($container['service.user_repo']);

// "Keep me signed in": with no active session but a valid remember cookie,
// silently re-establish the session before identity is resolved below.
// Restored sessions are marked not-fresh so the step-up gate re-prompts before
// sensitive actions.
if (empty($_SESSION['user_id'])) {
    $restoredUserId = $container['service.persistent_login']->restore();
    if ($restoredUserId !== null) {
        $restoredUser = $container['service.user_repo']->findById($restoredUserId);
        if ($restoredUser !== null) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id']    = $restoredUserId;
            $_SESSION['username']   = $restoredUser['username'];
            $_SESSION['auth_fresh'] = false;
        }
    }
}

// Logged-in identity as Twig globals so layout-level features (e.g. the
// Simple Analytics gate injected at build time) work on EVERY page, not just
// the controllers that happen to pass `is_logged_in`/`user` in their render.
$currentUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$currentUser   = $currentUserId
    ? $container['service.user_repo']->findById($currentUserId)
    : null;
if ($currentUserId && !$currentUser) {
    session_destroy();
    $currentUserId = 0;
}
// Redact the decrypted API token before exposing the user as a Twig global:
// no template needs it, and keeping it out of the global means a future
// {{ user.api_token }} can't accidentally leak the secret into HTML.
$currentUserSafe = $currentUser;
if (is_array($currentUserSafe)) {
    unset($currentUserSafe['api_token']);
}
$container['twig']->addGlobal('is_logged_in', $currentUser !== null);
$container['twig']->addGlobal('user', $currentUserSafe);

$mailCfg = [
    'host'       => $_ENV['MAIL_HOST'] ?? 'localhost',
    'port'       => $_ENV['MAIL_PORT'] ?? 25,
    'user'       => $_ENV['MAIL_USER'] ?? '',
    'pass'       => $_ENV['MAIL_PASS'] ?? '',
    'from'       => $_ENV['MAIL_FROM'] ?? 'admin@gpro-pitwall.com',
    'from_name'  => $_ENV['MAIL_FROM_NAME'] ?? 'GPRO Pitwall',
    'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
    'is_dev'     => $container['settings']['is_dev'],
    'mail_dir'   => __DIR__ . '/var/mail',
];

$container['service.rate_limiter'] = new \App\Service\RateLimiterService($container['service.cache']);
$container['service.email_service']  = new \App\Service\EmailService($mailCfg);
$container['service.recaptcha'] = new \App\Service\ReCaptchaService(
    $_ENV['RECAPTCHA_SECRET_KEY'] ?? '',
    $container['settings']['is_dev'],
);



// Server-wide outbound throttle: all GPRO calls leave from one host IP, so cap
// the aggregate rate (token bucket shared across workers via an flock'd file in
// var/cache) regardless of how many users sync at once. Disable with GPRO_API_RATE=0.
$container['service.api_throttle'] = new \App\Service\GproApiThrottle(
    __DIR__ . '/var/cache/gpro_api_throttle.json',
    (float) ($_ENV['GPRO_API_RATE'] ?? 2.0),
    (float) ($_ENV['GPRO_API_BURST'] ?? 4.0),
    (int) ($_ENV['GPRO_API_MAX_BLOCK_MS'] ?? 4000),
);
$container['service.api_fetcher'] = new \App\Service\GproApiFetcher(
    $container['config']['api'],
    $container['service.api_throttle'],
);
$container['service.api_client'] = new GproApiClient(
    $container['service.api_fetcher'],
    $container['service.cache']
);
$container['service.gpro_sync'] = new GproSyncService(
    $container['service.api_client'],
    $container['service.user_repo'],
    $container['service.cache'],
    (int) ($_ENV['SYNC_SAFETY_MARGIN'] ?? 20),
);
$container['service.auth_service']  = new \App\Service\AuthService(
    $container['service.user_repo'],
    $container['service.token_repo'],
    $container['service.pending_registration_repo'],
    $container['service.email_service'],
    $container['service.rate_limiter'],
    $container['service.recaptcha'],
    $container['service.email_crypto'],
    $_ENV['APP_SECRET'],
    $container['service.gpro_sync'],
    $container['service.persistent_login'],
    $container['service.security_log'],
    (int)$_ENV['VERIFICATION_CODE_TTL_SECONDS'] ?: 600,
    (int)$_ENV['VERIFICATION_MAX_ATTEMPTS'] ?: 5,
    (int) ($_ENV['SYNC_MIN_INTERVAL_SECONDS'] ?? 600),
    (int) ($_ENV['MAX_CODES_PER_USER_PER_HOUR'] ?? 3),
);
$container['service.data_mapper'] = new GproDataMapper();

$container['service.calculator'] = new PilotCalculatorService(
    $container['config']['secrets']['pilot_factors'],
    $container['config']['secrets']['division_caps']
);

$container['service.ideal_pilot'] = new IdealPilotService(
    $container['repo.pilot'],
    $container['service.calculator'],
    $container['config']['app']['stats_schema']
);

$container['service.insight'] = new InsightService($container['config']['app']['divisions']);

$container['service.recruitment'] = new RecruitmentService(
    $container['config']['app']['csv_to_schema_map'],
    $container['config']['secrets']['division_caps'],
    $container['service.ideal_pilot']
);

$container['service.car_wear'] = new CarWearService(
    $container['db'],
    $container['config']['secrets']
);

$container['service.strategy'] = new StrategyService(
    $container['db'],
    $container['config']['secrets']
);
$container['service.setup_calculator'] = new SetupCalculatorService($container['db'], $container['config']['secrets']);
$container['service.training'] = new TrainingService($container['db']);



use App\Controller\PageController;
use App\Controller\BaselineController;
use App\Controller\RecruitmentController;
use App\Controller\CarWearController;
use App\Controller\TrainingController;
use App\Controller\StrategyController;
use App\Controller\AuthController;

$container['service.race_weather'] = new \App\Service\RaceWeatherService();
$container['service.risk_advisor'] = new \App\Service\RiskAdvisorService();
$container['service.pha_match'] = new \App\Service\PhaMatchService();

$container['controller.strategy'] = new StrategyController(
    $container['service.strategy'],
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.setup_calculator'],
    $container['service.authorize'],
    $container['service.race_weather'],
    $container['service.risk_advisor'],
    $container['service.pha_match'],
    $container['service.car_wear'],
    $container['twig'],
);

$container['controller.auth'] = new AuthController(
    $container['service.auth_service'],
    $container['twig'],
    $_ENV['RECAPTCHA_SITE_KEY'] ?? ''
);

$container['service.boost_fuel'] = new \App\Service\BoostFuelService();
$container['service.wear_advisor'] = new \App\Service\WearAdvisorService();
$container['service.upgrade_advisor'] = new \App\Service\PartUpgradeAdvisorService(
    $container['service.pha_match'],
    $container['config']['secrets']['part_pha_contribution'] ?? [],
);
$container['service.swap_advisor'] = new \App\Service\PartSwapAdvisorService(
    $container['service.car_wear'],
    $container['service.pha_match'],
    $container['service.upgrade_advisor'],
);
$container['service.training_advisor'] = new \App\Service\TrainingAdvisorService(
    $container['config']['secrets']['pilot_recruitment_factors'] ?? [],
);
$container['service.testing_projection'] = new \App\Service\TestingProjectionService(
    $container['config']['secrets']['testing_priority_points'] ?? [],
    (float) ($container['config']['secrets']['testing_decay_factor'] ?? 0.5),
);
$container['service.sponsor_advisor'] = new \App\Service\SponsorAdvisorService();
$container['service.testing_targets'] = new \App\Service\TestingTargetsService();

$container['controller.testing'] = new \App\Controller\TestingController(
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.setup_calculator'],
    $container['service.car_wear'],
    $container['config']['secrets']['testing_priority_points'] ?? [],
);

// CarWearController is constructed early because PageController needs it
// (auto-populate on first Car Wear tab visit). All deps already wired above.
$container['controller.car_wear'] = new CarWearController(
    $container['service.car_wear'],
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.authorize'],
    $container['twig'],
);

$container['controller.page'] = new PageController(
    $container['service.ideal_pilot'],
    $container['service.insight'],
    $container['repo.metadata'],
    $container['service.training'],
    $container['service.user_repo'],
    $container['service.api_client'],
    $container['service.pha_match'],
    $container['service.race_weather'],
    $container['service.car_wear'],
    $container['service.wear_advisor'],
    $container['service.swap_advisor'],
    $container['service.testing_projection'],
    $container['service.sponsor_advisor'],
    $container['service.testing_targets'],
    $container['service.training_advisor'],
    $container['controller.strategy'],
    $container['controller.car_wear'],
    $container['controller.testing'],
    $container['service.data_mapper'],
    $container['service.recruitment'],
    $container['twig'],
    $container['config']
);

$container['controller.debug'] = new \App\Controller\DebugController(
    $container['service.user_repo'],
    $container['service.cache'],
    $container['twig'],
    Database::path(),
    $container['service.authorize'],
);

$container['controller.baseline'] = new BaselineController(
    $container['repo.pilot'],
    $container['repo.metadata'],
    $container['service.calculator'],
    $container['config']['app']['stats_schema'],
    $container['service.authorize'],
);

$container['service.contact'] = new \App\Service\ContactService(
    $container['service.email_service'],
    $container['service.rate_limiter'],
    $container['service.user_repo'],
    $container['service.email_crypto'],
    $container['service.security_log'],
);

$container['controller.contact'] = new \App\Controller\ContactController(
    $container['service.contact'],
    $container['service.authorize'],
    $container['twig'],
);

$container['controller.control_panel'] = new \App\Controller\ControlPanelController(
    $container['service.user_repo'],
    $container['twig'],
    $container['service.authorize'],
    $container['service.auth_service'],
);

$container['controller.recruitment'] = new RecruitmentController(
    $container['service.recruitment'],
    $container['service.api_client'],
    $container['service.authorize'],
);

$container['controller.training'] = new TrainingController(
    $container['service.training'],
    $container['service.calculator'],
    $container['service.authorize'],
);

use App\Controller\ApiWarmupController;

$container['controller.api_warmup'] = new ApiWarmupController(
    $container['service.gpro_sync'],
    $container['service.api_client'],
    $container['service.authorize'],
);

$container['controller.health'] = new \App\Controller\HealthController(
    $container['db'],
    $container['service.cache'],
);

$container['service.admin_users'] = new \App\Service\AdminUserService(
    $container['service.user_repo'],
    $container['repo.audit'],
    $container['service.auth_service'],
    $container['service.pending_registration_repo'],
);

$container['controller.admin_users'] = new \App\Controller\AdminUserController(
    $container['service.admin_users'],
    $container['service.authorize'],
    $container['twig'],
);

return $container;
