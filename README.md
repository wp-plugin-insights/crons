# PluginInsight Crons

PHP CLI scripts that fetch and synchronize WordPress.org plugin data into the PluginInsight database.

## Scripts

### `fetch-new-plugins.php`

Fetches page 1 of the WordPress.org Plugins API (`browse=new`, 200 plugins per request) and upserts each plugin into the `plugin` table. Fields synchronized:

| DB column | API field |
|---|---|
| `plugin_slug` | `slug` |
| `plugin_version` | `version` |
| `plugin_installs` | `active_installs` |
| `plugin_zip` | `download_link` |
| `plugin_name` | `name` |
| `plugin_requires` | `requires` |
| `plugin_tested` | `tested` |
| `plugin_requires_php` | `requires_php` |
| `plugin_requires_plugins` | `requires_plugins` (JSON) |
| `plugin_rating` | `rating` |
| `plugin_num_ratings` | `num_ratings` |
| `plugin_support_threads` | `support_threads` |
| `plugin_support_threads_resolved` | `support_threads_resolved` |
| `plugin_downloaded` | `downloaded` |
| `plugin_last_updated` | `last_updated` |
| `plugin_added` | `added` |

Each run uses a single `INSERT … ON DUPLICATE KEY UPDATE` per plugin, so it is safe to run as frequently as needed with no risk of duplicates.

**Schema migrations** run automatically on startup. The current schema version is stored in the `plugin_schema_meta` table and compared against the `DB_VERSION` constant defined in `fetch-new-plugins.php`.

## Requirements

- PHP 8.3–8.5 with the `mysqli` and `curl` extensions
- MariaDB 11.4+
- Database credentials in `/webs/plugininsight/database.txt` (already wired in `dbcon.php`)

## Running manually

```bash
cd /webs/plugininsight/crons
php8.4 fetch-new-plugins.php
```

Example output:

```
Done. Inserted: 3 | Updated: 197 | Unchanged: 0
```

## Systemd setup

Two unit files are provided:

| File | Purpose |
|---|---|
| `plugininsight-fetch-new-plugins.service` | Runs the PHP script as a one-shot job |
| `plugininsight-fetch-new-plugins.timer` | Fires the service every 5 minutes |

### Install

Copy the unit files and enable the timer:

```bash
cp /webs/plugininsight/crons/plugininsight-fetch-new-plugins.service /etc/systemd/system/
cp /webs/plugininsight/crons/plugininsight-fetch-new-plugins.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now plugininsight-fetch-new-plugins.timer
```

### Verify

```bash
# Timer status and next trigger time
systemctl status plugininsight-fetch-new-plugins.timer

# Run the job immediately (outside the timer schedule)
systemctl start plugininsight-fetch-new-plugins.service

# Follow live output
journalctl -u plugininsight-fetch-new-plugins.service -f
```

### Disable

```bash
systemctl disable --now plugininsight-fetch-new-plugins.timer
```

## Code validation

```bash
phpcs --standard=PSR12 src/ *.php
```
