# Release by downgrading on a release branch, then splitting to per-module repos

Modules are developed in modern PHP and distributed as PHP 7.4 packages. Rector
performs the downgrade at release time; the modern source is never distributed
and the downgraded code is never hand-edited. This is the established pattern from
PHPStan, Rector, and ECS — with the `.72`-style version suffix retired, so the
distributed package simply *is* the downgraded one, at the same version number.

## The flow

A release is one manual run of `.github/workflows/release.yml`, given a version
that every module receives — versioning is **lockstep** (see below). One job:

1. Downgrades the whole tree with Rector (`->withDowngradeSets(php74: true)`).
2. Validates the downgraded tree on **PHP 7.4** — see below.
3. Commits the downgraded tree, locally on the runner.
4. Runs `symplify/monorepo-split-github-action` once per module, splitting each
   module's subtree from that commit and force-pushing it to its own repository
   (`phpty-org/vterm`, `phpty-org/pty`, `phpty-org/screen-test`) with the tag.
5. Packagist updates each split repo by webhook — once each package is registered,
   a one-time step done out of band.

`main` never carries downgraded code: the downgrade commit lives only on the
release runner.

## Where the downgraded code lives for the split

A subtree split needs the code in a git ref. That ref is the **local commit made
on the release runner** in step 3 — not a branch pushed to the monorepo. The
split action reads the runner's checkout, so committing there is enough; nothing
generated ever reaches `main`. (An earlier draft of this ADR routed the downgrade
through a pushed `release` branch, which raw splitsh-lite would have needed. The
symplify action splits the local checkout, so the branch is unnecessary.)

## Why lockstep versioning

The milestone-1 modules are tightly coupled — ScreenTest depends on VTerm and
Pty, and all three move together. Independent version numbers would be machinery
serving no one at this stage, and would make inter-module constraints (VTerm
requires Pty) a thing to maintain rather than "same version, always". One
monorepo tag becomes the same tag in every split repo. This is Symfony's model,
and it is the right default until a module actually stabilises ahead of the
others.

## Resolving sibling modules before they are published

A module like ScreenTest requires its siblings by `self.version`, but they are not
on Packagist while publication is deferred, so a standalone `composer update` on
it cannot resolve them. Development and CI link siblings by path repository
instead. This needs no branch alias: every module sits on the same untagged
branch, so each resolves to `dev-main`, and `self.version` matches across all of
them. The path repositories are injected only where a standalone resolution is
performed — the CI `prefer-lowest` check — and never enter a shipped
`composer.json`. Verified: with path repos injected, ScreenTest resolves
`phpty/pty` and `phpty/vterm` at `dev-main` and installs them by symlink.

## Why PHP 7.4 validation runs outside the flake

The project's own rule is to render before believing — so shipping 7.4 code that
was only ever run on 8.x would be the exact untested assumption this project
exists to reject. The downgraded tree is therefore tested on a real 7.4. nixpkgs
has no 7.4 ([ADR-0008](./0008-nix-flake-for-dev-and-ci.md)), so this one CI leg
uses `shivammathur/setup-php` at 7.4 with FFI. Two environment systems, with
clean roles: the flake is development truth, setup-php is the ship inspection.

The two do not stay wholly apart even here. setup-php supplies the 7.4 runtime,
but libghostty-vt still comes from nix — `nix build .#libghostty-vt` yields the
exact pinned shared object, which the 7.4 PHP then dlopens. The PHP version and
the native library are pinned by different tools, and both are pinned.

## Consequences

- **Modules must be written to survive downgrade.** Rector's 7.4 set handles
  enums, `readonly`, first-class callables, `never`, and the like, but not
  everything (fibers, some `WeakMap` uses). What a module may use is bounded by
  what Rector can lower, and that bound is now a coding constraint, checked by the
  7.4 validation leg.
- **Two prerequisites remain before a real release run.** The split repos exist
  (`phpty-org/{vterm,pty,screen-test}`, public, issues/wiki/projects disabled).
  Still needed: a `SPLIT_TOKEN` secret with push access to them, and a one-time
  Packagist registration per package so its webhook tracks the split repo. Neither
  is done here — the workflow is wired but not yet runnable end to end, and it
  cannot be rehearsed without publishing.
- **The split repos are read-only, enforced not just documented.** Issues, wiki,
  and projects are off. GitHub has no switch to disable pull requests, so each
  module ships a `.github/workflows/close-pull-request.yml` that auto-closes any
  PR with a pointer upstream. It sits under the module directory, so GitHub
  ignores it in the monorepo (workflows run only from the repo root) and runs it
  in the split repo, where the module is the root.
- `phpty-org/phpty` is the development repo of record; the split repos are
  read-only distribution mirrors, and a pull request against one belongs upstream
  (see [issue-tracker.md](../agents/issue-tracker.md)).
