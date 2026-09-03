<?php

declare(strict_types=1);

namespace App\Client;

use App\Contracts\JiraClientInterface;
use App\DTO\JiraSearchResult;
use App\Support\Env;
use App\Support\Logger;

final class JiraClient implements JiraClientInterface
{
    private const ISSUE_FIELDS = 'summary,description,issuetype,status,assignee,reporter,labels,components,parent,subtasks';

    private HttpClient $httpClient;
    private string $baseUrl;
    private string $email;
    private string $apiToken;
    private string $projectKey;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $projectVersions = null;

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

        return $result->issues;
    }

    /**
     */
    public function searchIssuesByRelease(string $release): JiraSearchResult
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
                'fields' => self::ISSUE_FIELDS,
                'maxResults' => $maxResults,
                'startAt' => $startAt,
            ]);

            $decoded = $this->requestJson('/rest/api/3/search/jql?' . $query);

            $pageIssues = $decoded['issues'];
            $issues = array_merge($issues, $pageIssues);
            $startAt += count($pageIssues);
            $total = (int) ($decoded['total'] ?? count($issues));
        } while ($startAt < $total && $pageIssues !== []);

        $issues = $this->attachParentHierarchy($issues);

        return new JiraSearchResult($issues, count($issues), $jql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProjectVersions(): array
    {
        if ($this->projectVersions !== null) {
            return $this->projectVersions;
        }

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

        $this->projectVersions = $versions;

        return $this->projectVersions;
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

    public function getNextReleaseName(string $currentRelease): ?string
    {
        $currentRelease = trim($currentRelease);
        if ($currentRelease === '') {
            return null;
        }

        $releaseNames = [];

        foreach ($this->getProjectVersions() as $version) {
            $name = trim((string) ($version['name'] ?? ''));
            if ($name === '' || (bool) ($version['archived'] ?? false)) {
                continue;
            }

            $releaseNames[] = $name;
        }

        $releaseNames = array_values(array_unique($releaseNames));
        sort($releaseNames, SORT_NATURAL | SORT_FLAG_CASE);

        $currentIndex = array_search($currentRelease, $releaseNames, true);
        if ($currentIndex === false) {
            return null;
        }

        return $releaseNames[$currentIndex + 1] ?? null;
    }

    public function getReleaseUrlByName(string $release): ?string
    {
        $release = trim($release);
        if ($release === '') {
            return null;
        }

        foreach ($this->getProjectVersions() as $version) {
            if (trim((string) ($version['name'] ?? '')) !== $release) {
                continue;
            }

            return $this->buildReleaseUrl($version);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $version
     */
    public function buildReleaseUrl(array $version): ?string
    {
        $versionId = trim((string) ($version['id'] ?? ''));
        if ($versionId === '') {
            return null;
        }

        return sprintf(
            '%s/projects/%s/versions/%s/tab/release-report-all-issues',
            $this->baseUrl,
            rawurlencode($this->projectKey),
            rawurlencode($versionId)
        );
    }

    private function escapeJqlValue(string $value): string
    {
        return str_replace('"', '\\"', $value);
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function attachParentHierarchy(array $issues): array
    {
        $issueKeys = [];
        foreach ($issues as $issue) {
            $key = trim((string) ($issue['key'] ?? ''));
            if ($key !== '') {
                $issueKeys[$key] = true;
            }
        }

        $missingParentKeys = [];
        foreach ($issues as $issue) {
            $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
            $parent = is_array($fields['parent'] ?? null) ? $fields['parent'] : null;
            if ($parent === null || $this->isEpicIssue($parent)) {
                continue;
            }

            $parentKey = trim((string) ($parent['key'] ?? ''));
            if ($parentKey !== '' && !isset($issueKeys[$parentKey])) {
                $missingParentKeys[$parentKey] = true;
            }
        }

        if ($missingParentKeys === []) {
            return $issues;
        }

        $parentIssues = $this->searchIssuesByKeys(array_keys($missingParentKeys));
        if ($parentIssues === []) {
            return $issues;
        }

        foreach ($issues as $index => $issue) {
            $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
            $parent = is_array($fields['parent'] ?? null) ? $fields['parent'] : null;
            $parentKey = trim((string) ($parent['key'] ?? ''));
            $parentIssue = $parentKey !== '' ? ($parentIssues[$parentKey] ?? null) : null;
            if (!is_array($parentIssue)) {
                continue;
            }

            $parentFields = is_array($parentIssue['fields'] ?? null) ? $parentIssue['fields'] : [];
            $grandParent = is_array($parentFields['parent'] ?? null) ? $parentFields['parent'] : null;
            if ($grandParent === null || !$this->isEpicIssue($grandParent)) {
                continue;
            }

            $fields['_release_container_parent'] = [
                'key' => $parentKey,
                'fields' => [
                    'summary' => $parentFields['summary'] ?? '',
                    'status' => $parentFields['status'] ?? null,
                    'issuetype' => $parentFields['issuetype'] ?? null,
                ],
            ];
            $fields['parent'] = $grandParent;
            $issues[$index]['fields'] = $fields;
        }

        return $issues;
    }

    /**
     * @param array<int, string> $issueKeys
     * @return array<string, array<string, mixed>>
     */
    private function searchIssuesByKeys(array $issueKeys): array
    {
        $issueKeys = array_values(array_unique(array_filter(
            array_map(static fn (string $key): string => trim($key), $issueKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($issueKeys === []) {
            return [];
        }

        $issues = [];
        foreach (array_chunk($issueKeys, 50) as $chunk) {
            $quotedKeys = array_map(
                fn (string $key): string => '"' . $this->escapeJqlValue($key) . '"',
                $chunk
            );
            $jql = sprintf('key IN (%s)', implode(',', $quotedKeys));
            $query = http_build_query([
                'jql' => $jql,
                'fields' => self::ISSUE_FIELDS,
                'maxResults' => count($chunk),
                'startAt' => 0,
            ]);

            $decoded = $this->requestJson('/rest/api/3/search/jql?' . $query);
            foreach (($decoded['issues'] ?? []) as $issue) {
                if (!is_array($issue)) {
                    continue;
                }

                $key = trim((string) ($issue['key'] ?? ''));
                if ($key !== '') {
                    $issues[$key] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function isEpicIssue(array $issue): bool
    {
        $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];
        $issueType = is_array($fields['issuetype'] ?? null) ? $fields['issuetype'] : [];

        return strcasecmp(trim((string) ($issueType['name'] ?? '')), 'Эпик') === 0
            || strcasecmp(trim((string) ($issueType['name'] ?? '')), 'Epic') === 0;
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
