# Releasing

This package is versioned with [Semantic Versioning](https://semver.org) and
distributed via git tags (Packagist reads the tags — there is intentionally no
`version` field in `composer.json`).

> **This package is released FIRST.** Drivers require `isapp/laravel-cashier-support:
> ^1.0`, so this tag must exist *and be live on Packagist* before any driver is tagged.
> Tag a driver first and every consumer's `composer require` fails. Details in §6.

## 1. Pre-flight

- [ ] **Ask Packagist what is already published — not git.** `git tag` and `gh release list`
      describe the repository, not the registry, and the two can disagree: a deleted tag
      leaves the published version in place, immutable and invisible from here.
      ```bash
      curl -s https://repo.packagist.org/p2/isapp/laravel-cashier-support.json \
        | python3 -c "import sys,json; print([p['version'] for p in json.load(sys.stdin)['packages']['isapp/laravel-cashier-support']])"
      ```
      The next version must be strictly above everything that command returns *and* above
      anything the package page lists as deleted or missing upstream. A number that was ever
      published cannot be reused — deleting the version does not free it.
- [ ] All intended changes are merged into `main`.
- [ ] `main` is green in CI (tests matrix + quality job).
- [ ] Locally on an up-to-date `main`, everything passes:
  ```bash
  composer test
  composer analyse
  composer deptrac
  composer format -- --test
  ```
- [ ] The `[Unreleased]` section of `CHANGELOG.md` reflects every change since the
      last tag (the PR changelog enforcer keeps this honest).

## 2. Decide the version

Bump according to the content of `[Unreleased]`:

- **major** (`X`.0.0) — any breaking change: `Removed`, or backward-incompatible
  `Changed` (renamed/removed contract methods, changed signatures, dropped a
  Laravel version, etc.).
- **minor** (x.`Y`.0) — new backward-compatible features (`Added`).
- **patch** (x.y.`Z`) — backward-compatible bug fixes only (`Fixed` / `Security`).

Pre-1.0: breaking changes may ship in a minor bump, but call them out clearly.

## 3. Update the CHANGELOG

In `CHANGELOG.md`:

- [ ] Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` (use today's date).
- [ ] Add a fresh empty `## [Unreleased]` above it.
- [ ] Update the reference links at the bottom:
  ```markdown
  [Unreleased]: https://github.com/isap-ou/laravel-cashier-support/compare/vX.Y.Z...HEAD
  [X.Y.Z]:      https://github.com/isap-ou/laravel-cashier-support/compare/vPREV...vX.Y.Z
  ```
  For the very first release, point `[X.Y.Z]` at
  `.../releases/tag/vX.Y.Z` instead of a compare.

## 4. Commit and tag

```bash
git checkout main
git pull --ff-only origin main
git add CHANGELOG.md
git commit -m "Release X.Y.Z"
git tag -a vX.Y.Z -m "X.Y.Z"
git push origin main
git push origin vX.Y.Z
```

> Tags are the release. Never move or delete a published tag — cut a new patch
> instead.

## 5. Publish & verify

- [ ] Packagist updates automatically via the GitHub webhook; confirm the new
      version appears (or click **Update** on the package page).
- [ ] Create a GitHub Release from the tag, pasting the `X.Y.Z` CHANGELOG section as the
      body. **Not optional, and the reason is not presentation.** A Release is a record on
      GitHub rather than a ref in git, so it survives the tag being deleted — which is
      exactly what went wrong on 2026-07-20: `1.0.0` and `1.1.0` had been published on
      2026-07-01 and their tags later removed, so `git tag` and `gh release list` were both
      empty and the repository looked as though it had never been released. It had. Hours
      were spent tagging a version Packagist would refuse, and the number was burned.

      A Release would have said so on the first check.
- [ ] `composer require isapp/laravel-cashier-support:^X.Y` resolves the release —
      run it in a scratch project **outside this monorepo**. Inside it, the driver's
      `path` repository can satisfy the constraint locally and prove nothing.

## 6. This package is released FIRST

**Drivers depend on this one, so its tag must exist and be on Packagist before any
driver is tagged.** `isapp/laravel-cashier-revolut` requires
`isapp/laravel-cashier-support: ^1.0`; tag the driver first and every consumer's
`composer require` fails, because nothing satisfies that constraint.

The order is:

1. Tag this package.
2. Confirm Packagist has ingested the version (step 5 above) — not just that the tag
   was pushed.
3. Only then hand off to the driver's own `RELEASING.md`.

This is not hypothetical: the constraint sat broken and unnoticed for months, because
the driver resolves this package through a `path` repository with a hardcoded
`"versions": {"isapp/laravel-cashier-support": "1.0.0"}`. That satisfies `^1.0`
forever, in the monorepo and in the driver's CI — so nothing local can tell you the
published constraint is unsatisfiable. Step 5's scratch project is the only check that
can.

## Diffing versions

- Human-readable: the per-version sections of `CHANGELOG.md`.
- Code: `git diff vPREV vX.Y.Z` or GitHub `compare/vPREV...vX.Y.Z`.
