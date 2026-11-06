# Phase 4: Web Application, API & File Security Audit Report

**Target Application:** Marg ERP CRM / Friendly AI Solution SaaS  
**Audit Scope:** Full Application Surface (Frontend, Backend Controllers, AJAX Handlers, REST API Gateways, File Uploads, Webhooks)  
**Audit Mode:** Strictly Read-Only (Non-Destructive Static Analysis & Staging Inspection)  
**Security Classification:** Highly Confidential / Enterprise Application Security Assessment  

---

## Executive Summary

A comprehensive application security assessment was executed across the Core PHP codebase covering SQL Injection (SQLi), Cross-Site Scripting (XSS), Cross-Site Request Forgery (CSRF), File Handling and Storage Security, REST/JSON API Security, and Web Infrastructure hygiene.

### Critical Assessment Verdict
The application exhibits several high-impact security vulnerabilities across web and API vectors:
1. **Unvalidated Open Redirects in Authentication Controllers:** Post-login redirection logic directly accepts arbitrary external URLs beginning with `http://` or `https://`, enabling targeted credential harvesting and phishing.
2. **State-Changing Sensitive Actions Executable via Unauthenticated/Unprotected GET Requests:** Quotation approvals, rejection, email dispatches, and role switches are triggered via raw GET requests with zero CSRF verification.
3. **Stored XSS Vector via Unrestricted SVG Uploads:** The central upload validator permits `image/svg+xml`. Uploaded SVGs can host JavaScript payloads executing in user browser contexts upon viewing.
4. **Hardcoded Master API Key Gatekeeper Bypass:** All API endpoints relying on `requireApiAuth()` can be bypassed with administrative rights using a static fallback key when environment variables are unset.
5. **Public Direct Object Access to Sensitive KYC & Invoice Files:** Flat document storage in `uploads/` without server-side permission gates allows unauthenticated public access to customer PAN cards, Aadhaar identity documents, and commercial invoices.
6. **Potential SSRF Vectors in Self-Hosted WhatsApp Web Engine:** Dynamic Node.js engine URLs stored in user settings are invoked by backend cURL handlers without internal network (RFC 1918) IP blocking.

---

## Check 1: SQL Injection (SQLi) Audit

### 1.1 Core Query Architecture Analysis
The application predominantly utilizes PHP Data Objects (`PDO`) with parameterized prepared statements (`$stmt = $pdo->prepare(...)` followed by `$stmt->execute([...])`). This effectively shields the majority of standard CRUD operations from basic SQL injection.

However, specific architectural weaknesses and concatenation patterns were identified:

---

### Finding 1.1: Complex String Concatenation in Dashboard Card Metrics
- **File Path:** `includes/config.php` (and replicated in `config.php`)
- **Endpoint / Context:** `getDashboardCardCounts()` (Lines 1005–1095)
- **Root Cause:**
  Dynamic SQL subqueries are assembled via variable concatenation:
  ```php
  $res = $pdo->query("SELECT COUNT(DISTINCT lead_id) FROM followups WHERE status IN ('pending', 'missed') AND DATE(scheduled_at) < '{$today_str}' AND {$no_future_fup} {$exec_where_fup}");
  ```
  While `$user_idents` are quoted via `$pdo->quote()`, building complex SQL logic through string assembly rather than structured query builders or strict parameterized bindings introduces fragility. Any developer modifying `$exec_where_fup` without explicit quoting creates direct SQL injection.
- **Severity:** Medium
- **Potential Impact:** Information disclosure, metric poisoning, or database exfiltration if variables bypass sanitization.
- **Recommended Fix:** Refactor dynamic metric calculations to use standard prepared statements with parameterized positional or named placeholders.

---

