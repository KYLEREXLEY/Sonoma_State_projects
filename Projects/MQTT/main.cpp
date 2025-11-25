//-----------------------------
// Title: MQTT
//-----------------------------
// Program Details:
//-----------------------------
// Purpose: Conenct to broker.mqtt-dashboard.com, Publish and subscribe
// Dependencies: Make sure you have installed PubSubClient.h
// Compiler: PIO Version 1.72.0
// Author: Kyler Exley
// OUTPUT: publishes pot value on outTopic/kyler every 15 seconds and switch
//         value on outtopic/kyler/switch every 5 seconds
// INPUT: Received  led state from the broker on inTopic/kyler
// SETUP: To see the published values go to http://www.hivemq.com/demos/websocket-client/ 
//        subscribe to inTopic and outTopic. You can also create an APP using MQTT Dash
// Versions: 
//  v1: Nov-25-2025 
//-----------------------------

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include<PubSubClient.h>

// ------------ WiFi CONFIG ------------
const char* ssid     = "";   // <-- WiFi SSID
const char* password = "";       // <-- WiFi password

// ------------ MQTT CONFIG ------------
const char* mqtt_server = "broker.mqtt-dashboard.com";

const int   mqtt_port   = 1883;     // or 8884 if you have TLS set up

// Topics
const char* potPublishTopic    = "testtopic/temp/outTopic/kyler";
const char* switchPublishTopic = "testtopic/temp/outTopic/kyler/switch";
const char* subscribeTopic     = "testtopic/temp/inTopic/kyler";  // LED control

// ------------ TIMING ------------
const unsigned long potPublishInterval = 15000UL;  // 15 s
const unsigned long switchHoldTime     = 5000UL;   // 5 s after press send "0"

// ------------ PINS ------------
const int POT_PIN    = A0;
const int SWITCH_PIN = D5;             // GPIO14, button to GND, INPUT_PULLUP
const int LED_PIN    = LED_BUILTIN;    // active LOW 

// ------------ GLOBALS ------------
WiFiClient espClient;
PubSubClient client(espClient);

#define MSG_BUFFER_SIZE (50)
char msg[MSG_BUFFER_SIZE];

unsigned long lastPotPublish = 0;
bool ledStatus = false;

// switch edge detection
bool lastSwitchReading    = HIGH;  // INPUT_PULLUP
bool switchTimerActive    = false;
unsigned long switchStart = 0;

// ------------- HELPERS --------------
void setLed(bool on) {
  ledStatus = on;
  // Built-in LED is active LOW
  digitalWrite(LED_PIN, on ? LOW : HIGH);
}

void setup_wifi() {
  delay(10);
  Serial.println();
  Serial.print("Connecting to WiFi: ");
  Serial.println(ssid);

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print('.');
  }

  Serial.println("\nWiFi connected");
  Serial.print("IP address: ");
  Serial.println(WiFi.localIP());
}

// MQTT callback: called whenever a subscribed message arrives
void callback(char* topic, byte* payload, unsigned int length) {
  Serial.print("Message arrived [");
  Serial.print(topic);
  Serial.print("] ");

  // copy payload into a null-terminated buffer
  unsigned int len = (length < MSG_BUFFER_SIZE - 1) ? length : MSG_BUFFER_SIZE - 1;
  for (unsigned int i = 0; i < len; i++) {
    msg[i] = (char)payload[i];
    Serial.print(msg[i]);
  }
  msg[len] = '\0';
  Serial.println();

  // LED control from subscribeTopic
  if (strcmp(topic, subscribeTopic) == 0) {
    if (msg[0] == '1') {
      setLed(true);
      Serial.println("LED turned ON from MQTT");
    } else if (msg[0] == '0') {
      setLed(false);
      Serial.println("LED turned OFF from MQTT");
    }
  }
}

// MQTT reconnect
void reconnect() {
  while (!client.connected()) {
    Serial.print("Attempting MQTT connection...");
    String clientId = "ESP8266Client-";
    clientId += String(random(0xffff), HEX);

    if (client.connect(clientId.c_str())) {
      Serial.println("connected");
      client.subscribe(subscribeTopic);  // LED control
      Serial.print("Subscribed to: ");
      Serial.println(subscribeTopic);
    } else {
      Serial.print("failed, rc=");
      Serial.print(client.state());
      Serial.println(" – retrying in 5 seconds");
      delay(5000);
    }
  }
}

// Read serial for part A: type '1' or '0' to control LED
void handleSerialLed() {
  if (Serial.available() > 0) {
    char c = Serial.read();
    if (c == '1') {
      setLed(true);
      Serial.println("LED ON (from Serial)");
    } else if (c == '0') {
      setLed(false);
      Serial.println("LED OFF (from Serial)");
    }
  }
}

// Part A: print potentiometer & switch state to terminal
void printLocalInputs() {
  static unsigned long lastPrint = 0;
  unsigned long now = millis();
  if (now - lastPrint >= 1000) {   // once per second just for local debugging
    lastPrint = now;

    int raw = analogRead(POT_PIN);
    float voltage = (raw / 1023.0) * 3.3; // assuming 3.3 V reference

    Serial.print("Potentiometer: raw=");
    Serial.print(raw);
    Serial.print("  voltage=");
    Serial.print(voltage, 2);
    Serial.println(" V");

    bool pressed = (digitalRead(SWITCH_PIN) == LOW);
    if (pressed) {
      Serial.println("Switch: 1 (pressed)");
    } else {
      Serial.println("Switch: 0 (released)");
    }
  }
}

// Handle potentiometer publishing (every 15 s)
void handlePotPublish(unsigned long now) {
  if (now - lastPotPublish >= potPublishInterval) {
    lastPotPublish = now;

    int raw = analogRead(POT_PIN);

    // publish just the raw value (easiest for Python/db)
    snprintf(msg, MSG_BUFFER_SIZE, "%d", raw);
    Serial.print("Publishing potentiometer value: ");
    Serial.println(msg);
    client.publish(potPublishTopic, msg);
  }
}

// Handle switch press: send "1" on press, "0" after 5 s
void handleSwitch(unsigned long now) {
  bool reading = (digitalRead(SWITCH_PIN) == LOW); // LOW == pressed

  // detect rising edge: not pressed -> pressed
  if (!lastSwitchReading && reading) {
    // press started
    Serial.println("Switch pressed; sending 1 to MQTT");
    client.publish(switchPublishTopic, "1");
    switchTimerActive = true;
    switchStart = now;
  }
  lastSwitchReading = reading;

  // after 5 seconds send 0 once
  if (switchTimerActive && (now - switchStart >= switchHoldTime)) {
    Serial.println("5 seconds elapsed; sending 0 to MQTT (switch released)");
    client.publish(switchPublishTopic, "0");
    switchTimerActive = false;
  }
}

// ------------- SETUP & LOOP -------------
void setup() {
  pinMode(LED_PIN, OUTPUT);
  setLed(false);                      // LED off at start

  pinMode(POT_PIN, INPUT);
  pinMode(SWITCH_PIN, INPUT_PULLUP);  // button to GND

  Serial.begin(9600);
  delay(100);

  setup_wifi();

  client.setServer(mqtt_server, mqtt_port);
  client.setCallback(callback);
}

void loop() {
  if (!client.connected()) {
    reconnect();
  }
  client.loop();

  unsigned long now = millis();

  handleSerialLed();
  printLocalInputs();
  handlePotPublish(now);
  handleSwitch(now);
}
