#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiUdp.h>
#include <ArduinoJson.h>
#include <ESPAsyncWebServer.h>
#include <ElegantOTA.h>
#include <ESP32-HUB75-MatrixPanel-I2S-DMA.h>
#include <JPEGDecoder.h>
#include "web.h"


// Enable watchdog monitoring
#include "esp_system.h"
#include "esp_task_wdt.h"



// ===== USER SETTINGS =====
char ssid[32] = "user";
char password[64] = "pass";
char playlistUrl[128] = "https://paradox.ovh/led-art/nfts.json";
char updateUrl[128]   = "https://paradox.ovh/led-art/update.php";

#define PANEL_RES_X 64
#define PANEL_RES_Y 64
#define PANEL_CHAIN 1
#define LDR_PIN 34


// Custom HUB75 pins
#define R1_PIN 17
#define G1_PIN 18
#define B1_PIN 8
#define R2_PIN 3
#define G2_PIN 2
#define B2_PIN 10
#define A_PIN  15
#define B_PIN  11
#define C_PIN  7
#define D_PIN  4
#define E_PIN  13
#define LAT_PIN 6
#define OE_PIN  12
#define CLK_PIN 5

MatrixPanel_I2S_DMA *display = nullptr;
AsyncWebServer server(80);
WiFiUDP udp;


 

#define MAX_COLLECTION 10 
Art collection[MAX_COLLECTION+1];
int artCount = 0;
int currentIndex = 0;

uint32_t rotateTimer = 40000  ;
uint32_t sec15 = 12000;

unsigned long lastSwitch = 0;

int textX;
char scrollText[256] = "\0";

// Image download buffer
uint8_t *imageBuffer = nullptr;
size_t imageSize = 0;

unsigned long imageShownTime = 0;
bool textActive = false; 

extern void logo();

// Function prototypes
void triggerServerUpdate();
void fetchPlaylist();
void displayNext();
void showStatus(const char* msg, uint16_t color = 0xFFFF);
bool downloadImage(const char* url);
 
uint16_t applyGammaRGB565(uint16_t color, int x, int y);
void cleanText(const char* input, char* output, size_t maxLen);

#define BUTTON_SAVE_PIN 0 
bool savePressed = false;

 extern void SetConfig();



/////////////////////////////////////////////////
//avoid mem defragmentation

 class ImageDownloader {
private:
    uint8_t* _buffer = nullptr;
    size_t _maxSize = 0;
    size_t _lastImageSize = 0;
    bool _isPSRAM = false;

public:
    // Initialize once in setup()
    bool begin(size_t maxExpectedSize) {
        _maxSize = maxExpectedSize;
        
        // AUTO-DETECT: Try PSRAM first, then Internal RAM
        if (psramFound()) {
            _buffer = (uint8_t*)ps_malloc(_maxSize);
            if (_buffer) {
                _isPSRAM = true;
                Serial.printf("Image Buffer: %d KB allocated in PSRAM\n", _maxSize/1024);
            }
        }

        if (!_buffer) {
            _buffer = (uint8_t*)malloc(_maxSize);
            _isPSRAM = false;
            if (_buffer) {
                Serial.printf("Image Buffer: %d KB allocated in INTERNAL RAM\n", _maxSize/1024);
            }
        }

        if (!_buffer) {
            Serial.println("CRITICAL ERROR: Could not allocate image buffer!");
            return false;
        }
        return true;
    }

    // Robust download logic
    bool download(const char* url) {
        if (WiFi.status() != WL_CONNECTED || !_buffer) return false;

        WiFiClientSecure client;
       client.setInsecure();                 // or setCACert(...)
        client.setTimeout(8000);

        HTTPClient http;
        
        //  http.useHTTP10(true);
         if (!http.begin(client, url)) {
            Serial.println("HTTPS begin failed");
              return false;
               }
        
        http.setTimeout(10000); // 10 second connection timeout
        
        // Add headers to prevent caching issues
        http.addHeader("User-Agent", "ESP32-Art-Wall");
        
        int httpCode = http.GET();
        if (httpCode == 200) {
            size_t contentLength = http.getSize();
            
            if (contentLength > _maxSize) {
                Serial.printf("Download error: Image size (%d) > Buffer (%d)\n", contentLength, _maxSize);
                http.end();
                client.stop(); 
                return false;
            }

            WiFiClient* stream = http.getStreamPtr();
            _lastImageSize = 0;
            
            unsigned long startMillis = millis();
            while (http.connected() && (_lastImageSize < contentLength || contentLength == -1)) {
                size_t avail = stream->available();

                
                if (avail) {
                    // Read data directly into our persistent buffer
                    size_t read = stream->readBytes(_buffer + _lastImageSize, 
                                                   min(avail, (size_t)(_maxSize - _lastImageSize)));
                    _lastImageSize += read;
                    startMillis = millis(); // Reset timeout as long as we get data
                } else {
                    if (millis() - startMillis > 5000) { // 5s timeout during data transfer
                        Serial.println("Download timeout during stream!");
                        break;
                    }
                    delay(1);
                }
                yield(); // Keep WiFi and Watchdog happy
            }
            http.end();
            return (_lastImageSize > 0);
        }
        
        Serial.printf("HTTP GET Failed, error: %s\n", http.errorToString(httpCode).c_str());
        http.end();
        client.stop(); 
        return false;
    }

    // Getters
    uint8_t* getBuffer() { return _buffer; }
    size_t getSize() { return _lastImageSize; }
};

