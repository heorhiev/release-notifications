<?php

/**
 * @var string $release
 * @var array<int, string> $issueLines
 * @var int $issuesCount
 */

return implode("\n", [
    sprintf('Release: %s', $release),
    sprintf('Issue count: %d', $issuesCount),
    'Issues:',
    ...$issueLines,
]);