### Finding 1.2: Unescaped LIKE Wildcard Injection in Search Filters
- **File Path:** `modules/leads/list.php` (Lines 60–65) and `api/leads.php` (Lines 53–57)
- **Endpoint / Context:** Lead search filter (`search={keyword}`)
- **Root Cause:**
  User input is enclosed in `%` wildcards without escaping SQL LIKE metacharacters (`%` and `_`):
  ```php
  $st = '%' . $search . '%';
  ```
- **Severity:** Low
- **Potential Impact:** ReDoS / Denial of Service. An attacker supplying input like `%%%%%` can force expensive full-table regex scans, leading to high CPU load on shared MySQL instances.
- **Recommended Fix:** Escape LIKE wildcards using `addcslashes($search, '%_')` before binding parameters.

---

## Check 2: Cross-Site Scripting (XSS) Audit

### 2.1 Reflected & DOM-Based XSS Vectors

---

### Finding 2.1: Context-Sensitive XSS in Tab Navigation State
- **File Path:** `modules/leads/details.php`
- **Line Numbers:** 926, 928, 1741
- **Vulnerable Code:**
  ```php
  <button class="tab-link <?php echo (isset($_GET['active_tab']) && $_GET['active_tab'] === 'client-details') ? 'active' : ''; ?>" data-tab="tab-client-details" ...>
  ```
  While the equality comparison `$_GET['active_tab'] === 'client-details'` strictly matches a string, surrounding URL generation echoes unsanitized GET query parameters directly into HTML attributes in secondary templates.
- **Severity:** Low / Informational
- **Potential Impact:** Limited in current syntax, but represents a regression risk.
- **Recommended Fix:** Always pass query parameters through `htmlspecialchars($param, ENT_QUOTES, 'UTF-8')`.

---

### Finding 2.2: Telephone Link Context Injection
- **File Path:** `modules/leads/list.php`
- **Line Number:** 1616
- **Vulnerable Code:**
  ```php
  <a href="tel:<?php echo $lead['phone']; ?>" class="flex align-center gap-1 text-muted hover-primary">
  ```
- **Root Cause:** The database value `$lead['phone']` is echoed directly into the `href` attribute without sanitization. If an imported lead or webhook creates a record containing a pseudo-protocol (e.g., `javascript:alert(1)`), clicking the dial link triggers script execution.
- **Severity:** Medium
- **Potential Impact:** Stored Cross-Site Scripting executed when sales staff click to dial a lead.
- **Recommended Fix:** Sanitize phone numbers strictly to numeric characters and plus symbols:
  ```php
  <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $lead['phone'])); ?>">
  ```

---

### Finding 2.3: Stored XSS via SVG File Upload
- **File Path:** `includes/config.php`
- **Endpoint / Context:** `secureFileUpload()` (Lines 108–119)
- **Root Cause:**
  `image/svg+xml` and `.svg` extensions are permitted in the default allowed MIME list. SVG files are XML documents capable of executing embedded `<script>` blocks or event handlers (`<svg onload="alert(document.cookie)">`).
- **Severity:** High
- **Potential Impact:** Complete session hijacking and credential theft if an uploaded SVG document is opened in an administrator's browser.
- **Recommended Fix:** Disallow SVG file uploads, or sanitize incoming SVGs using an XML sanitizer and force download disposition (`Content-Disposition: attachment`).

---

## Check 3: Cross-Site Request Forgery (CSRF) Audit

### 3.1 Overview of Anti-CSRF Implementation
`includes/config.php` defines standard CSRF protection helpers:
- `getCsrfToken()`: Generates a 48-character cryptographic token (`bin2hex(random_bytes(24))`).
- `verifyCsrfToken()`: Compares session token using `hash_equals()`.

Despite these utilities, multiple high-risk endpoints fail to enforce CSRF checks:

---

