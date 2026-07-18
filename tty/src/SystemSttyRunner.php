<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * Runs the real `stty` binary with its stdin bound to this process's stdin — the
 * Tty. That binding is the whole mechanism: `stty` reads and mutates the line
 * discipline of its own stdin, so a change it makes there is a change to the Tty
 * this process shares, and it persists after the child exits (termios state
 * belongs to the device, not the descriptor). It is also why the API takes no
 * descriptor: `stty` cannot be pointed anywhere else (tty/CONTEXT.md).
 *
 * The command is passed to proc_open as an argv array, so there is no shell and
 * nothing to quote — the opaque `stty -g` string replayed on restore reaches
 * stty as one verbatim argument.
 */
final class SystemSttyRunner implements SttyRunner
{
    public function run(array $args): string
    {
        $descriptors = [
            0 => \STDIN,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @\proc_open(\array_merge([self::binary()], $args), $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not run stty.');
        }

        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($process);

        if ($status !== 0) {
            throw new \RuntimeException(\sprintf(
                'stty %s failed (exit %d): %s',
                \implode(' ', $args),
                $status,
                \trim((string) $stderr)
            ));
        }

        return (string) $stdout;
    }

    /**
     * The stty binary to run. Bare `stty` (resolved on PATH) is the OS-native one
     * on a normal install, and that is what must be used: an stty and a kernel
     * disagree on the `-g` format and on which settings a pty accepts, so they
     * have to be a matched pair. On macOS a PATH can shadow BSD stty with GNU
     * coreutils (a Nix or Homebrew shell does), whose full `-g` restore the XNU
     * pty rejects; the BSD `/bin/stty` is always present and always matches, so
     * prefer it there. Everywhere else the PATH stty already matches the kernel.
     */
    private static function binary(): string
    {
        if (PHP_OS_FAMILY === 'Darwin' && \is_executable('/bin/stty')) {
            return '/bin/stty';
        }

        return 'stty';
    }
}
