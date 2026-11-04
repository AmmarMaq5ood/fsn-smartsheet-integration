<?php
/**
 * Dashboard: every LP event the integration received, what it did with it, and why.
 *
 *   https://webhook.launchpadinnovations.ai/fsn-smartsheet/dashboard.php?key=<dashboard_key>
 *
 * The first visit with ?key= sets a 30-day cookie; after that the plain URL works.
 * Actions: filter / search / export CSV, open one event (payload, log lines, Smartsheet row link),
 * replay a stored event, import history from the log files, run a Smartsheet connection check.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------- auth
$dashKey = (string)($CONFIG['dashboard_key'] ?? '');
if ($dashKey === '') {
    http_response_code(500);
    exit('dashboard_key is not set in config.php');
}
$cookieToken = hash_hmac('sha256', 'fsn-dashboard-cookie', $dashKey);
$csrf        = hash_hmac('sha256', 'fsn-dashboard-csrf', $dashKey);
$self        = basename(__FILE__);
$cookiePath  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
$cookieOpts  = ['path' => $cookiePath, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];

$givenKey = $_POST['key'] ?? $_GET['key'] ?? null;
$loginFailed = false;
if ($givenKey !== null) {
    if (hash_equals($dashKey, (string)$givenKey)) {
        setcookie('fsn_dash', $cookieToken, ['expires' => time() + 30 * 86400] + $cookieOpts);
        header('Location: ' . $self);
        exit;
    }
    $loginFailed = true;
}
if (isset($_GET['logout'])) {
    setcookie('fsn_dash', '', ['expires' => time() - 3600] + $cookieOpts);
    header('Location: ' . $self);
    exit;
}
if (!hash_equals($cookieToken, (string)($_COOKIE['fsn_dash'] ?? ''))) {
    page_start('Sign in');
    echo '<div class="card login"><h2>FSN integration dashboard</h2>';
    if ($loginFailed) {
        echo '<p class="b err">Wrong key</p>';
    }
    echo '<form method="post"><input type="password" name="key" placeholder="dashboard key" autofocus> <button>Sign in</button></form></div>';
    page_end();
    exit;
}

// ---------------------------------------------------------------- actions (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['tok'] ?? ''))) {
        http_response_code(400);
        exit('bad token');
    }
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'replay') {
        $ev = Store::event((int)($_POST['id'] ?? 0));
        if (!$ev) {
            redirect($self . '?msg=' . rawurlencode('Event not found'));
        }
        $payload = json_decode((string)$ev['payload'], true);
        try {
            $r = Pipeline::run($CONFIG, (string)$ev['sheet'], is_array($payload) ? $payload : [], 'replay', (int)$ev['id']);
            redirect($self . '?id=' . (int)($r['eventId'] ?? 0) . '&msg=' . rawurlencode("Replayed #{$ev['id']}: " . ($r['action'] ?? '?') . ($r['rowId'] ? " row {$r['rowId']}" : '')));
        } catch (Throwable $e) {
            $new = Store::latestReplayOf((int)$ev['id']) ?? (int)$ev['id'];
            redirect($self . '?id=' . $new . '&msg=' . rawurlencode('Replay failed: ' . $e->getMessage()));
        }
    }
    if ($act === 'import') {
        $r = Store::backfill($CONFIG['log']['dir'] ?? (APP_ROOT . '/logs'));
        redirect($self . '?msg=' . rawurlencode(sprintf(
            'Imported %d events from %d log files (%d already present)%s',
            $r['imported'],
            $r['files'],
            $r['existing'],
            isset($r['error']) ? '. Error: ' . $r['error'] : ''
        )));
    }
    redirect($self);
}

// ---------------------------------------------------------------- filters
$filters = [];
foreach (['sheet', 'status', 'action', 'source', 'q', 'from', 'to', 'contact_id'] as $k) {
    $filters[$k] = trim((string)($_GET[$k] ?? ''));
}
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

// ---------------------------------------------------------------- CSV export
if (isset($_GET['export'])) {
    $rows = Store::events($filters, 5000, 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fsn-events-' . gmdate('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['id', 'received_utc', 'sheet', 'source', 'contact', 'email', 'school_or_company', 'document', 'document_type', 'document_url', 'opportunity_id', 'contact_id', 'result', 'reason', 'row_id', 'matched_by', 'error', 'trigger', 'duration_ms']);
    foreach ($rows as $r) {
        fputcsv($o, [
            $r['id'], $r['received_at'], $r['sheet'], $r['source'], $r['contact_name'], $r['email'], $r['company'],
            $r['doc_name'], doc_type($r['doc_name']), $r['doc_url'], $r['opp_id'], $r['contact_id'],
            $r['status'] === 'ok' ? $r['action'] : $r['status'], $r['reason'], $r['row_id'], $r['matched_by'], $r['error'], $r['trigger_name'], $r['duration_ms'],
        ]);
    }
    exit;
}

// ---------------------------------------------------------------- detail view
if (!empty($_GET['id'])) {
    $ev = Store::event((int)$_GET['id']);
    if (!$ev) {
        http_response_code(404);
        page_start('Not found');
        echo '<div class="card"><p>Event not found.</p><p><a href="' . h($self) . '">Back</a></p></div>';
        page_end();
        exit;
    }
    $logs = Store::logsFor((string)$ev['rid']);
    $replays = Store::replaysOf((int)$ev['id']);
    $rowUrl = row_url($CONFIG, $ev);
    $payload = json_decode((string)$ev['payload'], true);
    page_start('Event #' . $ev['id']);
    banner();
    echo '<p class="crumb"><a href="' . h($self . qs(['id' => null])) . '">&larr; All events</a></p>';
    echo '<div class="card"><div class="row-head"><div>';
    echo '<h2>#' . (int)$ev['id'] . ' <span class="b sheet">' . h($ev['sheet']) . '</span> ' . h($ev['contact_name'] ?: '(no name)');
    if ($ev['company']) {
        echo ' <span class="muted">&middot; ' . h($ev['company']) . '</span>';
    }
    echo '</h2>';
    echo '<p class="muted">' . h(t($ev['received_at'])) . ' UTC &middot; source: ' . h($ev['source']);
    if ($ev['replay_of']) {
        echo ' &middot; replay of <a href="' . h($self . '?id=' . (int)$ev['replay_of']) . '">#' . (int)$ev['replay_of'] . '</a>';
    }
    echo ' &middot; request ' . h($ev['rid']) . '</p></div>';
    echo '<div class="row-actions">' . result_badge($ev);
    if ($rowUrl) {
        echo ' <a class="btn" target="_blank" rel="noopener" href="' . h($rowUrl) . '">Open Smartsheet row</a>';
    }
    echo ' <form method="post" class="inline" onsubmit="return confirm(\'Replay this event against Smartsheet now?\')">'
        . '<input type="hidden" name="tok" value="' . h($csrf) . '"><input type="hidden" name="action" value="replay"><input type="hidden" name="id" value="' . (int)$ev['id'] . '">'
        . '<button class="btn primary">Replay</button></form>';
    echo '</div></div>';

    if ($ev['error']) {
        echo '<pre class="errbox">' . h($ev['error']) . '</pre>';
    }

    $fields = [
        'Document'       => ($ev['doc_name'] ? '<span class="tag">' . h(doc_type($ev['doc_name'])) . '</span> ' . h($ev['doc_name']) : '<span class="muted">none</span>'),
        'Document link'  => $ev['doc_url'] ? '<a target="_blank" rel="noopener" href="' . h($ev['doc_url']) . '">' . h($ev['doc_url']) . '</a>' : '',
        'Trigger'        => h($ev['trigger_name']),
        'Email'          => h($ev['email']),
        'LP contact id'  => $ev['contact_id'] ? '<a href="' . h($self . '?q=' . rawurlencode($ev['contact_id'])) . '">' . h($ev['contact_id']) . '</a>' : '',
        'LP opportunity' => h($ev['opp_id']),
        'Result'         => h($ev['status'] === 'ok' ? ($ev['action'] ?? '') : $ev['status']) . ($ev['reason'] ? ' <span class="muted">(' . h($ev['reason']) . ')</span>' : ''),
        'Matched by'     => h($ev['matched_by']),
        'Smartsheet row' => $ev['row_id'] ? h((string)$ev['row_id']) : '',
        'Cells written'  => $ev['cells'] !== null ? h((string)$ev['cells']) : '',
        'Duration'       => $ev['duration_ms'] !== null ? h((string)$ev['duration_ms']) . ' ms' : '',
    ];
    echo '<table class="kv">';
    foreach ($fields as $k => $v) {
        if ($v !== '') {
            echo '<tr><th>' . h($k) . '</th><td>' . $v . '</td></tr>';
        }
    }
    echo '</table></div>';

    if ($replays) {
        echo '<div class="card"><h3>Replays of this event</h3><table class="list">';
        foreach ($replays as $r) {
            echo '<tr><td><a href="' . h($self . '?id=' . (int)$r['id']) . '">#' . (int)$r['id'] . '</a></td><td>' . h(t($r['received_at'])) . '</td><td>' . result_badge($r) . '</td><td>' . h((string)($r['row_id'] ?? '')) . '</td><td class="muted">' . h((string)($r['error'] ?? '')) . '</td></tr>';
        }
        echo '</table></div>';
    }

    echo '<div class="card"><h3>What happened (' . count($logs) . ' log lines)</h3><table class="list logs">';
    foreach ($logs as $l) {
        $ctx = json_decode((string)$l['ctx'], true);
        if (is_array($ctx)) {
            unset($ctx['sheet']);
        }
        echo '<tr class="lvl-' . h($l['level']) . '"><td class="nowrap">' . h(substr(str_replace('T', ' ', (string)$l['t']), 11, 8)) . '</td><td class="nowrap"><b>' . h($l['event']) . '</b></td><td><code>' . h(is_array($ctx) ? json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$l['ctx']) . '</code></td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><details><summary>Full payload from LP</summary><pre>' . h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string)$ev['payload']) . '</pre></details></div>';
    page_end();
    exit;
}

// ---------------------------------------------------------------- list view
$check = null;
if (isset($_GET['check'])) {
    $t0 = microtime(true);
    try {
        $ss = new Smartsheet($CONFIG['smartsheet']);
        $info = [];
        foreach ($CONFIG['sheets'] as $k => $cfg) {
            $id = $ss->sheetId($cfg['sheet']);
            $info[] = $k . ': ' . count($ss->columns($id, true)) . ' columns';
        }
        $check = ['ok' => true, 'text' => 'Smartsheet reachable via ' . ($CONFIG['smartsheet']['transport'] ?? 'proxy') . ' in ' . (int)round((microtime(true) - $t0) * 1000) . ' ms. ' . implode('; ', $info)];
    } catch (Throwable $e) {
        $check = ['ok' => false, 'text' => 'Smartsheet check failed: ' . $e->getMessage()];
    }
}

$total = Store::count($filters);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$rows = Store::events($filters, $perPage, ($page - 1) * $perPage);
$stats = Store::stats();

page_start('Events');
banner();
if ($check) {
    echo '<p class="b ' . ($check['ok'] ? 'ok' : 'err') . ' wide">' . h($check['text']) . '</p>';
}
if (!Store::enabled()) {
    echo '<p class="b err wide">Event store is off: ' . h(Store::reason()) . '. Webhooks still work; nothing is recorded here.</p>';
}
if (!empty($CONFIG['dry_run'])) {
    echo '<p class="b warn wide">DRY RUN is on: events are evaluated but nothing is written to Smartsheet.</p>';
}

// stats strip
echo '<div class="stats">';
foreach (['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $k => $label) {
    $s = $stats[$k] ?? null;
    if (!$s) {
        continue;
    }
    echo '<div class="stat"><div class="label">' . h($label) . '</div><div class="big">' . (int)$s['total'] . '</div>'
        . '<div class="small"><span class="ok">' . (int)$s['create'] . ' created</span> &middot; <span class="info">' . (int)$s['update'] . ' updated</span> &middot; <span class="warn">' . (int)$s['skipped'] . ' skipped</span> &middot; <span class="err">' . (int)$s['error'] . ' errors</span></div></div>';
}
$last = $stats['last'] ?? null;
echo '<div class="stat"><div class="label">Last event</div><div class="big">' . ($last ? h(t($last['received_at'])) : '-') . '</div><div class="small">' . ($last ? h($last['sheet'] . ' · ' . ($last['contact_name'] ?? '') . ' · ' . ($last['status'] === 'ok' ? ($last['action'] ?? '') : $last['status'])) : 'nothing stored yet') . '</div></div>';
echo '<div class="stat"><div class="label">Store</div><div class="big">' . (int)($stats['total'] ?? 0) . '</div><div class="small">events · ' . h(number_format(($stats['size'] ?? 0) / 1024)) . ' KB · ' . h($CONFIG['smartsheet']['transport'] ?? 'proxy') . ' transport</div></div>';
echo '</div>';

// toolbar
echo '<div class="toolbar"><form method="get" class="filters">';
echo sel('sheet', ['' => 'All sheets'] + array_combine(array_keys($CONFIG['sheets']), array_keys($CONFIG['sheets'])), $filters['sheet']);
echo sel('status', ['' => 'Any result', 'ok' => 'OK', 'error' => 'Error', 'received' => 'In progress'], $filters['status']);
echo sel('action', ['' => 'Any action', 'create' => 'Created', 'update' => 'Updated', 'skipped' => 'Skipped'], $filters['action']);
echo sel('source', ['' => 'Any source', 'webhook' => 'Webhook', 'replay' => 'Replay', 'import' => 'Imported'], $filters['source']);
echo '<input type="search" name="q" value="' . h($filters['q']) . '" placeholder="name, school, document, email, id, row">';
echo '<input type="date" name="from" value="' . h($filters['from']) . '" title="from (UTC)"> <input type="date" name="to" value="' . h($filters['to']) . '" title="to (UTC)">';
echo '<button class="btn primary">Filter</button> <a class="btn" href="' . h($self) . '">Reset</a>';
echo '</form><div class="tools">';
echo '<a class="btn" href="' . h($self . qs(['export' => 1, 'page' => null])) . '">Export CSV</a> ';
echo '<a class="btn" href="' . h($self . qs(['check' => 1])) . '">Check Smartsheet</a> ';
echo '<form method="post" class="inline" onsubmit="return confirm(\'Import events from the log files? Already-imported requests are skipped.\')"><input type="hidden" name="tok" value="' . h($csrf) . '"><input type="hidden" name="action" value="import"><button class="btn">Import log history</button></form> ';
echo '<a class="btn" href="' . h($self . '?logout=1') . '">Sign out</a>';
echo '</div></div>';

// table
echo '<div class="card"><p class="muted">' . ($total ? 'Showing ' . (($page - 1) * $perPage + 1) . '–' . min($total, $page * $perPage) . ' of ' . $total : 'No events match') . ' · times are UTC</p>';
echo '<table class="list events"><thead><tr><th>Received</th><th>Sheet</th><th>Contact</th><th>School / company</th><th>Document</th><th>Result</th><th>Row</th><th></th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td class="nowrap">' . h(t($r['received_at'])) . ($r['source'] !== 'webhook' ? ' <span class="tag muted">' . h($r['source']) . '</span>' : '') . '</td>';
    echo '<td><span class="b sheet">' . h($r['sheet']) . '</span></td>';
    echo '<td>' . h($r['contact_name'] ?: '(no name)') . ($r['email'] ? '<div class="small muted">' . h($r['email']) . '</div>' : '') . '</td>';
    echo '<td>' . h($r['company']) . '</td>';
    echo '<td>' . ($r['doc_name'] ? '<span class="tag">' . h(doc_type($r['doc_name'])) . '</span> ' . h(mb_strimwidth((string)$r['doc_name'], 0, 60, '…')) : '<span class="muted">none</span>') . '</td>';
    echo '<td>' . result_badge($r) . ($r['status'] === 'error' ? '<div class="small muted">' . h(mb_strimwidth((string)$r['error'], 0, 90, '…')) . '</div>' : '') . '</td>';
    echo '<td class="nowrap">' . h((string)($r['row_id'] ?? '')) . ($r['matched_by'] ? '<div class="small muted">by ' . h($r['matched_by']) . '</div>' : '') . '</td>';
    echo '<td class="nowrap"><a href="' . h($self . '?id=' . (int)$r['id']) . '">View</a></td>';
    echo '</tr>';
}
echo '</tbody></table>';
if ($pages > 1) {
    echo '<p class="pager">';
    if ($page > 1) {
        echo '<a class="btn" href="' . h($self . qs(['page' => $page - 1])) . '">&larr; Newer</a> ';
    }
    echo '<span class="muted">page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        echo ' <a class="btn" href="' . h($self . qs(['page' => $page + 1])) . '">Older &rarr;</a>';
    }
    echo '</p>';
}
echo '</div>';
page_end();

// ---------------------------------------------------------------- helpers
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t(?string $iso): string
{
    return $iso ? substr(str_replace('T', ' ', $iso), 0, 16) : '';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function qs(array $over = []): string
{
    $q = array_merge($_GET, $over);
    unset($q['key'], $q['msg'], $q['logout']);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
}

function sel(string $name, array $options, string $current): string
{
    $out = '<select name="' . h($name) . '">';
    foreach ($options as $v => $label) {
        $out .= '<option value="' . h((string)$v) . '"' . ((string)$v === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out . '</select>';
}

function doc_type(?string $name): string
{
    global $CONFIG;
    $n = mb_strtolower((string)$name);
    if ($n === '') {
        return '';
    }
    if (str_contains($n, 'work order')) {
        return 'WO';
    }
    foreach ($CONFIG['staff_keywords'] ?? [] as $k) {
        if (str_contains($n, mb_strtolower($k))) {
            return 'Staff';
        }
    }
    return 'SOW';
}

function result_badge(array $e): string
{
    $age = time() - (strtotime((string)$e['received_at']) ?: time());
    return match (true) {
        $e['status'] === 'error'    => '<span class="b err">Error</span>',
        $e['status'] === 'received' => $age > 300 ? '<span class="b err">Stalled</span>' : '<span class="b muted">In progress</span>',
        $e['action'] === 'create'   => '<span class="b ok">Created</span>',
        $e['action'] === 'update'   => '<span class="b info">Updated</span>',
        default                     => '<span class="b warn">Skipped</span>' . (!empty($e['reason']) ? ' <span class="small muted">' . h($e['reason']) . '</span>' : ''),
    };
}

/** Smartsheet deep link to the row, when the sheet permalink can be resolved. */
function row_url(array $config, array $ev): ?string
{
    if (empty($ev['row_id']) || empty($config['sheets'][$ev['sheet']]['sheet'])) {
        return null;
    }
    try {
        $ss = new Smartsheet($config['smartsheet']);
        $link = $ss->permalink($ss->sheetId($config['sheets'][$ev['sheet']]['sheet']));
        return $link ? $link . '?rowId=' . (int)$ev['row_id'] : null;
    } catch (Throwable) {
        return null;
    }
}