### Finding 3.1: Sensitive State Changes via GET Requests (Zero CSRF)
- **File Path:** `modules/quotation/view.php` (Lines 60–76) and `modules/quotation/list.php` (Lines 14–25)
- **Endpoint:** `index.php?page=quotation_view&id={ID}&action={approve|reject|email_client}`
- **Root Cause:**
  Commercial actions (approving a formal quotation, marking it rejected, or triggering an official email proposal to a client) execute via raw HTTP GET parameters without CSRF validation:
  ```php
  if (isset($_GET['action'])) {
      $act = $_GET['action'];
      if ($act === 'approve') {
          $stmt = $pdo->prepare("UPDATE quotations SET status = 'approved' WHERE id = ?");
          $stmt->execute([$quoteId]);
      }
  }
  ```
- **Severity:** High
- **Potential Impact:** An attacker can embed an image tag on an external website (`<img src="https://friendlyaisolution.com/index.php?page=quotation_view&id=QT-9011&action=approve">`). When an authenticated administrator views the image, the quotation is approved without their consent.
- **Recommended Fix:** Refactor all state-altering operations to HTTP POST exclusively and mandate `verifyCsrfToken()`.

---

### Finding 3.2: Anti-CSRF Bypass on KYC Submissions
- **File Path:** `api/submit_customer_kyc.php`
- **Line Numbers:** 18–22
- **Vulnerable Code:**
  ```php
  if (isset($_POST['csrf_token']) && !verifyCsrfToken($_POST['csrf_token'])) {
      echo json_encode(['success' => false, 'error' => 'Security Error: CSRF token validation failed.']);
      exit;
  }
  ```
- **Root Cause:** The check only triggers if `csrf_token` is present in the POST body. If the field is omitted, validation passes silently.
- **Severity:** High
- **Potential Impact:** Forged customer KYC registrations and document injections.
- **Recommended Fix:** Make CSRF presence mandatory:
  ```php
  if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token']))
  ```

---

### Finding 3.3: Lead Batch Operations Missing CSRF Verification
- **File Path:** `index.php`
- **Line Numbers:** 332–380
- **Endpoint:** `index.php` (POST `action=batch_update`)
- **Root Cause:** AJAX batch operations for reassigning leads, altering statuses, and modifying priority classifications omit CSRF token checks.
- **Severity:** High
- **Potential Impact:** Forged bulk reassignment or bulk dropping of sales leads.
- **Recommended Fix:** Require `verifyCsrfToken($_POST['csrf_token'] ?? '')` before executing batch updates.

---

## Check 4: File Security & Storage Audit

---

### Finding 4.1: Public Direct Object Access to Sensitive KYC & Commercial Documents
- **File Path:** `uploads/kyc_docs/`, `uploads/invoices/`, `uploads/client_licenses/`
- **Web Server Configuration:** `uploads/.htaccess`
- **Root Cause:**
  `uploads/.htaccess` only disables directory indexing (`Options -Indexes`) and blocks script execution (`.php`, `.py`, `.sh`). Static files (`.pdf`, `.jpg`, `.png`, `.xlsx`) are served publicly without authentication.
- **Severity:** Critical
- **Potential Impact:** Complete exposure of government identity documents (Aadhaar cards, PAN cards, UDYAM certificates), tax invoices, and customer licenses to any unauthenticated internet user who knows or guesses the file path.
- **Recommended Fix:**
  1. Add `Require all denied` in `uploads/.htaccess`.
  2. Implement a protected streaming proxy (`download_document.php?id=...`) that validates user session, tenant ownership, and granular permissions before calling `readfile()`.

---

### Finding 4.2: Blacklist-Based Extension Validation in Upload Handler
- **File Path:** `includes/config.php`
- **Line Numbers:** 91–95
- **Vulnerable Code:**
  ```php
  $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
  $dangerous_extensions = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'exe', 'bat', 'sh', 'pl', 'py', 'cgi', 'asp', 'aspx', 'js', 'jsp'];
  if (in_array($ext, $dangerous_extensions)) {
      return ['success' => false, 'error' => 'Security Violation: Executable script extensions are strictly prohibited.'];
  }
  ```
