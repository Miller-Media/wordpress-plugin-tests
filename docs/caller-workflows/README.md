# Caller workflow templates

Copy these into each plugin repo (adjusting `<slug>` to that plugin's
manifest slug from `plugins.json` in this repo):

| Template | Copy to | Purpose |
|---|---|---|
| `ci.yml` | `.github/workflows/ci.yml` | Calls this repo's reusable `ci.yml` on every PR/push. |
| `release.yml` | `.github/workflows/release.yml` | Calls this repo's reusable `release.yml` on `vX.Y.Z` tag push. |
| `claude.yml` | `.github/workflows/claude.yml` | Calls this repo's reusable `claude-issue.yml`. |
| `dependabot.yml` | `.github/dependabot.yml` | Keeps the plugin's own actions/composer deps current. |
| `.distignore` | `.distignore` | What NOT to ship to WordPress.org SVN. |

## Required repo/org setup

- **Secrets** (repo or org level, consumed via `secrets: inherit`):
  `SVN_USERNAME`, `SVN_PASSWORD` (release), `CLAUDE_CODE_OAUTH_TOKEN`
  (claude), `HARNESS_DEPLOY_KEY` (all three, only while the harness repo
  is private — see below).
- **Environment**: create a `wordpress-org` GitHub Environment in the
  harness repo (`Miller-Media/wordpress-plugin-tests`) with required
  reviewers — that's what gates the actual SVN deploy in `release.yml`'s
  `deploy` job.
- **Branch protection**: require the **`ci-result`** check (and nothing
  else) on the plugin repo's default branch. That one job fails if any of
  `changes`/`matrix`/`test` failed, and passes through allow-failure
  (trunk) legs correctly.

## ⚠️ Private harness, public plugin repos

GitHub does not allow a reusable workflow in a **private** repository to
be called from a **public** repository. `Miller-Media/wordpress-plugin-tests`
is private; most of the plugin repos (`cloudflare-ip-blacklist`,
`edit-usernames`, etc.) are public. **Calling `ci.yml@main` /
`release.yml@main` from those public repos will fail** until one of the
following is done:

1. **Make `wordpress-plugin-tests` public.** It contains no secrets (all
   secrets are supplied by the *caller* via `secrets: inherit` /
   environment protection), so this is the simplest fix.
2. **Enable "Accessible from repositories in the organization"** on the
   harness repo's Actions settings. This only helps if the calling repo
   is itself private (e.g. an internal-only plugin) — it does **not**
   unblock public plugin repos.

Either way, every reusable workflow here also explicitly checks out the
harness repo's own files (`Makefile`, `bin/`, `tests/`) via a separate
`actions/checkout` step using `secrets.HARNESS_DEPLOY_KEY` when provided
— that checkout works over SSH regardless of the repo's visibility, but
it does not by itself solve the "can a public repo invoke a private
repo's reusable workflow" restriction above; that's a GitHub platform
rule, not something a deploy key can work around.
