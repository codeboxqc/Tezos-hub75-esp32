<?php
/**
 * TEZOS ART WALL v2.4 — ESP32 OPTIMIZED
 * 
 * FIXES FOR ESP32 ERROR -11 (READ_TIMEOUT):
 * - Sends output immediately (no buffering)
 * - Faster execution with fewer API calls
 * - Smaller batches, quicker response
 * - Timeout-safe operations
 */

// IMMEDIATE OUTPUT - prevents ESP32 timeout
if (ob_get_level()) ob_end_flush();
header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

// Shorter execution time
set_time_limit(60);
ini_set('memory_limit', '128M');
error_reporting(0);

echo "=== ART WALL v2.4 ===\n";
flush();

/* ================= CONFIG ================= */
$WIDTH = 64;
$HEIGHT = 64;
$TARGET_COUNT = 10;
$MAX_FETCH_SIZE = 1024 * 1024;
$BASE_URL = "https://paradox.ovh/led-art";
$ART_DIR = __DIR__ . "/art/";
$JSON_FILE = __DIR__ . "/nfts.json";
$LOCK_FILE = __DIR__ . "/art_wall.lock";

/* ================= LOCK ================= */
$lock_fp = @fopen($LOCK_FILE, 'c');
if ($lock_fp && !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    echo "BUSY\n";
    exit;
}

/* ================= GAMMA ================= */
$gamma = [];
for ($i = 0; $i < 256; $i++) {
    $gamma[$i] = (int)(pow($i / 255.0, 2.5) * 255.0 + 0.5);
}

/* ================= GATEWAYS ================= */
$GW = [
    "https://ipfs.io/ipfs/",
    "https://nftstorage.link/ipfs/",
    "https://cloudflare-ipfs.com/ipfs/",
];

/* ================= FETCH ================= */
function fetch($uri) {
    global $GW, $MAX_FETCH_SIZE;
    if (empty($uri)) return false;
    
    if (strpos($uri, 'http') === 0) {
        return quickFetch($uri);
    }
    
    if (strpos($uri, 'ipfs://') === 0) {
        $hash = substr($uri, 7);
        foreach ($GW as $gw) {
            $data = quickFetch($gw . $hash);
            if ($data) return $data;
        }
    }
    return false;
}

function quickFetch($url) {
    global $MAX_FETCH_SIZE;
    $ch = @curl_init($url);
    if (!$ch) return false;
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'ArtWall/2.4',
        CURLOPT_MAXFILESIZE => $MAX_FETCH_SIZE,
    ]);
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($code === 200 && $data && strlen($data) > 500) ? $data : false;
}

/* ================= PROCESS IMAGE ================= */
function processImg($data, $w, $h, $gamma, $path) {
    $info = @getimagesizefromstring($data);
    if (!$info || $info[0] > 2000 || $info[1] > 2000) return false;
    if (!in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP])) return false;
    
    $src = @imagecreatefromstring($data);
    if (!$src) return false;
    
    $dst = @imagecreatetruecolor($w, $h);
    if (!$dst) { imagedestroy($src); return false; }
    
    imagefill($dst, 0, 0, imagecolorallocate($dst, 0, 0, 0));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    imagedestroy($src);
    
    // Dark check
    $sum = 0;
    for ($y = 0; $y < $h; $y += 4) {
        for ($x = 0; $x < $w; $x += 4) {
            $rgb = imagecolorat($dst, $x, $y);
            $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
        }
    }
    if ($sum / 768 < 40) { imagedestroy($dst); return false; }
    
    imagefilter($dst, IMG_FILTER_CONTRAST, -10);
    
    // Gamma
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($dst, $x, $y);
            $r = $gamma[($rgb >> 16) & 0xFF];
            $g = $gamma[($rgb >> 8) & 0xFF];
            $b = $gamma[$rgb & 0xFF];
            if ($r < 10 && $g < 10 && $b < 10) $r = $g = $b = 10;
            imagesetpixel($dst, $x, $y, ($r << 16) | ($g << 8) | $b);
        }
    }
    
    $ok = imagejpeg($dst, $path, 85);
    imagedestroy($dst);
    gc_collect_cycles();
    
    return $ok && file_exists($path) && filesize($path) > 500;
}

/* ================= FETCH TOKENS (QUICK) ================= */
function getTokens($count = 50) {
    $tokens = [];
    $mimes = '["image/jpeg", "image/png", "image/webp"]';
    
    // Just 3 quick batches
    for ($b = 0; $b < 3; $b++) {
        $offset = mt_rand(0, 500000);
        $order = mt_rand(0, 1) ? 'asc' : 'desc';
        
        $query = 'query{token(limit:20,offset:'.$offset.',order_by:{pk:'.$order.'},where:{mime:{_in:'.$mimes.'},display_uri:{_is_null:false},supply:{_gt:0}}){display_uri name mime fa{name}creators{creator_address}lowest_ask}}';
        
        $ch = curl_init("https://data.objkt.com/v3/graphql");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (!empty($res['data']['token'])) {
            $tokens = array_merge($tokens, $res['data']['token']);
            echo "B" . ($b+1) . ":" . count($res['data']['token']) . " ";
            flush();
        }
    }
    
    shuffle($tokens);
    echo "\n";
    flush();
    return $tokens;
}

/* ================= MAIN ================= */
if (!is_dir($ART_DIR)) @mkdir($ART_DIR, 0755, true);

$old = file_exists($JSON_FILE) ? json_decode(file_get_contents($JSON_FILE), true) : [];
if (!is_array($old)) $old = [];

$tokens = getTokens(50);
echo "Pool:" . count($tokens) . "\n";
flush();

$playlist = [];
$seen = [];
$slot = 0;
$batch_v = time();

foreach ($tokens as $t) {
    if ($slot >= $TARGET_COUNT) break;
    
    $artist = $t['creators'][0]['creator_address'] ?? '';
    if (!$artist || in_array($artist, $seen)) continue;
    
    $uri = $t['display_uri'] ?? '';
    if (!$uri) continue;
    
    echo "[$slot]";
    flush();
    
    $data = fetch($uri);
    if (!$data) {
        echo "- ";
        flush();
        continue;
    }
    
    $fname = "art_$slot.jpg";
    $path = $ART_DIR . $fname;
    
    if (processImg($data, $WIDTH, $HEIGHT, $gamma, $path)) {
        $playlist[] = [
            "url" => "$BASE_URL/art/$fname?v=$batch_v",
            "artist" => $t['fa']['name'] ?? "Unknown",
            "title" => $t['name'] ?? "Untitled",
            "price" => ($t['lowest_ask'] > 0) ? round($t['lowest_ask']/1000000, 2) . " XTZ" : "NFS",
            "original_uri" => $uri,
        ];
        $seen[] = $artist;
        echo "+ ";
        $slot++;
    } else {
        echo "x ";
    }
    flush();
    
    unset($data);
    gc_collect_cycles();
}

echo "\n";

// Fallback
while ($slot < $TARGET_COUNT && isset($old[$slot])) {
    $fname = "art_$slot.jpg";
    if (file_exists($ART_DIR . $fname)) {
        $e = $old[$slot];
        $e['url'] = "$BASE_URL/art/$fname?v=$batch_v";
        $playlist[] = $e;
        echo "K$slot ";
        flush();
        $slot++;
    } else {
        break;
    }
}

if ($playlist) {
    file_put_contents($JSON_FILE, json_encode($playlist, JSON_PRETTY_PRINT));
}

echo "\nOK:" . count($playlist) . "\n";

if ($lock_fp) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
}