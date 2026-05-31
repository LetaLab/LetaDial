# LetaDial

<p align="center">
  <img src="https://github.com/user-attachments/assets/a914458f-d3bb-463b-874e-e8498b87ae23" alt="OG" width="50%">
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/e6230a1e-3fbd-48f7-965c-fdb42e52d370" alt="icon-512" width="220">
</p>

<p align="center">
  <em>Hi, I'm Leta - the mascot of projects under the LetaLab umbrella!</em>
</p>

---

<h2 align="center">
  **⚠️ ! SEEKING DEFENSIVE SECURITY ASSESSMENT ! ⚠️**<br>
  **⚠️ ! FOR OPEN SOURCE PROJECT ! ⚠️**
  <br><br>
</h2>

<h2 align="center">

  **⚠️ ! IMPORTANT DISCLAIMER - READ BEFORE USE ! ⚠️**
</h2>

> **THIS PROJECT IS A WORK IN PROGRESS / CONTINOUSLY PERFECTED AND IS 100% VIBE CODED USING FREE TIER ANTHROPIC CLAUDE SONNET 4.6. I CANNOT CODE IF IT WAS TO SAVE MY LIFE, BUT I LIKE TINKERING, AND SO I DID. THIS TOOK ABOUT 2 MONTHS TO BUILD.**
>
> This is an experimental public open source project that started as something built mainly for personal use and then shared publicly on GitHub.
>
> While there is no intentional malicious code in this repository, the codebase was created with limited programming experience, so security issues or design mistakes may still exist.
>
> Responsible security review and vulnerability reports are genuinely appreciated. If you find a problem, please report it privately and with enough detail to reproduce it.
>
> The project is provided under the MIT License and without warranty, but the goal is to improve it responsibly over time with community feedback.

---

**Personal speed dial dashboard - self-hosted, private, fast.**

A browser speed dial replacement you host yourself. Groups, thumbnails, 2FA, dark mode, import/export - no tracking, no ads, no cloud.

