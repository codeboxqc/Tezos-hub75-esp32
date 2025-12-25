#include "web.h"
#include <Arduino.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <ESP32-HUB75-MatrixPanel-I2S-DMA.h>

// External references from main.cpp
extern int artCount;
extern int currentIndex;
extern void displayNext();
extern void saveCurrentOriginal();
extern void fetchPlaylist();
extern void triggerServerUpdate();
extern uint32_t rotateTimer;
extern uint32_t sec15;

extern MatrixPanel_I2S_DMA *display;
extern Art collection[];

// Global config instance
WebConfig webConfig = {
    "user",                                    // ssid
    "pass",                                    // password
    "https://paradox.ovh/led-art/nfts.json",  // playlistUrl
    "https://paradox.ovh/led-art/update.php", // updateUrl
    "",                                        // favoriteAddress
    40000,                                     // rotateTimer
    12000,                                     // textDelay
    255,                                       // brightness
    false                                      // showFavoritesOnly
};

Preferences preferences;

// HTML Configuration Page (same as before)
const char CONFIG_PAGE[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tezos Art Wall Control</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #fff;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 {
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            margin-bottom: 10px;
        }
        .status {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
        }
        .panel {
            background: rgba(255,255,255,0.95);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            color: #333;
        }
        .panel h2 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 1.3em;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .current-art {
            background: linear-gradient(135deg, #667eea15, #764ba215);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .art-title {
            font-size: 1.4em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        .art-artist { color: #666; margin-bottom: 10px; }
        .art-price {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 5px 12px;
            border-radius: 5px;
            font-weight: bold;
        }
        .control-group { margin-bottom: 20px; }
        .control-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #555;
        }
        .control-group input[type="number"],
        .control-group input[type="text"],
        .control-group input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s;
        }
        .control-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .slider-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .slider {
            flex: 1;
            height: 8px;
            border-radius: 5px;
            background: #ddd;
            outline: none;
            -webkit-appearance: none;
        }
        .slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #667eea;
            cursor: pointer;
        }
        .slider-value {
            min-width: 60px;
            text-align: right;
            font-weight: bold;
            color: #667eea;
        }
        .button-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-top: 20px;
        }
        .btn {
            padding: 15px 20px;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .btn-success { background: #4CAF50; color: white; }
        .btn-warning { background: #ff9800; color: white; }
        .btn-danger { background: #f44336; color: white; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea15, #764ba215);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
            font-size: 0.9em;
        }
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-container input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎨 Tezos Art Wall</h1>
            <div class="status" id="statusBadge">🟢 Connected</div>
        </div>

        <div class="panel">
            <h2>📺 Now Showing</h2>
            <div class="current-art">
                <div class="art-title" id="currentTitle">Loading...</div>
                <div class="art-artist" id="currentArtist">by Artist</div>
                <span class="art-price" id="currentPrice">--</span>
            </div>
            <div class="button-grid">
                <button class="btn btn-primary" onclick="nextImage()">⏭️ Next</button>
                <button class="btn btn-success" onclick="saveImage()">💾 Save</button>
                <button class="btn btn-danger" onclick="refreshPlaylist()">🔄 Refresh</button>
            </div>
        </div>

        <div class="panel">
            <h2>⚙️ Display Settings</h2>
            <div class="control-group">
                <label>⏱️ Rotation Time (seconds)</label>
                <div class="slider-container">
                    <input type="range" class="slider" min="5" max="300" value="40" id="rotationSlider" oninput="updateRotation(this.value)">
                    <span class="slider-value" id="rotationValue">40s</span>
                </div>
            </div>
            <div class="control-group">
                <label>💡 Brightness</label>
                <div class="slider-container">
                    <input type="range" class="slider" min="16" max="255" value="255" id="brightnessSlider" oninput="updateBrightness(this.value)">
                    <span class="slider-value" id="brightnessValue">255</span>
                </div>
            </div>
            <div class="control-group">
                <label>📺 Text Scroll Delay (seconds)</label>
                <div class="slider-container">
                    <input type="range" class="slider" min="0" max="60" value="12" id="textDelaySlider" oninput="updateTextDelay(this.value)">
                    <span class="slider-value" id="textDelayValue">12s</span>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>📡 WiFi Configuration</h2>
            <div class="control-group">
                <label>Network SSID</label>
                <input type="text" id="wifiSSID" placeholder="Enter WiFi name">
            </div>
            <div class="control-group">
                <label>Password</label>
                <input type="password" id="wifiPassword" placeholder="Enter WiFi password">
            </div>
            <div class="button-grid">
                <button class="btn btn-primary" onclick="saveWiFiConfig()">💾 Save WiFi</button>
                <button class="btn btn-danger" onclick="resetWiFi()">🔄 Reset & AP Mode</button>
            </div>
        </div>

        <div class="panel">
            <h2>📊 System Status</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value" id="artCount">0</div>
                    <div class="stat-label">Artworks Loaded</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="freeHeap">-- KB</div>
                    <div class="stat-label">Free RAM</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="uptime">--</div>
                    <div class="stat-label">Uptime</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="wifiSignal">-- dBm</div>
                    <div class="stat-label">WiFi Signal</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.onload = function() {
            loadStatus();
            loadConfig();
            setInterval(loadStatus, 5000);
        };

        function loadStatus() {
            fetch('/api/status')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('currentTitle').textContent = data.title || 'No artwork';
                    document.getElementById('currentArtist').textContent = 'by ' + (data.artist || 'Unknown');
                    document.getElementById('currentPrice').textContent = data.price || '--';
                    document.getElementById('artCount').textContent = data.artCount || 0;
                    document.getElementById('freeHeap').textContent = Math.round(data.freeHeap / 1024) + ' KB';
                    document.getElementById('uptime').textContent = formatUptime(data.uptime);
                    document.getElementById('wifiSignal').textContent = data.wifiRSSI + ' dBm';
                })
                .catch(e => console.error('Status error:', e));
        }

        function loadConfig() {
            fetch('/api/config')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('wifiSSID').value = data.ssid || '';
                    document.getElementById('rotationSlider').value = data.rotateTimer / 1000;
                    document.getElementById('rotationValue').textContent = (data.rotateTimer / 1000) + 's';
                    document.getElementById('textDelaySlider').value = data.textDelay / 1000;
                    document.getElementById('textDelayValue').textContent = (data.textDelay / 1000) + 's';
                    document.getElementById('brightnessSlider').value = data.brightness;
                    document.getElementById('brightnessValue').textContent = data.brightness;
                })
                .catch(e => console.error('Config error:', e));
        }

        function formatUptime(ms) {
            const s = Math.floor(ms / 1000);
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            return h + 'h ' + m + 'm';
        }

        function updateRotation(val) {
            document.getElementById('rotationValue').textContent = val + 's';
            fetch('/api/set?rotation=' + val);
        }

        function updateBrightness(val) {
            document.getElementById('brightnessValue').textContent = val;
            fetch('/api/set?brightness=' + val);
        }

        function updateTextDelay(val) {
            document.getElementById('textDelayValue').textContent = val + 's';
            fetch('/api/set?textDelay=' + val);
        }

        function nextImage() {
            fetch('/api/next');
            setTimeout(loadStatus, 1000);
        }

        function saveImage() {
            fetch('/api/save').then(r => r.text()).then(msg => alert('💾 ' + msg));
        }

        function refreshPlaylist() {
            fetch('/api/refresh');
            alert('🔄 Refreshing...');
            setTimeout(loadStatus, 2000);
        }

        function saveWiFiConfig() {
            const ssid = document.getElementById('wifiSSID').value;
            const pass = document.getElementById('wifiPassword').value;
            
            if (!ssid) {
                alert('⚠️ Enter WiFi SSID');
                return;
            }

            fetch('/api/saveWiFi', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ssid: ssid, password: pass})
            })
            .then(r => r.text())
            .then(msg => alert('💾 ' + msg + '\n\nRestarting...'));
        }

        function resetWiFi() {
            if (!confirm('Reset WiFi?\n\nWill restart in AP mode.')) return;
            fetch('/api/resetWiFi')
                .then(r => r.text())
                .then(msg => alert('🔄 ' + msg));
        }
    </script>
