<?php

declare(strict_types=1);

namespace PhPty\Tty;

use FFI;

/**
 * Loads the C library and declares the termios/ioctl slice the Ffi Backend uses.
 * This is the io-console capability core PHP lacks: no tcgetattr, no ioctl, no
 * way to reach the Tty's line discipline. See
 * docs/adr/0001-ffi-for-terminal-primitives.md and tty/CONTEXT.md.
 *
 * The struct layout and constant values differ between Linux and macOS, and both
 * are known quantities — handled here the way Pty handles its own struct
 * differences (pty/src/Libc.php). macOS widens tcflag_t/speed_t to `unsigned
 * long`, drops the c_line member, and sizes c_cc at 20 rather than 32; the VMIN
 * and VTIME slots into c_cc, the lflag bits, and the winsize ioctl numbers all
 * move too.
 */
final class Libc
{
    /** c_lflag bits: canonical mode, echo, and signal interpretation. */
    public const ICANON = PHP_OS_FAMILY === 'Darwin' ? 0x00000100 : 0x00000002;
    public const ECHO = 0x00000008;
    public const ISIG = PHP_OS_FAMILY === 'Darwin' ? 0x00000080 : 0x00000001;

    /** Indices into c_cc for the byte-at-a-time read thresholds. */
    public const VMIN = PHP_OS_FAMILY === 'Darwin' ? 16 : 6;
    public const VTIME = PHP_OS_FAMILY === 'Darwin' ? 17 : 5;

    /** tcsetattr action: apply the change immediately. */
    public const TCSANOW = 0;

    // ioctl requests for the window size. The get and set numbers are not
    // adjacent on macOS — TIOCGWINSZ encodes ioctl 104 and TIOCSWINSZ 103, so
    // they end in 0x68 and 0x67 respectively, not a matched pair.
    public const TIOCGWINSZ = PHP_OS_FAMILY === 'Darwin' ? 0x40087468 : 0x5413;
    public const TIOCSWINSZ = PHP_OS_FAMILY === 'Darwin' ? 0x80087467 : 0x5414;

    // ioctl is variadic in C and must be declared with `...`, never a fixed
    // third parameter: on arm64 a fixed and a variadic argument use different
    // registers, so a fixed declaration passes the pointer where the function
    // does not look for it — the same trap Pty documents for fcntl/ioctl.
    private const CDEF_DARWIN = <<<'C'
        typedef unsigned long tcflag_t;
        typedef unsigned char cc_t;
        typedef unsigned long speed_t;
        struct termios {
            tcflag_t c_iflag;
            tcflag_t c_oflag;
            tcflag_t c_cflag;
            tcflag_t c_lflag;
            cc_t c_cc[20];
            speed_t c_ispeed;
            speed_t c_ospeed;
        };
        struct winsize {
            unsigned short ws_row;
            unsigned short ws_col;
            unsigned short ws_xpixel;
            unsigned short ws_ypixel;
        };
        int tcgetattr(int fd, struct termios *termios_p);
        int tcsetattr(int fd, int optional_actions, const struct termios *termios_p);
        int ioctl(int fd, unsigned long request, ...);
        C;

    private const CDEF_LINUX = <<<'C'
        typedef unsigned int tcflag_t;
        typedef unsigned char cc_t;
        typedef unsigned int speed_t;
        struct termios {
            tcflag_t c_iflag;
            tcflag_t c_oflag;
            tcflag_t c_cflag;
            tcflag_t c_lflag;
            cc_t c_line;
            cc_t c_cc[32];
            speed_t c_ispeed;
            speed_t c_ospeed;
        };
        struct winsize {
            unsigned short ws_row;
            unsigned short ws_col;
            unsigned short ws_xpixel;
            unsigned short ws_ypixel;
        };
        int tcgetattr(int fd, struct termios *termios_p);
        int tcsetattr(int fd, int optional_actions, const struct termios *termios_p);
        int ioctl(int fd, unsigned long request, ...);
        C;

    public static function load(): FFI
    {
        // tcgetattr/tcsetattr/ioctl live in libc on both platforms. On macOS the
        // physical libc.dylib is absent from disk (it lives in the dyld shared
        // cache), but dlopen still resolves the name, so FFI::cdef finds it.
        $isDarwin = PHP_OS_FAMILY === 'Darwin';
        $library = $isDarwin ? 'libc.dylib' : 'libc.so.6';

        return FFI::cdef($isDarwin ? self::CDEF_DARWIN : self::CDEF_LINUX, $library);
    }
}
