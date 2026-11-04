<?php
declare(strict_types=1);

/** Optional Slack incoming-webhook alert. Silent no-op when not configured. */
final class Alert
{
    private static string $url = '';

    public static function init(array $cfg): void
    {
        self::$url = (string)($cfg['slack_webhook'] ?? '');
    }

    public static function send(string $text): void
    {
        if (self::$url === '') {
            return;
        }
        try {
            Http::postJson(self::$url, ['text' => $text], [], 8);
        } catch (Throwable $e) {
            Log::warn('alert_failed', ['msg' => $e->getMessage()]);
        }
    }
}
