🚀 Open-source. A comprehensive geospatial mapping API service combining Nominatim (geocoding/reverse geocoding) and
OSRM (routing) with Docker containerization. Provides a complete open-source alternative to commercial mapping services
with London-focused optimization.
✨ **Features:**

- 📍 **Nominatim** - Full geocoding & reverse geocoding (address ↔ coordinates)
- 🛣️ **OSRM** - Fast, open-source routing engine (optional)
- ⚡ **Redis** - Intelligent caching for performance
- 🗄️ **PostgreSQL + PostGIS** - Spatial database backend
- 🐳 **Docker Compose** - Easy deployment & scaling
- 🔧 **Fully customizable** - Use any OSM region data
- 🔧 **RESTful API endpoints**

🏗️ Tech Stack: Docker, PHP, Nominatim, OSRM, PostgreSQL/PostGIS, Redis, Nginx

---
- Nominatim: Geocoding "Where is this address?" port:8181
- OSRM: Routing How do I get from A to B? port:5000
```shell
curl "http://localhost:8181/?q=Buckingham Palace"
# Returns: {lat: 51.501, lon: -0.142, address: "Buckingham Palace..."}

curl "http://localhost:5001/route/v1/driving/-0.142,51.501;-0.090,51.505"
# Returns: {routes: [{distance: 4500m, duration: 600s, geometry: [...], turns: [...]}]}
```
---


## Manual Setup
```shell
┌─────────────────────────────────────────────────────┐
│         RAW OSM DATA FILE                           │
│         (greater-london-260114.osm.pbf)             │
│         ~120MB binary file                          │
└───────────────┬─────────────────────────────────────┘
                │
                │ Processed by: nominatim-venv tools
                │ Command: nominatim import
                │
                ▼
┌─────────────────────────────────────────────────────┐
│         STRUCTURED POSTGRESQL DATABASE              │
│         (tables: placex, location_road_0, etc.)     │
│         ~2-3GB with indexes                         │
│         Optimized for fast text search              │
└───────────────┬─────────────────────────────────────┘
                │
                │ Queried by: Your PHP/Node.js/Laravel
                │ SQL: SELECT * FROM placex WHERE...
                │
                ▼
┌─────────────────────────────────────────────────────┐
│         CLEAN API RESPONSE                          │
│         {"lat": 51.5074, "lon": -0.1278, ...}       │
│         Ready for your application                  │
└─────────────────────────────────────────────────────┘
```
#### Component Summary

| Component | Purpose | Frequency | Delete? |
|-----------|---------|-----------|---------|
| **nominatim-venv** | Process raw OSM → database | Once/setup | No (need for updates) |
| **PostgreSQL** | Store processed, searchable data | Always on | No (your data!) |
| **PHP API** | Query the database | Every search | No (your application) |



##### Q. can we use mysql instead of postgress?
No, you cannot use MySQL instead of PostgreSQL for Nominatim. It's technically impossible because Nominatim has a hard dependency on PostgreSQL with PostGIS extensions.
Technical Impossibilities
 PostGIS is Non-Negotiable
Nominatim requires these PostgreSQL extensions that don't exist in MySQL:
- PostGIS - Advanced geospatial functions (ST_DWithin, ST_Distance, etc.)
- PostGIS Topology - Spatial relationship calculations
- hstore - Key-value storage for OSM tags (MySQL has JSON but different syntax)
- PostgreSQL Full-Text Search - Special text indexing (tsvector, tsquery)


### Resource Requirements (London Only)
| Service | Minimum RAM | Disk Space | CPU Load |
|---------|-------------|------------|----------|
| **Nominatim (PostgreSQL)** | 4-6 GB | 20-30 GB | Medium |
| **OSRM Processing** | 8-12 GB | 30-40 GB | Heavy (hours) |
| **OSRM Running** | 2-4 GB | 5-10 GB | Medium |
| **System + Nginx/PHP** | 1-2 GB | 5 GB | Low |
| **TOTAL** | **15-24 GB** | **60-85 GB** | - |

