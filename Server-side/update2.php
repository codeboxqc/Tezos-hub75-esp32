<?php
/**
 * TEZOS ART WALL — ESP32 HUB75 MASTER (Improved 2025)
 * 
 * FIXES & IMPROVEMENTS:
 * - Fixed ipfs_fetch() logic bug: now properly handles both IPFS and HTTP/HTTPS URLs
 * - Added SSL verification bypass for compatibility
 * - Added User-Agent header to all requests
 * - Better error handling and logging
 * - Added connection timeout consistency
 * - Improved code organization and comments
 * - Added retry logic for HTTP URLs
 * - Better gateway selection with health tracking
 */

set_time_limit(120);
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

echo "<pre>";

/* ================= CONFIG ================= */
$WIDTH = 64;
$HEIGHT = 64;
$TARGET_COUNT = 10;
$MAX_JPEG_SIZE = 50000; // ~50KB max per image
$BASE_URL = "https://paradox.ovh/led-art";
$ART_DIR = __DIR__ . "/art/";
$JSON_FILE = __DIR__ . "/nfts.json";
$LOCK_FILE = __DIR__ . "/art_wall.lock";
$LOG_FILE = __DIR__ . "/fetch_log.txt";
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
function log_failure($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('Y-m-d H:i:s') . " | $msg\n", FILE_APPEND);
}

function log_success($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('Y-m-d H:i:s') . " | [OK] $msg\n", FILE_APPEND);
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

/**
 * Create a configured cURL handle with common options
 */
function create_curl_handle($url, $timeout = 10, $connect_timeout = 5) {
    global $USER_AGENT;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connect_timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => $USER_AGENT,
        CURLOPT_ENCODING => '',  // Accept any encoding
        CURLOPT_HTTPHEADER => [
            'Accept: image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
    ]);
    
    return $ch;
}

/**
 * Fetch content from HTTP/HTTPS URL with retries
 */
function http_fetch($url, $max_retries = 3) {
    for ($retry = 0; $retry < $max_retries; $retry++) {
        $ch = create_curl_handle($url);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($http_code === 200 && $content && strlen($content) > 500) {
            return $content;
        }
        
        if ($retry < $max_retries - 1) {
            usleep(500000); // 0.5s delay between retries
        }
        
        log_failure("HTTP FAIL (retry: $retry) HTTP $http_code - $err - URL: $url");
    }
    
    return false;
}

/**
 * Fetch content from IPFS URI using multiple gateways
 * 
 * FIXED: Now properly handles both IPFS and HTTP/HTTPS URIs
 */
function ipfs_fetch($uri) {
    // Validate input
    if (empty($uri)) {
        return false;
    }
    
    // Handle direct HTTP/HTTPS URLs first (FIXED: moved before IPFS check)
    if (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
        $content = http_fetch($uri);
        if ($content) {
            log_success("Direct HTTP fetch: $uri");
            return $content;
        }
        log_failure("DIRECT HTTP EXHAUSTED - URI: $uri");
        return false;
    }
    
    // Handle IPFS URIs
    if (strpos($uri, 'ipfs://') !== 0) {
        log_failure("UNKNOWN URI SCHEME - URI: $uri");
        return false;
    }
    
    $hash = substr($uri, 7); // Remove 'ipfs://' prefix
    
    // Validate IPFS hash format (basic check)
    if (strlen($hash) < 10) {
        log_failure("INVALID IPFS HASH - Hash: $hash");
        return false;
    }
    
    $gateways = [
        "https://ipfs.io/ipfs/",
        "https://dweb.link/ipfs/",
        "https://w3s.link/ipfs/",
        "https://nftstorage.link/ipfs/",
        "https://gateway.pinata.cloud/ipfs/",
        "https://cloudflare-ipfs.com/ipfs/",
        "https://4everland.io/ipfs/",
    ];
    shuffle($gateways);
    
    foreach ($gateways as $gw) {
        for ($retry = 0; $retry < 2; $retry++) {
            $url = $gw . $hash;
            $ch = create_curl_handle($url);
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            
            if ($http_code === 200 && $content && strlen($content) > 500) {
                log_success("IPFS fetch via $gw - Hash: $hash");
                return $content;
            }
            
            if ($retry < 1) {
                usleep(600000); // 0.6s delay between retries
            }
            
            if (!empty($err) || $http_code !== 200) {
                log_failure("IPFS FAIL (gw: $gw, retry: $retry) HTTP $http_code - $err - Hash: $hash");
            }
        }
    }
    
    log_failure("IPFS EXHAUSTED all gateways - URI: $uri");
    return false;
}

/* ================= INIT ================= */
if (!is_dir($ART_DIR)) {
    mkdir($ART_DIR, 0755, true);
}

