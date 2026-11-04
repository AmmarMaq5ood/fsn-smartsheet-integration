<?php
/**
 * FSN Smartsheet integration - bootstrap.
 * Loads config, registers autoload, installs error handling that logs and alerts.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/lib/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo "config.php missing";
    exit;
}
$CONFIG = require $configFile;

Log::init($CONFIG['log'] ?? []);
Alert::init($CONFIG['alert'] ?? []);
Store::init($CONFIG['store'] ?? [], (string)($CONFIG['inbound_key'] ?? ''));
if (!Store::enabled() && Store::reason() !== 'disabled in config') {
    Log::warn('store_disabled', ['reason' => Store::reason()]);
}

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) {
        Log::warn('php_notice', ['msg' => $str, 'file' => basename($file), 'line' => $line]);
        return true;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    $ctx = ['type' => get_class($e), 'msg' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()];
    Log::error('unhandled', $ctx);
    Alert::send("FSN Smartsheet integration error: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")");
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
});

/** Read and validate the incoming webhook. Returns the decoded payload. */
function receive_webhook(array $config): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(['ok' => false, 'error' => 'POST only'], 405);
        exit;
    }
    $expected = (string)($config['inbound_key'] ?? '');
    $given = $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? ($_GET['key'] ?? '');
    if ($expected === '' || !hash_equals($expected, (string)$given)) {
        Log::warn('auth_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        Log::warn('bad_json', ['len' => strlen($raw)]);
        respond(['ok' => false, 'error' => 'invalid json'], 400);
        exit;
    }
    return $data;
}

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}