___
___
first lets check enabled ports
```shell
grep -R "listen" /etc/nginx/sites-enabled/
/etc/nginx/sites-enabled/nominatim:    listen 8181;
/etc/nginx/sites-enabled/adminer:    listen 8282;
/etc/nginx/sites-enabled/default:	listen 80 default_server;
/etc/nginx/sites-enabled/default:	listen [::]:80 default_server;
/etc/nginx/sites-enabled/default:	# listen 443 ssl default_server;
/etc/nginx/sites-enabled/default:	# listen [::]:443 ssl default_server;
/etc/nginx/sites-enabled/default:#	listen 80;
/etc/nginx/sites-enabled/default:#	listen [::]:80; 
```

## 1. postgres install 
https://www.postgresql.org/download/linux/ubuntu/
```shell
# Automated repository configuration:
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh

# To manually configure the Apt repository, follow these steps:
sudo apt install curl ca-certificates
sudo install -d /usr/share/postgresql-common/pgdg
sudo curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc --fail https://www.postgresql.org/media/keys/ACCC4CF8.asc
. /etc/os-release

sudo sh -c "echo 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $VERSION_CODENAME-pgdg main' > /etc/apt/sources.list.d/pgdg.list"
sudo apt update

# Install PostgreSQL: (replace "18" by the version you want)
sudo apt install postgresql-18

# Start and enable PostgreSQL
sudo systemctl start postgresql
sudo systemctl enable postgresql
sudo apt install -y postgis postgresql-18-postgis-3

# Create database and user for Nominatim
sudo -u postgres psql <<EOF
CREATE USER nominatim WITH PASSWORD 'nominatim_password';
CREATE DATABASE nominatim OWNER nominatim;
\c nominatim
CREATE EXTENSION postgis;
CREATE EXTENSION hstore;
CREATE EXTENSION postgis_topology;
CREATE EXTENSION fuzzystrmatch;
CREATE EXTENSION postgis_tiger_geocoder;
EOF
```

## 2. Install Nominatim
#### Install dependencies
```shell
sudo apt update
sudo apt install -y cmake g++ libboost-dev libboost-system-dev \
  libboost-filesystem-dev libexpat1-dev zlib1g-dev libbz2-dev \
  libpq-dev libproj-dev lua5.3 liblua5.3-dev libluabind-dev \
  nginx php-fpm php-intl php-pgsql php-curl \
  python3-pip python3-psycopg2 python3-psutil python3-jinja2 \
  python3-setuptools python3-dev

sudo apt install -y \
    git \
    postgresql-16 postgresql-16-postgis-3 \
    postgresql-contrib-16 \
    osm2pgsql \
    python3-pip python3-psycopg2 python3-setuptools \
    python3-dev python3-venv \
    build-essential cmake \
    libboost-dev libboost-system-dev libboost-filesystem-dev \
    libexpat1-dev zlib1g-dev libbz2-dev \
    libpq-dev libproj-dev lua5.3 liblua5.3-dev \
    nginx php8.2-fpm php8.2 php8.2-pgsql php8.2-xml \
    curl wget
```

#### Create database and user
```shell
-- 1. create role
CREATE USER nominatim WITH PASSWORD 'nominatim_password';

-- 2. create DB and make nominatim its owner
CREATE DATABASE nominatim OWNER nominatim;

-- 3. connect to the new DB (run the remaining commands inside it)
\c nominatim

-- 4. install required extensions
CREATE EXTENSION postgis;
CREATE EXTENSION hstore;
CREATE EXTENSION postgis_topology;
CREATE EXTENSION postgis_raster;

# If you prefer a single shell snippet:
sudo -u postgres psql -c "CREATE USER nominatim WITH PASSWORD 'nominatim_password';"
sudo -u postgres psql -c "CREATE DATABASE nominatim OWNER nominatim;"
sudo -u postgres psql -d nominatim -c "CREATE EXTENSION postgis; CREATE EXTENSION hstore; CREATE EXTENSION postgis_topology; CREATE EXTENSION postgis_raster;"
```

