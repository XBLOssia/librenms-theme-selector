# Releasing

How a change gets from a pull request to the hosts that run the plugin, and what stands in the way of a
bad one. The short version: **`main` is for development, a tag is a release, and hosts follow tags.**

## The model

| Where | What it follows | Gets a change |
|---|---|---|
| A pull request | (not merged yet) | CI runs: unit tests and the mutation check must pass to merge |
| `main` | the newest merged change | Integration runs on every push (the live suites, and install / update / uninstall on a clean LibreNMS) |
| A release, `vX.Y.Z` | a tag on a commit of `main` that CI **and** Integration passed | Hosts on `^X.0` take it that night, or at once with `scripts/update.sh` |
| The dev instance (`dev/`) | the working tree | Immediately |

A merge to `main` therefore reaches no host. Nothing is uploaded anywhere for a release: Composer reads the
repository's tags, so **the tag is the release** and the GitHub Release is its announcement.

A host that wants every merge (a staging box) installs `dev-main`; production installs `'^1.0'`.

## Versioning

[Semantic Versioning](https://semver.org/), applied to what a host or an uploaded skin depends on:

| Bump | When | Examples |
|---|---|---|
| **major** | a skin that was valid before is not now, or an update needs the administrator to act | a token removed or its meaning changed, the bundle format changed, a column dropped, PHP or LibreNMS support dropped |
| **minor** | something new, nothing taken away | a skin, a token, a page or command, an additive migration |
| **patch** | nothing new | a fix, styling, a typo, documentation, tests |

A major release is rare and says so in the changelog under **Upgrading**. A migration must never break the
release before it: add columns, do not rename or drop them, so a host can step back to the previous version.

## What CI runs, and where

| Workflow | When | Runs | Required to merge |
|---|---|---|---|
| `ci.yml` | every pull request and push to `main`; called by the release | PHP lint, shell lint, `composer validate`, the unit tests (PHP 8.2 and 8.4), the generated-file checks, the mutation check | **yes**: `unit (PHP 8.2)`, `unit (PHP 8.4)`, `mutation check` |
| `integration.yml` | every push to `main`; a pull request that touches the plugin (`src/`, `base/`, `skins/`, `dev/`, `scripts/` ...); by hand; called by the release and nightly | the five live suites against the dev instance, and `dev/test-update.sh` | no (about ten minutes), but a release needs it green on `main` |
| `release.yml` | a `vX.Y.Z` tag is pushed | the tag is annotated, on `main`, newer than the last and in the changelog; then CI and Integration again on that commit; then the GitHub Release | (it is the release) |
| `nightly.yml` | every night | Integration against the **newest** LibreNMS image and the real GitHub, `TS_NETWORK=1` | no: a failure opens one issue, `upstream-check` |

Locally, `sh dev/test.sh` (unit), `sh dev/test.sh mutate`, `sh dev/test.sh live`, `sh dev/test.sh update`, or
`sh dev/test.sh all` do the same; see [dev/README.md](../dev/README.md). `LIBRENMS_VERSION=latest` builds the dev stacks on another
LibreNMS release.

Runners are pinned (`ubuntu-24.04`) so a new default image does not change a result unannounced; move them on purpose.

The workflows can only read the repository (`contents: read`). Two jobs may write, and nothing else:
the release job (to create the Release) and the nightly report job (to open an issue). Every action is pinned
to a commit; Dependabot proposes the updates, and CI runs on them like any change. There are no secrets.

## Making a release

1. **Write the changelog as you go.** Each pull request adds what it changed under `## [Unreleased]` in
   [CHANGELOG.md](../CHANGELOG.md), in the reader's words (a host's administrator, not a developer).
2. **Decide to release** (a batch of merged changes, or a single fix that hosts need).
3. `scripts/release.sh prepare patch|minor|major` (or an explicit version) opens a pull request that dates the
   changelog's Unreleased section as the new version. Merge it like any other. (The very first release,
   1.0.0, has its section already: skip this step.)
4. Wait for **CI and Integration to go green on `main`** (the push that merged it starts both).
5. `scripts/release.sh tag X.Y.Z` checks that you are on `main`, equal to GitHub, that the version is newer than
   the last, that the changelog has its section, and that CI and Integration **passed on this commit**; it
   then asks, tags (`git tag -a`) and pushes the tag. There is no flag to skip the checks: a tag found broken
   later is already on hosts.
6. The Release workflow runs everything again on the tag and publishes the GitHub Release with the changelog
   section as its notes. It never deletes or moves a tag.
