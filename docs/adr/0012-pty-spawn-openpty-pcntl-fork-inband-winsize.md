# Pty spawns with openpty + pcntl_fork, and sizes the child in-band

Pty creates its pair with `openpty` and forks the child with `pcntl_fork`, then
sets the child's window size in-band — the child runs `stty rows H cols W` on its
own Tty before the command. Two smaller decisions, both driven by what actually
worked when run.

## Window size is set in-band, not with winp

`openpty` and `forkpty` both accept a `winp` argument that fixes the window size
at pty creation. It is unreliable here: across several spawns within one PHP
process, the child's Tty came back 0×0 even though the struct passed in was
correct — reproduced under our own PHPUnit run, identically for `forkpty` and for
`openpty`, and only after the first spawn. Outside PHPUnit the same code was fine,
so it is some interaction between repeated pty creation and the PHP/FFI process
rather than a plain bug. Rather than chase it, we do what the child can do for
itself: run `stty` on its controlling Tty. That is a separate process past
`exec`, untouched by whatever perturbs the FFI path, and it held across every
spawn a run makes. It is also exactly what the reference, yamatanooroti, does —
for what is presumably the same reason.

This matters because ScreenTest spawns a subject per test, many per process, and
programs like reline query their window size. A size that silently degrades to
0×0 after the first test would corrupt every later rendering.

`spawn` wraps the command as `sh -c 'stty rows H cols W; exec "$@"' sh <command…>`.
The command elements stay separate argv entries that `exec "$@"` runs without
re-parsing, so there is nothing to quote and nothing to inject.

## Fork with pcntl_fork, not forkpty

`forkpty` would fold openpty, fork, and login_tty into one call, but it forks at
the C level beneath the PHP runtime. `pcntl_fork` is the PHP-managed fork, and the
child then does login_tty explicitly (setsid, `TIOCSCTTY`, dup2 of the Device onto
0/1/2). More code, but the fork is the one PHP knows about, and the tty setup is
in plain view rather than hidden in libc.

## Consequences

- Pty requires a POSIX shell at `/bin/sh`. On the Unix-only first milestone
  ([ADR-0006](./0006-unix-only-first-milestone.md)) that is a safe assumption; a
  future Windows path (ConPTY) would size the child differently anyway.
- The child is one `exec` removed from a shell, not the shell itself, so no extra
  process lingers.
- `winp` is passed as NULL — we do not half-rely on a mechanism we do not trust.
