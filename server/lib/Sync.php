<?php
declare(strict_types=1);

/**
 * Find-or-create engine. One call per (sheet config, payload).
 *
 * Sheet config keys:
 *   sheet          ['id' => 123] or ['name' => 'Educator SOWs']
 *   require        value spec; when it resolves empty the event is skipped (e.g. no SOW link)
 *   reject_emails  list of contact emails to ignore (internal countersigners such as Rich)
 *   reject_if_staff bool, uses staff_keywords against the document name
 *   reject_doc_keywords list; skip when the document name contains any of them (e.g. work orders)
 *   require_doc_keywords list; skip unless the document name contains one of them
 *   staff_keywords  list of lowercase substrings
 *   find           ordered list of ['equals' => [title => spec], 'empty' => [title, ...]]
 *   create         bool, create a row when nothing is found
 *   map            [title => spec] written on both update and create
 *   map_on_create  [title => spec] written only when creating
 *   write_empty    bool, when false empty values are not written on update
 */
final class Sync
{
    public function __construct(private Smartsheet $ss, private bool $dryRun)
    {
    }

    public function run(string $name, array $cfg, Payload $p): array
    {
        $out = ['sheet' => $name, 'action' => 'skipped', 'reason' => null, 'rowId' => null, 'dryRun' => $this->dryRun];

        if (isset($cfg['require']) && $p->resolve($cfg['require']) === null) {
            $out['reason'] = 'required value missing';
            Log::info('sync_skip', $out);
            return $out;
        }
        // Internal signers (LP fires Doc Signed once per signer; Rich countersigns every SOW).
        if (!empty($cfg['reject_emails'])) {
            $email = mb_strtolower((string)($p->first('email', 'customData."LP Email"') ?? ''));
            if ($email !== '' && in_array($email, array_map('mb_strtolower', (array)$cfg['reject_emails']), true)) {
                $out['reason'] = 'internal signer';
                Log::info('sync_skip', $out + ['email' => $email]);
                return $out;
            }
        }
        if (!empty($cfg['reject_if_staff']) && $p->isStaffDocument($cfg['staff_keywords'] ?? [])) {
            $out['reason'] = 'staff document';
            Log::info('sync_skip', $out + ['doc' => $p->rule('sowName')]);
            return $out;
        }

        if (!empty($cfg['reject_doc_keywords']) && $p->docNameContains($cfg['reject_doc_keywords'])) {
            $out['reason'] = 'document type excluded';
            Log::info('sync_skip', $out + ['doc' => $p->rule('sowName')]);
            return $out;
        }

        if (!empty($cfg['require_doc_keywords']) && !$p->docNameContains($cfg['require_doc_keywords'])) {
            $out['reason'] = 'document type not for this sheet';
            Log::info('sync_skip', $out + ['doc' => $p->rule('sowName')]);
            return $out;
        }

        $sheetId = $this->ss->sheetId($cfg['sheet']);
        $rowId = null;
        $matchedBy = null;
        foreach ($cfg['find'] ?? [] as $i => $strategy) {
            $equals = [];
            $usable = true;
            foreach ($strategy['equals'] ?? [] as $title => $spec) {
                $v = $p->resolve($spec);
                if ($v === null) {
                    $usable = false;
                    break;
                }
                $equals[$title] = $v;
            }
            if (!$usable || !$equals) {
                continue;
            }
            $rowId = $this->ss->findRow($sheetId, $equals, $strategy['empty'] ?? []);
            if ($rowId !== null) {
                $matchedBy = $strategy['label'] ?? ('strategy ' . ($i + 1));
                break;
            }
        }

        $cells = $this->resolveMap($cfg['map'] ?? [], $p);

        if ($rowId !== null) {
            if (empty($cfg['write_empty'])) {
                $cells = array_filter($cells, fn($v) => $v !== null && $v !== '');
            }
            $out = array_merge($out, ['action' => 'update', 'rowId' => $rowId, 'matchedBy' => $matchedBy, 'cells' => count($cells)]);
            if (!$this->dryRun) {
                $this->ss->updateRow($sheetId, $rowId, $cells);
            }
            Log::info('sync_update', $out + ['values' => $cells]);
            return $out;
        }

        if (empty($cfg['create'])) {
            $out['reason'] = 'no match and create disabled';
            Log::info('sync_skip', $out);
            return $out;
        }

        $cells = array_merge($cells, $this->resolveMap($cfg['map_on_create'] ?? [], $p));
        $cells = array_filter($cells, fn($v) => $v !== null && $v !== '');
        $out = array_merge($out, ['action' => 'create', 'cells' => count($cells)]);
        if (!$this->dryRun) {
            $out['rowId'] = $this->ss->addRow($sheetId, $cells);
        }
        Log::info('sync_create', $out + ['values' => $cells]);
        return $out;
    }

    private function resolveMap(array $map, Payload $p): array
    {
        $cells = [];
        foreach ($map as $title => $spec) {
            $cells[$title] = $p->resolve($spec);
        }
        return $cells;
    }
}
