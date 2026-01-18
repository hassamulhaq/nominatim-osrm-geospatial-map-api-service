#!/bin/sh
set -e

# Check if Nginx is running
if ! pgrep nginx > /dev/null; then
    echo "Nginx is not running"
    exit 1
fi

# Check if PHP-FPM is running
if ! pgrep php-fpm > /dev/null; then
    echo "PHP-FPM is not running"
    exit 1
fi

# Check if the health endpoint responds
if ! curl -f http://localhost:8181/health > /dev/null 2>&1; then
    echo "Health endpoint is not responding"
    exit 1
fi

echo "Health check passed"
exit 0

# ============================================
# File: scripts/init-db.sh
# ============================================
#!/bin/bash
set -e

echo "🔧 Initializing PostgreSQL extensions for Nominatim..."

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    -- Enable required extensions
    CREATE EXTENSION IF NOT EXISTS postgis;
    CREATE EXTENSION IF NOT EXISTS hstore;
    CREATE EXTENSION IF NOT EXISTS postgis_topology;
    CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
    CREATE EXTENSION IF NOT EXISTS postgis_tiger_geocoder;

    -- Grant permissions
    GRANT ALL PRIVILEGES ON DATABASE $POSTGRES_DB TO $POSTGRES_USER;

    -- Create required schemas if they don't exist
    CREATE SCHEMA IF NOT EXISTS public;
    GRANT ALL ON SCHEMA public TO $POSTGRES_USER;
    GRANT ALL ON SCHEMA public TO public;
EOSQL

echo "✅ PostgreSQL extensions initialized successfully!"