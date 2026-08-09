# Putting LobbyHub on a server

Ubuntu 24.04 or Debian 12, one machine, behind Cloudflare. Nothing here needs
Redis or Docker: queues, cache and sessions all live in Postgres.

Five processes end up running:

| Process | What it does |
|---|---|
| `nginx` | the only thing listening publicly; see [nginx/README.md](nginx/README.md) |
| `php8.4-fpm` | the API on `api.lobbyhub.gg` |
| `lobbyhub-web` | `next start` on `127.0.0.1:3000`, the site itself |
| `lobbyhub-worker@N` | runs the monitoring queries the scheduler dispatches; one or more |
| `lobbyhub-scheduler` | the timetable in `routes/console.php` |

## 1. Packages

```sh
sudo apt update
sudo apt install -y nginx postgresql redis-server git unzip curl \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath \
  php8.4-redis

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

### Redis

Redis holds the cache: API payloads, the A2S challenge cache, and every lock the
monitor relies on — see section 8. Two settings in `/etc/redis/redis.conf`, and
both of them matter more than they look:

```ini
maxmemory 512mb
maxmemory-policy allkeys-lru

# It is a cache. Nothing in it needs to survive a restart, and the fork a
# snapshot takes is a latency spike paid for nothing.
save ""
appendonly no
```

The default is `maxmemory 0` with `maxmemory-policy noeviction`: no ceiling, and
on reaching one, writes fail rather than anything being dropped. That is the
right default for a Redis holding data somebody needs and the wrong one for a
cache, where the failure mode is the box running out of memory and then every
`Cache::put` in the application throwing.

`allkeys-lru` is only safe because this instance holds nothing but cache. If you
ever move the queue onto it, that policy is free to evict queued jobs — and the
queue is deliberately not on Redis, for reasons in section 19.5 of
`Мониторинг.md`. Keep them apart.

```sh
sudo systemctl enable --now redis-server
redis-cli ping          # PONG
redis-cli info keyspace # which indices are in use — see section 4
```

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
# The home directory is not a formality: npm and composer keep their caches
# there, and a deploy user without one writes them wherever HOME happens to
# point — usually somewhere another account already owns.
sudo adduser --system --group --home /home/deploy --shell /bin/bash deploy
sudo mkdir -p /home/deploy && sudo chown deploy:deploy /home/deploy
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
sudo -u deploy -H mkdir -p storage bootstrap/cache public/images
sudo chmod -R g+rwX storage bootstrap/cache public/images
sudo find storage bootstrap/cache public/images -type d -exec chmod g+s {} +
sudo setfacl -R -m g:www-data:rwX -m d:g:www-data:rwX storage bootstrap/cache public/images
```

`web/.next` needs the same, but the rule cannot live on `.next` itself: a build
deletes and recreates it, and takes the permissions with it. So it goes on `web`,
which survives, and `.next` inherits it on the way in. Only the default — the
existing `node_modules` stays as it is, so the services still cannot write to the
code they run.

```sh
sudo chmod g+s web
sudo setfacl -m d:g:www-data:rwX web
```

Worth checking after any restore, move to a new box, or manual `chmod` in the
checkout — and worth checking *reads*, not only writes, because the way this
fails does not look like a permission problem at all.

When the service cannot find or read the pages the build prerendered, every
partially prerendered route — game pages, server pages, search — hands the
browser a shell with nothing to resume it. A direct visit to the URL still
works and still answers 200, so health checks pass; only a *click* fails, with
"This page couldn't load". `deploy.sh` now checks the `x-nextjs-prerender`
header rather than the status code, which is the difference between the two.