// Create the global instance
 
 
// ===== GLOBAL   (Allocated once, prevents fragmentation) =====
ImageDownloader defrag;

DynamicJsonDocument jsonDoc(10240); 

////////////////////////////////////////////






 
// ==========

// Enable watchdog monitoring
#include "esp_system.h"
#include "esp_task_wdt.h"

// Track reboot reason
void printRebootReason() {
    esp_reset_reason_t reason = esp_reset_reason();
    Serial.print("Last reboot reason: ");
    switch (reason) {
        case ESP_RST_POWERON:   Serial.println("Power-on"); break;
        case ESP_RST_SW:        Serial.println("Software reset"); break;
        case ESP_RST_PANIC:     Serial.println("Exception/panic"); break;
        case ESP_RST_INT_WDT:   Serial.println("Interrupt watchdog"); break;
        case ESP_RST_TASK_WDT:  Serial.println("Task watchdog"); break;
        case ESP_RST_WDT:       Serial.println("Other watchdog"); break;
        case ESP_RST_DEEPSLEEP: Serial.println("Deep sleep"); break;
        case ESP_RST_BROWNOUT:  Serial.println("Brownout (low voltage!)"); break;
        case ESP_RST_SDIO:      Serial.println("SDIO reset"); break;
        default:                Serial.println("Unknown"); break;
    }
}
 

void cleanText(const char* input, char* output, size_t maxLen) {
    size_t out_idx = 0;
    size_t in_len = strlen(input);

    for (size_t i = 0; i < in_len && out_idx < maxLen - 1; ) {
        uint8_t c = (uint8_t)input[i];

        if (c <= 0x7F) { // ASCII
            output[out_idx++] = (c >= 32) ? (char)c : ' ';
            i++;
        } else { // Multi-byte UTF8 sequences
            output[out_idx++] = ' ';
            if ((c & 0xE0) == 0xC0) i += 2;
            else if ((c & 0xF0) == 0xE0) i += 3;
            else if ((c & 0xF8) == 0xF0) i += 4;
            else i++;
        }
    }
    output[out_idx] = '\0';
}



void urlEncode(const char* input, char* output, size_t maxLen) {
    const char* hex = "0123456789ABCDEF";
    size_t outIdx = 0;
    
    for (size_t i = 0; input[i] && outIdx < maxLen - 4; i++) {
        unsigned char c = input[i];
        
        if ((c >= 'A' && c <= 'Z') || 
            (c >= 'a' && c <= 'z') || 
            (c >= '0' && c <= '9') ||
            c == '-' || c == '_' || c == '.' || c == '~' ||
            c == '/' || c == ':') {
            output[outIdx++] = c;
        } else {
            output[outIdx++] = '%';
            output[outIdx++] = hex[c >> 4];
            output[outIdx++] = hex[c & 0x0F];
        }
    }
    output[outIdx] = '\0';
}


