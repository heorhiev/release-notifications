<?php

declare(strict_types=1);

namespace App\ReleaseSummary\RuleBased;

use App\ReleaseSummary\Contracts\SummaryGeneratorInterface;
use App\ReleaseSummary\DTO\ReleaseIssue;
use App\ReleaseSummary\DTO\SummaryResult;

final class RuleBasedSummaryGenerator implements SummaryGeneratorInterface
{
    /**
     * @param array<int, ReleaseIssue> $issues
     */
    public function generate(string $release, array $issues): SummaryResult
    {
        if ($issues === []) {
            return new SummaryResult(
                mode: $this->mode(),
                text: '- В этом релизе не найдено задач Jira.',
                bullets: ['В этом релизе не найдено задач Jira.'],
                meta: ['issues_count' => 0, 'provider' => 'rule'],
                rawOutput: ['provider' => 'rule', 'issues_count' => 0]
            );
        }

        $typeCounts = [];
        $statusCounts = [];
        $componentCounts = [];
        $labelCounts = [];
        $themeCounts = [];

        $bugFixes = [];
        $groupedIssues = [
            'user_facing' => [],
            'moderation' => [],
            'admin_ops' => [],
            'platform' => [],
            'other' => [],
        ];

        foreach ($issues as $issue) {
            $typeCounts[$issue->issueType] = ($typeCounts[$issue->issueType] ?? 0) + 1;
            $statusCounts[$issue->status] = ($statusCounts[$issue->status] ?? 0) + 1;

            foreach ($issue->components as $component) {
                $componentCounts[$component] = ($componentCounts[$component] ?? 0) + 1;
            }

            foreach ($issue->labels as $label) {
                $labelCounts[$label] = ($labelCounts[$label] ?? 0) + 1;
            }

            foreach ($this->extractThemes($issue) as $theme) {
                $themeCounts[$theme] = ($themeCounts[$theme] ?? 0) + 1;
            }

            if ($this->isBugFix($issue)) {
                $bugFixes[] = $issue;
            }

            $groupedIssues[$this->detectPrimaryGroup($issue)][] = $issue;
        }

        arsort($typeCounts);
        arsort($statusCounts);
        arsort($componentCounts);
        arsort($labelCounts);
        arsort($themeCounts);

        $bullets = [];
        $bullets[] = $this->buildOverviewBullet($release, $issues, $typeCounts, $statusCounts, $themeCounts);

        $groups = [
            $this->buildCategoryBullet(
                'Пользовательские и продуктовые изменения',
                $groupedIssues['user_facing'],
                'В релиз вошли изменения, влияющие на пользовательские сценарии, интерфейсы и поведение основных продуктовых потоков.',
                $componentCounts,
                $themeCounts
            ),
            $this->buildCategoryBullet(
                'Модерация и workflow',
                $groupedIssues['moderation'],
                'Заметная часть релиза касается модерации, статусов задач и сценариев проверки, что должно сделать процессы более предсказуемыми.',
                $componentCounts,
                $themeCounts
            ),
            $this->buildCategoryBullet(
                'Админка и операционные процессы',
                $groupedIssues['admin_ops'],
                'Отдельный блок задач направлен на админские инструменты, снижение ручной работы и ускорение внутренних операций.',
                $componentCounts,
                $themeCounts
            ),
            $this->buildCategoryBullet(
                'Платформа, интеграции и инфраструктурные изменения',
                $groupedIssues['platform'],
                'В релизе также есть внутренние платформенные изменения, интеграции и технические доработки, которые поддерживают стабильность сервиса.',
                $componentCounts,
                $themeCounts
            ),
            $this->buildBugFixBullet($bugFixes, $themeCounts),
        ];

        foreach ($groups as $groupBullet) {
            if ($groupBullet !== null) {
                $bullets[] = $groupBullet;
            }
        }

        $riskBullet = $this->buildRiskBullet($bugFixes, $statusCounts, $themeCounts);
        if ($riskBullet !== null) {
            $bullets[] = $riskBullet;
        }

        $text = implode("\n", array_map(static fn (string $line): string => '- ' . $line, $bullets));

        return new SummaryResult(
            mode: $this->mode(),
            text: $text,
            bullets: $bullets,
            meta: [
                'provider' => 'rule',
                'issues_count' => count($issues),
                'bugfix_count' => count($bugFixes),
                'user_facing_count' => count($groupedIssues['user_facing']),
                'moderation_count' => count($groupedIssues['moderation']),
                'admin_ops_count' => count($groupedIssues['admin_ops']),
                'platform_work_count' => count($groupedIssues['platform']),
                'top_components' => array_slice(array_keys($componentCounts), 0, 5),
                'top_labels' => array_slice(array_keys($labelCounts), 0, 5),
                'top_themes' => array_slice(array_keys($themeCounts), 0, 5),
            ],
            rawOutput: [
                'type_counts' => $typeCounts,
                'status_counts' => $statusCounts,
                'component_counts' => $componentCounts,
                'label_counts' => $labelCounts,
                'theme_counts' => $themeCounts,
                'bugfix_keys' => array_map(static fn (ReleaseIssue $issue): string => $issue->key, $bugFixes),
                'user_facing_keys' => array_map(static fn (ReleaseIssue $issue): string => $issue->key, $groupedIssues['user_facing']),
                'moderation_keys' => array_map(static fn (ReleaseIssue $issue): string => $issue->key, $groupedIssues['moderation']),
                'admin_ops_keys' => array_map(static fn (ReleaseIssue $issue): string => $issue->key, $groupedIssues['admin_ops']),
                'platform_work_keys' => array_map(static fn (ReleaseIssue $issue): string => $issue->key, $groupedIssues['platform']),
                'other_keys' => array_map(static fn (ReleaseIssue $issue): string => $issue->key, $groupedIssues['other']),
            ]
        );
    }