</body>
</html>
)rawliteral";

// Load configuration from flash
void handleLoadConfig() {
    if (!preferences.begin("artwall", true)) {  // Read-only mode first
        Serial.println("⚠️  Failed to open preferences for reading");
        preferences.end();
        
        // Try to initialize with defaults
        if (preferences.begin("artwall", false)) {
            preferences.putString("ssid", "user");
            preferences.putString("password", "pass");
            preferences.putString("playlist", "https://paradox.ovh/led-art/nfts.json");
            preferences.putString("update", "https://paradox.ovh/led-art/update.php");
            preferences.putString("favorite", "");
            preferences.end();
            Serial.println("✓ Initialized preferences with defaults");
        }
        return;
    }
    
    String ssid = preferences.getString("ssid", "user");
    String password = preferences.getString("password", "pass");
    String playlist = preferences.getString("playlist", "https://paradox.ovh/led-art/nfts.json");
    String update = preferences.getString("update", "https://paradox.ovh/led-art/update.php");
    String favorite = preferences.getString("favorite", "");
    
    strlcpy(webConfig.ssid, ssid.c_str(), 32);
    strlcpy(webConfig.password, password.c_str(), 64);
    strlcpy(webConfig.playlistUrl, playlist.c_str(), 128);
    strlcpy(webConfig.updateUrl, update.c_str(), 128);
    strlcpy(webConfig.favoriteAddress, favorite.c_str(), 64);
    
    webConfig.rotateTimer = preferences.getUInt("rotateTime", 40000);
    webConfig.textDelay = preferences.getUInt("textDelay", 12000);
    webConfig.brightness = preferences.getUChar("brightness", 255);
    webConfig.showFavoritesOnly = preferences.getBool("favOnly", false);
    
    preferences.end();
    
    Serial.printf("✓ Config loaded: SSID='%s', Brightness=%d\n", 
                  webConfig.ssid, webConfig.brightness);
}