- **Root Cause:** Extension filtering relies on a blacklist rather than an explicit whitelist. Notably omitted extensions include `.php8`, `.pht`, `.phar`, `.shtml`, and `.htaccess`.
- **Severity:** High
- **Potential Impact:** Potential Remote Code Execution (RCE) on web servers supporting unlisted executable handlers.
- **Recommended Fix:** Discontinue blacklist checking. Enforce strict extension whitelisting based on the upload context (e.g. only `['pdf', 'jpg', 'png', 'webp']`).

---

### Finding 4.3: Insecure Document Deletion without File Path Verification
- **File Path:** `modules/leads/details.php`
- **Line Numbers:** 498–511
- **Vulnerable Code:**
  ```php
  $docRec = $stmtF->fetch();
  if ($docRec) {
      $fullPath = __DIR__ . '/../../' . $docRec['file_path'];
      if (file_exists($fullPath)) {
          @unlink($fullPath);
      }
      $delStmt = $pdo->prepare("DELETE FROM lead_documents WHERE id = ?");
      $delStmt->execute([$docId]);
  }
  ```
- **Root Cause:** If an attacker manipulates `file_path` in the database or injects path traversal sequences (`../../`), `unlink()` deletes arbitrary files across the operating system.
- **Severity:** High
- **Potential Impact:** Arbitrary file deletion and system denial of service.
- **Recommended Fix:** Validate that `realpath($fullPath)` resides strictly within the designated uploads directory before executing `unlink()`.

---

## Check 5: API Security Audit

---

### Finding 5.1: Static Fallback Master API Key Backdoor
- **File Path:** `api/cors.php`
- **Line Numbers:** 219–230
- **Vulnerable Code:**
  ```php
  $apiKey = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';
  $definedKey = getenv('MARG_API_KEY') ?: 'MARG_CRM_SECURE_API_KEY_2026';

  if (!empty($apiKey) && hash_equals($definedKey, (string)$apiKey)) {
      return [
          'user_id'   => 0,
          'tenant_id' => 0,
          'name'      => 'API System',
          'role'      => 'Super Admin',
          'isAdmin'   => true,
          'auth_type' => 'api_key'
      ];
  }
  ```
- **Root Cause:** A fallback secret is hardcoded directly into source code. When `MARG_API_KEY` is not explicitly set in the server environment, supplying `?api_key=MARG_CRM_SECURE_API_KEY_2026` grants instant `Super Admin` privileges to any caller.
- **Severity:** Critical (CVSS 9.8)
- **Potential Impact:** Complete authentication bypass across all CRM API endpoints.
- **Recommended Fix:** Remove the static fallback string. If `getenv('MARG_API_KEY')` is false, reject API key requests immediately.

---

### Finding 5.2: Insecure Webhook Signature Bypass
- **File Path:** `api/webhook.php`
- **Line Numbers:** 64–69
- **Vulnerable Code:**
  ```php
  if (!empty(APP_SECRET) && APP_SECRET !== '1a2b3c4d5e6f7g8h9i0j' && !empty($sigHeader)) {
      if (!verify_meta_signature($rawPayload, APP_SECRET, $sigHeader)) {
          write_log('error', "Webhook HMAC Signature Verification Failed! Continuing processing...");
      }
  }
  ```
- **Root Cause:** When Meta HMAC SHA-256 signature verification fails, the endpoint logs an error but continues processing the incoming payload.
- **Severity:** High
- **Potential Impact:** Inbound message forgery, fake ticket creation, and false status manipulation.
- **Recommended Fix:** Terminate execution with `http_response_code(403)` and `exit('Invalid HMAC Signature');` upon signature mismatch.

---

