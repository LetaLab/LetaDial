#!/bin/bash
# LetaDial — Permission Fix + Cleanup Script (standalone, root-only)
#
# This script intentionally lives OUTSIDE the git repository so a
# compromised LetaDial origin can never modify it. It is not executed by
# the web-based "Update now" flow or by any code inside the repo — run it
# only via cron (see README → Permissions) or manually.
#
# Install path: /usr/sbin/LetaDial_Permissions.sh
# (see README.md → Permissions for the cat <<'EOF' one-liner + crontab entry)
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
