# nginx for lobbyhub.gg

Two sites on one machine, both behind Cloudflare:

| Host | Serves | Backed by |
|---|---|---|
| `lobbyhub.gg` | the Next.js frontend | `127.0.0.1:3000` |
| `api.lobbyhub.gg` | the Laravel API and game artwork | `php8.4-fpm` over `/var/www/lobbyhub/public` |

| File | Goes to | What it is |
|---|---|---|
| `conf.d/cloudflare.conf` | `/etc/nginx/conf.d/` | Cloudflare's ranges: real visitor IPs, and the `$from_cloudflare` flag. **Generated** — see below |
| `conf.d/lobbyhub.conf` | `/etc/nginx/conf.d/` | the scheme maps and the Next upstream, shared by both sites |
| `sites-available/lobbyhub.gg.conf` | `/etc/nginx/sites-available/` | the frontend, `www` redirect, and a catch-all that answers nothing |
| `sites-available/api.lobbyhub.gg.conf` | `/etc/nginx/sites-available/` | the API |
| `refresh-cloudflare-ips.sh` | `/usr/local/sbin/` | rewrites `cloudflare.conf` from Cloudflare's published list |
| `../systemd/lobbyhub-web.service` | `/etc/systemd/system/` | keeps `next start` running on 3000 |

## Install

```sh
sudo cp deploy/nginx/conf.d/*.conf              /etc/nginx/conf.d/
sudo cp deploy/nginx/sites-available/*.conf     /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/lobbyhub.gg.conf     /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/api.lobbyhub.gg.conf /etc/nginx/sites-enabled/

# Debian's default site also claims default_server on port 80.
sudo rm -f /etc/nginx/sites-enabled/default

sudo install -m 0755 deploy/nginx/refresh-cloudflare-ips.sh /usr/local/sbin/
sudo /usr/local/sbin/refresh-cloudflare-ips.sh          # writes conf.d/cloudflare.conf, reloads

# Where the API's fastcgi cache lives. nginx creates the level directories
# itself but not the root, and refuses to start without it.
sudo install -d -o www-data -g www-data -m 0700 /var/cache/nginx/api

sudo nginx -t && sudo systemctl reload nginx
```

### The API's cache

`conf.d/lobbyhub.conf` declares a `fastcgi_cache_path`, and
`api.lobbyhub.gg.conf` puts the PHP location behind it. What is stored and for
how long is decided by the application — the public read routes carry
`Cache-Control: s-maxage=…` from the `CachePublicReads` middleware and
everything else still says `no-cache, private`, which nginx honours by storing
nothing. So the list of shareable routes lives in `routes/api.php` and is not
repeated here.

Every response says which it was:

```sh
curl -sI https://api.lobbyhub.gg/api/games | grep -i x-cache   # MISS, then HIT
```

`BYPASS` means one of the exclusions matched — a token on the request, a search
term, or one of the paths in the `$api_uncacheable` map. Emptying it by hand is
`sudo rm -rf /var/cache/nginx/api/*` followed by a reload; nothing needs it in
normal operation, because the windows are a minute.

One entry does get dropped on purpose. Pressing Refresh on a server page
queries the machine there and then, which makes the stored copy of that
server's read wrong the moment it is written — and a minute is a long time to
show somebody the numbers they just replaced. So the application deletes that
one entry itself, the way the Go sweeper deletes Laravel's `api:games` key
after a sweep. It needs two things from this side:

- `NGINX_CACHE_PATH` in the API's `.env`, pointing at the `fastcgi_cache_path`
  root (`/var/cache/nginx/api`), plus `NGINX_CACHE_LEVELS` if `levels=` is ever
  something other than `1:2`. Unset, the drop is a no-op and the entry simply
  ages out.
- The cache key without `$host` in it, which is how `api.lobbyhub.gg.conf`
  now declares it. It also stops the same answer being filed twice — once
  under the public name and once under `127.0.0.1` for the renders that come
  in on the loopback listener.

PHP-FPM and nginx both run as `www-data`, which is what makes the delete
possible against a `0700` directory. A permission error is logged and the
refresh still answers.

Both files in `conf.d/` have to be there, and `nginx -t` names whichever is
missing rather than saying so:

- `host not found in upstream "lobbyhub_web"` — `conf.d/lobbyhub.conf` did not
  arrive. The name is an upstream, but with the block absent nginx reads it as a
  hostname and goes looking for it in DNS.
- `unknown "from_cloudflare" variable` — `conf.d/cloudflare.conf` did not
  arrive; run the refresh script.

Both belong at the `http` level, so neither can be moved into `sites-enabled`:
`upstream` and `map` are not allowed inside a `server` block. If
`/etc/nginx/nginx.conf` has no `include /etc/nginx/conf.d/*.conf;` — some builds
ship only the `sites-enabled` include — add it inside `http {}`, above the
`sites-enabled` line.

Cloudflare adds ranges from time to time, and a stale list breaks two things
quietly: per-IP limits start counting everyone as one client, and real visitors
start getting refused. A monthly run keeps it current:

```sh
echo '17 4 1 * * root /usr/local/sbin/refresh-cloudflare-ips.sh' | sudo tee /etc/cron.d/cloudflare-ips
```

## Cloudflare dashboard

- **DNS** — `lobbyhub.gg`, `www`, `api` as A records to this server, all proxied
  (orange cloud). An unproxied record hands out the origin address and skips
  every check below.
- **SSL/TLS → Overview** — Flexible, as chosen.
- **SSL/TLS → Edge Certificates** — *Always Use HTTPS* on. That redirect has to
  live here: in Flexible mode the origin is reached over HTTP, so a redirect to
  HTTPS on this machine would bounce the visitor back to Cloudflare and round
  again forever. Nothing in these configs redirects to HTTPS for that reason.

## Environment

`/var/www/lobbyhub/.env` (Laravel):

```
APP_URL=https://api.lobbyhub.gg
FRONTEND_ORIGINS=https://lobbyhub.gg
FRONTEND_REVALIDATE_URL=http://127.0.0.1:3000/api/revalidate
FRONTEND_REVALIDATE_SECRET=<same value as REVALIDATE_SECRET below>
```

`/var/www/lobbyhub/web/.env.local` (Next):

```
NEXT_PUBLIC_API_URL=https://api.lobbyhub.gg/api
NEXT_PUBLIC_SITE_URL=https://lobbyhub.gg
REVALIDATE_SECRET=<same value as FRONTEND_REVALIDATE_SECRET above>
```

`NEXT_PUBLIC_*` are baked in at `npm run build`, so they have to be right
*before* the build, not just before the service starts.

Register `https://api.lobbyhub.gg/api/auth/{provider}/callback` in the Discord
and Google consoles. Laravel builds that URL from the request, and the
`HTTPS` parameter in the API config is what keeps it from coming out as `http`
on this HTTP-only origin.

## Firewall

The origin speaks plain HTTP, so anyone who learns its address can read and
write everything the site does unless the packets are refused before nginx sees
them. `$from_cloudflare` in the configs is the second line; the first is the
firewall:

```sh
sudo ufw default deny incoming
sudo ufw allow 22/tcp
for net in $(curl -s https://www.cloudflare.com/ips-v4) $(curl -s https://www.cloudflare.com/ips-v6); do
  sudo ufw allow from "$net" to any port 80 proto tcp
done
sudo ufw enable
```

## Later: encrypting the last hop

Flexible leaves the Cloudflare→origin leg in the clear, which is the leg that
carries sign-in codes and session tokens. Moving to **Full (strict)** is one
certificate and three lines per site:

1. Cloudflare → SSL/TLS → Origin Server → *Create Certificate*. Put the pair in
   `/etc/ssl/cloudflare/lobbyhub.gg.pem` and `.key` (`chmod 600` the key).
2. In both site files, replace the `listen 80` pair with:

   ```nginx
   listen      443 ssl;
   listen      [::]:443 ssl;
   http2       on;

   ssl_certificate     /etc/ssl/cloudflare/lobbyhub.gg.pem;
   ssl_certificate_key /etc/ssl/cloudflare/lobbyhub.gg.key;
   ```

3. Switch the mode to Full (strict), open 443 in the firewall to Cloudflare's
   ranges, and drop 80.

The scheme maps keep working unchanged: `X-Forwarded-Proto` still says `https`,
and `$scheme` would too.
