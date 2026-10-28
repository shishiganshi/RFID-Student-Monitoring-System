#include <TFT_eSPI.h>
#include <SPI.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Adafruit_GFX.h>
#include "Minecraft10pt7b.h"
#include <ArduinoJson.h>
#include "secrets.h"

// WiFi Config (credentials removed for scanning focus)
const char* ssid = "";          // Empty for scanning mode
const char* password = "";      // Empty for scanning mode
const char* serverUrl = "";     // Server URL removed for scanning focus

// Student Info Structure
struct StudentInfo {
    String name;
    String studentNumber;
    String department;
    String yearSection;
} currentStudent;

// TFT Display
TFT_eSPI tft = TFT_eSPI();

// Function declarations (kept)
void drawTextBubble(const char* msg);
void drawFace(bool happy, bool angry, const char* msg);
void drawHappyMochi(const char* msg);
void drawAngryMochi(const char* msg);
void drawIdleMochi(const char* msg);
void startAccessGranted();
void startAccessDenied();
void handleMessageStages(unsigned long now);
void setupWiFi();              // defined but not called (preserved)
void sendTagToServer(String tag);
void testServerConnection();
void displayNetworksOnce();   // single scan & display
void startNetworkScanner();   // set up scanning state

// --- RFID ---
#define RDM6300_RX_PIN 19
String tagBuffer = "";
bool readingTag = false;
const String validTag = ADMIN_RFID_TAG;

// --- LEDs & Buzzer ---
#define RED_LED1 25
#define RED_LED2 26
#define GREEN_LED 27
#define BUZZER 14

// --- Message stages ---
enum MessageStage {
  NONE,
  ACCESS_GRANTED_STAGE1,
  ACCESS_GRANTED_STAGE2,
  ACCESS_GRANTED_STAGE3,
  ACCESS_DENIED_STAGE1,
  ACCESS_DENIED_STAGE2
};

MessageStage currentMessageStage = NONE;
unsigned long stageStartTime = 0;
const unsigned long stageDuration = 3000;
const unsigned long deniedDuration = 5000;
unsigned long accessDeniedLoopStart = 0;

// --- Idle ---
unsigned long lastBlink = 0;
bool eyesClosed = false;
unsigned long lastIdleEvent = 0;
int idleMode = 0;

// --- Face position ---
int centerX, centerY;
int eyeWidth = 36;
int eyeHeight = 42;
int eyeOffsetX = 60;
int eyeOffsetY = -30;
int mouthYoffset = 65;

// --- Network scan refresh ---
const unsigned long SCAN_INTERVAL_MS = 20000UL; // scan every 20 seconds
unsigned long lastScanTime = 0;
int lastScanCount = 0;
int wifiPerPage = 8; // number of network lines per TFT page

// ===================================================
// Draw text bubble
void drawTextBubble(const char* msg) {
  tft.setFreeFont(&Minecraft10pt7b);
  tft.setTextColor(TFT_BLACK, TFT_WHITE);
  int16_t w = tft.textWidth(msg);
  int16_t h = tft.fontHeight();
  int16_t x = (tft.width() - w) / 2;
  int16_t y = 30;

  tft.fillRoundRect(x - 10, y - h, w + 20, h + 20, 8, TFT_WHITE);
  tft.drawRoundRect(x - 10, y - h, w + 20, h + 20, 8, TFT_BLACK);
  tft.setCursor(x, y);
  tft.print(msg);
}

// ===================================================
// Draw Mochi face & wrappers
void drawFace(bool happy=false, bool angry=false, const char* msg="") {
  tft.fillScreen(TFT_WHITE);

  int leftEyeX = centerX - eyeOffsetX;
  int rightEyeX = centerX + eyeOffsetX;
  int eyeY = centerY + eyeOffsetY;

  if (!eyesClosed) {
    tft.fillRoundRect(leftEyeX - eyeWidth/2, eyeY - eyeHeight/2, eyeWidth, eyeHeight, 12, TFT_BLACK);
    tft.fillRoundRect(rightEyeX - eyeWidth/2, eyeY - eyeHeight/2, eyeWidth, eyeHeight, 12, TFT_BLACK);
  } else {
    tft.fillRect(leftEyeX - 18, eyeY, 36, 5, TFT_BLACK);
    tft.fillRect(rightEyeX - 18, eyeY, 36, 5, TFT_BLACK);
  }

  if (happy) {
    tft.drawArc(centerX, centerY + mouthYoffset, 18, 12, 200, 340, TFT_BLACK, TFT_WHITE);
  } else if (angry) {
    tft.drawLine(centerX - 20, centerY + mouthYoffset, centerX + 20, centerY + mouthYoffset, TFT_BLACK);
  } else if (idleMode == 3) {
    tft.fillCircle(centerX, centerY + mouthYoffset, 10, TFT_BLACK);
  } else {
    tft.drawArc(centerX, centerY + mouthYoffset, 12, 8, 200, 340, TFT_BLACK, TFT_WHITE);
  }

  if (idleMode == 4) {
    tft.fillCircle(leftEyeX, eyeY + 30, 10, TFT_RED);
    tft.fillCircle(rightEyeX, eyeY + 30, 10, TFT_RED);
  }

  if (msg[0] != '\0') {
    drawTextBubble(msg);
  }
}
void drawHappyMochi(const char *msg) { drawFace(true, false, msg); }
void drawAngryMochi(const char *msg) { drawFace(false, true, msg); }
void drawIdleMochi(const char *msg="") { drawFace(false, false, msg); }