void showStatus(const char* msg, uint16_t color) {
    // Clear ONLY the bottom 8 pixels (y = 56 to 63)
    display->fillRect(0, 56, 64, 8, 0); 
    display->setTextSize(1);
    display->setCursor(2, 56); // Move cursor to the bottom row
    display->setTextColor(color);
    display->print(msg);
    
    Serial.print("STATUS: "); Serial.println(msg);
}



 
 
 
void drawJPEG() {
    uint8_t* buf = defrag.getBuffer();
    size_t sz = defrag.getSize();

    if (!buf || sz == 0) {
        Serial.println("Draw Error: No image in buffer");
        return;
    }

    if (!JpegDec.decodeArray(buf, sz)) {
        Serial.println("JPEG Decode failed (Corrupt image?)");
        return;
    }
    
    uint32_t lastYield = millis();
    int blockCount = 0;
    while (JpegDec.read()) {
        uint16_t* pixels = JpegDec.pImage;
        int baseX = JpegDec.MCUx * JpegDec.MCUWidth;
        int baseY = JpegDec.MCUy * JpegDec.MCUHeight;
        
        display->drawRGBBitmap(baseX, baseY, pixels, JpegDec.MCUWidth, JpegDec.MCUHeight);

        // 🟢 watchdog feed every 5ms
    if (millis() - lastYield > 5) {
      yield();
      lastYield = millis();
    }
        
        // Feed watchdog every 8 blocks to prevent timeout
        if (++blockCount % 8 == 0) {
            esp_task_wdt_reset();
        }
        
        yield();
    }
    JpegDec.abort();
}


void drawJPEG16color() {
    uint8_t* buf = defrag.getBuffer();
    size_t sz = defrag.getSize();

    if (!buf || sz == 0) {
        Serial.println("Draw Error: No image in buffer");
        return;
    }

    if (!JpegDec.decodeArray(buf, sz)) {
        Serial.println("JPEG Decode failed");
        return;
    }

    const int maxW = display->width();   // 64
    const int maxH = display->height();  // 64

    // IMPORTANT: use swapped bytes for HUB75
    while (JpegDec.readSwappedBytes()) {

        int x = JpegDec.MCUx * JpegDec.MCUWidth;
        int y = JpegDec.MCUy * JpegDec.MCUHeight;

        if (x >= maxW || y >= maxH) continue;

        int w = JpegDec.MCUWidth;
        int h = JpegDec.MCUHeight;

        if (x + w > maxW) w = maxW - x;
        if (y + h > maxH) h = maxH - y;

        display->drawRGBBitmap(
            x,
            y,
            JpegDec.pImage,
            w,
            h
        );

        static uint8_t yd = 0;
        if ((yd++ & 3) == 0) yield();
    }

    JpegDec.abort();
}




bool downloadImage(const char* url) {
    if (WiFi.status() != WL_CONNECTED) return false;
    if (!url || strlen(url) < 10) {
        Serial.println("Download aborted: Invalid URL");
        return false;
    }

    WiFiClientSecure client;
    client.setInsecure();                 // or setCACert(...)
    client.setTimeout(8000);  

    if (defrag.download(url)) {
        // Double check we actually got data
        if (defrag.getSize() > 100) { 
            return true;
        }
    }
    return false;
}


 


 void triggerServerUpdate() {
    Serial.println(">>> Starting Server Update...");
    showStatus("Updating", 0xFFE0); // Yellow "Updating" at bottom

    if (WiFi.status() != WL_CONNECTED) {
        showStatus("NO WIFI", 0xF800);
        return;
    }
    
    char ncUrl[256];
    snprintf(ncUrl, sizeof(ncUrl), "%s?nc=%lu", updateUrl, millis());
    
    HTTPClient http;
    http.useHTTP10(true);///////////////
    http.begin(ncUrl);
    http.setTimeout(20000); 
    yield();
    
    int httpCode = http.GET();
    
    if (httpCode == 200) { 
        showStatus("Done!", 0x07E0); // Green "Done!" at bottom
        Serial.println("Update.php finished successfully");
        delay(1000); 
    } else {
        char errMsg[32];
        snprintf(errMsg, sizeof(errMsg), "ERR %d", httpCode);
        showStatus(errMsg, 0xF800);
        delay(3000);
    }
    http.end();
}




