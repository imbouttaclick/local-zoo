<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;

abstract class AbstractController
{
    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    protected function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    protected function render(string $viewName, array $data = [], int $statusCode = 200): void
    {
        $viewPath = dirname(__DIR__, 2) . '/views/' . ltrim($viewName, '/') . '.php';
        if (!is_file($viewPath)) {
            throw new RuntimeException("Шаблон представления не найден: {$viewPath}");
        }

        http_response_code($statusCode);
        extract($data, EXTR_SKIP);
        require $viewPath;
    }

    protected function json(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    protected function redirect(string $url, int $statusCode = 302): never
    {
        http_response_code($statusCode);
        header("Location: {$url}");
        exit;
    }
}
