<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Env::get('DB_HOST', 'postgres') ?? 'postgres';
        $port = Env::get('DB_PORT', '5432') ?? '5432';
        $database = Env::get('DB_DATABASE', 'jira_release_bot') ?? 'jira_release_bot';
        $username = Env::get('DB_USERNAME', 'jira_release_bot') ?? 'jira_release_bot';
        $password = Env::get('DB_PASSWORD', 'jira_release_bot') ?? 'jira_release_bot';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$connection;
    }
}

