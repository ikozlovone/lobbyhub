# Putting LobbyHub on a server

Ubuntu 24.04 or Debian 12, one machine, behind Cloudflare. Nothing here needs
Redis or Docker: queues, cache and sessions all live in Postgres.

Five processes end up running:

| Process | What it does |
|---|---|
| `nginx` | the only thing listening publicly; see [nginx/README.md](nginx/README.md) |
| `php8.4-fpm` | the API on `api.lobbyhub.gg` |
| `lobbyhub-web` | `next start` on `127.0.0.1:3000`, the site itself |
| `lobbyhub-worker` | runs the monitoring queries the scheduler dispatches |
| `lobbyhub-scheduler` | the timetable in `routes/console.php` |

## 1. Packages

```sh
sudo apt update
sudo apt install -y nginx postgresql git unzip curl \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath

# Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Node 22 — Next 16 will not run on Debian's 18
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

If PHP 8.4 is not in your distro's archive, add `ppa:ondrej/php` (Ubuntu) or
`packages.sury.org` (Debian) first. The app needs 8.3 or newer.

The server queries game servers over raw UDP and TCP streams, which is plain PHP
— no `ext-sockets`, and nothing to open outbound in the firewall beyond the
default allow-all for outgoing traffic.

## 2. Database

```sh
sudo -u postgres psql -c "CREATE USER lobbyhub WITH PASSWORD 'pick-a-real-one';"
sudo -u postgres psql -c "CREATE DATABASE lobbyhub OWNER lobbyhub;"
```

## 3. Code and who owns it

Two users, with one job each. A **deploy** user owns the checkout and is the only
one that writes to it — `git`, `composer`, `npm`, `artisan`. **www-data** runs the
three services and owns nothing: it reaches the code through the group, and can
write only to the handful of directories the runtime has to write to.

That split is worth the extra step. It means php-fpm, the worker and the frontend
cannot rewrite the application they are serving, which is the whole point; and it
means `git pull` never trips over a tree owned by somebody else — the failure
that reads as `fatal: detected dubious ownership`.

```sh
sudo adduser --system --group --home /home/deploy --shell /bin/bash deploy
sudo adduser deploy www-data

sudo mkdir -p /var/www && cd /var/www
sudo -u deploy -H git clone https://github.com/ikozlovone/lobbyhub.git
sudo chown -R deploy:www-data /var/www/lobbyhub
cd /var/www/lobbyhub
```

Now the directories the runtime writes to. `g+s` keeps the group on anything
created later, and the default ACL keeps it group-writable whatever umask the
writing process had — without that, a `laravel.log` first written by the worker
comes out `644` and the next deploy cannot touch it.

```sh
sudo apt install -y acl
sudo -u deploy -H mkdir -p storage bootstrap/cache public/images web/.next
sudo chmod -R g+rwX storage bootstrap/cache public/images web/.next
sudo find storage bootstrap/cache public/images web/.next -type d -exec chmod g+s {} +
sudo setfacl -R -m g:www-data:rwX -m d:g:www-data:rwX \
  storage bootstrap/cache public/images web/.next
```

Then the dependencies, as deploy from here on:

```sh
sudo -u deploy -H composer install --no-dev --optimize-autoloader
sudo -u deploy -H cp .env.example .env
sudo -u deploy -H php artisan key:generate
sudo chmod 640 .env          # holds the database password; www-data reads it by group
```

If something did get run as root, this shows what it left and puts it back:

```sh
sudo find /var/www/lobbyhub ! -user deploy -printf '%u %p\n' | head
sudo chown -R deploy:www-data /var/www/lobbyhub
```

## 4. `.env`

Edit `/var/www/lobbyhub/.env`. The lines that differ from the template:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.lobbyhub.gg          # this app answers on the API host

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lobbyhub
DB_USERNAME=lobbyhub
DB_PASSWORD=the-one-you-picked

FRONTEND_ORIGINS=https://lobbyhub.gg
FRONTEND_REVALIDATE_URL=http://127.0.0.1:3000/api/revalidate
FRONTEND_REVALIDATE_SECRET=<openssl rand -hex 16>

STEAM_API_KEY=...                        # discovery, and Sign in with Steam
DISCORD_CLIENT_ID=... DISCORD_CLIENT_SECRET=...
GOOGLE_CLIENT_ID=... GOOGLE_CLIENT_SECRET=...
```

