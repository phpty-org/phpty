# phpty/reline

A port of Ruby's [Reline](https://github.com/ruby/reline) line editor: Unicode
width, grapheme and key-input handling for PHP terminal programs.

> **Read-only mirror.** The canonical development repository is
> [phpty-org/phpty](https://github.com/phpty-org/phpty), a monorepo. This
> `phpty/reline` repository is split out from it for distribution and is
> read-only: issues and pull requests are disabled, and any pull request opened
> here is closed automatically. Please contribute upstream.

## Status

Early. This is **tier 0** of an incremental port — the pure, terminal-free
foundation: Unicode width and grapheme measurement, the East Asian Width table,
the byte-stream-to-key matcher, and the Emacs key map. There is no line editor
yet. See [`CONTEXT.md`](CONTEXT.md) and the monorepo's
[`docs/porting/`](https://github.com/phpty-org/phpty/tree/main/docs/porting) for
the tier plan.

## Install

```console
composer require phpty/reline
```

## Requirements

- PHP `^7.4 || ^8.0`, with the `mbstring` extension

Grapheme clustering is done with PCRE's `\X` (the bundled PCRE2), so `ext-intl`
is **not** required.

## License

Reline is a port and keeps its upstream terms rather than the MPL-2.0 the rest of
PhPty ships under. Upstream Reline is offered under a dual grant — the Ruby
license or the 2-clause BSD license (© Yukihiro Matsumoto) — and vendors
rb-readline under a 2-clause BSD notice (© 2009 Park Heesob). All three travel
with this module:

- [`COPYING`](COPYING) — the Ruby license
- [`BSDL`](BSDL) — the 2-clause BSD alternative
- [`license_of_rb-readline`](license_of_rb-readline) — the rb-readline BSD notice

The `composer.json` `license` field expresses this as the SPDX expression
`(Ruby OR BSD-2-Clause)`. For how licensing works across PhPty as a whole — and
why this module is the one exception to the uniform MPL-2.0 — see
[the monorepo's LICENSE](https://github.com/phpty-org/phpty/blob/main/LICENSE.md).
