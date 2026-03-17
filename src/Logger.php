<?php

declare(strict_types=1);

namespace App;

final class Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $json = json_encode([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        error_log($json !== false ? $json : sprintf('[%s] %s', $level, $message));
    }
}