// ===================================================
// RFID handlers
void startAccessGranted() {
  drawHappyMochi("Access Granted!");
  digitalWrite(GREEN_LED, HIGH);

  digitalWrite(BUZZER, HIGH);
  delay(200);
  digitalWrite(BUZZER, LOW);

  currentMessageStage = ACCESS_GRANTED_STAGE1;
  stageStartTime = millis();
}

void startAccessDenied() {
  drawAngryMochi("INTRUDER ALERT!!!");
  currentMessageStage = ACCESS_DENIED_STAGE1;
  stageStartTime = millis();
  accessDeniedLoopStart = millis();
}

// ===================================================
// Stage handler
void handleMessageStages(unsigned long now) {
  switch (currentMessageStage) {
    case ACCESS_GRANTED_STAGE1:
      if (now - stageStartTime >= stageDuration) {
        drawHappyMochi("Good Day, Mr. Garin!");
        currentMessageStage = ACCESS_GRANTED_STAGE2;
        stageStartTime = now;
      }
      break;
    case ACCESS_GRANTED_STAGE2:
      if (now - stageStartTime >= stageDuration) {
        drawHappyMochi("Welcome!");
        currentMessageStage = ACCESS_GRANTED_STAGE3;
        stageStartTime = now;
      }
      break;
    case ACCESS_GRANTED_STAGE3:
      if (now - stageStartTime >= stageDuration) {
        digitalWrite(GREEN_LED, LOW);
        drawIdleMochi("Scan your tag!");
        currentMessageStage = NONE;
      }
      break;
    case ACCESS_DENIED_STAGE1:
      if ((now / 100) % 2 == 0) {
        digitalWrite(RED_LED1, HIGH); digitalWrite(RED_LED2, LOW);
      } else {
        digitalWrite(RED_LED1, LOW); digitalWrite(RED_LED2, HIGH);
      }
      digitalWrite(BUZZER, HIGH);
      if (now - stageStartTime >= 3000) {
        drawAngryMochi("Access Denied!");
        currentMessageStage = ACCESS_DENIED_STAGE2;
        stageStartTime = now;
      }
      break;
    case ACCESS_DENIED_STAGE2:
      if ((now / 50) % 2 == 0) {
        digitalWrite(RED_LED1, HIGH);
        digitalWrite(RED_LED2, LOW);
      } else {
        digitalWrite(RED_LED1, LOW);
        digitalWrite(RED_LED2, HIGH);
      }
      digitalWrite(BUZZER, HIGH);
      if (now - stageStartTime >= deniedDuration) {
        digitalWrite(RED_LED1, LOW);
        digitalWrite(RED_LED2, LOW);
        digitalWrite(BUZZER, LOW);
        drawIdleMochi("Scan your tag!");
        currentMessageStage = NONE;
      }
      break;
    default:
      break;
  }
}

