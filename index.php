<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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
        showApiInfo($dbHost, $dbName);
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
    ], JSON_PRETTY_PRINT);
}

function showApiInfo($dbHost, $dbName)
{
    echo json_encode([
        'service' => 'Enhanced Nominatim Geocoding API',
        'version' => '2.0.0',
        'endpoints' => [
            'search' => [
                'url' => '/?q=W2+2DS or London Bridge',
                'params' => [
                    'q' => 'Search query (address, postcode, place name)',
                    'limit' => 'Number of results (default: 100, max: 120)',
                    'countrycodes' => 'Limit results to specific countries (e.g., gb,us)',
                    'bounded' => '1 to restrict to viewbox',
                    'viewbox' => 'Bounding box: lon1,lat1,lon2,lat2'
                ]
            ],
            'reverse' => [
                'url' => '/?lat=51.5074&lon=-0.1278',
                'params' => [
                    'lat' => 'Latitude',
                    'lon' => 'Longitude',
                    'zoom' => 'Detail level (default: 18)'
                ]
            ],
            'health' => '/health',
            'status' => '/status'
        ],
        'features' => [
            'Smart postcode detection',
            'Fuzzy matching',
            'Multi-language support',
            'Address hierarchy',
            'Distance-based ranking',
            'Relevance scoring'
        ],
        'database' => [
            'host' => $dbHost,
            'database' => $dbName,
            'connected' => true
        ]
    ], JSON_PRETTY_PRINT);
}

function handleSearch($db, $params)
{
    $q = trim($params['q'] ?? '');
    $limit = min((int)($params['limit'] ?? 100), 120);
    $countryCodes = isset($params['countrycodes']) ? explode(',', strtolower($params['countrycodes'])) : [];

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
        searchPostcode($db, $q, $limit, $countryCodes);
    } else {
        // Check for other query types
        $queryType = detectQueryType($q);

        switch ($queryType) {
            case 'coordinates':
                searchCoordinates($db, $q, $limit);
                break;
            case 'address':
                searchAddress($db, $q, $limit, $countryCodes);
                break;
            default:
                searchGeneral($db, $q, $limit, $countryCodes);
        }
    }
}

function detectQueryType($query)
{
    // Coordinates pattern (e.g., 51.5074,-0.1278)
    if (preg_match('/^-?\d+\.?\d*,\s*-?\d+\.?\d*$/', $query)) {
        return 'coordinates';
    }

    // Address with house number (e.g., 123 Main Street)
    if (preg_match('/^\d+\s+[A-Za-z]/', $query)) {
        return 'address';
    }

    return 'general';
}