void fetchPlaylist() {
    showStatus("GET JSON...");
    if (WiFi.status() != WL_CONNECTED) return;
    
    HTTPClient http;
    http.begin(playlistUrl);
    http.setTimeout(15000);
    Serial.println("Fetching playlist...");
    esp_task_wdt_reset(); 

    int httpCode = http.GET();

    
    
    if (httpCode == 200) {
        jsonDoc.clear();
        esp_task_wdt_reset();
        DeserializationError error = deserializeJson(jsonDoc, http.getStream());

        
        if (!error) {
            // Reset count and clear old data
            int found = jsonDoc.size();
            artCount = 0; 
            
            for (int i = 0; i < found && i < MAX_COLLECTION; i++) {
                const char* url = jsonDoc[i]["url"];
                // Only add to collection if URL is not empty
                if (url && strlen(url) > 10) {
                    strlcpy(collection[artCount].url, url, 250);
                    strlcpy(collection[artCount].artist, jsonDoc[i]["artist"] | "Unknown", 60);
                    strlcpy(collection[artCount].title, jsonDoc[i]["title"] | "Untitled", 60);
                    strlcpy(collection[artCount].price, jsonDoc[i]["price"] | "", 30);
                    artCount++;
                    if(artCount>=10) { artCount=10; break; }
                }
            }
            Serial.printf("Playlist: Validated %d valid entries\n", artCount);
        }
        yield();
    }
    http.end();
    esp_task_wdt_reset();
}


 
void displayNext() {
    Serial.println("--- displayNext() called ---");
    esp_task_wdt_reset(); // Feed at start

    // If collection is empty, try to load it
    if (artCount == 0) {
        Serial.println("Collection empty, fetching fresh data...");
        esp_task_wdt_reset();
        
        triggerServerUpdate();
        esp_task_wdt_reset(); // Feed after server update
        
        fetchPlaylist();
        esp_task_wdt_reset(); // Feed after playlist fetch
        
        // NOW check if we actually got art
        if (artCount == 0) {
            Serial.println("ERROR: Still no art after update!");
            showStatus("NO ART", 0xF800);
            delay(5);
            return;
        }
        
        Serial.printf("SUCCESS: Loaded %d artworks\n", artCount);
        currentIndex = -1; // Reset to -1 so first increment makes it 0
    }

    bool success = false;
    int attempts = 0;

    while (!success && attempts < artCount) {
        esp_task_wdt_reset(); // Feed each iteration
        
        currentIndex++;
        attempts++;

        if (currentIndex >= artCount) {
            Serial.println("End of playlist, refreshing...");
            esp_task_wdt_reset();
            
            triggerServerUpdate();
            esp_task_wdt_reset();
            
            fetchPlaylist();
            esp_task_wdt_reset();
            
            currentIndex = 0;
            if (artCount == 0) return;
        }

        // Prepare text
        char rawText[256];
        snprintf(rawText, sizeof(rawText), "%s by %s [%s]", 
                 collection[currentIndex].title, 
                 collection[currentIndex].artist, 
                 collection[currentIndex].price);
        cleanText(rawText, scrollText, sizeof(scrollText));

        // Show loading info at bottom bar only
        char loadMsg[16];
        snprintf(loadMsg, sizeof(loadMsg), "Load %d", currentIndex + 1);
        showStatus(loadMsg, 0x07E0);
        
        Serial.printf("Attempting to download: %s\n", collection[currentIndex].url);
        esp_task_wdt_reset();

        // Try to download and display the image
        if (downloadImage(collection[currentIndex].url)) {
            Serial.println("Download successful, rendering...");
            esp_task_wdt_reset(); // Feed before rendering
            
            display->fillScreen(0); // Clear screen for new image
            esp_task_wdt_reset();
            
            drawJPEG(); // or drawJPEG16color()
            esp_task_wdt_reset(); // Feed after rendering
            
            success = true;
            Serial.printf("Image %d displayed successfully\n", currentIndex);
        } else {
            Serial.printf("Failed to download image %d, trying next...\n", currentIndex);
            showStatus("Skip...", 0xF800);
            delay(5);
        }
        
        yield();
    }

    if (!success) {
        Serial.println("CRITICAL: Failed to load ANY images after trying all!");
        showStatus("ALL FAIL", 0xF800);
        delay(5);
        artCount = 0; // Force refresh on next call
    } else {
        // Image successfully displayed
        imageShownTime = millis();
        textActive = false;
        lastSwitch = millis();
        Serial.printf("Display complete. Free heap: %d bytes\n", ESP.getFreeHeap());
    }
    
    esp_task_wdt_reset(); // Feed at end
}


