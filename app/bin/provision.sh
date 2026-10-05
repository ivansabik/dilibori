#!/bin/bash
# Provisioning job: install TastyIgniter once, then apply a tenant seed manifest.
# Normal container startup never runs this script.
set -euo pipefail

echo "Provisioning TastyIgniter for TENANT_ID=${TENANT_ID:-unknown} DEPLOYMENT_ID=${DEPLOYMENT_ID:-unknown}"

setup_writable_dirs() {
    mkdir -p /var/www/html/storage/app/public/media/uploads \
        /var/www/html/storage/app/public/media/attachments \
        /var/www/html/storage/framework/{cache/data,sessions,views} \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache
    cat > /var/www/html/storage/app/public/.htaccess <<'EOF'
Options -Indexes
<FilesMatch "\.ph(p[3457]?|t|tml)$">
    Require all denied
</FilesMatch>
EOF
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
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
        CACHE_DRIVER REDIS_HOST REDIS_PASSWORD REDIS_PREFIX \
        QUEUE_CONNECTION \
        MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_ENCRYPTION \
        MAIL_FROM_ADDRESS MAIL_FROM_NAME \
        TENANT_ID DEPLOYMENT_ID CELL_ID DEPLOYMENT_ENVIRONMENT \
        CDP_ENABLED EVENT_CLIENT_DRIVER \
        RUDDERSTACK_DATA_PLANE_URL RUDDERSTACK_JS_DATA_PLANE_URL \
        RUDDERSTACK_JS_CONFIG_URL RUDDERSTACK_HTTP_WRITE_KEY RUDDERSTACK_JS_WRITE_KEY; do
        eval "val=\${$key-}"
        if [ -n "${val}" ]; then
            env_upsert "$key" "$val"
        fi
    done
}

# Random admin password when unset (platform injects ADMIN_PASSWORD; legacy compose does not).
# Mirror to persistent-media so legacy container recreates keep the same secret.
ensure_admin_password() {
    local persistent="/var/www/persistent-media/.dilibori-admin-password"
    local storage="/var/www/html/storage/dilibori-admin-password"
    mkdir -p /var/www/html/storage

    write_password_files() {
        printf '%s' "$ADMIN_PASSWORD" > "$storage"
        chmod 600 "$storage" 2>/dev/null || true
        if [ -d /var/www/persistent-media ] && [ -w /var/www/persistent-media ]; then
            printf '%s' "$ADMIN_PASSWORD" > "$persistent"
            chmod 600 "$persistent" 2>/dev/null || true
        fi
    }

    if [ -z "${ADMIN_PASSWORD:-}" ]; then
        if [ -f "$persistent" ] && [ -s "$persistent" ]; then
            ADMIN_PASSWORD="$(tr -d '\n' < "$persistent")"
        elif [ -f "$storage" ] && [ -s "$storage" ]; then
            ADMIN_PASSWORD="$(tr -d '\n' < "$storage")"
        else
            ADMIN_PASSWORD="$(openssl rand -hex 12)"
            echo "Generated ADMIN_PASSWORD (retrieve with make admin-pass)"
        fi
        export ADMIN_PASSWORD
    fi
    write_password_files
}

if [ ! -f /var/www/html/public/index.php ]; then
    echo "Copying application files from image..."
    if ! cp -a /usr/src/tastyigniter/. /var/www/html/ 2>/dev/null; then
        cp -R /usr/src/tastyigniter/. /var/www/html/
    fi
fi

setup_writable_dirs

if [ ! -f /var/www/html/.env ]; then
    if [ -f /var/www/html/.env.example ]; then
        cp /var/www/html/.env.example /var/www/html/.env
    else
        touch /var/www/html/.env
    fi
fi

apply_runtime_env

if [ -d /opt/extensions ]; then
    mkdir -p /var/www/html/extensions
    chmod -R u+w /var/www/html/extensions 2>/dev/null || true
    chown -R www-data:www-data /var/www/html/extensions 2>/dev/null || true
    cp -a /opt/extensions/. /var/www/html/extensions/ 2>/dev/null \
        || cp -R /opt/extensions/. /var/www/html/extensions/ 2>/dev/null \
        || true
    chown -R www-data:www-data /var/www/html/extensions || true
fi

cd /var/www/html

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    if [ -n "${APP_KEY:-}" ]; then
        env_upsert APP_KEY "$APP_KEY"
    else
        php artisan key:generate --force
    fi
fi

echo "Waiting for database..."
max_retries=60
try=0
until php -r "new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:3306).';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
    try=$((try + 1))
    if [ "$try" -ge "$max_retries" ]; then
        echo "Database not reachable after ${max_retries} attempts" >&2
        exit 1
    fi
    sleep 2
done

if [ ! -f /var/www/html/storage/dilibori-installed.flag ]; then
    echo "Running igniter:install..."
    php artisan igniter:install --no-interaction
    touch /var/www/html/storage/dilibori-installed.flag
fi

if [ -n "${SEED_MANIFEST:-}" ] && [ -f "${SEED_MANIFEST}" ]; then
    export SEED_MODE="${SEED_MODE:-initial}"
    ensure_admin_password
    echo "Applying seed manifest ${SEED_MANIFEST} (mode=${SEED_MODE})..."
    php /opt/platform/seeds/common/seed-from-manifest.php "$SEED_MANIFEST"
fi

if [ ! -e /var/www/html/public/storage ]; then
    php artisan storage:link || ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
fi

# Artisan/seed run as root; hand storage back to the web user before exit.
setup_writable_dirs

echo "Provisioning complete"
