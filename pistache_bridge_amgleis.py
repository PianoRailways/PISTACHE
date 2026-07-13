import socket
import xml.etree.ElementTree as ET
import requests
import time
import sys
import re

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

# ========== SMART DIFF: Letzte Delays merken ==========
last_reported_delays = {}  # Format: {zid: {'station_abbr': delay, ...}, ...}
# =====================================================

def extract_station_abbr(gleis_str):
    """Extrahiert Stationsabbreviatur aus Gleisnummer/Einfahrt: HWIL3 -> HWIL, TG 130 -> TG"""
    if not gleis_str:
        return None
    # Entferne Leerzeichen und nimm nur die Buchstaben am Anfang
    gleis_str = gleis_str.strip()
    match = re.match(r'^([A-Z]+)', gleis_str)
    if match:
        return match.group(1)
    return None

def send_to_rcs(sts_zid, station_abbr, delay, am_gleis=False, sichtbar=True):
    """Sendet Zugdaten an RCS mit amgleis und sichtbar Status.
    
    Smart Diff: Sendet nur wenn sich der Delay tatsächlich geändert hat.
    """
    global last_reported_delays
    
    # Erstelle eindeutigen Schlüssel für diese Zugposition
    key = f"{sts_zid}:{station_abbr}"
    
    # Prüfe ob wir diese Kombination schon kennen
    if sts_zid in last_reported_delays:
        last_delay = last_reported_delays[sts_zid].get(station_abbr)
        
        # Wenn sich nichts geändert hat: SKIP
        if last_delay is not None and last_delay == delay:
            print(f"   [⊘ SKIP] ZID: {sts_zid}, Halt: {station_abbr} → Delay unverändert (+{delay} Min), Update gespart")
            return
    
    # Änderung erkannt oder erste Meldung: Sende zu RCS
    sichtbar_str = "SICHTBAR" if sichtbar else "VORLAUF"
    am_gleis_str = "Am Gleis" if am_gleis else "Fahrplan"
    
    print(f"   [POST] Sende an RCS → ZID: {sts_zid}, Halt: {station_abbr}, Verspätung: +{delay} Min ({sichtbar_str}, {am_gleis_str})...")
    
    payload = {
        'action': 'plugin_update_delay',
        'sts_zid': str(sts_zid),
        'station_abbr': str(station_abbr),
        'delay': int(delay),
        'am_gleis': '1' if am_gleis else '0',
        'sichtbar': 'true' if sichtbar else 'false',
        'sts_user': SPIELER_NAME,
        'sts_sim': STELLWERK_NAME
    }
    
    try:
        response = requests.post(RCS_URL, data=payload, timeout=8)
        if response.status_code == 200:
            try:
                res_json = response.json()
                msg = res_json.get('message', str(res_json))
                if res_json.get('success'):
                    print(f"   -> 🎉 [RCS ERFOLG] {msg}")
                    # Speichere den gemeldeten Delay
                    if sts_zid not in last_reported_delays:
                        last_reported_delays[sts_zid] = {}
                    last_reported_delays[sts_zid][station_abbr] = delay
                else:
                    print(f"   -> ℹ️ [RCS INFO] {msg}")
            except ValueError:
                print(f"   -> ⚠️ [WARNUNG] Server-Antwort war kein valides JSON.")
        else:
            print(f"   -> ❌ [FEHLER] Serverfehler-Text: {response.text[:200]}")
    except requests.exceptions.RequestException as e:
        print(f"   -> ❌ [NETZWERKFEHLER] Verbindung fehlgeschlagen: {e}")

def read_xml_response(sock, expected_end_tag=None):
    """ Liest Daten blockweise mit Timeout-Schutz """
    buffer = ""
    sock.settimeout(5.0)
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
    print("  Stellwerksim zu RCS Bridge (INKL. VORLAUF-VERSPÄTUNG)   ")
    print("==========================================================")
    print(f"Verbinde zu lokalem STS-Spiel ({STS_HOST}:{STS_PORT})...")
    
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect((STS_HOST, STS_PORT))
        print("[Erfolg] TCP-Verbindung steht.")
    except Exception as e:
        print(f"[Fehler] Keine Verbindung zum STS-Spiel: {e}")
        sys.exit(1)

    print("[STS] Warte auf Willkommensnachricht...")
    init_msg = read_xml_response(s, expected_end_tag="</status>")
    if init_msg:
        print(f"[STS Empfangen] {init_msg.strip()}")

    print("[STS Senden] Registriere das RCS-Plugin...")
    register_xml = "<register name='RCS-Pistache-Bridge' autor='Dispo' version='1.7' protokoll='1' text='Live-Exporter mit Vorlauf' />\n"
    s.sendall(register_xml.encode('utf-8'))
    
    reg_status = read_xml_response(s, expected_end_tag="</status>")
    if reg_status:
        print(f"[STS Empfangen] {reg_status.strip()}")
    
    if not reg_status or "code='220'" not in reg_status:
        print("[Fehler] Registrierung wurde vom Simulator verweigert!")
        s.close()
        return

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

    print("\n[Bereit] Starte Überwachungsschleife...")
    print("[Info] Smart Diff aktiviert — Updates nur bei Änderungen der Verspätung")
    s.settimeout(None)

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
                            am_gleis_status = details_root.get('amgleis', 'false') == 'true'
                            von_einfahrt = details_root.get('von', '')
                            
                            delay_minutes = parse_delay(verspaetung_str)
                            
                            # ===== SICHTBARE ZÜGE (sichtbar='true') =====
                            if sichtbar == 'true' and aktuelles_gleis:
                                station_abbr = extract_station_abbr(aktuelles_gleis)
                                
                                if station_abbr:
                                    print(f"   [✓ SICHTBAR] {name} (ZID: {zid}) auf Gleis {aktuelles_gleis} (Am Gleis: {am_gleis_status})")
                                    send_to_rcs(zid, station_abbr, delay_minutes, am_gleis=am_gleis_status, sichtbar=True)
                                else:
                                    print(f"   [✗ WARNUNG] {name} (ZID: {zid}) auf Gleis {aktuelles_gleis}, aber konnte keine Abbr extrahieren")
                            
                            # ===== UNSICHTBARE ZÜGE (VORLAUF) - Mit "von" Einfahrt =====
                            elif sichtbar == 'false' and von_einfahrt and von_einfahrt.strip():
                                station_abbr = extract_station_abbr(von_einfahrt)
                                
                                if station_abbr:
                                    print(f"   [⏳ VORLAUF] {name} (ZID: {zid}) von {von_einfahrt} → Verspätung ab {station_abbr} gemeldet")
                                    send_to_rcs(zid, station_abbr, delay_minutes, am_gleis=False, sichtbar=False)
                                else:
                                    print(f"   [⏳ VORLAUF-SKIP] {name} (ZID: {zid}) von {von_einfahrt}, aber konnte keine Abbr extrahieren")
                            
                            # ===== UNSICHTBARE ZÜGE MIT LOKALEM START =====
                            elif sichtbar == 'false' and not von_einfahrt:
                                print(f"   [ℹ️ LOKAL] {name} (ZID: {zid}) startet lokal (Vorgängerzug) → PISTACHE propagiert bereits")
                                
            except Exception as e:
                print(f"❌ Fehler in Schleife: {e}")

            time.sleep(POLLING_INTERVAL)
    except KeyboardInterrupt:
        print("\nBeendet.")
    finally:
        s.close()

if __name__ == "__main__":
    main()