// Reset WiFi credentials
void resetWiFiConfig() {
    if (!preferences.begin("artwall", false)) {
        Serial.println("Failed to open preferences for WiFi reset");
        return;
    }
    
    preferences.putString("ssid", "");
    preferences.putString("password", "");
    preferences.end();
    
    Serial.println("WiFi credentials cleared");
}

// Handle status request - FIXED: Use static buffer to avoid String fragmentation
void handleGetStatus(AsyncWebServerRequest *request) {
    static char jsonBuffer[512];
    
    const char* title = (artCount > 0 && currentIndex >= 0 && currentIndex < artCount) 
                        ? collection[currentIndex].title : "No artwork";
    const char* artist = (artCount > 0 && currentIndex >= 0 && currentIndex < artCount) 
                         ? collection[currentIndex].artist : "Unknown";
    const char* price = (artCount > 0 && currentIndex >= 0 && currentIndex < artCount) 
                        ? collection[currentIndex].price : "--";
    
    snprintf(jsonBuffer, sizeof(jsonBuffer),
        "{\"title\":\"%s\",\"artist\":\"%s\",\"price\":\"%s\","
        "\"artCount\":%d,\"currentIndex\":%d,\"freeHeap\":%d,"
        "\"uptime\":%lu,\"wifiRSSI\":%d}",
        title, artist, price, artCount, currentIndex,
        ESP.getFreeHeap(), millis(), WiFi.RSSI()
    );
    
    request->send(200, "application/json", jsonBuffer);
}

// Handle config request - FIXED: Use static buffer
void handleAPI(AsyncWebServerRequest *request) {
    static char jsonBuffer[512];
    
    snprintf(jsonBuffer, sizeof(jsonBuffer),
        "{\"ssid\":\"%s\",\"playlistUrl\":\"%s\",\"updateUrl\":\"%s\","
        "\"favoriteAddress\":\"%s\",\"showFavoritesOnly\":%s,"
        "\"rotateTimer\":%u,\"textDelay\":%u,\"brightness\":%u}",
        webConfig.ssid, webConfig.playlistUrl, webConfig.updateUrl,
        webConfig.favoriteAddress, 
        webConfig.showFavoritesOnly ? "true" : "false",
        webConfig.rotateTimer, webConfig.textDelay, webConfig.brightness
    );
    
    request->send(200, "application/json", jsonBuffer);
}

