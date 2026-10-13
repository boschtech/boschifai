# Deploying to a VPS (Hostinger KVM)

Runs the whole stack — `caddy`, `app`, `worker`, `scheduler`, `test-runner`, `db` — on one Linux VM with
Docker Compose: `docker-compose.yml` plus the production overrides in
`docker-compose.prod.yml`. Nothing here is Hostinger-specific beyond creating the VM; any Ubuntu
VPS works the same way.

> **Before real customers use it:** the open item **D11** (agent isolation) in
> [`outstanding-decisions.md`](outstanding-decisions.md) is marked as blocking production
> multi-tenant use. The agent can read anything the worker can read, including `.env`. This
> runbook doesn't change that. Treat the result as fine for an internal or pilot install until
> D11 is decided.

## 1. Before you start

| Item | Notes |
| --- | --- |
| VPS | Ubuntu 24.04, **≥ 4 vCPU / 8 GB RAM / 100 GB disk** to start. Each run does a `composer install` plus a full test suite in `test-runner`, and every connected repo keeps a checkout on disk. |
| Data-centre region | Choose deliberately. Customer source code, user emails and payment records live here. If it's outside South Africa that's a cross-border transfer under POPIA s72. Confirm with compliance (compare D13). |
| Domain | An A record (and AAAA, if the VPS has IPv6) pointing at the VPS. Caddy needs it resolving **before** first start to get a certificate. |
| GitHub OAuth App | Callback URL `https://boschifai.boschtechnologies.com/github/callback`. Use a separate OAuth App from your dev one. |
| Paystack | Webhook URL `https://boschifai.boschtechnologies.com/webhooks/payments/paystack`. Live keys only once D3–D6 are settled. |
| Secrets | `ANTHROPIC_API_KEY`, the GitHub OAuth secret and `PAYSTACK_SECRET_KEY` go into `.env` on the server (mode 600). `.env.example` says production should use AWS SSM. A VPS has no SSM, so this is a known gap for the team to accept or close. |

## 2. Prepare the server (once)

As root on a fresh VM (tested on Ubuntu 26.04):

```bash
# A deploy user with your SSH key. It has no password, so it can't sudo: anything that needs root
# is done here, and root keeps key-only SSH for later admin work.
adduser --disabled-password --gecos "" deploy
install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
install -m 600 -o deploy -g deploy /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
# Check `ssh deploy@<server>` works from your machine before continuing.

# Key-only SSH. A drop-in, not sshd_config: Hostinger's cloud-init drop-ins set
# PasswordAuthentication yes, and sshd takes the first value it reads, so this file sorts first.
cat > /etc/ssh/sshd_config.d/00-boschifai-hardening.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
CONF
sshd -t && systemctl reload ssh
sshd -T | grep -E '^(passwordauthentication|permitrootlogin) '   # expect: no / prohibit-password

# Security updates install themselves.
export DEBIAN_FRONTEND=noninteractive
apt-get update && apt-get install -y unattended-upgrades
echo "unattended-upgrades unattended-upgrades/enable_auto_updates boolean true" | debconf-set-selections
dpkg-reconfigure -f noninteractive unattended-upgrades

# Firewall: SSH + web only. Set the same rules in hPanel's VPS firewall too, and sync it.
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw allow 443/udp && ufw --force enable

# Docker Engine + Compose plugin (Docker's own apt repository).
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy
docker compose version   # must be v2.24.4 or newer

# Directories the deploy user owns (it can't create them itself without sudo).
install -d -o deploy -g deploy /opt/boschifai /var/backups/boschifai
install -m 640 -o deploy -g deploy /dev/null /var/log/boschifai-backup.log
```

**Docker bypasses `ufw` for published ports.** That's why `docker-compose.prod.yml` publishes
only Caddy's 80/443. Never add `ports:` to `app` or `db` on this host.

Check your Hostinger plan's firewall and snapshot settings in hPanel too. A VM-level snapshot
before each deploy is a cheap rollback point.

## 3. First deploy

As `deploy`:

