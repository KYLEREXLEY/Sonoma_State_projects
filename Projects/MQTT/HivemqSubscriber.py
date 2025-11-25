import paho.mqtt.client as mqtt
import mysql.connector
from mysql.connector import Error

# ---------- MQTT CONFIG ----------
BROKER_URL  = "broker.mqtt-dashboard.com"
BROKER_PORT = 1883
TOPIC       = "testtopic/temp/outTopic/kyler"

# ---------- DB CONFIG (Hostinger) ----------
HOST     = ""
USER     = ""
PASSWORD = ""
DATABASE = ""

# ---------- DB HELPER ----------
def push_value_to_db(sensor_value_str):
    try:
        value = float(sensor_value_str)
    except ValueError:
        print(f"Received non-numeric payload, ignoring: {sensor_value_str}")
        return

    try:
        connection = mysql.connector.connect(
            host=HOST,
            user=USER,
            password=PASSWORD,
            database=DATABASE
        )

        if connection.is_connected():
            cursor = connection.cursor()
            insert_query = "INSERT INTO sensor_value (value) VALUES (%s)"
            cursor.execute(insert_query, (value,))
            connection.commit()
            print(f"Stored {value} in sensor_value table.")

    except Error as err:
        print(f"DB error: {err}")

    finally:
        try:
            if connection.is_connected():
                cursor.close()
                connection.close()
        except Exception:
            pass

# ---------- MQTT CALLBACKS ----------
def on_connect(client, userdata, flags, rc):
    if rc == 0:
        print("Connected to MQTT broker!")
        client.subscribe(TOPIC)
        print(f"Subscribed to topic: {TOPIC}")
    else:
        print(f"Failed to connect, return code {rc}")

def on_message(client, userdata, msg):
    payload_str = msg.payload.decode().strip()
    print(f"Received message: {payload_str} from topic: {msg.topic}")
    push_value_to_db(payload_str)

# ---------- MAIN ----------
# NOTE: no transport="websockets" here now
client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message

print("Connecting to broker...")
client.connect(BROKER_URL, BROKER_PORT, 60)

client.loop_forever()
