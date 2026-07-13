#!/usr/bin/env python3
"""
STS API Demo Plugin - Exploriert die verfügbaren Daten aus der STS-API
Testet: Zugliste, Zugdetails, Zugfahrplan, Gleisinformationen, etc.
"""

import socket
import xml.etree.ElementTree as ET
import time
import sys

STS_HOST = "127.0.0.1"
STS_PORT = 3691

def read_xml_response(sock, expected_end_tag=None, timeout=5.0):
    """Liest XML-Response mit Timeout-Schutz"""
    buffer = ""
    sock.settimeout(timeout)
    try:
        while True:
            chunk = sock.recv(4096).decode('utf-8', errors='ignore')
            if not chunk:
                return None
            buffer += chunk
            
            if expected_end_tag and expected_end_tag in buffer:
                return buffer
                
            if not expected_end_tag:
                stripped = buffer.strip()
                if stripped.endswith('/>') or stripped.endswith('>'):
                    return buffer
    except socket.timeout:
        print("⚠️ [Timeout]")
        return buffer if buffer else None

def pretty_print_xml(xml_str):
    """Pretty-print XML für Lesbarkeit"""
    try:
        root = ET.fromstring(xml_str.strip())
        return ET.tostring(root, encoding='unicode')
    except:
        return xml_str

def send_request(sock, request_xml, expected_tag=None):
    """Sendet einen Request und liest die Response"""
    print(f"\n{'='*70}")
    print(f"📤 REQUEST: {request_xml.strip()}")
    print(f"{'='*70}")
    
    sock.sendall(request_xml.encode('utf-8'))
    response = read_xml_response(sock, expected_end_tag=expected_tag, timeout=8)
    
    if response:
        print(f"📥 RESPONSE:\n{response}\n")
        return response
    else:
        print("❌ Keine Response erhalten!\n")
        return None

def parse_and_display_xml(xml_str, title=""):
    """Parsed XML und zeigt Struktur an"""
    if not xml_str:
        return
    
    try:
        root = ET.fromstring(xml_str.strip())
        print(f"\n--- {title} ({root.tag}) ---")
        print(f"Attribute: {root.attrib}\n")
        
        for i, child in enumerate(root, 1):
            print(f"  [{i}] <{child.tag}> attribs={child.attrib}")
            if child.text and child.text.strip():
                print(f"      Text: {child.text.strip()}")
            for subchild in child:
                print(f"      └─ <{subchild.tag}> {subchild.attrib}")
                if subchild.text and subchild.text.strip():
                    print(f"         Text: {subchild.text.strip()}")
    except Exception as e:
        print(f"Fehler beim Parsen: {e}")

def main():
    print("\n" + "="*70)
    print("  STS API DEMO PLUGIN - Daten-Explorer")
    print("="*70)
    print(f"Verbinde zu STS ({STS_HOST}:{STS_PORT})...\n")
    
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect((STS_HOST, STS_PORT))
        print("✓ TCP-Verbindung hergestellt.\n")
    except Exception as e:
        print(f"❌ Fehler: {e}")
        sys.exit(1)
    
    # 1. Willkommensnachricht empfangen
    print("[Schritt 1] Warte auf Willkommensnachricht vom STS...")
    init_msg = read_xml_response(s, expected_end_tag="</status>")
    if init_msg:
        print(f"✓ Erhalten:\n{init_msg}\n")
        parse_and_display_xml(init_msg, "Willkommensnachricht")
    
    # 2. Plugin registrieren
    print("\n[Schritt 2] Registriere Demo-Plugin...")
    register_xml = "<register name='RCS-Demo-Explorer' autor='Demo' version='1.0' protokoll='1' text='API-Explorer' />\n"
    reg_response = send_request(s, register_xml, expected_tag="</status>")
    if reg_response:
        parse_and_display_xml(reg_response, "Register-Response")
    
    # 3. Anlageninfo abfragen
    print("\n[Schritt 3] Frage Anlageninfo ab...")
    send_request(s, "<anlageninfo />\n", expected_tag="</anlageninfo>")
    
    # 4. Zugliste abfragen
    print("\n[Schritt 4] Frage Zugliste ab...")
    zugliste_response = send_request(s, "<zugliste />\n", expected_tag="</zugliste>")
    
    if zugliste_response:
        parse_and_display_xml(zugliste_response, "Zugliste")
        
        # Extrahiere ZIDs für weitere Queries
        try:
            root = ET.fromstring(zugliste_response.strip())
            zuege = root.findall('zug')
            
            if zuege:
                print(f"\n✓ Gefunden: {len(zuege)} Züge\n")
                
                for idx, zug in enumerate(zuege[:3], 1):  # Nur erste 3 für Demo
                    zid = zug.get('zid')
                    name = zug.get('name')
                    
                    print(f"\n--- ZUG {idx}: {name} (ZID: {zid}) ---")
                    
                    # 5a. Zugdetails abfragen
                    print(f"  └─ Frage Zugdetails ab...")
                    details_response = send_request(s, f"<zugdetails zid='{zid}' />\n", expected_tag="</zugdetails>")
                    if details_response:
                        parse_and_display_xml(details_response, f"Zugdetails (ZID {zid})")
                    
                    time.sleep(0.5)
                    
                    # 5b. Zugfahrplan abfragen
                    print(f"  └─ Frage Zugfahrplan ab...")
                    fahrplan_response = send_request(s, f"<zugfahrplan zid='{zid}' />\n", expected_tag="</zugfahrplan>")
                    if fahrplan_response:
                        parse_and_display_xml(fahrplan_response, f"Zugfahrplan (ZID {zid})")
                    
                    time.sleep(0.5)
                    
                    # 5c. Zugposition abfragen (falls verfügbar)
                    print(f"  └─ Frage Zugposition ab...")
                    pos_response = send_request(s, f"<zugposition zid='{zid}' />\n", expected_tag="</zugposition>")
                    if pos_response:
                        parse_and_display_xml(pos_response, f"Zugposition (ZID {zid})")
                    
                    time.sleep(0.5)
            else:
                print("❌ Keine Züge in der Zugliste gefunden.")
        except Exception as e:
            print(f"❌ Fehler beim Parsen der Zugliste: {e}")
    
    # 6. Zusätzliche Queries testen
    print("\n[Schritt 6] Teste weitere API-Befehle...\n")
    
    test_queries = [
        ("<status />", "</status>", "Status"),
        ("<fahrplan />", "</fahrplan>", "Fahrplan (Global)"),
    ]
    
    for query, end_tag, desc in test_queries:
        print(f"\n[Test] {desc}...")
        response = send_request(s, query + "\n", expected_tag=end_tag)
        if response:
            parse_and_display_xml(response, desc)
        time.sleep(0.5)
    
    print("\n" + "="*70)
    print("  Demo beendet")
    print("="*70 + "\n")
    
    s.close()

if __name__ == "__main__":
    main()