```bash
git clone https://github.com/boschtech/boschifai.git /opt/boschifai   # public repo: no key needed
cd /opt/boschifai

cp .env.example .env && chmod 600 .env
mkdir -p var/no-local-repos var/boschifai/repos
```

Edit `.env`. At a minimum:

```dotenv
# Read by Docker Compose, not Laravel: every `docker compose` run in this directory layers the
# production overrides, whoever runs it and from whatever shell. Don't rely on an exported
# variable in one user's ~/.bashrc instead: on the first deploy a compose run without it
# recreated app/worker/scheduler from the dev file alone (root/root DB login, crash loop, port
# 8420 published).
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml

APP_NAME=BoschifAI
APP_URL=https://boschifai.boschtechnologies.com
BOSCHIFAI_DOMAIN=boschifai.boschtechnologies.com            # read by Caddy

DB_CONNECTION=mysql
DB_HOST=db
DB_DATABASE=boschifai
DB_USERNAME=boschifai
DB_PASSWORD=<openssl rand -base64 32>

LOG_LEVEL=warning
SESSION_ENCRYPT=true

ANTHROPIC_API_KEY=...
BOSCHIFAI_GITHUB_OAUTH_CLIENT_ID=...
BOSCHIFAI_GITHUB_OAUTH_CLIENT_SECRET=...
GITHUB_MCP_PAT=                      # leave blank; the MCP push path is forced off in production

# Mail — SES (README "Sending email"), or any SMTP server. With Google Workspace's SMTP relay
# (Admin console → Gmail → Routing → SMTP relay: only addresses in your domains, allow this
# server's IPs, require TLS) no password is stored:
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.gmail.com
MAIL_PORT=587
MAIL_FROM_ADDRESS="no-reply@boschtechnologies.com"
MAIL_FROM_NAME="BoschifAI"

# Billing and Paystack: see README "Credits".
```

Check every container was created from both files — `app` crash-looping with "Access denied for
user 'root'" means one wasn't:

```bash
docker inspect boschifai-app -f '{{index .Config.Labels "com.docker.compose.project.config_files"}}'
```

You don't need to set `APP_ENV`, `APP_DEBUG`, `SESSION_SECURE_COOKIE`, `TRUSTED_PROXIES` or the
two feature flags. `docker-compose.prod.yml` pins them and wins over `.env`.

Build, install dependencies, generate keys, start:

```bash
docker compose build
docker compose run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction
docker compose run --rm --no-deps app sh -c "npm ci && npm run build"
docker compose run --rm --no-deps app php artisan key:generate --force
docker compose run --rm --no-deps app php artisan boschifai:generate-credentials-key   # prints a key: set it as BOSCHIFAI_CREDENTIALS_KEY in .env
docker compose up -d
```

**Copy the finished `.env` into the team password manager now.** `APP_KEY` and
`BOSCHIFAI_CREDENTIALS_KEY` decrypt sessions and every workspace's stored AI-provider keys. If
you lose them, a database backup is useless for those keys.

MySQL generates a random root password on first start and writes it once to the `db` logs. The
app never needs it. Leave it unrecorded unless ops wants it for break-glass access.

Create the first user and check the deployment:

```bash
docker compose exec app php artisan boschifai:create-user you@example.com --workspace="Boschifai"
curl -fsS https://boschifai.boschtechnologies.com/up                 # 200 = app + TLS ok
docker compose ps                             # all services "running"; db "healthy"
docker compose logs --tail=50 worker          # "Processing jobs from the [default] queue"
```

Send a test email (`docker compose exec app php artisan boschifai:send-test-email you@example.com`),
then do one complete pipeline run against a throwaway repo before anyone else signs in.

**Don't run `php artisan config:cache` or `optimize`.** `app` and `worker` share the same source
directory but have different environments (only `worker` has `ANTHROPIC_API_KEY`). A cached
config built in one container would be wrong in the other.

## 4. Routine deploy

