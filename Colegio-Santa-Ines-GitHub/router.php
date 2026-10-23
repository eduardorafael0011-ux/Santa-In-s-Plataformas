<?php
declare(strict_types=1);

use SantaInes\Http\ApiRouter;
use SantaInes\Http\Http;

// Configuração do servidor embutido para desenvolvimento local.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('post_max_size', '110M');
ini_set('memory_limit', '256M');

require_once __DIR__ . '/php/src/Http.php';
require_once __DIR__ . '/php/src/DataStore.php';
require_once __DIR__ . '/php/src/SchoolRules.php';
require_once __DIR__ . '/php/src/SpreadsheetReader.php';
require_once __DIR__ . '/php/src/AdminAuth.php';
require_once __DIR__ . '/php/src/TeacherAuth.php';
require_once __DIR__ . '/php/src/RosterService.php';
require_once __DIR__ . '/php/src/ContentService.php';
require_once __DIR__ . '/php/src/ActivityService.php';
require_once __DIR__ . '/php/src/ApiRouter.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    ApiRouter::dispatch();
}
if ($path === '/' || $path === '/index.php' || $path === '/index.html') {
    require __DIR__ . '/index.php';
    exit;
}

// O servidor embutido só pode publicar a página e o logotipo; código e dados privados nunca são arquivos estáticos.
$publicFiles = [
    '/assets/colegio-santa-ines.png' => __DIR__ . '/assets/colegio-santa-ines.png',
    '/assets/site.css' => __DIR__ . '/assets/site.css',
    '/assets/app.js' => __DIR__ . '/assets/app.js',
];
if (isset($publicFiles[$path]) && is_file($publicFiles[$path])) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Não encontrado';
