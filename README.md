# PluginInsight Crons

PHP CLI scripts that fetch, synchronise, and enrich WordPress plugin data.

---

## Overview

| Script | Frequency | Purpose |
|---|---|---|
| `fetch-new-plugins.php` | Every 5 min | Fetches the 200 most recently updated plugins (`browse=updated`) and upserts metadata + version history |
| `fetch-all-plugins.php` | Daily 02:00 UTC | Full sweep of all ~62 000 plugins (`browse=new`); inserts new records and refreshes all metadata |
| `validate-plugins.php` | Every 1 min | Downloads and extracts ZIP files for pending versions, parses `readme.txt`, publishes to RabbitMQ |
| `cleanup-plugins.php` | Every 1 hr | Deletes extracted plugin directories older than 6 hours; resets `plugin_version_path` to NULL |
| `fetch-wp-versions.php` | Every 1 hr | Fetches WordPress core release list from WP.org; stores one row per major.minor branch in `site_setting` |
| `fetch-wp-locales.php` | Weekly (Mon 00:00 UTC) | Fetches WordPress locale metadata from WP.org translations API; upserts one row per language code into `wp_locale` |

---

## Requirements

- **PHP 8.3–8.5** with extensions: `mysqli`, `curl`, `zip`, `amqp`
- **MariaDB 11.4+**
- **RabbitMQ** with fanout exchange `plugin.analysis.all`

---

## Database schema

Current version: **`DB_VERSION = 2.3.0`** (stored in `plugin_schema_meta`).

Migrations run automatically on every script startup and are always idempotent.

### `plugin` — one row per (source, slug)

Composite UNIQUE key: `(plugin_source, plugin_slug)`.

| Column | Type | Source |
|---|---|---|
| `plugin_id` | bigint PK | auto |
| `plugin_source` | varchar(250) NOT NULL | `wordpress.org` for WP.org plugins; `api` for uploaded plugins |
| `plugin_slug` | varchar(250) | `slug` |
| `plugin_version` | varchar(250) | `version` (latest) |
| `plugin_installs` | int unsigned | `active_installs` |
| `plugin_zip` | varchar(500) | `download_link` |
| `plugin_name` | varchar(250) | `name` (may contain HTML entities) |
| `plugin_author` | varchar(250) | `author` (HTML fragment) |
| `plugin_author_profile` | varchar(500) | `author_profile` |
| `plugin_homepage` | varchar(500) | `homepage` |
| `plugin_requires` | varchar(20) | `requires` |
| `plugin_tested` | varchar(20) | `tested` |
| `plugin_requires_php` | varchar(20) | `requires_php` |
| `plugin_requires_plugins` | text JSON | `requires_plugins` |
| `plugin_short_description` | text | `short_description` |
| `plugin_rating` | tinyint unsigned | `rating` (0–100) |
| `plugin_num_ratings` | int unsigned | `num_ratings` |
| `plugin_support_threads` | int unsigned | `support_threads` |
| `plugin_support_threads_resolved` | int unsigned | `support_threads_resolved` |
| `plugin_downloaded` | bigint unsigned | `downloaded` |
| `plugin_last_updated` | datetime | `last_updated` |
| `plugin_added` | date | `added` |
| `plugin_icons` | text JSON | `icons` |

> API-uploaded plugins (`plugin_source='api'`) are excluded from all public frontend queries.

### `plugin_version` — one row per (plugin, version)

| Column | Type | Notes |
|---|---|---|
| `plugin_id` | bigint FK | references `plugin.plugin_id` |
| `plugin_version` | varchar(250) PK | release tag, e.g. `2.7.3`; `trunk` is skipped |
| `plugin_version_zip` | varchar(500) | download URL |
| `plugin_version_path` | varchar(500) | absolute path to extracted directory; NULL until validated, reset to NULL after cleanup |
| `plugin_version_tested` | datetime | set when validation completes; NULL = pending |

### `pluginresult` — one row per (plugin, version, runner, run)

| Column | Type | Notes |
|---|---|---|
| `plugin_id` | bigint NOT NULL FK | references `plugin.plugin_id` |
| `plugin_version` | varchar(250) | version string |
| `runner_id` | int FK | references `runner.runner_id` |
| `pluginresult_result` | longtext JSON | full runner output; must satisfy `JSON_VALID` |
| `pluginresult_date` | datetime | when the result was stored |

### `plugin_upload` — slim upload tracking for API uploads

