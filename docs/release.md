# Releasing

A release is a semantic-version git tag `vX.Y.Z` plus a GitHub release whose notes are that version's
`CHANGELOG.md` section. Consumers pin the tag. Nothing is published to the npm or Packagist registries (roadmap
RSW-6).

## Why the tag carries the compiled files

The TypeScript package is installed from a git URL. For a git dependency that defines a `build`, `prepare`,
`prepack`, `preinstall`, `install` or `postinstall` script, npm clones the repository, installs its dependencies,
runs those scripts and packs the result on the consumer's machine; npm's documentation advises a repository that
ships pre-built files to define none of them. So `package.json` has none (the compile script is `compile`),
`dist/` stays git-ignored on `main`, and each release adds one commit on top of `main` that contains `dist/`; only
the tag points at it. The PHP package needs no build: Composer reads the same tag and takes the version from it,
which is why `composer.json` has no `version` field.

## Steps

RSW-5 (the first release) adds the command that builds `dist/` (step 2) and a check that the local tag installs
before it is pushed. No release is cut before then.

1. Release PR, through the normal flow. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`
   (UTC date), add an empty `## [Unreleased]` above it, and update the links at the bottom:
   `[Unreleased]: https://github.com/georgimorozov/redis-sliding-window/compare/vX.Y.Z...HEAD` and
   `[X.Y.Z]: https://github.com/georgimorozov/redis-sliding-window/compare/vPREVIOUS...vX.Y.Z` (first release:
   `.../releases/tag/vX.Y.Z`). Set `"version": "X.Y.Z"` in `package.json` and in both root entries of
   `package-lock.json`.
2. After the merge, from `git switch --detach origin/main`: build `dist/` (RSW-5), `git add -f dist`, commit
   `Release vX.Y.Z: compiled files`, run `bash scripts/check-no-attribution.sh origin/main`, then
   `git tag -a vX.Y.Z -m vX.Y.Z`.
3. `git push origin vX.Y.Z`, write the tag's CHANGELOG section to a notes file, and run
   `gh release create vX.Y.Z --repo georgimorozov/redis-sliding-window --verify-tag --title vX.Y.Z --notes-file <file>`.
   `--verify-tag` matters: without it, `gh` creates a missing tag from the default branch, which has no `dist/`.
