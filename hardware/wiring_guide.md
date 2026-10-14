# ESP32 + RFID (RC522) + OLED — Wiring & Configuration Guide

This guide covers the **optional** hardware part of the Smart Medical-Waste System.
The web application works fully without any hardware (use the software simulation
in `collector/verify_rfid.php`).

## 1. Bill of materials

| Part | Example | Notes |
|------|---------|-------|
| Microcontroller | ESP32 DevKitC / WROOM-32 | 3.3 V logic |
| RFID reader | MFRC522 (RC522) module | **3.3 V only** — never 5 V |
| RFID tags | Mifare Classic 13.56 MHz cards/fobs | RC522 reads the UID |
| Display | SSD1306 128×64 OLED (I²C) | 3.3 V |
| Jumper wires | — | keep SPI leads short |

> An RFID UID is **not** cryptographic proof of identity — UID-only tags can be
> cloned. The server therefore always validates the UID against the registry and
> the request state. Do not treat a verified scan as tamper-proof.

## 2. Wiring

### RC522 → ESP32 (SPI / VSPI)

| RC522 pin | ESP32 GPIO |
|-----------|-----------|
| SDA / SS  | GPIO 5    |
| SCK       | GPIO 18   |
| MOSI      | GPIO 23   |
| MISO      | GPIO 19   |
| RST       | GPIO 4    |
| 3.3V      | 3V3       |
| GND       | GND       |

### SSD1306 OLED → ESP32 (I²C)

| OLED pin | ESP32 GPIO |
|----------|-----------|
| SDA      | GPIO 21   |
| SCL      | GPIO 22   |
| VCC      | 3V3       |
| GND      | GND       |

> The MFRC522 and the OLED use different buses (SPI vs I²C) so no pins conflict.
> If your board differs, edit the `#define` pin constants at the top of the sketch.

## 3. Arduino IDE libraries

Install via **Library Manager**:

- **MFRC522** by GithubCommunity (a.k.a. "MFRC522")
- **Adafruit SSD1306** by Adafruit
- **Adafruit GFX Library** by Adafruit
- **ArduinoJson** (optional, only if you want real JSON parsing)

Board support: install **esp32 by Espressif Systems** via Boards Manager, then
select *ESP32 Dev Module*.

## 4. Configure the sketch

Open `esp32_rfid_oled.ino` and edit the configuration block:

```cpp
const char* WIFI_SSID   = "YOUR_HOTSPOT_NAME";
const char* WIFI_PASS   = "YOUR_HOTSPOT_PASSWORD";
const char* SERVER_HOST = "192.168.1.100";   // your PC's LAN/hotspot IP (NOT localhost)
const int   SERVER_PORT = 8090;
const char* DEVICE_CODE = "ESP32-01";
const char* DEVICE_TOKEN = "paste-token-from-admin-hardware-page";
const char* REQUEST_NO  = "";                 // optional default request number
```

- Find your PC IP: run `ipconfig` (look for the Wi-Fi adapter IPv4 address), or
  open **Admin → Hardware & Devices** which shows the detected base URL.
- Create the device in **Admin → Hardware & Devices → Register a device** and copy
  the token **once** (only hashed server-side).
- **Never** put database credentials in the firmware. The firmware only knows the
  device code + API token.

## 5. How it talks to the server

1. Connects to Wi-Fi and prints the IP on Serial + OLED.
2. Scans for a tag. On a tag, reads the UID.
3. Sends `POST http://SERVER_HOST:SERVER_PORT/api/rfid_verify.php` with headers
   `X-Device-Code` and `X-Device-Token` and body `tag_uid=<UID>&request_no=<REQ>`.
4. The PHP backend validates the device, the tag, the container and the request,
   then returns JSON containing an `oled` field.
5. The OLED shows **VERIFIED**, **NOT REGISTERED**, **COLLECTION DENIED**,
   **WRONG CONTAINER** or **AUTH FAILED**.
6. The result is stored in `rfid_verification_logs` and shown on the dashboards.

## 6. Network checklist

- ESP32 and the PC must be on the **same** Wi-Fi / hotspot.
- Disable **AP/client isolation** on the hotspot, or the ESP32 cannot reach the PC.
- Use the PC's LAN IP, never `localhost`/`127.0.0.1`.
- If Windows Firewall blocks the built-in PHP server, allow the port
  (e.g. `netsh advfirewall firewall add rule name="MWS PHP" dir=in action=allow protocol=TCP localport=8090`).
- Make sure the server is bound to all interfaces, not only `127.0.0.1`.

## 7. Troubleshooting

| Symptom | Fix |
|---------|-----|
| OLED shows `AUTH FAILED` | Wrong device code/token, or the device is inactive. Rotate the token in admin. |
| `WiFi connect failed` | Wrong SSID/password, or the ESP32 is out of range. |
| No response / timeout | Wrong IP/port, firewall, or client isolation on the hotspot. |
| `NOT REGISTERED` | The tag UID is not mapped in Admin → RFID Tags. |
| `WRONG CONTAINER` | The scanned tag's container does not match the request. |
| Nothing on OLED | Check I²C address (usually 0x3C) and SDA/SCL pins. |