### Finding 5.3: Permissive CORS Dynamic Host Reflection
- **File Path:** `api/cors.php`
- **Line Numbers:** 20–32
- **Vulnerable Code:**
  ```php
  if (!empty($httpOrigin)) {
      $parsedHost = parse_url($httpOrigin, PHP_URL_HOST) ?? '';
      $serverHost = $_SERVER['HTTP_HOST'] ?? '';
      if (in_array(rtrim($httpOrigin, '/'), $allowedOrigins, true) || 
          (!empty($serverHost) && strpos($serverHost, $parsedHost) !== false) ||
          (strpos($httpOrigin, 'capacitor://') === 0)) {
          header("Access-Control-Allow-Origin: {$httpOrigin}");
          header("Access-Control-Allow-Credentials: true");
      }
  }
  ```
- **Root Cause:** `strpos($serverHost, $parsedHost) !== false` will evaluate to true if `$parsedHost` is empty or if an attacker creates an origin whose hostname matches a substring of the server host. Reflecting `$httpOrigin` alongside `Access-Control-Allow-Credentials: true` allows unauthorized cross-origin requests from malicious origins.
- **Severity:** High
- **Potential Impact:** Cross-Origin Data Exfiltration of sensitive API responses.
- **Recommended Fix:** Validate `$httpOrigin` strictly against an explicit static whitelist. Never use loose substring matching.

---

### Finding 5.4: Unprotected Batch Package Downloader
- **File Path:** `api/download_bridge.php`
- **Line Numbers:** 10–22
- **Root Cause:**
  Endpoint generates Windows setup batch scripts and returns corporate configuration without authentication. Line 11 sets a static fallback key (`MARG-WABA-7DE9514EA1E4EF2C`) when no key is supplied, leaking API credentials in plain text.
- **Severity:** Medium
- **Potential Impact:** Credential leakage and unauthorized deployment script generation.
- **Recommended Fix:** Mandate session or bearer authentication before generating installer packages.

---

## Check 6: Other Security Risks

---

### Finding 6.1: Open Redirect in Login Handlers
- **File Path:** `auth/login.php`
- **Line Numbers:** 171–173, 289–291
- **Vulnerable Code:**
  ```php
  $redirect = !empty($_GET['redirect']) ? $_GET['redirect'] : '../index.php?page=dashboard';
  header("Location: " . (strpos($redirect, 'http') === 0 || strpos($redirect, '..') === 0 ? $redirect : '../' . ltrim($redirect, '/')));
  ```
- **Root Cause:**
  The condition explicitly permits destinations starting with `http`:
  `strpos($redirect, 'http') === 0`
  Supplying `auth/login.php?redirect=https://malicious-phishing.com` causes the browser to redirect immediately to the external attacker-controlled domain upon authentication.
- **Severity:** High
- **Potential Impact:** Credential phishing attacks leveraging trusted corporate domains.
- **Recommended Fix:** Disallow external URLs. Enforce local relative paths strictly:
  ```php
  $target = filter_var($redirect, FILTER_SANITIZE_URL);
  if (empty($target) || preg_match('/^[a-z]+:\/\//i', $target) || str_starts_with($target, '//')) {
      $target = '../index.php?page=dashboard';
  }
  ```

---

### Finding 6.2: Potential Server-Side Request Forgery (SSRF)
- **File Path:** `api/whatsapp_web_engine.php`
- **Line Numbers:** 26–42, 44–60
- **Vulnerable Code:**
  ```php
  $user_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? ...);
  $stmtUrl = $pdo->prepare("SELECT web_api_url ... FROM merchant_waba_settings WHERE user_id = ?");
  ...
  $localNodeEngineUrl = rtrim($currentMerchant['web_api_url'], '/');
  callLocalNodeEngine($endpoint, $postData);
  ```
- **Root Cause:**
  `callLocalNodeEngine()` executes cURL requests against `web_api_url` fetched from `merchant_waba_settings`. An attacker who modifies their WABA settings to point to internal services (`http://127.0.0.1:22`, `http://169.254.169.254/latest/meta-data/`) can probe internal cloud infrastructure.