**Project website:** [https://LetaLab.eu](https://LetaLab.eu)

![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)
![MySQL](https://img.shields.io/badge/MySQL%2FMariaDB-10.6%2B-blue)
![License](https://img.shields.io/badge/license-MIT-green)

---

## Screenshots

| Login | Dashboard |
|---|---|
| <a href="https://github.com/user-attachments/assets/469456fd-7313-4a84-ab05-2c21d423c333"><img width="100%" alt="Login" src="https://github.com/user-attachments/assets/469456fd-7313-4a84-ab05-2c21d423c333" /></a> | <a href="https://github.com/user-attachments/assets/b58dcfda-239b-4b1c-9df3-9c71fa5da49a"><img width="100%" alt="Dashboard" src="https://github.com/user-attachments/assets/b58dcfda-239b-4b1c-9df3-9c71fa5da49a" /></a> |

| Installer | 2FA Setup |
|---|---|
| <a href="https://github.com/user-attachments/assets/1a7bf682-bb0f-43ec-9f98-5ec4fd7498aa"><img width="100%" alt="Installer" src="https://github.com/user-attachments/assets/1a7bf682-bb0f-43ec-9f98-5ec4fd7498aa" /></a> | <a href="https://github.com/user-attachments/assets/b5854f50-d645-47a1-a4bd-2ad6e8de8342"><img width="100%" alt="2FA Setup" src="https://github.com/user-attachments/assets/b5854f50-d645-47a1-a4bd-2ad6e8de8342" /></a> |

| Vhost SSL Labs Test Results |
|---|
| <a href="https://github.com/user-attachments/assets/5f9d8c6b-7aaf-4e77-8a69-15c888bc8646"><img width="100%" alt="Vhost SSL Labs Test Results" src="https://github.com/user-attachments/assets/5f9d8c6b-7aaf-4e77-8a69-15c888bc8646" /></a> |

---

## Features

- Speed dial grid with custom thumbnails (auto-generated or uploaded)
- Groups with emoji icons, custom colors, custom image icons
- Full-text search, sort options (manual drag & drop, A→Z, popular, newest)
- Two-factor authentication (TOTP - Google Authenticator, Bitwarden, Authy)
- Import / export JSON
- Dark mode (auto + manual toggle)
- Keyboard navigation (Arrow keys, Enter, E, Delete)
- Dial notes with hover tooltip
- Bulk select, move, duplicate, delete
- Pin dials to top of group
- Recently used virtual tab (last 20 clicked)
- Auto-fetch page title and description when adding a dial
- Settings page: password change, backup codes management, UI preferences
- Forgot password / reset password via email
- Admin panel: blocked IPs, users, login history, update, install check
- Auto-update notifications from GitHub Releases (git pull from admin panel)
- Mobile-responsive, PWA-ready

---

## Known issues

- Sometimes uploading a custom thumbnail for a dial does not visibly update the tile right away.
  If that happens, right-click the dial and choose **Refresh thumbnail**.
  Wait about one second, then edit the dial again and upload the custom thumbnail one more time.
  After that, it should work correctly.

---

## Requirements

| Component | Minimum |
|---|---|
| PHP | 8.1+ |
| MySQL / MariaDB | 10.6+ |
| PHP extensions | `pdo_mysql`, `gd` (with WebP), `mbstring`, `openssl`, `json` |
| Web server | nginx or Apache with mod_rewrite |
| HTTPS | Strongly recommended |

Optional (better thumbnails):
- `imagick` PHP extension - enables OG image capture and better image processing

Check requirements before installing:

```bash
php -r "
echo 'PHP: '      . PHP_VERSION . PHP_EOL;
echo 'PDO MySQL: ' . (extension_loaded('pdo_mysql') ? 'OK' : 'MISSING') . PHP_EOL;
echo 'GD: '        . (extension_loaded('gd') ? 'OK' : 'MISSING') . PHP_EOL;
echo 'WebP: '      . (!empty(gd_info()['WebP Support']) ? 'OK' : 'MISSING') . PHP_EOL;
echo 'mbstring: '  . (extension_loaded('mbstring') ? 'OK' : 'MISSING') . PHP_EOL;
echo 'OpenSSL: '   . (extension_loaded('openssl') ? 'OK' : 'MISSING') . PHP_EOL;
echo 'Imagick: '   . (extension_loaded('imagick') ? 'OK (optional)' : 'not installed (optional)') . PHP_EOL;
"
```

---

## Installation

### 1. Create database

```sql
CREATE DATABASE letadial_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'letadial_user'@'localhost' IDENTIFIED BY 'your_strong_password_here';
GRANT ALL PRIVILEGES ON letadial_db.* TO 'letadial_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Clone repository

```bash
cd /var/www/html
git clone https://github.com/LetaLab/LetaDial
```

Project structure after clone:

```text
/var/www/html/LetaDial/
├── api/
├── assets/
├── install.php       ← installer (auto-deletes after use)
├── index.php
├── pages/
├── src/
└── storage/
```

### 3. Set permissions

```bash
chown -R www-data:www-data /var/www/html/LetaDial/
find /var/www/html/LetaDial/ -type d -exec chmod 755 {} \;
find /var/www/html/LetaDial/ -type f -exec chmod 644 {} \;
chmod +x /var/www/html/LetaDial/fix_permissions.sh
bash /var/www/html/LetaDial/fix_permissions.sh
```

### 4. Configure nginx

> **Important:** nginx does **not** read `.htaccess` files.
> The `storage/` and `logs/` location blocks below are **required** to protect user data.

```nginx
server {
    if ($host = CHANGEME.CHANGEME.CHANGEME) {
        return 301 https://$host$request_uri;
    }
    listen 80;
    server_name CHANGEME.CHANGEME.CHANGEME;
}

server {
    listen 443 ssl http2;
    server_name CHANGEME.CHANGEME.CHANGEME;

    # Optional: restrict access to specific IPs only
    # allow YOUR_IP_HERE;
    # allow 192.168.1.0/24;
    # deny all;

    ssl_trusted_certificate /path/to/ca.cer;
    ssl_certificate         /path/to/fullchain.cer;
    ssl_certificate_key     /path/to/CHANGEME.CHANGEME.CHANGEME.key;

    ssl_protocols TLSv1.3 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_conf_command CipherSuites TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256;
    ssl_ciphers ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305;
    ssl_ecdh_curve secp521r1:secp384r1;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;
    resolver 1.1.1.1 1.0.0.1 8.8.8.8 8.8.4.4 valid=300s ipv6=off;
    resolver_timeout 5s;
    ssl_session_cache shared:SSL:50m;
    ssl_session_timeout 30m;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header X-XSS-Protection "0" always;
    add_header Content-Security-Policy "default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' https: data: blob:" always;

    root /var/www/html/LetaDial/;
    index index.php;

    # Hide nginx version
    server_tokens off;

    # Block .git directory — explicit block before the dotfile catch-all
    location ^~ /.git {
        deny all;
        return 404;
    }

    # Block all dotfiles and dot-directories (.htaccess, .env, etc.)
    location ~ /\. {
        deny all;
        return 404;
    }

    # Block storage — thumbnails, sessions, avatars, group_icons
    # .htaccess is NOT read by nginx — this block is required!
    location ^~ /storage/ {
        deny all;
        return 404;
    }

    # Block logs directory
    location ^~ /logs/ {
        deny all;
        return 404;
    }

    # Block sensitive file extensions
    location ~* \.(ini|log|conf|bak|sql|swp|dist)$ {
        deny all;
        return 404;
    }

    location ~* \.(yml)$ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php(?:$|/) {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass   unix:/run/php/php-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param  PATH_INFO $fastcgi_path_info;
        fastcgi_param  HTTPS on;
        fastcgi_hide_header X-Powered-By;
        include        fastcgi_params;
        fastcgi_read_timeout 240;
    }

    access_log /var/log/nginx/CHANGEME.CHANGEME.CHANGEME_ssl_access.log;
    error_log  /var/log/nginx/CHANGEME.CHANGEME.CHANGEME_ssl_error.log;
}

```

> **php-fpm socket path:** adjust `fastcgi_pass` to match your PHP version, e.g.:
> - Ubuntu 24.04 + PHP 8.3: `unix:/run/php/php8.3-fpm.sock`
> - Alpine/generic: `unix:/run/php-fpm/php-fpm.sock`

### 5. Run installer

Navigate to `https://your-domain.com/install.php` and follow the 5 steps:

1. **Requirements** - all checks must pass
2. **Database** - enter DB credentials
3. **Admin** - create admin account (login, email, password)
4. **Email** - optional SMTP for activation emails and password resets
5. **Done** - installer self-deletes, `config.php` created

### 6. Set up 2FA

Admin accounts **require** two-factor authentication. On first login you will be redirected to set up TOTP with any authenticator app (Google Authenticator, Bitwarden, Authy).

### 7. Add to config.php (optional - auto-update)

```php
define('GITHUB_REPO',       'LetaLab/LetaDial');
define('UPDATER_CACHE_TTL', 21600);   // 6 hours cache
```

This enables the update banner for admin users and the Admin → Update tab (git pull from the web).

---

## Directory structure

```text
api/           HTTP endpoints (routed by index.php)
assets/        CSS, JS, icons, manifest
  css/         design-system.css + app.css
  js/          app.js
  icons/       favicon, PWA icons
pages/         HTML page templates
src/           PHP classes (Auth, Dial, Group, CSRF, ...)
storage/       User data - NOT web-accessible (protect with nginx!)
  thumbnails/  Dial thumbnails (WebP)
  group_icons/ Group custom icons (WebP)
  avatars/     User avatars
  sessions/    (reserved)
logs/          Application logs - NOT web-accessible
install.php    Installer - auto-deletes after installation
index.php      Main router
config.php     Generated by installer - NEVER commit this file
fix_permissions.sh  Run as root after deploy/update
```

---

## Security notes

- `config.php` contains database credentials and secret keys - **never commit it** (it's in `.gitignore`)
- `storage/` requires nginx `location ^~ /storage/ { deny all; }` - `.htaccess` is ignored by nginx
- `logs/` requires nginx `location ^~ /logs/ { deny all; }`
- All API endpoints require authentication + CSRF tokens
- 2FA is mandatory for admin accounts
- Passwords: bcrypt cost 12, minimum 12 characters with complexity requirements
- Sessions: DB-backed with SHA-256 token hashing
- Rate limiting on login (10/5min), 2FA (5/5min), imports, thumbnail generation
- `fix_permissions.sh` removes `install.php` on every run

---

## Upgrading

### Via Admin Panel (recommended)

1. Admin → Update tab → Check for updates
2. If update available → "Update now" (runs `git pull` + `fix_permissions.sh`)

### Manual

```bash
cd /var/www/html/LetaDial
sudo -u www-data git pull origin main
sudo bash fix_permissions.sh
```

Always back up `config.php` and your database before upgrading.

---

## Troubleshooting

### Missing database columns after install

If you installed from an older version of `install.php`, some columns may be missing.
The Admin → Install Check tab will report exactly which columns are absent with migration commands.

Common issues:

```sql
-- Missing rate_limits.key_plain (needed for Blocked IPs panel)
ALTER TABLE letadial_db.rate_limits ADD COLUMN key_plain varchar(255) NULL AFTER window_start;

-- Missing dials.notes
ALTER TABLE letadial_db.dials ADD COLUMN notes text NULL AFTER url;

-- Missing groups_list icon/color columns
ALTER TABLE letadial_db.groups_list
  ADD COLUMN icon varchar(10) NULL AFTER created_at,
  ADD COLUMN color varchar(7) NULL AFTER icon,
  ADD COLUMN icon_path varchar(255) NULL AFTER color;

-- Missing users.recent_disabled
ALTER TABLE letadial_db.users ADD COLUMN recent_disabled tinyint(1) NOT NULL DEFAULT 0;
```

### Thumbnails not showing

```bash
sudo bash /var/www/html/LetaDial/fix_permissions.sh
```

Check that `storage/thumbnails/` is writable by `www-data`.

### 500 error on login

Check PHP error log and nginx error log. Most common cause: missing DB column (see above) or wrong `config.php` credentials.

---

## License

MIT - see [LICENSE](LICENSE)

---

## Credits

Built by [LetaLab](https://LetaLab.eu)
