#!/usr/bin/env bash
set -e

echo "============================================="
echo " Starting AI Image Detector LAMP Container   "
echo "============================================="

# Ensure directories exist and have proper permissions
mkdir -p /var/run/mysqld /var/lib/mysql /var/www/html/uploads
chown -R mysql:mysql /var/run/mysqld /var/lib/mysql
chown -R www-data:www-data /var/www/html/uploads
chmod 775 /var/www/html/uploads

# Initialize MySQL data directory if not already initialized
if [ ! -d "/var/lib/mysql/mysql" ]; then
    echo "[MySQL] Initializing database directory..."
    mysqld --initialize-insecure --user=mysql
fi

# Start MySQL service
echo "[MySQL] Starting MySQL daemon..."
service mysql start

# Wait for MySQL to become ready
echo "[MySQL] Waiting for MySQL service to become available..."
MAX_RETRIES=30
RETRY_COUNT=0
until mysqladmin ping --silent || [ $RETRY_COUNT -eq $MAX_RETRIES ]; do
    sleep 1
    RETRY_COUNT=$((RETRY_COUNT + 1))
done

if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
    echo "[MySQL] ERROR: MySQL failed to start in time."
    exit 1
fi
echo "[MySQL] MySQL service is ready."

# Load DB configuration with sensible defaults
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-secret_password}"
DB_NAME="${DB_NAME:-aidb}"

echo "[MySQL] Configuring user credentials and database '${DB_NAME}'..."

# Determine connection flags for root
if mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
    INIT_CMD="mysql -u root"
elif mysql -u root -p"${DB_PASS}" -e "SELECT 1;" >/dev/null 2>&1; then
    INIT_CMD="mysql -u root -p${DB_PASS}"
else
    INIT_CMD="mysql -u root"
fi

$INIT_CMD <<EOSQL || true
ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'root'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOSQL

if [ "${DB_USER}" != "root" ]; then
    mysql -u root -p"${DB_PASS}" <<EOSQL || true
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
    ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
    CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
    ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'localhost' WITH GRANT OPTION;
    GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'127.0.0.1' WITH GRANT OPTION;
    GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'%' WITH GRANT OPTION;
    FLUSH PRIVILEGES;
EOSQL
fi

# Apply migrations from schema.sql
if [ -f "/var/www/schema.sql" ]; then
    echo "[MySQL] Applying schema migrations from /var/www/schema.sql..."
    mysql -u root -p"${DB_PASS}" < /var/www/schema.sql || {
        echo "[MySQL] Note: Running schema on existing database..."
        mysql -u root -p"${DB_PASS}" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
        mysql -u root -p"${DB_PASS}" "${DB_NAME}" < /var/www/schema.sql
    }
    echo "[MySQL] Database schema applied successfully."
fi

# Pass environment variables to Apache and PHP worker processes
echo "[Apache] Exporting environment variables to /etc/apache2/envvars..."
for var in DB_HOST DB_PORT DB_USER DB_PASS DB_NAME SE_API_USER SE_API_SECRET SE_API_KEY SE_API_URL ENABLE_MOCK_FALLBACK; do
    val="${!var}"
    if [ -n "$val" ]; then
        escaped_val=$(printf '%s\n' "$val" | sed 's/\\/\\\\/g; s/"/\\"/g')
        echo "export $var=\"$escaped_val\"" >> /etc/apache2/envvars
    fi
done

# Handle graceful shutdown on SIGTERM / SIGINT
stop_services() {
    echo "Caught shutdown signal. Stopping services cleanly..."
    service apache2 stop || true
    service mysql stop || true
    exit 0
}
trap stop_services SIGTERM SIGINT

echo "[Apache] Starting Apache web server in foreground..."
apache2ctl -D FOREGROUND &
APACHE_PID=$!
wait $APACHE_PID