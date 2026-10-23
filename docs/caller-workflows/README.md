# Caller workflow templates

Copy these into each plugin repo (adjusting `<slug>` to that plugin's
manifest slug from `plugins.json` in this repo):

| Template | Copy to | Purpose |
|---|---|---|
| `ci.yml` | `.github/workflows/ci.yml` | Calls this repo's reusable `ci.yml` on every PR/push. |
| `dependabot.yml` | `.github/dependabot.yml` | Keeps the plugin's own actions (and composer/npm, where applicable) deps current. |
| `.distignore` | `.distignore` | What NOT to ship to WordPress.org SVN. |

## Required repo/org setup

This harness repo (`Miller-Media/wordpress-plugin-tests`) is public, and
`ci.yml` needs no secrets to run — a plugin repo's caller workflow can
invoke it with no `secrets:` block at all.

- **Branch protection**: require the **`ci / ci-result`** check (and
  nothing else) on the plugin repo's default branch — the `ci /` prefix is
  the caller job id in `docs/caller-workflows/ci.yml`. That one job fails
  if any of `changes`/`matrix`/`test`/`plugin_check_matrix`/`plugin-check`
  failed, and passes through allow-failure (trunk, and plugin-check on the
  `wporg:false` plugin) legs correctly. In the harness repo's own direct
  runs (not through a caller), the equivalent check is plain **`ci-result`**
  (no prefix, since there's no wrapping caller job).

## Releases

There is no automated release/deploy workflow. Releases and SVN deploys
are done manually from the box, following the "Releasing a Plugin"
section in this repo's `CLAUDE.md`. Dependency bumps (actions, and
composer/npm where a plugin has them) come from Dependabot, not a bot
token.
