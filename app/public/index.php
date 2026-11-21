<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\BoardController;
use App\Database;
use App\Log;
use App\Routing\Router;
use App\Session\DatabaseSessionHandler;

// 1. Буферизация вывода и логирование каждого HTTP-запроса + ответа через Monolog
ob_start();
$rawRequestBody = file_get_contents('php://input') ?: '';

register_shutdown_function(function () use ($rawRequestBody): void {
    $responseBody = '';
    if (ob_get_level() > 0) {
        $responseBody = (string) ob_get_contents();
        ob_end_flush();
    }

    Log::info('HTTP request handled', [
        'method'        => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri'           => $_SERVER['REQUEST_URI'] ?? '/',
        'query'         => $_GET,
        'post'          => $_POST,
        'request_body'  => $rawRequestBody,
        'status_code'   => http_response_code(),
        'response_body' => $responseBody,
    ]);
});

// 2. Инициализация Eloquent ORM и кастомного обработчика сессий в PostgreSQL
Database::boot();
session_set_save_handler(new DatabaseSessionHandler(3600), true);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3. Регистрация маршрутов и запуск роутера
$router = new Router();
$router
    ->get('/', [BoardController::class, 'index'])
    ->post('/cards/create', [BoardController::class, 'createCard'])
    ->post('/cards/move', [BoardController::class, 'moveCard']);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/'
);
