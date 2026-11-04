<?php
/**
 * Offline tests. Run:  php tests/run.php
 * Uses the real LP payloads saved in ../runs and a fake Smartsheet backed by canned data.
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
    $d = json_decode((string)file_get_contents(APP_ROOT . "/../runs/$file.json"), true);
    return new Payload($d['operations'][0]['output']['Bundle 1']);
}

$schoolCols = array_merge(
    ['ID', 'SED', 'SCHOOL NAME', 'Program ID', 'SED Code (Look up in NYS Public Schools List )'],
    array_keys($CONFIG['sheets']['schools']['map'])
);
$ylim     = payload('kuvana_011537');     // real school signer w/o school name (dup record)
$sandra   = payload('sandra_204959');     // staff
$anast    = payload('anastasia_204512');  // staff

echo "Payload rules\n";
check('fullName', $sandra->rule('fullName') === 'Sandra Hall', (string)$sandra->rule('fullName'));
check('sowUrl from customData', str_starts_with((string)$sandra->rule('sowUrl'), 'https://go.foodstreamnetwork.com/'));
check('schoolName falls back to Organization Name', $ylim->schoolName() === 'P12X, Lewis and Clark School', (string)$ylim->schoolName());
check('schoolName uses Business Name', $sandra->schoolName() === 'FoodStream Network', (string)$sandra->schoolName());
check('quoted path', $sandra->get('customData."LP Contact Source"') === 'Sustainability Impact Report 2026');
check('interpolation null when all empty', $sandra->resolve('{{customData."LP City"}}') === null);
check('interpolation mixed', $sandra->resolve('{{first_name}} / {{customData."LP City"}}') === 'Sandra /');
$withDoc = new Payload(array_merge($sandra->raw(), ['SOW Document Name' => 'Assistant Educator Services SOW - Sandra']));
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
check('contact id written', ($new['LP Contact ID'] ?? '') === 'QEBzN1DWgmorYR1PQDVt');
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
check('name written', ($row['Educator Name'] ?? '') === 'Sandra Hall', json_encode($row));
check('school/site from business name', ($row['School / Site'] ?? '') === 'FoodStream Network');
check('primary column = doc name', ($row['Primary Column'] ?? '') === 'Assistant Educator Services SOW');
$r2 = $sync->run('educators', $CONFIG['sheets']['educators'], $sandraDoc);
check('signed event updates same row (by link)', $r2['action'] === 'update' && $r2['rowId'] === $r['rowId'] && $r2['matchedBy'] === 'sow link', json_encode($r2));
$fake2 = new FakeSmartsheet($eduCols, [5 => ['Educator Name' => 'Sandra Hall', 'Educator SOW' => '']]);
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
    check('free-text filter', count(Store::events(['q' => 'Kuvana'])) === 3, (string)count(Store::events(['q' => 'Kuvana'])));
    check('count with filter', Store::count(['sheet' => 'schools']) === 3 && Store::count(['sheet' => 'educators']) === 0);
    $stats = Store::stats();
    check('stats 24h', ($stats['24h']['total'] ?? 0) === 3 && ($stats['24h']['error'] ?? 0) === 1 && ($stats['24h']['create'] ?? 0) === 1 && ($stats['24h']['update'] ?? 0) === 1, json_encode($stats['24h'] ?? null));
    $logDir = $tmp . '/logs';
    @mkdir($logDir);
    $lines = [
        '<?php http_response_code(403); exit; ?>',
        json_encode(['t' => '2026-10-20T10:00:00+00:00', 'rid' => 'imp001', 'lvl' => 'info', 'ev' => 'inbound', 'ctx' => ['sheet' => 'educators', 'contact' => 'C1', 'name' => 'Sandra Hall', 'company' => 'FSN', 'doc' => 'Assistant Educator SOW', 'url' => 'https://x/doc', 'opp' => null, 'trigger' => 'Staff - Doc Sent']]),
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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
