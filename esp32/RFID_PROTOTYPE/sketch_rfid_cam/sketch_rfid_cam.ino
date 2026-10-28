#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include "driver/ledc.h"
#include <AsyncUDP.h>

struct WiFiNet { const char* ssid; const char* password; };
#include "secrets.h"

// --- SELECT YOUR CAMERA MODEL ---
#define CAMERA_MODEL_AI_THINKER
#include "camera_pins.h"

// --- [MODIFIED] Hardware Trigger Pin ---
// This pin listens for the HIGH signal from your Main ESP32
#define TRIGGER_PIN 13 // Use any free GPIO pin on the CAM that supports interrupts

const int numNetworks = sizeof(networks) / sizeof(networks[0]);

// --- Server Configuration ---
const bool USE_DYNAMIC_DISCOVERY = true;
String serverIp = "";

// --- Server URLs ---
String serverUrlCheckCapture = "";
String serverUrlUploadImage = "";
String serverUrlUploadImage_Intruder = "";

// --- UDP Discovery ---
AsyncUDP udp;
const int UDP_PORT = 4210;
const char* DISCOVERY_REQUEST = "ESP32_SERVER_REQUEST";
const char* DISCOVERY_RESPONSE_PREFIX = "PORTAL_SERVER_IP_IS:";
bool networkInitialized = false;

// --- Flash Configuration ---
const int FLASH_GPIO = 4;
const int LEDC_CHANNEL_FLASH = LEDC_CHANNEL_1;
const int LEDC_TIMER_FLASH = LEDC_TIMER_1;
const int LEDC_TIMER_BIT = 8;
const int LEDC_BASE_FREQ = 5000;

// --- Status LED ---
const int STATUS_LED_PIN = 33;

// --- State Variables ---
unsigned long lastCheckTime = 0;
const unsigned long checkIntervalMs = 1000;
volatile bool captureTriggered = false; // --- [NEW] Flag set by interrupt

// --- Function Declarations ---
bool initCamera();
void setupWiFiAndServer();
bool discoverServerIP();
void checkForCaptureRequest();
void captureAndUploadPhoto(String logId, String studentPhotoPath);
void captureAndTestPhoto();
void captureAndUploadPhoto_Intruder();

// --- [NEW] Interrupt Service Routine (ISR) for Trigger Pin ---
void IRAM_ATTR handleTriggerInterrupt() {
  captureTriggered = true; // Set flag to true
}


// ================================= SETUP =================================
void setup() {
  Serial.begin(115200);
  pinMode(STATUS_LED_PIN, OUTPUT);
  digitalWrite(STATUS_LED_PIN, HIGH);
  Serial.println("\n\n--- ESP32-CAM (Master/Slave + Polling) ---");

  // --- [MODIFIED] Setup the hardware trigger pin with INTERRUPT ---
  pinMode(TRIGGER_PIN, INPUT_PULLDOWN);
  attachInterrupt(digitalPinToInterrupt(TRIGGER_PIN), handleTriggerInterrupt, RISING); // Trigger on HIGH transition
  Serial.println("Hardware trigger pin 13 initialized with interrupt.");

  // Initialize flash
  ledc_timer_config_t ledc_timer = {
    .speed_mode       = LEDC_LOW_SPEED_MODE,
    .duty_resolution  = (ledc_timer_bit_t)LEDC_TIMER_BIT,
    .timer_num        = (ledc_timer_t)LEDC_TIMER_FLASH,
    .freq_hz          = LEDC_BASE_FREQ,
    .clk_cfg          = LEDC_AUTO_CLK
  };
  ledc_timer_config(&ledc_timer);
  ledc_channel_config_t ledc_channel = {
    .gpio_num       = FLASH_GPIO,
    .speed_mode     = LEDC_LOW_SPEED_MODE,
    .channel        = (ledc_channel_t)LEDC_CHANNEL_FLASH,
    .intr_type      = LEDC_INTR_DISABLE,
    .timer_sel      = (ledc_timer_t)LEDC_TIMER_FLASH,
    .duty           = 0,
    .hpoint         = 0
  };
  ledc_channel_config(&ledc_channel);

  // Initialize Camera
  if (!initCamera()) {
    Serial.println("Camera Init Failed! Restarting...");
    digitalWrite(STATUS_LED_PIN, LOW); // Solid ON for failure
    delay(5000);
    ESP.restart();
  }
  Serial.println("Camera Initialized.");
  Serial.println("Setup Complete. Network setup will run in loop...");
  digitalWrite(STATUS_LED_PIN, LOW); delay(500); digitalWrite(STATUS_LED_PIN, HIGH);
}