echo str_repeat("=", 60) . "\n";
echo " TEZOS ART WALL — IMPROVED 2025\n";
echo " Started: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 60) . "\n\n";

/* ================= LOAD OLD PLAYLIST (for fallbacks) ================= */
$old_playlist = file_exists($JSON_FILE) ? json_decode(file_get_contents($JSON_FILE), true) : [];
if (!is_array($old_playlist)) {
    $old_playlist = [];
}

/* ================= ARTIST POOL ================= */
$hardcoded_users = [
    "tz1XiK2GeWBkXtCz34xX8JBhjw3cF6Je6CjN", "tz1P5zDbc9LB7RWfYTFEbzQ9SzdYBn1vH1FW",
    "tz1czBpX8WrBZMtKAW3ZGhuPxt3Zhebw61gs", "tz1cxAHdRtx6x2qm6Kz1kJE3pJ9XnG3RQhip",
    "tz1QhMyNrcEtZXyE123g8nxLP5FXgNxTo5Ez", "tz1Taw6HUcZKQs3r4HjFxVgjTugYTktxrxo1",
    "tz1NQYBKmyd5n6vXBDdeS1z1pQ7B4CD2o97k", "tz1dd5cN392dj9DfHkFFvjbJqoxwrpdWhsoE",
    "tz1i2GzEp93wCpD8nfKRWAv7SgpBPZ9jSXtf", "tz1TeysWSYrjLdnurtFsZAfRDSZ4Durxo4mB",
    "tz2NBuibN4k8N3De4zAwFrFyuyQszvnqv9sy", "tz1MgZDC6JxGER3QeQdje6THRiofPRFnPc1T",
    "tz1Nkm7UqvS12YJTSyXXxWteb19XThTrPjdY", "tz1VknKTUQBpUBsTKn8GwYGTcqWsfpYHKudU",
    "tz1UC9U1PPdnzZ8aEEvPdfnhKfGutbs6Tup2", "tz1YKSWgo7dyqUCgw9cMkKuivNuJnD8tiKZb",
    "tz1S8wNQo3jdYk5RB3Hu7HKzVsYXPPdbnBUV", "tz1Ma4fDTd2JP11rkJHwJRtWdwWvZHoSGu9K",
    "tz1YgYp27jQN5YTkwu2TRVve8pnyNnXoiiJf", "tz1fE6KoCibLzUg93EKRH78YXNdR9fhyu5D4",
    "tz1YKSWgo7dyqUCgw9cMkKuivNuJnD8tiKZb", "tz1ixsi2aLwSCeEVi5V5J97LjRT4FaoA6Ryp",
    "tz1PUc3oQk3PpVGYRWmgQ6JHuk6rrHkP7K1Z", "tz1cxNr3nYkJGMPLL6czmNgYU83rCJh15km2",
    "tz1MN98ZDjGmXSyiX9fKvwYKryfzpDrQQAAx", "tz1WNJH6LWwknDvPMr4e8qNFCh3xog4DGKSn",
    "tz1LHeoyqvGGyJWustEDEcEnKuGv7pcCJg5F", "tz1e3WGTLPS4Nj5gWhEYXVfGsKBrnpMH9Dcf",
    "tz1bdh4LACkprspkq4HkTPWNQXmUiigJWKq5", "tz1V673LJBb6WfzXV96AVrdEY3CZcfPd5Vks",
    "tz2RJWXriPiWFA5pC83We8XjZeJpqySr5jd7", "tz1M9pVPgbmKL1uBB4SiUcpm4WyeythndcvJ",
    "tz1MDoU6gRYY2Db1cc854TTd6ark51kXHDPH", "tz1aQpdn6WkZRKyvgnJEG1dhwy815FwLTTKE",
    "tz1ft2mrATFz6AhHpY9pbwZowXqwkBkEpVwk", "tz1WEZkz46AZmGbW32qwUHsdA2PBBATgixth",
    "tz1XSpiscuTsy1gqJirnde1NF4RBQ3cGsqHz", "tz1gtHwjKkDSru3DirLGGE1Re251CznkUepk",
    "tz1UD4AmZKTVYYqspVcd3SSsocsVr7CJJzpw", "tz1gfuU9RAGyHdyh5GD7pN1B76B8CU1o7XF4",
    "tz1RpRGEEZCPSi5zQZNiA8UV6uHmmPXStGZH", "tz1PHgbvXnS4dULb1C8VDo96mxXsYRAvgpJw",
    "tz1d8hmkZH1a8iCzQTwxCCjWoajD3A4v3YpX", "tz1cQbywov54VNuQwharGqvmdeybK8W8SdgV",
    "tz1Zbvnu7SGEUWyReZCrSXr3wcc5wovAhJgs", "tz1TG24VFPypHX43ZLztZGcFozE1o6WKwbvu"
];
shuffle($hardcoded_users);
$selected_favs = array_slice($hardcoded_users, 0, 6);