| Column | Type | Notes |
|---|---|---|
| `upload_uuid` | char(36) PK | UUID v4; primary external identifier |
| `upload_ip` | varchar(45) | uploader IP address |
| `plugin_id` | bigint NOT NULL FK | references `plugin.plugin_id` |
| `plugin_version` | varchar(250) | version string at upload time |
| `upload_path` | varchar(500) | absolute path to extracted plugin directory |
| `upload_status` | enum | `pending` → `queued` → `done` / `error` |
| `upload_error` | text | error message if status is `error` |
| `uploaded_at` | datetime | |
| `processed_at` | datetime | set when a runner finishes |

### `runner` — one row per RabbitMQ consumer worker

| Column | Type | Notes |
|---|---|---|
| `runner_id` | int PK | auto |
| `runner_name` | varchar(100) | display name |
| `runner_slug` | varchar(50) UNIQUE | machine identifier |
| `runner_queue` | varchar(250) | RabbitMQ queue name |
| `runner_is_active` | tinyint | 1 = active |
| `created_at` | datetime | |

Default runners inserted on first migration: `ai`, `basic`, `security`.

### `site_setting` — key-value runtime config

| Key | Value |
|---|---|
| `api_active` | `1` / `0` — whether the upload API accepts new requests |
| `api_hostname` | hostname shown on the API docs page |
| `wp_versions` | JSON array of `{version, php_min, mysql_min}`, one per major.minor WP branch, newest first |

### `wp_locale` — WordPress locale metadata (since 1.9.0)

| Column | Type | Notes |
|---|---|---|
| `locale_language` | varchar(20) PK | language code, e.g. `es`, `fr`, `zh_CN` |
| `locale_english_name` | varchar(150) | e.g. `Spanish (Spain)` |
| `locale_native_name` | varchar(150) | e.g. `Español` |
| `locale_synced_at` | datetime | updated automatically on each upsert |

### Schema versioning

To reset the validation queue:

```sql
UPDATE plugin_version SET plugin_version_tested = NULL, plugin_version_path = NULL;
```

---

## RabbitMQ topology

```
plugin.analysis.all  (fanout exchange)
    ├── plugin.analysis.ai       → queue: plugin.analysis.ai
    ├── plugin.analysis.basic    → queue: plugin.analysis.basic
    └── plugin.analysis.security → queue: plugin.analysis.security
```

Each validated plugin version is published as a persistent JSON message:

```json
{
  "plugin": "inline-context",
  "source": "wordpress.org",
  "version": "2.7.3",
  "src": "/webs/plugininsight/extracted/inline-context/2.7.3"
}
```

- `plugin` — the plugin slug
- `source` — `wordpress.org` for WP.org plugins; `api` for plugins uploaded via the API
- `version` — the version string
- `src` — absolute path to the extracted plugin directory

```bash
# Check queue depths
rabbitmqctl list_queues name messages_ready

# Purge a queue
rabbitmqadmin purge queue name=plugin.analysis.ai
```

---

## Running manually

```bash
cd /webs/plugininsight/crons

php8.4 fetch-new-plugins.php      # sync recently updated plugins
php8.4 fetch-all-plugins.php      # full sync (takes several minutes)
php8.4 validate-plugins.php       # validate a batch of pending versions
php8.4 cleanup-plugins.php        # delete stale extracted directories
php8.4 fetch-wp-versions.php      # refresh WP core version list
php8.4 fetch-wp-locales.php       # refresh WP locale list
```

---

## Migrating to a new server (copy-paste guide)

Everything below is designed so that an operator who has never seen this project
before can get it running on a fresh Debian/Ubuntu server by following the steps
in order. Commands that must be customised are marked with `# ← CHANGE THIS`.

### 1. Install system packages

```bash
apt-get update
apt-get install -y \
    php8.4 php8.4-cli php8.4-mysqli php8.4-curl php8.4-zip php8.4-amqp \
    mariadb-server \
    rabbitmq-server \
    git
```

### 2. Create the system user

The cron scripts run as a dedicated unprivileged user. Create it once:

```bash
useradd --system --no-create-home --shell /usr/sbin/nologin plugininsight
```

### 3. Create the directory structure

```bash
mkdir -p /webs/plugininsight/{zipfiles,extracted,logs}
mkdir -p /webs/plugininsight/extracted/uploads

# Clone or copy the project (adjust to your source)
# git clone <repo-url> /webs/plugininsight   # ← CHANGE THIS

chown -R plugininsight:plugininsight /webs/plugininsight
chmod -R 750 /webs/plugininsight
```

