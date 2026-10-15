# UAT Server Installation — CGPSC ODPC Portal

Prepares a Linux server to run the ODPC Portal (`cgpsc/odpc-portal`) for UAT. Covers
Ubuntu (APT) and RHEL / Rocky / AlmaLinux (DNF), the web server, PHP-FPM, MySQL and
Redis.

Next documents, in order:
1. this one
2. `UAT-ENV-CONFIGURATION.md`
3. `UAT-APPLICATION-DEPLOYMENT.md`
4. `UAT-MIGRATION-AND-ROLLBACK.md`
5. `UAT-MANUAL-TESTING-CHECKLIST.md`
6. `UAT-TROUBLESHOOTING.md` (when something fails)

> **Placeholders.** Anything in `<ANGLE_BRACKETS>` is a UAT-specific value that is not
> known from the repository. Replace it before running a command. Nothing here
> invents a server address, domain, account or password.

| Placeholder | Meaning |
|---|---|
| `<UAT_DOMAIN>` | The host name testers will open, e.g. the UAT DNS name |
| `<APP_DIR>` | Installation directory of the application (the directory that contains `artisan`) |
| `<DEPLOY_USER>` | Unprivileged Linux user that owns the code and runs Composer |
| `<WEB_GROUP>` | Group the PHP-FPM workers run as: `www-data` on Ubuntu, `nginx` (or `apache`) on RHEL |
| `<DB_NAME>`, `<DB_USER>`, `<DB_PASSWORD>`, `<DB_HOST>` | UAT database |
| `<REDIS_PASSWORD>` | Redis password, if you set one |

---

## 1. What the application actually needs

Taken from `composer.json`, `composer.lock`, `config/*.php`, `routes/console.php`
and the code. The application was verified on PHP 8.4.26, MySQL 8.0.46 and Redis
6.0.16. Run the full MySQL schema build (86 migrations, 83 tables) with
`php artisan schema:verify-mysql`.

| Component | Status | Version | Why |
|---|---|---|---|
| PHP (CLI + FPM) | **REQUIRED** | **8.4.x exactly** | `composer.json` requires `^8.4`; `phpoffice/phpspreadsheet` (via `maatwebsite/excel`) allows `<8.5.0`. **PHP 8.5 will not install.** |
| Laravel | (bundled) | 13.23.0 | Locked in `composer.lock` |
| Composer | **REQUIRED** | 2.x (2.7+ verified) | Installs `vendor/` from `composer.lock` |
| MySQL | **REQUIRED** | 8.0 (8.0.46 verified) | `DB_CONNECTION=mysql`; JSON columns, UUID columns, foreign keys. MariaDB is **not verified**. |
| Redis | **REQUIRED (as configured)** | 6.x or 7.x | `.env.example` puts sessions, cache and the queue on Redis, and `/health` pings Redis. The PHP client is `predis` (pure PHP), so **no `php-redis` extension is needed**. |
| Web server | **REQUIRED** — one of them | NGINX 1.18+ or Apache 2.4 | Serves `public/` only |
| Queue worker | **REQUIRED** | `php artisan queue:work` under systemd | 9 queued listeners, including **login OTP delivery** and in-app notifications. Without a worker, **nobody can log in**: the OTP is never written. |
| Scheduler (cron) | **REQUIRED** | `php artisan schedule:run` every minute | Daily `dpc:return-expired-allotments` at 00:15 (application timezone UTC) |
| Git | **REQUIRED** | any 2.x | Fetches the approved release |
| Node.js / npm | **NOT USED** | — | No view uses `@vite`. CSS and JS ship pre-built in `public/css`, `public/js` and `public/vendor`. `package.json` lists Vite and Tailwind but has **no lockfile** and no build output is referenced. Do not install Node for UAT. |
| Supervisor | **NOT USED** | — | The queue worker runs as a systemd unit, so no extra service is needed |
| Memcached, Beanstalkd, SQS, DynamoDB, Pusher/Reverb, WebSockets | **NOT USED** | — | Present only as Laravel config defaults |
| S3 / object storage | **OPTIONAL** (off) | — | `FEATURE_S3_STORAGE=false`; documents go to local private storage |
| SMTP mail server | **OPTIONAL** | — | `MAIL_MAILER=log` writes mail to the Laravel log; set SMTP only if UAT must send real mail |
| SMS gateway, CSC DigiSign, HRMS, LGD | **NOT AVAILABLE** | — | No real provider is implemented; flags stay `false` (see `UAT-ENV-CONFIGURATION.md`) |

