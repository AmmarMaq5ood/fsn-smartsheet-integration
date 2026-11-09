<?php
declare(strict_types=1);

/**
 * Slack notifications driven by per-sheet `notify` rules in config.php:
 *
 *   ['name' => 'PM SOWs', 'when_doc' => ['program manager'], 'on' => ['sent', 'signed'],
 *    'slack_webhook' => 'https://hooks.slack.com/services/...', 'title' => 'Program Manager SOW']
 *
 * Runs after a successful sync (create or update). A rule with an empty webhook is off.
 * Never throws: a Slack failure is logged (and therefore visible in the dashboard) but does not
 * fail the webhook that LP is waiting on.
 */
final class Notify
{
    /** @var callable|null fn(string $url, array $body): array{int,string}; tests inject a fake poster */
    public static $poster = null;

    /** @return list<array{rule:string,event:string,ok:bool,status:int,dry?:bool}> */
    public static function run(array $rules, string $sheetKey, Payload $p, array $result, bool $dryRun): array
    {
        $out = [];
        if (!$rules || !in_array($result['action'] ?? '', ['create', 'update'], true)) {
            return $out;
        }
        $event = self::event($p);
        foreach ($rules as $i => $r) {
            $name = (string)($r['name'] ?? ('rule ' . ($i + 1)));
            if (empty($r['slack_webhook'])) {
                continue;
            }
            if (!empty($r['when_doc']) && !$p->docNameContains((array)$r['when_doc'])) {
                continue;
            }
            if (!empty($r['on']) && !in_array($event, (array)$r['on'], true)) {
                continue;
            }
            $body = self::slackMessage($r, $event, $p, $result);
            if ($dryRun) {
                Log::info('notify_dry', ['rule' => $name, 'event' => $event, 'text' => $body['text']]);
                $out[] = ['rule' => $name, 'event' => $event, 'ok' => true, 'status' => 0, 'dry' => true];
                continue;
            }
            $status = 0;
            $ok = false;
            try {
                $post = self::$poster ?? [Http::class, 'postJson'];
                [$status, $text] = $post((string)$r['slack_webhook'], $body, [], 15);
                $status = (int)$status;
                $ok = $status >= 200 && $status < 300;
                if ($ok) {
                    Log::info('notify_sent', ['rule' => $name, 'event' => $event, 'sheet' => $sheetKey, 'text' => $body['text']]);
                } else {
                    Log::error('notify_failed', ['rule' => $name, 'event' => $event, 'status' => $status, 'body' => substr((string)$text, 0, 300)]);
                }
            } catch (Throwable $e) {
                Log::error('notify_failed', ['rule' => $name, 'event' => $event, 'msg' => $e->getMessage()]);
            }
            $out[] = ['rule' => $name, 'event' => $event, 'ok' => $ok, 'status' => $status];
        }
        return $out;
    }

    /** "signed" when the LP workflow that fired is a Doc Signed one, otherwise "sent". */
    public static function event(Payload $p): string
    {
        $wf = mb_strtolower((string)($p->get('workflow.name') ?? ''));
        return str_contains($wf, 'signed') ? 'signed' : 'sent';
    }

    private static function slackMessage(array $r, string $event, Payload $p, array $result): array
    {
        $title = (string)($r['title'] ?? 'SOW');
        $doc   = $p->rule('sowName') ?? 'document';
        $url   = $p->rule('sowUrl');
        $who   = $p->rule('fullName') ?? 'unknown contact';
        $email = $p->first('email', 'customData."LP Email"');
        $site  = $p->first('customData."LP Business Name"', 'company_name');
        $verb  = $event === 'signed' ? 'signed' : 'sent for signature';
        $emoji = $event === 'signed' ? ':white_check_mark:' : ':page_facing_up:';

        $lines = array_filter([
            "*$doc*",
            'Contact: ' . $who . ($email ? " ($email)" : ''),
            $site ? "School / Site: $site" : null,
            $url ? "<$url|Open document>" : null,
            'Smartsheet row ' . (($result['action'] ?? '') === 'create' ? 'created' : 'updated') . (!empty($result['rowId']) ? " ({$result['rowId']})" : ''),
        ]);

        return [
            'text'   => "$emoji $title $verb: $doc",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_strimwidth("$title $verb", 0, 150, '…'), 'emoji' => true]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)]],
            ],
        ];
    }
}
