#!/bin/bash
set -e

echo "Starting TastyIgniter entrypoint script..."

# Symlink storage/app/public/media → persistent volume (must run BEFORE seed)
# so photos are not written into a container-local dir that later gets deleted.
setup_persistent_media() {
    mkdir -p /var/www/html/storage/app/public
    mkdir -p /var/www/persistent-media/uploads /var/www/persistent-media/attachments
    MEDIA_LINK="/var/www/html/storage/app/public/media"
    if [ -L "$MEDIA_LINK" ]; then
        : # already linked
    elif [ -d "$MEDIA_LINK" ]; then
        echo "Migrating container-local media into persistent volume..."
        cp -a "$MEDIA_LINK"/. /var/www/persistent-media/ 2>/dev/null || true
        rm -rf "$MEDIA_LINK"
        ln -sfn /var/www/persistent-media "$MEDIA_LINK"
    elif [ ! -e "$MEDIA_LINK" ]; then
        ln -sfn /var/www/persistent-media "$MEDIA_LINK"
    fi
    for dir in /var/www/persistent-media/uploads /var/www/persistent-media/attachments; do
        mkdir -p "$dir"
        chown www-data:www-data "$dir" 2>/dev/null || true
        chmod 777 "$dir"
    done
}

# First-run application setup
if [ ! -f '/var/www/html/public/index.php' ]; then
    echo "Setting up TastyIgniter application... (first start)"

    # Clear existing files EXCEPT the persistent media volume
    # (now safe because media is mounted at different location)
    rm -rf /var/www/html/*

    # Copy application files
    cp -a /usr/src/tastyigniter/. /var/www/html/

    # Link media before install/seed so attachments land on the host volume
    setup_persistent_media

    # Create .env file with proper values
    if [ -f "/var/www/html/.env.example" ]; then
        echo "Creating .env file..."
        cp /var/www/html/.env.example /var/www/html/.env

        # Set basic configuration from container environment
        if [ -n "${APP_ENV}" ]; then
            sed -i "s|^APP_ENV=.*|APP_ENV=${APP_ENV}|g" /var/www/html/.env
        fi
        if [ -n "${APP_DEBUG}" ]; then
            sed -i "s|^APP_DEBUG=.*|APP_DEBUG=${APP_DEBUG}|g" /var/www/html/.env
        fi
        if [ -n "${APP_URL}" ]; then
            # Escape / for sed
            APP_URL_ESCAPED=$(printf '%s\n' "${APP_URL}" | sed 's/[\/&]/\\&/g')
            sed -i "s|^APP_URL=.*|APP_URL=${APP_URL_ESCAPED}|g" /var/www/html/.env
        fi

        # Set database config if provided
        if [ -n "${DB_CONNECTION}" ]; then
            sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=${DB_CONNECTION}|g" /var/www/html/.env
        fi
        if [ -n "${DB_HOST}" ]; then
            sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|g" /var/www/html/.env
        fi
        if [ -n "${DB_PORT}" ]; then
            sed -i "s|^DB_PORT=.*|DB_PORT=${DB_PORT}|g" /var/www/html/.env
        fi
        if [ -n "${DB_DATABASE}" ]; then
            sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|g" /var/www/html/.env
        fi
        if [ -n "${DB_USERNAME}" ]; then
            sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME}|g" /var/www/html/.env
        fi
        if [ -n "${DB_PASSWORD}" ]; then
            sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|g" /var/www/html/.env
        fi
        if [ -n "${CACHE_DRIVER}" ]; then
            sed -i "s|^CACHE_DRIVER=.*|CACHE_DRIVER=${CACHE_DRIVER}|g" /var/www/html/.env
        fi
        if [ -n "${REDIS_HOST}" ]; then
            sed -i "s|^REDIS_HOST=.*|REDIS_HOST=${REDIS_HOST}|g" /var/www/html/.env
        fi
    fi

    # Set proper permissions
    echo "Setting file permissions..."
    chown -R www-data:www-data /var/www/html
    chmod -R 755 /var/www/html/storage
    chmod -R 755 /var/www/html/bootstrap/cache

    # Generate key and run installation
    cd /var/www/html/
    php artisan key:generate --force

    # Run installation if database is configured
    if [ -n "${DB_HOST}" ] && [ -n "${DB_DATABASE}" ] && [ -n "${DB_USERNAME}" ]; then
        echo "Waiting for database to be ready..."
        max_retries=30
        try=0
        until php -r "new PDO('mysql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
            try=$((try+1))
            if [ $try -ge $max_retries ]; then
                echo "Database not reachable after $max_retries attempts. Continuing without database setup."
                break
            fi
            echo "Retrying database connection ($try/$max_retries)..."
            sleep 2
        done

        echo "Running TastyIgniter installation..."
        php artisan igniter:install --no-interaction

        ADMIN_EMAIL="${ADMIN_EMAIL:-operations@taconazotaqueria.com}"
        ADMIN_PASSWORD="${ADMIN_PASSWORD:-12345678abc!}"
        # Super admin is created by the El Taconazo seeder (install --no-interaction
        # does not create one; without it /admin stays on Initial Setup).
        if [ -f /opt/seed/el-taconazo.php ]; then
            echo "Seeding El Taconazo sample data (includes admin)..."
            php /opt/seed/el-taconazo.php || true
        fi
        echo "Admin login: ${ADMIN_EMAIL} / ${ADMIN_PASSWORD}"
    else
        echo "Database environment variables not set. Manual setup will be required."
    fi
fi

# Overlay local extensions (locale picker, etc.) on every start
if [ -d /opt/extensions ]; then
    echo "Installing local extensions..."
    mkdir -p /var/www/html/extensions
    cp -a /opt/extensions/. /var/www/html/extensions/
    chown -R www-data:www-data /var/www/html/extensions 2>/dev/null || true
fi

# Public URL for media uploads + attachments (logo, store photos)
if [ -f /var/www/html/public/index.php ] && [ ! -e /var/www/html/public/storage ]; then
    echo "Linking public/storage..."
    (cd /var/www/html && php artisan storage:link) || ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
fi

# Configure Apache (idempotent — always end on /var/www/html/public)
echo "Setting correct DocumentRoot..."
sed -i 's|DocumentRoot[[:space:]].*|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
echo '<Directory "/var/www/html/public">' > /etc/apache2/conf-available/public-dir.conf
echo '    AllowOverride All' >> /etc/apache2/conf-available/public-dir.conf
echo '    Require all granted' >> /etc/apache2/conf-available/public-dir.conf
echo '</Directory>' >> /etc/apache2/conf-available/public-dir.conf
a2enconf public-dir

# Persistent media on every start (idempotent; migrate if a real dir appeared)
echo "Configuring persistent media storage..."
setup_persistent_media

# Make storage directory writable during runtime
chmod -R 777 /var/www/html/storage
chmod -R 777 /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "Starting Apache..."
exec "$@"
