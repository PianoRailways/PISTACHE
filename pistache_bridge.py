import socket
import xml.etree.ElementTree as ET
import requests
import time
import sys

# =========================================================================
# KONFIGURATION
# =========================================================================
STS_HOST = "127.0.0.1"
STS_PORT = 3691
RCS_URL = "https://rcs.stellwerksim.ch/pistache.php"
POLLING_INTERVAL = 60
DEBUG_XML = True
# =========================================================================

SPIELER_NAME = "Unbekannter Spieler"
STELLWERK_NAME = "Unbekanntes Stellwerk"

def send_to_rcs(sts_zid, station_abbr, delay):
    payload = {
        'action': 'plugin_update_delay',
        'sts_zid': str(sts_zid),
        'station_abbr': str(station_abbr),
        'delay': int(delay),
        'sts_user': SPIELER_NAME,
        'sts_sim': STELLWERK_NAME
    }
    print(f"   [POST] Sende an RCS -> ZID: {sts_zid}, Halt: {station_abbr}, Verspätung: {delay} Min...")
    try:
        response = requests.post(RCS_URL, data=payload, timeout=8)
        if response.status_code == 200:
            try:
                res_json = response.json()
                msg = res_json.get('message', str(res_json))
                if res_json.get('success'):
                    print(f"   -> 🎉 [RCS ERFOLG] {msg}")
                else:
                    print(f"   -> ℹ️ [RCS INFO] {msg}")
            except ValueError:
                print(f"   -> ⚠️ [WARNUNG] Server-Antwort war kein valides JSON.")
        else:
            print(f"   -> ❌ [FEHLER] Serverfehler-Text: {response.text[:200]}")
    except requests.exceptions.RequestException as e:
        print(f"   -> ❌ [NETZWERKFEHLER] Verbindung fehlgeschlagen: {e}")

def read_xml_response(sock, expected_end_tag=None):
    """ Liest Daten blockweise. Schützt vor unendlichem Hängen durch Timeout. """
    buffer = ""
    sock.settimeout(5.0) # 5 Sekunden Netzwerk-Timeout beim Lesen
    try:
        while True:
            chunk = sock.recv(4096).decode('utf-8')
            if not chunk:
                return None
            buffer += chunk
            
            if expected_end_tag and expected_end_tag in buffer:
                return buffer
                
            if not expected_end_tag:
                stripped = buffer.strip()
                if stripped.endswith('/>') or stripped.endswith('</status>') or stripped.endswith('</zugdetails>') or stripped.endswith('</anlageninfo>'):
                    return buffer
    except socket.timeout:
        print("⚠️ [Timeout] Der Simulator hat nicht rechtzeitig geantwortet.")
        return None if buffer == "" else buffer

def parse_delay(verspaetung_str):
    if not verspaetung_str:
        return 0
    try:
        return int(verspaetung_str.replace('+', ''))
    except ValueError:
        return 0