The layout must be:

```
/webs/plugininsight/
├── www.plugininsight.com/    # frontend
├── api.plugininsight.com/    # upload API
├── crons/                    # this directory
├── extracted/                # plugin extraction target
│   └── uploads/              # API-uploaded ZIPs extracted here
├── zipfiles/                 # temporary ZIP staging (API uploads)
├── logs/                     # application logs
├── dbcon.php                 # shared DB connection
└── secrets.php               # app secrets (HMAC key, mail from, base URL)
```

### 4. Set up the database

```bash
# Start MariaDB and secure it
systemctl enable --now mariadb
mysql_secure_installation
```

Then create the database and user:

```sql
-- Run as root: mysql -u root
CREATE DATABASE plugininsight CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'plugininsight'@'127.0.0.1' IDENTIFIED BY 'CHANGE_PASSWORD_HERE';  -- ← CHANGE THIS
GRANT ALL PRIVILEGES ON plugininsight.* TO 'plugininsight'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The schema is created automatically on first run via migrations.

### 5. Create `dbcon.php`

```bash
cat > /webs/plugininsight/dbcon.php << 'EOF'
<?php

declare(strict_types=1);

const DB_HOST    = '127.0.0.1';
const DB_PORT    = 3306;
const DB_NAME    = 'plugininsight';
const DB_USER    = 'plugininsight';
const DB_PASS    = 'CHANGE_PASSWORD_HERE';   // ← CHANGE THIS
const DB_CHARSET = 'utf8mb4';

function db_connect(): mysqli
{
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($db->connect_errno) {
        throw new RuntimeException(
            'DB connection failed: ' . $db->connect_error,
            $db->connect_errno
        );
    }

    $db->set_charset(DB_CHARSET);

    return $db;
}
EOF
chown plugininsight:plugininsight /webs/plugininsight/dbcon.php
chmod 640 /webs/plugininsight/dbcon.php
```

### 6. Create `secrets.php`

```bash
cat > /webs/plugininsight/secrets.php << 'EOF'
<?php

declare(strict_types=1);

/** HMAC key for password-reset token hashing. */
const APP_SECRET = 'CHANGE_TO_64_HEX_CHARS';   // ← CHANGE THIS (openssl rand -hex 32)

/** Address used in the From: header of system e-mails. */
const MAIL_FROM = 'noreply@plugininsight.com';  // ← CHANGE THIS

/** Public base URL (no trailing slash). */
const APP_URL = 'https://www.plugininsight.com'; // ← CHANGE THIS
EOF
chown plugininsight:plugininsight /webs/plugininsight/secrets.php
chmod 640 /webs/plugininsight/secrets.php
```

Generate the `APP_SECRET` value:

```bash
openssl rand -hex 32
```

### 7. Set up RabbitMQ

```bash
systemctl enable --now rabbitmq-server

# Create a dedicated vhost and user (replace passwords)
rabbitmqctl add_vhost /
rabbitmqctl add_user plugininsight CHANGE_RABBITMQ_PASS   # ← CHANGE THIS
rabbitmqctl set_permissions -p / plugininsight ".*" ".*" ".*"

# Enable the management UI (optional but useful)
rabbitmq-plugins enable rabbitmq_management
```

Then declare the fanout exchange and queues. Runners create their own queues
on startup, but you can pre-declare them:

```bash
rabbitmqadmin declare exchange name=plugin.analysis.all type=fanout durable=true
rabbitmqadmin declare queue    name=plugin.analysis.ai       durable=true
rabbitmqadmin declare queue    name=plugin.analysis.basic    durable=true
rabbitmqadmin declare queue    name=plugin.analysis.security durable=true
rabbitmqadmin declare binding  source=plugin.analysis.all destination=plugin.analysis.ai
rabbitmqadmin declare binding  source=plugin.analysis.all destination=plugin.analysis.basic
rabbitmqadmin declare binding  source=plugin.analysis.all destination=plugin.analysis.security
```

### 8. Create `crons/rabbitmq.php`

```bash
cat > /webs/plugininsight/crons/rabbitmq.php << 'EOF'
<?php

declare(strict_types=1);

