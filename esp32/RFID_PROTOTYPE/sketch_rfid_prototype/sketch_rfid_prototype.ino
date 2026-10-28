/*
 * SKETCH_RFID_PROTOTYPE.INO (FINAL - Defense Ready)
 * - Re-enabled server alerts (Motion trigger & Status check).
 * - Motion sensor now triggers CAM hardware and SERVER alert.
 * - Local alert (LEDs/Buzzer) is now controlled BY SERVER status.
 * - Intruder alerts are ONLY cleared by a VERIFIED RFID tap (calls clearServerAlert).
 * - Handles Log-In (1) and Log-Out (2) responses from sendTagToServer.
 * - REMOVED hardware CAM trigger for *valid* RFID scans.
 */
#include <TFT_eSPI.h>
#include <SPI.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <Adafruit_GFX.h>
#include "Minecraft10pt7b.h" // Make sure you have this font file
#include <ArduinoJson.h>
#include <AsyncUDP.h>
#include <TJpg_Decoder.h> // Include JPEG decoder library

// ===================================================
// =====         MASTER CONFIGURATION          =====
// ===================================================

const bool USE_DYNAMIC_DISCOVERY = true;
struct WiFiNet { const char* ssid; const char* password; };
#include "secrets.h"
const int numNetworks = sizeof(networks) / sizeof(networks[0]);
// --- Image Display Settings ---
const int IMG_WIDTH = 80;
const int IMG_HEIGHT = 80;
const int IMG_X = 220;
const int IMG_Y = 100;
const size_t MAX_JPEG_SIZE = 20 * 1024;
// Limit JPEG download size to 20KB

// ===================================================

// --- Network & Server Variables ---
AsyncUDP udp; String serverIp = "";
String serverUrlVerify = "";
String serverUrlRegisterUid = "";
String serverUrlCheckRegMode = "";
String serverUrlCheckAlert = "";
String serverUrlTriggerMotion = "";
String serverUrlClearAlert = ""; // <-- NEW: For clearing server alert
const int UDP_PORT = 4210;
const char* DISCOVERY_REQUEST = "ESP32_SERVER_REQUEST";
const char* DISCOVERY_RESPONSE_PREFIX = "PORTAL_SERVER_IP_IS:";
bool networkInitialized = false;

// --- Registration Mode ---
bool isInRegistrationMode = false;
unsigned long lastRegCheck = 0;
const unsigned long REG_CHECK_INTERVAL = 1000;
// --- Student Info Structure ---
struct StudentInfo { String name, studentNumber, department, yearSection, photoUrl;
} currentStudent;
// --- Display ---
TFT_eSPI tft = TFT_eSPI();

// --- Function declarations ---
void drawTextBubble(const char* msg);
void drawFace(bool happy, bool angry, const char* msg);
void drawHappyMochi(const char* msg); void drawAngryMochi(const char* msg); void drawIdleMochi(const char* msg);
void startAccessGranted(String title, String subtitle); // <-- MODIFIED
void startAccessDenied();
void handleMessageStages(unsigned long now);
void setupWiFiAndServer(); bool discoverServerIP();
int sendTagToServer(String tag); // <-- MODIFIED to return int
void sendTagForRegistration(String tag);
void displayStudentInfo();
void checkRegistrationMode();
void checkReaderAlertStatus(); // <-- RE-ENABLED
void triggerMotionAlertOnServer(); // <-- RE-ENABLED
void clearServerAlert(); // <-- NEW
bool tft_output(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t* bitmap);
// Callback for JPEG decoder

// --- RFID, LEDs, Buzzer, Message Stages, Idle, Face Position ---
#define RDM6300_RX_PIN 19
String tagBuffer = "";
bool readingTag = false;
#define RED_LED1 25
#define RED_LED2 26
#define GREEN_LED 27
#define BUZZER 14

// --- Pin Definitions for Motion and CAM Trigger ---
#define MOTION_SENSOR_PIN 32
#define CAM_TRIGGER_PIN 21
// --- END Pin Definitions ---

enum MessageStage { NONE, ACCESS_GRANTED_STAGE1, ACCESS_GRANTED_STAGE2, ACCESS_GRANTED_STAGE3, ACCESS_DENIED_STAGE1, ACCESS_DENIED_STAGE2 } currentMessageStage = NONE;
unsigned long stageStartTime = 0; const unsigned long stageDuration = 5000; const unsigned long deniedDuration = 5000;
String grantedTitle = ""; String grantedSubtitle = ""; // <-- NEW for granted message
unsigned long accessDeniedLoopStart = 0;
unsigned long lastBlink = 0; bool eyesClosed = false; unsigned long lastIdleEvent = 0;
int idleMode = 0;
int centerX, centerY; int eyeWidth = 36; int eyeHeight = 42; int eyeOffsetX = 60;
int eyeOffsetY = -30; int mouthYoffset = 65;
bool localIntruderAlertActive = false; // This is now controlled BY THE SERVER
unsigned long lastReaderAlertCheck = 0;
const unsigned long READER_ALERT_CHECK_INTERVAL = 1500;
unsigned long lastMotionTrigger = 0;
// Motion Cooldown
const unsigned long MOTION_COOLDOWN = 5000; // 5 sec cooldown