void updateBrightness() { 
    /*
    static uint8_t lastB = 0;
    uint8_t currentB = 254; 
    if (currentB != lastB) {
        display->setBrightness8(currentB);
        lastB = currentB;
    }
        */


        yield();
}

void saveCurrentOriginal() {
    if (artCount == 0 || currentIndex >= artCount || currentIndex < 0) {
        Serial.println("Cannot save: no valid artwork");
        return;
    }
    
    // Check if display is initialized
    if (!display) {
        Serial.println("Display not initialized");
        return;
    }
    
    // Clear bottom bar
    display->fillRect(0, 56, 64, 8, 0); 
    display->setCursor(1, 56);
    display->setTextColor(0xFFE0);
    display->print("Saving...");
    Serial.println("Saving...");

    if (WiFi.status() != WL_CONNECTED) {
        display->fillRect(0, 56, 64, 8, 0);
        display->setCursor(1, 56);
        display->setTextColor(0xF800);
        display->print("NO WIFI");
        delay(2000);
        return;
    }

    // URL encode
    char encodedUrl[512];
    urlEncode(collection[currentIndex].url, encodedUrl, sizeof(encodedUrl));
    
    char savePath[600];
    snprintf(savePath, sizeof(savePath), 
             "https://paradox.ovh/led-art/save.php?url=%s", encodedUrl);
    
    HTTPClient http;
    http.begin(savePath);
    http.setTimeout(10000);
    int httpCode = http.GET();
    http.end();

    display->fillRect(0, 56, 64, 8, 0);
    display->setCursor(1, 56);
    if (httpCode == 200) {
        display->setTextColor(0x07E0);
        display->print("Saved!");
        Serial.println("Saved!");
    } else {
        display->setTextColor(0xF800);
        display->printf("ERR %d", httpCode);
        Serial.printf("Error: %d\n", httpCode);
    }

    delay(2000);
    display->fillRect(0, 56, 64, 8, 0); 
}








///////////////////////////////////////////////////////////
bool Wi() {
    bool hasCredentials = (strlen(webConfig.ssid) > 0 && 
                          strcmp(webConfig.ssid, "") != 0);
    bool wifiConnected = false;
    int maxAttempts = 3;
    
    if (hasCredentials) {
        for (int attempt = 1; attempt <= maxAttempts && !wifiConnected; attempt++) {
            Serial.printf("\n[Attempt %d/%d] Connecting to: %s\n", 
                         attempt, maxAttempts, webConfig.ssid);
            
            char statusMsg[16];
            snprintf(statusMsg, sizeof(statusMsg), "WiFi %d/%d", attempt, maxAttempts);
            showStatus(statusMsg, 0xFFE0);
            
            WiFi.mode(WIFI_STA);
            WiFi.begin(webConfig.ssid, webConfig.password);  // Use webConfig, not ssid/password
            
            int retries = 0;
            while (WiFi.status() != WL_CONNECTED && retries < 20) {
                delay(500);
                Serial.print(".");
                retries++;
                esp_task_wdt_reset();
            }
            
            if (WiFi.status() == WL_CONNECTED) {
                wifiConnected = true;
                Serial.println("\n✓ Connected!");
            } else {
                Serial.println("\n✗ Failed");
                WiFi.disconnect(true);
                delay(1000);
            }
        }
    }
    
    if (!wifiConnected) {
        Serial.println("\n╔═════════════════════════════════════╗");
        Serial.println("║  WiFi Connection Failed!          ║");
        Serial.println("║  Starting Access Point Mode...    ║");
        Serial.println("╚═════════════════════════════════════╝");
        
        resetWiFiConfig();
        showStatus("AP MODE", 0xF800);
        delay(1000);
        
        WiFi.mode(WIFI_AP);
        WiFi.softAP("TEZ", "");  // Open AP, no password
        
        IPAddress apIP = WiFi.softAPIP();
        
        Serial.printf("\nAP Started!\n");
        Serial.printf("SSID: TEZ (no password)\n");
        Serial.printf("IP: %s\n\n", apIP.toString().c_str());
        
        // Display AP info
        display->fillScreen(0);
        display->setTextSize(1);
        display->setTextColor(0xFFE0);
        display->setCursor(1, 2);
        display->print("WiFi:TEZ");
        display->setTextColor(0xFFFF);
        display->setCursor(1, 22);
        display->print(apIP.toString().c_str());
        
        // Blink to get attention
        for (int i = 0; i < 3; i++) {
            delay(300);
            display->setBrightness8(100);
            delay(300);
            display->setBrightness8(webConfig.brightness);
        }
        
    } else {
        Serial.printf("\n✓ Connected to WiFi: %s\n", webConfig.ssid);
        Serial.printf("   IP: %s\n", WiFi.localIP().toString().c_str());
        Serial.printf("   Signal: %d dBm\n\n", WiFi.RSSI());
        
        showStatus("WiFi OK!", 0x07E0);
        delay(1000);
        
        display->fillScreen(0);
        display->setTextSize(1);
        display->setTextColor(0x07E0);
        display->setCursor(8, 15);
        display->print("CONNECTED");
        display->setTextColor(0x07FF);
        display->setCursor(4, 30);
        display->print(webConfig.ssid);
        display->setTextColor(0xFFFF);
        display->setCursor(2, 45);
        display->print(WiFi.localIP().toString().c_str());
        delay(2000);
    }

    // Setup web server
    setupWebServer(&server);
    return wifiConnected;
}
 /////////////////////////////////////////////





