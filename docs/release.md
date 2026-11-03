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

Run the Docker helpers with `DOCKER_HOST` pointing at the build box (`CLAUDE.md`, "Commands"), or against a
local daemon.

1. Release PR, through the normal flow. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`
   (UTC date), add an empty `## [Unreleased]` above it, and update the links at the bottom:
   `[Unreleased]: https://github.com/georgimorozov/redis-sliding-window/compare/vX.Y.Z...HEAD` and
   `[X.Y.Z]: https://github.com/georgimorozov/redis-sliding-window/compare/vPREVIOUS...vX.Y.Z` (first release:
   `.../releases/tag/vX.Y.Z`). Set `"version": "X.Y.Z"` in `package.json` and in both root entries of
   `package-lock.json`.
2. After the merge: `git fetch origin`, `git switch --detach origin/main`, and check `git status --short --ignored`
   shows nothing but ignored dependency folders. Then `bash scripts/docker-test.sh release-dist` (compiles in a
   fresh Node 24 container and replaces `./dist` with the output, so no stale file survives), `git add -f dist`,
   commit `Release vX.Y.Z: compiled files`, check `git diff --name-only HEAD~1 HEAD` lists only `dist/` files and
   `HEAD~1` is `origin/main`, run `bash scripts/check-no-attribution.sh origin/main`, then
   `git tag -a vX.Y.Z -m vX.Y.Z`.
3. `bash scripts/docker-test.sh tag-check vX.Y.Z`, before anything is pushed. It copies the tag to two fresh
   consumers as a git bundle (no checkout files, no build): npm installs `git+file:///repo#vX.Y.Z` with its normal
   lifecycle behaviour and the check confirms the resolved commit is the tag's, `dist/` and `lua/` shipped, no
   lifecycle script or dev dependency came along, the ESM import works and a NodeNext TypeScript consumer
   type-checks; Composer requires exactly `X.Y.Z` from the tag as a VCS repository and the check confirms the
   commit, the consumer's autoloader and that the Lua script loads. It never changes the tag. If it fails, delete
   the local tag and release commit (`git tag -d vX.Y.Z`), fix on `main` through a PR, and start step 2 again. A
   local check does not exercise GitHub's ZIP download path for Composer; install from GitHub once after step 4.
4. `git push origin vX.Y.Z`, write the tag's CHANGELOG section to a notes file
   (`git show vX.Y.Z:CHANGELOG.md | awk '/^## \[X\.Y\.Z\]/{p=1;next} /^## \[|^\[/{p=0} p' > notes.md`, with the
   version's dots escaped), and run
   `gh release create vX.Y.Z --repo georgimorozov/redis-sliding-window --verify-tag --title vX.Y.Z --notes-file notes.md`.
   `--verify-tag` matters: without it, `gh` creates a missing tag from the default branch, which has no `dist/`.

`bash tests/scripts/release-tag-check.test.sh` (part of the gate's `package` step) runs steps 2 and 3 in a
disposable clone of the current commit: a tag without `dist/` must fail `tag-check` on the missing compiled files,
and a tag with the `release-dist` output must pass.
