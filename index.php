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
                    'limit' => 'Number of results (default: 10, max: 50)',
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
    $limit = min((int)($params['limit'] ?? 10), 50);
    $countryCodes = isset($params['countrycodes']) ? explode(',', strtolower($params['countrycodes'])) : ['gb'];

    if (empty($q)) {
        echo json_encode(['error' => 'Missing query parameter "q"']);
        return;
    }

    // Detect query type
    $queryType = detectQueryType($q);

    switch ($queryType) {
        case 'postcode':
            searchPostcode($db, $q, $limit, $countryCodes);
            break;
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

function detectQueryType($query)
{
    // UK Postcode pattern (e.g., W2 2DS, SE19 2AZ)
    if (preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?\s?\d[A-Z]{2}$/i', $query)) {
        return 'postcode';
    }

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
    // Normalize postcode
    $cleanPostcode = strtoupper(preg_replace('/\s+/', '', $postcode));
    $postcodeWithSpace = preg_replace('/([A-Z]{1,2}\d{1,2}[A-Z]?)(\d[A-Z]{2})/', '$1 $2', $cleanPostcode);

    // Build country filter
    $countryFilter = '';
    $countryParams = [];
    if (!empty($countryCodes)) {
        $placeholders = [];
        foreach ($countryCodes as $i => $code) {
            $placeholders[] = ":country$i";
            $countryParams[":country$i"] = $code;
        }
        $countryFilter = 'AND country_code IN (' . implode(',', $placeholders) . ')';
    }

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
                WHEN postcode = :exact_postcode THEN 1
                WHEN postcode = :space_postcode THEN 2
                WHEN postcode ILIKE :fuzzy_postcode THEN 3
                WHEN address::text ILIKE :addr_postcode THEN 4
                ELSE 5
            END as match_quality
        FROM placex 
        WHERE (
            postcode = :exact_postcode OR
            postcode = :space_postcode OR
            postcode ILIKE :fuzzy_postcode OR
            address::text ILIKE :addr_postcode
        )
        $countryFilter
        ORDER BY 
            match_quality ASC,
            CASE 
                WHEN class = 'place' AND type IN ('house', 'building') THEN 1
                WHEN class = 'boundary' AND type = 'postal_code' THEN 2
                WHEN class IN ('amenity', 'shop', 'building') THEN 3
                ELSE 4
            END,
            importance DESC NULLS LAST,
            rank_address ASC
        LIMIT :limit
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':exact_postcode', $cleanPostcode, PDO::PARAM_STR);
    $stmt->bindValue(':space_postcode', $postcodeWithSpace, PDO::PARAM_STR);
    $stmt->bindValue(':fuzzy_postcode', "%$postcodeWithSpace%", PDO::PARAM_STR);
    $stmt->bindValue(':addr_postcode', "%$postcodeWithSpace%", PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    foreach ($countryParams as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->execute();
    $results = $stmt->fetchAll();

    if (empty($results)) {
        // Fallback to fuzzy search
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
            importance DESC NULLS LAST,
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
    $searchTerms = explode(' ', $query);
    $countryFilter = buildCountryFilter($countryCodes);

    // Build search conditions with ranking
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
                -- Exact name match (highest priority)
                WHEN LOWER(name->>'name') = LOWER(:exact_query) THEN 1
                WHEN LOWER(name->>'name:en') = LOWER(:exact_query) THEN 2
                -- Name starts with query
                WHEN LOWER(name->>'name') LIKE LOWER(:starts_query) THEN 3
                -- Address exact match
                WHEN address::text ILIKE :exact_addr THEN 4
                -- Postcode exact match
                WHEN postcode ILIKE :exact_query THEN 5
                -- Name contains query
                WHEN name::text ILIKE :search_query THEN 6
                -- Address contains query
                WHEN address::text ILIKE :search_query THEN 7
                -- Extratags match
                WHEN extratags::text ILIKE :search_query THEN 8
                ELSE 9
            END as match_quality,
            -- Calculate text similarity for better ranking
            CASE
                WHEN name->>'name' IS NOT NULL THEN 
                    similarity(LOWER(name->>'name'), LOWER(:exact_query))
                ELSE 0
            END as name_similarity
        FROM placex 
        WHERE (
            name::text ILIKE :search_query OR
            address::text ILIKE :search_query OR
            postcode ILIKE :search_query OR
            housenumber ILIKE :search_query OR
            extratags::text ILIKE :search_query
        )
        {$countryFilter['sql']}
        AND rank_search < 30  -- Exclude very low-ranked results
        ORDER BY 
            match_quality ASC,
            name_similarity DESC,
            CASE 
                WHEN class IN ('place', 'boundary', 'amenity', 'shop', 'building') THEN 1
                WHEN class = 'highway' THEN 2
                ELSE 3
            END,
            importance DESC NULLS LAST,
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
        AND rank_address > 0  -- Exclude invalid addresses
        ORDER BY 
            -- Prioritize by type and distance
            CASE 
                WHEN class = 'building' AND type IN ('house', 'residential', 'apartments') THEN 1
                WHEN class IN ('amenity', 'shop', 'tourism', 'leisure') THEN 2
                WHEN class = 'highway' AND type IN ('residential', 'service', 'road') THEN 3
                WHEN class = 'place' THEN 4
                WHEN class = 'boundary' THEN 5
                ELSE 6
            END,
            distance ASC,
            importance DESC NULLS LAST
        LIMIT 10
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lat', $lat, PDO::PARAM_STR);
    $stmt->bindValue(':lon', $lon, PDO::PARAM_STR);
    $stmt->bindValue(':radius', $radius, PDO::PARAM_INT);
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
    if (empty($hstoreString) || $hstoreString === '""' || $hstoreString === '{}') {
        return [];
    }

    $result = [];

    // Remove outer quotes if present
    $hstoreString = trim($hstoreString, '"');

    // Handle empty after trim
    if (empty($hstoreString)) {
        return [];
    }

    // Enhanced regex for hstore parsing with better escaping support
    $pattern = '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"\s*=>\s*"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/';
    preg_match_all($pattern, $hstoreString, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $key = stripcslashes($match[1]);
        $value = stripcslashes($match[2]);

        if (!empty($value) && $value !== 'NULL') {
            $result[$key] = $value;
        }
    }

    return $result;
}