// ================================= LOOP ==================================
void loop() {
  unsigned long now = millis();

  // --- Check for network first ---
  if (!networkInitialized) {
    Serial.println("Network not initialized. Running setup...");
    setupWiFiAndServer();
    if (!networkInitialized) {
      Serial.println("Network setup failed. Retrying in 5s...");
      delay(5000);
    }
    // Return here to ensure network is up before listening for triggers
    return;
  }

  // --- [MODIFIED] Job 1: Check for Hardware Trigger Flag ---
  if (captureTriggered) {
    captureTriggered = false; // Reset the flag immediately
    Serial.println("--- [!] Hardware Triggered Camera Capture ---");
    if (WiFi.status() == WL_CONNECTED) { // Use WiFi.status() for current connection state
      Serial.println("Network is ON. Uploading intruder photo...");
      captureAndUploadPhoto_Intruder();
    } else {
      Serial.println("Network is OFF. Running test capture...");
      captureAndTestPhoto();
    }
    lastCheckTime = millis(); // Reset polling timer to avoid double-tap
  }

  // --- [EXISTING] Job 2: Check for Server Poll (Facial Verification) ---
  if (now - lastCheckTime > checkIntervalMs) {
    lastCheckTime = now;
    if (networkInitialized && WiFi.status() == WL_CONNECTED) { // Only poll if connected
      checkForCaptureRequest(); // This is your existing facial verification check
    } else {
      // Serial.println("Network down. Skipping poll check."); // Don't print constantly
    }
  }

  // --- [EXISTING] Connection Health Check ---
  static unsigned long lastWiFiCheck = 0;
  if (now - lastWiFiCheck > 30000) { // Check every 30 seconds
    lastWiFiCheck = now;
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println("WiFi lost. Resetting network flag...");
      networkInitialized = false;
      serverIp = "";
      Serial.println("Reconnecting...");
      // LED indication for reconnecting attempt
      for (int j = 0; j < 3; ++j) { digitalWrite(STATUS_LED_PIN, LOW); delay(50); digitalWrite(STATUS_LED_PIN, HIGH); delay(50); }
    }
  }

  delay(10); // Small delay to yield to other tasks
}

// ============================= FUNCTIONS =================================

