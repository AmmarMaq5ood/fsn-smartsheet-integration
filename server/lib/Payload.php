<?php
declare(strict_types=1);

/**
 * Helpers over the LP webhook payload: path access, templates, and the business rules
 * that used to live in Make filters (staff gate, school-name fallback).
 */
final class Payload
{
    private array $d;

    public function __construct(array $data)
    {
        $this->d = $data;
    }

    public function raw(): array
    {
        return $this->d;
    }

    /** Dot path, with literal dots allowed inside segments when the segment is quoted: `customData."LP Email"` */
    public function get(string $path): ?string
    {
        $segments = self::splitPath($path);
        $cur = $this->d;
        foreach ($segments as $s) {
            if (!is_array($cur) || !array_key_exists($s, $cur)) {
                return null;
            }
            $cur = $cur[$s];
        }
        if ($cur === null || is_array($cur)) {
            return null;
        }
        $v = trim((string)$cur);
        return $v === '' ? null : $v;
    }

    /** First non-empty value among several paths. */
    public function first(string ...$paths): ?string
    {
        foreach ($paths as $p) {
            $v = $this->get($p);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Resolve a mapping value:
     *   "literal text"                  -> as is
     *   "{{path}}"                      -> value at path (null if empty)
     *   "{{a}} and {{b}}"               -> interpolated, null if every token is empty
     *   ['first' => ['p1','p2',...]]    -> first non-empty
     *   ['rule' => 'schoolName']        -> named rule below
     * Array specs may add a document-name guard; when it fails the value resolves to null:
     *   ['rule' => 'sowUrl', 'if_doc' => ['work order']]      -> only when the document name contains a keyword
     *   ['rule' => 'sowUrl', 'unless_doc' => ['work order']]  -> only when it contains none of them
     */
    public function resolve(mixed $spec): ?string
    {
        if (is_array($spec)) {
            if (isset($spec['if_doc']) && !$this->docNameContains((array)$spec['if_doc'])) {
                return null;
            }
            if (isset($spec['unless_doc']) && $this->docNameContains((array)$spec['unless_doc'])) {
                return null;
            }
            if (isset($spec['first'])) {
                return $this->first(...$spec['first']);
            }
            if (isset($spec['rule'])) {
                return $this->rule((string)$spec['rule']);
            }
            return null;
        }
        $spec = (string)$spec;
        if (!str_contains($spec, '{{')) {
            return $spec;
        }
        $any = false;
        $out = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/u', function ($m) use (&$any) {
            $v = $this->get($m[1]);
            if ($v !== null) {
                $any = true;
            }
            return $v ?? '';
        }, $spec);
        return $any ? trim((string)$out) : null;
    }

    public function rule(string $name): ?string
    {
        return match ($name) {
            'schoolName'  => $this->schoolName(),
            'fullName'    => $this->first('full_name') ?? trim(($this->get('first_name') ?? '') . ' ' . ($this->get('last_name') ?? '')) ?: null,
            'contactId'   => $this->get('contact_id'),
            'sowUrl'      => $this->first('customData."LP SOW Sent"', 'SOW Document URL'),
            'sowName'     => $this->first('customData."LP SOW Name"', 'SOW Document Name'),
            default       => throw new RuntimeException("Unknown rule: $name"),
        };
    }

    /** Official School Name -> "School name:" -> Business Name -> Organization Name */
    public function schoolName(): ?string
    {
        return $this->first(
            'Official School Name',
            'School name:',
            'customData."LP Business Name"',
            'company_name',
            'Organization Name'
        );
    }

    /** True when the document name reads like a staff / contractor agreement. Empty name = not staff. */
    public function isStaffDocument(array $keywords): bool
    {
        return $this->docNameContains($keywords);
    }

    /** True when the document name contains any keyword (case-insensitive). Empty name = false. */
    public function docNameContains(array $keywords): bool
    {
        $name = mb_strtolower((string)($this->rule('sowName') ?? ''));
        if ($name === '') {
            return false;
        }
        foreach ($keywords as $k) {
            if (str_contains($name, mb_strtolower($k))) {
                return true;
            }
        }
        return false;
    }

    private static function splitPath(string $path): array
    {
        $out = [];
        if (preg_match_all('/"([^"]*)"|([^.]+)/', $path, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = $x[1] !== '' ? $x[1] : $x[2];
            }
        }
        return $out;
    }
}
