# Licensing of PhPty

PhPty is a monorepo. Every module PhPty authors ships under the **Mozilla Public
License 2.0** (MPL-2.0), uniformly. Each module carries its own `LICENSE` file
holding the authoritative MPL-2.0 text — including Exhibit B, "Incompatible With
Secondary Licenses" — and that file travels with the module when it is split out
into its own repository.

| Module           | Licence                     | Derived from                                                   |
| ---------------- | --------------------------- | -------------------------------------------------------------- |
| `tty/`           | MPL-2.0                     | Original work, written against POSIX and termios documentation  |
| `pty/`           | MPL-2.0                     | Original work, written against POSIX and termios documentation  |
| `vterm/`         | MPL-2.0                     | Original work. Binds libghostty-vt (MIT, (c) 2024 Mitchell     |
|                  |                             | Hashimoto and Ghostty contributors) at runtime via FFI          |
| `screen-test/`   | MPL-2.0                     | yamatanooroti — MIT, Copyright (c) 2020 aycabta                 |
| `reline/`        | Ruby licence or BSDL (dual) | reline — Copyright (c) Yukihiro Matsumoto, and rb-readline,     |
|                  |                             | Copyright (c) 2009 Park Heesob                                  |

Reline is the one exception, and it cannot be brought under MPL-2.0. Upstream
reline declares `license = 'Ruby'`, a dual Ruby-licence/2-clause-BSDL grant, and
it vendors rb-readline under a BSD licence. A port is a derivative work, so both
notices carry through: `reline/` keeps those upstream terms rather than PhPty's
MPL-2.0, carrying upstream's `COPYING`, `BSDL`, and `license_of_rb-readline`
verbatim.

Despite its name, `vterm/` is not derived from vterm-gem: that gem binds
libvterm, and `vterm/` binds libghostty-vt, so no aycabta code or design remains
in it. libghostty-vt (MIT, (c) 2024 Mitchell Hashimoto and Ghostty contributors)
is dlopen'ed at runtime and never redistributed here, which makes it a dependency
rather than an upstream; its MIT licensing is unaffected by anything in this
repository.

The reference implementations under `references/` are git submodules. They are
not part of PhPty and remain under their own upstream licences.

See `docs/adr/0014-mpl-2.0-uniform-module-licence.md` for the decision, and
`docs/adr/0005-port-fidelity-varies-by-layer.md` for the port-fidelity reasoning
it rests on.