```bash
cd /opt/boschifai

# 1. Make sure no step is mid-flight: stopping the worker kills a running step after 2 minutes.
docker compose exec db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
  -e "SELECT COUNT(*) AS running FROM jobs WHERE reserved_at IS NOT NULL"'

# 2. Back up, then update.
./docker/production/backup.sh
git pull --ff-only
docker compose build
docker compose run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction
docker compose run --rm --no-deps app sh -c "npm ci && npm run build"

# 3. Restart. The source is bind-mounted, so a code-only change doesn't recreate the containers
#    on its own. Migrations run as `app` and `worker` start.
docker compose up -d --force-recreate app worker scheduler test-runner
docker compose up -d                          # anything else whose config changed (caddy, db)
curl -fsS https://boschifai.boschtechnologies.com/up
```

**Rollback:** `git checkout <previous-sha>`, repeat step 3, and restore the pre-deploy backup if
a migration changed data. Better still, roll back to the VM snapshot.

## 5. Backups

`docker/production/backup.sh` writes a consistent MySQL dump plus `storage/app` (it skips the
temporary decrypted credential files) to `/var/backups/boschifai` and keeps 14 days. Schedule it
nightly in the `deploy` user's crontab (`crontab -e`):

```cron
15 2 * * * /opt/boschifai/docker/production/backup.sh >> /var/log/boschifai-backup.log 2>&1
```

(The backup directory and log file are created in §2.)

**This copy stays on the same machine, which doesn't count as a backup.** Ship it off the box
nightly to object storage in a region compliance has approved, for example S3 in `af-south-1`
with `aws s3 sync`. Test a restore at least once:

```bash
gunzip -c db-<stamp>.sql.gz | docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

Repo checkouts under `var/` aren't backed up. They're re-cloned from GitHub.

## 6. Scheduled commands

The `scheduler` container runs Laravel's scheduler (`php artisan schedule:work`) — no host cron
needed. **Every hour, at half past** (`routes/console.php`):

- `boschifai:refresh-exchange-rates` — the ECB's daily reference rate for rand quotes. If it keeps
  failing, rand payments stop once the last rate is 4 days old; check the scheduler's log.
- `boschifai:release-stale-holds` — returns credit holds left by a worker that died mid-step.
- `boschifai:expire-credits` — removes credits past their 36-month validity and emails owners and
  admins 60 and 14 days beforehand.

```bash
docker compose logs --tail=50 scheduler          # each run, with the command's output
docker compose exec scheduler php artisan schedule:list   # what's scheduled and when it runs next
```

If you added the earlier hourly `release-stale-holds` host cron, remove it.

## 7. Operating it

| Task | Command |
| --- | --- |
| Logs | `docker compose logs -f worker` (also `app`, `scheduler`, `test-runner`, `caddy`). Capped at 5 × 20 MB per container. |
| Laravel log | `tail -f storage/logs/laravel.log` |
| Shell | `docker compose exec worker bash` |
| Restart a stuck worker | `docker compose restart worker`. Any step it was running fails and needs re-running. |
| Disk | `df -h /` and `du -sh var/boschifai/repos`. Checkouts grow with every connected repo. |
| Rotate the credentials key | `php artisan boschifai:rotate-credentials-key` (see README) |

Add uptime monitoring on `https://boschifai.boschtechnologies.com/up` and alert on failures. The org standard for
errors is Sentry, which isn't wired into this app yet.

## Known limitations

- **PHP's built-in web server** (`php -S`, run with `PHP_CLI_SERVER_WORKERS=4`) serves `app`. It
  copes with a small team behind Caddy, but it isn't a hardened production server. The image is
  already `php-fpm` based, so nginx + php-fpm is the natural next step if load or security review
  calls for it.
- **One worker.** Steps run one at a time across all workspaces. `container_name` in the base
  file also stops `docker compose up --scale worker=N`. Adding workers widens D11's cross-workspace
  exposure, so treat it as part of that decision.
- **Single machine.** No failover. Recovery means rebuilding the VM and restoring a backup. If
  the business needs more than that, the team could evaluate managed hosting (e.g. AWS ECS + EFS
  for the shared checkout volume, RDS for MySQL).
