# 🐳 Docker Setup Guide for Nominatim API

Complete guide to run the PHP-based Nominatim geocoding API with Docker.

## 📁 Project Structure

```
project-root/
├── docker/
│   ├── nginx.conf
│   ├── default.conf
│   ├── php-fpm.conf
│   ├── start.sh
│   └── healthcheck.sh
├── scripts/
│   └── init-db.sh
├── osm-data/
│   └── greater-london-260114.osm.pbf  # Download this file
├── data/                              # Created automatically
│   ├── postgres-nominatim/
│   ├── osrm/
│   ├── redis/
│   └── nominatim-flatnode/
├── logs/                              # Created automatically
│   ├── nginx/
│   └── php-fpm/
├── index.php
├── test.php
├── Dockerfile
├── docker-compose.yml
├── .env
└── README.md
```

## 🚀 Quick Start

### 1. Create Required Files

Create all the configuration files from the artifacts above:

```bash
# Create directory structure
mkdir -p docker scripts osm-data data logs/{nginx,php-fpm}

# Create docker configuration files
# Copy content from artifact "Docker Configuration Files" into:
# - docker/nginx.conf
# - docker/default.conf
# - docker/php-fpm.conf
# - docker/start.sh
# - docker/healthcheck.sh
# - scripts/init-db.sh

# Make scripts executable
chmod +x docker/start.sh docker/healthcheck.sh scripts/init-db.sh
```

### 2. Download OSM Data

Download the London OSM data file:

```bash
cd osm-data
wget https://download.geofabrik.de/europe/great-britain/england/greater-london-latest.osm.pbf \
  -O greater-london-260114.osm.pbf
cd ..
```

### 3. Create Environment File

```bash
cp .env.example .env
# Edit .env if needed (passwords, etc.)
```

### 4. Build and Start Services

```bash
# Build the Docker image
docker-compose build

# Start PostgreSQL and wait for it to be ready
docker-compose up -d nominatim-postgres

# Wait 30 seconds for PostgreSQL to initialize
sleep 30

# Import OSM data (one-time operation, takes 15-30 minutes)
docker-compose --profile import up nominatim-import

# Start all services
docker-compose up -d
```

## 📊 Service URLs

Once running, access your services at:

| Service | URL | Description |
|---------|-----|-------------|
| **Nominatim API** | http://localhost:8181 | Geocoding API |
| **OSRM Routing** | http://localhost:5000 | Routing engine |
| **Adminer** | http://localhost:8282 | Database admin |
| **PostgreSQL** | localhost:5434 | Database (external) |
| **Redis** | localhost:6380 | Cache (external) |

## 🧪 Test Your API

### Search Query
```bash
curl "http://localhost:8181/?q=London+Bridge&limit=5"
```

### Reverse Geocoding
```bash
curl "http://localhost:8181/?lat=51.5074&lon=-0.1278"
```

### Postcode Search
```bash
curl "http://localhost:8181/?q=W2+2DS"
```

### Health Check
```bash
curl "http://localhost:8181/health"
```

### Test All Endpoints
```bash
curl "http://localhost:8181/test.php"
```

## 🔧 Common Commands

### View Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f nominatim-api
docker-compose logs -f nominatim-postgres

# Nginx logs
tail -f logs/nginx/nominatim-access.log
tail -f logs/nginx/nominatim-error.log

# PHP-FPM logs
tail -f logs/php-fpm/error.log
```

### Restart Services
```bash
# Restart all
docker-compose restart

# Restart specific service
docker-compose restart nominatim-api
```

### Stop Services
```bash
# Stop all
docker-compose down

# Stop and remove volumes (WARNING: deletes data)
docker-compose down -v
```

### Check Service Status
```bash
docker-compose ps
```

### Access Container Shell
```bash
# PHP/Nginx container
docker-compose exec nominatim-api sh

# PostgreSQL container
docker-compose exec nominatim-postgres bash
```

## 🗄️ Database Management

### Access PostgreSQL via Adminer
1. Open http://localhost:8282
2. Login with:
    - **System**: PostgreSQL
    - **Server**: nominatim-postgres
    - **Username**: nominatim
    - **Password**: nominatim_password (from .env)
    - **Database**: nominatim

### Access PostgreSQL via CLI
```bash
docker-compose exec nominatim-postgres psql -U nominatim -d nominatim
```

### Common SQL Queries
```sql
-- Check total places
SELECT COUNT(*) FROM placex;

