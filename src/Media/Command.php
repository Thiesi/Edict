<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * Runs an external tool without a shell, with a time limit.
 */
final class Command
{
    private const MAX_OUTPUT_BYTES = 65536;

    /**
     * @param list<string> $command
     * @param string $tempDir where the tool may spill temp files: /tmp is a small RAM
     *                        disk on the host, and this directory gets cleaned up
     *
     * @return string standard output
     *
     * @throws MediaError
     */
    public static function run(array $command, int $timeoutSeconds, string $tempDir): string
    {
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tempDir] + getenv());
        if (!is_resource($process)) {
            throw new MediaError('Could not start ' . basename($command[0]) . '.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = microtime(true) + $timeoutSeconds;

        try {
            do {
                $status = proc_get_status($process);
                $output = self::keep($output, $pipes[1]);
                $errors = self::keep($errors, $pipes[2]);
                if ($status['running']) {
                    if (microtime(true) > $deadline) {
                        proc_terminate($process, 9);

                        throw new MediaError(basename($command[0]) . ' took too long.', true);
                    }
                    usleep(20_000);
                }
            } while ($status['running']);

            $output = self::keep($output, $pipes[1]);
            $errors = self::keep($errors, $pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        if ($status['exitcode'] !== 0) {
            throw new MediaError(basename($command[0]) . ' failed: ' . mb_substr(trim($errors), 0, 500));
        }

        return $output;
    }

    /**
     * Keeps draining a noisy tool's pipe, but holds on to only so much of it.
     *
     * @param resource $pipe
     */
    private static function keep(string $kept, $pipe): string
    {
        $read = (string) stream_get_contents($pipe, self::MAX_OUTPUT_BYTES);

        return strlen($kept) < self::MAX_OUTPUT_BYTES ? $kept . $read : $kept;
    }
}
