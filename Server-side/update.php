<?php
/**
 * TEZOS ART WALL v2 — ESP32 HUB75 MASTER (2025)
 * 
 * NEW IN V2:
 * - Auto-tests IPFS gateways and uses fastest working ones
 * - Fetches RANDOM users/images from objkt.com (no hardcoded list!)
 * - Strict image-only filter (no GIF, no video, no animation)
 * - Extended gateway list with health checking
 * - Smarter random offset for true randomness across millions of NFTs
 */

set_time_limit(180);
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

echo "<pre>";

/* ================= CONFIG ================= */
$WIDTH = 64;
$HEIGHT = 64;
$TARGET_COUNT = 10;
$MAX_JPEG_SIZE = 50000;
$BASE_URL = "https://paradox.ovh/led-art";
$ART_DIR = __DIR__ . "/art/";
$JSON_FILE = __DIR__ . "/nfts.json";
$LOCK_FILE = __DIR__ . "/art_wall.lock";
$LOG_FILE = __DIR__ . "/fetch_log.txt";
$GATEWAY_CACHE = __DIR__ . "/gateway_speeds.json";
$USER_AGENT = 'TezosArtWall/2.0 (ESP32 HUB75 Display)';

/* ================= CRON LOCK ================= */
$lock_fp = fopen($LOCK_FILE, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    echo "Another instance is already running. Exiting.\n";
    exit;
}
register_shutdown_function(function() use ($lock_fp) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
});

/* ================= GAMMA LUT ================= */
$gamma_lut = [];
for ($i = 0; $i < 256; $i++) {
    $gamma_lut[$i] = (int)(pow($i / 255.0, 2.5) * 255.0 + 0.5);
}

/* ================= HELPERS ================= */
function log_msg($msg, $type = 'INFO') {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('Y-m-d H:i:s') . " [$type] $msg\n", FILE_APPEND);
}

function cleanStr($str, $len) {
    $str = preg_replace('/[^a-zA-Z0-9# ]/', '', $str);
    return str_pad(substr($str, 0, $len), $len, " ");
}

function image_is_too_dark($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    $sum = 0;
    $samples = 0;
    
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $rgb = imagecolorat($img, $x, $y);
            $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
            $samples++;
        }
    }
    
    return ($samples > 0) ? ($sum / $samples) < 60 : true;
}

/* ================= IPFS GATEWAY MANAGEMENT ================= */

// Extended list of IPFS gateways
$ALL_GATEWAYS = [
    "https://ipfs.io/ipfs/",
    "https://dweb.link/ipfs/",
    "https://w3s.link/ipfs/",
    "https://nftstorage.link/ipfs/",
    "https://gateway.pinata.cloud/ipfs/",
    "https://cloudflare-ipfs.com/ipfs/",
    "https://4everland.io/ipfs/",
    "https://cf-ipfs.com/ipfs/",
    "https://ipfs.runfission.com/ipfs/",
    "https://gateway.ipfs.io/ipfs/",
    "https://hardbin.com/ipfs/",
    "https://ipfs.eth.aragon.network/ipfs/",
    "https://ipfs.fleek.co/ipfs/",
];

/**
 * Test a single gateway's speed and availability
 * Returns response time in ms, or false if failed
 */
function test_gateway($gateway_url, $test_hash = "QmYwAPJzv5CZsnA625s3Xf2nemtYgPpHdWEz79ojWnPbdG") {
    global $USER_AGENT;
    
    $url = $gateway_url . $test_hash;
    $start = microtime(true);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => $USER_AGENT,
        CURLOPT_NOBODY => false,  // We need content to verify
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $elapsed = (microtime(true) - $start) * 1000;
    
    // Check if response is valid (should be directory listing or content)
    if ($http_code === 200 && strlen($response) > 100) {
        return round($elapsed);
    }
    
    return false;
}

/**
 * Test all gateways and return sorted list by speed (fastest first)
 */
