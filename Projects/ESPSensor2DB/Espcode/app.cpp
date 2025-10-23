#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include "DHTesp.h"

/* ===== Pins / WiFi / DB ===== */
#define PIN_DHT  D7
#define PIN_BTN  D5
#define PIN_TILT D6
static const char* WIFI_SSID="Galaxy S24 E409";
static const char* WIFI_PASS="Giants3322!";
static const char* DB_URL   ="https://kylerexley.com/sensorDB.php";

/* ===== Time zones (user must select) ===== */
struct Zone{ const char* label; const char* iana; };
static const Zone ZONES[] = {
  {"1: Eastern (ET)",          "America/New_York"},
  {"2: Central (CT)",          "America/Chicago"},
  {"3: Mountain (MT)",         "America/Denver"},
  {"4: Pacific (PT)",          "America/Los_Angeles"},
  {"5: Alaska (AKT)",          "America/Anchorage"},
  {"6: Hawaii-Aleutian (HAT)", "Pacific/Honolulu"},
  {"7: Atlantic (AT)",         "America/Puerto_Rico"}
};
static const int DEFAULT_ZONE_INDEX = 3; // Pacific
static String g_zoneIANA = ZONES[DEFAULT_ZONE_INDEX].iana;

/* ===== humidity/temp sensor DHT library setup ===== */
DHTesp dht;
struct TH{ float tC=NAN,hPct=NAN; };
struct NodeCache{ float t=NAN,h=NAN; String iso; };
static NodeCache node1,node2;
static String lastErr,lastTimeISO;
static bool needTime=false,trig1=false,trig2=false;

/* ===== small debounce/edge code for DHT library===== */
struct Edge{
  int pin,prev=HIGH; unsigned long last=0; const unsigned long db=80;
  void begin(){ pinMode(pin,INPUT); }
  bool fell(){ int v=digitalRead(pin); bool tr=(v==LOW&&prev==HIGH&&millis()-last>db); if(tr) last=millis(); prev=v; return tr; }
};
static Edge btn{PIN_BTN}, tilt{PIN_TILT};

/* ===== Encodeing data sent ===== */
static String urlEncode(const String& s){
  static const char hex[]="0123456789ABCDEF";
  String o; o.reserve(s.length()*2);
  for(size_t i=0;i<s.length();++i){ uint8_t c=(uint8_t)s[i];
    if((c>='0'&&c<='9')||(c>='A'&&c<='Z')||(c>='a'&&c<='z')||c=='-'||c=='_'||c=='.'||c=='~') o+=(char)c;
    else if(c==' ') o+="%20"; else { o+='%'; o+=hex[(c>>4)&0xF]; o+=hex[c&0xF]; }
  } return o;
}
static TH readTH(){ auto v=dht.getTempAndHumidity(); return TH{v.temperature,v.humidity}; }
static bool same(float a,float b){ return (isnan(a)&&isnan(b))||(!isnan(a)&&!isnan(b)&&fabs(a-b)<0.01f); }
static bool isDup(NodeCache& c,float t,float h,const String& iso){ return same(c.t,t)&&same(c.h,h)&&c.iso==iso; }

//code for forming the encoded url for transmition to database 
static String do_transmit(int node,const String& iso,float tC,float h){
  if(!iso.length()) return "No time in payload.";
  const char* name=(node==1)?"node_1":"node_2";
  String url=String(DB_URL)+"?node_name="+urlEncode(name)+"&time_received="+urlEncode(iso)+
             "&temperature="+(isnan(tC)?"nan":String(tC,2))+
             "&humidity="+(isnan(h)?"nan":String(h,1))+
             "&tz="+urlEncode(g_zoneIANA);
  WiFiClientSecure client; client.setInsecure(); HTTPClient https;
  if(!https.begin(client,url)) return "HTTPS begin() failed";
  int code=https.GET(); String resp=(code>0)?https.getString():""; https.end();
  if(code!=200) return String("HTTP ")+code+" "+resp;
  if(resp.indexOf("DUPLICATE")>=0) return "Server duplicate: "+resp;
  return "";
}