### Tune PostgreSQL for Nominatim
```shell
# apply the changes
sudo -u postgres psql -d nominatim \
  -c "ALTER SYSTEM SET shared_buffers          = '1GB';" \
  -c "ALTER SYSTEM SET maintenance_work_mem    = '256MB';" \
  -c "ALTER SYSTEM SET work_mem                = '16MB';" \
  -c "ALTER SYSTEM SET effective_cache_size    = '4GB';" \
  -c "ALTER SYSTEM SET fsync                   = off;" \
  -c "ALTER SYSTEM SET full_page_writes        = off;"

# reload configuration (or simply restart the cluster)
sudo systemctl restart postgresql
```

### Clone and Setup Nominatim (Following osm-search README)
#### Clone the repository
`cd /var/www/html`
```shell
sudo git clone https://github.com/osm-search/Nominatim.git nominatim
cd nominatim
```

#### Download the country grid (important!)
```shell
sudo wget -O data/country_osm_grid.sql.gz \
    https://nominatim.org/data/country_grid.sql.gz
```

#### Create Python virtual environment
```shell
python3 -m venv venv
source venv/bin/activate
```

#### Install Nominatim packages (if permission error for below 3 cmds, then `sudo chown -R $USER:$USER nominatim`)
```shell
pip install --upgrade pip
pip install ./packaging/nominatim-api
pip install ./packaging/nominatim-db
```

#### Create project directory
```shell
mkdir -p /var/www/html/nominatim-project
cd /var/www/html/nominatim-project
```

#### Copy your OSM data
`cp /path/to/your/greater-london-260114.osm.pbf ./`

#### Or download London data
```shell
wget -O greater-london-260114.osm.pbf \
    https://download.geofabrik.de/europe/great-britain/england/greater-london-260114.osm.pbf
``` 
#### Configure Environment Variables
##### Create .env file
```shell
# 1. make sure the directory exists
sudo mkdir -p /var/www/html/nominatim-project

# 2. create the file line-by-line
echo 'NOMINATIM_DATABASE_DSN="pgsql:dbname=nominatim;host=localhost"'     >  .env
echo 'NOMINATIM_DATABASE_USER="nominatim"'                                >> .env
echo 'NOMINATIM_DATABASE_PASSWORD="nominatim_password"'                   >> .env
echo 'NOMINATIM_IMPORT_STYLE=admin'                                       >> .env
echo 'NOMINATIM_IMPORT_WIKIPEDIA=false'                                   >> .env
echo 'NOMINATIM_FLATNODE_FILE=/var/www/html/nominatim-project/flatnode.file' >> .env
```

#### Source the environment
```shell
set -a
source .env
set +a
```

#### Create Linux User (Optional but recommended)
```shell
sudo useradd -m -s /bin/bash nominatim
sudo passwd nominatim  # Set a password (P@$$w0rd!)
sudo usermod -aG sudo nominatim  # Add to sudo group
```

#### Set ownership
```shell
sudo chown -R nominatim:nominatim /var/www/html/nominatim-project
```

#### Switch to nominatim user or run as current user with sudo
Option A: As nominatim user
```shell
sudo -u nominatim -i
cd /var/www/html/nominatim-project
source ../nominatim/venv/bin/activate
nominatim import --osm-file greater-london-260114.osm.pbf 2>&1 | tee setup.log
```

#### Option B: As current user with sudo
```shell
cd /var/www/html/nominatim-project
source ../nominatim/venv/bin/activate
sudo -u postgres nominatim import --osm-file greater-london-260114.osm.pbf 2>&1 | tee setup.log
```

## 3. Setup PHP API (Simpler Nginx Setup)
#### Create minimal PHP API endpoint
```shell
mkdir -p /var/www/html/nominatim-api
cd /var/www/html/nominatim-api
```

