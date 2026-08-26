import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer
import requests
import os
import time

# 1. Aapka Live SaaS API URL
LIVE_SAAS_API = "https://friendlyaisolution.com/api/marg_erp_gateway.php"

# 2. Aapki API Key
API_KEY = "MARG-WABA-7DE9514EA1E4EF2C"

# Jis folder me yeh script hai (C:\Users\Public\MARG\31041\)
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

def find_pdf():
    # Marg jo bhi file export karta hai ya Invoice.PDF banata hai, use dhoondho
    for root, dirs, files in os.walk(BASE_DIR):
        for file in files:
            if file.lower().endswith('.pdf'):
                pdf_full_path = os.path.join(root, file)
                # Check karo ki file pichle 1 minute ke andar hi bani ho
                if time.time() - os.path.getmtime(pdf_full_path) < 60:
                    return pdf_full_path
    return None

class MargLocalBridge(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        query = urllib.parse.parse_qs(parsed.query)

        mob = query.get('mob', [''])[0]
        msg = query.get('msg', [''])[0]

        print(f"\n[+] Marg Trigger Received! Mobile: {mob}")

        payload = {
            'api_key': API_KEY,
            'mob': mob,
            'msg': msg
        }

        # PDF Hard Drive me save hone ke liye 3 second wait karo
        print("[*] Waiting 3 seconds for Marg to save PDF...")
        time.sleep(3)

        files = {}
        pdf_path = find_pdf()

        if pdf_path and os.path.exists(pdf_path):
            print(f"[*] BINGO! Asli PDF Mil Gayi: {pdf_path}")
            files['pdf'] = open(pdf_path, 'rb')
        else:
            print("[-] WARNING: Koi nayi PDF nahi mili!")

        # Cloud Par Bhejo
        try:
            print("[*] Uploading to friendlyaisolution.com...")
            response = requests.post(LIVE_SAAS_API, data=payload, files=files)
            print(f"[+] Live Server Response Code: {response.status_code}")
            print(f"[+] Server Response: {response.text}")
        except Exception as e:
            print(f"[-] Cloud connection failed: {e}")

        if 'pdf' in files:
            files['pdf'].close()

        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"Success")

def run_server():
    port = 8080
    server_address = ('', port)
    httpd = HTTPServer(server_address, MargLocalBridge)
    print("==================================================")
    print(" Marg SaaS Bridge SERVER IS RUNNING (Auto-Scan V4)")
    print(f" Listening on: http://localhost:{port}")
    print(" (Keep this window open while saving bill in Marg)")
    print("==================================================\n")
    httpd.serve_forever()

if __name__ == '__main__':
    run_server()