const RABBITMQ_HOST     = '127.0.0.1';
const RABBITMQ_PORT     = 5672;
const RABBITMQ_USER     = 'plugininsight';       // ← CHANGE THIS if different
const RABBITMQ_PASS     = 'CHANGE_RABBITMQ_PASS'; // ← CHANGE THIS
const RABBITMQ_VHOST    = '/';
const RABBITMQ_EXCHANGE = 'plugin.analysis.all';
EOF
chown plugininsight:plugininsight /webs/plugininsight/crons/rabbitmq.php
chmod 640 /webs/plugininsight/crons/rabbitmq.php
```

### 9. Set up database backup scripts

```bash
mkdir -p /webs/plugininsight/database
chown plugininsight:plugininsight /webs/plugininsight/database
chmod 750 /webs/plugininsight/database

# Copy backup-schema.sh and backup-data.sh from the repo, then:
chmod 750 /webs/plugininsight/database/backup-schema.sh
chmod 750 /webs/plugininsight/database/backup-data.sh

# Create the credentials file
cat > /webs/plugininsight/database/.db-credentials.cnf << 'EOF'
[client]
host     = 127.0.0.1
port     = 3306
user     = plugininsight
password = CHANGE_PASSWORD_HERE   # ← CHANGE THIS
EOF
chmod 640 /webs/plugininsight/database/.db-credentials.cnf
chown plugininsight:plugininsight /webs/plugininsight/database/.db-credentials.cnf
```

See `database/README.md` for full details on the backup scripts.

### 10. Install systemd unit files

Create all 16 files (8 services + 8 timers):

```bash
# ── fetch-new-plugins ────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-fetch-new-plugins.service << 'EOF'
[Unit]
Description=PluginInsight — fetch new plugins from WordPress.org API
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php8.4 /webs/plugininsight/crons/fetch-new-plugins.php
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/crons
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-fetch-new-plugins.timer << 'EOF'
[Unit]
Description=PluginInsight — fetch new plugins every 5 minutes

[Timer]
OnCalendar=*:0/5
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── fetch-all-plugins ────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-fetch-all-plugins.service << 'EOF'
[Unit]
Description=PluginInsight — full sync of all plugins from WordPress.org API
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php8.4 /webs/plugininsight/crons/fetch-all-plugins.php
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/crons
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-fetch-all-plugins.timer << 'EOF'
[Unit]
Description=PluginInsight — full plugin sync once per day

[Timer]
OnCalendar=*-*-* 02:00:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── validate-plugins ─────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-validate-plugins.service << 'EOF'
[Unit]
Description=PluginInsight — validate plugin ZIPs and publish to RabbitMQ
After=network-online.target mariadb.service rabbitmq-server.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php8.4 /webs/plugininsight/crons/validate-plugins.php
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/crons
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-validate-plugins.timer << 'EOF'
[Unit]
Description=PluginInsight — validate plugin ZIPs every minute

[Timer]
OnCalendar=*:0/1
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── cleanup-plugins ──────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-cleanup-plugins.service << 'EOF'
[Unit]
Description=PluginInsight — remove extracted plugin directories older than 6 hours
After=mariadb.service

[Service]
Type=oneshot
ExecStart=/usr/bin/php8.4 /webs/plugininsight/crons/cleanup-plugins.php
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/crons
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-cleanup-plugins.timer << 'EOF'
[Unit]
Description=PluginInsight — cleanup extracted plugin directories every hour

[Timer]
OnCalendar=*:00:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── fetch-wp-versions ────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-fetch-wp-versions.service << 'EOF'
[Unit]
Description=PluginInsight — fetch WordPress core version list
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php8.4 /webs/plugininsight/crons/fetch-wp-versions.php
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/crons
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-fetch-wp-versions.timer << 'EOF'
[Unit]
Description=PluginInsight — fetch WordPress core versions every hour

[Timer]
OnCalendar=hourly
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── fetch-wp-locales ─────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-fetch-wp-locales.service << 'EOF'
[Unit]
Description=PluginInsight — fetch WordPress locale list
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php8.4 /webs/plugininsight/crons/fetch-wp-locales.php
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/crons
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-fetch-wp-locales.timer << 'EOF'
[Unit]
Description=PluginInsight — fetch WordPress locales once per week

[Timer]
OnCalendar=weekly
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── db-backup-schema ──────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-db-backup-schema.service << 'EOF'
[Unit]
Description=PluginInsight — dump database schema (DDL only) to database/schema.sql
After=mariadb.service