// FIXED: Safe JSON parsing helper
bool extractJsonString(const String& json, const char* key, char* output, size_t maxLen) {
    String searchKey = String("\"") + key + "\":\"";
    int start = json.indexOf(searchKey);
    if (start == -1) return false;
    
    start += searchKey.length();
    int end = json.indexOf("\"", start);
    if (end == -1) return false;
    
    String value = json.substring(start, end);
    strlcpy(output, value.c_str(), maxLen);
    return true;
}

bool extractJsonInt(const String& json, const char* key, uint32_t& output) {
    String searchKey = String("\"") + key + "\":";
    int start = json.indexOf(searchKey);
    if (start == -1) return false;
    
    start += searchKey.length();
    int end = json.indexOf(",", start);
    if (end == -1) end = json.indexOf("}", start);
    if (end == -1) return false;
    
    output = json.substring(start, end).toInt();
    return true;
}

// Setup web server routes
void setupWebServer(AsyncWebServer* server) {
    // Main page
    server->on("/", HTTP_GET, [](AsyncWebServerRequest *request){
        request->send(200, "text/html", CONFIG_PAGE);
    });
    
    // API endpoints
    server->on("/api/status", HTTP_GET, handleGetStatus);
    server->on("/api/config", HTTP_GET, handleAPI);
    
    server->on("/api/next", HTTP_GET, [](AsyncWebServerRequest *request){
        displayNext();
        request->send(200, "text/plain", "Next image");
    });
    
    server->on("/api/save", HTTP_GET, [](AsyncWebServerRequest *request){
        saveCurrentOriginal();
        request->send(200, "text/plain", "Saved!");
    });
    
    server->on("/api/refresh", HTTP_GET, [](AsyncWebServerRequest *request){
        triggerServerUpdate();
        fetchPlaylist();
        request->send(200, "text/plain", "Refreshed");
    });
    
    server->on("/api/set", HTTP_GET, [](AsyncWebServerRequest *request){
        if (request->hasParam("rotation")) {
            rotateTimer = request->getParam("rotation")->value().toInt() * 1000;
        }
        if (request->hasParam("brightness")) {
            uint8_t val = request->getParam("brightness")->value().toInt();
            if (display) display->setBrightness8(val);
            webConfig.brightness = val;
        }
        if (request->hasParam("textDelay")) {
            sec15 = request->getParam("textDelay")->value().toInt() * 1000;
        }
        request->send(200, "text/plain", "OK");
    });
    
    // WiFi save - FIXED: Safe parsing
    server->on("/api/saveWiFi", HTTP_POST, [](AsyncWebServerRequest *request){}, NULL,
        [](AsyncWebServerRequest *request, uint8_t *data, size_t len, size_t index, size_t total){
        
        // Build string from data
        String body;
        body.reserve(len + 1);
        for (size_t i = 0; i < len; i++) {
            body += (char)data[i];
        }
        
        char ssid[32] = {0};
        char password[64] = {0};
        
        if (!extractJsonString(body, "ssid", ssid, sizeof(ssid))) {
            request->send(400, "text/plain", "Invalid JSON");
            return;
        }
        
        extractJsonString(body, "password", password, sizeof(password));
        
        if (!preferences.begin("artwall", false)) {
            request->send(500, "text/plain", "Storage error");
            return;
        }
        
        preferences.putString("ssid", ssid);
        preferences.putString("password", password);
        preferences.end();
        
        request->send(200, "text/plain", "WiFi saved");
        
        delay(500);
        ESP.restart();
    });
    
    // WiFi reset
    server->on("/api/resetWiFi", HTTP_GET, [](AsyncWebServerRequest *request){
        resetWiFiConfig();
        request->send(200, "text/plain", "WiFi reset - restarting");
        delay(500);
        ESP.restart();
    });
    
    Serial.println("✓ Web server configured");
}