<?php

declare(strict_types=1);

namespace App\Support;

final class ReporterIdExtractor
{
    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, string>
     */
    public function fromJiraIssues(array $issues): array
    {
        return $this->extract($issues, false);
    }

    /**
     * @param array<int, array<string, mixed>> $snapshots
     * @return array<int, string>
     */
    public function fromReportRunSnapshots(array $snapshots): array
    {
        return $this->extract($snapshots, true);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function extract(array $items, bool $unwrapRawIssue): array
    {
        $jiraUserIds = [];

        foreach ($items as $item) {
            $issue = $unwrapRawIssue && is_array($item['raw_issue'] ?? null)
                ? $item['raw_issue']
                : $item;
            $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
            $reporter = is_array($fields['reporter'] ?? null) ? $fields['reporter'] : [];
            $accountId = trim((string) ($reporter['accountId'] ?? ''));

            if ($accountId !== '') {
                $jiraUserIds[] = $accountId;
            }
        }

        return array_values(array_unique($jiraUserIds));
    }
}
