<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ReportRunRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @param array<string, mixed> $runData
     * @param array<int, array<string, mixed>> $issues
     */
    public function createRun(array $runData, array $issues): int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO report_runs (
                    release_name,
                    issues_count,
                    include_description,
                    dry_run,
                    slack_sent,
                    summary_text,
                    summary_mode,
                    summary_provider,
                    summary_model,
                    summary_fallback_used,
                    summary_raw_output,
                    message_preview,
                    jira_jql
                ) VALUES (
                    :release_name,
                    :issues_count,
                    :include_description,
                    :dry_run,
                    :slack_sent,
                    :summary_text,
                    :summary_mode,
                    :summary_provider,
                    :summary_model,
                    :summary_fallback_used,
                    CAST(:summary_raw_output AS JSONB),
                    :message_preview,
                    :jira_jql
                ) RETURNING id'
            );

            $statement->bindValue(':release_name', (string) $runData['release_name']);
            $statement->bindValue(':issues_count', (int) $runData['issues_count'], PDO::PARAM_INT);
            $statement->bindValue(':include_description', (bool) $runData['include_description'], PDO::PARAM_BOOL);
            $statement->bindValue(':dry_run', (bool) $runData['dry_run'], PDO::PARAM_BOOL);
            $statement->bindValue(':slack_sent', (bool) $runData['slack_sent'], PDO::PARAM_BOOL);
            $statement->bindValue(':summary_text', $this->sanitizeText((string) ($runData['summary_text'] ?? '')));
            $statement->bindValue(':summary_mode', $this->sanitizeText((string) ($runData['summary_mode'] ?? 'rule')));
            $statement->bindValue(
                ':summary_provider',
                $this->sanitizeNullableText(isset($runData['summary_provider']) ? (string) $runData['summary_provider'] : null)
            );
            $statement->bindValue(
                ':summary_model',
                $this->sanitizeNullableText(isset($runData['summary_model']) ? (string) $runData['summary_model'] : null)
            );
            $statement->bindValue(
                ':summary_fallback_used',
                (bool) ($runData['summary_fallback_used'] ?? false),
                PDO::PARAM_BOOL
            );
            $statement->bindValue(':summary_raw_output', $this->encodeJson($runData['summary_raw_output'] ?? null));
            $statement->bindValue(':message_preview', $this->sanitizeText((string) $runData['message_preview']));
            $statement->bindValue(':jira_jql', $this->sanitizeText((string) $runData['jira_jql']));
            $statement->execute();

            $reportRunId = (int) $statement->fetchColumn();

            foreach ($issues as $issue) {
                $this->insertIssueSnapshot($reportRunId, $issue);
            }

            $this->pdo->commit();

            return $reportRunId;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRuns(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        $statement = $this->pdo->prepare(
            'SELECT
                id,
                release_name,
                issues_count,
                include_description,
                dry_run,
                slack_sent,
                summary_mode,
                summary_provider,
                summary_model,
                summary_fallback_used,
                jira_jql,
                created_at
            FROM report_runs
            ORDER BY id DESC
            LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRunWithIssues(int $id): ?array
    {
        $runStatement = $this->pdo->prepare(
            'SELECT
                id,
                release_name,
                issues_count,
                include_description,
                dry_run,
                slack_sent,
                summary_text,
                summary_mode,
                summary_provider,
                summary_model,
                summary_fallback_used,
                summary_raw_output,
                message_preview,
                jira_jql,
                created_at
            FROM report_runs
            WHERE id = :id'
        );
        $runStatement->execute(['id' => $id]);

        $run = $runStatement->fetch();
        if (!is_array($run)) {
            return null;
        }

        $run['summary_raw_output'] = $this->decodeJsonColumn($run['summary_raw_output'] ?? null);

        $issueStatement = $this->pdo->prepare(
            'SELECT
                id,
                issue_key,
                summary,
                issue_type,
                status,
                assignee,
                description,
                raw_issue,
                created_at
            FROM report_run_issues
            WHERE report_run_id = :report_run_id
            ORDER BY id ASC'
        );
        $issueStatement->execute(['report_run_id' => $id]);

        $issues = [];
        foreach ($issueStatement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['raw_issue'] = $this->decodeJsonColumn($row['raw_issue'] ?? null);
            $issues[] = $row;
        }

        $run['issues'] = $issues;

        return $run;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestRun(?string $releaseName = null, ?bool $onlyUnsent = null): ?array
    {
        $sql = 'SELECT
                id,
                release_name,
                issues_count,
                include_description,
                dry_run,
                slack_sent,
                summary_text,
                summary_mode,
                summary_provider,
                summary_model,
                summary_fallback_used,
                summary_raw_output,
                message_preview,
                jira_jql,
                created_at
            FROM report_runs';

        $conditions = [];

        if ($releaseName !== null && trim($releaseName) !== '') {
            $conditions[] = 'release_name = :release_name';
        }

        if ($onlyUnsent === true) {
            $conditions[] = 'slack_sent = FALSE';
        } elseif ($onlyUnsent === false) {
            $conditions[] = 'slack_sent = TRUE';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        $statement = $this->pdo->prepare($sql);

        if ($releaseName !== null && trim($releaseName) !== '') {
            $statement->bindValue(':release_name', $releaseName);
        }

        $statement->execute();
        $run = $statement->fetch();

        if (!is_array($run)) {
            return null;
        }

        $run['summary_raw_output'] = $this->decodeJsonColumn($run['summary_raw_output'] ?? null);

        return $run;
    }

    public function markSlackSent(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE report_runs
            SET slack_sent = TRUE
            WHERE id = :id'
        );

        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function insertIssueSnapshot(int $reportRunId, array $issue): void
    {
        $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];

        $statement = $this->pdo->prepare(
            'INSERT INTO report_run_issues (
                report_run_id,
                issue_key,
                summary,
                issue_type,
                status,
                assignee,
                description,
                raw_issue
            ) VALUES (
                :report_run_id,
                :issue_key,
                :summary,
                :issue_type,
                :status,
                :assignee,
                :description,
                CAST(:raw_issue AS JSONB)
            )'
        );

        $rawIssue = json_encode(
            $this->sanitizeValueRecursive($issue),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($rawIssue === false) {
            throw new \RuntimeException('Unable to encode Jira issue snapshot to JSON');
        }

        $statement->execute([
            'report_run_id' => $reportRunId,
            'issue_key' => $this->sanitizeText((string) ($issue['key'] ?? 'UNKNOWN')),
            'summary' => $this->sanitizeText((string) ($fields['summary'] ?? '')),
            'issue_type' => $this->sanitizeText($this->nestedField($fields, ['issuetype', 'name']) ?? 'Unknown'),
            'status' => $this->sanitizeText($this->nestedField($fields, ['status', 'name']) ?? 'Unknown'),
            'assignee' => $this->sanitizeNullableText($this->nestedField($fields, ['assignee', 'displayName'])),
            'description' => $this->sanitizeNullableText($this->extractDescriptionText($fields['description'] ?? null)),
            'raw_issue' => $rawIssue,
        ]);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function nestedField(array $source, array $path): ?string
    {
        $current = $source;

        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        if (!is_string($current)) {
            return null;
        }

        $value = trim($current);

        return $value === '' ? null : $value;
    }

    private function extractDescriptionText(mixed $description): ?string
    {
        if (is_string($description)) {
            $value = trim($description);
            return $value === '' ? null : $value;
        }

        if (!is_array($description)) {
            return null;
        }

        $chunks = [];
        $stack = [$description];

        while ($stack !== []) {
            $node = array_pop($stack);

            if (!is_array($node)) {
                continue;
            }

            if (isset($node['text']) && is_string($node['text'])) {
                $chunks[] = $node['text'];
            }

            $content = $node['content'] ?? null;
            if (is_array($content)) {
                foreach (array_reverse($content) as $child) {
                    $stack[] = $child;
                }
            }
        }

        $value = trim(preg_replace('/\s+/u', ' ', implode(' ', $chunks)) ?? '');

        return $value === '' ? null : $value;
    }

    private function decodeJsonColumn(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return $decoded === null && strtolower($value) !== 'null' ? $value : $decoded;
    }

    private function sanitizeNullableText(?string $value): ?string
    {
        return $value === null ? null : $this->sanitizeText($value);
    }

    private function encodeJson(mixed $value): string
    {
        $json = json_encode(
            $this->sanitizeValueRecursive($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new \RuntimeException('Unable to encode JSON column');
        }

        return $json;
    }

    private function sanitizeText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x{00A0}-\x{10FFFF}]/u', '', $value) ?? '';
    }

    private function sanitizeValueRecursive(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeText($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            $result[$key] = $this->sanitizeValueRecursive($item);
        }

        return $result;
    }
}
