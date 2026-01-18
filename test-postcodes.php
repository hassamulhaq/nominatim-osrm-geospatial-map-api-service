<?php
header('Content-Type: application/json');

$testPostcodes = [
    'W2 2DS',
    'SW1A 1AA', // Buckingham Palace area
    'E1 6AN',   // Shoreditch
    'EC2A 4BX', // Old Street
    'NW1 5RY',  // Camden
    'SE1 9SG',  // London Bridge
    'WC2E 9AB', // Covent Garden
];

$base_url = 'http://' . $_SERVER['HTTP_HOST'];
$results = [];

foreach ($testPostcodes as $postcode) {
    $url = $base_url . '/?q=' . urlencode($postcode) . '&limit=3';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    
    $results[$postcode] = [
        'url' => $url,
        'status' => $http_code,
        'found' => is_array($decoded) && count($decoded) > 0 && !isset($decoded['error']),
        'results_count' => is_array($decoded) ? count($decoded) : 0,
        'sample_display' => is_array($decoded) && count($decoded) > 0 ? $decoded[0]['display_name'] ?? 'No display name' : 'No results'
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>