// ===================================================
// TFT Output Function for JPEG Decoder
// ===================================================
bool tft_output(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t* bitmap) {
  int16_t drawX = x + IMG_X;
int16_t drawY = y + IMG_Y;
  if ((drawY + h) > tft.height() || (drawX + w) > tft.width()) return false;
tft.pushImage(drawX, drawY, w, h, bitmap);
  return true;
}

// ===================================================
// Drawing Functions
// ===================================================
// These functions remain UNCHANGED [cite: 172-187]
void drawTextBubble(const char* msg) {
  tft.setFreeFont(&Minecraft10pt7b);
  tft.setTextColor(TFT_BLACK, TFT_WHITE); // White text on black bubble
  int16_t w = tft.textWidth(msg); int16_t h = tft.fontHeight();
  int16_t x = (tft.width() - w) / 2; int16_t y = 30;
  tft.fillRoundRect(x - 10, y - h, w + 20, h + 20, 8, TFT_WHITE); // Black fill
  tft.drawRoundRect(x - 10, y - h, w + 20, h + 20, 8, TFT_BLACK); // White border
  tft.setCursor(x, y); tft.print(msg);
}

void drawFace(bool happy=false, bool angry=false, const char* msg="") {
  tft.fillScreen(TFT_WHITE);
  int leftEyeX = centerX - eyeOffsetX;
int rightEyeX = centerX + eyeOffsetX; int eyeY = centerY + eyeOffsetY;
if (!eyesClosed) {
    tft.fillRoundRect(leftEyeX - eyeWidth/2, eyeY - eyeHeight/2, eyeWidth, eyeHeight, 12, TFT_BLACK);
tft.fillRoundRect(rightEyeX - eyeWidth/2, eyeY - eyeHeight/2, eyeWidth, eyeHeight, 12, TFT_BLACK);
} else {
    tft.fillRect(leftEyeX - 18, eyeY, 36, 5, TFT_WHITE); tft.fillRect(rightEyeX - 18, eyeY, 36, 5, TFT_WHITE);
}
  if (happy) tft.drawArc(centerX, centerY + mouthYoffset, 18, 12, 200, 340, TFT_BLACK, TFT_BLACK);
else if (angry) tft.drawLine(centerX - 20, centerY + mouthYoffset, centerX + 20, centerY + mouthYoffset, TFT_WHITE);
else if (idleMode == 3) tft.fillCircle(centerX, centerY + mouthYoffset, 10, TFT_WHITE);
else tft.drawArc(centerX, centerY + mouthYoffset, 12, 8, 200, 340, TFT_BLACK, TFT_BLACK);
if (idleMode == 4) { tft.fillCircle(leftEyeX, eyeY + 30, 10, TFT_RED); tft.fillCircle(rightEyeX, eyeY + 30, 10, TFT_RED);
}
  if (msg[0] != '\0') drawTextBubble(msg);
}

void drawHappyMochi(const char *msg) { drawFace(true, false, msg);
}
void drawAngryMochi(const char *msg) { drawFace(false, true, msg); }
void drawIdleMochi(const char *msg="") { drawFace(false, false, msg);
}

// ===================================================
// RFID Event Handlers (MODIFIED)
// ===================================================
void startAccessGranted(String title, String subtitle) {
  grantedTitle = title;
  grantedSubtitle = subtitle;
  drawHappyMochi(grantedTitle.c_str());
  digitalWrite(GREEN_LED, HIGH);
  digitalWrite(BUZZER, HIGH); delay(200); digitalWrite(BUZZER, LOW);
// Short beep for access granted
  currentMessageStage = ACCESS_GRANTED_STAGE1;
  stageStartTime = millis();
}

void startAccessDenied() {
  Serial.println(">>> startAccessDenied() called!");
  drawAngryMochi("INTRUDER ALERT!!!");
// Trigger CAM for DENIED scan (This is for invalid RFID)
  Serial.println("Triggering CAM for DENIED scan.");
  digitalWrite(CAM_TRIGGER_PIN, LOW);
// Pull LOW to trigger
  delay(100); // 100ms pulse
  digitalWrite(CAM_TRIGGER_PIN, HIGH);
// Return to HIGH
  currentMessageStage = ACCESS_DENIED_STAGE1;
  stageStartTime = millis();
  accessDeniedLoopStart = millis();
}