#### Create the correct PHP API for your Nominatim database
`sudo nano index.php`
<details>
<summary>Click to expand the full PHP "index.php"</summary>

```php
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// Database connection
$dsn = 'pgsql:dbname=nominatim;host=localhost';
$user = 'nominatim';
$password = 'nominatim_password';

try {
    $db = new PDO($dsn, $user, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Parse request
    $request_uri = $_SERVER['REQUEST_URI'];
    $query_string = $_SERVER['QUERY_STRING'];
    parse_str($query_string, $params);
    
    // Determine endpoint
    if (isset($params['q'])) {
        handleSearch($db, $params);
    } elseif (isset($params['lat'], $params['lon'])) {
        handleReverse($db, $params);
    } elseif ($request_uri === '/health' || $request_uri === '/health/') {
        handleHealth();
    } else {
        echo json_encode([
            'usage' => [
                'search' => '/?q=W2 2DS or London Bridge',
                'reverse' => '/?lat=51.5074&lon=-0.1278',
                'health' => '/health'
            ]
        ], JSON_PRETTY_PRINT);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

function handleSearch($db, $params) {
    $q = trim($params['q'] ?? '');
    $limit = min((int)($params['limit'] ?? 10), 50);
    
    if (empty($q)) {
        echo json_encode(['error' => 'Missing query parameter "q"']);
        return;
    }
    
    // Clean the query - remove UK, UK postcode formatting
    $cleanQuery = strtoupper(preg_replace('/[^A-Z0-9 ]/i', '', $q));
    $cleanQuery = trim($cleanQuery);
    
    // Check if it looks like a postcode (UK postcode pattern)
    $isPostcode = preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/i', $q);
    
    if ($isPostcode) {
        // Search specifically for postcodes
        searchPostcode($db, $q, $limit);
    } else {
        // General search
        searchGeneral($db, $q, $limit);
    }
}

function searchPostcode($db, $postcode, $limit) {
    // Clean postcode for searching
    $cleanPostcode = strtoupper(preg_replace('/\s+/', '', $postcode));
    $postcodeWithSpace = substr($cleanPostcode, 0, -3) . ' ' . substr($cleanPostcode, -3);
    
    // Multiple ways to search for postcode
    $sql = "
        SELECT 
            place_id,
            parent_place_id,
            osm_type,
            osm_id,
            class,
            type,
            admin_level,
            name,
            address,
            extratags,
            ST_X(centroid) AS lon,
            ST_Y(centroid) AS lat,
            importance,
            rank_search,
            rank_address,
            country_code,
            housenumber,
            postcode,
            wikipedia
        FROM placex 
        WHERE (
            postcode ILIKE :postcode1 OR 
            postcode ILIKE :postcode2 OR
            address::text ILIKE :postcode3 OR
            address::text ILIKE :postcode4
        )
        ORDER BY 
            CASE 
                WHEN postcode ILIKE :postcode1 THEN 1
                WHEN postcode ILIKE :postcode2 THEN 2
                WHEN class = 'boundary' AND type = 'postal_code' THEN 3
                ELSE 4
            END,
            importance DESC
        LIMIT :limit
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':postcode1', "%$cleanPostcode%", PDO::PARAM_STR);
    $stmt->bindValue(':postcode2', "%$postcodeWithSpace%", PDO::PARAM_STR);
    $stmt->bindValue(':postcode3', "%$cleanPostcode%", PDO::PARAM_STR);
    $stmt->bindValue(':postcode4', "%$postcodeWithSpace%", PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        // Fallback to general search if no direct postcode match
        searchGeneral($db, $postcode, $limit);
        return;
    }
    
    $formattedResults = array_map(function($row) {
        return formatPlaceResult($row);
    }, $results);
    
    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function searchGeneral($db, $query, $limit) {
    // Multiple search strategies
    $sql = "
        SELECT 
            place_id,
            parent_place_id,
            osm_type,
            osm_id,
            class,
            type,
            admin_level,
            name,
            address,
            extratags,
            ST_X(centroid) AS lon,
            ST_Y(centroid) AS lat,
            importance,
            rank_search,
            rank_address,
            country_code,
            housenumber,
            postcode,
            wikipedia
        FROM placex 
        WHERE (
            name ILIKE :query OR
            address::text ILIKE :query OR
            housenumber ILIKE :query OR
            postcode ILIKE :query OR
            (class = 'highway' AND name ILIKE :query) OR
            (class = 'place' AND name ILIKE :query)
        )
        ORDER BY 
            CASE 
                WHEN name ILIKE :query_exact THEN 1
                WHEN postcode ILIKE :query THEN 2
                WHEN class IN ('place', 'boundary') THEN 3
                ELSE 4
            END,
            importance DESC,
            rank_search ASC
        LIMIT :limit
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':query', "%$query%", PDO::PARAM_STR);
    $stmt->bindValue(':query_exact', "$query", PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedResults = array_map(function($row) {
        return formatPlaceResult($row);
    }, $results);
    
    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function handleReverse($db, $params) {
    $lat = (float)($params['lat'] ?? 0);
    $lon = (float)($params['lon'] ?? 0);
    
    if ($lat === 0.0 || $lon === 0.0) {
        echo json_encode(['error' => 'Missing or invalid lat/lon parameters']);
        return;
    }
    
    $radius = 100; // meters
    
    $sql = "
        SELECT 
            place_id,
            parent_place_id,
            osm_type,
            osm_id,
            class,
            type,
            admin_level,
            name,
            address,
            extratags,
            ST_X(centroid) AS lon,
            ST_Y(centroid) AS lat,
            importance,
            rank_search,
            rank_address,
            country_code,
            housenumber,
            postcode,
            wikipedia,
            ST_Distance(
                ST_SetSRID(ST_Point(:lon, :lat), 4326)::geography,
                centroid::geography
            ) AS distance
        FROM placex 
        WHERE ST_DWithin(
            ST_SetSRID(ST_Point(:lon, :lat), 4326)::geography,
            centroid::geography,
            :radius
        )
        ORDER BY 
            CASE 
                WHEN class IN ('building', 'amenity', 'shop') THEN 1
                WHEN class = 'highway' THEN 2
                ELSE 3
            END,
            distance ASC
        LIMIT 5
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lat', $lat, PDO::PARAM_STR);
    $stmt->bindValue(':lon', $lon, PDO::PARAM_STR);
    $stmt->bindValue(':radius', $radius, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedResults = array_map(function($row) {
        $result = formatPlaceResult($row);
        $result['distance'] = (float)$row['distance'];
        return $result;
    }, $results);
    
    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function handleHealth() {
    echo json_encode([
        'status' => 'OK',
        'timestamp' => date('c'),
        'service' => 'Nominatim API'
    ]);
}

function formatPlaceResult($row) {
    $address = parseHstore($row['address'] ?? '');
    $name = parseHstore($row['name'] ?? '');
    
    // Build display name
    $displayParts = [];
    
    // Add house number + street if available
    if (!empty($row['housenumber']) && isset($address['street'])) {
        $displayParts[] = $row['housenumber'] . ' ' . $address['street'];
    } elseif (isset($address['street'])) {
        $displayParts[] = $address['street'];
    }
    
    // Add locality
    $localityFields = ['suburb', 'village', 'town', 'city', 'locality'];
    foreach ($localityFields as $field) {
        if (isset($address[$field]) && !in_array($address[$field], $displayParts)) {
            $displayParts[] = $address[$field];
            break;
        }
    }
    
    // Add postcode
    if (!empty($row['postcode'])) {
        $displayParts[] = $row['postcode'];
    }
    
    // Add country if not UK (default)
    if (isset($address['country']) && $address['country'] !== 'GB' && $address['country'] !== 'United Kingdom') {
        $displayParts[] = $address['country'];
    } elseif (isset($address['country_code']) && $address['country_code'] !== 'gb') {
        $displayParts[] = strtoupper($address['country_code']);
    } else {
        $displayParts[] = 'UK';
    }
    
    $displayName = implode(', ', $displayParts);
    
    // Get primary name
    $primaryName = $name['name'] ?? $name['name:en'] ?? 
                  (isset($address['street']) ? $address['street'] : 
                  (isset($address['city']) ? $address['city'] : 
                  ($displayParts[0] ?? '')));
    
    return [
        'place_id' => (int)$row['place_id'],
        'licence' => 'Data © OpenStreetMap contributors, ODbL 1.0. https://osm.org/copyright',
        'osm_type' => $row['osm_type'],
        'osm_id' => (int)$row['osm_id'],
        'lat' => (float)$row['lat'],
        'lon' => (float)$row['lon'],
        'class' => $row['class'],
        'type' => $row['type'],
        'importance' => (float)$row['importance'],
        'name' => $primaryName,
        'display_name' => $displayName,
        'address' => $address,
        'postcode' => $row['postcode'] ?? null,
        'housenumber' => $row['housenumber'] ?? null,
        'country_code' => $row['country_code'] ?? null
    ];
}

function parseHstore($hstoreString) {
    if (empty($hstoreString) || $hstoreString === '""') {
        return [];
    }
    
    $result = [];
    $hstoreString = trim($hstoreString, '"');
    
    // Simple regex parsing for hstore
    $pattern = '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"\s*=>\s*"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/';
    preg_match_all($pattern, $hstoreString, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $key = stripcslashes($match[1]);
        $value = stripcslashes($match[2]);
        
        if (!empty($value)) {
            $result[$key] = $value;
        }
    }
    
    return $result;
}
?>
```
</details>