// --- [MODIFIED] discoverServerIP (Includes LED signals) ---
bool discoverServerIP() {
    serverIp = "";
    serverUrlCheckCapture = ""; serverUrlUploadImage = "";
    serverUrlUploadImage_Intruder = ""; // Reset new URL

    Serial.println("Finding server via UDP broadcast...");

    unsigned long discoveryStart = millis();
    bool listening = udp.listen(UDP_PORT);
    if (listening) {
        udp.onPacket([](AsyncUDPPacket packet) {
            String data = (char*)packet.data(); data.trim();
            // Serial.println("UDP Packet Received: " + data); // Debugging line
            if (data.startsWith(DISCOVERY_RESPONSE_PREFIX)) {
                serverIp = data.substring(strlen(DISCOVERY_RESPONSE_PREFIX));
                String baseUrl = "http://" + serverIp + "/portal/php/";
                serverUrlCheckCapture = baseUrl + "get_capture_request.php";
                serverUrlUploadImage = baseUrl + "upload_scan_image.php";
                serverUrlUploadImage_Intruder = baseUrl + "upload_intruder_image.php";
                Serial.println("Server Discovered! URLs built.");
                udp.close(); // Stop listening
                // LED: Double blink success
                digitalWrite(STATUS_LED_PIN, LOW); delay(200); digitalWrite(STATUS_LED_PIN, HIGH); delay(200);
                digitalWrite(STATUS_LED_PIN, LOW); delay(200); digitalWrite(STATUS_LED_PIN, HIGH);
            }
        });
        IPAddress broadcastIp = WiFi.broadcastIP();
        Serial.println("Broadcasting request to: " + broadcastIp.toString());
        udp.broadcastTo(DISCOVERY_REQUEST, UDP_PORT);
        // Wait for response or timeout, blinking LED slowly
        while (serverIp == "" && millis() - discoveryStart < 15000) {
             digitalWrite(STATUS_LED_PIN, LOW); delay(50); // Faster blink during wait
             digitalWrite(STATUS_LED_PIN, HIGH); delay(950);
             yield();
        }

        digitalWrite(STATUS_LED_PIN, HIGH); // Ensure LED is off after loop

        if (serverIp == "") { // Timeout check
            Serial.println("Server discovery timed out.");
            udp.close();
            return false;
        }
        return true; // Server IP was found
    } else {
        Serial.println("UDP Listener Failed!");
        // LED: Fast short blinks for UDP listen fail
        for (int j = 0; j < 5; ++j) { digitalWrite(STATUS_LED_PIN, LOW); delay(50); digitalWrite(STATUS_LED_PIN, HIGH); delay(50); }
        return false;
    }
}

// --- [MODIFIED] setupWiFiAndServer (Now tries both networks) ---
void setupWiFiAndServer() {
    bool connected = false;
    networkInitialized = false;
    serverIp = "";
    serverUrlCheckCapture = ""; serverUrlUploadImage = "";
    serverUrlUploadImage_Intruder = ""; // Reset new URL

    Serial.println("Starting WiFi Connection Attempt...");
    // Loop will now try both networks
    for (int i = 0; i < numNetworks; i++) {
        Serial.println("Trying WiFi: " + String(networks[i].ssid));
        // LED: Fast blink while trying WiFi
        for (int j = 0; j < 5; ++j) { digitalWrite(STATUS_LED_PIN, LOW); delay(100); digitalWrite(STATUS_LED_PIN, HIGH); delay(100); }

        WiFi.disconnect(true); delay(100);
        WiFi.begin(networks[i].ssid, networks[i].password);
        int attempts = 0;
        while (WiFi.status() != WL_CONNECTED && attempts < 30) {
             digitalWrite(STATUS_LED_PIN, LOW); delay(50); // Faster blink during wait
             digitalWrite(STATUS_LED_PIN, HIGH); delay(50);
             Serial.print("."); attempts++; yield();
        }

        if (WiFi.status() == WL_CONNECTED) {
            Serial.println("\nWiFi connected! IP: " + WiFi.localIP().toString());
            // LED: Solid ON for 2 seconds on WiFi connect
            digitalWrite(STATUS_LED_PIN, LOW); delay(2000); digitalWrite(STATUS_LED_PIN, HIGH);
            connected = true; break; // Exit loop on success
        } else {
             Serial.println(" Failed.");
             // LED: Long OFF pulse if network fails
             digitalWrite(STATUS_LED_PIN, HIGH); delay(1000);
        }
    }

    if (connected) {
        bool serverFound = false;
        if (USE_DYNAMIC_DISCOVERY) {
            serverFound = discoverServerIP();

            if (!serverFound) {
                Serial.println("UDP Discovery failed. Using hardcoded fallback.");
                // LED: Quick triple blink for UDP failure
                for (int j = 0; j < 3; ++j) { digitalWrite(STATUS_LED_PIN, LOW); delay(100); digitalWrite(STATUS_LED_PIN, HIGH); delay(100); }
                serverIp = HARDCODED_SERVER_IP;
                serverFound = true;
            }
        } else {
            serverIp = HARDCODED_SERVER_IP;
            serverFound = true;
        }

        if (serverFound) {
            // Build URLs if not already built (e.g., fallback was used)
            if (serverUrlCheckCapture == "") {
                String baseUrl = "http://" + serverIp + "/portal/php/";
                serverUrlCheckCapture = baseUrl + "get_capture_request.php";
                serverUrlUploadImage = baseUrl + "upload_scan_image.php";
                serverUrlUploadImage_Intruder = baseUrl + "upload_intruder_image.php";
            }
            Serial.println("Server URL (Check): " + serverUrlCheckCapture);
            Serial.println("Server URL (Upload): " + serverUrlUploadImage);
            Serial.println("Server URL (Intruder): " + serverUrlUploadImage_Intruder);
            Serial.println("Server Ready!");
            networkInitialized = true;
            // LED: Success - Long ON pulse
            digitalWrite(STATUS_LED_PIN, LOW); delay(1500); digitalWrite(STATUS_LED_PIN, HIGH);
        } else {
            Serial.println("Server could not be found!");
            // LED: Fast long blinks for total failure
            for (int j = 0; j < 3; ++j) { digitalWrite(STATUS_LED_PIN, LOW); delay(500); digitalWrite(STATUS_LED_PIN, HIGH); delay(500); }
        }
    } else {
        Serial.println("WiFi connection failed.");
        // LED: Fast long blinks for total failure
        for (int j = 0; j < 3; ++j) { digitalWrite(STATUS_LED_PIN, LOW); delay(500); digitalWrite(STATUS_LED_PIN, HIGH); delay(500); }
    }
}


