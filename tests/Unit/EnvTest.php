<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testLoadReadsValuesAndStripsWrappingQuotes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jira-release-env-');
        self::assertNotFalse($path);
        file_put_contents($path, "# comment\nPLAIN=value\nDOUBLE=\"two words\"\nSINGLE='three words'\n");

        try {
            Env::load($path);

            self::assertSame('value', Env::get('PLAIN'));
            self::assertSame('two words', Env::get('DOUBLE'));
            self::assertSame('three words', Env::require('SINGLE'));
        } finally {
            unlink($path);
            foreach (['PLAIN', 'DOUBLE', 'SINGLE'] as $name) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            }
        }
    }

    public function testRequireThrowsForMissingValue(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required environment variable: ABSENT_TEST_VALUE');

        Env::require('ABSENT_TEST_VALUE');
    }

    public function testGetStripsQuotesProvidedByExternalEnvironment(): void
    {
        $_ENV['QUOTED_EXTERNAL_VALUE'] = "'quoted value'";

        try {
            self::assertSame('quoted value', Env::get('QUOTED_EXTERNAL_VALUE'));
        } finally {
            unset($_ENV['QUOTED_EXTERNAL_VALUE']);
        }
    }
}
