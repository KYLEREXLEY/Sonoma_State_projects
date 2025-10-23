/*
 * ----------------------------------------------
 * Project/Program Name : <Humidity/Temp sensor2database>
 * File Name            : <main.cpp>
 * Author               : <Kyler Exley>
 * Date                 : <23/10/2025>
 * Version              : <v1>
 * 
 * Purpose:
 *    To connect a humidity and temp sensor to the esp8266 controlled by a tilt and pushbutton to send  
 *    the data to a database/webpage that must be documented in time useing timeAPI.io for what the time is
 * Inputs:
 *   - DHT11 sensor on PIN_DHT (D7): provides temperature (°C) and humidity (%RH)
 *   - Pushbutton on PIN_BTN (D5): triggers a humidity-only upload as node_1
 *   - Tilt switch on PIN_TILT (D6): triggers a temperature-only upload as node_2
 *   - Serial menu at startup: user selects IANA timezone (e.g., America/Los_Angeles)
 *   - Wi-Fi credentials (WIFI_SSID / WIFI_PASS)
 * Outputs:
 *    - HTTPS GET request to sensorDB.php with fields:
 *        node_name, time_received (ISO-8601), tz (IANA), temperature, humidity
 *    - Serial Monitor logs for status/errors
 *    - Database/webpage shows register table, data table (incl. tz), and charts
 * Example Application:
 *    - IoT lab exercise or small deployment that logs environmental conditions
 *      (temperature/humidity) on demand, tagged with accurate local time, to a web dashboard.
 * Dependencies:
 *    - <Arduino.h>, <ESP8266WiFi.h>, <ESP8266HTTPClient.h>, <WiFiClientSecure.h>, <ArduinoJson.h>, "DHTesp.h"
 *    - Remote services: timeAPI.io (current time by timezone), sensorDB.php backend (MySQL)
 * Usage Notes:
 *    This file is used as a base of operations for the code where all the functions are declared and controlled here
 *    but the functions are all defined in the app.cpp file
 * ---------------------------------------------------------------------------
 */
#include <Arduino.h>

void app_setup();
void check_switch();
String read_time();
float  read_sensor_1();
float  read_sensor_2();
void   transmit();
void   check_error();

void setup() {
  app_setup();
}

void loop() {
  check_switch();
  read_time();
  read_sensor_1();
  read_sensor_2();
  transmit();
  check_error();
}
