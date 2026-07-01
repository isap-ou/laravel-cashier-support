# Releasing

This package is versioned with [Semantic Versioning](https://semver.org) and
distributed via git tags (Packagist reads the tags — there is intentionally no
`version` field in `composer.json`).

## 1. Pre-flight

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
- [ ] (Optional) Create a GitHub Release from the tag, pasting the `X.Y.Z`
      CHANGELOG section as the body.
- [ ] `composer require isapp/laravel-cashier-support:^X.Y` resolves the release.

## Diffing versions

- Human-readable: the per-version sections of `CHANGELOG.md`.
- Code: `git diff vPREV vX.Y.Z` or GitHub `compare/vPREV...vX.Y.Z`.