void setup() {
    Serial.begin(115200);
    Serial.println("\n\n=== ESP32 Tezos Art Wall ===");


    //esp32 memory test allocation
//////////////////////////////////////////
    // Try allocating 250KB
    /*
    uint8_t* testBuf = (uint8_t*)malloc(250000);
    if (testBuf) {
        Serial.println("✅ 250KB malloc SUCCESS - could handle 128×128 hub75  4 hub");
        free(testBuf);
    } else {
        Serial.println("❌ 250KB malloc FAILED - cannot handle 128×128");
    }
    
    Serial.printf("Free heap: %d\n", ESP.getFreeHeap());
    */
////////////////////////////////////////////
    
Serial.println("Init defrag..."); 
Serial.flush();



/////////////////////////////////////////////////////
// resetWiFiConfig();  //password user




//////////////////////////////////////////////////////////





///////////////////////////////////////////////
   printRebootReason(); // See WHY it rebooted
    
    // Print memory info
    Serial.printf("Total heap: %d bytes\n", ESP.getHeapSize());
    Serial.printf("Free heap: %d bytes\n", ESP.getFreeHeap());
    Serial.printf("PSRAM: %s\n", psramFound() ? "YES" : "NO");
    
    // ===== WATCHDOG CONFIGURATION =====
    // Increase watchdog timeout to 30 seconds (default is 5s)
    esp_task_wdt_init(30, true);
    esp_task_wdt_add(NULL); // Add current task to watchdog
    Serial.println("Watchdog: 30s timeout enabled");
/////////////////////////////////////////////////////////////////

  // ===== LOAD WEB CONFIGURATION =====
     strlcpy(webConfig.ssid, "user", 32);
    strlcpy(webConfig.password, "pass", 64);
    strlcpy(webConfig.playlistUrl, "https://paradox.ovh/led-art/nfts.json", 128);
    strlcpy(webConfig.updateUrl, "https://paradox.ovh/led-art/update.php", 128);
    webConfig.rotateTimer = 40000;
    webConfig.textDelay = 12000;
    webConfig.brightness = 255;

    handleLoadConfig();
    // Use loaded config for WiFi
    if (strlen(webConfig.ssid) > 0) {
        strlcpy((char*)ssid, webConfig.ssid, 32);
        strlcpy((char*)password, webConfig.password, 64);
    }
    if (strlen(webConfig.playlistUrl) > 0) {
       // strlcpy(playlistUrl, webConfig.playlistUrl, 128);
    }
    if (strlen(webConfig.updateUrl) > 0) {
      //  strlcpy(updateUrl, webConfig.updateUrl, 128);
    }

 

    

  /////////////////////////////////////////////    
    // Initialize image buffer
    if (!defrag.begin(80000)) {
        Serial.println("FATAL: Memory Init Failed");
        while(1) { 
            delay(1000);
            esp_task_wdt_reset(); // Keep feeding watchdog even in error
        }
    }
    /////////////////////////////////////////////////////////

    HUB75_I2S_CFG::i2s_pins pins = {R1_PIN, G1_PIN, B1_PIN, R2_PIN, G2_PIN, B2_PIN, A_PIN, B_PIN, C_PIN, D_PIN, E_PIN, LAT_PIN, OE_PIN, CLK_PIN};
    HUB75_I2S_CFG mxconfig(PANEL_RES_X, PANEL_RES_Y, PANEL_CHAIN, pins);
    mxconfig.clkphase = 1;
    mxconfig.latch_blanking = 4;

    display = new MatrixPanel_I2S_DMA(mxconfig);
    display->begin();
    display->setBrightness8(100);
    display->fillScreen(0);

    pinMode(BUTTON_SAVE_PIN, INPUT_PULLUP);

     Wi();

      
  

    ElegantOTA.begin(&server);
    server.begin();
}




