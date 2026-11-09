<?php
declare(strict_types=1);

/**
 * One LP event end to end: log it, store it, take the per-contact lock, run Sync, record the outcome.
 * Used by lp.php (live webhook) and dashboard.php (replay of a stored event).
 */
final class Pipeline
{
    /**
     * @param string         $source   webhook | replay
     * @param int|null       $replayOf event id this run replays
     * @param Smartsheet|null $ss      injected client (tests); built from config when null
     * @return array Sync result plus 'eventId'
     * @throws Throwable when the sync fails; the failure is recorded in the store before rethrowing
     */
    public static function run(array $config, string $sheetKey, array $data, string $source = 'webhook', ?int $replayOf = null, ?Smartsheet $ss = null): array
    {
        if (!isset($config['sheets'][$sheetKey])) {
            throw new InvalidArgumentException("unknown sheet '$sheetKey'");
        }
        $p = new Payload($data);
        Log::info('inbound', [
            'sheet'   => $sheetKey,
            'source'  => $source,
            'contact' => $p->get('contact_id'),
            'name'    => $p->rule('fullName'),
            'company' => $p->get('company_name'),
            'doc'     => $p->rule('sowName'),
            'url'     => $p->rule('sowUrl'),
            'opp'     => $p->get('customData."LP Opportunity ID"'),
            'trigger' => $p->get('workflow.name'),
        ]);
        if (!empty($config['log']['full_payload'])) {
            Log::info('payload', ['sheet' => $sheetKey, 'data' => $data]);
        }
        $eventId = Store::begin(Log::requestId(), $sheetKey, $p, $data, $source, $replayOf);
        $t0 = microtime(true);

        // One event per contact at a time. LP fires "sent", "URL changed" and "signed" within seconds of
        // each other, and a replay delivers dozens at once; without this, two parallel requests both miss
        // the row and both create it (the Nov 4 2026 replay made 8 duplicate rows that way).
        $lockDir = rtrim((string)($config['lock_dir'] ?? (APP_ROOT . '/cache/locks')), '/\\');
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lockName = preg_replace('/[^A-Za-z0-9_-]/', '_', $sheetKey . '-' . ($p->get('contact_id') ?? 'none'));
        $lock = @fopen("$lockDir/$lockName.lock", 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }

        try {
            $ss ??= new Smartsheet($config['smartsheet']);
            $sync = new Sync($ss, (bool)($config['dry_run'] ?? true));
            $result = $sync->run($sheetKey, $config['sheets'][$sheetKey], $p);
        } catch (Throwable $e) {
            Store::fail($eventId, get_class($e) . ': ' . $e->getMessage(), (int)round((microtime(true) - $t0) * 1000));
            throw $e;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        Store::finish($eventId, $result, (int)round((microtime(true) - $t0) * 1000));
        $result['eventId'] = $eventId;
        // Slack etc. Logged under the same request id, so the dashboard shows it with the event.
        $result['notified'] = Notify::run($config['sheets'][$sheetKey]['notify'] ?? [], $sheetKey, $p, $result, (bool)($config['dry_run'] ?? true));
        return $result;
    }
}