function get_working_gateways($force_retest = false) {
    global $ALL_GATEWAYS, $GATEWAY_CACHE;
    
    // Check cache (valid for 1 hour)
    if (!$force_retest && file_exists($GATEWAY_CACHE)) {
        $cache = json_decode(file_get_contents($GATEWAY_CACHE), true);
        if ($cache && isset($cache['timestamp']) && (time() - $cache['timestamp']) < 3600) {
            echo "Using cached gateway speeds (age: " . round((time() - $cache['timestamp']) / 60) . " min)\n";
            return $cache['gateways'];
        }
    }
    
    echo "Testing IPFS gateways...\n";
    echo "+------------------------------------------+----------+\n";
    echo "| GATEWAY                                  | SPEED    |\n";
    echo "+------------------------------------------+----------+\n";
    
    $results = [];
    
    foreach ($ALL_GATEWAYS as $gw) {
        $speed = test_gateway($gw);
        $name = str_pad(substr($gw, 8, 38), 40);
        
        if ($speed !== false) {
            $results[$gw] = $speed;
            $status = str_pad($speed . " ms", 8);
            echo "| $name | $status |\n";
        } else {
            echo "| $name | FAIL     |\n";
        }
    }
    
    echo "+------------------------------------------+----------+\n";
    
    // Sort by speed (fastest first)
    asort($results);
    $sorted_gateways = array_keys($results);
    
    // Cache results
    file_put_contents($GATEWAY_CACHE, json_encode([
        'timestamp' => time(),
        'gateways' => $sorted_gateways,
        'speeds' => $results
    ], JSON_PRETTY_PRINT));
    
    echo "Working gateways: " . count($sorted_gateways) . " / " . count($ALL_GATEWAYS) . "\n\n";
    
    return $sorted_gateways;
}

/* ================= FETCH FUNCTIONS ================= */

/**
 * Create configured cURL handle
 */
function create_curl($url, $timeout = 10) {
    global $USER_AGENT;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => $USER_AGENT,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8'],
    ]);
    
    return $ch;
}

/**
 * Fetch from HTTP/HTTPS URL
 */
function http_fetch($url, $retries = 2) {
    for ($i = 0; $i <= $retries; $i++) {
        $ch = create_curl($url);
        $content = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && strlen($content) > 500) {
            return $content;
        }
        
        if ($i < $retries) usleep(300000);
    }
    
    log_msg("HTTP fetch failed: $url", 'FAIL');
    return false;
}

/**
 * Fetch from IPFS using fastest working gateways
 */
function ipfs_fetch($uri, $working_gateways) {
    if (empty($uri)) return false;
    
    // Handle direct HTTP/HTTPS
    if (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
        return http_fetch($uri);
    }
    
    // Handle IPFS
    if (strpos($uri, 'ipfs://') !== 0) {
        return false;
    }
    
    $hash = substr($uri, 7);
    
    // Try gateways in order (fastest first)
    foreach ($working_gateways as $gw) {
        $url = $gw . $hash;
        $ch = create_curl($url, 12);
        $content = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && strlen($content) > 500) {
            return $content;
        }
    }
    
    log_msg("IPFS fetch exhausted: $uri", 'FAIL');
    return false;
}

/* ================= OBJKT.COM RANDOM FETCH ================= */

/**
 * Get total count of static images on objkt.com
 */
function get_total_image_count() {
    global $USER_AGENT;
    
    $query = 'query { token_aggregate(where: {mime: {_in: ["image/png", "image/jpeg", "image/webp", "image/bmp", "image/tiff"]}}) { aggregate { count } } }';
    
    $ch = curl_init("https://data.objkt.com/v3/graphql");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ' . $USER_AGENT],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    return $res['data']['token_aggregate']['aggregate']['count'] ?? 500000;
}

/**
 * Fetch random images from objkt.com
 * Uses random offsets to get truly random images from millions available
 */
function fetch_random_images($count = 50) {
    global $USER_AGENT;
    
    // Get approximate total count
    $total = get_total_image_count();
    echo "Total static images on objkt.com: ~" . number_format($total) . "\n";
    
    $all_tokens = [];
    $batch_size = 25;
    $batches = ceil($count / $batch_size);
    
    // Allowed MIME types (static images only - NO GIF, NO VIDEO)
    $allowed_mimes = '["image/png", "image/jpeg", "image/webp", "image/bmp", "image/tiff"]';
    
    for ($b = 0; $b < $batches; $b++) {
        // Random offset within the total range
        $max_offset = max(0, $total - $batch_size - 1000);
        $offset = rand(0, $max_offset);
        
        $query = 'query {
            token(
                limit: ' . $batch_size . ',
                offset: ' . $offset . ',
                where: {
                    mime: {_in: ' . $allowed_mimes . '},
                    display_uri: {_is_null: false},
                    supply: {_gt: 0}
                },
                order_by: {pk: asc}
            ) {
                display_uri
                artifact_uri
                name
                mime
                fa { name }
                creators { creator_address }
                lowest_ask
            }
        }';
        
        $ch = curl_init("https://data.objkt.com/v3/graphql");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ' . $USER_AGENT],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (!empty($res['data']['token'])) {
            $all_tokens = array_merge($all_tokens, $res['data']['token']);
        }
        
        // Small delay between batches
        usleep(200000);
    }
    
    // Shuffle for extra randomness
    shuffle($all_tokens);
    
    return $all_tokens;
}