// ===================================================
// Handle Display Stages (MODIFIED)
// ===================================================
void handleMessageStages(unsigned long now) {
  switch (currentMessageStage) {
    case ACCESS_GRANTED_STAGE1:
      if (now - stageStartTime >= stageDuration) {
        drawHappyMochi(grantedSubtitle.c_str()); // <-- Use new subtitle
        currentMessageStage = ACCESS_GRANTED_STAGE2;
        stageStartTime = now;
      }
      break;
case ACCESS_GRANTED_STAGE2:
      if (now - stageStartTime >= stageDuration) {
        displayStudentInfo(); // <-- Display student info
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
// ACCESS_DENIED stages remain UNCHANGED [cite: 198-207]
    case ACCESS_DENIED_STAGE1:
      // *** ADD THIS LINE ***
      Serial.println(">>> Handling ACCESS_DENIED_STAGE1 - Turning BUZZER ON");
      // *** END ADDED LINE ***
      if ((now / 100) % 2 == 0) { digitalWrite(RED_LED1, HIGH); digitalWrite(RED_LED2, LOW); }
      else { digitalWrite(RED_LED1, LOW); digitalWrite(RED_LED2, HIGH); }
      digitalWrite(BUZZER, HIGH); // Buzzer stays on during Stage 1 of denied
      if (now - stageStartTime >= 3000) { // 3 seconds beep for denied
        // *** ADD THIS LINE ***
        Serial.println(">>> Handling ACCESS_DENIED_STAGE1 - Turning BUZZER OFF after 3s");
        // *** END ADDED LINE ***
        digitalWrite(BUZZER, LOW); // Turn off after initial beep
        drawAngryMochi("Access Denied!");
        currentMessageStage = ACCESS_DENIED_STAGE2;
        stageStartTime = now;
      }
      break;
case ACCESS_DENIED_STAGE2:
      if ((now / 50) % 2 == 0) { digitalWrite(RED_LED1, HIGH); digitalWrite(RED_LED2, LOW);
}
      else { digitalWrite(RED_LED1, LOW); digitalWrite(RED_LED2, HIGH);
}
      // Buzzer is off here, only LEDs blink faster
      if (now - stageStartTime >= deniedDuration) {
        digitalWrite(RED_LED1, LOW);
digitalWrite(RED_LED2, LOW);
        digitalWrite(BUZZER, LOW);
        drawIdleMochi("Scan your tag!");
        currentMessageStage = NONE;
      }
      break;
    default: break;
}
}

// ===================================================
// WiFi and Server Setup / Discovery (MODIFIED)
// ===================================================
void setupWiFiAndServer() {
  bool connected = false;
  networkInitialized = false;
serverIp = "";
  serverUrlVerify = ""; serverUrlRegisterUid = ""; serverUrlCheckRegMode = ""; serverUrlCheckAlert = "";
  serverUrlTriggerMotion = "";
  serverUrlClearAlert = ""; // <-- NEW

  Serial.println("Starting WiFi Connection Attempt...");
  for (int i = 0; i < numNetworks; i++) {
    Serial.println("Trying WiFi: " + String(networks[i].ssid));
WiFi.disconnect(true); delay(100);
    WiFi.begin(networks[i].ssid, networks[i].password);
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) { delay(500); Serial.print("."); attempts++;
yield();
    }
    if (WiFi.status() == WL_CONNECTED) {
      Serial.println("\nWiFi connected! IP: " + WiFi.localIP().toString());
drawIdleMochi("WiFi Connected!"); delay(1000);
      connected = true; break;
    } else { Serial.println(" Failed.");
}
  }

  if (connected) {
    bool serverFound = false;
if (USE_DYNAMIC_DISCOVERY) {
        serverFound = discoverServerIP();
if (!serverFound) {
            Serial.println("UDP Discovery failed. Using hardcoded fallback.");
drawIdleMochi("Server Fallback!"); delay(1000);
            serverIp = HARDCODED_SERVER_IP;
            serverFound = true;
        }
    } else { serverIp = HARDCODED_SERVER_IP;
serverFound = true; }

    if (serverFound) {
      if (serverUrlVerify == "") {
          String baseUrl = "http://" + serverIp + "/portal/php/";
serverUrlVerify = baseUrl + "verify-rfid.php";
          serverUrlRegisterUid = baseUrl + "receive_registration_uid.php";
          serverUrlCheckRegMode = baseUrl + "get_registration_state.php";
          serverUrlCheckAlert = baseUrl + "check_alert_status.php";
serverUrlTriggerMotion = baseUrl + "trigger_motion.php";
          serverUrlClearAlert = baseUrl + "clear_alert_status.php"; // <-- NEW
}
      Serial.println("Server URL (Verify): " + serverUrlVerify);
Serial.println("Server URL (Reg UID): " + serverUrlRegisterUid);
      Serial.println("Server URL (Alert Check): " + serverUrlCheckAlert);
Serial.println("Server URL (Motion Trigger): " + serverUrlTriggerMotion);
      Serial.println("Server URL (Alert Clear): " + serverUrlClearAlert); // <-- NEW
drawIdleMochi("Server Ready!"); delay(1000);
      drawIdleMochi("Scan your tag!");
      networkInitialized = true;
} else {
        drawIdleMochi("Server Error!"); delay(2000);
        drawIdleMochi("Scan your tag!");
}
  } else {
    Serial.println("All WiFi networks failed.");
    drawIdleMochi("WiFi Failed!"); delay(2000);
    drawIdleMochi("Scan your tag!");
}
}

