# Site Bloat Auditor — development test suite

Development-only. Excluded from release builds by `.distignore` and `.gitattributes`
(`export-ignore`). Every script refuses to run outside the PHP CLI.

## Requirements

- The Local by Flywheel site that contains this plugin **running** (MySQL reachable on the site's port).
- The runner finds that site in Local's `sites.json` (by the plugin's folder) and auto-detects
  PHP, `php.ini` and the MySQL port. Override with `$env:SBA_PHP`, `$env:SBA_PHP_INI`,
  `$env:SBA_DB_PORT`, `$env:SBA_LOCAL_SITE` (site name), `$env:SBA_TEST_HOST` (request host).
- Snapshot baselines are portable: the WordPress root path is stored as `{WP_ROOT}/` and the
  site URL as `https://example.test` (`tests/snapshots/portable.php`).

## Commands (PowerShell, from the plugin directory)

| Command | What it does | Writes? |
|---|---|---|
| `.\tests\run.ps1` | lint (all installed PHP versions) + `node --check` + security + regression + snapshot compare | No |
| `.\tests\run.ps1 -Suite lint\|security\|regression\|snapshot` | One suite | No |
| `.\tests\run.ps1 -Suite regression -Report tests\reports\x.md` | Regression + expected-vs-actual Markdown report | Report file only |
| `.\tests\run.ps1 -Suite snapshot -Capture` | Re-capture golden-master baselines | Baseline JSON only |
| `.\tests\run.ps1 -Fixtures create` | Create the fixture scenario | **Yes** — see below |
| `.\tests\run.ps1 -Fixtures cleanup` | Dry run: list what cleanup would remove | No |
| `.\tests\run.ps1 -Fixtures cleanup -Confirm` | Remove the fixture scenario | **Yes** — fixtures only |

Direct PHP equivalents live in `tests/fixtures/`, `tests/security/`, `tests/regression/`,
`tests/snapshots/`, `tests/tools/`. `create-fixtures.php --plan` prints the full plan without writing.

## Safety model

- **Read-only suites** start a DB transaction before any plugin code runs and ROLL BACK at
  shutdown, then print a checksum comparison of posts, postmeta, options, users, usermeta.
  Outbound HTTP is blocked (`pre_http_request`) so results are deterministic and offline.
- Every run prints an `[env]` banner (site URL, ABSPATH, DB name/host/port, plugin path) and
  aborts if the loaded plugin is not the copy that contains these tests.
- `tests/tools/state-fingerprint.php capture|compare <file>` hashes every existing file under
  `uploads/` (except the fixture folder) and every row of posts, postmeta, options,
  term_taxonomy and term_relationships, to prove a step left existing data untouched.

## Fixture scenario

Created by `fixtures/create-fixtures.php` from `fixtures/fixture-definitions.php`
(37 attachments, 12 posts, 4 revisions). Isolation guarantees:

- All files are written to `wp-content/uploads/sba-test-fixtures/` (never year/month folders).
- Every fixture post/attachment carries post meta `_sba_test_fixture = 1`; titles start with `[SBA TEST]`.
- `uploads/sba-test-fixtures/manifest.json` records every ID and file.
- Refuses to run if a manifest already exists.
- Known, verified side effects on existing rows: one key (the fixture ID) is added to the
  `sba_optimized_images` option by the plugin's real optimizer; the default category's
  post count changes. The core publish-ping cron hook is disabled during creation.

### Cleanup procedure

1. `.\tests\run.ps1 -Fixtures cleanup` — review the dry-run list.
2. `.\tests\run.ps1 -Fixtures cleanup -Confirm` — removes only items that are **both** in the
   manifest **and** tagged `_sba_test_fixture`; removes files only inside
   `uploads/sba-test-fixtures/` (and `uploads/sba-quarantine/sba-test-fixtures/` if a test
   quarantined a fixture); prunes `sba_optimized_images` / `sba_quarantined_files` records for
   fixture IDs only.
3. Optional proof: compare against a fingerprint captured before fixture creation —
   `php tests/tools/state-fingerprint.php compare <before.json>` must report 0 changed rows/files.

After re-creating fixtures, attachment IDs change: re-capture snapshots (`-Suite snapshot -Capture`).

## Expectations

`fixtures/fixture-definitions.php` holds the **target** behaviour per fixture
(usage used/unused/review, usage location, condition optimized/within_benchmark/oversized/n/a,
duplicate, accessibility issue, missing file). Failures against the unmodified 1.1.0 code are
the documented defects; see `reports/phase0-baseline-behaviour.md`.