```sh
sudo -u www-data test -w /var/www/lobbyhub/web/.next/cache && echo cache writable
sudo -u www-data head -c1 /var/www/lobbyhub/web/.next/server/app/games/rust.html >/dev/null \
    && echo prerender readable
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

CACHE_STORE=redis
REDIS_DB=3                               # not 0 and 1 — read the note below
REDIS_CACHE_DB=4

FRONTEND_ORIGINS=https://lobbyhub.gg
FRONTEND_REVALIDATE_URL=http://127.0.0.1:3000/api/revalidate
FRONTEND_REVALIDATE_SECRET=<openssl rand -hex 16>

ASSET_URL=https://api.lobbyhub.gg          # see section 11: artwork URLs

STEAM_API_KEY=...                        # the server sweep, and Sign in with Steam
DISCORD_CLIENT_ID=... DISCORD_CLIENT_SECRET=...
GOOGLE_CLIENT_ID=... GOOGLE_CLIENT_SECRET=...
```

**Pick the Redis indices deliberately.** `cache:clear` is `FLUSHDB` — it empties
the whole index, not the keys carrying our prefix, and `CACHE_PREFIX` protects
nothing from it. Laravel defaults to index 0 for the general connection and 1
for the cache; on a box where Redis serves anything else, that is somebody
else's data. Run `redis-cli info keyspace`, take two indices it does not list,
and write them down here. If Redis is dedicated to this app, the defaults are
fine and this is one less thing to remember.

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
sudo systemctl enable --now lobbyhub-worker@1 lobbyhub-scheduler
```

The worker is a template — `lobbyhub-worker@1`, `@2`, and so on. One is enough
to start with; the section below is about when it stops being enough.

### How many workers

Not a number to guess. Add one, watch, repeat:

```sh
sudo -u deploy -H php artisan monitoring:status
```

or the same two figures in the admin at `/admin`: **of the intended cadence**
and **due now**. A healthy monitor sits near 100% with `due now` flat.

- **`due now` climbing while the queue is empty** — the dispatcher is not
  putting enough work in, and more workers change nothing. Raise
  `MONITORING_BATCH_SIZE`.
- **`due now` climbing with a full queue** — the workers are behind. Add one:

  ```sh
  sudo systemctl enable --now lobbyhub-worker@2
  ```

Querying a game server is almost all waiting on a socket, so this scales nearly
linearly and the processor is not what runs out. Two things are:

- **Memory.** Each worker is a PHP process holding the framework, roughly
  60–100 MB, next to Postgres, php-fpm and Node. Check `free -m` before adding
  one; an out-of-memory kill picks its own victim, and it may not be the worker.
- **Politeness.** These are packets aimed at machines that are not ours, and
  large hosts keep hundreds of servers behind one address. `monitoring.max_per_host`
  spreads a batch at dispatch, but three workers still knock three times as
  densely. Grow one at a time rather than jumping to eight.

Coming from a single `lobbyhub-worker` installed before the template existed:

```sh
sudo systemctl disable --now lobbyhub-worker
sudo rm /etc/systemd/system/lobbyhub-worker.service
sudo systemctl daemon-reload
sudo systemctl enable --now lobbyhub-worker@1
```

### Emptying the monitoring queue

Occasionally the queue is worth throwing away rather than draining — after a
backlog built up under an older configuration, say, where everything in it is
about a state of the world that has moved on. Four steps, and the third is the
one that is easy to miss.

```sh
# 1. Stop both sides, so nothing refills the queue mid-flush.
sudo systemctl stop lobbyhub-scheduler
sudo systemctl stop 'lobbyhub-worker@*'

# 2. Throw the queue away. --force because the command asks for confirmation
#    in production and there is no terminal to answer it here.
sudo -u deploy -H php artisan queue:clear database --queue=monitoring --force

# 3. Free the servers those queries had claimed.
sudo -u deploy -H php artisan monitoring:unlock