bool discoverServerIP() {
  serverIp = ""; serverUrlVerify = ""; serverUrlRegisterUid = ""; serverUrlCheckRegMode = ""; serverUrlCheckAlert = "";
serverUrlTriggerMotion = ""; serverUrlClearAlert = ""; // <-- NEW
  drawIdleMochi("Finding Server...");
  Serial.println("Finding server via UDP broadcast...");
if (udp.listen(UDP_PORT)) {
    udp.onPacket([](AsyncUDPPacket packet) {
      String data = (char*)packet.data(); data.trim();
      // Serial.println("UDP Packet Received: " + data); // Reduce spam
      if (data.startsWith(DISCOVERY_RESPONSE_PREFIX)) {
        serverIp = data.substring(strlen(DISCOVERY_RESPONSE_PREFIX));
        String baseUrl = "http://" + serverIp + "/portal/php/";
        serverUrlVerify = baseUrl + "verify-rfid.php";
        serverUrlRegisterUid = baseUrl + "receive_registration_uid.php";
        serverUrlCheckRegMode = baseUrl + "get_registration_state.php";
  
      serverUrlCheckAlert = baseUrl + "check_alert_status.php";
        serverUrlTriggerMotion = baseUrl + "trigger_motion.php";
        serverUrlClearAlert = baseUrl + "clear_alert_status.php"; // <-- NEW

        Serial.println("Server Discovered! URLs built.");
        drawIdleMochi("Server Found!"); delay(1000);
        udp.close();
      }
    });
IPAddress broadcastIp = WiFi.broadcastIP();
    Serial.println("Broadcasting request to: " + broadcastIp.toString());
    udp.broadcastTo(DISCOVERY_REQUEST, UDP_PORT);

    unsigned long discoveryStart = millis();
while(serverIp == "" && millis() - discoveryStart < 15000) { delay(100); yield();
}

    if (serverIp == "") { // Timeout check
      Serial.println("Server discovery timed out.");
drawIdleMochi("Server Not Found!"); delay(2000);
      udp.close();
      return false;
    }
    return true;
} else {
    Serial.println("UDP Listener Failed!");
    drawIdleMochi("UDP Error!"); delay(2000);
    drawIdleMochi("Scan your tag!");
    return false;
}
}

// ===================================================
// Display Student Info with Photo
// ===================================================
void displayStudentInfo() {
    tft.fillScreen(TFT_WHITE);
    tft.setTextColor(TFT_BLACK, TFT_WHITE);
    tft.setFreeFont(&Minecraft10pt7b);
    int y = 40; int margin = 10;
    int textWidthArea = IMG_X - (2 * margin);
    tft.setTextWrap(true); tft.setCursor(margin, y);
    tft.print("Name: ");
    tft.fillRect(margin + tft.textWidth("Name: "), y-tft.fontHeight(), textWidthArea, tft.fontHeight()*2, TFT_WHITE); // Clear area
    tft.setCursor(margin + tft.textWidth("Name: "), y); tft.print(currentStudent.name);
    y += 40;

    tft.setTextWrap(false); tft.setCursor(margin, y); tft.print("ID: ");
    tft.println(currentStudent.studentNumber); y += 30;
    tft.setCursor(margin, y); tft.print("Dept: "); tft.println(currentStudent.department); y += 30;
    tft.setCursor(margin, y); tft.print("Year/Sec: "); tft.println(currentStudent.yearSection);

    bool imageDrawn = false;
    if (currentStudent.photoUrl != "" && WiFi.status() == WL_CONNECTED) {
        Serial.println("Attempting to draw image from URL: " + currentStudent.photoUrl);
        HTTPClient http;
        http.setConnectTimeout(7000);
        http.begin(currentStudent.photoUrl);
        int httpCode = http.GET();

        if (httpCode == HTTP_CODE_OK) {
            WiFiClient *stream = http.getStreamPtr();
            size_t len = http.getSize();
            String contentType = http.header("Content-Type"); // Read the specific header
            Serial.printf("Image size: %d bytes, Content-Type: %s\n", len, contentType.c_str());

            // --- *** TEMPORARY DEBUG: Bypass Content-Type Check *** ---
            bool isJpeg = true; // FORCE TRUE FOR TESTING
            Serial.println("!!! DEBUG: Forcing isJpeg = true !!!");
            // Original check: bool isJpeg = (contentType.indexOf("jpeg") >= 0 || contentType.indexOf("jpg") >= 0);
            // --- *** END TEMPORARY DEBUG *** ---

            bool sizeOk = (len > 0 && len <= MAX_JPEG_SIZE);
            if (isJpeg && sizeOk) { // Now check only sizeOk (since isJpeg is forced true)
                uint8_t *jpegBuffer = (uint8_t *)malloc(len);
                if (jpegBuffer) {
                    Serial.println("Memory allocated for JPEG buffer.");
                    int bytesRead = stream->readBytes(jpegBuffer, len);
                    if (bytesRead == len) {
                        Serial.println("Image downloaded successfully.");
                        JRESULT drawResult = TJpgDec.drawJpg(0, 0, jpegBuffer, len);
                        if (drawResult == JDR_OK) {
                            Serial.println("JPEG draw completed successfully via callback.");
                            imageDrawn = true;
                            tft.drawRect(IMG_X-1, IMG_Y-1, IMG_WIDTH+2, IMG_HEIGHT+2, TFT_WHITE); // Border
                        } else {
                            Serial.printf("JPEG draw FAILED. Error code (JRESULT): %d\n", drawResult);
                        }
                    } else { Serial.printf("Error reading image stream, expected %d, got %d\n", len, bytesRead); }
                    free(jpegBuffer);
                    Serial.println("JPEG buffer memory freed.");
                } else { Serial.println("ERROR: Failed to allocate memory for JPEG buffer!"); }
            } else {
                // Keep original checks for logging
                bool originalIsJpegCheck = (contentType.indexOf("jpeg") >= 0 || contentType.indexOf("jpg") >= 0);
                if (!originalIsJpegCheck) Serial.println("[Image] ERROR: Content-Type is not JPEG.");
                if (len <= 0) Serial.println("[Image] ERROR: Reported size is zero or less.");
                if (len > MAX_JPEG_SIZE) Serial.printf("[Image] ERROR: File too large (%d > %d max)\n", len, MAX_JPEG_SIZE);
            }
        } else {
             Serial.printf("[Image] ERROR: HTTP GET failed, code: %d (%s)\n", httpCode, http.errorToString(httpCode).c_str());
        }
        http.end();
    } else {
        if (currentStudent.photoUrl == "") Serial.println("[Image] No photo URL provided by server.");
        if (WiFi.status() != WL_CONNECTED) Serial.println("[Image] WiFi not connected.");
    }

    if (!imageDrawn) {
        Serial.println("[Image] Drawing placeholder.");
        tft.fillRect(IMG_X, IMG_Y, IMG_WIDTH, IMG_HEIGHT, TFT_LIGHTGREY);
        tft.drawRect(IMG_X, IMG_Y, IMG_WIDTH, IMG_HEIGHT, TFT_DARKGREY);
        tft.setTextColor(TFT_DARKGREY); tft.setTextDatum(MC_DATUM);
        tft.drawString("No Photo", IMG_X + IMG_WIDTH / 2, IMG_Y + IMG_HEIGHT / 2);
        tft.setTextDatum(TL_DATUM);
    }
     tft.setTextWrap(false);
} // <<< --- THIS CLOSING BRACE WAS LIKELY MISSING ---