// --- [EXISTING] Check Server for Capture Request ---
void checkForCaptureRequest() {
    // ... (This function remains unchanged) ...
    Serial.print("Checking for capture request...");
    HTTPClient http;
    http.begin(serverUrlCheckCapture);
    http.setConnectTimeout(3000);
    int httpCode = http.GET();

    if (httpCode == HTTP_CODE_OK) {
        String payload = http.getString();
        DynamicJsonDocument doc(256);
        DeserializationError error = deserializeJson(doc, payload);

        if (!error && doc["status"] == "capture") {
            String access_log_id = doc["access_log_id"];
            String student_photo_path = doc["student_photo_path"];
            Serial.println("CAPTURE REQUEST RECEIVED for log ID: " + access_log_id);
            captureAndUploadPhoto(access_log_id, student_photo_path);
        } else if (!error) {
            // Serial.println(" (Idle)"); // Keep this commented out to avoid spamming serial
        } else {
            Serial.println(" [!] Failed to parse server response JSON.");
        }
    } else {
        Serial.printf(" [!] HTTP GET failed, error: %d (%s)\n", httpCode, http.errorToString(httpCode).c_str());
    }
    http.end();
}

// --- [EXISTING] Capture Photo and Upload (for Facial Verification) ---
void captureAndUploadPhoto(String logId, String studentPhotoPath) {
    Serial.println("Capturing photo (Facial Verification)...");
    camera_fb_t * fb = NULL;
    sensor_t * s = esp_camera_sensor_get();

    // --- [MODIFIED] Camera settings for potentially faster capture ---
    s->set_framesize(s, FRAMESIZE_VGA); // Reduced from SXGA to VGA for speed
    delay(50); // Shorter delay
    s->set_quality(s, 20); // Slightly lower quality for smaller file, faster upload
    s->set_gainceiling(s, GAINCEILING_32X);
    s->set_exposure_ctrl(s, 1);
    s->set_gain_ctrl(s, 1);
    s->set_awb_gain(s, 1);

    ledc_set_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH, 128);
    ledc_update_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH);
    delay(1000); // Wait for exposure, adjusted from 1500ms
    // --- [END MODIFIED] ---

    fb = esp_camera_fb_get();
    ledc_set_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH, 0);
    ledc_update_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH);
    if (!fb) {
        Serial.println("Camera capture failed!");
        s->set_framesize(s, FRAMESIZE_QVGA); // Reset to a small safe size
        return;
    }
    Serial.printf("Capture Success. Size: %d bytes\n", fb->len);

    // --- Upload Image ---
    HTTPClient http;
    http.begin(serverUrlUploadImage);
    String boundary = "----WebKitFormBoundary" + String(random(0, 0x7FFFFFFF), HEX);
    http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);
    String bodyStart = "--" + boundary + "\r\n";
    bodyStart += "Content-Disposition: form-data; name=\"access_log_id\"\r\n\r\n";
    bodyStart += logId + "\r\n";
    bodyStart += "--" + boundary + "\r\n";
    bodyStart += "Content-Disposition: form-data; name=\"student_photo_path\"\r\n\r\n";
    bodyStart += studentPhotoPath + "\r\n";
    bodyStart += "--" + boundary + "\r\n";
    bodyStart += "Content-Disposition: form-data; name=\"imageFile\"; filename=\"esp32_scan.jpg\"\r\n";
    bodyStart += "Content-Type: image/jpeg\r\n\r\n";
    String bodyEnd = "\r\n--" + boundary + "--\r\n";

    size_t totalLen = bodyStart.length() + fb->len + bodyEnd.length();
    uint8_t * payload_buf = (uint8_t *) ps_malloc(totalLen);
    if (!payload_buf) {
        Serial.println("Failed to allocate memory for upload payload!");
        esp_camera_fb_return(fb);
        http.end();
        return;
    }

    size_t offset = 0;
    memcpy(payload_buf + offset, bodyStart.c_str(), bodyStart.length());
    offset += bodyStart.length();
    memcpy(payload_buf + offset, fb->buf, fb->len);
    offset += fb->len;
    memcpy(payload_buf + offset, bodyEnd.c_str(), bodyEnd.length());

    int httpResponseCode = http.POST(payload_buf, totalLen);

    free(payload_buf);
    if (httpResponseCode > 0) {
        String response = http.getString();
        Serial.printf("HTTP Response code: %d\n", httpResponseCode);
        Serial.println("Server response: " + response);
    } else {
        Serial.printf("HTTP POST failed, error: %s\n", http.errorToString(httpResponseCode).c_str());
    }

    http.end();
    esp_camera_fb_return(fb);
    Serial.println("Image upload attempt finished.");
}