- **Severity:** High
- **Potential Impact:** Internal network scanning and cloud metadata exfiltration.
- **Recommended Fix:** Validate that destination URLs do not resolve to loopback, private RFC 1918, or cloud metadata IP addresses before executing cURL requests.

---

### Finding 6.3: Sensitive Information Disclosure via Database Exception Halts
- **File Path:** `index.php`
- **Line Numbers:** 148, 251
- **Vulnerable Code:**
  ```php
  catch (PDOException $e) {
      die("Export error: " . $e->getMessage());
  }
  ```
- **Root Cause:** Uncaught database exceptions are output directly to the browser via `die()`. Error messages reveal internal database schema names, table structures, and SQL query syntax.
- **Severity:** Medium
- **Potential Impact:** Information disclosure aiding targeted SQL injection or schema enumeration.
- **Recommended Fix:** Log detailed exceptions to error log files; return generic friendly messages to users.

---

### Finding 6.4: Missing HTTP Security Headers
- **File Path:** `includes/config.php` and `.htaccess`
- **Missing Headers:**
  - `Content-Security-Policy` (CSP)
  - `Strict-Transport-Security` (HSTS)
- **Root Cause:** Neither the web server `.htaccess` nor PHP bootstrap sets CSP or HSTS headers.
- **Severity:** Medium
- **Potential Impact:** Susceptibility to clickjacking and inline script injection.
- **Recommended Fix:** Implement a strict Content-Security-Policy and HSTS header (`Strict-Transport-Security: max-age=31536000; includeSubDomains`).

---

## Vulnerability Remediation Priority Matrix

| Priority | Vulnerability Finding | File Location | Complexity | Recommended Remediation |
| :---: | :--- | :--- | :---: | :--- |
| **P0** | Hardcoded API Backdoor Key | `api/cors.php:219` | Low | Remove fallback static string; reject if env unset. |
| **P0** | Open Redirect on Authentication | `auth/login.php:172` | Low | Restrict redirects strictly to internal relative paths. |
| **P0** | Public Direct Access to Documents | `uploads/.htaccess` | Medium | Deny public web access; stream via authenticated PHP proxy. |
| **P1** | State-Altering GET Operations (CSRF) | `modules/quotation/view.php:66` | Medium | Convert to POST with mandatory `verifyCsrfToken()`. |
| **P1** | Insecure Webhook Signature Bypass | `api/webhook.php:67` | Low | Terminate with HTTP 403 on HMAC failure. |
| **P1** | KYC CSRF Token Bypass | `api/submit_customer_kyc.php:19` | Low | Mandate token presence: `!isset() \|\| !verify()`. |
| **P1** | Stored XSS via SVG Uploads | `includes/config.php:108` | Low | Remove `image/svg+xml` from permitted MIME types. |
| **P2** | Potential SSRF in WhatsApp Engine | `api/whatsapp_web_engine.php:46`| Medium | Block internal RFC 1918 / metadata IP targets. |
| **P2** | Dynamic CORS Reflection | `api/cors.php:23` | Low | Replace substring search with strict array whitelist. |
| **P2** | Dial-Link Context XSS | `modules/leads/list.php:1616` | Low | Sanitize phone variables before echoing in `tel:` tags. |
| **P3** | Raw Exception Output Disclosure | `index.php:148` | Low | Replace `die($e->getMessage())` with generic error. |
| **P3** | Missing CSP & HSTS Headers | `includes/config.php:14` | Low | Add CSP and HSTS headers in PHP bootstrap. |

---

## Conclusion

The web application, API, and file security audit identified multiple critical vulnerabilities across open redirects, API authentication, document storage, and CSRF protection.

**This audit was performed strictly in READ-ONLY mode. Zero code or database modifications have been made.**

Awaiting your authorization before proceeding to the remediation phase.

