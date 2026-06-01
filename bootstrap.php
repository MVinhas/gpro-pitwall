<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $invalidPathException) {
    // Continue if .env missing
}

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

$container['config']['api'] = [
    'base_url' => $_ENV['GPRO_API_BASE_URL'] ?? 'https://gpro.net',
];

use App\Database\Database;
use App\Database\DatabaseSeeder;
use App\Security\ApiTokenCrypto;

$container['db'] = Database::getConnection();

$container['service.api_token_crypto'] = new ApiTokenCrypto($_ENV['APP_SECRET']);

$seeder = new DatabaseSeeder(
    $container['db'],
    $container['config']['app']['stats_schema'],
    $container['config']['app']['divisions'],
    $container['config']['secrets'],
    $container['service.api_token_crypto'],
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

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
$loader = new FilesystemLoader(__DIR__ . '/templates');
$container['twig'] = new Environment($loader, [
    'debug' => $container['settings']['is_dev'],
]);
if ($container['settings']['is_dev']) {
    $container['twig']->addExtension(new \Twig\Extension\DebugExtension());
}

use App\Repository\PilotRepository;
use App\Repository\DivisionMetadataRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Repository\TokenRepository;

$container['repo.pilot'] = new PilotRepository($container['db']);
$container['repo.metadata'] = new DivisionMetadataRepository($container['db']);
$container['repo.track'] = new TrackRepository($container['db']);

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

$container['service.authorize'] = new \App\Security\Authorize($container['service.user_repo']);

$mailCfg = [
    'host' => $_ENV['MAIL_HOST'],
    'port' => $_ENV['MAIL_PORT'],
    'user' => $_ENV['MAIL_USER'],
    'pass' => $_ENV['MAIL_PASS'],
    'from' => $_ENV['MAIL_FROM'],
    'from_name' => $_ENV['MAIL_FROM_NAME'] ?: 'GPRO Assistant',
    'is_dev'   => $container['settings']['is_dev'],
    'mail_dir' => __DIR__ . '/var/mail',
];

$container['service.rate_limiter'] = new \App\Service\RateLimiterService($container['service.cache']);
$container['service.email_service']  = new \App\Service\EmailService($mailCfg);
$container['service.recaptcha'] = new \App\Service\ReCaptchaService(
    $_ENV['RECAPTCHA_SECRET_KEY'] ?? '',
    $container['settings']['is_dev'],
);



$container['service.api_client'] = new GproApiClient(
    $container['config']['api'],
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
    $container['service.email_service'],
    $container['service.rate_limiter'],
    $container['service.recaptcha'],
    $container['service.email_crypto'],
    $_ENV['APP_SECRET'],
    $container['service.gpro_sync'],
    (int)$_ENV['VERIFICATION_CODE_TTL_SECONDS'] ?: 600,
    (int)$_ENV['VERIFICATION_MAX_ATTEMPTS'] ?: 5,
    (int) ($_ENV['SYNC_MIN_INTERVAL_SECONDS'] ?? 600),
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

$container['controller.strategy'] = new StrategyController(
    $container['service.strategy'],
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.setup_calculator'],
    $container['service.authorize'],
    $container['service.race_weather'],
    $container['twig'],
);

$container['controller.auth'] = new AuthController(
    $container['service.auth_service'],
    $container['twig'],
    $_ENV['RECAPTCHA_SITE_KEY'] ?? ''
);

$container['service.pha_match'] = new \App\Service\PhaMatchService();
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

$container['controller.page'] = new PageController(
    $container['service.ideal_pilot'],
    $container['service.insight'],
    $container['repo.track'],
    $container['repo.metadata'],
    $container['service.training'],
    $container['service.user_repo'],
    $container['service.api_client'],
    $container['service.pha_match'],
    $container['service.boost_fuel'],
    $container['service.race_weather'],
    $container['service.car_wear'],
    $container['service.wear_advisor'],
    $container['service.swap_advisor'],
    $container['service.testing_projection'],
    $container['service.sponsor_advisor'],
    $container['service.testing_targets'],
    $container['service.training_advisor'],
    $container['controller.strategy'],
    $container['service.data_mapper'],
    $container['twig'],
    $container['config']
);

$container['controller.debug'] = new \App\Controller\DebugController(
    $container['service.user_repo'],
    $container['service.cache'],
    $container['twig'],
    $container['settings']['db_file'],
    $container['service.authorize'],
);

$container['controller.baseline'] = new BaselineController(
    $container['repo.pilot'],
    $container['repo.metadata'],
    $container['service.calculator'],
    $container['config']['app']['stats_schema'],
    $container['service.authorize'],
);

$container['controller.control_panel'] = new \App\Controller\ControlPanelController(
    $container['service.user_repo'],
    $container['twig'],
    $container['service.authorize'],
);

$container['controller.recruitment'] = new RecruitmentController(
    $container['service.recruitment'],
    $container['service.api_client'],
    $container['service.authorize'],
);

$container['controller.car_wear'] = new CarWearController(
    $container['service.car_wear'],
    $container['service.api_client'],
    $container['service.data_mapper'],
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
    $container['service.authorize'],
);

$container['controller.health'] = new \App\Controller\HealthController(
    $container['db'],
    $container['service.cache'],
);

return $container;
