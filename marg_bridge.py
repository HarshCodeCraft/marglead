import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer
import requests
import os
import time
import json
import sys

# PyInstaller-safe Directory Setup
if getattr(sys, 'frozen', False):
    BASE_DIR = os.path.dirname(os.path.abspath(sys.executable))
else:
    BASE_DIR = os.path.dirname(os.path.abspath(__file__))

CONFIG_FILE = os.path.join(BASE_DIR, "config.json")
LOG_FILE = os.path.join(BASE_DIR, "bridge.log")

def log_msg(text):
    try:
        with open(LOG_FILE, "a", encoding="utf-8") as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M:%S')} - {text}\n")
    except:
        pass

# Dynamic API Key Loader from local config.json file
def get_api_key():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                data = json.load(f)
                return data.get("api_key", "").strip()
        except Exception as e:
            log_msg(f"Error reading config.json: {e}")
    return ""

LIVE_SAAS_API = "https://friendlyaisolution.com/api/marg_erp_gateway.php"

def find_pdf():
    path1 = os.path.join(BASE_DIR, "Invoice.PDF")
    path2 = os.path.join(BASE_DIR, "Invoice.pdf")
    
    if os.path.exists(path1):
        return path1
    if os.path.exists(path2):
        return path2
        
    for root, dirs, files in os.walk(BASE_DIR):
        for file in files:
            if file.lower().endswith('.pdf'):
                pdf_full_path = os.path.join(root, file)
                if time.time() - os.path.getmtime(pdf_full_path) < 60:
                    return pdf_full_path
    return None

class MargLocalBridge(BaseHTTPRequestHandler):
    def do_GET(self):
        try:
            parsed = urllib.parse.urlparse(self.path)
            query = urllib.parse.parse_qs(parsed.query)

            mob = query.get('mob', [''])[0]
            msg = query.get('msg', [''])[0]

            # Har request ke waqt client ki unique API key read karo
            current_api_key = get_api_key()
            if not current_api_key:
                log_msg("FATAL ERROR: config.json mein API Key nahi mili!")
                self.send_response(400)
                self.end_headers()
                self.wfile.write(b"API Key Missing in config.json")
                return

            log_msg(f"Trigger Received! Mobile: {mob}")

            payload = {
                'api_key': current_api_key,
                'mob': mob,
                'msg': msg
            }

            time.sleep(3)
            files = {}
            pdf_path = find_pdf()

            if pdf_path and os.path.exists(pdf_path):
                log_msg(f"PDF Found: {pdf_path}")
                files['pdf'] = open(pdf_path, 'rb')
            else:
                log_msg("Warning: PDF not found locally.")

            response = requests.post(LIVE_SAAS_API, data=payload, files=files, timeout=10)
            log_msg(f"Server Response Code: {response.status_code}")

            if 'pdf' in files:
                files['pdf'].close()

            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"Success")
        except Exception as e:
            log_msg(f"Error in do_GET: {e}")
            self.send_response(500)
            self.end_headers()

    def log_message(self, format, *args):
        return

def run_server():
    port = 8080
    server_address = ('', port)
    try:
        httpd = HTTPServer(server_address, MargLocalBridge)
        log_msg("MULTI-TENANT BRIDGE SERVER RUNNING ON PORT 8080")
        httpd.serve_forever()
    except Exception as e:
        log_msg(f"FATAL ERROR STARTING SERVER: {e}")

if __name__ == '__main__':
    run_server()