# 4. Start again.
sudo systemctl start lobbyhub-scheduler
sudo systemctl start 'lobbyhub-worker@1'
sudo -u deploy -H php artisan monitoring:status
```

Step 3 is not optional. A queued query holds a lock on its server so that a
second one cannot be queued for it, and that lock lives in the cache rather
than in the queue — so `queue:clear` deletes the jobs and leaves the locks. Skip
this and every server that had a query waiting is refused a new one until its
lock expires by itself, which is `MONITORING_UNIQUE_FOR`: an hour of a monitor
that looks healthy in every reading and is polling nothing. `monitoring:unlock`
is safe to run when there is nothing stuck — it says so and exits — and refuses
to run while queries are still queued, because releasing those locks is how you
get two copies of every query.

Afterwards `pending jobs` should settle near the number of genuinely overdue
servers and stop climbing on its own. Watch **stalest reading** rather than
*oldest overdue by*: the second is measured from when a server was queued, which
the dispatcher moves whether or not anything reached the server, so during a
backlog it reports work as done that has not happened. The first is measured
from the last actual answer, which is what the site is showing.

### Changing the cache store

Moving `CACHE_STORE` — database to Redis, one Redis index to another — is not an
`.env` edit. The locks that keep the monitor honest live in the cache store, so
pointing the app at an empty one makes every lock currently held invisible:

* a queued `QueryServer` holds a uniqueness lock, and without it the dispatcher
  will queue a second copy of every query already waiting — the exact failure
  the lock exists to prevent, arriving as a queue that doubles on its own;
* `withoutOverlapping()` mutexes go the same way, so a `steam:sync` that is
  mid-pass can be started again beside itself.

So it is the queue-flush procedure with the store swapped in the middle:

```sh
# 1. Stop both sides. Nothing may take a lock while the store is changing.
sudo systemctl stop lobbyhub-scheduler
sudo systemctl stop 'lobbyhub-worker@*'

# 2. Let the queue drain to nothing, or throw it away. monitoring:unlock in the
#    next step refuses to run while queries are still queued, and it is right to.
sudo -u deploy -H php artisan queue:clear database --queue=monitoring --force

# 3. Release the locks while the app can still see them — on the OLD store.
sudo -u deploy -H php artisan monitoring:unlock

# 4. Now edit .env, and rebuild the config cache: a cached config pins whatever
#    .env said when it was written, so skipping this changes nothing at all.
sudo -u deploy -H php artisan config:cache

# 5. Start again, and watch the first pass.
sudo systemctl start lobbyhub-scheduler
sudo systemctl start 'lobbyhub-worker@1'
sudo -u deploy -H php artisan monitoring:status
```

Step 3 before step 4, not after. Afterwards the locks are in a store the app is
no longer reading, and `monitoring:unlock` would walk the catalog releasing
nothing while the stale rows sit in the old one until they expire.

What is lost in the swap is cache, and all of it is meant to be lost: the API
payload caches rebuild on the first request, the A2S challenge cache re-handshakes
once per server, and any sign-in that was mid-redirect through Google or Discord
fails and has to be started again. That last one is the only visible casualty, so
prefer a quiet hour.

Old rows in the `cache` table are not read again by anything. They can be left to
rot or dropped with `truncate cache, cache_locks;` once the new store is serving.

## 9. nginx, Cloudflare, firewall

All of it is in [nginx/README.md](nginx/README.md): copy four files, run the
Cloudflare IP script, point three DNS records here, turn on *Always Use HTTPS*,
and close port 80 to everything except Cloudflare's ranges.

## 10. Make sure the API answers

Over loopback first, because that is what the frontend build will use and it
depends on nothing outside this machine:

```sh
curl -si http://127.0.0.1:8080/up | head -1
curl -s  http://127.0.0.1:8080/api/games | head -c 200
```

A list of games means php-fpm, Postgres and nginx are all working. Then the
public address, which additionally exercises DNS and Cloudflare:

```sh
curl -s https://api.lobbyhub.gg/api/games | head -c 200
```

The second one failing is worth fixing before you go further, but it no longer
blocks the build.

## 11. Frontend

`NEXT_PUBLIC_*` are read at build time and baked into the bundle, so they have
to be right *before* `npm run build` — not just before the service starts.

`API_URL_INTERNAL` is the one the build actually uses. The public address
resolves to Cloudflare, so building through it means a round trip out to the
edge and back — which fails while DNS is still propagating, while the proxy
cannot reach the origin, or whenever this machine cannot reach the edge at all.
The loopback listener in the nginx config has none of those dependencies. It is
read at runtime too, so server-side renders take the same short path; browsers
keep using the public address, and `ASSET_URL` in the API's `.env` is what stops
artwork URLs from coming back as `127.0.0.1`.

Do not copy `web/.env.example` into place: it holds the local development
defaults, and `http://localhost:8000/api` is precisely the value that makes a
build fail with `ECONNREFUSED`. Write the file:

```sh
cd /var/www/lobbyhub/web
sudo -u deploy -H tee .env.local >/dev/null <<'EOF'
NEXT_PUBLIC_API_URL=https://api.lobbyhub.gg/api
NEXT_PUBLIC_SITE_URL=https://lobbyhub.gg
REVALIDATE_SECRET=<the same value as FRONTEND_REVALIDATE_SECRET>
API_URL_INTERNAL=http://127.0.0.1:8080/api
NEXT_PUBLIC_CONTROLLER_NAME=<full legal name of whoever runs the site>
NEXT_PUBLIC_CONTROLLER_ADDRESS=<a postal address somebody can write to>
NEXT_PUBLIC_CONTACT_EMAIL=privacy@lobbyhub.gg
NEXT_PUBLIC_HOSTING_COUNTRY=<country this machine sits in>
NEXT_PUBLIC_CONSENT_CATEGORIES=
EOF

sudo -u deploy -H npm ci
sudo -u deploy -H npm run build
```

The last five are what `/terms` and `/privacy` print. They are baked into the
bundle like everything else here, so a build with any of the first four left
empty ships both pages carrying a visible "this deployment is not configured"
warning — which is the intended failure, and not one to discover from a user.
`NEXT_PUBLIC_CONSENT_CATEGORIES` stays empty until an analytics or ad script is
actually on the page; see `web/.env.example` for why.

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
journalctl -u lobbyhub-worker@1 -n 50
journalctl -u lobbyhub-web -n 50
```

Filling the catalog is no longer a decision anyone makes by hand. `steam:sync`
runs on the timetable and imports every Source server Steam lists, which is
hundreds of thousands rather than the thousands the old manual import added. To
see what one game holds before it lands, run a single game inline:

```sh
sudo -u deploy -H php artisan steam:sync --game=counter-strike-2 --sync
```

## Deploying again

```sh
sudo /var/www/lobbyhub/deploy/deploy.sh
```

That is the whole of it. The script pulls, decides from the diff what needs
doing, and ends by checking that the site answers:

- `composer install` only when the lock file moved
- `npm ci` only when the frontend's lock file moved, and a rebuild only when
  anything under `web/` did — a backend commit has no business spending two
  minutes rebuilding a frontend that did not change
- `artisan migrate --force`, then `artisan optimize`, which rewrites the config,
  route, view and event caches rather than clearing them, so nothing serves an
  empty config in the gap
- reloads php-fpm, restarts the scheduler and every worker instance, and
  restarts the frontend only when it was rebuilt
- finally curls the API, the site and the units, and exits non-zero with where
  to look if any of them is not answering

Everything that touches the checkout runs as the deploy user even though the
script itself is run with sudo — see section 3 for why that matters.

Two flags for when the default is not what you want:

```sh
sudo /var/www/lobbyhub/deploy/deploy.sh --skip-build   # backend only
sudo /var/www/lobbyhub/deploy/deploy.sh --skip-pull    # deploy what is checked out
```

With nothing new to pull the script cannot tell what changed, so it does
everything — including the rebuild. That is the safe way round: the cost is two
minutes, and the alternative is a deploy that quietly skips the step you needed.

One thing it does not do is copy the unit files. They are installed once in
section 8 and then live in `/etc/systemd/system`, so a change to anything under
`deploy/systemd/` reaches the machine with the pull and does nothing until it is
put where systemd reads it:

```sh
sudo cp /var/www/lobbyhub/deploy/systemd/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl restart lobbyhub-web lobbyhub-scheduler 'lobbyhub-worker@*'
```
