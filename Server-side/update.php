<?php
/**
 * TEZOS ART WALL v2.1 — ESP32 HUB75 MASTER (2025)
 * 
 * FIXES FOR ERROR -11 (SEGFAULT):
 * - Added image size limits before processing
 * - Increased memory limit to 512M
 * - Proper resource cleanup after each image
 * - Safe image creation with dimension checks
 * - Force garbage collection periodically
 * - Unset large variables immediately after use
 */

set_time_limit(180);
ini_set('memory_limit', '512M');  // Increased memory
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Flush output immediately
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

echo "<pre>";

/* ================= CONFIG ================= */
$WIDTH = 64;
$HEIGHT = 64;
$TARGET_COUNT = 10;
$MAX_JPEG_SIZE = 50000;
$MAX_SOURCE_SIZE = 5 * 1024 * 1024;  // 5MB max source image
$MAX_SOURCE_PIXELS = 4000 * 4000;     // 16MP max
$BASE_URL = "https://paradox.ovh/led-art";
$ART_DIR = __DIR__ . "/art/";
$JSON_FILE = __DIR__ . "/nfts.json";
$LOCK_FILE = __DIR__ . "/art_wall.lock";
$LOG_FILE = __DIR__ . "/fetch_log.txt";
$GATEWAY_CACHE = __DIR__ . "/gateway_speeds.json";
$USER_AGENT = 'TezosArtWall/2.1 (ESP32 HUB75 Display)';

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
    $str = preg_replace('/[^a-zA-Z0-9# ]/', '', $str ?? '');
    return str_pad(substr($str, 0, $len), $len, " ");
}

function image_is_too_dark($img) {
    if (!$img) return true;
    
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) return true;
    
    $sum = 0;
    $samples = 0;
    
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $rgb = @imagecolorat($img, $x, $y);
            if ($rgb === false) continue;
            $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
            $samples++;
        }
    }
    
    return ($samples > 0) ? ($sum / $samples) < 60 : true;
}

/**
 * Safe image creation with size checks - PREVENTS SEGFAULT
 */
function safe_imagecreatefromstring($data) {
    global $MAX_SOURCE_SIZE, $MAX_SOURCE_PIXELS;
    
    if (!$data || strlen($data) < 100) {
        return false;
    }
    
    // Check data size
    if (strlen($data) > $MAX_SOURCE_SIZE) {
        log_msg("Image too large: " . strlen($data) . " bytes", 'SKIP');
        return false;
    }
    
    // Get image info without loading full image
    $info = @getimagesizefromstring($data);
    if (!$info) {
        return false;
    }
    
    $width = $info[0];
    $height = $info[1];
    $pixels = $width * $height;
    
    // Check dimensions
    if ($width <= 0 || $height <= 0) {
        return false;
    }
    
    if ($pixels > $MAX_SOURCE_PIXELS) {
        log_msg("Image too many pixels: {$width}x{$height} = $pixels", 'SKIP');
        return false;
    }
    
    // Check image type (only allow known safe types)
    $type = $info[2];
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_BMP])) {
        log_msg("Unsupported image type: $type", 'SKIP');
        return false;
    }
    
    // Now safe to create image
    $img = @imagecreatefromstring($data);
    
    return $img ?: false;
}

/* ================= IPFS GATEWAY MANAGEMENT ================= */

$ALL_GATEWAYS = [
    "https://ipfs.io/ipfs/",
    "https://dweb.link/ipfs/",
    "https://w3s.link/ipfs/",
    "https://nftstorage.link/ipfs/",
    "https://gateway.pinata.cloud/ipfs/",
    "https://cloudflare-ipfs.com/ipfs/",
    "https://4everland.io/ipfs/",
    "https://cf-ipfs.com/ipfs/",
];

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
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $elapsed = (microtime(true) - $start) * 1000;
    
    if ($http_code === 200 && strlen($response) > 100) {
        return round($elapsed);
    }
    
    return false;
}

function get_working_gateways($force_retest = false) {
    global $ALL_GATEWAYS, $GATEWAY_CACHE;
    
    // Check cache (valid for 1 hour)
    if (!$force_retest && file_exists($GATEWAY_CACHE)) {
        $cache = json_decode(file_get_contents($GATEWAY_CACHE), true);
        if ($cache && isset($cache['timestamp']) && (time() - $cache['timestamp']) < 3600) {
            echo "Using cached gateway speeds (" . count($cache['gateways']) . " gateways)\n";
            return $cache['gateways'];
        }
    }
    
    echo "Testing IPFS gateways...\n";
    
    $results = [];
    
    foreach ($ALL_GATEWAYS as $gw) {
        $speed = test_gateway($gw);
        $short_name = substr($gw, 8, 30);
        
        if ($speed !== false) {
            $results[$gw] = $speed;
            echo "  ✓ $short_name - {$speed}ms\n";
        } else {
            echo "  ✗ $short_name - FAIL\n";
        }
    }
    
    asort($results);
    $sorted_gateways = array_keys($results);
    
    // Cache results
    file_put_contents($GATEWAY_CACHE, json_encode([
        'timestamp' => time(),
        'gateways' => $sorted_gateways,
        'speeds' => $results
    ], JSON_PRETTY_PRINT));
    
    echo "Working gateways: " . count($sorted_gateways) . "\n\n";
    
    return $sorted_gateways;
}

