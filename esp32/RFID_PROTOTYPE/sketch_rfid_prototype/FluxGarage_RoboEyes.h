/*
 * FluxGarage RoboEyes for TFT Displays (ST7789 Adapted)
 * Modified to work with Adafruit ST7789 / Adafruit_GFX (no OLED-only calls).
 *
 * Original: FluxGarage RoboEyes V 1.1.1
 * Modified by ChatGPT for TFT use
 */

#ifndef _FLUXGARAGE_ROBOEYES_H
#define _FLUXGARAGE_ROBOEYES_H

#include <Adafruit_GFX.h>
#include <Adafruit_ST7789.h>

// Display colors (16-bit TFT values)
uint16_t BGCOLOR   = ST77XX_BLACK;  // background
uint16_t MAINCOLOR = ST77XX_WHITE;  // drawings

// For mood type switch
#define DEFAULT 0
#define TIRED   1
#define ANGRY   2
#define HAPPY   3

// For turning things on or off
#define ON  1
#define OFF 0

// For switch "predefined positions"
#define N  1
#define NE 2
#define E  3
#define SE 4
#define S  5
#define SW 6
#define W  7
#define NW 8
// for middle center set "DEFAULT"

// Constructor: takes a reference to the active Adafruit display object
template<typename AdafruitDisplay>
class RoboEyes {
public:
  AdafruitDisplay *display;

  // Screen + timing
  int screenWidth = 128;
  int screenHeight = 64;
  int frameInterval = 20;
  unsigned long fpsTimer = 0;

  // Mood states
  bool tired = 0;
  bool angry = 0;
  bool happy = 0;
  bool curious = 0;
  bool cyclops = 0;
  bool eyeL_open = 0;
  bool eyeR_open = 0;

  // Geometry defaults
  int eyeLwidthDefault = 36;
  int eyeLheightDefault = 36;
  int eyeLwidthCurrent = 36;
  int eyeLheightCurrent = 1;
  int eyeLwidthNext = 36;
  int eyeLheightNext = 36;
  int eyeLheightOffset = 0;
  byte eyeLborderRadiusDefault = 8;
  byte eyeLborderRadiusCurrent = 8;
  byte eyeLborderRadiusNext = 8;

  int eyeRwidthDefault = 36;
  int eyeRheightDefault = 36;
  int eyeRwidthCurrent = 36;
  int eyeRheightCurrent = 1;
  int eyeRwidthNext = 36;
  int eyeRheightNext = 36;
  int eyeRheightOffset = 0;
  byte eyeRborderRadiusDefault = 8;
  byte eyeRborderRadiusCurrent = 8;
  byte eyeRborderRadiusNext = 8;

  int spaceBetweenDefault = 10;
  int spaceBetweenCurrent = 10;
  int spaceBetweenNext = 10;

  int eyeLx = 30;
  int eyeLy = 40;
  int eyeRx = 80;
  int eyeRy = 40;
  int eyeLxNext = 30;
  int eyeLyNext = 40;
  int eyeRxNext = 80;
  int eyeRyNext = 40;

  // Macro animation
  bool autoblinker = 0;
  int blinkInterval = 1;
  int blinkIntervalVariation = 4;
  unsigned long blinktimer = 0;

  bool idle = 0;
  int idleInterval = 1;
  int idleIntervalVariation = 3;
  unsigned long idleAnimationTimer = 0;

  bool hFlicker = 0;
  bool hFlickerAlternate = 0;
  byte hFlickerAmplitude = 2;

  bool vFlicker = 0;
  bool vFlickerAlternate = 0;
  byte vFlickerAmplitude = 10;

  // Constructor
  RoboEyes(AdafruitDisplay &disp) : display(&disp) {}

  // Init
  void begin(int width, int height, byte frameRate) {
    screenWidth = width;
    screenHeight = height;
    display->fillScreen(BGCOLOR);
    eyeLheightCurrent = 1;
    eyeRheightCurrent = 1;
    setFramerate(frameRate);
  }

  void update() {
    if (millis() - fpsTimer >= frameInterval) {
      drawEyes();
      fpsTimer = millis();
    }
  }

  void setFramerate(byte fps) { frameInterval = 1000 / fps; }

  // Main draw
  void drawEyes() {
    // === CLEAR TFT (instead of clearDisplay/display) ===
    display->fillScreen(BGCOLOR);

    // Left eye
    display->fillRoundRect(eyeLx, eyeLy, eyeLwidthCurrent, eyeLheightCurrent,
                           eyeLborderRadiusCurrent, MAINCOLOR);

    // Right eye (unless cyclops mode)
    if (!cyclops) {
      display->fillRoundRect(eyeRx, eyeRy, eyeRwidthCurrent, eyeRheightCurrent,
                             eyeRborderRadiusCurrent, MAINCOLOR);
    }

    // Pupils
    display->fillCircle(eyeLx + eyeLwidthCurrent / 2,
                        eyeLy + eyeLheightCurrent / 2,
                        6, ST77XX_BLACK);
    if (!cyclops) {
      display->fillCircle(eyeRx + eyeRwidthCurrent / 2,
                          eyeRy + eyeRheightCurrent / 2,
                          6, ST77XX_BLACK);
    }
  }

  // Animations
  void blink() {
    eyeLheightCurrent = 10;
    eyeRheightCurrent = 10;
  }

  void openEyes() {
    eyeLheightCurrent = eyeLheightDefault;
    eyeRheightCurrent = eyeRheightDefault;
  }
};

#endif