#### Also create a simple test endpoint
`sudo nano test.php`
```php
<?php
header('Content-Type: application/json');
$test_queries = [
    'search' => 'q=London&limit=3',
    'reverse' => 'lat=51.5074&lon=-0.1278',
    'health' => 'health'
];
$base_url = 'http://' . $_SERVER['HTTP_HOST'];
$results = [];
foreach ($test_queries as $name => $query) {
    $url = $base_url . '/?' . $query;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $results[$name] = [
        'url' => $url,
        'status' => $http_code,
        'response' => json_decode($response, true)
    ];
}
echo json_encode($results, JSON_PRETTY_PRINT);
```

#### Set correct permissions
```php
sudo chmod 755 /var/www/html/nominatim-api
sudo chmod 644 /var/www/html/nominatim-api/*.php
sudo systemctl reload nginx
```

#### Configure Nginx (Simplified)
```apacheconf
server {
    listen 8181;
    server_name localhost;
    
    root /var/www/html/nominatim-api;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Timeouts for large queries
        fastcgi_read_timeout 300s;
        fastcgi_send_timeout 300s;
    }
    
    # Rate limiting
#    limit_req_zone $binary_remote_addr zone=nominatim:10m rate=10r/s;
#    limit_req zone=nominatim burst=20 nodelay;
    
    # Health check
    location /health {
        access_log off;
        return 200 "OK\n";
        add_header Content-Type text/plain;
    }
    
    # Status endpoint
    location /status {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

```shell
sudo ln -sf /etc/nginx/sites-available/nominatim /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