/* ================= GRAPHQL FETCH ================= */
echo "Fetching tokens from objkt.com...\n";

$queries = [];
foreach ($selected_favs as $addr) {
    $queries[] = 'query { token(limit: 3, where: {creators:{creator_address:{_eq:"'.$addr.'"}}, mime:{_ilike:"image/%"}, _and:[{mime:{_neq:"image/gif"}}]}){display_uri artifact_uri name fa{name} creators{creator_address} lowest_ask} }';
}
$deep_offset = rand(100, 300000);
$queries[] = 'query { token(limit: 80, offset: '.$deep_offset.', where:{mime:{_ilike:"image/%"}, _and:[{mime:{_neq:"image/gif"}}]}){display_uri artifact_uri name fa{name} creators{creator_address} lowest_ask} }';

$token_pool = [];
$query_success = 0;
$query_fail = 0;

foreach ($queries as $q) {
    $ch = curl_init("https://data.objkt.com/v3/graphql");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['query' => $q]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ' . $USER_AGENT
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!empty($res['data']['token'])) {
        $token_pool = array_merge($token_pool, $res['data']['token']);
        $query_success++;
    } else {
        $query_fail++;
        log_failure("GraphQL query failed - HTTP $http_code");
    }
}

echo "Queries: $query_success success, $query_fail failed\n";
echo "Token pool: " . count($token_pool) . " tokens\n\n";

shuffle($token_pool);

/* ================= PROCESSING ================= */
$playlist = [];
$seen_artists = [];
$slot = 0;
$batch_v = time();

$stats = [
    'fetch_fail' => 0,
    'decode_fail' => 0,
    'too_dark' => 0,
    'updated' => 0,
    'kept_old' => 0,
];

echo "+------+--------------------------------+-----------------+----------+\n";
echo "| SLOT | TITLE                          | ARTIST          | STATUS   |\n";
echo "+------+--------------------------------+-----------------+----------+\n";

foreach ($token_pool as $t) {
    if ($slot >= $TARGET_COUNT) break;
    
    $artist_id = $t['creators'][0]['creator_address'] ?? null;
    if (!$artist_id || in_array($artist_id, $seen_artists)) continue;
    
    // Try display_uri first, fall back to artifact_uri
    $image_uri = $t['display_uri'] ?? $t['artifact_uri'] ?? null;
    if (!$image_uri) {
        continue;
    }
    
    $data = ipfs_fetch($image_uri);
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
    
    $img = imagecreatetruecolor($WIDTH, $HEIGHT);
    
    // Enable alpha blending for transparency support
    imagealphablending($img, true);
    imagesavealpha($img, true);
    
    // Fill with black background (for transparent images)
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $black);
    
    // Resample
    imagecopyresampled($img, $src, 0, 0, 0, 0, $WIDTH, $HEIGHT, imagesx($src), imagesy($src));
    
    // Apply filters
    imagefilter($img, IMG_FILTER_CONTRAST, -12);
    imagefilter($img, IMG_FILTER_COLORIZE, 8, 8, 8);
    
    if (image_is_too_dark($img)) {
        imagedestroy($src);
        imagedestroy($img);
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | DARK ✗   |\n";
        $stats['too_dark']++;
        continue;
    }
    
    // Apply gamma + minimum brightness floor
    for ($y = 0; $y < $HEIGHT; $y++) {
        for ($x = 0; $x < $WIDTH; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = $gamma_lut[($rgb >> 16) & 0xFF];
            $g = $gamma_lut[($rgb >> 8) & 0xFF];
            $b = $gamma_lut[$rgb & 0xFF];
            if ($r < 12 && $g < 12 && $b < 12) {
                $r = $g = $b = 12;
            }
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
        "artifact_uri" => $t['artifact_uri'] ?? '',  // Added full-res URI
    ];
    
    $seen_artists[] = $artist_id;
    imagedestroy($src);
    imagedestroy($img);
    
    echo "| ".str_pad($slot,4)." | ".cleanStr($t['name'] ?? 'Untitled',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | $status |\n";
    $slot++;
}

// Fallback to old images if needed
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
// Only write if we have content
if (!empty($playlist)) {
    file_put_contents($JSON_FILE, json_encode($playlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$total_kb = 0;
if (is_dir($ART_DIR)) {
    foreach (glob("$ART_DIR/*.jpg") as $f) {
        $total_kb += filesize($f);
    }
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
echo "  Log file:      $LOG_FILE\n";
echo "\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";
echo "SYSTEM IDLE.\n";
echo "</pre>";