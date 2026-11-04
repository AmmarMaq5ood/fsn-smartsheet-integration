<?php
declare(strict_types=1);

/**
 * JSON-lines logger. Log files end in .php and begin with a guard line,
 * so nginx + php-fpm executes the guard and never serves the contents.
 */
final class Log
{
    private static string $dir = '';
    private static string $reqId = '';
    private const GUARD = "<?php http_response_code(403); exit; ?>\n";

    public static function init(array $cfg): void
    {
        self::$dir = $cfg['dir'] ?? (APP_ROOT . '/logs');
        self::$reqId = substr(bin2hex(random_bytes(6)), 0, 10);
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0750, true);
        }
    }

    public static function requestId(): string
    {
        return self::$reqId;
    }

    public static function info(string $event, array $ctx = []): void
    {
        self::write('info', $event, $ctx);
    }

    public static function warn(string $event, array $ctx = []): void
    {
        self::write('warn', $event, $ctx);
    }

    public static function error(string $event, array $ctx = []): void
    {
        self::write('error', $event, $ctx);
    }

    private static function write(string $level, string $event, array $ctx): void
    {
        $file = self::$dir . '/app-' . gmdate('Y-m') . '.log.php';
        $line = json_encode(
            ['t' => gmdate('c'), 'rid' => self::$reqId, 'lvl' => $level, 'ev' => $event, 'ctx' => $ctx],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
        if (!is_file($file)) {
            @file_put_contents($file, self::GUARD, LOCK_EX);
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        Store::log(self::$reqId, $level, $event, $ctx);
    }
}
