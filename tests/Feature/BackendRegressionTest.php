<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BackendRegressionTest extends TestCase
{
    public static function suites(): array
    {
        return [['dependency-contract.php'], ['security.php'], ['backend.php']];
    }

    #[DataProvider('suites')]
    public function testSyntheticSuite(string $script): void
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../'.$script], dirname(__DIR__, 2));
        $process->setTimeout(90);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('PASS:', $process->getOutput());
        self::assertStringNotContainsString('FAIL:', $process->getOutput());
    }
}
