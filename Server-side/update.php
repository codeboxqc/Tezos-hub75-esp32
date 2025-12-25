<?php
/**
 * TEZOS ART WALL — ESP32 HUB75 MASTER (Optimized 2025)
 * - Safe updates (keeps old images on failure)
 * - Multi-gateway IPFS with retries
 * - Accepts tiny images (8x8 pixel art friendly)
 * - Includes original_uri in playlist
 * - Cron lock + fetch logging
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
    $gamma_lut[$i] = (int)(pow($i / 255.0, 2.5) * 255.0 + 0.5); // Slightly brighter than 2.8
}

/* ================= HELPERS ================= */
function log_failure($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('Y-m-d H:i:s') . " | $msg\n", FILE_APPEND);
}

function cleanStr($str, $len) {
    $str = preg_replace('/[^a-zA-Z0-9# ]/', '', $str);
    return str_pad(substr($str, 0, $len), $len, " ");
}

function image_is_too_dark($img) {
    $w = imagesx($img); $h = imagesy($img);
    $sum = 0;
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $rgb = imagecolorat($img, $x, $y);
            $sum += (($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF);
        }
    }
    return ($sum / (($w * $h) / 4)) < 60;
}

/* ================= IPFS FETCH WITH RETRIES ================= */
function ipfs_fetch($uri) {
    if (!$uri || strpos($uri, 'ipfs://') !== 0) return false;


if (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code === 200 && strlen($content) > 500) {
            return $content;
        }
        log_failure("DIRECT HTTP FAIL - HTTP $http_code - URI: $uri");
        return false;
    }


    $hash = substr($uri, 7);

    $gateways = [
        "https://ipfs.io/ipfs/",
        "https://dweb.link/ipfs/",
        "https://w3s.link/ipfs/",
        "https://nftstorage.link/ipfs/",
        "https://gateway.pinata.cloud/ipfs/"
    ];
    shuffle($gateways);

    foreach ($gateways as $gw) {
        for ($retry = 0; $retry < 3; $retry++) {
            $ch = curl_init($gw . $hash);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $content = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($http_code === 200 && strlen($content) > 500) {
                return $content;
            }
            if ($retry < 2) usleep(600000); // 0.6s delay
            if (!empty($err) || $http_code !== 200) {
                log_failure("IPFS FAIL (gw: $gw, retry: $retry) HTTP $http_code - $err - URI: $uri");
            }
        }
    }
    log_failure("IPFS EXHAUSTED all gateways - URI: $uri");
    return false;
}

/* ================= INIT ================= */
if (!is_dir($ART_DIR)) mkdir($ART_DIR, 0755, true);

echo str_repeat("=", 55) . "\n";
echo " TEZOS ART WALL — OPTIMIZED 2025\n";
echo str_repeat("=", 55) . "\n\n";

/* ================= LOAD OLD PLAYLIST (for fallbacks) ================= */
$old_playlist = file_exists($JSON_FILE) ? json_decode(file_get_contents($JSON_FILE), true) : [];

/* ================= ARTIST POOL ================= */
$hardcoded_users = [
    "tz1PUc3oQk3PpVGYRWmgQ6JHuk6rrHkP7K1Z", "tz1QUC5M3fjxVBtZyaAkPYaygFdG69W4YaBi",
    "tz1cxNr3nYkJGMPLL6czmNgYU83rCJh15km2", "tz1MN98ZDjGmXSyiX9fKvwYKryfzpDrQQAAx",
    "tz1WNJH6LWwknDvPMr4e8qNFCh3xog4DGKSn", "tz1V673LJBb6WfzXV96AVrdEY3CZcfPd5Vks",
    "tz1LHeoyqvGGyJWustEDEcEnKuGv7pcCJg5F", "tz1e3WGTLPS4Nj5gWhEYXVfGsKBrnpMH9Dcf",
    "tz1YuyKNk6yjsu6qYEZ8yvfCxvvg5H7j4Dto", "tz1QiaNzkgVR1TCqbmJnfrsM8Hksah7XoU9C",
    "tz1M9pVPgbmKL1uBB4SiUcpm4WyeythndcvJ", "tz1bdh4LACkprspkq4HkTPWNQXmUiigJWKq5",
    "tz1c8YasoAkyTLW9R3nL85bMmPvWsFhbbbE2", "tz1LBwyJMRkH4tcG19KwYzAW7fLYbjFmWdWy",
    "tz2RJWXriPiWFA5pC83We8XjZeJpqySr5jd7", "tz1aQpdn6WkZRKyvgnJEG1dhwy815FwLTTKE",
    "tz1WEZkz46AZmGbW32qwUHsdA2PBBATgixth", "tz1buGzt5zYc1rSXHEtcRtBvmCyBmgALtKRR",
    "tz1gtHwjKkDSru3DirLGGE1Re251CznkUepk", "KT1BPiSDboHKYcTa3dMWQaRJfoGL7txrE7qZ",
    "KT1B1YyKidzDoRR1Lfqi7H84XTCW59kkJn75", "tz1L3jRZoYWL6xWcmCqjyiwX7fK9VAM7pmph",
    "tz1Sue5xUeUPLu7nn2ECAEGrskJVBDWG8a1K", "tz1gfuU9RAGyHdyh5GD7pN1B76B8CU1o7XF4",
    "tz1WexV1ADBpBaSyZAeqgY4cw39Dv5AhPR1J", "tz1MDoU6gRYY2Db1cc854TTd6ark51kXHDPH",
    "tz1XSpiscuTsy1gqJirnde1NF4RBQ3cGsqHz", "tz1ft2mrATFz6AhHpY9pbwZowXqwkBkEpVwk",
    "tz1PHgbvXnS4dULb1C8VDo96mxXsYRAvgpJw", "tz1UD4AmZKTVYYqspVcd3SSsocsVr7CJJzpw",
    "tz1RpRGEEZCPSi5zQZNiA8UV6uHmmPXStGZH", "tz1d8hmkZH1a8iCzQTwxCCjWoajD3A4v3YpX",
    "tz1bgKfbwo6GN9BQYeJj3VYQ8MqBjCxVN3ni", "tz1VjaaBTjSEywNDdtHyQsdPKquDxKMwkmWp",
    "tz1TMhPyVaxsVVKqochpywWDPSumqwHjYWar", "tz1cQbywov54VNuQwharGqvmdeybK8W8SdgV",
    "tz1Zbvnu7SGEUWyReZCrSXr3wcc5wovAhJgs", "tz1TG24VFPypHX43ZLztZGcFozE1o6WKwbvu"
];
shuffle($hardcoded_users);
$selected_favs = array_slice($hardcoded_users, 0, 6);