// --- [NEW] Test Capture (Offline) ---
void captureAndTestPhoto() {
    Serial.println("--- Test Capture (Offline) ---");
    // --- [MODIFIED] Camera settings for potentially faster capture ---
    sensor_t * s = esp_camera_sensor_get();
    s->set_framesize(s, FRAMESIZE_VGA); // Reduced from SXGA to VGA for speed
    delay(50); // Shorter delay
    s->set_quality(s, 20); // Slightly lower quality for smaller file, faster processing
    s->set_gainceiling(s, GAINCEILING_32X);
    s->set_exposure_ctrl(s, 1);
    s->set_gain_ctrl(s, 1);
    s->set_awb_gain(s, 1);
    // --- [END MODIFIED] ---

    Serial.println("Turning on flash (50%)...");
    ledc_set_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH, 128);
    ledc_update_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH);
    delay(500); // Shorter delay for test

    Serial.println("Getting frame buffer...");
    camera_fb_t * fb = esp_camera_fb_get();

    Serial.println("Turning off flash.");
    ledc_set_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH, 0);
    ledc_update_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH);
    
    if (!fb) {
        Serial.println("TEST CAPTURE FAILED!");
    } else {
        Serial.printf("TEST CAPTURE SUCCESS. Size: %d bytes. Discarding...\n", fb->len);
        esp_camera_fb_return(fb); // Discard the image
    }
    s->set_framesize(s, FRAMESIZE_SXGA); // Reset to default high-res after test
}