// ===================================================
// Server Communication Functions (MODIFIED)
// ===================================================

/**
 * Sends the RFID tag to the server for verification.
 * Supports Log-In/Log-Out.
 * Server MUST return: {"success": true/false, "action": "login/logout/null", "student_data": {...}}
 * Returns: 0 = Denied, 1 = Login OK, 2 = Logout OK
 */
int sendTagToServer(String tag) {
  int accessResult = 0; // 0 = Denied
  currentStudent.photoUrl = "";
if (WiFi.status() == WL_CONNECTED && serverIp != "" && serverUrlVerify != "") {
    HTTPClient http; http.setConnectTimeout(5000); http.begin(serverUrlVerify);
http.addHeader("Content-Type", "application/json"); http.addHeader("X-RFID-Source", "ESP32");
    String jsonData = "{\"rfid_uid\":\"" + tag + "\"}"; int httpResponseCode = http.POST(jsonData);
if (httpResponseCode > 0) {
      String response = http.getString(); Serial.println("[Verify] Response: " + response);
      DynamicJsonDocument doc(2048);
DeserializationError error = deserializeJson(doc, response);
      if (!error && doc["success"] && doc.containsKey("student_data") && !doc["student_data"].isNull()) {
        JsonObject studentData = doc["student_data"];
currentStudent.name = studentData["full_name"].as<String>(); currentStudent.studentNumber = studentData["student_number"].as<String>();
        currentStudent.department = studentData["department"].as<String>(); currentStudent.yearSection = studentData["year_section"].as<String>();
        currentStudent.photoUrl = studentData["photo_url"].as<String>();
Serial.println("Photo URL: " + currentStudent.photoUrl);
        
        String action = doc["action"].as<String>();
        if (action == "login") {
          accessResult = 1; // Login OK
        } else if (action == "logout") {
          accessResult = 2; // Logout OK
        } else {
          accessResult = 1; // Default to login OK if action is missing
        }
        
      } else if (!error && !doc["success"]) { Serial.println("[Verify] Tag not found or error.");
accessResult = 0;
      } else if (error) { Serial.println("[Verify] JSON parse error: " + String(error.c_str()));}
    } else { Serial.println("[Verify] HTTP Error: " + String(httpResponseCode));
serverIp = ""; accessResult = 0; } http.end();
  } else { Serial.println("[Verify] Cannot send: WiFi/Server not ready."); }
  return accessResult;
}

void sendTagForRegistration(String tag) {
  // This function remains UNCHANGED [cite: 270-279]
  bool registrationSuccess = false;
  if (WiFi.status() == WL_CONNECTED && serverIp != "" && serverUrlRegisterUid != "") {
    HTTPClient http;
http.setConnectTimeout(5000); http.begin(serverUrlRegisterUid);
    http.addHeader("Content-Type", "application/json"); String jsonData = "{\"rfid_uid\":\"" + tag + "\"}";
    Serial.println("[Register] Sending UID: " + tag);
int httpResponseCode = http.POST(jsonData);
    if (httpResponseCode > 0) {
      String response = http.getString();
Serial.println("[Register] Response: " + response);
       DynamicJsonDocument doc(256); DeserializationError error = deserializeJson(doc, response);
       if (!error && doc["success"]) { Serial.println("[Register] UID sent.");
registrationSuccess = true; }
       else { Serial.println("[Register] Server error."); drawIdleMochi("Server Error!"); delay(1500);
}
    } else { Serial.println("[Register] HTTP Error: " + String(httpResponseCode)); drawIdleMochi("Send Error!"); delay(1500); } http.end();
} else { Serial.println("[Register] Cannot send: WiFi/Server not ready."); }
  isInRegistrationMode = false;
if (registrationSuccess) {
      Serial.println("[Register] Verifying..."); drawIdleMochi("Verifying..."); delay(1000);
      String registeredTag = tag;
      int verificationSuccess = sendTagToServer(registeredTag); // <-- MODIFIED to check int
if (verificationSuccess > 0) { // 1 or 2
        Serial.println("[Register] Verification OK."); tagBuffer = registeredTag;
        startAccessGranted("Registered!", "Welcome!"); // <-- MODIFIED
}
      else { Serial.println("[Register] Verification FAILED."); drawIdleMochi("Verify Failed!"); delay(2000); drawIdleMochi("Scan your tag!");
}
  } else { drawIdleMochi("Scan your tag!"); }
}

