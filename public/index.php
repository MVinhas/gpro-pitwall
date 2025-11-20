<?php
use App\Http\Request;

$container = require_once __DIR__ . '/../bootstrap.php';
$request = Request::createFromGlobals();

if ($request->getMethod() === 'GET') {
    $container['controller.page']->index($request);
} 
elseif ($request->getMethod() === 'POST') {
    $action = $request->post('action');

    switch ($action) {
        case 'add_pilot':
        case 'update_season':
        case 'undo_last_pilot':
        case 'clear_stats':
            $container['controller.baseline']->handle($request);
            break;

        case 'update_track_risks':
            $container['controller.track_risk']->update($request);
            break;

        case 'analyze_recruitment':
            $container['controller.recruitment']->analyze($request);
            break;
        
        case 'import_driver':
        case 'calculate_training':
            $container['controller.training']->handle($request);
            break;
            
        case 'calculate_wear':
            $container['controller.car_wear']->handle($request);
            break;
            
        default:
            die("Unknown action: " . htmlspecialchars($action));
    }
}