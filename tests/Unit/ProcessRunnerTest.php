<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Process\ProcessRunner;
use MonkeysLegion\Backup\Exception\EngineException;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testRunSuccess(): void
    {
        $runner = new ProcessRunner();
        $output = $runner->run(['echo', 'hello world']);
        $this->assertSame("hello world\n", $output);
    }

    public function testRunEnvInjection(): void
    {
        $runner = new ProcessRunner();
        // Print env variable MY_VAR
        // We use php -r since env is shell independent and safe
        $output = $runner->run(['php', '-r', 'echo getenv("MY_VAR");'], ['MY_VAR' => 'injected_value']);
        $this->assertSame('injected_value', $output);
    }

    public function testRunStdin(): void
    {
        $runner = new ProcessRunner();
        // Pipe text via stdin to cat
        $output = $runner->run(['cat'], [], 'hello stdin');
        $this->assertSame('hello stdin', $output);
    }

    public function testRunFailureThrowsExceptionWithStderr(): void
    {
        $runner = new ProcessRunner();

        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains("Process exited with code");
        // We run a command that outputs to stderr and exits with non-zero
        // php -r 'fwrite(STDERR, "error details"); exit(5);'
        $runner->run(['php', '-r', 'fwrite(STDERR, "error details"); exit(5);']);
    }

    public function testRunRedactsSecretsOnFailure(): void
    {
        $runner = new ProcessRunner();

        try {
            $runner->run(
                ['php', '-r', 'fwrite(STDERR, "error with secret: analikayn"); exit(5);'],
                [],
                null,
                ['analikayn']
            );
            $this->fail('Expected EngineException to be thrown.');
        } catch (EngineException $e) {
            $this->assertStringNotContainsString('analikayn', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    public function testRunTimeout(): void
    {
        // 1 second timeout
        $runner = new ProcessRunner(1);

        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains("Process timed out");

        // Run php sleep 3 seconds
        $runner->run(['php', '-r', 'sleep(3);']);
    }
}
