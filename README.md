# PluginInsight Crons

PHP CLI scripts that fetch, synchronize, and analyze WordPress.org plugin data.

---

## Overview

Three crons work in pipeline:

| Script | Frequency | Purpose |
|---|---|---|
| `fetch-new-plugins.php` | Every 5 min | Fetches the 200 most recently updated plugins (`browse=updated`) and upserts them with their version history |
| `fetch-all-plugins.php` | Daily at 02:00 UTC | Full sweep of all ~62 000 plugins (`browse=new`); inserts new ones and refreshes all data |
| `validate-plugins.php` | Every minute | Downloads and extracts ZIP files for pending versions, validates `readme.txt`, and publishes to RabbitMQ |
| `cleanup-plugins.php` | Every hour | Deletes extracted plugin directories older than 6 hours and clears `plugin_version_path` in the database |

---

## Requirements

- PHP 8.3–8.5 with extensions: `mysqli`, `curl`, `zip`, `amqp`
- MariaDB 11.4+
- RabbitMQ with exchange `plugin.analysis.all` (fanout)

Install the AMQP extension:
```bash
apt-get install php8.4-amqp
```

---

## Configuration

Two files must be created from their `.example.php` templates before running any script:

### `../dbcon.php`
Database connection — lives one level above the repo root. See the host server's `/webs/plugininsight/database.txt` for credentials.

### `rabbitmq.php`
```bash
cp rabbitmq.example.php rabbitmq.php
# then edit rabbitmq.php with real credentials
```

| Constant | Default | Description |
|---|---|---|
| `RABBITMQ_HOST` | `127.0.0.1` | Broker host |
| `RABBITMQ_PORT` | `5672` | Broker port |
| `RABBITMQ_USER` | — | Login |
| `RABBITMQ_PASS` | — | Password |
| `RABBITMQ_VHOST` | `/` | Virtual host |
| `RABBITMQ_EXCHANGE` | `plugin.analysis.all` | Fanout exchange to publish to |
| `RABBITMQ_QUEUE` | `plugin-validation` | Routing key (ignored by fanout) |

Both files are gitignored.

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
| `plugin_version` | varchar(250) PK | release tag, e.g. `2.7.3` |
| `plugin_version_zip` | varchar(500) | download URL for that release |
| `plugin_version_path` | varchar(500) | absolute path to the extracted directory; NULL until validated |
| `plugin_version_tested` | datetime | set when validation completes; NULL = pending |
| `plugin_version_path` | varchar(500) | absolute path to the extracted directory; NULL until validated, reset to NULL after cleanup |

`trunk` is skipped — it is a floating pointer, not a discrete release.

### Schema versioning

Migrations run automatically on every script startup. The current version (`DB_VERSION = 1.5.0`) is stored in `plugin_schema_meta` and compared against the constant; pending migrations are applied in order.

To reset the validation queue:
```sql
UPDATE plugin_version SET plugin_version_tested = NULL, plugin_version_path = NULL;
```

---

## RabbitMQ topology

```
plugin.analysis.all  (fanout)
    ├── plugin.analysis.ai       (fanout) → queue: plugin.analysis.ai
    ├── plugin.analysis.basic    (fanout) → queue: plugin.analysis.basic
    └── plugin.analysis.security (fanout) → queue: plugin.analysis.security
```

Each validated plugin version is published as a persistent JSON message:

```json
{
  "name": "inline-context",
  "source": "wordpress.org",
  "version": "2.7.3",
  "src": "/webs/plugininsight/extracted/inline-context/2.7.3"
}
```

To check queue depths:
```bash
rabbitmqctl list_queues name messages_ready
```

To purge all queues:
```bash
rabbitmqadmin purge queue name=plugin.analysis.ai
rabbitmqadmin purge queue name=plugin.analysis.basic
rabbitmqadmin purge queue name=plugin.analysis.security
```

---

## Running manually

```bash
cd /webs/plugininsight/crons

# Sync recently updated plugins (page 1 only)
php8.4 fetch-new-plugins.php

# Full sync — all plugins, all pages (takes several minutes)
php8.4 fetch-all-plugins.php

# Validate a batch of 10 pending plugin versions
php8.4 validate-plugins.php
```

---

## Systemd setup

Six unit files, two per cron:

| File | Purpose |
|---|---|
| `plugininsight-fetch-new-plugins.service` | Runs `fetch-new-plugins.php` |
| `plugininsight-fetch-new-plugins.timer` | Every 5 minutes |
| `plugininsight-fetch-all-plugins.service` | Runs `fetch-all-plugins.php` |
| `plugininsight-fetch-all-plugins.timer` | Daily at 02:00 UTC |
| `plugininsight-validate-plugins.service` | Runs `validate-plugins.php` |
| `plugininsight-validate-plugins.timer` | Every minute |
| `plugininsight-cleanup-plugins.service` | Runs `cleanup-plugins.php` |
| `plugininsight-cleanup-plugins.timer` | Every hour |

### Install

Unit files are already installed in `/etc/systemd/system/`. Enable all timers:

```bash
systemctl daemon-reload
systemctl enable --now plugininsight-fetch-new-plugins.timer
systemctl enable --now plugininsight-fetch-all-plugins.timer
systemctl enable --now plugininsight-validate-plugins.timer
systemctl enable --now plugininsight-cleanup-plugins.timer
```

### Verify

```bash
# All timers and next trigger times
systemctl list-timers 'plugininsight-*'

# Run a job immediately
systemctl start plugininsight-fetch-new-plugins.service
systemctl start plugininsight-fetch-all-plugins.service
systemctl start plugininsight-validate-plugins.service
systemctl start plugininsight-cleanup-plugins.service

# Follow live output
journalctl -u plugininsight-fetch-new-plugins.service -f
journalctl -u plugininsight-fetch-all-plugins.service -f
journalctl -u plugininsight-validate-plugins.service -f
journalctl -u plugininsight-cleanup-plugins.service -f
```

### Disable

```bash
systemctl disable --now plugininsight-fetch-new-plugins.timer
systemctl disable --now plugininsight-fetch-all-plugins.timer
systemctl disable --now plugininsight-validate-plugins.timer
systemctl disable --now plugininsight-cleanup-plugins.timer
```

---

## Code validation

```bash
phpcs --standard=PSR12 src/ *.php
```
