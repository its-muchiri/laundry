<?php

require __DIR__ . '/../vendor/autoload.php';

// Minimal .env loader (swap for vlucas/phpdotenv once composer deps are allowed).
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

use Laundry\Core\Request;
use Laundry\Core\Router;

$router = new Router();
require __DIR__ . '/../routes/web.php';
require __DIR__ . '/../routes/api.php';

$router->dispatch(Request::fromGlobals());
