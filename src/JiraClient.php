<?php

declare(strict_types=1);

namespace App;

final class JiraClient
{
    private HttpClient $httpClient;
    private string $baseUrl;
    private string $email;
    private string $apiToken;
    private string $projectKey;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->baseUrl = rtrim(Env::require('JIRA_BASE_URL'), '/');
        $this->email = Env::require('JIRA_EMAIL');
        $this->apiToken = Env::require('JIRA_API_TOKEN');
        $this->projectKey = Env::require('JIRA_PROJECT_KEY');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIssuesByRelease(string $release): array
    {
        $result = $this->searchIssuesByRelease($release);

        return $result['issues'];
    }

    /**
     * @return array{issues:array<int, array<string, mixed>>, total:int, jql:string}
     */
    public function searchIssuesByRelease(string $release): array
    {
        $jql = sprintf(
            'project = "%s" AND fixVersion = "%s" ORDER BY issuetype ASC, key ASC',
            $this->escapeJqlValue($this->projectKey),
            $this->escapeJqlValue($release)
        );

        $issues = [];
        $startAt = 0;
        $maxResults = 100;

        do {
            $query = http_build_query([
                'jql' => $jql,
                'fields' => 'summary,description,issuetype,status,assignee,labels,components',
                'maxResults' => $maxResults,
                'startAt' => $startAt,
            ]);

            $decoded = $this->requestJson('/rest/api/3/search/jql?' . $query);

            $pageIssues = $decoded['issues'];
            $issues = array_merge($issues, $pageIssues);
            $startAt += count($pageIssues);
            $total = (int) ($decoded['total'] ?? count($issues));
        } while ($startAt < $total && $pageIssues !== []);

        return [
            'issues' => $issues,
            'total' => count($issues),
            'jql' => $jql,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProjectVersions(): array
    {
        $startAt = 0;
        $maxResults = 50;
        $versions = [];

        do {
            $query = http_build_query([
                'startAt' => $startAt,
                'maxResults' => $maxResults,
                'orderBy' => 'sequence',
            ]);

            $decoded = $this->requestJson(
                sprintf('/rest/api/3/project/%s/version?%s', rawurlencode($this->projectKey), $query)
            );

            $pageValues = $decoded['values'] ?? [];
            if (!is_array($pageValues)) {
                throw new \RuntimeException('Unexpected Jira versions response format');
            }

            foreach ($pageValues as $version) {
                if (is_array($version)) {
                    $versions[] = $version;
                }
            }

            $isLast = (bool) ($decoded['isLast'] ?? true);
            $startAt += count($pageValues);
        } while (!$isLast && $pageValues !== []);

        return $versions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestReleaseVersion(): array
    {
        $versions = array_values(array_filter(
            $this->getProjectVersions(),
            static fn (array $version): bool => !((bool) ($version['archived'] ?? false))
        ));

        if ($versions === []) {
            throw new \RuntimeException(sprintf('No non-archived Jira versions found for project %s', $this->projectKey));
        }

        usort($versions, function (array $left, array $right): int {
            $leftRank = $this->versionRank($left);
            $rightRank = $this->versionRank($right);

            return $rightRank <=> $leftRank;
        });

        return $versions[0];
    }

    public function getLatestReleaseName(): string
    {
        $version = $this->getLatestReleaseVersion();
        $name = trim((string) ($version['name'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException('Latest Jira version does not have a valid name');
        }

        return $name;
    }

    private function escapeJqlValue(string $value): string
    {
        return str_replace('"', '\\"', $value);
    }

    /**
     * @param array<string, mixed> $version
     */
    private function versionRank(array $version): int
    {
        $released = (bool) ($version['released'] ?? false);
        $sequence = (int) ($version['sequence'] ?? 0);
        $releaseDate = $this->parseDateToTimestamp($version['releaseDate'] ?? null);
        $startDate = $this->parseDateToTimestamp($version['startDate'] ?? null);
        $id = (int) ($version['id'] ?? 0);

        return (
            (($released ? 0 : 1) * 10_000_000_000) +
            ($sequence * 1_000_000) +
            ($releaseDate * 100) +
            max($startDate, 0) +
            $id
        );
    }

    private function parseDateToTimestamp(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : $timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $path): array
    {
        $response = $this->httpClient->request(
            'GET',
            $this->baseUrl . $path,
            [
                'Authorization' => 'Basic ' . base64_encode($this->email . ':' . $this->apiToken),
                'Accept' => 'application/json',
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            Logger::error('Jira request failed', [
                'status' => $response['status'],
                'path' => $path,
                'body' => $response['body'],
            ]);
            throw new \RuntimeException(sprintf('Jira returned HTTP %d: %s', $response['status'], $response['body']));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            Logger::error('Jira response had invalid JSON', [
                'path' => $path,
                'body' => $response['body'],
            ]);
            throw new \RuntimeException('Unexpected Jira response format');
        }

        return $decoded;
    }
}
