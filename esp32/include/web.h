#ifndef WEB_H
#define WEB_H

#include <ESPAsyncWebServer.h>



struct Art {   
    char url[256]; 
    char artist[64]; 
    char title[64]; 
    char price[32]; 
    char original_uri[256];   
};

// Configuration structure
struct WebConfig {
    char ssid[32];
    char password[64];
    char playlistUrl[128];
    char updateUrl[128];
    char favoriteAddress[64];
    uint32_t rotateTimer;
    uint32_t textDelay;
    uint8_t brightness;
    bool showFavoritesOnly;
};

// Function declarations
void setupWebServer(AsyncWebServer* server);
void handleRoot(AsyncWebServerRequest *request);
void handleAPI(AsyncWebServerRequest *request);
void handleSetConfig(AsyncWebServerRequest *request);
void handleGetStatus(AsyncWebServerRequest *request);
void handleSaveConfig(AsyncWebServerRequest *request);
void handleLoadConfig();
void resetWiFiConfig();

// Global config (extern declaration)
extern WebConfig webConfig;

// HTML page stored in PROGMEM
extern const char CONFIG_PAGE[] PROGMEM;

#endif