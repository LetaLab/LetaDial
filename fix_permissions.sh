#!/bin/bash
# LetaDial — Permission Fix + Cleanup Script
# Wykrywa własny katalog automatycznie — działa z dowolnej lokalizacji.
# Run as root: bash /path/to/fix_permissions.sh
#
# What this does:
#   1. Sets correct ownership (www-data) and permissions (dirs 755, files 644)
#   2. Protects config.php with 600
#   3. Creates missing required directories with correct .htaccess files
#   4. Removes misplaced src/*.php files from api/
#   5. Removes leftover installer and migration files
#   6. Makes this script executable

# ── Detect own directory ──────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$SCRIPT_DIR"
WEB_USER="www-data"

echo "LetaDial — fixing permissions"
echo "APP_DIR: $APP_DIR"
echo ""

# ── 1. Ownership ──────────────────────────────────────────────────────────────
chown -R ${WEB_USER}:${WEB_USER} "$APP_DIR"

# ── 2. Permissions: dirs 755, files 644 ───────────────────────────────────────
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;

# ── 3. config.php: 600 ────────────────────────────────────────────────────────
[ -f "$APP_DIR/config.php" ] && chmod 600 "$APP_DIR/config.php" && echo "config.php → 600"

# ── 4. fix_permissions.sh: executable ────────────────────────────────────────
chmod +x "$APP_DIR/fix_permissions.sh" 2>/dev/null || true

# ── 5. Ensure required directories exist ─────────────────────────────────────
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

# ── 6. Create missing .htaccess files ─────────────────────────────────────────
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

write_htaccess "$APP_DIR/storage/.htaccess"            "$DENY_ALL"
write_htaccess "$APP_DIR/storage/sessions/.htaccess"   "$DENY_ALL"
write_htaccess "$APP_DIR/storage/avatars/.htaccess"    "$DENY_ALL"
write_htaccess "$APP_DIR/storage/group_icons/.htaccess" "$DENY_ALL"
write_htaccess "$APP_DIR/storage/thumbnails/.htaccess" "$NO_PHP"
write_htaccess "$APP_DIR/logs/.htaccess"               "$DENY_ALL"

# ── 7. Remove misplaced src/ files from api/ ──────────────────────────────────
API_SRC_FILES=(
    Auth.php CSRF.php DB.php Mailer.php Password.php
    QRCode.php RateLimit.php TOTP.php
    Group.php Dial.php Thumbnail.php GroupIcon.php
    Meta.php Updater.php Import.php Export.php Admin.php
    Avatar.php
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

# ── 8. Remove installer and migration files ────────────────────────────────────
for f in install.php migrate_001.sql migrate_002.sql migrate_051.sql \
          migrate_052.sql migrate_057.sql migrate_064.sql migrate_065.sql; do
    [ -f "$APP_DIR/$f" ] && rm -f "$APP_DIR/$f" && echo "Removed: $f"
done

echo ""
echo "Result:"
echo "-------"
ls -la "$APP_DIR"
echo ""
echo "src/ contents:"
ls -la "$APP_DIR/src/"
echo ""
echo "api/ contents:"
ls -la "$APP_DIR/api/"
echo ""
echo "storage/ contents:"
ls -la "$APP_DIR/storage/"
echo ""
echo "config.php (should be 600):"
ls -la "$APP_DIR/config.php"
echo ""
echo "Done."
