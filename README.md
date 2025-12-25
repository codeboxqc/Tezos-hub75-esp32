<div align="center"> <img src="https://img.shields.io/badge/PlatformIO-Compatible-brightgreen?style=for-the-badge&logo=platformio" alt="PlatformIO"> <img src="https://img.shields.io/badge/ESP32-Compatible-blue?style=for-the-badge&logo=espressif" alt="ESP32"> <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License"> <img src="https://img.shields.io/badge/Version-1.0.0-orange?style=for-the-badge" alt="Version"> <img src="https://img.shields.io/badge/Arduino-Framework-yellow?style=for-the-badge&logo=arduino" alt="Arduino"> <img src="https://img.shields.io/badge/Tezos-Art%20Wall-009688?style=for-the-badge&logo=ethereum" alt="Tezos"> </div>
<div align="center"> 


first connect to tez wifi
![a (1)](https://github.com/user-attachments/assets/690bbda8-0f4b-46ab-9331-7ee01af4cd8f)

validate user passowd wifi only once 192.168.4.1  browser / save
![a (2)](https://github.com/user-attachments/assets/05e85958-3e2b-4af0-af33-9a70f21032ad)

Tezos Art Wall – ESP32 LED Matrix Display
This project turns an ESP32 with a 64×64 HUB75 LED matrix panel into a beautiful digital art wall that displays Tezos NFT artworks. It automatically cycles through a curated playlist, shows artwork metadata, and provides a full web-based control interface.
Features



What You Need!
-ESP32
-HUB75
-Your Server to run the code



tezos fav gallery edit update.php edit 
$hardcoded_users = [
    "tz1PUc3oQk3PpVGYRWmgQ6JHuk6rrHkP7K1Z",


    ]

update code main.cpp
char ssid[32] = "user";
char password[64] = "pass";
char playlistUrl[128] = "https://yourserver.cc/led-art/nfts.json";
char updateUrl[128]   = "https://yourserver.cc/led-art/update.php";


# Tezos Art Wall – ESP32 LED Matrix NFT Display

A beautiful, automated digital art wall for 64×64 HUB75 RGB LED panels. Displays curated Tezos NFT artworks with metadata, controlled via an elegant built-in web interface.

![Tezos Art Wall](https://via.placeholder.com/800x400?text=Tezos+Art+Wall+Display)  
*(Example display – actual appearance depends on your panel and artwork)*

## Features

- Fetches and displays JPEG artworks from a remote JSON playlist
- Automatic rotation with adjustable timer (5–300 seconds)
- Scrolling bottom overlay with title, artist, and price
- Full web control panel (Wi-Fi setup, brightness, timers)
- Physical button (GPIO 0) to save current artwork as favorite
- ElegantOTA for wireless firmware updates
- Falls back to open Access Point ("TEZ") if Wi-Fi fails
- Robust memory handling (persistent buffer, watchdog protection)
- Modern, responsive web UI with live system stats

## Hardware Required

- ESP32 development board (PSRAM strongly recommended)
- 64×64 HUB75 RGB LED matrix panel
- 3.3V → 5V level shifters (recommended for all data lines)
- Stable 5V power supply (≥4A at full brightness)
- Optional: Button on GPIO 0 (with pull-up) for manual save

## Wiring (Custom Pinout Used)

| HUB75 | ESP32 GPIO |
|------|------------|
| R1   | 17         |
| G1   | 18         |
| B1   | 8          |
| R2   | 3          |
| G2   | 2          |
| B2   | 10         |
| A    | 15         |
| B    | 11         |
| C    | 7          |
| D    | 4          |
| E    | 13         |
| LAT  | 6          |
| OE   | 12         |
| CLK  | 5          |

## Quick Start

1. Flash the firmware (see Compilation below).
2. On first boot, the device creates an open Wi-Fi AP named **TEZ**.
3. Connect to it and visit `http://192.168.4.1`.
4. Configure your home Wi-Fi in the web interface.
5. Device restarts and connects – access the panel at its new local IP.

## Web Interface

- **Now Showing**: Current art + Next / Save / Refresh buttons
- **Display Settings**: Rotation time, brightness, text delay sliders
- **WiFi Configuration**: Change credentials or reset to AP mode
- **System Status**: Live stats (RAM, uptime, signal, etc.)

All settings update instantly (except Wi-Fi changes).

## Compilation (Arduino IDE)

### Required Libraries (install via Library Manager)
- ESP Async WebServer (me-no-dev)
- AsyncTCP
- ElegantOTA
- ESP32-HUB75-MatrixPanel-I2S-DMA (mrfaptastic)
- JPEGDecoder (Bodmer)
- ArduinoJson (Benoit Blanchon)

### Board Settings
- Board: ESP32 Dev Module (or your board)
- Partition Scheme: Any with OTA support
- PSRAM: Enabled (if available)

Upload and go!

## Compilation (PlatformIO)

```ini
[env:esp32dev]
platform = espressif32
board = esp32dev
framework = arduino
monitor_speed = 115200
lib_deps =
    me-no-dev/ESP Async WebServer
    me-no-dev/AsyncTCP
    ayushsharma82/ElegantOTA
    mrfaptastic/ESP32-HUB75-MatrixPanel-I2S-DMA
    bodmer/JPEGDecoder
    bblanchon/ArduinoJson
board_build.psram = enabled




## Important: Save/Favorite Button & PHP Server

The "Save" button (web UI and physical GPIO 0 button) calls a server endpoint:  
`https://your-server.com/led-art/save.php?url=ENCODED_IMAGE_URL`

This originally points to `https://paradox.ovh/led-art/save.php` (likely for marking favorites or logging).

**Do you need your own server?**  
- **No**, if you just want to display art (skip saving favorites).  
- **Yes**, if you want the Save button to work (e.g., add to your personal favorites list).

### Option 1: Disable Save Feature (No Server Needed)
Comment out or remove these parts in the code:
- In `saveCurrentOriginal()` function (main.cpp)
- The `/api/save` endpoint in web.cpp
- The "Save" button in the HTML

### Option 2: Set Up Your Own Simple PHP Server
1. Get web hosting with PHP support (e.g., shared hosting like Bluehost, Hostinger, or free options like 000webhost).
2. Create a folder (e.g., `/led-art/`).
3. Upload two files:

**save.php** (basic example – logs to a file):
```php
<?php
// Simple save.php - logs the saved URL to a file
$url = $_GET['url'] ?? 'no_url';

$file = 'favorites.txt';
$current = file_get_contents($file);
$current .= urldecode($url) . "\n";
file_put_contents($file, $current);

echo "Saved!";
?>


Compilation & Upload (Arduino IDE)

Install ESP32 board package (Boards Manager → esp32 by Espressif).
Install libraries (Sketch → Include Library → Manage Libraries):
ESPAsyncWebServer (me-no-dev)
AsyncTCP
ElegantOTA
ESP32-HUB75-MatrixPanel-I2S-DMA (mrfaptastic)
JPEGDecoder (Bodmer)
ArduinoJson (Benoit Blanchon)
Preferences (built-in)

Board settings:
Board: ESP32 Dev Module
PSRAM: Enabled (if your board has it)
Partition Scheme: Default with OTA

Copy the provided main.cpp and web.cpp into a new sketch.
Upload!

First Boot & Setup

Device creates open Wi-Fi AP: TEZ
Connect and visit http://192.168.4.1
Configure your home Wi-Fi
Device restarts, connects, and starts displaying art

Access web control at the device's local IP.
Customization

Change playlist URL in web UI
Max artworks: Edit MAX_COLLECTION (default 10)
Disable save: See above

Enjoy your personal Tezos NFT art wall! 🎨✨