### PHP extensions

**REQUIRED** — Laravel, `maatwebsite/excel` / PhpSpreadsheet, `owen-it/laravel-auditing`,
`spatie/laravel-permission`, and file uploads:

`bcmath ctype curl dom fileinfo filter gd hash iconv intl json libxml mbstring openssl pcre pdo pdo_mysql session simplexml tokenizer xml xmlreader xmlwriter zip zlib`

**RECOMMENDED** — `opcache` (performance), `readline` (`php artisan tinker`).

**NOT NEEDED** — `redis` / `phpredis` (the app uses predis), `imagick`, `memcached`,
`pdo_pgsql`, `sqlsrv`, `pdo_sqlite` (tests only).

Some of these come compiled into PHP on a given distribution and have no package of
their own (`ctype`, `filter`, `hash`, `json`, `libxml`, `openssl`, `pcre`,
`session`, `tokenizer`, `zlib` usually). Section 4 verifies every one by name.

### Sizing notes

- **Uploads.** Supporting PDFs up to **10 MB** (`DPC_DOCUMENTS_MAX_KB=10240`), and Excel / CSV imports up to **5 MB** (`DPC_IMPORT_MAX_FILE_KB=5120`). PHP and the web server must accept at least **12 MB per file** and **16 MB per request**.
- **PHP-FPM workers.** Ubuntu's stock `pm.max_children = 5` will queue requests under even light UAT load. Raise it (section 5.3).

---

## 2. Ubuntu Server (22.04 LTS or 24.04 LTS)

Run as a user with `sudo`.

### 2.1 System update and utilities

```bash
sudo apt update
sudo apt -y upgrade
sudo apt -y install software-properties-common ca-certificates curl gnupg unzip git acl \
                    lsb-release cron logrotate ufw chrony
sudo timedatectl set-timezone Asia/Kolkata      # server clock for logs/cron; the app itself stores UTC
sudo systemctl enable --now chrony cron
```

### 2.2 PHP 8.4 (Ondřej Surý PPA)

Neither 22.04 nor 24.04 ships PHP 8.4 in its own archive. The `ondrej/php` PPA is the
standard source for it on Ubuntu.

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt -y install php8.4-cli php8.4-fpm php8.4-common \
    php8.4-mysql php8.4-mbstring php8.4-xml php8.4-zip php8.4-gd php8.4-curl \
    php8.4-bcmath php8.4-intl php8.4-opcache php8.4-readline
sudo systemctl enable --now php8.4-fpm
php -v            # must print PHP 8.4.x
```

### 2.3 Composer

```bash
cd /tmp
EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
curl -fsSL https://getcomposer.org/installer -o composer-setup.php
ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
[ "$EXPECTED" = "$ACTUAL" ] && echo "installer OK" || { echo "installer checksum MISMATCH"; rm composer-setup.php; exit 1; }
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

### 2.4 MySQL 8.0 — only if the database runs on this server

```bash
sudo apt -y install mysql-server
sudo systemctl enable --now mysql
sudo mysql_secure_installation
mysql --version     # 8.0.x
```

### 2.5 Redis

```bash
sudo apt -y install redis-server
sudo sed -i 's/^supervised .*/supervised systemd/' /etc/redis/redis.conf
# Keep Redis on loopback only (the default `bind 127.0.0.1 -::1`). If you set a password:
#   sudo sed -i 's/^# *requirepass .*/requirepass <REDIS_PASSWORD>/' /etc/redis/redis.conf
sudo systemctl enable --now redis-server
redis-cli ping      # PONG   (with a password: redis-cli -a '<REDIS_PASSWORD>' ping)
```