/* ================= FETCH FUNCTIONS ================= */

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
    ]);
    
    return $ch;
}

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
    
    return false;
}

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
        $ch = create_curl($url, 15);
        $content = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && strlen($content) > 500) {
            return $content;
        }
    }
    
    return false;
}

/* ================= OBJKT.COM RANDOM FETCH ================= */

function fetch_random_images($count = 60) {
    global $USER_AGENT;
    
    $all_tokens = [];
    // Only static images - NO GIF, NO VIDEO
    $allowed_mimes = '["image/png", "image/jpeg", "image/webp"]';
    
    // Multiple small batches from random offsets
    $batches = 4;
    $per_batch = ceil($count / $batches);
    
    echo "Fetching random images from objkt.com...\n";
    
    for ($b = 0; $b < $batches; $b++) {
        // Random offset across millions of NFTs
        $offset = rand(1000, 1500000);
        
        $query = 'query {
            token(
                limit: ' . $per_batch . ',
                offset: ' . $offset . ',
                where: {
                    mime: {_in: ' . $allowed_mimes . '},
                    display_uri: {_is_null: false},
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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if (!empty($res['data']['token'])) {
            $fetched = count($res['data']['token']);
            $all_tokens = array_merge($all_tokens, $res['data']['token']);
            echo "  Batch " . ($b + 1) . ": $fetched tokens (offset: $offset)\n";
        } else {
            echo "  Batch " . ($b + 1) . ": FAILED (HTTP $http_code)\n";
        }
        
        usleep(200000);
    }
    
    shuffle($all_tokens);
    echo "Total tokens: " . count($all_tokens) . "\n\n";
    
    return $all_tokens;
}

/* ================= INIT ================= */
if (!is_dir($ART_DIR)) {
    mkdir($ART_DIR, 0755, true);
}

echo str_repeat("=", 60) . "\n";
echo " TEZOS ART WALL v2.1 — RANDOM DISCOVERY MODE\n";
echo " Started: " . date('Y-m-d H:i:s') . "\n";
echo " Memory limit: " . ini_get('memory_limit') . "\n";
echo str_repeat("=", 60) . "\n\n";

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
$token_pool = fetch_random_images(60);

if (empty($token_pool)) {
    echo "ERROR: Could not fetch any tokens from objkt.com\n";
    exit(1);
}

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
    
    // Double-check MIME type (safety)
    $mime = $t['mime'] ?? '';
    if (strpos($mime, 'gif') !== false || strpos($mime, 'video') !== false) {
        continue;
    }
    
    $image_uri = $t['display_uri'] ?? $t['artifact_uri'] ?? null;
    if (!$image_uri) continue;
    
    // Fetch image data
    $data = ipfs_fetch($image_uri, $working_gateways);
    if (!$data) {
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"",15)." | FETCH ✗  |\n";
        $stats['fetch_fail']++;
        continue;
    }
    
    // Safe image creation with size checks (PREVENTS SEGFAULT)
    $src = safe_imagecreatefromstring($data);
    unset($data);  // FREE MEMORY IMMEDIATELY
    
    if (!$src) {
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"",15)." | DECODE ✗ |\n";
        $stats['decode_fail']++;
        continue;
    }
    
    // Create output image
    $img = @imagecreatetruecolor($WIDTH, $HEIGHT);
    if (!$img) {
        imagedestroy($src);
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"",15)." | MEM ✗    |\n";
        continue;
    }
    
    // Fill with black background
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $black);
    
    // Resample
    @imagecopyresampled($img, $src, 0, 0, 0, 0, $WIDTH, $HEIGHT, imagesx($src), imagesy($src));
    
    // FREE SOURCE IMMEDIATELY
    imagedestroy($src);
    $src = null;
    
    // Apply filters
    @imagefilter($img, IMG_FILTER_CONTRAST, -12);
    @imagefilter($img, IMG_FILTER_COLORIZE, 8, 8, 8);
    
    if (image_is_too_dark($img)) {
        imagedestroy($img);
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"",15)." | DARK ✗   |\n";
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
    
    @imagejpeg($img, $ART_DIR . $temp_fname, 88);
    
    if (file_exists($ART_DIR . $temp_fname) && filesize($ART_DIR . $temp_fname) > $MAX_JPEG_SIZE) {
        @imagejpeg($img, $ART_DIR . $temp_fname, 80);
    }
    
    // FREE IMAGE MEMORY
    imagedestroy($img);
    $img = null;
    
    if (file_exists($ART_DIR . $temp_fname) && filesize($ART_DIR . $temp_fname) > 1000) {
        @rename($ART_DIR . $temp_fname, $ART_DIR . $fname);
        $status = "UPDATED ✓";
        $stats['updated']++;
    } else {
        @unlink($ART_DIR . $temp_fname);
        if (file_exists($ART_DIR . $fname)) {
            $status = "KEPT OLD";
            $stats['kept_old']++;
        } else {
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
    ];
    
    $seen_artists[] = $artist_id;
    
    echo "| ".str_pad($slot,4)." | ".cleanStr($t['name'] ?? 'Untitled',30)." | ".cleanStr($t['fa']['name']??"",15)." | $status |\n";
    $slot++;
    
    // FORCE GARBAGE COLLECTION every 3 images
    if ($slot % 3 === 0) {
        gc_collect_cycles();
    }
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
echo "  Memory peak:   " . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . " MB\n";
echo "\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "SYSTEM IDLE.\n";
echo "</pre>";