/**
 * Fetch random artists and their works
 */
function fetch_random_artists_images($count = 50) {
    global $USER_AGENT;
    
    $all_tokens = [];
    $allowed_mimes = '["image/png", "image/jpeg", "image/webp", "image/bmp", "image/tiff"]';
    
    // Strategy: Fetch multiple small batches from random offsets
    // This gives us images from many different random artists
    
    $batches = 5;
    $per_batch = ceil($count / $batches);
    
    for ($b = 0; $b < $batches; $b++) {
        // Random large offset to get different artists each time
        $offset = rand(1000, 2000000);
        
        $query = 'query {
            token(
                limit: ' . $per_batch . ',
                offset: ' . $offset . ',
                where: {
                    mime: {_in: ' . $allowed_mimes . '},
                    display_uri: {_is_null: false},
                    artifact_uri: {_is_null: false},
                    supply: {_gt: 0}
                }
            ) {
                display_uri
                artifact_uri
                name
                mime
                fa { name }
                creators { creator_address }
                lowest_ask
            }
        }';
        
        $ch = curl_init("https://data.objkt.com/v3/graphql");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ' . $USER_AGENT],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (!empty($res['data']['token'])) {
            $all_tokens = array_merge($all_tokens, $res['data']['token']);
            echo "  Batch " . ($b + 1) . ": fetched " . count($res['data']['token']) . " tokens (offset: $offset)\n";
        }
        
        usleep(150000);
    }
    
    shuffle($all_tokens);
    return $all_tokens;
}

/* ================= INIT ================= */
if (!is_dir($ART_DIR)) {
    mkdir($ART_DIR, 0755, true);
}

echo str_repeat("=", 65) . "\n";
echo " TEZOS ART WALL v2 — RANDOM DISCOVERY MODE\n";
echo " Started: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 65) . "\n\n";

/* ================= TEST GATEWAYS ================= */
$working_gateways = get_working_gateways();

if (empty($working_gateways)) {
    echo "ERROR: No working IPFS gateways found!\n";
    exit(1);
}

/* ================= LOAD OLD PLAYLIST ================= */
$old_playlist = file_exists($JSON_FILE) ? json_decode(file_get_contents($JSON_FILE), true) : [];
if (!is_array($old_playlist)) $old_playlist = [];

/* ================= FETCH RANDOM IMAGES ================= */
echo "Fetching random images from objkt.com...\n";
$token_pool = fetch_random_artists_images(80);
echo "Token pool: " . count($token_pool) . " random images\n\n";

/* ================= PROCESSING ================= */
$playlist = [];
$seen_artists = [];
$slot = 0;
$batch_v = time();

$stats = ['fetch_fail' => 0, 'decode_fail' => 0, 'too_dark' => 0, 'updated' => 0, 'kept_old' => 0];

echo "+------+--------------------------------+-----------------+----------+\n";
echo "| SLOT | TITLE                          | ARTIST          | STATUS   |\n";
echo "+------+--------------------------------+-----------------+----------+\n";

