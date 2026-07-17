<?php

declare(strict_types=1);

namespace PhPty\Pty;

use FFI;

/**
 * Loads the C library that provides openpty(3) and declares the slice of it that
 * Pty uses. This is the capability with no route through core PHP: there is no
 * posix_openpt, and proc_open yields pipes rather than a Tty. See
 * docs/adr/0001-ffi-for-terminal-primitives.md and pty/CONTEXT.md.
 *
 * The C names master/slave stay inside this boundary; Pty's own API says
 * Controller and Device.
 */
final class Libc
{
    /** ioctl request to make a Tty the calling session's controlling terminal. */
    public const TIOCSCTTY = PHP_OS_FAMILY === 'Darwin' ? 0x20007461 : 0x540E;

    private const CDEF = <<<'C'
        int openpty(int *amaster, int *aslave, char *name, const void *termp, const void *winp);
        int dup2(int oldfd, int newfd);
        int ioctl(int fd, unsigned long request, int arg);
        long read(int fd, void *buf, unsigned long count);
        long write(int fd, const void *buf, unsigned long count);
        int close(int fd);
        C;

    public static function load(): FFI
    {
        // On macOS openpty lives in libutil; on modern glibc (>= 2.34, which the
        // Nix flake provides) it moved into libc.so.6. Older glibc keeps it in
        // libutil.so.1 — out of scope while the environment is the flake.
        $library = PHP_OS_FAMILY === 'Darwin' ? 'libutil.dylib' : 'libc.so.6';

        return FFI::cdef(self::CDEF, $library);
    }
}