7. **Hosts update** that night, or at once: `sudo -u librenms /opt/librenms/vendor/xblossia/librenms-theme-selector/scripts/update.sh`.

## A fix that cannot wait

There are no release branches: `main` stays releasable, so a fix is a normal pull request, a `patch` release,
and `update.sh`. If `main` holds something unfinished, it was merged too early: revert it on `main` (a pull
request) rather than working around it.

## A bad release

A tag is never moved or deleted (the tag rules below forbid it, and a host that already took it would not
notice). Fix forward: a patch release. To get a host off it at once, pin the host to the last good version
(`./lnms plugin:add xblossia/librenms-theme-selector 1.2.0`), then go back to the range
(`'^1.0'`) once the fix is out. The database is not stepped back, which is why migrations only add.

## Following releases on a host

```bash
# production: take only releases
sudo -u librenms ./lnms plugin:add xblossia/librenms-theme-selector '^1.0'
# a staging box: take every merge
sudo -u librenms ./lnms plugin:add xblossia/librenms-theme-selector dev-main
# stay exactly here until told otherwise
sudo -u librenms ./lnms plugin:add xblossia/librenms-theme-selector 1.2.0
```

`composer.plugins.json` records the choice and `daily.sh` keeps following it. `./lnms theme-selector:status`
says which of the three a host is on. The install steps are in [DEPLOYMENT.md](DEPLOYMENT.md).

## One-time repository setup (settings, not code)

These are GitHub settings that no file in the repository can make; do them in this order.

1. **Email privacy.** Settings, Emails: tick "Keep my email addresses private" and "Block command line pushes
   that expose my email". Merges made on GitHub use the account's address unless this is on, and rulesets
   below route every merge through GitHub.
2. **Two-factor authentication** on the account (and a security key or passkey if you can). The account
   is what publishes code that every host runs overnight: it is the real defence.
3. **Run CI once** (open any pull request) so GitHub knows the check names.
4. **A ruleset for `main`**: pull request required (no approval count, you are the only maintainer), the three
   checks required, no force push, no deletion, nobody bypasses it.

   ```bash
   gh api -X POST repos/XBLOssia/librenms-theme-selector/rulesets --input - <<'JSON'
   {"name":"main","target":"branch","enforcement":"active",
    "conditions":{"ref_name":{"include":["~DEFAULT_BRANCH"],"exclude":[]}},
    "rules":[{"type":"deletion"},{"type":"non_fast_forward"},
     {"type":"pull_request","parameters":{"required_approving_review_count":0,"dismiss_stale_reviews_on_push":false,"require_code_owner_review":false,"require_last_push_approval":false,"required_review_thread_resolution":false}},
     {"type":"required_status_checks","parameters":{"strict_required_status_checks_policy":false,
      "required_status_checks":[{"context":"unit (PHP 8.2)"},{"context":"unit (PHP 8.4)"},{"context":"mutation check"}]}}],
    "bypass_actors":[]}
   JSON
   ```

5. **Rulesets for `v*` tags**: only an administrator may create one, and once made it cannot be moved or deleted
   (two rulesets, because a bypass applies to every rule in its ruleset).

   ```bash
   gh api -X POST repos/XBLOssia/librenms-theme-selector/rulesets --input - <<'JSON'
   {"name":"release tags: who may create","target":"tag","enforcement":"active",
    "conditions":{"ref_name":{"include":["refs/tags/v*"],"exclude":[]}},
    "rules":[{"type":"creation"}],
    "bypass_actors":[{"actor_id":5,"actor_type":"RepositoryRole","bypass_mode":"always"}]}
   JSON
   gh api -X POST repos/XBLOssia/librenms-theme-selector/rulesets --input - <<'JSON'
   {"name":"release tags: immutable","target":"tag","enforcement":"active",
    "conditions":{"ref_name":{"include":["refs/tags/v*"],"exclude":[]}},
    "rules":[{"type":"deletion"},{"type":"update"},{"type":"non_fast_forward"}],
    "bypass_actors":[]}
   JSON
   ```

6. **Actions**: Settings, Actions, General: workflow permissions "Read repository contents" (the workflows ask
   for more where they need it), and require approval to run workflows from outside collaborators.
7. **Security**: turn on Dependabot alerts and the dependency graph; "Automatically delete head branches".
8. **Immutable releases**, if the account has the setting (Settings, General, Releases): published Releases
   and their tags can then no longer be changed.

After step 4, `gh pr merge` is the way to merge (the local merge-and-push used so far is refused by the ruleset).
