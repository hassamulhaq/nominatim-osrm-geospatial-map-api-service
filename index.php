<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// Database connection from environment variables
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'nominatim';
$dbUser = getenv('DB_USER') ?: 'nominatim';
$dbPassword = getenv('DB_PASSWORD') ?: 'nominatim_password';

$dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";

try {
    $db = new PDO($dsn, $dbUser, $dbPassword);
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
    } elseif (strpos($request_uri, '/health') !== false) {
        handleHealth($db);
    } elseif (strpos($request_uri, '/status') !== false) {
        handleStatus($db);
    } else {
        echo json_encode([
            'service' => 'Nominatim Geocoding API',
            'version' => '1.0.0',
            'endpoints' => [
                'search' => [
                    'url' => '/?q=W2+2DS or London Bridge',
                    'params' => [
                        'q' => 'Search query (address, postcode, place name)',
                        'limit' => 'Number of results (default: 10, max: 50)'
                    ]
                ],
                'reverse' => [
                    'url' => '/?lat=51.5074&lon=-0.1278',
                    'params' => [
                        'lat' => 'Latitude',
                        'lon' => 'Longitude'
                    ]
                ],
                'health' => '/health',
                'status' => '/status'
            ],
            'database' => [
                'host' => $dbHost,
                'database' => $dbName,
                'connected' => true
            ]
        ], JSON_PRETTY_PRINT);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection error',
        'message' => $e->getMessage(),
        'database' => [
            'host' => $dbHost,
            'database' => $dbName
        ]
    ]);
}

function handleSearch($db, $params)
{
    $q = trim($params['q'] ?? '');
    $limit = min((int)($params['limit'] ?? 100), 120);

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

function searchPostcode($db, $postcode, $limit)
{
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

    $formattedResults = array_map(function ($row) {
        return formatPlaceResult($row);
    }, $results);

    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function searchGeneral($db, $query, $limit)
{
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

    $formattedResults = array_map(function ($row) {
        return formatPlaceResult($row);
    }, $results);

    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function handleReverse($db, $params)
{
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

    $formattedResults = array_map(function ($row) {
        $result = formatPlaceResult($row);
        $result['distance'] = (float)$row['distance'];
        return $result;
    }, $results);

    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function handleHealth($db)
{
    try {
        // Check database connection
        $stmt = $db->query('SELECT 1');
        $dbStatus = 'connected';

        // Check if data exists
        $stmt = $db->query('SELECT COUNT(*) as count FROM placex LIMIT 1');
        $hasData = $stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;

        echo json_encode([
            'status' => 'OK',
            'timestamp' => date('c'),
            'service' => 'Nominatim API',
            'database' => [
                'status' => $dbStatus,
                'has_data' => $hasData
            ],
            'version' => '1.0.0'
        ]);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'ERROR',
            'timestamp' => date('c'),
            'service' => 'Nominatim API',
            'error' => $e->getMessage()
        ]);
    }
}

function handleStatus($db)
{
    try {
        // Get database statistics
        $stmt = $db->query("
            SELECT 
                (SELECT COUNT(*) FROM placex) as total_places,
                (SELECT COUNT(DISTINCT postcode) FROM placex WHERE postcode IS NOT NULL) as unique_postcodes,
                (SELECT COUNT(*) FROM placex WHERE class = 'place') as places,
                (SELECT COUNT(*) FROM placex WHERE class = 'highway') as highways,
                (SELECT COUNT(*) FROM placex WHERE class = 'building') as buildings
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get database size
        $stmt = $db->query("SELECT pg_size_pretty(pg_database_size(current_database())) as db_size");
        $size = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'OK',
            'timestamp' => date('c'),
            'service' => 'Nominatim API',
            'database' => [
                'connected' => true,
                'size' => $size['db_size'],
                'statistics' => $stats
            ],
            'version' => '1.0.0'
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'ERROR',
            'timestamp' => date('c'),
            'service' => 'Nominatim API',
            'error' => $e->getMessage()
        ]);
    }
}

function formatPlaceResult($row)
{
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

function parseHstore($hstoreString)
{
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