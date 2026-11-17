<?php
declare(strict_types=1);

/**
 * Read-only Launchpad (GHL) client, used to look up the signer's company = the school record.
 *
 * Config ['lp']: base, token (private integration token), location, timeout.
 * Empty token = disabled; every lookup then returns null and the sync falls back to the payload.
 * Never throws: a failed lookup is logged and returns null, so the row is still written.
 */
class Launchpad
{
    public function __construct(private array $cfg)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['token']) && !empty($this->cfg['location']);
    }

    /** @return array{id:string,businessId:?string,companyName:?string,email:?string,customFields:array<string,mixed>}|null */
    public function contact(string $id): ?array
    {
        $r = $this->get('/contacts/' . rawurlencode($id), [], '2021-07-28');
        $c = $r['contact'] ?? null;
        if (!is_array($c)) {
            return null;
        }
        $cf = [];
        foreach ($c['customFields'] ?? [] as $f) {
            if (isset($f['id']) && isset($f['value']) && $f['value'] !== '') {
                $cf[(string)$f['id']] = $f['value'];
            }
        }
        return [
            'id'           => (string)($c['id'] ?? $id),
            'businessId'   => isset($c['businessId']) && $c['businessId'] !== '' ? (string)$c['businessId'] : null,
            'companyName'  => isset($c['companyName']) && $c['companyName'] !== '' ? (string)$c['companyName'] : null,
            'email'        => isset($c['email']) ? (string)$c['email'] : null,
            'customFields' => $cf,
        ];
    }

    /**
     * Company ("business") record: standard fields (name, address, city, state, postalCode, phone, website)
     * plus its custom fields under their plain keys (sed_code, dbn, official_school_name, borough, ...).
     * Empty values are dropped. Null when the record cannot be read.
     */
    public function company(string $id): ?array
    {
        $out = [];
        $r = $this->get('/objects/business/records/' . rawurlencode($id), ['locationId' => $this->cfg['location']], 'v3');
        $props = $r['record']['properties'] ?? null;
        if (is_array($props)) {
            foreach ($props as $k => $v) {
                if (is_array($v)) {
                    $v = $v['value'] ?? reset($v);
                }
                if ($v === null || $v === '' || !is_scalar($v)) {
                    continue;
                }
                $out[(string)$k] = trim((string)$v);
            }
        }
        $b = $this->get('/businesses/' . rawurlencode($id), [], '2021-07-28');
        $b = $b['business'] ?? null;
        if (is_array($b)) {
            foreach (['name', 'address', 'city', 'state', 'postalCode', 'country', 'phone', 'email', 'website'] as $k) {
                if (isset($b[$k]) && is_scalar($b[$k]) && trim((string)$b[$k]) !== '') {
                    $out[$k] = trim((string)$b[$k]);
                }
            }
            foreach ($b['customFields'] ?? [] as $f) {
                // [{key: "sed_code" | "business.sed_code", valueString|value}] - fills anything the objects call left out
                $key = preg_replace('/^business\./', '', (string)($f['key'] ?? $f['id'] ?? ''));
                $v = $f['valueString'] ?? $f['value'] ?? null;
                if ($key !== '' && !isset($out[$key]) && is_scalar($v) && trim((string)$v) !== '') {
                    $out[$key] = trim((string)$v);
                }
            }
        }
        return $out ?: null;
    }

    private function get(string $path, array $query, string $version): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $url = rtrim((string)($this->cfg['base'] ?? 'https://services.leadconnectorhq.com'), '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $t0 = microtime(true);
        try {
            [$status, $text] = Http::json('GET', $url, null, [
                'Authorization: Bearer ' . $this->cfg['token'],
                'Version: ' . $version,
                'User-Agent: fsn-smartsheet/1.0',
            ], (int)($this->cfg['timeout'] ?? 15));
        } catch (Throwable $e) {
            Log::warn('lp_call_failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $data = json_decode($text, true);
        if ($status >= 300 || !is_array($data)) {
            Log::warn('lp_call_failed', ['path' => $path, 'status' => $status, 'ms' => $ms, 'body' => substr($text, 0, 300)]);
            return null;
        }
        Log::info('lp_call', ['path' => $path, 'status' => $status, 'ms' => $ms]);
        return $data;
    }
}
