/*
 * ----------------------------------------------
 * Project/Program Name : <RGB+LED web Control>
 * File Name            : <main.cpp>
 * Author               : <Kyler Exley>
 * Date                 : <11/3/2025>
 * Version              : <v1>
 * 
 * Purpose:
 *    To connect an RGB LED and a button to the esp8266 microcontroller and be able to control the led and rgb values
 *    from a web page and display the values on the webpage using google sheets API
 * Inputs:
 *   - Pushbutton on PIN_BTN (D1): triggers to send and recieves information from webpage
 *   - Wi-Fi credentials (WIFI_SSID / WIFI_PASS)
 * Outputs:
 *    - RGB Colors
 *    - LED ON/OFF
 *    - Database(googlesheets)/webpage shows RGB values, LED values, with charts
 * Example Application:
 *    - Want to crontol a device from the web page without having to be near said device
 * Dependencies:
 *    - <Arduino.h>, <ESP8266WiFi.h>, <ESP8266HTTPClient.h>, <WiFiClientSecure.h>, <ArduinoJson.h>, 
 * Usage Notes:
 *    This code on button press first reads values from webpage to turn RGB/LED to those values
 *    Then sends current status of the values back to webpage to show that everything is working
 * ---------------------------------------------------------------------------
 */
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <ArduinoJson.h>

// ===== CONFIG =====
const char* WIFI_SSID = "ID";
const char* WIFI_PASS = "password";

// Hostinger endpoints
const char* LED_API = "https://kylerexley.com/led.php?api=1";  // GET JSON
const char* LED_PUT = "https://kylerexley.com/led.php";        // PUT {"led":"ON|OFF"}
const char* RGB_API = "https://kylerexley.com/rgb.php?api=1";  // GET JSON

// Google Apps Script Web App (/exec)
const char* GSCRIPT_POST = "https://script.google.com/macros/s/APP ID/exec";

// Pins
const int LED_PIN = 2;  // D4 onboard (active-LOW)
const bool LED_ACTIVE_LOW = true;

const int R_PIN = 14; // D5
const int G_PIN = 12; // D6
const int B_PIN = 13; // D7
const bool COMMON_ANODE = false;

const int BTN = 5;    // D1 Button
const int SENSOR_PIN = A0; // unused 

// ===== tiny queue =====
struct Req { String m,u,b; };
static const int QMAX=12; Req Q[QMAX]; int qh=0, qt=0;
bool qEmpty(){ return qh==qt; }
bool enqueue(const String&m,const String&u,const String&b=""){
  int nx=(qt+1)%QMAX; if(nx==qh) return false; Q[qt]={m,u,b}; qt=nx; return true;
}
bool httpSend(const String& method, const String& url, const String& body, String* out=nullptr){
  HTTPClient http; int code=-1;
  if(url.startsWith("https://")){
    std::unique_ptr<BearSSL::WiFiClientSecure> client(new BearSSL::WiFiClientSecure);
    client->setInsecure();
    if(!http.begin(*client, url)) return false;
  } else {
    WiFiClient client; if(!http.begin(client, url)) return false;
  }
  if(method=="GET") code=http.GET();
  else if(method=="POST"){ http.addHeader("Content-Type","application/json"); code=http.POST(body); }
  else if(method=="PUT"){ http.addHeader("Content-Type","application/json"); code=http.sendRequest("PUT", body); }
  if(code>0 && out) *out=http.getString();
  http.end();
  return code==200;
}
void flushOne(){ if(qEmpty()||WiFi.status()!=WL_CONNECTED) return; Req r=Q[qh]; qh=(qh+1)%QMAX; httpSend(r.m,r.u,r.b,nullptr); }

// ===== device state =====
bool led_state=false;
uint8_t curR=0, curG=0, curB=0;

void setLED(bool on){
  led_state=on;
  int lvl = on ? (LED_ACTIVE_LOW?LOW:HIGH) : (LED_ACTIVE_LOW?HIGH:LOW);
  pinMode(LED_PIN, OUTPUT); digitalWrite(LED_PIN, lvl);
}
void setRGB(uint8_t r,uint8_t g,uint8_t b){
  curR=r; curG=g; curB=b;
  if(COMMON_ANODE){ r=255-r; g=255-g; b=255-b; }
  analogWriteRange(255); analogWriteFreq(1200);
  pinMode(R_PIN,OUTPUT); pinMode(G_PIN,OUTPUT); pinMode(B_PIN,OUTPUT);
  analogWrite(R_PIN,r); analogWrite(G_PIN,g); analogWrite(B_PIN,b);
}

// ===== actions =====
void logLED_RGB(){ // POST current LED (0/1) and RGB to Sheets
  JsonDocument d;
  d["led"] = led_state ? 1 : 0;
  d["r"] = curR; d["g"] = curG; d["b"] = curB;
  // omit ts → server time; add d["ts"]=epoch_ms if you run NTP
  String body; serializeJson(d, body);
  enqueue("POST", GSCRIPT_POST, body);
}

void checkServer(){ // update device from Hostinger
  String res;
  if(httpSend("GET", LED_API, "", &res)){
    JsonDocument d; if(!deserializeJson(d,res)){ setLED((String(d["led"]| "OFF")=="ON")); }
  }
  res="";
  if(httpSend("GET", RGB_API, "", &res)){
    JsonDocument d; if(!deserializeJson(d,res)){
      setRGB(uint8_t(d["r"]|0), uint8_t(d["g"]|0), uint8_t(d["b"]|0));
    }
  }
}

void toggleAndSync(){
  bool next=!led_state; setLED(next);
  JsonDocument d; d["led"]= next? "ON":"OFF"; String body; serializeJson(d, body);
  enqueue("PUT", LED_PUT, body);
}

// ===== one-button gestures =====
void handleButton() {
  // Active-LOW button, fire once on a quick tap (release)
  static bool last = HIGH;
  static uint32_t lastChange = 0;
  static uint32_t lastAction = 0;

  const uint16_t DEBOUNCE_MS = 25;   // smaller = snappier, still robust
  const uint16_t COOLDOWN_MS = 120;  // ignore re-triggers for 120 ms

  bool s = digitalRead(BTN);         // HIGH = idle, LOW = pressed
  uint32_t now = millis();
  // edge detected with debounce
  if (s != last && (now - lastChange) > DEBOUNCE_MS) {
    lastChange = now;
    // rising edge = button released -> run the "hold" logic instantly
    if (last == LOW && s == HIGH) {
      if (now - lastAction > COOLDOWN_MS) {
        checkServer();   // pulls LED + RGB from led.php/rgb.php (2x GET)
        logLED_RGB();    // posts current LED(0/1)+R/G/B to Apps Script (enqueue)
        lastAction = now;
      }
    }
    last = s;
  }
}

void wifiConnect(){
  WiFi.mode(WIFI_STA); WiFi.begin(WIFI_SSID,WIFI_PASS);
  uint32_t t0=millis(); while(WiFi.status()!=WL_CONNECTED && millis()-t0<15000) delay(200);
}

void setup(){
  setLED(false); setRGB(0,0,0);
  pinMode(BTN, INPUT_PULLUP);
  wifiConnect();
}

void loop(){
  handleButton();
  flushOne();
  delay(10);
}
