<?php
declare(strict_types=1);

/**
 * SQLite event store behind dashboard.php.
 *
 * One row in `events` per inbound LP event: who it was about, the document, what Sync decided
 * (create / update / skipped / error), the Smartsheet row, and the full payload so the event can
 * be replayed. Every log line written during that request goes to `log`, keyed by request id.
 *
 * It never throws into the request path. If SQLite is missing or the file cannot be opened the
 * store disables itself, the webhook keeps working, and health.php reports the reason.
 *
 * The database file lives in data/ under an unguessable name derived from the inbound key, with
 * an .htaccess and empty index.html beside it. Never upload or download data/.
 */
final class Store
{
    private static ?PDO $db = null;
    private static string $path = '';
    private static string $reason = 'not initialised';
    private static int $retentionDays = 400;

    public static function init(array $cfg, string $secret): void
    {
        self::$db = null;
        if (array_key_exists('enabled', $cfg) && !$cfg['enabled']) {
            self::$reason = 'disabled in config';
            return;
        }
        if (!extension_loaded('pdo_sqlite')) {
            self::$reason = 'pdo_sqlite extension not loaded';
            return;
        }
        $dir = rtrim((string)($cfg['dir'] ?? (APP_ROOT . '/data')), '/\\');
        self::$retentionDays = max(1, (int)($cfg['retention_days'] ?? 400));
        self::$path = $dir . '/events-' . substr(hash('sha256', 'fsn-store|' . $secret), 0, 16) . '.sqlite';
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
                throw new RuntimeException("cannot create $dir");
            }
            foreach (['.htaccess' => "Require all denied\n", 'index.html' => ''] as $f => $body) {
                if (!is_file("$dir/$f")) {
                    @file_put_contents("$dir/$f", $body);
                }
            }
            $db = new PDO('sqlite:' . self::$path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('PRAGMA busy_timeout=5000');
            $db->exec('PRAGMA synchronous=NORMAL');
            self::migrate($db);
            self::$db = $db;
            self::$reason = '';
            if (random_int(1, 50) === 1) {
                self::purge();
            }
        } catch (Throwable $e) {
            self::$db = null;
            self::$reason = 'open failed: ' . $e->getMessage();
        }
    }

    public static function enabled(): bool
    {
        return self::$db !== null;
    }

    public static function reason(): string
    {
        return self::$reason;
    }

    public static function path(): string
    {
        return self::$path;
    }

    public static function sizeBytes(): int
    {
        return self::$path !== '' && is_file(self::$path) ? (int)filesize(self::$path) : 0;
    }

    // ---------------------------------------------------------------- writes

    /** Record an inbound event before anything is attempted. Returns the event id (null when disabled). */
    public static function begin(string $rid, string $sheet, Payload $p, array $raw, string $source = 'webhook', ?int $replayOf = null, ?string $receivedAt = null): ?int
    {
        if (!self::$db) {
            return null;
        }
        try {
            $st = self::$db->prepare(
                'INSERT INTO events (rid, received_at, source, replay_of, sheet, trigger_name, contact_id, contact_name, company, email,
                                     doc_name, doc_url, opp_id, status, payload)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $rid,
                $receivedAt ?? gmdate('c'),
                $source,
                $replayOf,
                $sheet,
                $p->get('workflow.name'),
                $p->get('contact_id'),
                $p->rule('fullName'),
                $p->first('customData."LP Business Name"', 'company_name'),
                $p->first('email', 'customData."LP Email"'),
                $p->rule('sowName'),
                $p->rule('sowUrl'),
                $p->get('customData."LP Opportunity ID"'),
                'received',
                json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ]);
            return (int)self::$db->lastInsertId();
        } catch (Throwable $e) {
            self::$reason = 'write failed: ' . $e->getMessage();
            return null;
        }
    }

    public static function finish(?int $id, array $result, int $ms): void
    {
        if (!self::$db || !$id) {
            return;
        }
        try {
            $st = self::$db->prepare('UPDATE events SET status=?, action=?, reason=?, row_id=?, matched_by=?, cells=?, duration_ms=? WHERE id=?');
            $st->execute([
                'ok',
                $result['action'] ?? null,
                $result['reason'] ?? null,
                isset($result['rowId']) && $result['rowId'] !== null ? (int)$result['rowId'] : null,
                $result['matchedBy'] ?? null,
                isset($result['cells']) ? (int)$result['cells'] : null,
                $ms,
                $id,
            ]);
        } catch (Throwable $e) {
            self::$reason = 'write failed: ' . $e->getMessage();
        }
    }

    public static function fail(?int $id, string $error, int $ms): void
    {
        if (!self::$db || !$id) {
            return;
        }
        try {
            $st = self::$db->prepare('UPDATE events SET status=?, error=?, duration_ms=? WHERE id=?');
            $st->execute(['error', mb_substr($error, 0, 2000), $ms, $id]);
        } catch (Throwable $e) {
            self::$reason = 'write failed: ' . $e->getMessage();
        }
    }

    /** Called by Log for every line. Must never throw and must never call Log. */
    public static function log(string $rid, string $level, string $event, array $ctx): void
    {
        if (!self::$db || $event === 'payload') {
            return;
        }
        self::insertLog($rid, gmdate('c'), $level, $event, $ctx);
    }

    private static function insertLog(string $rid, string $t, string $level, string $event, array $ctx): void
    {
        try {
            $json = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = '{}';
            }
            if (strlen($json) > 20000) {
                $json = substr($json, 0, 20000) . '...';
            }
            self::$db->prepare('INSERT INTO log (rid, t, level, event, ctx) VALUES (?,?,?,?,?)')
                ->execute([$rid, $t, $level, $event, $json]);
        } catch (Throwable) {
            // never break the request because of the audit trail
        }
    }

    // ---------------------------------------------------------------- reads

    /** Filters: sheet, status, action, source, q (free text), from (Y-m-d), to (Y-m-d), contact_id */
    public static function events(array $f, int $limit = 50, int $offset = 0): array
    {
        if (!self::$db) {
            return [];
        }
        [$where, $args] = self::where($f);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $st = self::$db->prepare(
            "SELECT id, rid, received_at, source, replay_of, sheet, trigger_name, contact_id, contact_name, company, email,
                    doc_name, doc_url, opp_id, status, action, reason, row_id, matched_by, cells, error, duration_ms
             FROM events$where ORDER BY received_at DESC, id DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function count(array $f = []): int
    {
        if (!self::$db) {
            return 0;
        }
        [$where, $args] = self::where($f);
        $st = self::$db->prepare("SELECT COUNT(*) FROM events$where");
        $st->execute($args);
        return (int)$st->fetchColumn();
    }

    public static function event(int $id): ?array
    {
        if (!self::$db) {
            return null;
        }
        $st = self::$db->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function replaysOf(int $id): array
    {
        if (!self::$db) {
            return [];
        }
        $st = self::$db->prepare('SELECT id, received_at, status, action, reason, row_id, error FROM events WHERE replay_of = ? ORDER BY id DESC');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    public static function latestReplayOf(int $id): ?int
    {
        $r = self::replaysOf($id);
        return $r ? (int)$r[0]['id'] : null;
    }

    public static function logsFor(string $rid): array
    {
        if (!self::$db) {
            return [];
        }
        $st = self::$db->prepare('SELECT t, level, event, ctx FROM log WHERE rid = ? ORDER BY id');
        $st->execute([$rid]);
        return $st->fetchAll();
    }

    public static function stats(): array
    {
        if (!self::$db) {
            return [];
        }
        $out = [
            'total' => (int)self::$db->query('SELECT COUNT(*) FROM events')->fetchColumn(),
            'last'  => self::$db->query('SELECT id, received_at, sheet, contact_name, status, action FROM events ORDER BY received_at DESC, id DESC LIMIT 1')->fetch() ?: null,
            'size'  => self::sizeBytes(),
        ];
        foreach (['24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days'] as $k => $rel) {
            $st = self::$db->prepare('SELECT status, action, COUNT(*) AS n FROM events WHERE received_at >= ? GROUP BY status, action');
            $st->execute([gmdate('c', strtotime($rel))]);
            $b = ['total' => 0, 'create' => 0, 'update' => 0, 'skipped' => 0, 'error' => 0, 'received' => 0];
            foreach ($st as $r) {
                $n = (int)$r['n'];
                $b['total'] += $n;
                if ($r['status'] === 'error') {
                    $b['error'] += $n;
                } elseif ($r['status'] === 'received') {
                    $b['received'] += $n;
                } else {
                    $key = in_array($r['action'], ['create', 'update'], true) ? $r['action'] : 'skipped';
                    $b[$key] += $n;
                }
            }
            $out[$k] = $b;
        }
        return $out;
    }

    private static function where(array $f): array
    {
        $w = [];
        $a = [];
        foreach (['sheet', 'status', 'action', 'source', 'contact_id'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $w[] = "$k = ?";
                $a[] = $f[$k];
            }
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . $f['q'] . '%';
            $w[] = '(contact_name LIKE ? OR company LIKE ? OR doc_name LIKE ? OR email LIKE ? OR opp_id LIKE ? OR contact_id LIKE ? OR rid LIKE ? OR CAST(row_id AS TEXT) LIKE ? OR error LIKE ? OR reason LIKE ?)';
            array_push($a, ...array_fill(0, 10, $like));
        }
        if (($f['from'] ?? '') !== '' && strtotime((string)$f['from'])) {
            $w[] = 'received_at >= ?';
            $a[] = gmdate('Y-m-d', strtotime((string)$f['from'])) . 'T00:00:00+00:00';
        }
        if (($f['to'] ?? '') !== '' && strtotime((string)$f['to'])) {
            $w[] = 'received_at < ?';
            $a[] = gmdate('Y-m-d', strtotime((string)$f['to'] . ' +1 day')) . 'T00:00:00+00:00';
        }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $a];
    }

    // ---------------------------------------------------------------- maintenance

    public static function purge(): void
    {
        if (!self::$db) {
            return;
        }
        try {
            $cut = gmdate('c', strtotime('-' . self::$retentionDays . ' days'));
            self::$db->prepare('DELETE FROM events WHERE received_at < ?')->execute([$cut]);
            self::$db->prepare('DELETE FROM log WHERE t < ?')->execute([$cut]);
        } catch (Throwable) {
        }
    }

    /**
     * Import history from the JSON-lines log files (logs/app-YYYY-MM.log.php) written before the
     * store existed. Groups lines by request id; one event per request that logged "inbound".
     * Idempotent: requests already in the store are skipped.
     */
    public static function backfill(string $logDir): array
    {
        if (!self::$db) {
            return ['files' => 0, 'imported' => 0, 'existing' => 0, 'error' => self::$reason];
        }
        $files = glob(rtrim($logDir, '/\\') . '/app-*.log.php') ?: [];
        sort($files);
        $byRid = [];
        foreach ($files as $f) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $j = json_decode($line, true);
                if (is_array($j) && !empty($j['rid'])) {
                    $byRid[(string)$j['rid']][] = $j;
                }
            }
        }
        $existing = array_flip(self::$db->query('SELECT rid FROM events')->fetchAll(PDO::FETCH_COLUMN));
        $imported = 0;
        $skipped = 0;
        self::$db->beginTransaction();
        try {
            foreach ($byRid as $rid => $lines) {
                $inbound = null;
                foreach ($lines as $l) {
                    if (($l['ev'] ?? '') === 'inbound') {
                        $inbound = $l;
                        break;
                    }
                }
                if ($inbound === null) {
                    continue; // health checks, auth failures, notices
                }
                if (isset($existing[$rid])) {
                    $skipped++;
                    continue;
                }
                $c = $inbound['ctx'] ?? [];
                $data = null;
                foreach ($lines as $l) {
                    if (($l['ev'] ?? '') === 'payload' && isset($l['ctx']['data']) && is_array($l['ctx']['data'])) {
                        $data = $l['ctx']['data'];
                        break;
                    }
                }
                if ($data === null) {
                    $data = [
                        'contact_id'   => $c['contact'] ?? null,
                        'full_name'    => $c['name'] ?? null,
                        'company_name' => $c['company'] ?? null,
                        'workflow'     => ['name' => $c['trigger'] ?? null],
                        'customData'   => ['LP SOW Sent' => $c['url'] ?? null, 'LP SOW Name' => $c['doc'] ?? null, 'LP Opportunity ID' => $c['opp'] ?? null],
                        '_note'        => 'reconstructed from log summary; full payload was not logged',
                    ];
                }
                $sheet = (string)($c['sheet'] ?? 'unknown');
                $t0 = strtotime((string)($inbound['t'] ?? '')) ?: time();
                $id = self::begin((string)$rid, $sheet, new Payload($data), $data, 'import', null, $inbound['t'] ?? gmdate('c'));
                if (!$id) {
                    continue;
                }
                $done = false;
                foreach ($lines as $l) {
                    $ev = (string)($l['ev'] ?? '');
                    $ms = max(0, (int)round(((strtotime((string)($l['t'] ?? '')) ?: $t0) - $t0) * 1000));
                    if (!$done && in_array($ev, ['sync_update', 'sync_create', 'sync_skip'], true)) {
                        self::finish($id, $l['ctx'] ?? [], $ms);
                        $done = true;
                    } elseif (!$done && ($l['lvl'] ?? '') === 'error') {
                        self::fail($id, $ev . ': ' . json_encode($l['ctx'] ?? [], JSON_UNESCAPED_SLASHES), $ms);
                        $done = true;
                    }
                    if ($ev !== 'payload') {
                        self::insertLog((string)$rid, (string)($l['t'] ?? ''), (string)($l['lvl'] ?? 'info'), $ev, is_array($l['ctx'] ?? null) ? $l['ctx'] : []);
                    }
                }
                $imported++;
            }
            self::$db->commit();
        } catch (Throwable $e) {
            self::$db->rollBack();
            return ['files' => count($files), 'imported' => 0, 'existing' => $skipped, 'error' => $e->getMessage()];
        }
        return ['files' => count($files), 'imported' => $imported, 'existing' => $skipped];
    }

    private static function migrate(PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    rid          TEXT    NOT NULL,
    received_at  TEXT    NOT NULL,
    source       TEXT    NOT NULL DEFAULT 'webhook',
    replay_of    INTEGER,
    sheet        TEXT    NOT NULL,
    trigger_name TEXT,
    contact_id   TEXT,
    contact_name TEXT,
    company      TEXT,
    email        TEXT,
    doc_name     TEXT,
    doc_url      TEXT,
    opp_id       TEXT,
    status       TEXT    NOT NULL,
    action       TEXT,
    reason       TEXT,
    row_id       INTEGER,
    matched_by   TEXT,
    cells        INTEGER,
    error        TEXT,
    duration_ms  INTEGER,
    payload      TEXT    NOT NULL
)
SQL);
        $db->exec('CREATE INDEX IF NOT EXISTS ix_events_received ON events(received_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS ix_events_contact  ON events(contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS ix_events_status   ON events(status)');
        $db->exec('CREATE INDEX IF NOT EXISTS ix_events_rid      ON events(rid)');
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS log (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    rid   TEXT NOT NULL,
    t     TEXT NOT NULL,
    level TEXT NOT NULL,
    event TEXT NOT NULL,
    ctx   TEXT
)
SQL);
        $db->exec('CREATE INDEX IF NOT EXISTS ix_log_rid ON log(rid)');
    }
}