[Service]
Type=oneshot
ExecStart=/usr/bin/bash /webs/plugininsight/database/backup-schema.sh
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/database
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-db-backup-schema.timer << 'EOF'
[Unit]
Description=PluginInsight — dump database schema every hour

[Timer]
OnCalendar=*:00:00
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ── db-backup-data ────────────────────────────────────────────────────────────
cat > /etc/systemd/system/plugininsight-db-backup-data.service << 'EOF'
[Unit]
Description=PluginInsight — dump database data to database/data-<timestamp>.sql
After=mariadb.service

[Service]
Type=oneshot
ExecStart=/usr/bin/bash /webs/plugininsight/database/backup-data.sh
User=plugininsight
Group=plugininsight
WorkingDirectory=/webs/plugininsight/database
StandardOutput=journal
StandardError=journal
EOF

cat > /etc/systemd/system/plugininsight-db-backup-data.timer << 'EOF'
[Unit]
Description=PluginInsight — dump database data every hour (offset 5 min from schema job)

[Timer]
OnCalendar=*:05:00
Persistent=true

[Install]
WantedBy=timers.target
EOF
```

### 11. Enable all timers

```bash
systemctl daemon-reload

systemctl enable --now plugininsight-fetch-new-plugins.timer
systemctl enable --now plugininsight-fetch-all-plugins.timer
systemctl enable --now plugininsight-validate-plugins.timer
systemctl enable --now plugininsight-cleanup-plugins.timer
systemctl enable --now plugininsight-fetch-wp-versions.timer
systemctl enable --now plugininsight-fetch-wp-locales.timer
systemctl enable --now plugininsight-db-backup-schema.timer
systemctl enable --now plugininsight-db-backup-data.timer
```

Verify all timers are scheduled:

```bash
systemctl list-timers 'plugininsight-*'
```

### 12. Smoke-test

Run each script once manually as the service user to confirm connectivity:

```bash
sudo -u plugininsight php8.4 /webs/plugininsight/crons/fetch-wp-versions.php
sudo -u plugininsight php8.4 /webs/plugininsight/crons/fetch-wp-locales.php
sudo -u plugininsight php8.4 /webs/plugininsight/crons/fetch-new-plugins.php
sudo -u plugininsight php8.4 /webs/plugininsight/crons/validate-plugins.php
sudo -u plugininsight php8.4 /webs/plugininsight/crons/cleanup-plugins.php
sudo -u plugininsight bash /webs/plugininsight/database/backup-schema.sh
sudo -u plugininsight bash /webs/plugininsight/database/backup-data.sh
```

---

## Day-to-day operations

### Run a job immediately

```bash
systemctl start plugininsight-fetch-new-plugins.service
systemctl start plugininsight-fetch-all-plugins.service
systemctl start plugininsight-validate-plugins.service
systemctl start plugininsight-cleanup-plugins.service
systemctl start plugininsight-fetch-wp-versions.service
systemctl start plugininsight-fetch-wp-locales.service
systemctl start plugininsight-db-backup-schema.service
systemctl start plugininsight-db-backup-data.service
```

### Follow live output

```bash
journalctl -u plugininsight-fetch-new-plugins.service -f
journalctl -u plugininsight-fetch-all-plugins.service -f
journalctl -u plugininsight-validate-plugins.service -f
journalctl -u plugininsight-cleanup-plugins.service -f
journalctl -u plugininsight-fetch-wp-versions.service -f
journalctl -u plugininsight-fetch-wp-locales.service -f
journalctl -u plugininsight-db-backup-schema.service -f
journalctl -u plugininsight-db-backup-data.service -f
```

### Disable all timers

```bash
systemctl disable --now \
  plugininsight-fetch-new-plugins.timer \
  plugininsight-fetch-all-plugins.timer \
  plugininsight-validate-plugins.timer \
  plugininsight-cleanup-plugins.timer \
  plugininsight-fetch-wp-versions.timer \
  plugininsight-fetch-wp-locales.timer \
  plugininsight-db-backup-schema.timer \
  plugininsight-db-backup-data.timer
```

---

## Code validation

```bash
cd /webs/plugininsight

# PSR-12 style check
vendor/bin/phpcs --standard=PSR12 crons/src/ crons/*.php

# Static analysis (level 6)
cd crons && php8.4 ../vendor/bin/phpstan analyse --level=6 --configuration=phpstan.neon

# Syntax check a single file
php8.4 -l crons/src/WpLocalesFetcher.php
```