-- Check postcodes
SELECT postcode, COUNT(*) as count 
FROM placex 
WHERE postcode IS NOT NULL 
GROUP BY postcode 
ORDER BY count DESC 
LIMIT 10;

-- Check database size
SELECT pg_size_pretty(pg_database_size('nominatim'));

-- Check table sizes
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
LIMIT 10;
```

## 🔄 Re-importing Data

If you need to re-import the OSM data:

```bash
# Stop all services
docker-compose down

# Remove PostgreSQL data
rm -rf data/postgres-nominatim/*

# Start PostgreSQL
docker-compose up -d nominatim-postgres

# Wait for it to be ready
sleep 30

# Import data again
docker-compose --profile import up nominatim-import

# Start all services
docker-compose up -d
```

## 🐛 Troubleshooting

### Issue: "Connection refused" to database
```bash
# Check if PostgreSQL is running
docker-compose ps nominatim-postgres

# Check PostgreSQL logs
docker-compose logs nominatim-postgres

# Try restarting
docker-compose restart nominatim-postgres
```

### Issue: PHP-FPM not responding
```bash
# Check PHP-FPM logs
tail -f logs/php-fpm/error.log

# Restart the API service
docker-compose restart nominatim-api
```

### Issue: Import failed
```bash
# Check import logs
docker-compose --profile import logs nominatim-import

# Ensure OSM file exists and is valid
ls -lh osm-data/greater-london-260114.osm.pbf

# Try with more resources
# Edit docker-compose.yml and increase shm_size or memory
```

### Issue: Slow queries
```bash
# Check if database has enough resources
docker stats nominatim-postgres

# Consider increasing PostgreSQL memory settings in docker-compose.yml
# - shared_buffers (recommended: 25% of RAM)
# - effective_cache_size (recommended: 50-75% of RAM)
```

## 📈 Performance Tuning

### For Production Use:

1. **Increase PHP-FPM workers** (in .env):
```bash
PHP_FPM_PM_MAX_CHILDREN=50
PHP_FPM_PM_START_SERVERS=10
```

2. **Adjust PostgreSQL settings** (in docker-compose.yml):
```yaml
command: >
  postgres
  -c shared_buffers=2GB           # 25% of available RAM
  -c effective_cache_size=8GB     # 75% of available RAM
  -c maintenance_work_mem=512MB
  -c work_mem=32MB
```

3. **Enable Nginx rate limiting** (in docker/default.conf):
```nginx
limit_req_zone $binary_remote_addr zone=nominatim:10m rate=10r/s;
limit_req zone=nominatim burst=20 nodelay;
```

4. **Add Redis caching** to index.php for frequently searched locations

## 🔒 Security Recommendations

### For Production:

1. **Change default passwords** in .env
2. **Restrict Adminer access** or remove it entirely
3. **Enable HTTPS** with a reverse proxy (nginx/traefik)
4. **Limit PostgreSQL external access** (remove port mapping)
5. **Set up firewall rules**
6. **Enable rate limiting**

## 📦 Resource Requirements

| Service | RAM | Disk | Notes |
|---------|-----|------|-------|
| PostgreSQL | 4-6 GB | 20-30 GB | For London data |
| OSRM Processing | 8-12 GB | 30-40 GB | One-time |
| OSRM Running | 2-4 GB | 5-10 GB | Persistent |
| PHP/Nginx | 512 MB | 1 GB | API server |
| Redis | 512 MB | 1 GB | Cache |
| **Total** | **15-24 GB** | **60-85 GB** | - |

## 🆘 Getting Help

If you encounter issues:

1. Check logs: `docker-compose logs -f`
2. Verify OSM data file is present and correct
3. Ensure Docker has enough resources allocated
4. Check PostgreSQL initialization: `docker-compose logs nominatim-postgres`
5. Verify all required ports are available

## 📝 Notes

- Initial import takes 15-30 minutes for London data
- OSRM processing takes 5-10 minutes
- Database size will be ~3-5 GB after import
- Keep the OSM file for future re-imports