function searchPostcode($db, $postcode, $limit, $countryCodes)
{
    // Clean postcode for searching
    $cleanPostcode = strtoupper(preg_replace('/\s+/', '', $postcode));
    $postcodeWithSpace = substr($cleanPostcode, 0, -3) . ' ' . substr($cleanPostcode, -3);

    // Build country filter
    $countryFilter = buildCountryFilter($countryCodes);

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
            wikipedia,
            CASE 
                WHEN postcode ILIKE :postcode1 THEN 1
                WHEN postcode ILIKE :postcode2 THEN 2
                WHEN class = 'boundary' AND type = 'postal_code' THEN 3
                ELSE 4
            END as match_quality
        FROM placex 
        WHERE (
            postcode ILIKE :postcode1 OR 
            postcode ILIKE :postcode2 OR
            address::text ILIKE :postcode3 OR
            address::text ILIKE :postcode4
        )
        {$countryFilter['sql']}
        ORDER BY 
            match_quality ASC,
            importance DESC,
            rank_search ASC
        LIMIT :limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':postcode1', "%$cleanPostcode%", PDO::PARAM_STR);
    $stmt->bindValue(':postcode2', "%$postcodeWithSpace%", PDO::PARAM_STR);
    $stmt->bindValue(':postcode3', "%$cleanPostcode%", PDO::PARAM_STR);
    $stmt->bindValue(':postcode4', "%$postcodeWithSpace%", PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($countryFilter['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->execute();
    $results = $stmt->fetchAll();

    if (empty($results)) {
        // Fallback to general search if no direct postcode match
        searchGeneral($db, $postcode, $limit, $countryCodes);
        return;
    }

    outputResults($results);
}

function searchCoordinates($db, $query, $limit)
{
    list($lat, $lon) = explode(',', $query);
    $lat = (float)trim($lat);
    $lon = (float)trim($lon);

    handleReverse($db, ['lat' => $lat, 'lon' => $lon, 'limit' => $limit]);
}

function searchAddress($db, $query, $limit, $countryCodes)
{
    // Extract house number
    preg_match('/^(\d+[A-Z]?)\s+(.+)$/', $query, $matches);
    $housenumber = $matches[1] ?? '';
    $street = $matches[2] ?? $query;

    $countryFilter = buildCountryFilter($countryCodes);

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
            CASE
                WHEN housenumber ILIKE :housenumber AND address::text ILIKE :street THEN 1
                WHEN housenumber ILIKE :housenumber THEN 2
                WHEN address::text ILIKE :street THEN 3
                ELSE 4
            END as relevance_score
        FROM placex 
        WHERE (
            (housenumber ILIKE :housenumber AND address::text ILIKE :street) OR
            (housenumber ILIKE :housenumber) OR
            (address::text ILIKE :full_query)
        )
        {$countryFilter['sql']}
        ORDER BY 
            relevance_score ASC,
            importance DESC,
            rank_address ASC
        LIMIT :limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':housenumber', "%$housenumber%", PDO::PARAM_STR);
    $stmt->bindValue(':street', "%$street%", PDO::PARAM_STR);
    $stmt->bindValue(':full_query', "%$query%", PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($countryFilter['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->execute();
    $results = $stmt->fetchAll();

    if (empty($results)) {
        searchGeneral($db, $query, $limit, $countryCodes);
        return;
    }

    outputResults($results);
}

function searchGeneral($db, $query, $limit, $countryCodes)
{
    $countryFilter = buildCountryFilter($countryCodes);

    // Escape special characters in query for ILIKE
    $searchQuery = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

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
            indexed_date,
            CASE 
                -- Exact name match (highest priority) - using -> not ->> for PostgreSQL 18 compatibility
                WHEN LOWER((name->'name')::text) = LOWER(:exact_query) THEN 1
                WHEN LOWER((name->'name:en')::text) = LOWER(:exact_query) THEN 2
                -- Name starts with query
                WHEN LOWER((name->'name')::text) LIKE LOWER(:starts_query) THEN 3
                -- Address exact match
                WHEN address::text ILIKE :exact_addr THEN 4
                -- Postcode exact match
                WHEN postcode ILIKE :exact_query THEN 5
                -- Name contains query
                WHEN (name->'name')::text ILIKE :search_query THEN 6
                -- Address contains query
                WHEN address::text ILIKE :search_query THEN 7
                -- Extratags match
                WHEN extratags::text ILIKE :search_query THEN 8
                ELSE 9
            END as match_quality
        FROM placex 
        WHERE (
            (name->'name')::text ILIKE :search_query OR
            (name->'name:en')::text ILIKE :search_query OR
            address::text ILIKE :search_query OR
            housenumber ILIKE :search_query OR
            postcode ILIKE :search_query OR
            extratags::text ILIKE :search_query
        )
        {$countryFilter['sql']}
        AND rank_search < 30
        ORDER BY 
            match_quality ASC,
            importance DESC,
            rank_search ASC
        LIMIT :limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':exact_query', $query, PDO::PARAM_STR);
    $stmt->bindValue(':starts_query', $query . '%', PDO::PARAM_STR);
    $stmt->bindValue(':exact_addr', '%"' . $query . '"%', PDO::PARAM_STR);
    $stmt->bindValue(':search_query', $searchQuery, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($countryFilter['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->execute();
    $results = $stmt->fetchAll();

    outputResults($results);
}

function buildCountryFilter($countryCodes)
{
    if (empty($countryCodes)) {
        return ['sql' => '', 'params' => []];
    }

    $placeholders = [];
    $params = [];
    foreach ($countryCodes as $i => $code) {
        $key = ":country$i";
        $placeholders[] = $key;
        $params[$key] = strtolower($code);
    }

    return [
        'sql' => 'AND country_code IN (' . implode(',', $placeholders) . ')',
        'params' => $params
    ];
}

function handleReverse($db, $params)
{
    $lat = (float)($params['lat'] ?? 0);
    $lon = (float)($params['lon'] ?? 0);
    $zoom = (int)($params['zoom'] ?? 18);
    $limit = (int)($params['limit'] ?? 5);

    if ($lat === 0.0 || $lon === 0.0) {
        echo json_encode(['error' => 'Missing or invalid lat/lon parameters']);
        return;
    }

    // Adjust search radius based on zoom level
    $radius = match (true) {
        $zoom >= 18 => 50,      // Street level
        $zoom >= 16 => 100,     // Block level
        $zoom >= 14 => 500,     // Neighborhood
        $zoom >= 12 => 1000,    // District
        default => 5000         // City level
    };

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
        AND rank_address > 0
        ORDER BY 
            CASE 
                WHEN class = 'building' AND type IN ('house', 'residential', 'apartments') THEN 1
                WHEN class IN ('amenity', 'shop', 'tourism', 'leisure') THEN 2
                WHEN class = 'highway' AND type IN ('residential', 'service', 'road') THEN 3
                WHEN class = 'place' THEN 4
                WHEN class = 'boundary' THEN 5
                ELSE 6
            END,
            distance ASC,
            importance DESC
        LIMIT :limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lat', $lat, PDO::PARAM_STR);
    $stmt->bindValue(':lon', $lon, PDO::PARAM_STR);
    $stmt->bindValue(':radius', $radius, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $results = $stmt->fetchAll();

    if (empty($results)) {
        echo json_encode([
            'error' => 'No results found',
            'lat' => $lat,
            'lon' => $lon,
            'radius' => $radius
        ]);
        return;
    }

    $formattedResults = array_map(function ($row) {
        $result = formatPlaceResult($row);
        $result['distance'] = round((float)$row['distance'], 2);
        return $result;
    }, $results);

    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function outputResults($results)
{
    if (empty($results)) {
        echo json_encode([]);
        return;
    }

    $formattedResults = array_map(function ($row) {
        return formatPlaceResult($row);
    }, $results);

    echo json_encode($formattedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function handleHealth($db)
{
    try {
        $stmt = $db->query('SELECT 1');
        $dbStatus = 'connected';

        $stmt = $db->query('SELECT COUNT(*) as count FROM placex LIMIT 1');
        $hasData = $stmt->fetch()['count'] > 0;

        // Check index health
        $stmt = $db->query("
            SELECT schemaname, tablename, indexname 
            FROM pg_indexes 
            WHERE tablename = 'placex' 
            LIMIT 5
        ");
        $indexes = $stmt->fetchAll();

        echo json_encode([
            'status' => 'OK',
            'timestamp' => date('c'),
            'service' => 'Enhanced Nominatim API',
            'database' => [
                'status' => $dbStatus,
                'has_data' => $hasData,
                'indexes_count' => count($indexes)
            ],
            'version' => '2.0.0',
            'features' => [
                'Smart query detection',
                'Fuzzy matching',
                'Relevance ranking',
                'Multi-country support'
            ]
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'ERROR',
            'timestamp' => date('c'),
            'service' => 'Enhanced Nominatim API',
            'error' => $e->getMessage()
        ]);
    }
}

function handleStatus($db)
{
    try {
        $stmt = $db->query("
            SELECT 
                (SELECT COUNT(*) FROM placex) as total_places,
                (SELECT COUNT(DISTINCT postcode) FROM placex WHERE postcode IS NOT NULL) as unique_postcodes,
                (SELECT COUNT(*) FROM placex WHERE class = 'place') as places,
                (SELECT COUNT(*) FROM placex WHERE class = 'highway') as highways,
                (SELECT COUNT(*) FROM placex WHERE class = 'building') as buildings,
                (SELECT COUNT(*) FROM placex WHERE class IN ('amenity', 'shop')) as amenities
        ");
        $stats = $stmt->fetch();

        $stmt = $db->query("SELECT pg_size_pretty(pg_database_size(current_database())) as db_size");
        $size = $stmt->fetch();

        $stmt = $db->query("
            SELECT class, COUNT(*) as count 
            FROM placex 
            GROUP BY class 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $classList = $stmt->fetchAll();

        echo json_encode([
            'status' => 'OK',
            'timestamp' => date('c'),
            'service' => 'Enhanced Nominatim API',
            'database' => [
                'connected' => true,
                'size' => $size['db_size'],
                'statistics' => $stats,
                'top_classes' => $classList
            ],
            'version' => '2.0.0'
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            'status' => 'ERROR',
            'timestamp' => date('c'),
            'service' => 'Enhanced Nominatim API',
            'error' => $e->getMessage()
        ]);
    }
}

function formatPlaceResult($row)
{
    $address = parseHstore($row['address'] ?? '');
    $name = parseHstore($row['name'] ?? '');

    // Build comprehensive display name
    $displayParts = [];

    // House number + street
    if (!empty($row['housenumber']) && isset($address['road'])) {
        $displayParts[] = $row['housenumber'] . ' ' . $address['road'];
    } elseif (!empty($row['housenumber']) && isset($address['street'])) {
        $displayParts[] = $row['housenumber'] . ' ' . $address['street'];
    } elseif (isset($address['road'])) {
        $displayParts[] = $address['road'];
    } elseif (isset($address['street'])) {
        $displayParts[] = $address['street'];
    }

    // Locality hierarchy
    $localityFields = ['suburb', 'neighbourhood', 'village', 'town', 'city', 'municipality'];
    foreach ($localityFields as $field) {
        if (isset($address[$field]) && !in_array($address[$field], $displayParts)) {
            $displayParts[] = $address[$field];
            break;
        }
    }

    // Region
    if (isset($address['county']) && !in_array($address['county'], $displayParts)) {
        $displayParts[] = $address['county'];
    } elseif (isset($address['state']) && !in_array($address['state'], $displayParts)) {
        $displayParts[] = $address['state'];
    }

    // Postcode
    if (!empty($row['postcode'])) {
        $displayParts[] = $row['postcode'];
    }

    // Country
    if (isset($address['country'])) {
        $displayParts[] = $address['country'];
    } elseif (!empty($row['country_code'])) {
        $displayParts[] = strtoupper($row['country_code']);
    }

    $displayName = implode(', ', array_filter($displayParts));

    // Determine primary name with fallback chain
    $primaryName =
        $name['name'] ??
        $name['name:en'] ??
        $name['official_name'] ??
        $address['road'] ??
        $address['street'] ??
        $address['suburb'] ??
        $address['city'] ??
        $address['town'] ??
        $row['postcode'] ??
        'Unknown';

    return [
        'place_id' => (int)$row['place_id'],
        'licence' => 'Data © OpenStreetMap contributors, ODbL 1.0. https://osm.org/copyright',
        'osm_type' => strtoupper($row['osm_type']),
        'osm_id' => (int)$row['osm_id'],
        'lat' => round((float)$row['lat'], 7),
        'lon' => round((float)$row['lon'], 7),
        'class' => $row['class'],
        'type' => $row['type'],
        'importance' => round((float)($row['importance'] ?? 0), 4),
        'name' => $primaryName,
        'display_name' => $displayName,
        'address' => $address,
        'postcode' => $row['postcode'] ?? null,
        'housenumber' => $row['housenumber'] ?? null,
        'country_code' => strtolower($row['country_code'] ?? 'gb')
    ];
}

function parseHstore($hstoreString)
{
    if (empty($hstoreString) || $hstoreString === '""' || $hstoreString === '{}' || $hstoreString === 'NULL') {
        return [];
    }

    $result = [];

    // Remove outer quotes and braces
    $hstoreString = trim($hstoreString, '"{}');

    if (empty($hstoreString)) {
        return [];
    }

    // Split by comma that's not inside quotes
    $pairs = [];
    $current = '';
    $inQuotes = false;

    for ($i = 0; $i < strlen($hstoreString); $i++) {
        $char = $hstoreString[$i];

        if ($char === '"' && ($i === 0 || $hstoreString[$i - 1] !== '\\')) {
            $inQuotes = !$inQuotes;
        }

        if ($char === ',' && !$inQuotes) {
            $pairs[] = $current;
            $current = '';
        } else {
            $current .= $char;
        }
    }

    if (!empty($current)) {
        $pairs[] = $current;
    }

    foreach ($pairs as $pair) {
        $pair = trim($pair);
        if (empty($pair)) continue;

        // Split key and value by '=>'
        if (preg_match('/^"([^"]+)"\s*=>\s*(?:"([^"]*)"|NULL)$/', $pair, $matches)) {
            $key = $matches[1];
            $value = isset($matches[2]) ? $matches[2] : null;

            if ($value !== null && $value !== 'NULL' && $value !== '') {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}