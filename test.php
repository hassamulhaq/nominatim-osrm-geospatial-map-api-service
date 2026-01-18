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
?>