def main():
    global SPIELER_NAME, STELLWERK_NAME
    print("==========================================================")
    print("      Stellwerksim zu RCS Bridge (ALL-TRAINS ACTIVE)      ")
    print("==========================================================")
    print(f"Verbinde zu lokalem STS-Spiel ({STS_HOST}:{STS_PORT})...")
    
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect((STS_HOST, STS_PORT))
        print("[Erfolg] TCP-Verbindung steht.")
    except Exception as e:
        print(f"[Fehler] Keine Verbindung zum STS-Spiel: {e}")
        sys.exit(1)

    # 1. Willkommens-Status (Code 300) einlesen und wegarbeiten
    print("[STS] Warte auf Willkommensnachricht...")
    init_msg = read_xml_response(s, expected_end_tag="</status>")
    if init_msg:
        print(f"[STS Empfangen] {init_msg.strip()}")

    # 2. Registrieren (MUSS zwingend als Erstes passieren!)
    print("[STS Senden] Registriere das RCS-Plugin...")
    register_xml = "<register name='RCS-Pistache-Bridge' autor='Dispo' version='1.6' protokoll='1' text='Live-Exporter' />\n"
    s.sendall(register_xml.encode('utf-8'))
    
    reg_status = read_xml_response(s, expected_end_tag="</status>")
    if reg_status:
        print(f"[STS Empfangen] {reg_status.strip()}")
    
    if not reg_status or "code='220'" not in reg_status:
        print("[Fehler] Registrierung wurde vom Simulator verweigert oder kam nicht an!")
        s.close()
        return

    # 3. Erst JETZT, nach erfolgreicher Registrierung, darf die Anlageninfo abgefragt werden
    print("[STS Senden] <anlageninfo />")
    s.sendall(b"<anlageninfo />\n")
    info_data = read_xml_response(s, expected_end_tag="</anlageninfo>")
    
    if info_data:
        try:
            info_root = ET.fromstring(info_data.strip())
            if info_root.tag == "anlageninfo":
                STELLWERK_NAME = info_root.get('name', 'Unbekanntes Stellwerk')
                SPIELER_NAME = info_root.get('user', 'PISTACHE-Client')
                print(f"🌍 Spielsitzung erkannt!")
                print(f"   -> Stellwerk: {STELLWERK_NAME}")
                print(f"   -> Spieler:   {SPIELER_NAME}")
        except Exception as ex:
            print(f"⚠️ Konnte Anlageninfo nicht parsen: {ex}")
    else:
        print("⚠️ Keine Anlageninfo erhalten. Fahre mit Standardnamen fort.")

    print("\n[Bereit] Starte Überwachungsschleife...")
    s.settimeout(None) # Timeout für die Dauerschleife wieder deaktivieren

    try:
        while True:
            print(f"\n==================== DURCHLAUF: {time.strftime('%H:%M:%S')} ====================")
            s.sendall(b"<zugliste />\n")
            xml_data = read_xml_response(s, expected_end_tag="</zugliste>")
            if not xml_data:
                print("[Verbindung verloren] Keine Daten vom Simulator erhalten.")
                break
                
            try:
                root = ET.fromstring(xml_data.strip())
                if root.tag == "zugliste":
                    zug_elements = root.findall('zug')
                    for zug in zug_elements:
                        zid = zug.get('zid')
                        name = zug.get('name')
                        
                        s.sendall(f"<zugdetails zid='{zid}' />\n".encode('utf-8'))
                        details_data = read_xml_response(s, expected_end_tag=None)
                        if not details_data:
                            continue
                            
                        details_root = ET.fromstring(details_data.strip())
                        if details_root.tag == "zugdetails":
                            verspaetung_str = details_root.get('verspaetung', '0')
                            aktuelles_gleis = details_root.get('gleis', '')
                            sichtbar = details_root.get('sichtbar', 'false')
                            
                            delay_minutes = parse_delay(verspaetung_str)
                            
                            if sichtbar == 'true' and aktuelles_gleis:
                                print(f"   [ZUG AKTIV] {name} (ZID: {zid}) auf Gleis {aktuelles_gleis}")
                                send_to_rcs(zid, aktuelles_gleis, delay_minutes)
                                
                            elif sichtbar == 'false':
                                # Fahrplan für den noch unsichtbaren Zug abrufen
                                s.sendall(f"<zugfahrplan zid='{zid}' />\n".encode('utf-8'))
                                fahrplan_data = read_xml_response(s, expected_end_tag="</zugfahrplan>")
                                
                                station_placeholder = None
                                if fahrplan_data:
                                    try:
                                        fplan_root = ET.fromstring(fahrplan_data.strip())
                                        erster_halt = fplan_root.find('halt')
                                        if erster_halt is not None:
                                            station_placeholder = erster_halt.get('mgl')
                                    except Exception:
                                        pass
                                
                                # Nur senden, wenn der erste Halt im Fahrplan ermittelt werden konnte
                                if station_placeholder:
                                    print(f"   [ZUG VORANKÜNDIGUNG] {name} (ZID: {zid}) im Zulauf, gemeldet an: {station_placeholder}")
                                    send_to_rcs(zid, station_placeholder, delay_minutes)
                                else:
                                    print(f"   [ZUG VORANKÜNDIGUNG] {name} (ZID: {zid}) im Zulauf, aber noch kein Fahrplanhalt verfügbar. Überspringe...")
                                
            except Exception as e:
                print(f"❌ Fehler in Schleife: {e}")

            time.sleep(POLLING_INTERVAL)
    except KeyboardInterrupt:
        print("\nBeendet.")
    finally:
        s.close()

if __name__ == "__main__":
    main()