void loop() {
    static unsigned long lastWatchdogFeed = 0;
    static unsigned long lastHeapCheck = 0;
    static uint32_t minHeap = 0xFFFFFFFF;

    // ===== CRITICAL: Feed watchdog FIRST, every loop =====
    if (millis() - lastWatchdogFeed > 1000) { // Every 1 second (was 5s - too slow!)
        esp_task_wdt_reset();
        lastWatchdogFeed = millis();
    }

    // Monitor heap every 30 seconds
    if (millis() - lastHeapCheck > 30000) {
        uint32_t freeHeap = ESP.getFreeHeap();
        if (freeHeap < minHeap) {
            minHeap = freeHeap;
            Serial.printf("⚠️  New min heap: %d bytes\n", minHeap);
        }
        Serial.printf("Heap: %d bytes (min: %d)\n", freeHeap, minHeap);
        lastHeapCheck = millis();
    }

    // Main rotation timer
    if (millis() - lastSwitch > rotateTimer || lastSwitch == 0) {
        lastSwitch = millis();
        esp_task_wdt_reset(); // Feed before heavy operation
        displayNext();
        
        // UDP broadcast (optional)
        if (udp.beginPacket("255.255.255.255", 4210)) {
            udp.write(currentIndex);
            udp.endPacket();
        }
        esp_task_wdt_reset(); // Feed after heavy operation
    }

    // Skip text animation if no images loaded
    if (artCount == 0) {
        delay(100);
        return;
    }

    // Wait before showing text
    if (millis() - imageShownTime < sec15) {
        yield();
        delay(10); // Reduced from 30ms - allows more responsive watchdog feeding
        return;
    }

    // Start text animation
    if (!textActive) {
        textActive = true;
        textX = PANEL_RES_X;
    }

    // ===== FIXED: Animate scrolling text WITHOUT blocking =====
    if (textActive) {
        static unsigned long lastMove = 0;
        
        // Only update display every 30ms (smooth scrolling speed)
        if (millis() - lastMove > 30) {
            lastMove = millis();
            
            // Clear only the text strip
            display->fillRect(0, 56, 64, 8, 0); 
            display->setTextColor(display->color565(0, 255, 0));
            display->setCursor(textX, 56);
            display->print(scrollText);
            
            textX -= 1;

            // Calculate exact width: approx 6 pixels per character
            int textWidth = strlen(scrollText) * 6;
            if (textX < -textWidth) {
                textX = PANEL_RES_X; // Reset to right side
            }
            
            // Feed watchdog during animation
            esp_task_wdt_reset();
        }
    }

    // Button handling with proper debouncing
    static unsigned long lastButtonPress = 0;
    if (digitalRead(BUTTON_SAVE_PIN) == LOW) {
        if (!savePressed && (millis() - lastButtonPress > 500)) {
            savePressed = true;
            lastButtonPress = millis();
            esp_task_wdt_reset(); // Feed before potentially slow operation
            saveCurrentOriginal();
            esp_task_wdt_reset(); // Feed after
        }
    } else {
        savePressed = false;
    }

    // CRITICAL: Always yield at end of loop to prevent blocking
    yield();
    
    // Small delay to prevent tight loop (reduces CPU usage)
    delay(5);
}

  