foreach ($token_pool as $t) {
    if ($slot >= $TARGET_COUNT) break;
    
    // Skip if we've seen this artist
    $artist_id = $t['creators'][0]['creator_address'] ?? null;
    if (!$artist_id || in_array($artist_id, $seen_artists)) continue;
    
    // Double-check MIME type (safety filter)
    $mime = $t['mime'] ?? '';
    if (strpos($mime, 'gif') !== false || strpos($mime, 'video') !== false || strpos($mime, 'audio') !== false) {
        continue;
    }
    
    $image_uri = $t['display_uri'] ?? $t['artifact_uri'] ?? null;
    if (!$image_uri) continue;
    
    $data = ipfs_fetch($image_uri, $working_gateways);
    if (!$data) {
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | FETCH ✗  |\n";
        $stats['fetch_fail']++;
        continue;
    }
    
    $src = @imagecreatefromstring($data);
    if (!$src) {
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | DECODE ✗ |\n";
        $stats['decode_fail']++;
        continue;
    }
    
    // Create output image
    $img = imagecreatetruecolor($WIDTH, $HEIGHT);
    imagealphablending($img, true);
    imagesavealpha($img, true);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $black);
    
    imagecopyresampled($img, $src, 0, 0, 0, 0, $WIDTH, $HEIGHT, imagesx($src), imagesy($src));
    imagefilter($img, IMG_FILTER_CONTRAST, -12);
    imagefilter($img, IMG_FILTER_COLORIZE, 8, 8, 8);
    
    if (image_is_too_dark($img)) {
        imagedestroy($src);
        imagedestroy($img);
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | DARK ✗   |\n";
        $stats['too_dark']++;
        continue;
    }
    
    // Apply gamma correction
    for ($y = 0; $y < $HEIGHT; $y++) {
        for ($x = 0; $x < $WIDTH; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = $gamma_lut[($rgb >> 16) & 0xFF];
            $g = $gamma_lut[($rgb >> 8) & 0xFF];
            $b = $gamma_lut[$rgb & 0xFF];
            if ($r < 12 && $g < 12 && $b < 12) $r = $g = $b = 12;
            imagesetpixel($img, $x, $y, ($r << 16) | ($g << 8) | $b);
        }
    }
    
    $fname = "art_$slot.jpg";
    $temp_fname = "art_temp_$slot.jpg";
    
    imagejpeg($img, $ART_DIR . $temp_fname, 88);
    if (filesize($ART_DIR . $temp_fname) > $MAX_JPEG_SIZE) {
        imagejpeg($img, $ART_DIR . $temp_fname, 82);
    }
    
    if (file_exists($ART_DIR . $temp_fname) && filesize($ART_DIR . $temp_fname) > 1000) {
        rename($ART_DIR . $temp_fname, $ART_DIR . $fname);
        $status = "UPDATED ✓";
        $stats['updated']++;
    } else {
        @unlink($ART_DIR . $temp_fname);
        if (file_exists($ART_DIR . $fname)) {
            $status = "KEPT OLD";
            $stats['kept_old']++;
        } else {
            imagedestroy($src);
            imagedestroy($img);
            continue;
        }
    }
    
    $playlist[] = [
        "url" => "$BASE_URL/art/$fname?v=$batch_v",
        "artist" => $t['fa']['name'] ?? "Unknown",
        "title" => $t['name'] ?? "Untitled",
        "price" => ($t['lowest_ask'] > 0) ? round($t['lowest_ask']/1000000, 2) . " XTZ" : "NFS",
        "original_uri" => $t['display_uri'] ?? '',
        "artifact_uri" => $t['artifact_uri'] ?? '',
        "mime" => $t['mime'] ?? '',
    ];
    
    $seen_artists[] = $artist_id;
    imagedestroy($src);
    imagedestroy($img);
    
    echo "| ".str_pad($slot,4)." | ".cleanStr($t['name'] ?? 'Untitled',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | $status |\n";
    $slot++;
}

// Fallback to old images
while ($slot < $TARGET_COUNT && isset($old_playlist[$slot])) {
    $fname = "art_$slot.jpg";
    if (file_exists($ART_DIR . $fname) && filesize($ART_DIR . $fname) > 1000) {
        $old_entry = $old_playlist[$slot];
        $old_entry['url'] = "$BASE_URL/art/$fname?v=$batch_v";
        $playlist[] = $old_entry;
        echo "| ".str_pad($slot,4)." | (kept existing)                |                 | OLD ✓    |\n";
        $slot++;
        $stats['kept_old']++;
    } else {
        break;
    }
}

/* ================= FINALIZE ================= */
if (!empty($playlist)) {
    file_put_contents($JSON_FILE, json_encode($playlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$total_kb = 0;
foreach (glob("$ART_DIR/*.jpg") as $f) {
    $total_kb += filesize($f);
}
$total_kb = round($total_kb / 1024, 1);

echo "+------+--------------------------------+-----------------+----------+\n\n";

echo "SUMMARY:\n";
echo "  Images ready:  $slot / $TARGET_COUNT\n";
echo "  Updated:       {$stats['updated']}\n";
echo "  Kept old:      {$stats['kept_old']}\n";
echo "  Fetch failed:  {$stats['fetch_fail']}\n";
echo "  Decode failed: {$stats['decode_fail']}\n";
echo "  Too dark:      {$stats['too_dark']}\n";
echo "\n";
echo "  Disk usage:    {$total_kb} KB\n";
echo "  Batch ID:      $batch_v\n";
echo "  Fastest gateway: " . ($working_gateways[0] ?? 'N/A') . "\n";
echo "\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "SYSTEM IDLE.\n";
echo "</pre>";