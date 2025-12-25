<div align="center"> <img src="https://img.shields.io/badge/PlatformIO-Compatible-brightgreen?style=for-the-badge&logo=platformio" alt="PlatformIO"> <img src="https://img.shields.io/badge/ESP32-Compatible-blue?style=for-the-badge&logo=espressif" alt="ESP32"> <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License"> <img src="https://img.shields.io/badge/Version-1.0.0-orange?style=for-the-badge" alt="Version"> <img src="https://img.shields.io/badge/Arduino-Framework-yellow?style=for-the-badge&logo=arduino" alt="Arduino"> <img src="https://img.shields.io/badge/Tezos-Art%20Wall-009688?style=for-the-badge&logo=ethereum" alt="Tezos"> </div>
<div align="center"> 


Tezos Art Wall – ESP32 LED Matrix Display
This project turns an ESP32 with a 64×64 HUB75 LED matrix panel into a beautiful digital art wall that displays Tezos NFT artworks. It automatically cycles through a curated playlist, shows artwork metadata, and provides a full web-based control interface.
Features



 tezos-art-wall/
├── src/
│   ├── main.cpp
│   ├── web.cpp
│   └── web.h
├── include/
├── lib/
└── platformio.ini


# Tezos Art Wall – ESP32 LED Matrix Display

This project turns an ESP32 with a 64×64 HUB75 LED matrix panel into a beautiful digital art wall that displays Tezos NFT artworks. It automatically cycles through a curated playlist, shows artwork metadata, and provides a full web-based control interface.

## Features

- Displays JPEG images from a remote JSON playlist
- Automatic rotation with configurable timer
- Scrolling artwork title, artist, and price at the bottom
- Built-in web configuration panel (Wi-Fi setup, brightness, rotation time, etc.)
- Physical button to save current artwork as favorite (via server endpoint)
- Over-the-air (OTA) updates via ElegantOTA
- Access Point mode if Wi-Fi credentials are missing/invalid
- Robust memory management to prevent fragmentation and crashes
- Watchdog protection and heap monitoring
- Responsive, modern web UI

## Hardware Requirements

- ESP32 development board (with PSRAM recommended for smoother operation)
- 64×64 HUB75 RGB LED matrix panel (P3 or similar, 1/32 scan recommended)
- Level shifters (3.3V → 5V) for HUB75 signals (highly recommended)
- Stable 5V power supply (≥4A depending on brightness)
- Optional: Button connected to GPIO 0 (with pull-up) for manual save

## Wiring

Custom pin configuration used in the code:

| HUB75 Pin | ESP32 GPIO |
|-----------|------------|
| R1        | 17         |
| G1        | 18         |
| B1        | 8          |
| R2        | 3          |
| G2        | 2          |
| B2        | 10         |
| A         | 15         |
| B         | 11         |
| C         | 7          |
| D         | 4          |
| E         | 13         |
| LAT       | 6          |
| OE        | 12         |
| CLK       | 5          |

> **Important**: Use level shifters between ESP32 and panel to protect both devices.

## Installation & First Boot

1. Connect the LED panel and power the ESP32.
2. Flash the firmware using Arduino IDE, PlatformIO, or esptool.
3. On first boot (or after Wi-Fi reset):
   - The device will create an open Access Point named **TEZ**
   - The panel will display the AP name and IP address (usually `192.168.4.1`)
   - Connect your phone/computer to the **TEZ** Wi-Fi network
4. Open a browser and go to `http://192.168.4.1`
5. Use the web interface to configure your home Wi-Fi network.
6. The device will save credentials, restart, and connect to your Wi-Fi.

## Web Interface

After connecting to your Wi-Fi, access the control panel at the ESP32’s local IP (shown in serial monitor or during boot on the panel).

### Sections

- **Now Showing** – Current artwork info + buttons:
  - Next – Skip to next artwork
  - Save – Mark current artwork as favorite (calls server save.php)
  - Refresh – Force playlist reload
- **Display Settings** – Sliders for:
  - Rotation Time (5–300 seconds)
  - Brightness (16–255)
  - Text Scroll Delay (0–60 seconds)
- **WiFi Configuration** – Change or reset Wi-Fi settings
- **System Status** – Live stats: loaded artworks, free RAM, uptime, Wi-Fi signal

All changes take effect immediately (except Wi-Fi, which requires restart).

## Compilation (Arduino IDE)

1. Install **ESP32 board package** via Boards Manager.
2. Install required libraries via Library Manager:
   - ESPAsyncWebServer (by me-no-dev)
   - AsyncTCP
   - ElegantOTA
   - ESP32-HUB75-MatrixPanel-I2S-DMA (by mrfaptastic)
   - JPEGDecoder (by Bodmer)
   - ArduinoJson (by Benoit Blanchon)
3. Select your ESP32 board (e.g., ESP32 Dev Module).
4. Set **Partition Scheme** to one with OTA support (e.g., “Default 4MB with spiffs” or “Minimal SPIFFS”).
5. Set **PSRAM**: Enabled (if your board has it).
6. Upload the sketch.

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
board_build.partitions = default_8MB.csv  ; or any with OTA
board_build.psram = enabled               ; if available



