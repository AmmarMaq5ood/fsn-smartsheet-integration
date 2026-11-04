<?php
declare(strict_types=1);

/**
 * Smartsheet client. Every call goes through one transport:
 *   proxy  - a Make scenario (webhook -> Smartsheet "Make an API Call" -> webhook response)
 *   direct - api.smartsheet.com with a bearer token (swap in later; callers do not change)
 *
 * Column titles are resolved to IDs at runtime and cached on disk, so config only uses titles.
 */
class Smartsheet
{
    private array $cfg;
    private array $colCache = [];

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    // ---------------------------------------------------------------- low level

    /** @return array decoded JSON body of the Smartsheet response */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $t0 = microtime(true);

        if (($this->cfg['transport'] ?? 'proxy') === 'direct') {
            $url = rtrim($this->cfg['direct']['base'] ?? 'https://api.smartsheet.com/2.0', '/') . $path;
            if ($query) {
                $url .= '?' . http_build_query($query);
            }
            [$status, $text] = Http::json($method, $url, $body, ['Authorization: Bearer ' . ($this->cfg['direct']['token'] ?? '')]);
        } else {
            $payload = [
                'secret' => $this->cfg['proxy']['secret'] ?? '',
                'method' => $method,
                'path'   => $path,
                'query'  => $query ? http_build_query($query) : '',
                'body'   => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
            [$status, $text] = Http::postJson($this->cfg['proxy']['url'], $payload, [], 45);
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        $data = json_decode($text, true);

        if ($status >= 300 || !is_array($data)) {
            Log::error('smartsheet_call_failed', ['method' => $method, 'path' => $path, 'status' => $status, 'ms' => $ms, 'body' => substr($text, 0, 500)]);
            throw new RuntimeException("Smartsheet $method $path failed (HTTP $status): " . substr($text, 0, 300));
        }

        // The proxy wraps the API reply as {"statusCode": .., "body": ..}
        if (isset($data['statusCode']) && array_key_exists('body', $data)) {
            $inner = $data['body'];
            if (is_string($inner)) {
                $inner = json_decode($inner, true);
            }
            if ((int)$data['statusCode'] >= 300 || !is_array($inner)) {
                $shown = is_string($data['body']) ? substr($data['body'], 0, 500) : $data['body'];
                Log::error('smartsheet_api_error', ['method' => $method, 'path' => $path, 'status' => $data['statusCode'], 'ms' => $ms, 'body' => $shown]);
                throw new RuntimeException("Smartsheet API $method $path returned {$data['statusCode']}");
            }
            $data = $inner;
        }

        Log::info('smartsheet_call', ['method' => $method, 'path' => $path, 'status' => $status, 'ms' => $ms]);
        return $data;
    }

    // ---------------------------------------------------------------- columns

    /** title => ['id','type','formula','system'], cached on disk. */
    public function columns(int $sheetId, bool $refresh = false): array
    {
        if (!$refresh && isset($this->colCache[$sheetId])) {
            return $this->colCache[$sheetId];
        }
        $file = $this->cacheDir() . "/columns-$sheetId.json";
        $ttl = (int)($this->cfg['column_cache_ttl'] ?? 21600);
        if (!$refresh && is_file($file) && (time() - filemtime($file)) < $ttl) {
            $map = json_decode((string)file_get_contents($file), true);
            if (is_array($map) && $map) {
                return $this->colCache[$sheetId] = $map;
            }
        }
        $res = $this->request('GET', "/sheets/$sheetId/columns", ['includeAll' => 'true']);
        $map = [];
        foreach ($res['data'] ?? [] as $c) {
            $map[trim((string)$c['title'])] = [
                'id'      => (int)$c['id'],
                'type'    => $c['type'] ?? null,
                'formula' => isset($c['formula']),
                'system'  => $c['systemColumnType'] ?? null,
            ];
        }
        if (!$map) {
            throw new RuntimeException("No columns returned for sheet $sheetId");
        }
        self::cacheWrite($file, $map);
        return $this->colCache[$sheetId] = $map;
    }

    public function columnId(int $sheetId, string $title): int
    {
        $title = trim($title);
        $map = $this->columns($sheetId);
        if (!isset($map[$title])) {
            $map = $this->columns($sheetId, true);
        }
        if (!isset($map[$title])) {
            throw new RuntimeException("Column not found in sheet $sheetId: '$title'");
        }
        return $map[$title]['id'];
    }

    public function isWritable(int $sheetId, string $title): bool
    {
        $c = $this->columns($sheetId)[trim($title)] ?? null;
        return $c !== null && !$c['formula'] && $c['system'] === null;
    }

    // ---------------------------------------------------------------- rows

    /**
     * First row where every $equals[title] matches (trimmed, case-insensitive)
     * and every title in $empty is blank. Returns rowId or null.
     */
    public function findRow(int $sheetId, array $equals, array $empty = []): ?int
    {
        $titles = array_merge(array_keys($equals), $empty);
        $ids = [];
        foreach ($titles as $t) {
            $ids[$t] = $this->columnId($sheetId, (string)$t);
        }
        $res = $this->request('GET', "/sheets/$sheetId", [
            'columnIds' => implode(',', array_values($ids)),
            'exclude'   => 'nonexistentCells',
        ]);
        foreach ($res['rows'] ?? [] as $row) {
            $vals = [];
            foreach ($row['cells'] ?? [] as $cell) {
                $vals[(int)$cell['columnId']] = trim((string)($cell['displayValue'] ?? $cell['value'] ?? ''));
            }
            $ok = true;
            foreach ($equals as $t => $want) {
                if (strcasecmp($vals[$ids[$t]] ?? '', trim((string)$want)) !== 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                foreach ($empty as $t) {
                    if (($vals[$ids[$t]] ?? '') !== '') {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                return (int)$row['id'];
            }
        }
        return null;
    }

    /** @param array $cells title => value. Returns the new rowId. */
    public function addRow(int $sheetId, array $cells): int
    {
        $res = $this->request('POST', "/sheets/$sheetId/rows", [], [['toBottom' => true, 'cells' => $this->toCells($sheetId, $cells)]]);
        $row = $res['result'][0] ?? ($res['result'] ?? null);
        return (int)($row['id'] ?? 0);
    }

    public function updateRow(int $sheetId, int $rowId, array $cells): void
    {
        $c = $this->toCells($sheetId, $cells);
        if (!$c) {
            return;
        }
        $this->request('PUT', "/sheets/$sheetId/rows", [], [['id' => $rowId, 'cells' => $c]]);
    }

    private function toCells(int $sheetId, array $cells): array
    {
        $out = [];
        foreach ($cells as $title => $value) {
            if ($value === null) {
                continue;
            }
            if (!$this->isWritable($sheetId, (string)$title)) {
                Log::warn('skip_readonly_column', ['col' => $title]);
                continue;
            }
            $out[] = ['columnId' => $this->columnId($sheetId, (string)$title), 'value' => is_bool($value) ? $value : (string)$value];
        }
        return $out;
    }

    private function cacheDir(): string
    {
        return rtrim((string)($this->cfg['cache_dir'] ?? (APP_ROOT . '/cache')), '/');
    }

    private static function cacheWrite(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /** Sheet permalink for deep links (cached; a row link is permalink?rowId=<id>). */
    public function permalink(int $sheetId): ?string
    {
        $file = $this->cacheDir() . '/permalinks.json';
        $map = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        if (empty($map[(string)$sheetId])) {
            $res = $this->request('GET', "/sheets/$sheetId", ['pageSize' => '1']);
            if (empty($res['permalink'])) {
                return null;
            }
            $map[(string)$sheetId] = (string)$res['permalink'];
            self::cacheWrite($file, $map);
        }
        return (string)$map[(string)$sheetId];
    }

    /** Resolve a sheet by configured id, or by name (cached). */
    public function sheetId(array $sheetCfg): int
    {
        if (!empty($sheetCfg['id'])) {
            return (int)$sheetCfg['id'];
        }
        $name = trim((string)($sheetCfg['name'] ?? ''));
        $file = $this->cacheDir() . '/sheets.json';
        $ttl = (int)($this->cfg['sheet_cache_ttl'] ?? 86400);
        $fresh = is_file($file) && (time() - filemtime($file)) < $ttl;
        $map = $fresh ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        if (!isset($map[$name])) {
            $res = $this->request('GET', '/sheets', ['includeAll' => 'true']);
            $map = [];
            foreach ($res['data'] ?? [] as $s) {
                $map[trim((string)$s['name'])] = (int)$s['id'];
            }
            self::cacheWrite($file, $map);
        }
        if (!isset($map[$name])) {
            throw new RuntimeException("Sheet not found: '$name'");
        }
        return $map[$name];
    }
}
