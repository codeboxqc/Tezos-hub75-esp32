<?php
// save_original.php - Saves FULL original size (handles IPFS + direct HTTPS)
$originals_dir = __DIR__ . "/originals/";
if (!is_dir($originals_dir)) mkdir($originals_dir, 0755, true);

if (!isset($_GET['url']) || empty($_GET['url'])) {
    http_response_code(400);
    echo "Missing URL";
    exit;
}

// Clean local URL (remove ?v=...)
$local_url = $_GET['url'];
$clean_url = strtok($local_url, '?');
$filename_base = basename($clean_url);

// Load nfts.json to find original_uri and title
$nfts = json_decode(file_get_contents(__DIR__ . "/nfts.json"), true);
$original_uri = null;
$title = null;

foreach ($nfts as $item) {
    if (strtok($item['url'], '?') === $clean_url) {
        $original_uri = $item['original_uri'] ?? null;
        $title = $item['title'] ?? null;
        break;
    }
}

if (!$original_uri) {
    echo "Original URI not found for $filename_base";
    http_response_code(404);
    exit;
}

// Convert IPFS to HTTP gateway if needed
$download_url = $original_uri;
if (strpos($original_uri, 'ipfs://') === 0) {
    $hash = str_replace('ipfs://', '', $original_uri);
    $download_url = "https://ipfs.io/ipfs/" . $hash;  // Reliable gateway
} // else: direct HTTPS (e.g., battletabs.com) — use as is

// Download the FULL original
$ch = curl_init($download_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'TezosWall/1.0');
$data = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Determine filename
if ($title) {
    // Sanitize title for filename
    $sanitized_title = preg_replace('/[^\w\s\-\.]/', '', $title); // Remove special characters
    $sanitized_title = preg_replace('/\s+/', '_', $sanitized_title); // Replace spaces with underscores
    $sanitized_title = trim($sanitized_title, '_'); // Remove leading/trailing underscores
    
    // Get file extension from original filename
    $extension = pathinfo($filename_base, PATHINFO_EXTENSION);
    
    // Use sanitized title with original extension
    $new_filename = $sanitized_title . '.' . $extension;
} else {
    // Fallback to original filename
    $new_filename = $filename_base;
}

$path = $originals_dir . $new_filename;

if ($code == 200 && $data && strlen($data) > 1000) {  // Basic size check
    // Check if file already exists (avoid overwriting)
    $counter = 1;
    $base_name = pathinfo($new_filename, PATHINFO_FILENAME);
    $extension = pathinfo($new_filename, PATHINFO_EXTENSION);
    
    while (file_exists($path)) {
        $new_filename = $base_name . '_' . $counter . '.' . $extension;
        $path = $originals_dir . $new_filename;
        $counter++;
    }
    
    file_put_contents($path, $data);
    echo "Saved full-size original: $new_filename (from $download_url)";
    http_response_code(200);
} else {
    echo "Failed to download original: HTTP $code from $download_url";
    http_response_code(500);
}
?>