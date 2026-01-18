<?php
// Direct proxy to Nominatim API (if you set up the full Nominatim)
$base_url = 'http://localhost:8080'; // If you run Nominatim on port 8080

$request_uri = $_SERVER['REQUEST_URI'];
$query_string = $_SERVER['QUERY_STRING'];

// Remove /nominatim.php from path
$path = str_replace('/nominatim.php', '', $request_uri);

$url = $base_url . $path;
if ($query_string) {
    $url .= '?' . $query_string;
}

// Forward the request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Forward headers
$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[] = "$name: $value";
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Forward method
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);

// Forward POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

curl_close($ch);

// Return response
http_response_code($http_code);
if ($content_type) {
    header("Content-Type: $content_type");
}
echo $response;
?>