function banner(): void
{
    if (!empty($_GET['msg'])) {
        echo '<p class="b info wide">' . h((string)$_GET['msg']) . '</p>';
    }
}

function page_start(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · FSN integration</title><style>'
        . ':root{--bg:#f3f4f6;--card:#fff;--ink:#111827;--muted:#6b7280;--line:#e5e7eb;--ok:#15803d;--okbg:#dcfce7;--warn:#b45309;--warnbg:#fef3c7;--err:#b91c1c;--errbg:#fee2e2;--info:#1d4ed8;--infobg:#dbeafe;--accent:#2563eb}'
        . '*{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink)}'
        . 'header{background:#111827;color:#fff;padding:12px 20px;display:flex;align-items:center;gap:14px}header h1{font-size:16px;margin:0;font-weight:600}header a{color:#9ca3af;text-decoration:none}'
        . 'main{max-width:1400px;margin:0 auto;padding:16px}.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px 16px;margin-bottom:14px}'
        . '.login{max-width:380px;margin:60px auto}.login input{width:100%;padding:8px;margin:8px 0;border:1px solid var(--line);border-radius:6px}'
        . '.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:14px}.stat{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:12px 14px}.stat .label{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em}.stat .big{font-size:24px;font-weight:600;margin:2px 0}.stat .small{font-size:12px}'
        . '.ok{color:var(--ok)}.info{color:var(--info)}.warn{color:var(--warn)}.err{color:var(--err)}.muted{color:var(--muted)}.small{font-size:12px}.nowrap{white-space:nowrap}'
        . '.b{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}.b.ok{background:var(--okbg);color:var(--ok)}.b.info{background:var(--infobg);color:var(--info)}.b.warn{background:var(--warnbg);color:var(--warn)}.b.err{background:var(--errbg);color:var(--err)}.b.muted{background:#e5e7eb;color:#374151}.b.sheet{background:#ede9fe;color:#5b21b6}.b.wide{display:block;padding:10px 14px;border-radius:8px;margin-bottom:14px;white-space:normal}'
        . '.tag{display:inline-block;font-size:11px;font-weight:600;padding:1px 6px;border:1px solid var(--line);border-radius:4px;color:#374151;background:#f9fafb}'
        . '.toolbar{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center;margin-bottom:14px}.filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.filters input,.filters select{padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:#fff;font:inherit}.filters input[type=search]{min-width:260px}.tools{display:flex;flex-wrap:wrap;gap:6px;align-items:center}'
        . '.btn{display:inline-block;padding:6px 12px;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--ink);text-decoration:none;font:inherit;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}form.inline{display:inline}'
        . 'table.list{width:100%;border-collapse:collapse}table.list th,table.list td{text-align:left;padding:8px 8px;border-top:1px solid var(--line);vertical-align:top}table.list thead th{border-top:0;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em}table.events tbody tr:hover{background:#f9fafb}'
        . 'table.kv{border-collapse:collapse;margin-top:10px}table.kv th{text-align:left;color:var(--muted);font-weight:500;padding:4px 16px 4px 0;white-space:nowrap;vertical-align:top}table.kv td{padding:4px 0;word-break:break-all}'
        . 'table.logs code{font-size:12px;white-space:pre-wrap;word-break:break-all}tr.lvl-error td{background:var(--errbg)}tr.lvl-warn td{background:var(--warnbg)}'
        . '.row-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start}.row-head h2{margin:0 0 4px;font-size:18px}.row-head p{margin:0}.row-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
        . '.errbox{background:var(--errbg);color:var(--err);padding:10px;border-radius:6px;white-space:pre-wrap;word-break:break-all}pre{background:#f9fafb;border:1px solid var(--line);border-radius:6px;padding:10px;overflow:auto;font-size:12px}summary{cursor:pointer;font-weight:600}.crumb{margin:0 0 10px}.pager{margin:12px 0 0}'
        . '@media (max-width:700px){table.events thead{display:none}table.events td{display:block;border-top:0;padding:3px 8px}table.events tr{display:block;border-top:1px solid var(--line);padding:6px 0}}'
        . '</style></head><body><header><h1>FSN · LP → Smartsheet</h1><a href="' . h(basename(__FILE__)) . '">Events</a></header><main>';
}

function page_end(): void
{
    echo '</main></body></html>';
}
