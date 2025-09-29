//-------------------------------
// Title: ESP8266 Lux Sensor Controller
//-------------------------------
//Program Detail:
//-------------------------------
// Purpose: Uses a photoresistor to control an RGB LED and Buzzer given different Lux levels
// Inputs: A0 Photoresistor voltage, and 'B' command
// Outputs: Active buzzer on D1, and RGB LED (common-cathode) on D5 (R), D6 (G), D7 (B)
// Date: 9/28/2025
// Compiler: VSCODE + PlatformIO
// Author: Kyler Exley
// Versions:
//   V1 - Calibrated Lux values for Photoresistor voltage readings
//-------------------------------
// File Dependencies: these are the listing and header files you need for the program
//-------------------------------
#include <Arduino.h>
//-------------------------------
// Main Program
//-------------------------------
// ---------------- Pins (ESP8266 + HW modules) ----------------
const uint8_t PIN_LDR_AO = A0;   // HW-486 AO → A0
const uint8_t PIN_BUZZER = D1;   // HW-512 IN → D1 (active HIGH)
const uint8_t PIN_R      = D5;   // HW-479 R → D5 (common-cathode)
const uint8_t PIN_G      = D6;   // HW-479 G → D6
const uint8_t PIN_B      = D7;   // HW-479 B → D7

// ---------------- Behavior ----------------
float THRESHOLD_LUX      = 20.0f;     // The variable X sets the buzzer threshold for Lux
const float LUX_FULL_RED = 600.0f;    // fully red by this lux

// ---------------- ADC ----------------
#define ADC_MAX   1023.0
#define ADC_VREF  3.2       // NodeMCU/Wemos A0 effective full-scale

// --------- Direct Vout→Lux fit (from your Excel trendline) ---------
// Trendline: Vout = A * ln(Lux) + B   (natural log)
// Invert:    Lux  = exp( (Vout - B) / A )
#define FIT_A   (-0.4037f)
#define FIT_B   ( 2.8917f)

// ---------------- Helper functions ----------------
inline void buzzerOn(bool on) { digitalWrite(PIN_BUZZER, on ? HIGH : LOW); }

// t=0 = white, t=1 = red (RGB is common-cathode)
inline void setRgbWhiteToRed(float t) {
  t = constrain(t, 0.0f, 1.0f);
  int R = 1023;
  int G = (int)((1.0f - t) * 1023.0f);
  int B = (int)((1.0f - t) * 1023.0f);
  analogWrite(PIN_R, R);
  analogWrite(PIN_G, G);
  analogWrite(PIN_B, B);
}

// If Lux less than threshold => t=0, else scale up to LUX_FULL_RED
inline float luxToBlend(float lux) {
  if (lux <= THRESHOLD_LUX) return 0.0f;
  float t = (lux - THRESHOLD_LUX) / max(1.0f, (LUX_FULL_RED - THRESHOLD_LUX));
  return constrain(t, 0.0f, 1.0f);
}

// Convert A0 counts → Vout (volts)
inline float adcToVout(int raw) {
  return (raw / ADC_MAX) * ADC_VREF;
}

// Direct Vout → Lux using ln-fit (natural log)
inline float luxFromVout(float vout) {
  // keep vout away from extremes to avoid huge exponents
  vout = constrain(vout, 0.05f, ADC_VREF - 0.05f);
  return expf((vout - FIT_B) / FIT_A); //Best fit log equation inverted for Finding LUX
}

// ---------------- Setup ----------------
void setup() {
  pinMode(PIN_R, OUTPUT);
  pinMode(PIN_G, OUTPUT);
  pinMode(PIN_B, OUTPUT);
  pinMode(PIN_BUZZER, OUTPUT);
  buzzerOn(false);

  analogWriteRange(1023);  // ESP8266 10-bit ADC
  setRgbWhiteToRed(0.0f);  // start white

  Serial.begin(9600);
  Serial.println(F("Type 'B' to buzz for 5 seconds."));
}

// ---------------- Loop (delay-based) ----------------
void loop() {
  // Serial command: buzzer test
  if (Serial.available() > 0) {
    char c = Serial.read();
    if (c == 'B' || c == 'b') {
      Serial.println(F("Buzzer ON (5s)"));
      buzzerOn(true); delay(5000); buzzerOn(false);
      Serial.println(F("Buzzer OFF"));
    }
  }

  // Read LDR and compute Lux directly from Vout
  int   raw   = analogRead(PIN_LDR_AO);   // 0..1023
  float vout  = adcToVout(raw);           // volts at A0
  float lux   = luxFromVout(vout);        // lux via ln-fit

  // Actuate and print (each on its own line)
  if (lux < THRESHOLD_LUX) {
    buzzerOn(true);
    setRgbWhiteToRed(0.0f); // white below threshold
    Serial.print(F("Lux="));   Serial.print(lux, 1);
    Serial.print(F("  Vout=")); Serial.print(vout, 3);
    Serial.println(F(" V  Buzzer=ON"));
  } else {
    buzzerOn(false);
    float t = luxToBlend(lux);
    setRgbWhiteToRed(t);
    Serial.print(F("Lux="));   Serial.print(lux, 1);
    Serial.print(F("  Vout=")); Serial.print(vout, 3);
    Serial.println(F(" V  Buzzer=OFF"));
  }

  delay(500);  // sample every 0.5 s
}