// ===================================================
// Network scanner: scan once and display (paged)
void displayNetworksOnce() {
  tft.fillScreen(TFT_WHITE);
  tft.setFreeFont(&Minecraft10pt7b);
  tft.setTextColor(TFT_BLACK);
  tft.setCursor(10, 18);
  tft.print("Scanning WiFi..."); // Indicate scan is starting on TFT

  Serial.println("Scanning for available networks...");
  // Ensure WiFi in STA mode for scanning but don't connect
  WiFi.mode(WIFI_STA);
  WiFi.disconnect(true);
  delay(200);

  int n = WiFi.scanNetworks();
  Serial.printf("Scan complete. %d networks found.\n", n);
  lastScanCount = n;

  tft.fillScreen(TFT_WHITE); // Clear screen for results
  tft.setCursor(10, 18);
  tft.print("Scan Complete."); // Indicate scan is complete on TFT
  delay(1000); // Give user time to read "Scan Complete."

  // Prepare pagination
  int pages = 1;
  if (n > 0) pages = (n + wifiPerPage - 1) / wifiPerPage;

  // store data locally for display cycling
  // We'll build strings to print per page
  for (int p = 0; p < pages; ++p) {
    tft.fillScreen(TFT_WHITE);
    tft.setFreeFont(&Minecraft10pt7b);
    tft.setTextColor(TFT_BLACK);
    tft.setCursor(10, 18);
    tft.print("WiFi Networks Found:");
    tft.setCursor(tft.width() - 60, 18);
    tft.print("Page ");
    tft.print(p + 1);
    tft.print("/");
    tft.print(pages);

    int y = 40;
    int startIdx = p * wifiPerPage;
    int endIdx = startIdx + wifiPerPage;
    if (endIdx > n) endIdx = n;

    if (n == 0) {
      tft.setCursor(10, y);
      tft.println("No networks found");
      Serial.println("No networks found");
    } else {
      for (int i = startIdx; i < endIdx; ++i) {
        String ss = WiFi.SSID(i);
        int ch = WiFi.channel(i);
        int rssi = WiFi.RSSI(i);
        int enc = WiFi.encryptionType(i);

        Serial.printf("%d: %s  (ch:%d, rssi:%d, enc:%d)\n", i + 1, ss.c_str(), ch, rssi, enc);

        // On TFT: show index and SSID (truncate if needed), then channel & RSSI
        tft.setCursor(10, y);
        String line = String(i + 1) + ". ";
        // truncate SSID if too long
        String ss_display = ss;
        if (ss_display.length() > 18) ss_display = ss_display.substring(0, 18) + "..";
        line += ss_display;
        tft.print(line);
        y += 16;
        tft.setCursor(16, y);
        tft.printf("ch:%d r:%d", ch, rssi);
        y += 18;
      }
    }

    // Pause between pages to let user read
    delay(2500);
  }

  Serial.println("Display done.");
  drawIdleMochi("Scan your tag!"); // Return to idle face after scan display
}

// helper: start scanner (single call)
void startNetworkScanner() {
  displayNetworksOnce();
  lastScanTime = millis();
}

// ===================================================
// WiFi and Server Communication (preserved but unused here)
void testServerConnection() {
  Serial.println("\nTesting connection to server...");
  Serial.print("Server URL: "); // Changed to URL
  Serial.println(serverUrl);

  // If serverUrl is empty, skip connection attempt
  if (String(serverUrl).isEmpty()) {
      Serial.println("Server URL not set. Skipping connection test.");
      return;
  }

  // Parse host and port from URL
  String host = serverUrl;
  int port = 80; // Default HTTP port
  if (host.startsWith("http://")) {
      host.remove(0, 7);
  }
  int slashPos = host.indexOf('/');
  if (slashPos != -1) {
      host = host.substring(0, slashPos);
  }

  WiFiClient client;
  if (client.connect(host.c_str(), port)) {
    Serial.println("Connected to server!");
    client.println("GET / HTTP/1.1");
    client.print("Host: ");
    client.println(host);
    client.println("Connection: close");
    client.println();
    client.stop();
  } else {
    Serial.println("Server connection failed!");
  }
}

void setupWiFi() {
  // kept for future, currently not used
  WiFi.mode(WIFI_STA);
  WiFi.disconnect(true);
  delay(1000);

  // If ssid or password are empty, skip connection attempt
  if (String(ssid).isEmpty() || String(password).isEmpty()) {
      Serial.println("WiFi credentials not set. Skipping WiFi connection attempt.");
      drawIdleMochi("WiFi Disabled!");
      delay(2000);
      drawIdleMochi("Scan your tag!");
      return;
  }


  drawIdleMochi("Connecting WiFi...");
  WiFi.begin(ssid, password);

  unsigned long startAttemptTime = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startAttemptTime < 15000) {
    Serial.print("WiFi Status: ");
    Serial.println(WiFi.status());
    delay(500);
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n✅ WiFi Connected!");
    Serial.print("IP: ");
    Serial.println(WiFi.localIP());
    drawIdleMochi("WiFi Connected!");
    delay(1000);
    testServerConnection();
  } else {
    Serial.println("\n❌ WiFi Failed!");
    drawIdleMochi("WiFi Failed!");
    delay(2000);
  }
  drawIdleMochi("Scan your tag!");
}