// Function to Trigger Server-Side Motion Alert (RE-ENABLED)
void triggerMotionAlertOnServer() {
   if (!networkInitialized || serverIp == "" || serverUrlTriggerMotion == "") {
        Serial.println("[Motion Trigger] Cannot send: WiFi/Server not ready.");
return;
   }
   HTTPClient http;
   http.begin(serverUrlTriggerMotion);
   http.setConnectTimeout(2000);
int httpCode = http.GET(); // Simple GET request to trigger
   if (httpCode > 0) {
     Serial.println("[Motion Trigger] Server alert triggered. Code: " + String(httpCode));
} else {
     Serial.println("[Motion Trigger] HTTP GET failed: " + String(httpCode));
}
   http.end();
}

// --- [NEW] Function to Clear Server-Side Alert ---
// --- [NEW] Function to Clear Server-Side Alert ---
void clearServerAlert() {
   if (!networkInitialized || serverIp == "" || serverUrlClearAlert == "") {
        Serial.println("[Alert Clear] Cannot send: WiFi/Server not ready.");
        return;
   }
   // Send RFID tag with clear request
   String url = serverUrlClearAlert + "?rfid_uid=" + tagBuffer;

   HTTPClient http;
   http.begin(url);
   http.setConnectTimeout(2000);
   int httpCode = http.GET(); // Simple GET request to trigger
   if (httpCode > 0) {
     Serial.printf("[Alert Clear] Request sent. Code: %d\n", httpCode);
     // Note: Successful clear means *server* gets updated. CheckReaderAlertStatus will confirm it.
   } else {
     Serial.printf("[Alert Clear] HTTP GET failed (Code %d). CHECK SERVER LOGS!\n", httpCode); // <-- Highlight failure
   }
   http.end();
}

// Function to Check Server Alert Status (RE-ENABLED + DEBUG)
// Function to Check Server Alert Status (RE-ENABLED + DEBUG)
void checkReaderAlertStatus() {
   if (!networkInitialized || serverIp == "" || serverUrlCheckAlert == "") {
        if(localIntruderAlertActive) {
             Serial.println("[Reader Alert] Conn lost, stopping alert.");
             digitalWrite(RED_LED1, LOW); digitalWrite(RED_LED2, LOW); digitalWrite(BUZZER, LOW);
             localIntruderAlertActive = false;
        } return;
   }
   HTTPClient http; http.begin(serverUrlCheckAlert); http.setConnectTimeout(1000);
   int httpCode = http.GET();
   bool serverSaysActive = false;

   if (httpCode == 200) {
     String payload = http.getString();
     DynamicJsonDocument doc(128); DeserializationError error = deserializeJson(doc, payload);
     
     if (!error && doc["status"] == "active") { serverSaysActive = true; }
     else if (error) { Serial.println("[Reader Alert] JSON parse error."); }
     else if (!error && doc["status"] == "inactive") { Serial.println("[Reader Alert] Server status: INACTIVE."); }
     else { Serial.println("[Reader Alert] Unknown status in JSON."); } // Debug unknown status

   } else { Serial.printf("[Reader Alert] HTTP GET failed: %d\n", httpCode); } http.end();

   // --- Logic: Update local flag based on server status ---
   if (serverSaysActive) {
        if (!localIntruderAlertActive) {
            Serial.println("[Reader Alert] Local alert state changing to ACTIVE.");
            localIntruderAlertActive = true;
        }
   }
   else { // Server says INACTIVE
       if(localIntruderAlertActive) {
           Serial.println("[Reader Alert] CLEARED by server. Stopping hardware.");
           localIntruderAlertActive = false;
           // *** HARDWARE STOP ***
           digitalWrite(RED_LED1, LOW);
           digitalWrite(RED_LED2, LOW);
           digitalWrite(BUZZER, LOW);
           // *********************
       }
   }
}

void checkRegistrationMode() {
    // This function remains UNCHANGED [cite: 294-301]
    if (WiFi.status() != WL_CONNECTED || serverIp == "" || serverUrlCheckRegMode == "") {
        if (isInRegistrationMode) { Serial.println("Conn lost, exiting reg mode.");
isInRegistrationMode = false; drawIdleMochi("Scan your tag!"); } return;
    }
    HTTPClient http; http.setConnectTimeout(3000); http.begin(serverUrlCheckRegMode); int httpCode = http.GET();
if (httpCode == 200) {
        String payload = http.getString(); DynamicJsonDocument doc(256);
DeserializationError error = deserializeJson(doc, payload);
        if (!error && doc["status"] == "waiting_scan") { if (!isInRegistrationMode) { Serial.println("Entering Reg Mode.");
isInRegistrationMode = true; drawIdleMochi("Scan Tag for Reg..."); } }
        else { if (isInRegistrationMode) { Serial.println("Exiting Reg Mode.");
isInRegistrationMode = false; drawIdleMochi("Scan your tag!"); } }
    } else {
        Serial.println("[Reg Check] HTTP Error: " + String(httpCode));
if (isInRegistrationMode) { isInRegistrationMode = false; drawIdleMochi("Scan your tag!");
        }
    }
    http.end();
}