### 2.6 Web server — choose ONE

NGINX (recommended):

```bash
sudo apt -y install nginx
sudo systemctl enable --now nginx
```

Apache 2.4 with PHP-FPM (the development machine uses Apache):

```bash
sudo apt -y install apache2
sudo a2enmod proxy_fcgi setenvif rewrite headers ssl expires
sudo a2enconf php8.4-fpm
sudo a2dismod php8.4 2>/dev/null || true      # never mod_php alongside FPM
sudo systemctl enable --now apache2
```

### 2.7 Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'          # or: sudo ufw allow 'Apache Full'
# MySQL (3306) and Redis (6379) stay closed to the outside.
sudo ufw enable
sudo ufw status verbose
```

---

## 3. RHEL 9 / Rocky Linux 9 / AlmaLinux 9

Run as a user with `sudo`. RHEL 9 AppStream does not ship PHP 8.4. The Remi
repository's `php:remi-8.4` module stream provides it.

### 3.1 System update and utilities

```bash
sudo dnf -y update
sudo dnf -y install curl unzip git acl tar cronie logrotate chrony policycoreutils-python-utils
sudo timedatectl set-timezone Asia/Kolkata
sudo systemctl enable --now chronyd crond
```

### 3.2 Repositories and PHP 8.4

```bash
sudo dnf -y install https://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm
sudo dnf -y install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
# RHEL only (not needed on Rocky/Alma):
#   sudo subscription-manager repos --enable codeready-builder-for-rhel-9-$(arch)-rpms
sudo dnf -y module reset php
sudo dnf -y module enable php:remi-8.4
sudo dnf -y install php-cli php-fpm php-common php-mysqlnd php-mbstring php-xml php-pdo \
    php-zip php-gd php-bcmath php-intl php-opcache php-process
sudo systemctl enable --now php-fpm
php -v            # must print PHP 8.4.x
```

On RHEL the FPM pool (`/etc/php-fpm.d/www.conf`) runs as `apache` by default. If you
use NGINX, set `user = nginx` and `group = nginx` there, and set
`listen.owner`/`listen.group` to `nginx`.

### 3.3 Composer

Same commands as section 2.3.

### 3.4 MySQL 8.0 — only if local

```bash
sudo dnf -y install mysql-server
sudo systemctl enable --now mysqld
sudo mysql_secure_installation
```

### 3.5 Redis

```bash
sudo dnf -y install redis            # RHEL 9 AppStream: redis 6.x
sudo systemctl enable --now redis
redis-cli ping
```

On EL 10 the package is `valkey`, which is Redis-protocol compatible. Use
`sudo dnf -y install valkey` and `systemctl enable --now valkey`.

### 3.6 Web server — choose ONE

```bash
sudo dnf -y install nginx && sudo systemctl enable --now nginx
#   or
sudo dnf -y install httpd mod_ssl && sudo systemctl enable --now httpd
```

### 3.7 Firewall and SELinux

```bash
sudo firewall-cmd --permanent --add-service=http --add-service=https
sudo firewall-cmd --reload
# SELinux: let the web server reach PHP-FPM, Redis and MySQL, and write storage/.
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_can_network_connect_db 1
# After the code is deployed (see deployment doc), label the writable paths:
#   sudo semanage fcontext -a -t httpd_sys_rw_content_t "<APP_DIR>/storage(/.*)?"
#   sudo semanage fcontext -a -t httpd_sys_rw_content_t "<APP_DIR>/bootstrap/cache(/.*)?"
#   sudo restorecon -Rv <APP_DIR>
```

---

## 4. Verify PHP

```bash
php -v
for e in bcmath ctype curl dom fileinfo filter gd hash iconv intl json libxml mbstring openssl \
         pcre pdo pdo_mysql session simplexml tokenizer xml xmlreader xmlwriter zip zlib; do
  php -m | grep -qix "$e" && echo "ok   $e" || echo "MISSING $e"