// ===================================================
void displayStudentInfo() {
  tft.fillScreen(TFT_WHITE);
  tft.setTextColor(TFT_BLACK, TFT_WHITE);
  tft.setFreeFont(&Minecraft10pt7b);

  int y = 40;
  int margin = 10;

  tft.setCursor(margin, y);
  tft.print("Name: ");
  tft.println(currentStudent.name);

  y += 30;
  tft.setCursor(margin, y);
  tft.print("ID: ");
  tft.println(currentStudent.studentNumber);

  y += 30;
  tft.setCursor(margin, y);
  tft.print("Dept: ");
  tft.println(currentStudent.department);

  y += 30;
  tft.setCursor(margin, y);
  tft.print("Year/Sec: ");
  tft.println(currentStudent.yearSection);

  delay(3000);
}

void sendTagToServer(String tag) {
  // This function will only attempt if WiFi is connected (which it won't be in scanner mode)
  // and if serverUrl is not empty.
  if (WiFi.status() == WL_CONNECTED && !String(serverUrl).isEmpty()) {
    HTTPClient http;
    http.begin(serverUrl);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("X-RFID-Source", "ESP32");

    String jsonData = "{\"rfid_uid\":\"" + tag + "\", \"source\":\"esp32\"}";
    int httpResponseCode = http.POST(jsonData);

    if (httpResponseCode > 0) {
      String response = http.getString();
      Serial.println("HTTP Response: " + String(httpResponseCode));
      Serial.println("Server Response: " + response);

      DynamicJsonDocument doc(1024);
      DeserializationError error = deserializeJson(doc, response);

      if (!error && doc["success"]) {
        currentStudent.name = doc["student_data"]["full_name"].as<String>();
        currentStudent.studentNumber = doc["student_data"]["student_number"].as<String>();
        currentStudent.department = doc["student_data"]["department"].as<String>();
        currentStudent.yearSection = doc["student_data"]["year_section"].as<String>();
        displayStudentInfo();
      }
    } else {
      Serial.println("Error sending to server: " + String(httpResponseCode));
      testServerConnection();
    }
    http.end();
  } else {
      Serial.println("Cannot send tag to server: WiFi not connected or Server URL not set.");
  }
}

// ===================================================
// Setup
void setup() {
  Serial.begin(115200);
  Serial2.begin(9600, SERIAL_8N1, RDM6300_RX_PIN, -1);

  tft.init();
  tft.setRotation(1);

  pinMode(RED_LED1, OUTPUT);
  pinMode(RED_LED2, OUTPUT);
  pinMode(GREEN_LED, OUTPUT);
  pinMode(BUZZER, OUTPUT);

  digitalWrite(RED_LED1, LOW);
  digitalWrite(RED_LED2, LOW);
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(BUZZER, LOW);

  centerX = tft.width() / 2;
  centerY = tft.height() / 2;

  // === NETWORK SCANNER MODE ===
  // Run an initial scan & display. This DOES NOT connect to any WiFi.
  startNetworkScanner();

  lastBlink = millis();
  lastIdleEvent = millis();
}

// ===================================================
void loop() {
  unsigned long now = millis();

  // RFID reading unchanged
  while (Serial2.available()) {
    byte b = Serial2.read();
    if (b == 0x02) { tagBuffer = ""; readingTag = true; }
    else if (b == 0x03 && readingTag) {
      readingTag = false;
      Serial.print("Tag detected: "); Serial.println(tagBuffer);
      if (tagBuffer.equalsIgnoreCase(validTag)) {
        startAccessGranted();
        // NOTE: Won't send to server because WiFi isn't connected in scanner mode.
        // To re-enable, you must call setupWiFi() in setup() instead of startNetworkScanner().
        // sendTagToServer(tagBuffer); // This line is commented out as WiFi is not connected
      } else startAccessDenied();
      tagBuffer = "";
    } else if (readingTag) tagBuffer += (char)b;
  }

  // message stage handling
  handleMessageStages(now);

  // idle animations
  if (currentMessageStage == NONE) {
    if (now - lastBlink > 3000) {
      eyesClosed = true; drawIdleMochi("Scan your tag!"); delay(150);
      eyesClosed = false; drawIdleMochi("Scan your tag!");
      lastBlink = now;
    }
    if (now - lastIdleEvent > random(8000, 12000)) {
      idleMode = random(0, 5);
      drawIdleMochi("Scan your tag!");
      lastIdleEvent = now;
    }
  }

  // periodic re-scan: refresh network list every SCAN_INTERVAL_MS
  if (now - lastScanTime > SCAN_INTERVAL_MS) {
    startNetworkScanner();
  }

  // Note: we purposely do NOT attempt WiFi.begin() here — scanner mode only.
}