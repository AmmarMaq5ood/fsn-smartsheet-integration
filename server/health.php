<?php
/**
 * Health check.  GET health.php?key=<inbound_key>            -> config + log status
 *                GET health.php?key=<inbound_key>&deep=1     -> also calls Smartsheet through the proxy
 *                GET health.php?key=<inbound_key>&tail=50    -> last N log lines (secrets are never logged)
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$given = $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? ($_GET['key'] ?? '');
if (!hash_equals((string)$CONFIG['inbound_key'], (string)$given)) {
    respond(['ok' => false, 'error' => 'unauthorized'], 401);
    exit;
}

$out = [
    'ok'        => true,
    'php'       => PHP_VERSION,
    'dry_run'   => (bool)($CONFIG['dry_run'] ?? true),
    'transport' => $CONFIG['smartsheet']['transport'] ?? 'proxy',
    'sheets'    => array_keys($CONFIG['sheets'] ?? []),
    'logs_dir_writable' => is_writable($CONFIG['log']['dir'] ?? (APP_ROOT . '/logs')),
    'time_utc'  => gmdate('c'),
    'store'     => [
        'enabled' => Store::enabled(),
        'reason'  => Store::enabled() ? null : Store::reason(),
        'events'  => Store::enabled() ? Store::count() : null,
        'last'    => Store::enabled() ? (Store::stats()['last']['received_at'] ?? null) : null,
        'size_kb' => Store::enabled() ? (int)round(Store::sizeBytes() / 1024) : null,
    ],
];

if (!empty($_GET['tail'])) {
    $n = max(1, min(500, (int)$_GET['tail']));
    $dir = $CONFIG['log']['dir'] ?? (APP_ROOT . '/logs');
    $files = glob($dir . '/app-*.log.php') ?: [];
    rsort($files);
    $lines = [];
    foreach ($files as $f) {
        $all = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        array_shift($all); // guard line
        $lines = array_merge($all, $lines);
        if (count($lines) >= $n) {
            break;
        }
    }
    $out['log'] = array_map(fn($l) => json_decode($l, true) ?? $l, array_slice($lines, -$n));
}

if (!empty($_GET['deep'])) {
    $ss = new Smartsheet($CONFIG['smartsheet']);
    $t0 = microtime(true);
    try {
        $checks = [];
        foreach ($CONFIG['sheets'] as $key => $cfg) {
            $id = $ss->sheetId($cfg['sheet']);
            $cols = $ss->columns($id, true);
            $missing = [];
            foreach (array_merge(array_keys($cfg['map'] ?? []), array_keys($cfg['map_on_create'] ?? [])) as $title) {
                if (!isset($cols[trim($title)])) {
                    $missing[] = $title;
                }
            }
            foreach ($cfg['find'] ?? [] as $s) {
                foreach (array_merge(array_keys($s['equals'] ?? []), $s['empty'] ?? []) as $title) {
                    if (!isset($cols[trim($title)])) {
                        $missing[] = $title;
                    }
                }
            }
            $checks[$key] = ['sheetId' => $id, 'columns' => count($cols), 'missing_columns' => array_values(array_unique($missing))];
        }
        $out['smartsheet'] = ['ok' => true, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'sheets' => $checks];
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['smartsheet'] = ['ok' => false, 'error' => $e->getMessage()];
    }
}

respond($out, $out['ok'] ? 200 : 503);