// ===================================================
// Setup
// ===================================================
void setup() {
  Serial.begin(115200); Serial.println("\n\n--- Setup Start ---");
  Serial2.begin(9600, SERIAL_8N1, RDM6300_RX_PIN, -1); Serial.println("Serial2 Started.");
  tft.init();
Serial.println("TFT Initialized."); tft.setRotation(1); tft.fillScreen(TFT_WHITE);

// --- START COLOR TEST ---
  Serial.println("Drawing color test rectangles...");
  tft.fillRect(0,   0, 50, 50, TFT_RED);
  tft.fillRect(50,  0, 50, 50, TFT_GREEN);
  tft.fillRect(100, 0, 50, 50, TFT_BLUE);
  tft.fillRect(150, 0, 50, 50, TFT_WHITE);
  delay(3000); // Pause for 3 seconds to see colors
  Serial.println("Color test complete.");
  // --- END COLOR TEST ---

Serial.println("TFT Rotation Set."); delay(100);
  Serial.println("Initializing JPEG Decoder..."); TJpgDec.setJpgScale(1); TJpgDec.setSwapBytes(true); TJpgDec.setCallback(tft_output); Serial.println("JPEG Decoder Initialized.");
  pinMode(RED_LED1, OUTPUT);
pinMode(RED_LED2, OUTPUT); pinMode(GREEN_LED, OUTPUT); pinMode(BUZZER, OUTPUT);

  // --- Initialize Motion Sensor and CAM Trigger Pins ---
  pinMode(MOTION_SENSOR_PIN, INPUT_PULLDOWN);
pinMode(CAM_TRIGGER_PIN, OUTPUT);
  digitalWrite(CAM_TRIGGER_PIN, HIGH); // Set trigger pin HIGH (idle)
  Serial.println("Motion Sensor & CAM Trigger Pins Initialized.");
// --- END Initialize Motion Sensor and CAM Trigger Pins ---

  digitalWrite(RED_LED1, LOW); digitalWrite(RED_LED2, LOW); digitalWrite(GREEN_LED, LOW); digitalWrite(BUZZER, LOW);
Serial.println("Pin Modes Set.");
  centerX = tft.width() / 2; centerY = tft.height() / 2; Serial.println("Center calculated.");
  lastBlink = millis();
lastIdleEvent = millis();
  Serial.println("Basic Setup Complete. Network setup deferred.");
  drawIdleMochi("Initializing...");
}

