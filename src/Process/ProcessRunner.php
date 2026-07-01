<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Process;

use MonkeysLegion\Backup\Exception\EngineException;

/**
 * Secure subprocess runner using proc_open.
 *
 * - Always passes arguments as an argv array (no shell interpolation).
 * - Injects sensitive credentials via environment variables, never via argv.
 * - Captures stderr; redacts secrets from debug output.
 * - Enforces a configurable timeout.
 */
class ProcessRunner
{
    public function __construct(
        private int $timeout = 3600
    ) {}

    /**
     * Run a command described as an argv array.
     *
     * @param list<string>         $cmd    Command + arguments (no shell expansion).
     * @param array<string,string> $env    Extra environment variables for the process.
     * @param string|null          $stdin  Optional input piped to stdin.
     * @param list<string>         $redact Strings that must be masked in exception messages.
     * @return string stdout output
     * @throws EngineException on non-zero exit or timeout.
     */
    public function run(
        array $cmd,
        array $env = [],
        ?string $stdin = null,
        array $redact = []
    ): string {
        if ($cmd === []) {
            throw new EngineException("Failed to launch subprocess: empty command");
        }

        $descriptors = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];

        // Merge parent environment so $PATH etc. survive
        $mergedEnv = \array_merge(\getenv() ?: [], $env);

        $proc = \proc_open($cmd, $descriptors, $pipes, null, $mergedEnv);

        if (!\is_resource($proc)) {
            $preview = $this->redact(\implode(' ', $cmd), $redact);
            throw new EngineException("Failed to launch subprocess: {$preview}");
        }

        // Write stdin then close it
        if ($stdin !== null) {
            \fwrite($pipes[0], $stdin);
        }
        \fclose($pipes[0]);

        // Read stdout / stderr concurrently using stream_select to avoid deadlocks
        $stdout = '';
        $stderr = '';

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $start = \microtime(true);

        while (true) {
            $read   = [$pipes[1], $pipes[2]];
            $write  = [];
            $except = [];

            $changed = \stream_select($read, $write, $except, 0, 200_000);

            if ($changed === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = \fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            if (\feof($pipes[1]) && \feof($pipes[2])) {
                break;
            }

            if ((\microtime(true) - $start) > $this->timeout) {
                \proc_terminate($proc, 9);
                \fclose($pipes[1]);
                \fclose($pipes[2]);
                \proc_close($proc);
                $secs    = $this->timeout;
                $preview = $this->redact(\implode(' ', $cmd), $redact);
                throw new EngineException("Process timed out after {$secs} seconds: {$preview}");
            }
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        if ($exit !== 0) {
            $errOut = $this->redact($stderr, $redact);
            throw new EngineException("Process exited with code {$exit}. Stderr: {$errOut}");
        }

        return $stdout;
    }

    /**
     * Replace each secret value with *** in a string for safe display.
     *
     * @param list<string> $secrets
     */
    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = \str_replace($secret, '***', $text);
            }
        }
        return $text;
    }
}
