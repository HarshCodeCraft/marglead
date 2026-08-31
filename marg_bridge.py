import urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer
import requests
import os
import time
import json
import sys

# PyInstaller-safe Directory Setup (Yahan .exe aur config.json rahengi)
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

# Dynamic Config Loader (API Key aur Marg ka alag folder path read karega)
def get_config():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                data = json.load(f)
                return {
                    "api_key": data.get("api_key", "").strip(),
                    "pdf_path": data.get("pdf_path", "").strip()
                }
        except Exception as e:
            log_msg(f"Error reading config.json: {e}")
    return {"api_key": "", "pdf_path": ""}

LIVE_SAAS_API = "https://friendlyaisolution.com/api/marg_erp_gateway.php"

# Smart PDF Finder jo client ke Marg folder ko scan karega
def find_pdf(target_dir):
    if not target_dir or not os.path.exists(target_dir):
        log_msg(f"Error: Target Marg PDF directory not found: {target_dir}")
        return None
        
    latest_file = None
    latest_time = 0
    current_time = time.time()
    
    try:
        for root, dirs, files in os.walk(target_dir):
            for file in files:
                if file.lower().endswith('.pdf'):
                    pdf_full_path = os.path.join(root, file)
                    try:
                        mtime = os.path.getmtime(pdf_full_path)
                        # Agar file pichle 60 seconds mein bani hai
                        if current_time - mtime < 60:
                            if mtime > latest_time:
                                latest_time = mtime
                                latest_file = pdf_full_path
                    except Exception as e:
                        pass
    except Exception as e:
        log_msg(f"Error scanning directory: {e}")
        
    return latest_file

class MargLocalBridge(BaseHTTPRequestHandler):
    def do_GET(self):
        try:
            parsed = urllib.parse.urlparse(self.path)
            query = urllib.parse.parse_qs(parsed.query)

            mob = query.get('mob', [''])[0]
            msg = query.get('msg', [''])[0]

            # Har request par config.json se fresh key aur path padho
            config = get_config()
            current_api_key = config.get("api_key", "")
            pdf_target_dir = config.get("pdf_path", "")

            if not current_api_key or not pdf_target_dir:
                log_msg("FATAL ERROR: config.json mein api_key ya pdf_path missing hai!")
                self.send_response(400)
                self.end_headers()
                self.wfile.write(b"Config Error: API Key or pdf_path missing in config.json")
                return

            log_msg(f"Trigger Received! Mobile: {mob}")

            payload = {
                'api_key': current_api_key,
                'mob': mob,
                'msg': msg
            }

            time.sleep(3) # Marg ko PDF save karne ka waqt do
            files = {}
            pdf_path = find_pdf(pdf_target_dir)

            if pdf_path and os.path.exists(pdf_path):
                log_msg(f"PDF Found: {pdf_path}")
                files['pdf'] = open(pdf_path, 'rb')
            else:
                log_msg(f"Warning: PDF not found in Marg path: {pdf_target_dir}")

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
        log_msg("MULTI-TENANT DYNAMIC BRIDGE SERVER RUNNING ON PORT 8080")
        httpd.serve_forever()
    except Exception as e:
        log_msg(f"FATAL ERROR STARTING SERVER: {e}")

if __name__ == '__main__':
    run_server()    