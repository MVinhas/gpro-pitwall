<?php
// gpro-driver-analyzer/bootstrap.php
session_start();

require_once __DIR__ . '/vendor/autoload.php';

// 1. Load Env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    die("Error: Could not find .env file.");
}

// 2. Container Setup
$container = [];
$container['settings'] = [
    'is_dev' => ($_ENV['IS_DEV'] ?? 'false') === 'true',
    'db_file' => $_ENV['DB_FILE'] ?? 'gpro_pilots.sqlite',
    'write_disabled_message' => $_ENV['WRITE_DISABLED_MESSAGE'] ?? 'Writes are disabled.'
];

// 3. Config Loading
$container['config'] = [
    'app' => require __DIR__ . '/config/app_config.php',
    'factors' => require __DIR__ . '/config/factors.php',
    'recruitment' => require __DIR__ . '/config/recruitment.php',
];

// **FIX 4: Merge settings into config so PageController works**
$container['config']['settings'] = $container['settings'];

// 4. Database & Seeder
use App\Database\Database;
use App\Database\DatabaseSeeder;

$container['db'] = Database::getConnection();
$seeder = new DatabaseSeeder(
    $container['db'],
    $container['config']['app']['stats_schema'],
    $container['config']['app']['divisions'],
    $container['config']['app']['tracks'], // This will now pass the array of ID=>Name
    $container['config']['app']['default_q1_risk']
);
$seeder->migrate();

// 5. Twig
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

$container['repo.pilot'] = new PilotRepository($container['db']);
$container['repo.metadata'] = new DivisionMetadataRepository($container['db']);
$container['repo.track'] = new TrackRepository($container['db']);

// Services
use App\Service\PilotCalculatorService;
use App\Service\IdealPilotService;
use App\Service\InsightService;
use App\Service\RecruitmentService;

$container['service.calculator'] = new PilotCalculatorService(
    $container['config']['factors']['pilot_factors'],
    $container['config']['factors']['division_caps']
);

$container['service.ideal_pilot'] = new IdealPilotService(
    $container['repo.pilot'],
    $container['service.calculator'],
    $container['config']['app']['stats_schema']
);

$container['service.insight'] = new InsightService($container['config']['app']['divisions']);

// **FIX 1 & 3: Updated Injection for RecruitmentService**
// Removed constraints, Added pilot_factors
$container['service.recruitment'] = new RecruitmentService(
    $container['config']['factors']['pilot_recruitment_factors'],
    $container['config']['recruitment']['csv_to_schema_map'],
    $container['config']['factors']['division_caps'],
    $container['service.ideal_pilot']
);

// --- NEW: Formula & Utility Services ---

use App\Service\SetupCalculatorService;
use App\Service\TrainingService;

// Setup Calculator (The "Magic Sauce")
$container['service.setup_calculator'] = new SetupCalculatorService($container['db']);

// Training Service (The "Planner")
$container['service.training'] = new TrainingService($container['db']);

// 6. API Config
$container['config']['api'] = [
    'base_url' => $_ENV['GPRO_API_BASE_URL'] ?? 'https://gpro.net',
    'token' => $_ENV['GPRO_API_TOKEN'] ?? '',
];

// Controllers
use App\Controller\PageController;
use App\Controller\BaselineController;
use App\Controller\TrackRiskController;
use App\Controller\RecruitmentController;

$container['controller.page'] = new PageController(
    $container['service.ideal_pilot'],
    $container['service.insight'],
    $container['repo.track'],
    $container['repo.metadata'],
    $container['service.training'],
    $container['twig'],
    $container['config'] // Now contains ['settings']
);

$container['controller.baseline'] = new BaselineController(
    $container['repo.pilot'],
    $container['repo.metadata'],
    $container['service.calculator'],
    $container
);

$container['controller.track_risk'] = new TrackRiskController(
    $container['repo.track'],
    $container
);

$container['controller.recruitment'] = new RecruitmentController(
    $container['service.recruitment'],
    $container['controller.page']
);

use App\Service\GproApiClient;
$container['service.api_client'] = new GproApiClient($container['config']['api']);

use App\Service\GproDataMapper;
$container['service.data_mapper'] = new GproDataMapper();

use App\Controller\TrainingController;
$container['controller.training'] = new TrainingController(
    $container['service.api_client'],
    $container['service.data_mapper'],
    $container['service.training']
);

use App\Service\CarWearService;
use App\Service\StrategyService;

$container['service.car_wear'] = new CarWearService($container['db']);
$container['service.strategy'] = new StrategyService($container['db']);

use App\Controller\CarWearController;
$container['controller.car_wear'] = new CarWearController(
    $container['service.car_wear'],
    $container['service.api_client'],
    $container['service.data_mapper']
);

return $container;