    public function mode(): string
    {
        return 'rule';
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @param array<string, int> $typeCounts
     * @param array<string, int> $statusCounts
     * @param array<string, int> $themeCounts
     */
    private function buildOverviewBullet(string $release, array $issues, array $typeCounts, array $statusCounts, array $themeCounts): string
    {
        $topTypes = $this->formatTopCounts($typeCounts, 2);
        $topStatuses = $this->formatTopCounts($statusCounts, 2);
        $topThemes = $this->formatTopNames($themeCounts, 4);

        $line = sprintf(
            'Релиз %s включает %d задач. Основной объем составляют %s, а больше всего задач сейчас находится в статусах %s.',
            $release,
            count($issues),
            $topTypes,
            $topStatuses
        );

        if ($topThemes !== '') {
            $line .= sprintf(' Наиболее заметные темы релиза: %s.', $topThemes);
        }

        return $line;
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @param array<string, int> $componentCounts
     * @param array<string, int> $themeCounts
     */
    private function buildCategoryBullet(
        string $title,
        array $issues,
        string $lead,
        array $componentCounts,
        array $themeCounts
    ): ?string {
        if ($issues === []) {
            return null;
        }

        $line = sprintf('%s: %s', $title, $lead);
        $line .= sprintf(' В эту группу попадает %s.', $this->formatIssueCount(count($issues)));

        $focus = $this->describeCategoryFocus($issues, $componentCounts, $themeCounts);
        if ($focus !== '') {
            $line .= ' Основные темы: ' . $focus . '.';
        }

        $examples = $this->formatIssueExamples($issues, 3);
        if ($examples !== '') {
            $line .= ' Примеры задач: ' . $examples . '.';
        }

        return $line;
    }

    /**
     * @param array<int, ReleaseIssue> $bugFixes
     * @param array<string, int> $themeCounts
     */
    private function buildBugFixBullet(array $bugFixes, array $themeCounts): ?string
    {
        if ($bugFixes === []) {
            return null;
        }

        $line = sprintf(
            'Багфиксы и стабильность: В релиз вошло %d задач, связанных с исправлениями, ошибками и повышением стабильности.',
            count($bugFixes)
        );

        $focus = $this->formatTopNames($themeCounts, 3);
        if ($focus !== '') {
            $line .= ' Наиболее часто они связаны с темами: ' . $focus . '.';
        }

        $line .= ' Примеры задач: ' . $this->formatIssueExamples($bugFixes, 3) . '.';

        return $line;
    }

    /**
     * @param array<int, ReleaseIssue> $bugFixes
     * @param array<string, int> $statusCounts
     * @param array<string, int> $themeCounts
     */
    private function buildRiskBullet(array $bugFixes, array $statusCounts, array $themeCounts): ?string
    {
        $risks = [];

        if (($statusCounts['В работе'] ?? 0) > 0 || ($statusCounts['Требуется доработка'] ?? 0) > 0) {
            $risks[] = 'в релизе есть задачи, которые еще не завершены или требуют дополнительной доработки';
        }

        if (count($bugFixes) >= 5) {
            $risks[] = 'значимая часть релиза посвящена исправлениям и стабилизации, поэтому после выката нужно внимательно мониторить регрессии';
        }

        $riskThemes = array_intersect(array_keys($themeCounts), ['1s1p', 'цены', 'модерации', 'таски', 'категории']);
        if ($riskThemes !== []) {
            $risks[] = 'повышенного внимания после релиза требуют темы: ' . implode(', ', array_slice($riskThemes, 0, 3));
        }

        if ($risks === []) {
            return null;
        }

        return 'Риски и замечания: ' . implode('; ', $risks) . '.';
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     * @param array<string, int> $componentCounts
     * @param array<string, int> $themeCounts
     */
    private function describeCategoryFocus(array $issues, array $componentCounts, array $themeCounts): string
    {
        $localThemes = [];
        $localComponents = [];

        foreach ($issues as $issue) {
            foreach ($issue->components as $component) {
                $localComponents[$component] = ($localComponents[$component] ?? 0) + 1;
            }

            foreach ($this->extractThemes($issue) as $theme) {
                $localThemes[$theme] = ($localThemes[$theme] ?? 0) + 1;
            }
        }

        arsort($localThemes);
        arsort($localComponents);

        $parts = [];
        if ($localThemes !== []) {
            $parts[] = $this->formatTopNames($localThemes, 3);
        }

        if ($localComponents !== []) {
            $parts[] = 'компоненты: ' . $this->formatTopNames($localComponents, 2);
        } elseif ($parts === [] && $componentCounts !== []) {
            $parts[] = 'компоненты: ' . $this->formatTopNames($componentCounts, 2);
        } elseif ($parts === [] && $themeCounts !== []) {
            $parts[] = $this->formatTopNames($themeCounts, 3);
        }

        return implode('; ', array_filter($parts));
    }

    /**
     * @param array<string, int> $counts
     */
    private function formatTopCounts(array $counts, int $limit): string
    {
        $parts = [];

        foreach (array_slice($counts, 0, $limit, true) as $name => $count) {
            $parts[] = sprintf('%s (%d)', $name, $count);
        }

        return $parts === [] ? 'не классифицированные задачи' : implode(', ', $parts);
    }

    /**
     * @param array<string, int> $counts
     */
    private function formatTopNames(array $counts, int $limit): string
    {
        $names = array_slice(array_keys($counts), 0, $limit);

        return $names === [] ? '' : implode(', ', $names);
    }

    /**
     * @param array<int, ReleaseIssue> $issues
     */
    private function formatIssueExamples(array $issues, int $limit): string
    {
        $items = [];

        foreach (array_slice($issues, 0, $limit) as $issue) {
            $items[] = sprintf('%s %s', $issue->key, $issue->summary);
        }

        return implode('; ', $items);
    }

    private function isBugFix(ReleaseIssue $issue): bool
    {
        $haystack = $this->normalizeLower(
            $issue->issueType . ' ' . $issue->summary . ' ' . ($issue->description ?? '')
        );

        $keywords = [
            'bug', 'fix', 'error', 'issue', 'timeout', 'duplicate', 'incorrect', 'wrong', 'problem', 'fail',
            'ошиб', 'баг', 'дублир', 'таймаут', 'некоррект', 'не учитыва', 'неправиль', 'не работает',
        ];

        return $this->containsKeyword($haystack, $keywords);
    }

    private function isUserFacing(ReleaseIssue $issue): bool
    {
        return $this->scoreCategory($issue, $this->userFacingKeywords()) > 0;
    }

    private function isModerationWork(ReleaseIssue $issue): bool
    {
        return $this->scoreCategory($issue, $this->moderationKeywords()) > 0;
    }

    private function isAdminOpsWork(ReleaseIssue $issue): bool
    {
        return $this->scoreCategory($issue, $this->adminOpsKeywords()) > 0;
    }

    private function isPlatformWork(ReleaseIssue $issue): bool
    {
        return $this->scoreCategory($issue, $this->platformKeywords()) > 0;
    }

    /**
     * @return array<int, string>
     */
    private function extractThemes(ReleaseIssue $issue): array
    {
        $text = $this->normalizeLower(
            $issue->summary . ' ' .
            implode(' ', $issue->labels) . ' ' .
            implode(' ', $issue->components)
        );
        $normalized = preg_replace('/[^[:alnum:]\s-]+/u', ' ', $text) ?? '';
        $tokens = preg_split('/[\s-]+/u', $normalized) ?: [];

        $stopWords = [
            'the', 'and', 'for', 'with', 'from', 'into', 'that', 'this', 'fix', 'fixed', 'add', 'added',
            'update', 'updated', 'remove', 'removed', 'api', 'jira', 'task', 'issue', 'bug', 'release',
            'button', 'page', 'screen', 'user', 'users', 'make', 'made', 'create', 'created', 'list',
            'при', 'для', 'что', 'как', 'это', 'надо', 'сделать', 'добавить', 'обновить', 'исправить',
            'если', 'после', 'через', 'стороне', 'система', 'новый', 'новая', 'новые', 'возможность',
            'adsy', 'site', 'backend', 'frontend', 'front', 'internal', 'publisher', 'tasks',
        ];

        $themes = [];

        foreach ($tokens as $token) {
            if ($token === '' || mb_strlen($token) < 4 || in_array($token, $stopWords, true)) {
                continue;
            }

            $themes[] = $token;
        }

        return array_values(array_unique($themes));
    }

    /**
     * @param array<int, string> $keywords
     */
    private function containsKeyword(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function detectPrimaryGroup(ReleaseIssue $issue): string
    {
        $scores = [
            'moderation' => $this->scoreCategory($issue, $this->moderationKeywords()),
            'admin_ops' => $this->scoreCategory($issue, $this->adminOpsKeywords()),
            'platform' => $this->scoreCategory($issue, $this->platformKeywords()),
            'user_facing' => $this->scoreCategory($issue, $this->userFacingKeywords()),
        ];

        arsort($scores);
        $group = (string) array_key_first($scores);
        $score = $scores[$group] ?? 0;

        if ($score <= 0) {
            return 'other';
        }

        return $group;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function scoreCategory(ReleaseIssue $issue, array $keywords): int
    {
        $haystack = $this->normalizeLower(
            $issue->summary . ' ' .
            ($issue->description ?? '') . ' ' .
            implode(' ', $issue->labels) . ' ' .
            implode(' ', $issue->components)
        );

        $score = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function userFacingKeywords(): array
    {
        return [
            'front', 'frontend', 'ui', 'widget', 'page', 'form', 'banner', 'blog', 'search', 'table',
            'button', 'filter', 'display', 'contact', 'cart', 'buyer', 'publisher', 'site',
            'страниц', 'форма', 'баннер', 'виджет', 'поиск', 'фильтр', 'интерфейс', 'контакт', 'блог',
            'сайт', 'паблишер', 'баер',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function moderationKeywords(): array
    {
        return [
            'moderation', 'task review', 'review', 'dispute', 'sanctions', 'reject', 'rejection',
            'модерац', 'проверк', 'диспут', 'санкц', 'реджект',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function adminOpsKeywords(): array
    {
        return [
            'admin', 'book a call', 'wire transfer', 'invoice', 'inventory', 'top articles',
            'админ', 'инвойс', 'инвентори', 'наград', 'главн',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function platformKeywords(): array
    {
        return [
            'backend', 'internal', 'api', 'integration', 'amplitude', 'automation', 'processor',
            'domain category', 'monitoring', 'cron', 'service',
            'бэкенд', 'интеграц', 'мониторинг', 'категор', 'amplitude',
        ];
    }

    private function formatIssueCount(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return sprintf('%d задача', $count);
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return sprintf('%d задачи', $count);
        }

        return sprintf('%d задач', $count);
    }

    private function normalizeLower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtr(strtolower($value), [
            'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё',
            'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м',
            'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у',
            'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ',
            'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
        ]);
    }
}
