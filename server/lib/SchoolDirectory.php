<?php
declare(strict_types=1);

/**
 * The master school list "NYS Public Schools Principal Contact Info" (Smartsheet), read by SED code.
 *
 * Its "Popular Name" column is exactly the PLT's SCHOOL NAME dropdown, and the PLT's SED / principal /
 * county / type columns are lookups on that name, so writing the master's name for a SED is what makes
 * a tracker row resolve. Loaded into a disk cache. A cache older than the TTL is still served at once (a webhook
 * must not wait for the 10 s refetch); the caller refreshes it after responding (see Pipeline). No cache = fetch now.
 *
 * Config ['school_directory']: sheet (['id' => ..] or ['name' => ..]), ttl seconds, cache_dir.
 */
class SchoolDirectory
{
    public const COLUMNS = [
        'SED Code', 'Popular Name', 'Legal Name', 'County Code', 'County Description',
        'SchDistofLoc Code', 'SchDistofLoc Description', 'Grade Organization Description',
        'CEO FName', 'CEO LName', 'CEO email', 'CEO Phone #',
    ];

    private ?array $bySed = null;
    private array $nameCount = [];
    private ?string $loadedFrom = null;
    private bool $stale = false;

    public function __construct(private Smartsheet $ss, private array $cfg)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['sheet']);
    }

    /** 12-digit SED code, or null. Excel-mangled values ("3.5311") and blanks give null; 11 digits get a leading zero. */
    public static function normaliseSed(?string $x): ?string
    {
        $x = trim((string)$x);
        if ($x === '' || !preg_match('/^\d{11,12}$/', $x)) {
            return null;
        }
        return strlen($x) === 11 ? '0' . $x : $x;
    }

    /**
     * @return array{sed:string,name:string,legal_name:?string,county:?string,county_code:?string,district_code:?string,
     *               district:?string,type:?string,principal_name:?string,principal_email:?string,principal_phone:?string,
     *               name_shared:bool}|null
     */
    public function bySed(?string $sed): ?array
    {
        $sed = self::normaliseSed($sed);
        if ($sed === null || !$this->enabled()) {
            return null;
        }
        $this->load();
        $r = $this->bySed[$sed] ?? null;
        if (!$r) {
            return null;
        }
        $r['name_shared'] = ($this->nameCount[$r['name']] ?? 0) > 1;
        return $r;
    }

    public function count(): int
    {
        if (!$this->enabled()) {
            return 0;
        }
        $this->load();
        return count($this->bySed ?? []);
    }

    /** 'cache' | 'sheet' | 'stale cache' | 'none' - where the current table came from (for health). */
    public function loadedFrom(): string
    {
        return $this->loadedFrom ?? 'none';
    }

    /** True when the table in use is older than the TTL (or could not be fetched) and a refresh is due. */
    public function isStale(): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $this->load();
        return $this->stale;
    }

    /** Fetch the sheet now and rewrite the cache. Never throws; on failure whatever was loaded stays in use. */
    public function refresh(): void
    {
        $file = $this->cacheFile();
        try {
            [$bySed, $nameCount] = $this->fetch();
            $this->bySed = $bySed;
            $this->nameCount = $nameCount;
            $this->stale = false;
            $this->loadedFrom = 'sheet';
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            @file_put_contents($file, json_encode(['bySed' => $bySed, 'nameCount' => $nameCount]), LOCK_EX);
            Log::info('school_directory_loaded', ['schools' => count($bySed)]);
        } catch (Throwable $e) {
            Log::warn('school_directory_fetch_failed', ['error' => $e->getMessage(), 'kept' => count($this->bySed ?? [])]);
            $this->bySed ??= [];
            $this->stale = true;
            $this->loadedFrom ??= 'none';
        }
    }

    private function load(): void
    {
        if ($this->bySed !== null) {
            return;
        }
        $file = $this->cacheFile();
        $ttl = (int)($this->cfg['ttl'] ?? 21600);
        $cached = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($cached) && !empty($cached['bySed'])) {
            $this->bySed = $cached['bySed'];
            $this->nameCount = $cached['nameCount'] ?? [];
            $this->stale = (time() - filemtime($file)) >= $ttl;
            $this->loadedFrom = $this->stale ? 'stale cache' : 'cache';
            return;
        }
        $this->refresh();
    }

    /** @return array{0: array<string,array>, 1: array<string,int>} */
    private function fetch(): array
    {
        $sheetId = $this->ss->sheetId($this->cfg['sheet']);
        $cols = $this->ss->columns($sheetId, true);
        $ids = [];
        foreach (self::COLUMNS as $t) {
            if (isset($cols[$t])) {
                $ids[$t] = (int)$cols[$t]['id'];
            }
        }
        if (!isset($ids['SED Code'], $ids['Popular Name'])) {
            throw new RuntimeException('master sheet is missing the SED Code / Popular Name columns');
        }
        $res = $this->ss->request('GET', "/sheets/$sheetId", [
            'columnIds' => implode(',', array_values($ids)),
            'exclude'   => 'nonexistentCells',
            'pageSize'  => 10000,
        ]);
        $byId = array_flip($ids);
        $bySed = [];
        $nameCount = [];
        foreach ($res['rows'] ?? [] as $row) {
            $v = [];
            foreach ($row['cells'] ?? [] as $cell) {
                $t = $byId[(int)$cell['columnId']] ?? null;
                if ($t !== null) {
                    $v[$t] = trim((string)($cell['displayValue'] ?? $cell['value'] ?? ''));
                }
            }
            $sed = self::normaliseSed($v['SED Code'] ?? null);
            $name = $v['Popular Name'] ?? '';
            if ($sed === null || $name === '' || isset($bySed[$sed])) {
                continue;
            }
            $nz = fn(string $k) => isset($v[$k]) && $v[$k] !== '' ? $v[$k] : null;
            $principal = trim(($v['CEO FName'] ?? '') . ' ' . ($v['CEO LName'] ?? ''));
            $bySed[$sed] = [
                'sed'             => $sed,
                'name'            => $name,
                'legal_name'      => $nz('Legal Name'),
                'county'          => $nz('County Description') !== null ? ucwords(strtolower($v['County Description'])) : null,
                'county_code'     => $nz('County Code'),
                'district_code'   => $nz('SchDistofLoc Code'),
                'district'        => $nz('SchDistofLoc Description'),
                'type'            => $nz('Grade Organization Description'),
                'principal_name'  => $principal !== '' ? ucwords(strtolower($principal), " -'") : null,
                'principal_email' => $nz('CEO email') !== null ? strtolower($v['CEO email']) : null,
                'principal_phone' => $nz('CEO Phone #'),
            ];
            $nameCount[$name] = ($nameCount[$name] ?? 0) + 1;
        }
        if (!$bySed) {
            throw new RuntimeException('master sheet returned no usable rows');
        }
        return [$bySed, $nameCount];
    }

    private function cacheFile(): string
    {
        $dir = rtrim((string)($this->cfg['cache_dir'] ?? (APP_ROOT . '/cache')), '/\\');
        return $dir . '/school-directory.json';
    }
}