/* ===== Timezone selection function (Serial monitor) ===== */
static void pick_timezone(){
  Serial.println("\nSelect Time Zone (press Enter for default 4 = Pacific):");
  for(const auto& z: ZONES) Serial.println(z.label);
  Serial.print("> ");
  String line; unsigned long t0=millis();
  while(millis()-t0<15000){ while(Serial.available()){ char c=Serial.read(); if(c=='\r') continue; if(c=='\n'){ line.trim(); goto GOT; } line+=c; } }
GOT:
  if(line.length()==0){ g_zoneIANA = ZONES[DEFAULT_ZONE_INDEX].iana; Serial.printf("\nDefaulting to %s\n",g_zoneIANA.c_str()); }
  else{
    int pick=line.toInt();
    if(pick>=1 && pick<= (int)(sizeof(ZONES)/sizeof(ZONES[0]))){
      g_zoneIANA = ZONES[pick-1].iana; Serial.printf("Selected: %s\n",g_zoneIANA.c_str());
    }else{
      g_zoneIANA = ZONES[DEFAULT_ZONE_INDEX].iana; Serial.printf("Invalid; defaulting to %s\n",g_zoneIANA.c_str());
    }
  }
}

/* ===== function and dependency setup ===== */
void app_setup(){
  Serial.begin(9600); delay(50);
  dht.setup(PIN_DHT,DHTesp::DHT11);
  btn.begin(); tilt.begin();
  Serial.printf("WiFi: connecting to %s\n", WIFI_SSID);
  WiFi.begin(WIFI_SSID,WIFI_PASS);
  while(WiFi.status()!=WL_CONNECTED){ delay(200); Serial.print("."); }
  Serial.print("\nIP: "); Serial.println(WiFi.localIP());
  Serial.println(WiFi.macAddress());
  pick_timezone();
  Serial.println("Ready. Button=node_1 (Humidity); Tilt=node_2 (Temperature).");
}
//check if switches are triggered
void check_switch(){
  if(btn.fell()){ trig1=true; needTime=true; Serial.println("Node 1 triggered"); }
  if(tilt.fell()){ trig2=true; needTime=true; Serial.println("Node 2 triggered"); }
}

//reads time from timeAPI.io using http url after check switch has been switched to true then parses the datetime
String read_time(){
  if(needTime){
    WiFiClientSecure client; client.setInsecure(); HTTPClient https;
    String url="https://timeapi.io/api/Time/current/zone?timeZone="+g_zoneIANA;
    if(https.begin(client,url)){
      int code=https.GET();
      if(code>0){
        String body=https.getString();
        JsonDocument d;
        if(!deserializeJson(d,body)) lastTimeISO=d["dateTime"].as<String>();
        else lastErr="Time JSON parse error.";
      } else lastErr="Time API HTTP "+String(code);
      https.end();
    } else lastErr="Time API begin() failed.";
    needTime=false;
  }
  if(WiFi.status()!=WL_CONNECTED) WiFi.reconnect(); // resilience
  return lastTimeISO; // empty until first successful fetch after a trigger
}
/* ===== for monitoring only ===== */
float read_sensor_1(){ return readTH().tC; }   
float read_sensor_2(){ return readTH().hPct; }

//calls the do_transmit function to send time documented data to database when switches are triggered
static void send_node(bool isNode1){
  String iso=lastTimeISO; if(!iso.length()){ lastErr=isNode1?"No time (Node 1).":"No time (Node 2)."; return; }
  TH th=readTH(); float t=isNode1?NAN:th.tC, h=isNode1?th.hPct:NAN;
  NodeCache& c=isNode1?node1:node2;
  if(isDup(c,t,h,iso)){ lastErr=isNode1?"Duplicate (Node 1); not sent.":"Duplicate (Node 2); not sent."; return; }
  String e=do_transmit(isNode1?1:2,iso,t,h);
  if(e.length()) lastErr=e; else { c.t=t; c.h=h; c.iso=iso; Serial.println(isNode1?"OK: Node 1 sent":"OK: Node 2 sent"); }
}
//for 
void transmit(){
  if(trig1){ trig1=false; send_node(true); }
  if(trig2){ trig2=false; send_node(false); }
}
/* ===== for monitoring only ===== */
void check_error(){ if(lastErr.length()){ Serial.println("ERR: "+lastErr); lastErr=""; } }
