#!/bin/bash
# Runtime entrypoint: never installs or seeds. Use /usr/local/bin/provision.sh for that.
set -euo pipefail

echo "Starting TastyIgniter runtime"

setup_writable_dirs() {
    mkdir -p /var/www/html/storage/app/public/media/uploads \
        /var/www/html/storage/app/public/media/attachments \
        /var/www/html/storage/framework/{cache/data,sessions,views} \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache
    # Deny PHP execution under public media (TI WebServerSecurityCheck probes).
    cat > /var/www/html/storage/app/public/.htaccess <<'EOF'
Options -Indexes
<FilesMatch "\.ph(p[3457]?|t|tml)$">
    Require all denied
</FilesMatch>
EOF
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
    chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache
}

env_upsert() {
    local key="$1"
    local value="$2"
    local file="/var/www/html/.env"
    [ -f "$file" ] || return 0
    local encoded="$value"
    case "$value" in
        *[!A-Za-z0-9._:@/-]*)
            encoded=$(printf '%s' "$value" | sed 's/"/\\"/g')
            encoded="\"${encoded}\""
            ;;
    esac
    if grep -q "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${encoded}|" "$file"
    else
        printf '%s=%s\n' "$key" "$encoded" >> "$file"
    fi
}

apply_runtime_env() {
    [ -f /var/www/html/.env ] || return 0
    for key in APP_ENV APP_DEBUG APP_URL APP_KEY \
        DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
        CACHE_DRIVER QUEUE_CONNECTION \
        MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION \
        MAIL_FROM_ADDRESS MAIL_FROM_NAME \
        SESSION_SECURE_COOKIE SESSION_SAME_SITE \
        CDP_ENABLED EVENT_CLIENT_DRIVER CDP_ANONYMOUS_ID_COOKIE \
        RUDDERSTACK_DATA_PLANE_URL RUDDERSTACK_JS_DATA_PLANE_URL \
        RUDDERSTACK_JS_CONFIG_URL RUDDERSTACK_HTTP_WRITE_KEY RUDDERSTACK_JS_WRITE_KEY; do
        eval "val=\${$key-}"
        if [ -n "${val}" ]; then
            env_upsert "$key" "$val"
        fi
    done

    if [ "${APP_ENV:-}" = "production" ]; then
        env_upsert APP_DEBUG false
        env_upsert APP_ENV production
    fi
}

# First boot of a fresh volume: copy baked application files (still no seed/install)
if [ ! -f /var/www/html/public/index.php ]; then
    echo "Initializing application files from image..."
    if [ ! -w /var/www/html ]; then
        echo "ERROR: /var/www/html is not writable and app files are missing; re-run provision" >&2
        exit 1
    fi
    if ! cp -a /usr/src/tastyigniter/. /var/www/html/ 2>/dev/null; then
        cp -R /usr/src/tastyigniter/. /var/www/html/
    fi
fi

setup_writable_dirs

if [ ! -f /var/www/html/.env ] && [ -f /var/www/html/.env.example ]; then
    cp /var/www/html/.env.example /var/www/html/.env
fi

apply_runtime_env

# Overlay extensions from /opt/extensions.
# Never fail the container if a leftover root-owned file blocks the copy
# (e.g. after a manual docker cp); chown and retry once.
if [ -d /opt/extensions ]; then
    mkdir -p /var/www/html/extensions
    chmod -R u+w /var/www/html/extensions 2>/dev/null || true
    chown -R www-data:www-data /var/www/html/extensions 2>/dev/null || true
    cp -a /opt/extensions/. /var/www/html/extensions/ 2>/dev/null \
        || cp -R /opt/extensions/. /var/www/html/extensions/ 2>/dev/null \
        || true
    chown -R www-data:www-data /var/www/html/extensions 2>/dev/null || true
fi

if [ -f /var/www/html/public/index.php ] && [ ! -e /var/www/html/public/storage ]; then
    (cd /var/www/html && php artisan storage:link) || ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
fi

# Apache document root
sed -i 's|DocumentRoot[[:space:]].*|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
cat > /etc/apache2/conf-available/public-dir.conf <<'EOF'
<Directory "/var/www/html/public">
    AllowOverride All
    Require all granted
</Directory>
EOF
a2enconf public-dir >/dev/null

# Cap Apache workers from env (connection budget)
workers="${APACHE_MAX_REQUEST_WORKERS:-4}"
cat > /etc/apache2/conf-available/dilibori-mpm.conf <<EOF
<IfModule mpm_prefork_module>
    StartServers 1
    MinSpareServers 1
    MaxSpareServers ${workers}
    MaxRequestWorkers ${workers}
    MaxConnectionsPerChild 200
</IfModule>
EOF
a2enconf dilibori-mpm >/dev/null || true

if [ "${1:-}" = "provision" ]; then
    shift
    exec /usr/local/bin/provision.sh "$@"
fi

# One-shot install and seed when AUTO_PROVISION=true (compose sets this).
if [ "${AUTO_PROVISION:-false}" = "true" ] && [ ! -f /var/www/html/storage/dilibori-installed.flag ]; then
    echo "AUTO_PROVISION=true: running one-shot provision..."
    /usr/local/bin/provision.sh
fi

# Theme CSS (public/vendor/igniter-orange/css/) is not volume-mounted. Provision
# builds brand colors into a one-off container; every runtime recreate would
# otherwise serve stock Orange #ff4900 until the next seed. Rebuild from DB
# after a brief DB wait so ProxySQL/MariaDB are reachable.
if [ -f /var/www/html/storage/dilibori-installed.flag ] \
    && [ -f /usr/local/bin/rebuild-theme-css.php ] \
    && [ -f /var/www/html/artisan ]; then
    echo "Waiting for database before theme CSS rebuild..."
    for _i in $(seq 1 30); do
        if (cd /var/www/html && php artisan db:show >/dev/null 2>&1) \
            || (cd /var/www/html && php -r 'try{new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0);}catch(Throwable){exit(1);}'); then
            break
        fi
        sleep 2
    done
    echo "Rebuilding theme CSS from DB customizations..."
    # buildBundles can take ~1–2m; bound it so a hang never blocks Apache forever.
    if command -v timeout >/dev/null 2>&1; then
        timeout 180 php /usr/local/bin/rebuild-theme-css.php \
            || echo "WARN: theme CSS rebuild failed/timed out (storefront may show default Orange colors)" >&2
    else
        php /usr/local/bin/rebuild-theme-css.php \
            || echo "WARN: theme CSS rebuild failed (storefront may show default Orange colors)" >&2
    fi
fi

# Root artisan/seed can recreate storage files after the early chown.
setup_writable_dirs

if [ "${1:-}" = "apache2-foreground" ] || [ $# -eq 0 ]; then
    if command -v supervisord >/dev/null 2>&1 && [ -f /etc/supervisor/conf.d/dilibori.conf ]; then
        echo "Starting supervisord (apache + queue + scheduler)..."
        exec supervisord -n -c /etc/supervisor/supervisord.conf
    fi
    echo "Starting Apache..."
    exec apache2-foreground
fi

exec "$@"
