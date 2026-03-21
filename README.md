# PluginInsight Crons

PHP CLI scripts that fetch and synchronize WordPress.org plugin data into the PluginInsight database.

---

## Overview

Two complementary crons keep the database in sync:

| Script | Frequency | API browse | Purpose |
|---|---|---|---|
| `fetch-new-plugins.php` | Every 5 min | `updated` | Catches version bumps and stats changes as soon as they happen |
| `fetch-all-plugins.php` | Daily at 02:00 UTC | `new` | Full sweep of all ~62 000 plugins; inserts new ones and refreshes all data |

Both scripts share the same `PluginSync` class and write to the same tables, so there is no risk of conflicts or duplicates — every write uses `INSERT … ON DUPLICATE KEY UPDATE`.

---

## Database schema

### `plugin` — one row per plugin

| Column | Type | Source |
|---|---|---|
| `plugin_id` | bigint PK | auto |
| `plugin_slug` | varchar(250) UNIQUE | `slug` |
| `plugin_version` | varchar(250) | `version` |
| `plugin_installs` | int unsigned | `active_installs` |
| `plugin_zip` | varchar(500) | `download_link` |
| `plugin_name` | varchar(250) | `name` |
| `plugin_author` | varchar(250) | `author` (HTML) |
| `plugin_author_profile` | varchar(500) | `author_profile` |
| `plugin_homepage` | varchar(500) | `homepage` |
| `plugin_requires` | varchar(20) | `requires` |
| `plugin_tested` | varchar(20) | `tested` |
| `plugin_requires_php` | varchar(20) | `requires_php` |
| `plugin_requires_plugins` | text (JSON) | `requires_plugins` |
| `plugin_short_description` | text | `short_description` |
| `plugin_rating` | tinyint unsigned | `rating` (0–100) |
| `plugin_num_ratings` | int unsigned | `num_ratings` |
| `plugin_support_threads` | int unsigned | `support_threads` |
| `plugin_support_threads_resolved` | int unsigned | `support_threads_resolved` |
| `plugin_downloaded` | bigint unsigned | `downloaded` |
| `plugin_last_updated` | datetime | `last_updated` |
| `plugin_added` | date | `added` |
| `plugin_icons` | text (JSON) | `icons` |
| `plugin_source` | varchar(250) | hardcoded `wordpress.org` |

### `plugin_version` — one row per (plugin, version)

| Column | Type | Notes |
|---|---|---|
| `plugin_id` | bigint FK | references `plugin.plugin_id` |
| `plugin_version` | varchar(250) | release tag, e.g. `2.7.3` |
| `plugin_version_zip` | varchar(500) | download URL for that release |
| `plugin_version_tested` | datetime | set externally when a test run completes; NULL until then |

The `trunk` pseudo-version returned by the API is skipped — it is a floating pointer, not a discrete release.

### Schema versioning

Migrations run automatically on every script startup. The current version (`DB_VERSION = 1.4.0`) is stored in `plugin_schema_meta` and compared against the constant; pending migrations are applied in order and the stored version is updated.

---

## Requirements

- PHP 8.3–8.5 with the `mysqli` and `curl` extensions
- MariaDB 11.4+
- Database credentials in `/webs/plugininsight/database.txt` (loaded via `../dbcon.php`)

---

## Running manually

```bash
cd /webs/plugininsight/crons

# High-frequency sync (latest updated plugins, page 1 only)
php8.4 fetch-new-plugins.php

# Full sync (all plugins, all pages — takes several minutes)
php8.4 fetch-all-plugins.php
```

Example output — `fetch-new-plugins.php`:

```
Done. Inserted: 3 | Updated: 197 | Unchanged: 0
```

Example output — `fetch-all-plugins.php`:

```
[2026-03-21 02:00:01] Starting full sync
[2026-03-21 02:00:02] Total plugins: 61999 — expected pages: 310
[2026-03-21 02:00:12] Page 10/310 — inserted: 0, updated: 2000
...
[2026-03-21 02:07:44] Done. Inserted: 12 | Updated: 61987 | Unchanged: 0 | Errors: 0
```

---

## Systemd setup

Four unit files are provided (two per cron):

| File | Purpose |
|---|---|
| `plugininsight-fetch-new-plugins.service` | Runs `fetch-new-plugins.php` as a one-shot job |
| `plugininsight-fetch-new-plugins.timer` | Fires every 5 minutes |
| `plugininsight-fetch-all-plugins.service` | Runs `fetch-all-plugins.php` as a one-shot job |
| `plugininsight-fetch-all-plugins.timer` | Fires daily at 02:00 UTC |

### Install

The unit files are already installed in `/etc/systemd/system/`. To enable both timers:

```bash
systemctl daemon-reload

systemctl enable --now plugininsight-fetch-new-plugins.timer
systemctl enable --now plugininsight-fetch-all-plugins.timer
```

### Verify

```bash
# List all active PluginInsight timers with next trigger times
systemctl list-timers 'plugininsight-*'

# Status of each timer
systemctl status plugininsight-fetch-new-plugins.timer
systemctl status plugininsight-fetch-all-plugins.timer

# Run a job immediately (without waiting for the timer)
systemctl start plugininsight-fetch-new-plugins.service
systemctl start plugininsight-fetch-all-plugins.service

# Follow live output
journalctl -u plugininsight-fetch-new-plugins.service -f
journalctl -u plugininsight-fetch-all-plugins.service -f

# Review last run
journalctl -u plugininsight-fetch-new-plugins.service -n 50 --no-pager
journalctl -u plugininsight-fetch-all-plugins.service -n 50 --no-pager
```

### Disable

```bash
systemctl disable --now plugininsight-fetch-new-plugins.timer
systemctl disable --now plugininsight-fetch-all-plugins.timer
```

---

## Code validation

```bash
phpcs --standard=PSR12 src/ *.php
```
