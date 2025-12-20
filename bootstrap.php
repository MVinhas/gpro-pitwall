<?php

// bootstrap.php

require_once __DIR__ . '/vendor/autoload.php';

// 1. Load Env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $invalidPathException) {
    // Continue if .env missing
}

// 2. Container Setup
$container = [];
$container['settings'] = [
    'is_dev' => ($_ENV['IS_DEV'] ?? 'false') === 'true',
    'db_file' => $_ENV['DB_FILE'] ?? 'gpro_pilots.sqlite',
];

// 3. Config Loading
$container['config'] = [
    'app' => require __DIR__ . '/config/game_constants.php',
    'secrets' => file_exists(__DIR__ . '/config/secrets.php') ? require __DIR__ . '/config/secrets.php' : [],
];

$container['config']['settings'] = $container['settings'];

// 4. API Config
$container['config']['api'] = [
    'base_url' => $_ENV['GPRO_API_BASE_URL'] ?? 'https://gpro.net',
];

// 5. Database
use App\Database\Database;
use App\Database\DatabaseSeeder;

$container['db'] = Database::getConnection();

// FIXED: Removed 'tracks' argument
$seeder = new DatabaseSeeder(
    $container['db'],
    $container['config']['app']['stats_schema'],
    $container['config']['app']['divisions'],
    $container['config']['app']['default_q1_risk'],
    $container['config']['secrets']
);
$seeder->migrate();

use App\Cache\CacheFactory;

$cacheConfig = [
    'CACHE_DRIVER'   => $_ENV['CACHE_DRIVER'] ?? 'none',
    'REDIS_HOST'     => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    'REDIS_PORT'     => $_ENV['REDIS_PORT'] ?? 6379,
    'REDIS_PASSWORD' => $_ENV['REDIS_PASSWORD'] ?? null,
];

$container['service.cache'] = CacheFactory::create($cacheConfig);

// 6. Twig
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
$loader = new FilesystemLoader(__DIR__ . '/templates');
$container['twig'] = new Environment($loader, [
    'debug' => $container['settings']['is_dev'],
]);
if ($container['settings']['is_dev']) {
    $container['twig']->addExtension(new \Twig\Extension\DebugExtension());
}

// Repositories
use App\Repository\PilotRepository;
use App\Repository\DivisionMetadataRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Repository\TokenRepository;

$container['repo.pilot'] = new PilotRepository($container['db']);
$container['repo.metadata'] = new DivisionMetadataRepository($container['db']);
$container['repo.track'] = new TrackRepository($container['db']);

// Services
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

use App\Security\EmailCrypto;

$container['service.email_crypto'] = new EmailCrypto($_ENV['APP_SECRET']);

// Authorization
$container['service.user_repo'] = new \App\Repository\UserRepository(
    $container['db'],
    $container['service.email_crypto']
);
$container['service.token_repo'] = new \App\Repository\TokenRepository($container['db']);

$mailCfg = [
    'host' => $_ENV['MAIL_HOST'],
    'port' => $_ENV['MAIL_PORT'],
    'user' => $_ENV['MAIL_USER'],
    'pass' => $_ENV['MAIL_PASS'],
    'from' => $_ENV['MAIL_FROM'],
    'from_name' => $_ENV['MAIL_FROM_NAME'] ?: 'GPRO Driver Analyzer'
];

$container['service.rate_limiter'] = new \App\Service\RateLimiterService($container['service.cache']);
$container['service.email_service']  = new \App\Service\EmailService($mailCfg);
$container['service.recaptcha'] = new \App\Service\ReCaptchaService($_ENV['RECAPTCHA_SECRET_KEY'] ?? '');
$container['service.auth_service']  = new \App\Service\AuthService(
    $container['service.user_repo'],
    $container['service.token_repo'],
    $container['service.email_service'],
    $container['service.rate_limiter'],
    $container['service.recaptcha'],
    $container['service.email_crypto'],
    $_ENV['APP_SECRET'],
    (int)$_ENV['VERIFICATION_CODE_TTL_SECONDS'] ?: 600,
    (int)$_ENV['VERIFICATION_MAX_ATTEMPTS'] ?: 5
);

// API & Mapper
$container['service.api_client'] = new GproApiClient(
    $container['config']['api'],
    $container['service.cache']
);
$container['service.data_mapper'] = new GproDataMapper();

// Calculators
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
    $container['config']['secrets']['pilot_recruitment_factors'],
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

// Controllers
use App\Controller\PageController;
use App\Controller\BaselineController;
use App\Controller\TrackRiskController;
use App\Controller\RecruitmentController;
use App\Controller\CarWearController;
use App\Controller\TrainingController;
use App\Controller\StrategyController;
use App\Controller\AuthController;

$container['controller.strategy'] = new StrategyController(
    $container['service.strategy'],
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.setup_calculator'],
    $container['service.user_repo']
);

$container['controller.auth'] = new AuthController(
    $container['service.auth_service'],
    $container['service.user_repo'],
    $container['twig'],
    $_ENV['RECAPTCHA_SITE_KEY'] ?? ''
);

$container['controller.page'] = new PageController(
    $container['service.ideal_pilot'],
    $container['service.insight'],
    $container['repo.track'],
    $container['repo.metadata'],
    $container['service.training'],
    $container['service.user_repo'],
    $container['service.api_client'],
    $container['twig'],
    $container['config']
);

$container['controller.debug'] = new \App\Controller\DebugController(
    $container['service.user_repo'],
    $container['service.cache'],
    $container['twig'],
    $container['settings']['db_file']
);

$container['controller.baseline'] = new BaselineController(
    $container['repo.pilot'],
    $container['repo.metadata'],
    $container['service.calculator'],
    $container['config']['app']['stats_schema'],
    $container['settings']['is_dev']
);

$container['controller.track_risk'] = new TrackRiskController(
    $container['repo.track'],
    $container
);

$container['controller.control_panel'] = new \App\Controller\ControlPanelController(
    $container['service.user_repo'],
    $container['twig']
);

$container['controller.recruitment'] = new RecruitmentController(
    $container['service.recruitment']
);

$container['controller.car_wear'] = new CarWearController(
    $container['service.car_wear'],
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.user_repo']
);

$container['controller.training'] = new TrainingController(
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.training'],
    $container['service.user_repo']
);

use App\Controller\ApiWarmupController;

$container['controller.api_warmup'] = new ApiWarmupController(
    $container['service.api_client'],
    $container['service.user_repo']
);

return $container;
