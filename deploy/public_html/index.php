<?php

/**
 * Точка входу для хостингу, де document root змінити не можна.
 *
 * Цей файл кладеться в ~/public_html/, а сам проєкт лежить поруч,
 * у ~/school-food/ — тобто поза зоною досяжності вебсервера.
 *
 * Якщо папку проєкту назвали інакше — виправте один рядок нижче.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/** Шлях до проєкту відносно цього файлу. */
$app_root = __DIR__.'/../school-food';

if (! file_exists($app_root.'/vendor/autoload.php')) {
    http_response_code(500);
    exit('Не знайдено папку проєкту. Перевірте шлях $app_root у public_html/index.php');
}

// Режим обслуговування...
if (file_exists($maintenance = $app_root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_root.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_root.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