**Mail is not optional here.** `MAIL_MAILER` defaults to `log`, which writes the
sign-in code to `storage/logs` instead of sending it — nobody can sign in.
Point it at a real sender:

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=hello@lobbyhub.gg
MAIL_FROM_NAME=LobbyHub
```

Providers refuse to send for a domain that has not authorised them, so add
their SPF and DKIM records in Cloudflare DNS before expecting mail to arrive.

## 5. Schema, countries, games

```sh
cd /var/www/lobbyhub
sudo -u deploy -H php artisan migrate --force
sudo -u deploy -H php artisan db:seed --force        # countries and the game catalog
```

## 6. Two data files the catalog wants

**GeoLite2** turns a server's address into a country and city. Without it geo
resolution quietly no-ops and every server is listed as unknown. Free account at
maxmind.com, then:

```sh
sudo -u deploy -H mkdir -p storage/app/geoip
# put GeoLite2-City.mmdb there (City alone is enough — it carries country data)
```

**Game artwork** is downloaded from Steam and served from this host:

```sh
sudo -u deploy -H php artisan games:artwork
```

## 7. Cache the framework's own lookups

Do this before anything serves traffic, and again after every deploy — a cached
config file pins whatever `.env` said when it was written.

```sh
cd /var/www/lobbyhub
sudo -u deploy -H php artisan config:cache
sudo -u deploy -H php artisan route:cache
sudo -u deploy -H php artisan event:cache
```

## 8. Monitoring processes

The worker and the scheduler need nothing from the web layer, so they can start
as soon as the database is ready. All three units run as `www-data`, not as the
deploy user — see section 3 — with a umask that leaves what they create writable
by the group.

```sh
cd /var/www/lobbyhub
sudo cp deploy/systemd/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now lobbyhub-worker lobbyhub-scheduler
```

## 9. nginx, Cloudflare, firewall

All of it is in [nginx/README.md](nginx/README.md): copy four files, run the
Cloudflare IP script, point three DNS records here, turn on *Always Use HTTPS*,
and close port 80 to everything except Cloudflare's ranges.

## 10. Make sure the API answers — before building the frontend

**This order matters.** `/games/[game]` is prerendered, which means the frontend
build fetches the catalog from the API and cannot finish without it. Build too
early and it fails on `TypeError: fetch failed` / `ECONNREFUSED` while
collecting page data.

```sh
curl -s https://api.lobbyhub.gg/api/games | head -c 200
```

A list of games means everything below it — DNS, Cloudflare, nginx, php-fpm,
Postgres — is working. Anything else, fix that first.

## 11. Frontend

`NEXT_PUBLIC_*` are read at build time and baked into the bundle, so they have
to be right *before* `npm run build` — not just before the service starts.

Do not copy `web/.env.example` into place: it holds the local development
defaults, and `http://localhost:8000/api` is precisely the value that makes a
build fail with `ECONNREFUSED`. Write the file:

```sh
cd /var/www/lobbyhub/web
sudo -u deploy -H tee .env.local >/dev/null <<'EOF'
NEXT_PUBLIC_API_URL=https://api.lobbyhub.gg/api
NEXT_PUBLIC_SITE_URL=https://lobbyhub.gg
REVALIDATE_SECRET=<the same value as FRONTEND_REVALIDATE_SECRET>
EOF

sudo -u deploy -H npm ci
sudo -u deploy -H npm run build
```

Two lines of the build output are worth reading:

- `- Environments: .env.local` — the file was found. If it says `.env` instead,
  Next is reading something else and the values above are not in effect.
  Anything already in `process.env` wins over both.
- `✓ Generating static pages` — the API was reachable.

The public URL really is in the bundle:

```sh
grep -rl api.lobbyhub.gg .next/static/chunks | head -1
```

Then start it:

```sh
sudo systemctl enable --now lobbyhub-web
```

Server-side rendering and this build reach the API through Cloudflare and back,
because `NEXT_PUBLIC_API_URL` has to be the address browsers use. With TLS ending
at the edge there is no shortcut over loopback — https to 127.0.0.1 would find
nothing listening. It costs a hop per uncached render.

## 12. Check it works

```sh
curl -si https://api.lobbyhub.gg/up | head -1                  # 200
curl -s  https://api.lobbyhub.gg/api/auth/providers            # which sign-ins are live
curl -si https://lobbyhub.gg | head -1                         # 200 from Next
curl -si http://<server-ip> | head -1                          # nothing at all: 444
```

Then, in a browser: ask for a sign-in code and see whether the mail arrives, and
watch `storage/logs/laravel.log` if it does not. Within a minute or two of the
scheduler starting, `servers:query` should be filling in player counts — if
every server stays offline, the worker is not running.

```sh
journalctl -u lobbyhub-worker -n 50
journalctl -u lobbyhub-web -n 50
```

Discovery is deliberately not on the timetable — one sweep can add thousands of
servers, which is a decision about volume rather than background routine:

```sh
sudo -u deploy -H php artisan discovery:steam --help
```

## Deploying again

```sh
cd /var/www/lobbyhub
sudo -u deploy -H git pull
sudo -u deploy -H composer install --no-dev --optimize-autoloader
sudo -u deploy -H php artisan migrate --force
sudo -u deploy -H npm --prefix web ci
sudo -u deploy -H npm --prefix web run build
sudo -u deploy -H php artisan config:cache
sudo -u deploy -H php artisan route:cache
sudo -u deploy -H php artisan event:cache
sudo systemctl restart lobbyhub-web lobbyhub-worker lobbyhub-scheduler
sudo systemctl reload php8.4-fpm
```