// --- [NEW] Capture Photo and Upload (for Intruder) ---
void captureAndUploadPhoto_Intruder() {
    Serial.println("--- Intruder Capture (Online) ---");
    camera_fb_t * fb = NULL;
    sensor_t * s = esp_camera_sensor_get();
    
    // --- [MODIFIED] Camera settings for potentially faster capture ---
    s->set_framesize(s, FRAMESIZE_VGA); // Reduced from SXGA to VGA for speed
    delay(50); // Shorter delay
    s->set_quality(s, 20); // Slightly lower quality for smaller file, faster upload
    s->set_gainceiling(s, GAINCEILING_32X);
    s->set_exposure_ctrl(s, 1);
    s->set_gain_ctrl(s, 1);
    s->set_awb_gain(s, 1);
    // --- [END MODIFIED] ---

    ledc_set_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH, 128);
    ledc_update_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH);
    delay(1000); // Wait for exposure, adjusted from 1500ms

    fb = esp_camera_fb_get();
    ledc_set_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH, 0);
    ledc_update_duty(LEDC_LOW_SPEED_MODE, (ledc_channel_t)LEDC_CHANNEL_FLASH);

    if (!fb) {
        Serial.println("Intruder capture FAILED!");
        s->set_framesize(s, FRAMESIZE_SXGA); // Reset to default high-res after failure
        return;
    }
    Serial.printf("Intruder capture Success. Size: %d bytes\n", fb->len);

    // --- Upload Image ---
    HTTPClient http;
    http.begin(serverUrlUploadImage_Intruder);
    String boundary = "----WebKitFormBoundary" + String(random(0, 0x7FFFFFFF), HEX);
    http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);

    String bodyStart = "--" + boundary + "\r\n";
    bodyStart += "Content-Disposition: form-data; name=\"imageFile\"; filename=\"intruder_esp32.jpg\"\r\n";
    bodyStart += "Content-Type: image/jpeg\r\n\r\n";
    String bodyEnd = "\r\n--" + boundary + "--\r\n";

    size_t totalLen = bodyStart.length() + fb->len + bodyEnd.length();
    uint8_t * payload_buf = (uint8_t *) ps_malloc(totalLen);
    if (!payload_buf) {
        Serial.println("Failed to allocate memory for upload payload!");
        esp_camera_fb_return(fb);
        http.end();
        return;
    }

    size_t offset = 0;
    memcpy(payload_buf + offset, bodyStart.c_str(), bodyStart.length());
    offset += bodyStart.length();
    memcpy(payload_buf + offset, fb->buf, fb->len);
    offset += fb->len;
    memcpy(payload_buf + offset, bodyEnd.c_str(), bodyEnd.length());

    int httpResponseCode = http.POST(payload_buf, totalLen);

    free(payload_buf);
    if (httpResponseCode > 0) {
        String response = http.getString();
        Serial.printf("HTTP Response code: %d\n", httpResponseCode);
        Serial.println("Server response: " + response);
    } else {
        Serial.printf("HTTP POST failed, error: %s\n", http.errorToString(httpResponseCode).c_str());
    }

    http.end();
    esp_camera_fb_return(fb);
    Serial.println("Intruder image upload attempt finished.");
    s->set_framesize(s, FRAMESIZE_SXGA); // Reset to default high-res after capture
}


// --- [EXISTING] Initialize Camera (SXGA) ---
bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM; config.pin_d1 = Y3_GPIO_NUM; config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM; config.pin_d4 = Y6_GPIO_NUM; config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM; config.pin_d7 = Y9_GPIO_NUM; config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM; config.pin_vsync = VSYNC_GPIO_NUM; config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM; config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM; config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  if (psramFound()) {
    config.frame_size = FRAMESIZE_SXGA; // Default high-res for facial verification
    config.jpeg_quality = 10;
    config.fb_count = 2; config.fb_location = CAMERA_FB_IN_PSRAM;
  } else {
    config.frame_size = FRAMESIZE_VGA;
    config.jpeg_quality = 12;
    config.fb_count = 1; config.fb_location = CAMERA_FB_IN_DRAM;
  }
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) { Serial.printf("Camera init failed 0x%x", err); return false; }

  sensor_t * s = esp_camera_sensor_get();
  if (s) {
    s->set_vflip(s, 1);
    s->set_hmirror(s, 1);
    Serial.println("Camera sensor rotated 180 degrees.");
  } else {
    Serial.println("Failed to get sensor for rotation.");
  }

  return true;
}