done
```

Every line must say `ok`. Check FPM separately: it can load a different `php.ini`.

```bash
# Ubuntu:
php-fpm8.4 -m | grep -Ei 'pdo_mysql|mbstring|gd|zip|intl|bcmath'
# RHEL:
php-fpm -m | grep -Ei 'pdo_mysql|mbstring|gd|zip|intl|bcmath'
```

---

## 5. PHP configuration

### 5.1 `php.ini` limits — FPM **and** CLI

The ini paths are:
- Ubuntu: `/etc/php/8.4/fpm/conf.d/99-odpc.ini` and `/etc/php/8.4/cli/conf.d/99-odpc.ini`
- RHEL: `/etc/php.d/99-odpc.ini`

Write this file at the path for your distribution:

```ini
; ODPC Portal — UAT
upload_max_filesize = 12M
post_max_size       = 16M
memory_limit        = 512M
max_execution_time  = 120
max_input_vars      = 5000      ; the Working Officers / Evaluation Chart grids post many fields
date.timezone       = UTC       ; Laravel's config/app.php timezone is UTC
expose_php          = Off
opcache.enable      = 1
opcache.memory_consumption = 192
opcache.validate_timestamps = 1  ; keep 1 on UAT so a redeploy is picked up after an FPM reload
```

On Ubuntu, create it once and copy it to the CLI directory:

```bash
sudo tee /etc/php/8.4/fpm/conf.d/99-odpc.ini >/dev/null <<'INI'
upload_max_filesize = 12M
post_max_size = 16M
memory_limit = 512M
max_execution_time = 120
max_input_vars = 5000
date.timezone = UTC
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 192
opcache.validate_timestamps = 1
INI
sudo cp /etc/php/8.4/fpm/conf.d/99-odpc.ini /etc/php/8.4/cli/conf.d/99-odpc.ini
```

### 5.2 Confirm

```bash
php -i | grep -E 'upload_max_filesize|post_max_size|max_input_vars'
```

### 5.3 PHP-FPM pool

Edit the pool file:
- Ubuntu: `/etc/php/8.4/fpm/pool.d/www.conf`
- RHEL: `/etc/php-fpm.d/www.conf`

Raise the worker count:

```ini
pm = dynamic
pm.max_children = 20        ; size to RAM: roughly (RAM_MB - 1024) / 80
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
request_terminate_timeout = 120
```

Then reload:

```bash
sudo systemctl reload php8.4-fpm     # Ubuntu
sudo systemctl reload php-fpm        # RHEL
```

The socket path is:
- Ubuntu: `/run/php/php8.4-fpm.sock`
- RHEL: `/run/php-fpm/www.sock`

---

## 6. Web server configuration

**The document root is `<APP_DIR>/public` and nothing above it.** The project root
holds `.env`, `storage/` (including every uploaded document) and `vendor/`. None of
it may be reachable over HTTP.

TLS:
- If UAT has a certificate, terminate TLS on this server.
- The application does **not** configure trusted proxies (`bootstrap/app.php`). Terminating TLS on a separate load balancer would make Laravel see plain HTTP: URLs, secure cookies and HSTS would be wrong. See the troubleshooting document if that is the UAT topology.

### 6.1 NGINX + PHP-FPM

Create `/etc/nginx/sites-available/odpc-uat.conf` (Ubuntu), or
`/etc/nginx/conf.d/odpc-uat.conf` (RHEL):

```nginx
# ODPC Portal — UAT
server {
    listen 80;
    listen [::]:80;
    server_name <UAT_DOMAIN>;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name <UAT_DOMAIN>;

    root <APP_DIR>/public;
    index index.php;
    charset utf-8;

    ssl_certificate     <PATH_TO_FULLCHAIN_PEM>;
    ssl_certificate_key <PATH_TO_PRIVATE_KEY_PEM>;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;

    # Uploads: documents up to 10 MB, imports up to 5 MB, plus form overhead.
    client_max_body_size 16m;

    access_log /var/log/nginx/odpc-uat.access.log;
    error_log  /var/log/nginx/odpc-uat.error.log warn;

    # The application sends CSP, X-Frame-Options, Referrer-Policy and HSTS itself
    # (App\Http\Middleware\SecurityHeaders). Do not duplicate them here.
    server_tokens off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Pre-built static assets shipped in public/.
    location ~* ^/(css|js|images|vendor)/.+\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf)$ {
        expires 7d;
        access_log off;
        try_files $uri =404;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;      # RHEL: unix:/run/php-fpm/www.sock
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_read_timeout 120s;
    }

    # Never serve dotfiles (.env, .git, .htaccess).
    location ~ /\.(?!well-known).* { deny all; }
}
```

Enable and test it:

```bash
# Ubuntu:
sudo ln -s /etc/nginx/sites-available/odpc-uat.conf /etc/nginx/sites-enabled/odpc-uat.conf
sudo rm -f /etc/nginx/sites-enabled/default
# Both:
sudo nginx -t && sudo systemctl reload nginx
```

**No TLS certificate yet?** Use only a `listen 80` server block with the same body
(drop the redirect and the `ssl_*` lines), and set
`APP_URL=http://<UAT_DOMAIN>` and `SESSION_SECURE_COOKIE=false`. Treat this as
temporary.

