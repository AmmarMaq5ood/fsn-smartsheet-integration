<?php
/**
 * LP webhook entry point.
 *   POST https://webhook.launchpadinnovations.ai/fsn-smartsheet/lp.php?sheet=schools
 *   POST https://webhook.launchpadinnovations.ai/fsn-smartsheet/lp.php?sheet=educators
 * Header: x-webhook-key: <inbound_key>
 *
 * Everything that happens to the event is in Pipeline::run; this file only validates the request.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$data = receive_webhook($CONFIG);
$sheetKey = (string)($_GET['sheet'] ?? '');
if (!isset($CONFIG['sheets'][$sheetKey])) {
    respond(['ok' => false, 'error' => "unknown sheet '$sheetKey'"], 400);
    exit;
}

$result = Pipeline::run($CONFIG, $sheetKey, $data);

respond(['ok' => true, 'rid' => Log::requestId()] + $result);