```text
# test API
# Test search
curl "http://localhost:8181/search?q=london&format=json"
# Test reverse geocoding
curl "http://localhost:8181/reverse?lat=51.5074&lon=-0.1278&format=json"
# Test health
curl "http://localhost:8181/health"
```

##### if error
```textmate
http://localhost:8181/reverse?lat=51.5074&lon=-0.1278&format=json
{
"error": "Database error: SQLSTATE[42703]: Undefined column: 7 ERROR:  column \"lat\" does not exist\nLINE 6:             lat AS latitude,\n                    ^"
}
# Alternative: Check Actual Database Schema
# Check the actual schema of placex table
sudo -u postgres psql -d nominatim -c "\d placex" | head -30
# Or check specific columns
sudo -u postgres psql -d nominatim -c "
SELECT column_name, data_type
FROM information_schema.columns
WHERE table_name = 'placex'
AND column_name IN ('lat', 'lon', 'latitude', 'longitude', 'centroid')
ORDER BY column_name;"

# Check what tables exist
sudo -u postgres psql -d nominatim -c "\dt"
# Check sample data
sudo -u postgres psql -d nominatim -c "
SELECT * FROM placex LIMIT 1;"
```

### Alternative: Use Nominatim's Built-in PHP API (or use Nodejs etc project)
#### Instead of creating index.php, use Nominatim's built-in setup
`cd /var/www/html/nominatim-project`
Create configuration file
```shell
tee config/settings.php <<'EOF'
<?php
    @define('CONST_Database_DSN', 'pgsql:dbname=nominatim;host=localhost');
    @define('CONST_Database_User', 'nominatim');
    @define('CONST_Database_Password', 'nominatim_password');
    @define('CONST_Website_BaseURL', 'http://localhost:8181/');
?>
EOF
```
Copy website files from the Nominatim repo (TODO: it not worked. will fix later)
`cp -r ../nominatim/website/* ./`
`cp -r ../nominatim/lib ./`
Then point Nginx to this directory