// ===================================================
// Loop (FINAL FIX: Restored Logic Flow)
// ===================================================
void loop() {
  unsigned long now = millis();
  
  if (!networkInitialized) {
      setupWiFiAndServer();
      if (!networkInitialized){ delay(5000); }
      return;
  }

  // Check server for alert status (Kept RE-ENABLED)
  checkReaderAlertStatus();

  // --- CHECK MOTION SENSOR (Server Trigger Only) ---
  if (currentMessageStage == NONE && !isInRegistrationMode && !localIntruderAlertActive) { 
      int motion = digitalRead(MOTION_SENSOR_PIN);

      if (motion == HIGH) { // Motion detected
          if (now - lastMotionTrigger > MOTION_COOLDOWN) { // Use the increased 15s cooldown if you set it
              Serial.println("Motion Detected! Triggering CAM and Server Alert.");
              lastMotionTrigger = now; 
              
              // 1. Trigger the CAM capture immediately
              digitalWrite(CAM_TRIGGER_PIN, LOW);
              delay(100); 
              digitalWrite(CAM_TRIGGER_PIN, HIGH);
              
              // 2. Trigger the server-side alert
              triggerMotionAlertOnServer();
          }
      }
  }
  // --- END CHECK MOTION SENSOR ---
// --- RFID READING (FINAL LOGIC FIX) ---
while (Serial2.available()) {
    byte b = Serial2.read();
    if (b == 0x02) { tagBuffer = ""; readingTag = true; }
    else if (b == 0x03 && readingTag) {
        readingTag = false;
        String currentTag = tagBuffer;
        Serial.print("Tag detected: "); Serial.println(currentTag);

        // Only process if idle
        if (currentMessageStage == NONE) {
            if (isInRegistrationMode) {
                sendTagForRegistration(currentTag);
            } else {
                static String lastProcessedTag = ""; 
                static unsigned long lastProcessTime = 0;
                
                if(currentTag != lastProcessedTag || (now - lastProcessTime > 1500) ) {
                    tagBuffer = currentTag; 
                    int accessResult = sendTagToServer(currentTag);
                    
                    // CRITICAL: Capture the alert status BEFORE we call clearServerAlert()
                    bool wasAlertActive = localIntruderAlertActive; 

                    // --- ALERT CLEARING LOGIC (Priority 1: If Alert is active) ---
                    if (wasAlertActive) {
                        if (accessResult > 0) { // 1 (Login) or 2 (Logout): VALID tag clears alert
                            Serial.println("VERIFIED RFID detected. Clearing server alert.");
                            clearServerAlert(); // Tell server to clear alert

                            // Manually stop hardware NOW (Crucial for immediate visual confirmation)
                            digitalWrite(RED_LED1, LOW);
                            digitalWrite(RED_LED2, LOW);
                            digitalWrite(BUZZER, LOW);
                            
                            // *** CRITICAL FIX: TRIGGER GRANTED ANIMATION IMMEDIATELY AFTER CLEARING ***
                            if (accessResult == 1) { 
                                startAccessGranted("Alert Cleared!", "Welcome!");
                            } else if (accessResult == 2) {
                                startAccessGranted("Alert Cleared!", "Logged Out!");
                            }
                            
                        } else { // Invalid RFID during alert: Trigger denied animation
                            Serial.println("Invalid RFID during alert. Triggering DENIED animation.");
                            startAccessDenied();
                        }
                    }
                    
                    // --- ACCESS ANIMATION LOGIC (Priority 2: If NO alert was active) ---
                    // Run this ONLY if the alert was NOT active when the tap occurred (i.e., normal state).
                    if (!wasAlertActive) {
                        if (accessResult == 1) { // Login
                            startAccessGranted("Access Granted!", "Welcome!");
                        } else if (accessResult == 2) { // Logout
                            startAccessGranted("Logged Out!", "Goodbye!");
                        } else if (accessResult == 0) { // Denied (Regular denial)
                            startAccessDenied();
                        }
                    }

                    lastProcessedTag = currentTag;
                    lastProcessTime = now;
                } else { Serial.println("Ignoring rapid re-read."); }
            }
        } else { Serial.println("Ignoring tag read, device busy with animation."); }
    } else if (readingTag) { tagBuffer += (char)b; }
}
// --- END RFID READING ---

  // *** CRITICAL CALL: Drives the animation stages ***
  handleMessageStages(now); 

  // --- HANDLE LOCAL INTRUDER ALERT EFFECTS (Driven by server status) ---
  static bool alertBuzzerShouldBeOn = false;
  static unsigned long alertStartTime = 0;

  if (localIntruderAlertActive) {
       // Start initial beep
       if (!alertBuzzerShouldBeOn) { 
           digitalWrite(BUZZER, HIGH);
           alertBuzzerShouldBeOn = true;
           alertStartTime = now;
       }
       // Turn off initial long beep after 3 seconds
       if (alertBuzzerShouldBeOn && digitalRead(BUZZER) == HIGH && (now - alertStartTime >= 3000)) {
           digitalWrite(BUZZER, LOW);
       }
       // Run LEDs (Blinking logic)
       if ((now / 100) % 2 == 0) { digitalWrite(RED_LED1, HIGH); digitalWrite(RED_LED2, LOW); }
       else { digitalWrite(RED_LED1, LOW); digitalWrite(RED_LED2, HIGH); }

  } else { // Alert is inactive (localIntruderAlertActive is false)
      if (alertBuzzerShouldBeOn) { 
          alertBuzzerShouldBeOn = false; 
      }
      // Ensure hardware is OFF only if no Denied animation is running
      if (currentMessageStage != ACCESS_DENIED_STAGE1 && currentMessageStage != ACCESS_DENIED_STAGE2) {
        if(digitalRead(BUZZER) == HIGH) digitalWrite(BUZZER, LOW);
        if(digitalRead(RED_LED1) == HIGH) digitalWrite(RED_LED1, LOW);
        if(digitalRead(RED_LED2) == HIGH) digitalWrite(RED_LED2, LOW);
      }
  }
  // --- END LOCAL INTRUDER ALERT EFFECTS ---


  if (currentMessageStage == NONE && !isInRegistrationMode && !localIntruderAlertActive) {
    if (now - lastBlink > 3000) { eyesClosed = true; drawIdleMochi("Scan your tag!"); delay(150); eyesClosed = false; drawIdleMochi("Scan your tag!"); lastBlink = now; }
    if (now - lastIdleEvent > random(8000, 12000)) { idleMode = random(0, 5); drawIdleMochi("Scan your tag!"); lastIdleEvent = now; }
  }

   if (now - lastRegCheck > REG_CHECK_INTERVAL) { lastRegCheck = now; if(networkInitialized) { checkRegistrationMode(); } }
  static unsigned long lastWiFiCheck = 0;
  if (now - lastWiFiCheck > 30000) {
    lastWiFiCheck = now;
    if (WiFi.status() != WL_CONNECTED) { Serial.println("WiFi lost. Resetting..."); networkInitialized = false; serverIp = ""; drawIdleMochi("Reconnecting..."); }
    else if (USE_DYNAMIC_DISCOVERY && serverIp == "") {
        Serial.println("Server IP lost. Re-discovering..."); bool success = discoverServerIP();
        if (!success) {
            Serial.println("UDP Discovery failed again. Using fallback."); drawIdleMochi("Server Fallback!"); delay(1000); serverIp = HARDCODED_SERVER_IP;
             String baseUrl = "http://" + serverIp + "/portal/php/"; serverUrlVerify = baseUrl + "verify-rfid.php"; serverUrlRegisterUid = baseUrl + "receive_registration_uid.php";
             serverUrlCheckRegMode = baseUrl + "get_registration_state.php"; serverUrlCheckAlert = baseUrl + "check_alert_status.php"; serverUrlTriggerMotion = baseUrl + "trigger_motion.php"; serverUrlClearAlert = baseUrl + "clear_alert_status.php";
            drawIdleMochi("Scan your tag!");
        } else { drawIdleMochi("Scan your tag!"); }
    }
  }
  delay(10);
} // End loop