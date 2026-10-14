/*
 * Smart Medical-Waste System - ESP32 RFID + OLED verification client
 * ------------------------------------------------------------------
 * Reads an RFID tag UID with an RC522 reader and asks the PHP backend
 * (api/rfid_verify.php) to verify it, then shows the result on an SSD1306 OLED.
 *
 * Libraries required (Arduino Library Manager):
 *   - MFRC522        (GithubCommunity)
 *   - Adafruit SSD1306 (Adafruit)
 *   - Adafruit GFX    (Adafruit)
 *
 * Board: "ESP32 Dev Module"
 *
 * NOTE: This firmware contains NO database credentials. It authenticates with a
 * device code + API token that an administrator generates in the web app.
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

// --------------------------------------------------------------------------
// CONFIGURATION - edit these values
// --------------------------------------------------------------------------
const char* WIFI_SSID    = "YOUR_HOTSPOT_NAME";
const char* WIFI_PASS    = "YOUR_HOTSPOT_PASSWORD";
const char* SERVER_HOST  = "192.168.1.100";   // PC LAN/hotspot IP (NOT localhost)
const int   SERVER_PORT  = 8090;
const char* DEVICE_CODE  = "ESP32-01";
const char* DEVICE_TOKEN = "paste-token-from-admin-hardware-page";
const char* REQUEST_NO   = "";                 // optional default request number

// --------------------------------------------------------------------------
// Pins
// --------------------------------------------------------------------------
#define RST_PIN   4
#define SS_PIN    5
#define SCREEN_WIDTH  128
#define SCREEN_HEIGHT 64
#define OLED_RESET    -1
#define OLED_ADDR     0x3C

MFRC522 mfrc522(SS_PIN, RST_PIN);
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// --------------------------------------------------------------------------
void show(const char* line1, const char* line2 = "", const char* line3 = "") {
    display.clearDisplay();
    display.setTextSize(1);
    display.setTextColor(SSD1306_WHITE);
    display.setCursor(0, 0);
    display.println(line1);
    display.println();
    display.println(line2);
    display.println(line3);
    display.display();
}

void connectWiFi() {
    show("Connecting WiFi", WIFI_SSID, "");
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASS);
    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 20000) {
        delay(500);
        Serial.print(".");
    }
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println();
        Serial.print("WiFi connected. IP: ");
        Serial.println(WiFi.localIP());
        show("WiFi OK", WiFi.localIP().toString().c_str(), "");
    } else {
        Serial.println("\nWiFi FAILED");
        show("WiFi FAILED", "Check SSID/pass", "");
    }
}

// Extract a JSON string value for a given key (minimal parser, no libs needed).
String jsonValue(const String& json, const String& key) {
    String needle = "\"" + key + "\"";
    int k = json.indexOf(needle);
    if (k < 0) return "";
    int colon = json.indexOf(':', k + needle.length());
    if (colon < 0) return "";
    int firstQuote = json.indexOf('"', colon + 1);
    if (firstQuote < 0) return "";
    int secondQuote = json.indexOf('"', firstQuote + 1);
    if (secondQuote < 0) return "";
    return json.substring(firstQuote + 1, secondQuote);
}

void doVerify(String uid) {
    if (WiFi.status() != WL_CONNECTED) {
        connectWiFi();
    }
    String url = String("http://") + SERVER_HOST + ":" + SERVER_PORT + "/api/rfid_verify.php";
    String body = "tag_uid=" + uid;
    if (String(REQUEST_NO).length() > 0) {
        body += "&request_no=" + String(REQUEST_NO);
    }

    Serial.println("POST " + url + "  body=" + body);
    HTTPClient http;
    http.begin(url);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    http.addHeader("X-Device-Code", DEVICE_TOKEN[0] ? DEVICE_CODE : DEVICE_CODE);
    http.addHeader("X-Device-Token", DEVICE_TOKEN);
    http.setTimeout(8000);
    int code = http.POST(body);
    String payload = http.getString();
    http.end();

    Serial.printf("HTTP %d: %s\n", code, payload.c_str());

    String oled = jsonValue(payload, "oled");
    String msg  = jsonValue(payload, "message");
    if (oled.length() == 0) oled = (code == 200) ? "OK" : "ERROR";

    // Show the short OLED status and the tag UID.
    show(oled.c_str(), ("UID " + uid).c_str(), "");
    delay(1500);
    show("Ready", "Scan tag...", "");
}

void setup() {
    Serial.begin(115200);
    delay(200);

    Wire.begin(21, 22);                 // SDA, SCL
    if (!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDR)) {
        Serial.println("SSD1306 allocation failed");
    }
    display.clearDisplay();
    display.display();
    show("Medical Waste", "System booting", "");

    SPI.begin(18, 19, 23, 5);           // SCK, MISO, MOSI, SS
    mfrc522.PCD_Init();
    delay(200);
    Serial.println("RC522 ready");

    connectWiFi();
    show("Ready", "Scan tag...", "");
}

void loop() {
    if (WiFi.status() != WL_CONNECTED) {
        connectWiFi();
    }

    // Look for a new RFID tag.
    if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial()) {
        delay(150);
        return;
    }

    String uid = "";
    for (byte i = 0; i < mfrc522.uid.size; i++) {
        if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
        uid += String(mfrc522.uid.uidByte[i], HEX);
    }
    uid.toUpperCase();
    Serial.println("Tag UID: " + uid);

    show("Tag detected", uid.c_str(), "Verifying...");
    doVerify(uid);

    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();
    delay(500);
}
