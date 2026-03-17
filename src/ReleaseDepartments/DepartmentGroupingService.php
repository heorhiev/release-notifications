<?php

declare(strict_types=1);

namespace App\ReleaseDepartments;

use App\ReleaseDepartments\DTO\DepartmentGroup;
use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\Support\ReleaseIssueMapper;

final class DepartmentGroupingService
{
    private ReleaseIssueMapper $issueMapper;

    /**
     * @var array<string, array{title:string, keywords:array<int, string>}>
     */
    private array $definitions = [
        'support' => [
            'title' => 'Support',
            'keywords' => [
                'support', 'саппорт', 'support team', 'invite', 'invite-message', 'message', 'contact',
                'диспут', 'dispute', 'banner', 'баннер', 'notification', 'support message',
            ],
        ],
        'internal_publishers' => [
            'title' => 'Internal Publishers',
            'keywords' => [
                'internal publisher', 'internal publishers', 'publisher', 'паблишер', 'паблишерам',
                '1s1p', '1 site 1 publisher', 'performer', 'site options', 'link insertion',
            ],
        ],
        'moderation' => [
            'title' => 'Moderation',
            'keywords' => [
                'moderation', 'модерац', 'task review', 'review', 'in moderation', 'pre-moderation',
                'реджект', 'reject', 'sanctions', 'диспут', 'approve', 'апрув', 'category',
            ],
        ],
        'other' => [
            'title' => 'Other',
            'keywords' => [],
        ],
    ];

    public function __construct(?ReleaseIssueMapper $issueMapper = null)
    {
        $this->issueMapper = $issueMapper ?? new ReleaseIssueMapper();
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, DepartmentGroup>
     */
    public function groupRawIssues(array $issues): array
    {
        return $this->groupIssues($this->issueMapper->mapMany($issues));
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @return array<int, DepartmentGroup>
     */
    public function groupIssues(array $issues): array
    {
        $grouped = [
            'support' => [],
            'internal_publishers' => [],
            'moderation' => [],
            'other' => [],
        ];

        foreach ($issues as $issue) {
            $department = $this->classify($issue);
            $grouped[$department][] = $issue;
        }

        $result = [];

        foreach (['support', 'internal_publishers', 'moderation', 'other'] as $code) {
            if ($grouped[$code] === []) {
                continue;
            }

            $result[] = new DepartmentGroup(
                code: $code,
                title: $this->definitions[$code]['title'],
                issues: $grouped[$code]
            );
        }

        return $result;
    }

    private function classify(ReleaseIssue $issue): string
    {
        $haystack = $this->normalize(
            implode(' ', [
                $issue->summary,
                $issue->description ?? '',
                implode(' ', $issue->labels),
                implode(' ', $issue->components),
                $issue->issueType,
            ])
        );

        $scores = [
            'support' => 0,
            'internal_publishers' => 0,
            'moderation' => 0,
        ];

        foreach (['support', 'internal_publishers', 'moderation'] as $code) {
            foreach ($this->definitions[$code]['keywords'] as $keyword) {
                if (str_contains($haystack, $this->normalize($keyword))) {
                    $scores[$code]++;
                }
            }
        }

        arsort($scores);
        $bestCode = (string) array_key_first($scores);
        $bestScore = $scores[$bestCode] ?? 0;

        return $bestScore > 0 ? $bestCode : 'other';
    }

    private function normalize(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}

