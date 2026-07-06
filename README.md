# LetaDial

<p align="left">  
<img src="https://github.com/user-attachments/assets/a914458f-d3bb-463b-874e-e8498b87ae23" alt="OG" width="25%">
</p>

---

<p align="center">
  <em>Hi, I'm Leta - the mascot of all projects under the LetaLab umbrella!</em><br><br>
  <em>Andrzej brought me to life using Inkscape! I am related to Tux!</em><br>
</p>

<p align="center">
  <img src="https://github.com/user-attachments/assets/e6230a1e-3fbd-48f7-965c-fdb42e52d370" alt="icon-512" width="220">
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

### Speed Dial Dashboard
- Speed dial grid with custom thumbnails (auto-generated via OG image / GD gradient fallback)
- Custom thumbnail upload (JPG/PNG/WebP → Imagick → WebP 163×100 px, EXIF stripped)
- Favicon overlay on gradient thumbnails (fetched directly by browser — no server-side SSRF)
- Bulk refresh thumbnails
- Dial notes (up to 500 chars, hover tooltip, preserved on duplicate/export)
- Pin dials to top of group (persists across all sort modes)
- Duplicate dial — to same or different group (single + bulk)
- Middle-click opens in background tab (native `<a>` element, no JS window.open)
- Left-click records click count and opens in new tab
- Open all dials in new tabs (synchronous — bypasses popup blockers)
- Drag & drop reorder within group (disabled for pinned dials and non-manual sort)
- Full-text search across title, URL and notes (debounced 150 ms, `/` shortcut, `×` clear)
- Sort options per group: Manual / A→Z / Z→A / 🔥 Popular / Newest / Oldest (persisted in localStorage)
- Keyboard navigation: Arrow keys, Enter (open), E (edit), Delete (confirm delete), Escape
- "All groups" virtual tab — shows all dials across every group simultaneously
- Recently used virtual tab — last 20 clicked dials, sorted by last_click DESC
- OG meta auto-fetch — title and description auto-filled when adding a dial (debounced + blur)

### LetaLink Bookmarklet
- Drag-to-toolbar bookmarklet — add any webpage to LetaDial in one click
- Popup window (430×540 px) pre-fills URL, title and OG description from the page
- Group selector with localStorage memory of last used group
- Notes field (500 chars) with live counter
- Thumbnail generated in background after save; popup auto-closes after 2 s
- Mobile fallback: copy bookmarklet code from Settings → LetaLink, paste as bookmark URL
- Settings → LetaLink: drag button, Copy code, Test popup link

### Groups
- Groups with emoji icons, custom color per group, custom image icon upload (32×32 WebP, GD decode)
- Reorder groups left/right from context menu
- Context menu: rename, delete, move left/right, style (emoji + color + image)
- Group count badge updates live

### Bulk Actions
- Multi-select mode (checkbox overlay on cards)
- Bulk move, duplicate, delete, refresh thumbnails
- Group picker modal for move/duplicate targets
- `☑ Select multiple` from single-dial context menu enters bulk mode pre-selecting that dial