### 6.2 Apache 2.4 + PHP-FPM

Create `/etc/apache2/sites-available/odpc-uat.conf` (Ubuntu), or
`/etc/httpd/conf.d/odpc-uat.conf` (RHEL):

```apache
<VirtualHost *:80>
    ServerName <UAT_DOMAIN>
    Redirect permanent / https://<UAT_DOMAIN>/
</VirtualHost>

<VirtualHost *:443>
    ServerName <UAT_DOMAIN>
    DocumentRoot <APP_DIR>/public

    SSLEngine on
    SSLCertificateFile    <PATH_TO_FULLCHAIN_PEM>
    SSLCertificateKeyFile <PATH_TO_PRIVATE_KEY_PEM>
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Directory <APP_DIR>/public>
        Options -Indexes +FollowSymLinks
        # public/.htaccess carries Laravel's rewrite rules.
        AllowOverride All
        Require all granted
    </Directory>

    # Uploads: 16 MB request body.
    LimitRequestBody 16777216

    # RHEL socket: "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost"
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
    ProxyTimeout 120

    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css "access plus 7 days"
        ExpiresByType application/javascript "access plus 7 days"
        ExpiresByType image/png "access plus 7 days"
        ExpiresByType image/svg+xml "access plus 7 days"
    </IfModule>

    ServerSignature Off
    # RHEL has no APACHE_LOG_DIR: use logs/odpc-uat.error.log and logs/odpc-uat.access.log.
    ErrorLog  ${APACHE_LOG_DIR}/odpc-uat.error.log
    CustomLog ${APACHE_LOG_DIR}/odpc-uat.access.log combined
</VirtualHost>
```

Enable and test it:

```bash
# Ubuntu:
sudo a2ensite odpc-uat && sudo a2dissite 000-default
sudo apachectl configtest && sudo systemctl reload apache2
# RHEL:
sudo apachectl configtest && sudo systemctl reload httpd
```

Also set `ServerTokens Prod`:
- Ubuntu: in `/etc/apache2/conf-available/security.conf`
- RHEL: in `httpd.conf`

---

## 7. Server checklist before deploying the code

```bash
php -v                                   # 8.4.x
composer --version                       # 2.x
mysql --version                          # 8.0.x (or reach the remote DB host)
redis-cli ping                           # PONG
systemctl is-active php8.4-fpm || systemctl is-active php-fpm
systemctl is-active nginx || systemctl is-active apache2 || systemctl is-active httpd
systemctl is-active cron || systemctl is-active crond
```

Continue with `UAT-ENV-CONFIGURATION.md`, then `UAT-APPLICATION-DEPLOYMENT.md`.