/* ================= GRAPHQL FETCH ================= */
$queries = [];
foreach ($selected_favs as $addr) {
    $queries[] = 'query { token(limit: 3, where: {creators:{creator_address:{_eq:"'.$addr.'"}}, mime:{_ilike:"image/%"}, _and:[{mime:{_neq:"image/gif"}}]}){display_uri name fa{name} creators{creator_address} lowest_ask} }';
}
$deep_offset = rand(100, 300000);
$queries[] = 'query { token(limit: 80, offset: '.$deep_offset.', where:{mime:{_ilike:"image/%"}, _and:[{mime:{_neq:"image/gif"}}]}){display_uri name fa{name} creators{creator_address} lowest_ask} }';

$token_pool = [];
foreach ($queries as $q) {
    $ch = curl_init("https://data.objkt.com/v3/graphql");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode(['query' => $q]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15
    ]);
    $res = json_decode(curl_exec($ch), true);
    if (!empty($res['data']['token'])) {
        $token_pool = array_merge($token_pool, $res['data']['token']);
    }
    curl_close($ch);
}
shuffle($token_pool);

/* ================= PROCESSING ================= */
$playlist = [];
$seen_artists = [];
$slot = 0;
$batch_v = time();

echo "+------+--------------------------------+-----------------+----------+\n";
echo "| SLOT | TITLE                          | ARTIST          | STATUS   |\n";
echo "+------+--------------------------------+-----------------+----------+\n";

foreach ($token_pool as $t) {
    if ($slot >= $TARGET_COUNT) break;

    $artist_id = $t['creators'][0]['creator_address'] ?? null;
    if (!$artist_id || in_array($artist_id, $seen_artists)) continue;

    $data = ipfs_fetch($t['display_uri']);
    if (!$data) {
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | FETCH ✗  |\n";
        continue;
    }

    $src = @imagecreatefromstring($data);
    if (!$src) {
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | DECODE ✗ |\n";
        continue;
    }

    $img = imagecreatetruecolor($WIDTH, $HEIGHT);
    imagecopyresampled($img, $src, 0,0,0,0, $WIDTH,$HEIGHT, imagesx($src), imagesy($src));
    imagefilter($img, IMG_FILTER_CONTRAST, -12);
    imagefilter($img, IMG_FILTER_COLORIZE, 8, 8, 8);

    if (image_is_too_dark($img)) {
        imagedestroy($src); imagedestroy($img);
        echo "| ".str_pad($slot,4)." | ".cleanStr($t['name']??'Unknown',30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | DARK ✗   |\n";
        continue;
    }

    // Apply gamma + minimum brightness floor
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
    } else {
        @unlink($ART_DIR . $temp_fname);
        if (file_exists($ART_DIR . $fname)) {
            $status = "KEPT OLD";
        } else {
            imagedestroy($src); imagedestroy($img);
            continue;
        }
    }

    $playlist[] = [
        "url" => "$BASE_URL/art/$fname?v=$batch_v",
        "artist" => $t['fa']['name'] ?? "Unknown",
        "title" => $t['name'] ?? "Untitled",
        "price" => ($t['lowest_ask'] > 0) ? ($t['lowest_ask']/1000000)." XTZ" : "NFS",
        "original_uri" => $t['display_uri'] ?? ''   // ← YOUR REQUESTED FIELD
    ];

    $seen_artists[] = $artist_id;
    imagedestroy($src);
    imagedestroy($img);

    echo "| ".str_pad($slot,4)." | ".cleanStr($t['name'],30)." | ".cleanStr($t['fa']['name']??"Unknown",15)." | $status |\n";
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
    } else {
        break;
    }
}

/* ================= FINALIZE ================= */
file_put_contents($JSON_FILE, json_encode($playlist, JSON_PRETTY_PRINT));

$total_kb = 0;
if (is_dir($ART_DIR)) {
    foreach (glob("$ART_DIR/*.jpg") as $f) {
        $total_kb += filesize($f);
    }
}
$total_kb = round($total_kb / 1024, 1);

echo "+------+--------------------------------+-----------------+----------+\n";
echo "RESULT: $slot / $TARGET_COUNT images ready\n";
echo "Disk: {$total_kb} KB | Batch: $batch_v\n";
echo "Log: $LOG_FILE (failed fetches saved)\n";
echo "SYSTEM IDLE.\n";
echo "</pre>";