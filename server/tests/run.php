<?php
/**
 * Offline tests. Run:  php tests/run.php
 * Uses sanitised LP payloads from tests/fixtures (real structure, fake people) and a fake Smartsheet.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(function (string $c): void {
    $f = APP_ROOT . '/lib/' . $c . '.php';
    if (is_file($f)) {
        require $f;
    }
});
Log::init(['dir' => sys_get_temp_dir() . '/fsn-test-logs']);
Alert::init([]);

$CONFIG = require APP_ROOT . '/config.sample.php';

// ---------------------------------------------------------------- fake Smartsheet
final class FakeSmartsheet extends Smartsheet
{
    public array $calls = [];
    public array $rows;      // rowId => [title => value]
    private array $cols;     // title => id
    private int $nextRow = 9000;

    public function __construct(array $columns, array $rows)
    {
        $tmp = sys_get_temp_dir() . '/fsn-test-cache-' . getmypid();
        parent::__construct(['transport' => 'proxy', 'proxy' => ['url' => 'x', 'secret' => 'x'], 'column_cache_ttl' => 0, 'sheet_cache_ttl' => 0, 'cache_dir' => $tmp]);
        $this->cols = [];
        $i = 1000;
        foreach ($columns as $c) {
            $this->cols[$c] = $i++;
        }
        $this->rows = $rows;
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $this->calls[] = "$method $path";
        if ($method === 'GET' && str_ends_with($path, '/columns')) {
            $data = [];
            foreach ($this->cols as $title => $id) {
                $c = ['id' => $id, 'title' => $title, 'type' => 'TEXT_NUMBER'];
                if ($title === 'SED') {
                    $c['formula'] = '=1';
                }
                if ($title === 'ID') {
                    $c['systemColumnType'] = 'AUTO_NUMBER';
                }
                $data[] = $c;
            }
            return ['data' => $data];
        }
        if ($method === 'GET' && $path === '/sheets') {
            return ['data' => [['id' => 111, 'name' => 'Educator SOWs'], ['id' => 6023663445823364, 'name' => '1. LEARN | Program Launch Tracker']]];
        }
        if ($method === 'GET' && preg_match('#^/sheets/\d+$#', $path)) {
            $ids = array_map('intval', explode(',', $query['columnIds'] ?? ''));
            $rows = [];
            foreach ($this->rows as $rid => $vals) {
                $cells = [];
                foreach ($this->cols as $title => $id) {
                    if (in_array($id, $ids, true) && isset($vals[$title]) && $vals[$title] !== '') {
                        $cells[] = ['columnId' => $id, 'value' => $vals[$title]];
                    }
                }
                $rows[] = ['id' => $rid, 'cells' => $cells];
            }
            return ['rows' => $rows];
        }
        if ($method === 'POST' && str_ends_with($path, '/rows')) {
            $rid = $this->nextRow++;
            $this->rows[$rid] = $this->cellsToTitles($body[0]['cells']);
            return ['result' => [['id' => $rid]]];
        }
        if ($method === 'PUT' && str_ends_with($path, '/rows')) {
            $rid = (int)$body[0]['id'];
            $this->rows[$rid] = array_merge($this->rows[$rid] ?? [], $this->cellsToTitles($body[0]['cells']));
            return ['result' => [['id' => $rid]]];
        }
        throw new RuntimeException("fake: unhandled $method $path");
    }

    private function cellsToTitles(array $cells): array
    {
        $byId = array_flip($this->cols);
        $out = [];
        foreach ($cells as $c) {
            $out[$byId[(int)$c['columnId']]] = $c['value'];
        }
        return $out;
    }
}

// ---------------------------------------------------------------- helpers
$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $name\n";
    } else {
        $fail++;
        echo "  FAIL $name $detail\n";
    }
}
function payload(string $file): Payload
{
    $d = json_decode((string)file_get_contents(APP_ROOT . "/tests/fixtures/$file.json"), true);
    return new Payload($d);
}

$schoolCols = array_merge(
    ['ID', 'SED', 'SCHOOL NAME', 'Program ID', 'SED Code (Look up in NYS Public Schools List )'],
    array_keys($CONFIG['sheets']['schools']['map'])
);
$ylim     = payload('school_signer');   // school signer without a school name field (dup record)
$sandra   = payload('staff_sent');      // staff, Doc Sent
$anast    = payload('staff_signed');    // staff, Doc Signed

echo "Payload rules\n";
check('fullName', $sandra->rule('fullName') === 'Sally Staffer', (string)$sandra->rule('fullName'));
check('sowUrl from customData', str_starts_with((string)$sandra->rule('sowUrl'), 'https://go.foodstreamnetwork.com/'));
check('schoolName falls back to Organization Name', $ylim->schoolName() === 'P12X, Lewis and Clark School', (string)$ylim->schoolName());
check('schoolName uses Business Name', $sandra->schoolName() === 'FoodStream Network', (string)$sandra->schoolName());
check('quoted path', $sandra->get('customData."LP Contact Source"') === 'Sustainability Impact Report 2026');
check('interpolation null when all empty', $sandra->resolve('{{customData."LP City"}}') === null);
check('interpolation mixed', $sandra->resolve('{{first_name}} / {{customData."LP City"}}') === 'Sally /');
$withDoc = new Payload(array_merge($sandra->raw(), ['SOW Document Name' => 'Assistant Educator Services SOW - Sally']));
check('staff gate by doc name', $withDoc->isStaffDocument($CONFIG['staff_keywords']) === true);
check('staff gate passes empty name', $sandra->isStaffDocument($CONFIG['staff_keywords']) === false);

echo "\nSchools sync: staff document rejected\n";
$fake = new FakeSmartsheet($schoolCols, []);
$sync = new Sync($fake, false);
$r = $sync->run('schools', $CONFIG['sheets']['schools'], $withDoc);
check('skipped', $r['action'] === 'skipped' && $r['reason'] === 'staff document', json_encode($r));
check('no API calls', $fake->calls === [], json_encode($fake->calls));

echo "\nSchools sync: new school with no opportunity -> create with fallback name\n";
$fake = new FakeSmartsheet($schoolCols, [1 => ['SCHOOL NAME' => 'PS 331', 'LP Contact ID' => '']]);
$sync = new Sync($fake, false);
$r = $sync->run('schools', $CONFIG['sheets']['schools'], $ylim);
check('created', $r['action'] === 'create', json_encode($r));
$new = $fake->rows[$r['rowId']] ?? [];
check('school name set from Organization Name', ($new['SCHOOL NAME'] ?? '') === 'P12X, Lewis and Clark School', json_encode($new));
check('SOW link written', str_starts_with($new['LP SOW Sent'] ?? '', 'https://'));
check('contact id written', ($new['LP Contact ID'] ?? '') === 'CONTACT0SCHOOL0001');
check('empty values not written', !array_key_exists('LP City', $new) || $new['LP City'] !== '');

echo "\nSchools sync: second event for same contact -> update same row\n";
$r2 = $sync->run('schools', $CONFIG['sheets']['schools'], $ylim);
check('updated', $r2['action'] === 'update' && $r2['rowId'] === $r['rowId'], json_encode($r2));
check('matched by contact id', ($r2['matchedBy'] ?? '') === 'contact id', (string)($r2['matchedBy'] ?? ''));

echo "\nSchools sync: hand-made row matched by school name, then stamped\n";
$sandraSchool = new Payload(array_merge($sandra->raw(), ['Official School Name' => 'PS 331', 'customData' => array_merge($sandra->raw()['customData'], ['LP Opportunity ID' => 'OPP123'])]));
$fake = new FakeSmartsheet($schoolCols, [1 => ['SCHOOL NAME' => 'PS 331'], 2 => ['SCHOOL NAME' => 'PS 331', 'LP Opportunity ID' => 'OTHER']]);
$sync = new Sync($fake, false);
$r = $sync->run('schools', $CONFIG['sheets']['schools'], $sandraSchool);
check('updated row 1 (first row with empty ids)', $r['action'] === 'update' && $r['rowId'] === 1, json_encode($r));
check('opportunity id stamped', ($fake->rows[1]['LP Opportunity ID'] ?? '') === 'OPP123');
check('school name not overwritten on update', ($fake->rows[1]['SCHOOL NAME'] ?? '') === 'PS 331');

echo "\nSchools sync: Work Order goes to LP WO Sent, SOW link untouched\n";
$fake = new FakeSmartsheet($schoolCols, []);
$sync = new Sync($fake, false);
$sowDoc = new Payload(array_merge($ylim->raw(), ['SOW Document Name' => 'Foodstream LEARN - Food Explorers SOW x IS 584']));
$r = $sync->run('schools', $CONFIG['sheets']['schools'], $sowDoc);
$row = $fake->rows[$r['rowId']] ?? [];
check('SOW event writes LP SOW Sent only', $r['action'] === 'create' && !empty($row['LP SOW Sent']) && empty($row['LP WO Sent']), json_encode($row));
$woRaw = $ylim->raw();
$woRaw['customData']['LP SOW Sent'] = 'https://go.foodstreamnetwork.com/documents/v1/wo-link';
$woRaw['SOW Document Name'] = '[Work Order] NYC DOE _ IS 584';
$r2 = $sync->run('schools', $CONFIG['sheets']['schools'], new Payload($woRaw));
$row = $fake->rows[$r['rowId']] ?? [];
check('WO event updates same row', $r2['action'] === 'update' && $r2['rowId'] === $r['rowId'], json_encode($r2));
check('WO link in LP WO Sent', ($row['LP WO Sent'] ?? '') === 'https://go.foodstreamnetwork.com/documents/v1/wo-link', json_encode($row));
check('SOW link preserved', str_starts_with($row['LP SOW Sent'] ?? '', 'https://') && $row['LP SOW Sent'] !== $row['LP WO Sent'], json_encode($row));
$fake = new FakeSmartsheet($schoolCols, []);
$r3 = (new Sync($fake, false))->run('schools', $CONFIG['sheets']['schools'], new Payload($woRaw));
$row = $fake->rows[$r3['rowId']] ?? [];
check('WO-first creates row with WO link only', $r3['action'] === 'create' && !empty($row['LP WO Sent']) && empty($row['LP SOW Sent']), json_encode($row));

echo "\nSchools sync: dry run writes nothing\n";
$fake = new FakeSmartsheet($schoolCols, []);
$sync = new Sync($fake, true);
$r = $sync->run('schools', $CONFIG['sheets']['schools'], $ylim);
check('dry create reported', $r['action'] === 'create' && $r['dryRun'] === true);
check('no write calls', !array_filter($fake->calls, fn($c) => str_starts_with($c, 'POST') || str_starts_with($c, 'PUT')), json_encode($fake->calls));

echo "\nEducators sync: create then update by contact id\n";
$eduCols = ['Primary Column', 'Educator Name', 'Educator SOW', 'FSN Assignment POC', 'School / Site', 'Program Name'];
$fake = new FakeSmartsheet($eduCols, []);
$sync = new Sync($fake, false);
$sandraDoc = new Payload(array_merge($sandra->raw(), ['SOW Document Name' => 'Assistant Educator Services SOW']));
$r = $sync->run('educators', $CONFIG['sheets']['educators'], $sandraDoc);
check('created', $r['action'] === 'create', json_encode($r));
$row = $fake->rows[$r['rowId']] ?? [];
check('name written', ($row['Educator Name'] ?? '') === 'Sally Staffer', json_encode($row));
check('school/site from business name', ($row['School / Site'] ?? '') === 'FoodStream Network');
check('primary column = doc name', ($row['Primary Column'] ?? '') === 'Assistant Educator Services SOW');
$r2 = $sync->run('educators', $CONFIG['sheets']['educators'], $sandraDoc);
check('signed event updates same row (by link)', $r2['action'] === 'update' && $r2['rowId'] === $r['rowId'] && $r2['matchedBy'] === 'sow link', json_encode($r2));
$fake2 = new FakeSmartsheet($eduCols, [5 => ['Educator Name' => 'Sally Staffer', 'Educator SOW' => '']]);
$r3 = (new Sync($fake2, false))->run('educators', $CONFIG['sheets']['educators'], $sandraDoc);
check('hand-made row matched by name when link empty', $r3['action'] === 'update' && $r3['rowId'] === 5, json_encode($r3));
check('no contact id column needed', !in_array('LP Contact ID', $eduCols, true));

echo "\nEducators: work order excluded\n";
$wo = new Payload(array_merge($sandra->raw(), ['SOW Document Name' => '[Work Order] NYC DOE - PS 331']));
$r = (new Sync(new FakeSmartsheet($eduCols, []), false))->run('educators', $CONFIG['sheets']['educators'], $wo);
check('skipped as excluded type', $r['action'] === 'skipped' && $r['reason'] === 'document type excluded', json_encode($r));

echo "\nEducators: signed school SOW not accepted\n";
$schoolDoc = new Payload(array_merge($sandra->raw(), ['SOW Document Name' => 'PS 175_Food Explorers SOW_2026-27 Agreement']));
$r = (new Sync(new FakeSmartsheet($eduCols, []), false))->run('educators', $CONFIG['sheets']['educators'], $schoolDoc);
check('skipped as not for this sheet', $r['action'] === 'skipped' && $r['reason'] === 'document type not for this sheet', json_encode($r));

echo "\nSkip when no SOW link\n";
$noUrl = $anast->raw();
$noUrl['customData']['LP SOW Sent'] = '';
$noUrl['SOW Document URL'] = '';
$r = (new Sync(new FakeSmartsheet($eduCols, []), false))->run('educators', $CONFIG['sheets']['educators'], new Payload($noUrl));
check('skipped, required missing', $r['action'] === 'skipped', json_encode($r));

echo "\nStore + Pipeline (SQLite)\n";
if (!extension_loaded('pdo_sqlite')) {
    echo "  skip pdo_sqlite not available\n";
} else {
    $tmp = sys_get_temp_dir() . '/fsn-test-store-' . getmypid();
    @mkdir($tmp, 0750, true);
    Store::init(['dir' => $tmp], 'test-secret');
    check('store enabled', Store::enabled(), Store::reason());
    $cfg = $CONFIG;
    $cfg['dry_run'] = false;
    $cfg['lock_dir'] = $tmp . '/locks';
    $fake = new FakeSmartsheet($schoolCols, []);
    $r = Pipeline::run($cfg, 'schools', $ylim->raw(), 'webhook', null, $fake);
    check('pipeline created row and stored event', $r['action'] === 'create' && !empty($r['eventId']), json_encode($r));
    $ev = Store::event((int)$r['eventId']);
    check('event fields', $ev && $ev['status'] === 'ok' && $ev['action'] === 'create' && $ev['contact_name'] === $ylim->rule('fullName') && $ev['sheet'] === 'schools' && (int)$ev['row_id'] === $r['rowId'], json_encode($ev ? array_diff_key($ev, ['payload' => 1]) : null));
    check('payload stored', is_array(json_decode((string)$ev['payload'], true)));
    check('log lines stored', count(Store::logsFor((string)$ev['rid'])) >= 2, (string)count(Store::logsFor((string)$ev['rid'])));
    $r2 = Pipeline::run($cfg, 'schools', json_decode((string)$ev['payload'], true), 'replay', (int)$ev['id'], $fake);
    $ev2 = Store::event((int)$r2['eventId']);
    check('replay updates same row', $r2['action'] === 'update' && $r2['rowId'] === $r['rowId'], json_encode($r2));
    check('replay recorded with replay_of', $ev2 && (int)$ev2['replay_of'] === (int)$ev['id'] && $ev2['source'] === 'replay');
    check('replaysOf lists it', Store::latestReplayOf((int)$ev['id']) === (int)$ev2['id']);
    $down = new class(['transport' => 'proxy', 'proxy' => ['url' => 'x', 'secret' => 'x'], 'cache_dir' => $tmp . '/c']) extends Smartsheet {
        public function request(string $method, string $path, array $query = [], ?array $body = null): array
        {
            throw new RuntimeException('Smartsheet down');
        }
    };
    try {
        Pipeline::run($cfg, 'schools', $ylim->raw(), 'webhook', null, $down);
        $threw = false;
    } catch (Throwable $e) {
        $threw = true;
    }
    $errs = Store::events(['status' => 'error']);
    check('failure rethrown and stored as error', $threw && count($errs) === 1 && str_contains((string)$errs[0]['error'], 'Smartsheet down'), json_encode($errs));
    check('free-text filter', count(Store::events(['q' => 'Kay'])) === 3, (string)count(Store::events(['q' => 'Kay'])));
    check('count with filter', Store::count(['sheet' => 'schools']) === 3 && Store::count(['sheet' => 'educators']) === 0);
    $stats = Store::stats();
    check('stats 24h', ($stats['24h']['total'] ?? 0) === 3 && ($stats['24h']['error'] ?? 0) === 1 && ($stats['24h']['create'] ?? 0) === 1 && ($stats['24h']['update'] ?? 0) === 1, json_encode($stats['24h'] ?? null));
    $logDir = $tmp . '/logs';
    @mkdir($logDir);
    $lines = [
        '<?php http_response_code(403); exit; ?>',
        json_encode(['t' => '2026-10-20T10:00:00+00:00', 'rid' => 'imp001', 'lvl' => 'info', 'ev' => 'inbound', 'ctx' => ['sheet' => 'educators', 'contact' => 'C1', 'name' => 'Sally Staffer', 'company' => 'FSN', 'doc' => 'Assistant Educator SOW', 'url' => 'https://x/doc', 'opp' => null, 'trigger' => 'Staff - Doc Sent']]),
        json_encode(['t' => '2026-10-20T10:00:01+00:00', 'rid' => 'imp001', 'lvl' => 'info', 'ev' => 'sync_create', 'ctx' => ['sheet' => 'educators', 'action' => 'create', 'rowId' => 123, 'cells' => 4]]),
        json_encode(['t' => '2026-10-21T10:00:00+00:00', 'rid' => 'imp002', 'lvl' => 'info', 'ev' => 'inbound', 'ctx' => ['sheet' => 'schools', 'contact' => 'C2', 'name' => 'X Y', 'company' => 'PS 1', 'doc' => null, 'url' => null, 'opp' => 'O1', 'trigger' => 'Pipeline stage changed']]),
        json_encode(['t' => '2026-10-21T10:00:01+00:00', 'rid' => 'imp002', 'lvl' => 'error', 'ev' => 'smartsheet_api_error', 'ctx' => ['status' => 400, 'body' => 'CANT_EDIT_LOCKED_COLUMN']]),
        json_encode(['t' => '2026-10-22T10:00:00+00:00', 'rid' => 'hc1', 'lvl' => 'info', 'ev' => 'smartsheet_call', 'ctx' => ['path' => '/sheets']]),
    ];
    file_put_contents($logDir . '/app-2026-10.log.php', implode("\n", $lines) . "\n");
    $b = Store::backfill($logDir);
    check('backfill imported 2 of 3 requests', ($b['imported'] ?? 0) === 2, json_encode($b));
    $imp = Store::events(['source' => 'import']);
    check('imported create', count(array_filter($imp, fn($e) => $e['rid'] === 'imp001' && $e['action'] === 'create' && (int)$e['row_id'] === 123 && $e['status'] === 'ok' && $e['sheet'] === 'educators')) === 1, json_encode($imp));
    check('imported error', count(array_filter($imp, fn($e) => $e['rid'] === 'imp002' && $e['status'] === 'error' && str_contains((string)$e['error'], 'CANT_EDIT_LOCKED_COLUMN'))) === 1);
    check('imported received_at from log', count(array_filter($imp, fn($e) => $e['rid'] === 'imp001' && str_starts_with((string)$e['received_at'], '2026-10-20T10:00:00'))) === 1);
    check('date filter', count(Store::events(['from' => '2026-10-21', 'to' => '2026-10-21'])) === 1);
    $b2 = Store::backfill($logDir);
    check('backfill idempotent', ($b2['imported'] ?? -1) === 0 && ($b2['existing'] ?? 0) === 2, json_encode($b2));
}

echo "
Internal countersigner ignored on both sheets
";
$rich = new Payload(array_merge($sandra->raw(), ['email' => 'Rich@FSNNYC.com', 'first_name' => '', 'last_name' => '', 'full_name' => '', 'SOW Document Name' => 'Contractor SOW_Foodstream LEARN_Lead Educator_Anna']));
$r = (new Sync(new FakeSmartsheet($eduCols, []), false))->run('educators', $CONFIG['sheets']['educators'], $rich);
check('educators: skipped as internal signer', $r['action'] === 'skipped' && $r['reason'] === 'internal signer', json_encode($r));
$richSchool = new Payload(array_merge($rich->raw(), ['SOW Document Name' => 'Foodstream LEARN - Food Explorers SOW - PS 1']));
$r = (new Sync(new FakeSmartsheet($schoolCols, []), false))->run('schools', $CONFIG['sheets']['schools'], $richSchool);
check('schools: skipped as internal signer', $r['action'] === 'skipped' && $r['reason'] === 'internal signer', json_encode($r));

echo "\nNotify: Program Manager SOWs -> Slack\n";
$posted = [];
Notify::$poster = function (string $url, array $body) use (&$posted): array {
    $posted[] = [$url, $body];
    return [200, 'ok'];
};
$rules = [['name' => 'pm', 'when_doc' => ['program manager'], 'on' => ['sent', 'signed'], 'slack_webhook' => 'https://hooks.slack.com/services/T/B/X', 'title' => 'Program Manager SOW']];
$pm = new Payload(array_merge($sandra->raw(), ['SOW Document Name' => '[Master/Staff] Contractor SOW_Foodstream LEARN_Program Manager - Sally', 'workflow' => ['name' => 'Staff - Doc Sent -> Slack']]));
$n = Notify::run($rules, 'educators', $pm, ['action' => 'create', 'rowId' => 9001], false);
check('posted once', count($posted) === 1 && ($n[0]['ok'] ?? false) === true, json_encode($n));
check('message says sent, names the doc and contact', str_contains($posted[0][1]['text'], 'sent for signature') && str_contains($posted[0][1]['blocks'][1]['text']['text'], 'Sally Staffer') && str_contains($posted[0][1]['blocks'][1]['text']['text'], 'Open document'), json_encode($posted[0][1]));
$pmSigned = new Payload(array_merge($pm->raw(), ['workflow' => ['name' => 'Staff - Doc Signed -> Slack']]));
Notify::run($rules, 'educators', $pmSigned, ['action' => 'update', 'rowId' => 9001], false);
check('signed event posts a signed message', count($posted) === 2 && str_contains($posted[1][1]['text'], 'signed'), $posted[1][1]['text'] ?? '');
$edu = new Payload(array_merge($pm->raw(), ['SOW Document Name' => 'Assistant Educator Services SOW - Sally']));
Notify::run($rules, 'educators', $edu, ['action' => 'create', 'rowId' => 1], false);
check('other staff documents ignored', count($posted) === 2);
Notify::run($rules, 'educators', $pm, ['action' => 'skipped', 'reason' => 'x'], false);
check('skipped events not announced', count($posted) === 2);
Notify::run([['when_doc' => ['program manager'], 'slack_webhook' => '']], 'educators', $pm, ['action' => 'create'], false);
check('rule without webhook is silent', count($posted) === 2);
$n2 = Notify::run($rules, 'educators', $pm, ['action' => 'create', 'rowId' => 1], true);
check('dry run posts nothing', count($posted) === 2 && ($n2[0]['dry'] ?? false) === true);
Notify::$poster = fn(string $url, array $body): array => [500, 'boom'];
$n3 = Notify::run($rules, 'educators', $pm, ['action' => 'create', 'rowId' => 1], false);
check('slack failure reported, not thrown', ($n3[0]['ok'] ?? true) === false && ($n3[0]['status'] ?? 0) === 500, json_encode($n3));
Notify::$poster = function (string $url, array $body) use (&$posted): array {
    $posted[] = [$url, $body];
    return [200, 'ok'];
};
$cfgN = $CONFIG;
$cfgN['dry_run'] = false;
$cfgN['lock_dir'] = sys_get_temp_dir() . '/fsn-test-locks-' . getmypid();
$cfgN['sheets']['educators']['notify'] = $rules;
$rp = Pipeline::run($cfgN, 'educators', $pm->raw(), 'webhook', null, new FakeSmartsheet($eduCols, []));
check('pipeline notifies after a successful sync', count($posted) === 3 && ($rp['notified'][0]['ok'] ?? false) === true, json_encode($rp));
Notify::$poster = null;

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