Test data
```shell
sudo -u postgres psql -d nominatim -c "
SELECT
postcode,
COUNT(*) as count
FROM placex
WHERE postcode IS NOT NULL
AND postcode != ''
GROUP BY postcode
ORDER BY count DESC
LIMIT 10;"
```
```text
postcode | count
----------+-------
TW6 2GA  |   709
W12 0BP  |   554
TW6 1QG  |   480
SM4 6HY  |   422
W12 0QT  |   420
SM4 6RT  |   350
W12 0BS  |   342
TW12 2PD |   317
N8 0HJ   |   313
W12 7JA  |   307
(10 rows)
```

### Configure Nginx for Nominatim
Install Nginx if not already installed
`sudo apt install -y nginx`
##### Configure PHP-FPM for Nominatim
```shell
sudo tee /etc/php/8.2/fpm/pool.d/nominatim.conf <<EOF
[nominatim]
user = nominatim
group = nominatim
listen = /run/php/php8.2-fpm-nominatim.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF
```

#### Also check if PHP-FPM socket exists
sudo ls -la /run/php/php8.2-fpm-nominatim.sock
sudo systemctl restart php8.2-fpm

##### Let me provide you with the correct PHP-FPM setup for Nominatim:
##### First, create the correct PHP-FPM pool configuration
```shell
sudo tee /etc/php/8.2/fpm/pool.d/nominatim.conf <<EOF
[nominatim]
user = nominatim
group = nominatim
listen = /run/php/php8.2-fpm-nominatim.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 20
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 10M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_time] = 300
EOF
```

## 4. Install OSRM (continued with Nginx) [OPTIONAL]
`sudo nano /etc/nginx/sites-enabled/osrm`
```shell
server {
listen 5001;
server_name localhost;
    # OSRM API
    location / {
        proxy_pass http://localhost:5000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
    # Health check
    location /health {
        proxy_pass http://localhost:5000/route/v1/driving/-0.1278,51.5074;-0.0900,51.5050?overview=false;
        access_log off;
    }
    # Rate limiting
    limit_req_zone $binary_remote_addr zone=osrm:10m rate=10r/s;
    limit_req zone=osrm burst=20 nodelay;
}
```

## 5. Adminer
```shell
sudo apt install -y adminer
sudo nano /etc/nginx/sites-available/adminer
```

```shell
server {
listen 8282;
server_name localhost;
root /usr/share/adminer;
index index.php;

    location / {
        try_files $uri $uri/ =404;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    # Security - restrict access
    allow 127.0.0.1;
    allow ::1;
    deny all;
    # Add authentication (optional)
    auth_basic "Adminer Restricted";
    auth_basic_user_file /etc/nginx/.htpasswd;
}
```