### Themes & Customization
- Three themes: Light / Dark / Midnight (cool navy/graphite)
- Theme cycle button (Light → Dark → Midnight → Light)
- Theme saved per-user in database (no flash on page load — PHP inline `<style>` scoped per `[data-theme]`)
- Custom primary color per-user per-theme (color picker + HEX input + 6 curated suggestions)
- Automatic contrast FG (#000/#fff) based on luminance
- Recently used tab can be hidden per-user (Settings → UI Preferences → Hide Recent)

### User Avatars
- Upload profile photo per user (JPEG / PNG / GIF / WebP → GD → 128×128 WebP, EXIF stripped)
- Shown in: dashboard topbar (desktop + mobile), Settings preview, Admin → Users table
- Served through authenticated PHP endpoint — direct web access blocked
- ETag / 304 caching; removed automatically when account is deleted

### Authentication & Security
- Login with rate limiting (IP-based, `REMOTE_ADDR` only — X-Forwarded-For spoofing blocked)
- TOTP two-factor authentication (Google Authenticator, Bitwarden, Authy)
- Custom pure-PHP QR code generator (no external libraries) — PNG via GD, inline CSS stripped
- Admin 2FA enforced — redirect to `/setup-2fa` until configured
- TOTP time drift tolerance ±60 seconds (wider window for clock-skewed mobile devices)
- Backup codes (10 × bcrypt, single-use, downloadable as `.txt`)
- Backup codes regeneration (requires TOTP verification)
- Remember me (90-day cookie, `HttpOnly`, `Secure`, `SameSite=Strict`)
- CSRF protection — dual-mode: HMAC-SHA256 (authenticated) + double-submit cookie (pre-auth)
- AES-256-GCM encrypted TOTP secrets in database
- Bcrypt passwords (`cost=12`, auto-salted)
- POST-only logout (GET `/logout` redirects without action)
- Rate limiting on: login, 2FA, forgot password, thumbnail refresh, import, invite, registration
- SSRF protection on thumbnail fetch: DNS resolve + private/reserved range block
- URL scheme whitelist (`http`/`https` only — blocks `ftp://`, `file://`, `javascript:`, etc.)
- EXIF/metadata stripping on all uploaded images (GD re-encode)
- `storage/` served by PHP only — direct web access blocked via `.htaccess` deny-all
- Cookie consent banner on login page (EU ePrivacy Directive 2002/58/EC + GDPR 2016/679)
- Email activation flow for new accounts (256-bit token, single-use, via `/activate`)

### Sessions
- All sessions stored in database (not filesystem)
- View and terminate own active sessions (Settings → Active Sessions)
- Admin: view and terminate any user's sessions
- Password change invalidates all sessions
- Email change invalidates all sessions
- Force password reset (admin) invalidates all sessions

### Account Management
- Forgot password / reset via email (256-bit token, 1-hour expiry, single-use)
- Email change with confirmation link sent to new address (1-hour expiry)
- Self-registration (toggleable by admin, rate-limited 5/IP/h)
- Admin: invite user via email (24-hour setup link, user sets own password)
- Admin: direct user creation (admin sets password, account active immediately, no SMTP required)
- Admin: force password reset for any user

### Import / Export
- Export to LetaDial JSON (version 1.1, includes notes, group names, positions)
- Import LetaDial JSON
- Import legacy speed dial browser export format (`{"db":{"groups":[],"dials":[]}}`)
- Import sanitises URLs (`filter_var` + scheme whitelist) and titles (`strip_tags` + truncate)
- Duplicate URL per group skipped on import
- Respects `max_dials_per_user` and `max_groups_per_user` limits

### Admin Panel
- Blocked IPs: list, unblock single / all for IP / unblock all; export CSV/JSON
- Users: list with stats, delete account (cascades thumbnails + group_icons + avatar), force logout
- Login history: last N entries, filter by IP or status
- Sessions: all active sessions across all users, terminate any
- Auto-update: check GitHub Releases API (cached 6h in `settings` table), update notification banner for admin
- Update via git pull from admin panel, requires re-entering your password (step-up auth) — always pulls from `https://github.com/LetaLab/LetaDial`, verified live against `git remote -v` on every Install Check
- Install Check: PHP extensions, GD WebP, Imagick, DB schema, config constants, security (no `install.php`, HTTPS, `.git` block, git remote origin, world-writable directories), filesystem permissions, file integrity via `git status`
- Registration toggle: enable/disable self-registration with one click

### Installer (`install.php`)
- 5-step web wizard — no CLI required
- Detects missing PHP extensions (PDO, GD, WebP support, Imagick, OpenSSL, mbstring)
- Tests database connection before proceeding
- Auto-generates `config.php` with cryptographically random `HMAC_KEY` and `ENCRYPTION_KEY`
- Auto-sets file permissions (`chmod 600` config, `755` dirs, `644` files)
- Creates `storage/` directory structure with correct ownership
- Creates all database tables and default settings in one transaction
- Validates SMTP by sending a test activation email during setup
- Self-deletes after successful installation
- `install.php` is also removed automatically after every `git pull` (via
  the update flow), and again by `LetaDial_Permissions.sh` if you've set
  it up (see [Permissions](#permissions)) — defense in depth, not reliant
  on any single mechanism

### Architecture & Quality
- Zero external PHP dependencies — no Composer, no CDN, no npm
- Zero JavaScript frameworks — vanilla ES6+ modules
- Zero CSS frameworks — custom design system with CSS custom properties
- System font stack only (`system-ui, -apple-system, 'Segoe UI'`)
- All thumbnails served as WebP (163×100 px, quality 72) with ETag/304 caching
- PWA-ready: `manifest.json`, icons (48/192/512 px PNG + SVG), Apple Touch Icon
- Mobile-responsive layout: hamburger menu, fluid grid (`auto-fill minmax`)
- OG meta tags on dashboard (pinguin mascot image, title, description)
- Shared design system CSS between all LetaLab projects
- Git-based deployment — self-hosted Forgejo or GitHub

---

## Planned / Upcoming

- Trusted device — skip 2FA for 30 days on confirmed devices
- GDPR: full data export (own dials, groups, settings as JSON)
- GDPR: account self-deletion with cascade
- i18n — English / Polish (array-based `lang/en.php` + `lang/pl.php`)

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

Optional:
- `imagick` PHP extension — enables OG image capture and better image processing
- `exif` PHP extension — auto-corrects avatar orientation from phone cameras (cosmetic, not required)

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
```

`install.php` creates `storage/`, `logs/`, and their `.htaccess` files
itself during setup. For ongoing permission maintenance after install
(recommended), set up `LetaDial_Permissions.sh` — see
[Permissions](#permissions) below.

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
    add_header Content-Security-Policy "default-src 'self' https: data: 'unsafe-inline'; img-src 'self' https: data: blob:" always;

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

## Permissions

LetaDial does **not** ship a permission-fixing script inside the repository.
A script that lives inside a git-tracked, auto-updatable directory and later
gets executed as root is a privilege-escalation risk if the GitHub repo were
ever compromised. Instead, create your own copy **outside** the project
directory and run it independently via cron.

### 1. Create `/usr/sbin/LetaDial_Permissions.sh`

```bash
cat <<'EOF' > /usr/sbin/LetaDial_Permissions.sh
#!/bin/bash
# LetaDial — Permission Fix + Cleanup Script (standalone, root-only)
#
# This script intentionally lives OUTSIDE the git repository so a
# compromised LetaDial origin can never modify it. It is not executed by
# the web-based "Update now" flow or by any code inside the repo — run it
# only via cron (see README) or manually.
#
# What this does:
#   1. Sets correct ownership (www-data) and permissions (dirs 755, files 644)
#      — this also strips any accidental world-writable (777/775/etc) bits.
#   2. Protects config.php with 600
#   3. Creates missing required directories with correct .htaccess files
#   4. Removes misplaced src/*.php files from api/
#   5. Removes leftover installer, migration, and legacy files

# ── Configuration — EDIT THIS if your install path differs ────────────────
APP_DIR="/var/www/html/LetaDial"
WEB_USER="www-data"

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root." >&2
    exit 1
fi

if [ ! -d "$APP_DIR" ]; then
    echo "APP_DIR does not exist: $APP_DIR" >&2
    echo "Edit the APP_DIR variable at the top of this script." >&2
    exit 1
fi

echo "LetaDial — fixing permissions"
echo "APP_DIR: $APP_DIR"
echo ""

# ── 1. Ownership ──────────────────────────────────────────────────────────
chown -R ${WEB_USER}:${WEB_USER} "$APP_DIR"

# ── 2. Permissions: dirs 755, files 644 (also strips world-writable bits) ─
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;

# ── 3. config.php: 600 ────────────────────────────────────────────────────
if [ -f "$APP_DIR/config.php" ]; then
    chmod 600 "$APP_DIR/config.php"
    echo "config.php → 600"
fi

# ── 4. Ensure required directories exist ─────────────────────────────────
for dir in \
    storage \
    storage/thumbnails \
    storage/sessions \
    storage/avatars \
    storage/group_icons \
    logs \
    api \
    assets/css \
    assets/js \
    assets/icons \
    src \
    pages; do
    full="$APP_DIR/$dir"
    if [ ! -d "$full" ]; then
        mkdir -p "$full"
        chown ${WEB_USER}:${WEB_USER} "$full"
        chmod 755 "$full"
        echo "Created: $dir/"
    fi
done

# ── 5. Create missing .htaccess files ────────────────────────────────────
DENY_ALL="Options -Indexes\nOrder deny,allow\nDeny from all"
NO_PHP="Options -Indexes\nphp_flag engine off"

write_htaccess() {
    local path="$1"
    local content="$2"
    if [ ! -f "$path" ]; then
        printf "$content\n" > "$path"
        chown ${WEB_USER}:${WEB_USER} "$path"
        echo "Created: ${path#$APP_DIR/}"
    fi
}

write_htaccess "$APP_DIR/storage/.htaccess"             "$DENY_ALL"
write_htaccess "$APP_DIR/storage/sessions/.htaccess"    "$DENY_ALL"
write_htaccess "$APP_DIR/storage/avatars/.htaccess"     "$DENY_ALL"
write_htaccess "$APP_DIR/storage/group_icons/.htaccess" "$DENY_ALL"
write_htaccess "$APP_DIR/storage/thumbnails/.htaccess"  "$NO_PHP"
write_htaccess "$APP_DIR/logs/.htaccess"                "$DENY_ALL"

# ── 6. Remove misplaced src/ files from api/ ─────────────────────────────
API_SRC_FILES=(
    Auth.php CSRF.php DB.php Mailer.php Password.php
    QRCode.php RateLimit.php TOTP.php
    Group.php Dial.php Thumbnail.php GroupIcon.php Avatar.php
    Meta.php Updater.php Import.php Export.php Admin.php
)
REMOVED_FROM_API=0
for f in "${API_SRC_FILES[@]}"; do
    if [ -f "$APP_DIR/api/$f" ]; then
        rm -f "$APP_DIR/api/$f"
        echo "Removed misplaced: api/$f"
        REMOVED_FROM_API=1
    fi
done
[ "$REMOVED_FROM_API" -eq 0 ] && echo "api/ folder: clean"

# ── 7. Remove installer, migration, and legacy files ─────────────────────
for f in install.php fix_permissions.sh \
         migrate_001.sql migrate_002.sql migrate_051.sql \
         migrate_052.sql migrate_057.sql migrate_064.sql migrate_065.sql; do
    if [ -f "$APP_DIR/$f" ]; then
        rm -f "$APP_DIR/$f"
        echo "Removed: $f"
    fi
done

echo ""
echo "Done."
EOF
chmod 700 /usr/sbin/LetaDial_Permissions.sh
```

If your install path is not `/var/www/html/LetaDial`, edit the `APP_DIR`
variable near the top of the script before running it.

### 2. Run it once manually

```bash
sudo /usr/sbin/LetaDial_Permissions.sh
```

### 3. Add it to root's crontab

```bash
sudo crontab -e
```

Add this line:

```
0 * * * * /usr/sbin/LetaDial_Permissions.sh >> /var/log/letadial-permissions.log 2>&1
```

Runs hourly. `*/30 * * * *` (every 30 minutes) is also reasonable on a
low-traffic personal instance — adjust to your preference.

Admin → Install Check flags world-writable directories and ownership
mismatches, and always points back to `sudo /usr/sbin/LetaDial_Permissions.sh`
as the fix.

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
- Updates only ever pull from `https://github.com/LetaLab/LetaDial` — the
  Admin panel verifies `git remote get-url origin` on every Install Check
  and warns if it's ever anything else
- "Update now" requires re-entering your current password (step-up auth)
  before it runs `git pull` — a stolen session cookie alone is not enough
- `install.php` is removed automatically after every `git pull`, and again
  by `LetaDial_Permissions.sh` if you've set it up — see [Permissions](#permissions)
- Permission maintenance runs as an independent script **outside** the git
  repository (`/usr/sbin/LetaDial_Permissions.sh`), so a compromised
  GitHub repo can never modify the script a root cron executes

---

## Upgrading

### Via Admin Panel (recommended)

1. Admin → Update tab → Check for updates
2. If update available → "Update now" → re-enter your password to confirm → runs `git pull origin main`

### Manual

```bash
cd /var/www/html/LetaDial
sudo -u www-data git pull origin main
```

If you've set up `/usr/sbin/LetaDial_Permissions.sh` (see
[Permissions](#permissions)), it will pick up any permission drift on its
next scheduled run — or run it immediately:

```bash
sudo /usr/sbin/LetaDial_Permissions.sh
```

Always back up `config.php` and your database before upgrading.

---

## Troubleshooting

### Thumbnails not showing

```bash
sudo /usr/sbin/LetaDial_Permissions.sh
```

Check that `storage/thumbnails/` is writable by `www-data`. If you haven't
set up `LetaDial_Permissions.sh` yet, see [Permissions](#permissions), or
fix it directly:

```bash
sudo chown -R www-data:www-data /var/www/html/LetaDial/storage
sudo find /var/www/html/LetaDial/storage -type d -exec chmod 755 {} \;
sudo find /var/www/html/LetaDial/storage -type f -exec chmod 644 {} \;
```

---

## License

MIT - see [LICENSE](LICENSE)

---

## Credits

Built by [LetaLab](https://LetaLab.eu)
