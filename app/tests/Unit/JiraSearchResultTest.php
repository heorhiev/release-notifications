<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\JiraSearchResult;
use PHPUnit\Framework\TestCase;

final class JiraSearchResultTest extends TestCase
{
    public function testConvertsToApiArray(): void
    {
        $result = new JiraSearchResult([['key' => 'PROJECT-1']], 1, 'project = PROJECT');

        self::assertSame([
            'issues' => [['key' => 'PROJECT-1']],
            'total' => 1,
            'jql' => 'project = PROJECT',
        ], $result->toArray());
    }
}
