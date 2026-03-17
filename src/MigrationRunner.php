<?php

declare(strict_types=1);

namespace App;

use PDO;

final class MigrationRunner
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @return array<string, mixed>
     */
    public function migrate(): array
    {
        $migrationFiles = glob(dirname(__DIR__) . '/migrations/*.sql');
        if ($migrationFiles === false) {
            throw new \RuntimeException('Unable to list migration files');
        }

        sort($migrationFiles);

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) PRIMARY KEY,
                executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
        );

        $appliedVersions = $this->fetchAppliedVersions();
        $executed = [];

        foreach ($migrationFiles as $file) {
            $version = basename($file);

            if (in_array($version, $appliedVersions, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException(sprintf('Unable to read migration file: %s', $file));
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, executed_at) VALUES (:version, NOW())'
                );
                $statement->execute(['version' => $version]);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }

            $executed[] = $version;
        }

        return [
            'executed' => $executed,
            'count' => count($executed),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fetchAppliedVersions(): array
    {
        $statement = $this->pdo->query('SELECT version FROM schema_migrations');

        return array_map(
            static fn (array $row): string => (string) $row['version'],
            $statement->fetchAll()
        );
    }
}

