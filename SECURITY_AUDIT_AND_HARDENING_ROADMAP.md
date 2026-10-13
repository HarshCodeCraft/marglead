# Marg ERP CRM - Complete Security Audit & Hardening Roadmap
**Document Created:** October 8, 2026  
**Status:** Audit Completed & Ready for Execution on User Instruction  
**Repository:** `HarshCodeCraft/marglead` (Branch: `feature/marg-waba-pdf-gateway`)

---

## 📌 Executive Summary
This document provides an exhaustive security audit covering **20 requested security checkpoints** plus **additional mission-critical security layers** identified in the codebase. 

No code changes will be deployed until explicit instruction is given by the user. Each point contains:
1. **Current Status & Codebase Reality** (exact file locations and findings).
2. **Defensive Remediation Plan** (how to improve and secure it).
3. **Drawbacks / Operational Precautions** (potential impact on functionality or UX).

---

## 🛡️ Comprehensive 20-Point Audit Matrix

### 1. Check for SQL Injection
* **Current Status:** 🟡 Partial / Mostly Safe
* **Codebase Reality:** 
  * Core CRUD logic (`modules/leads/`, `modules/clients.php`, `auth/login.php`) uses PDO prepared statements with `?` or named parameters.
  * `TenantAwarePDO` in `includes/db.php` performs regex-based table rewriting (`t_{tenant}_leads`). If an untrusted tenant prefix is loaded into session, it could cause SQL syntax errors or query injection.
  * In `index.php` (`export_csv_advanced`), the column selection list is parsed from `explode(',', $_GET['exp_cols'])` without strict whitelist validation before building query projection.
* **How to Improve:**
  * Validate all tenant prefixes with strict regex `^[a-zA-Z0-9_]+$`.
  * Implement a strict column whitelist for dynamic exports (`['id', 'name', 'phone', 'company', 'status', 'created_at']`).
* **Drawbacks & Precautions:** Adding a strict whitelist may omit custom fields if not kept synchronized with custom CRM form fields.

---

### 2. Block Cross-Site Scripting (XSS)
* **Current Status:** 🟡 Partial
* **Codebase Reality:**
  * Most rendered output uses `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.
  * Dynamic template preview modals (e.g. WhatsApp template builders, bot flow screens) and flash messages occasionally print unescaped content.
  * Modern HTTP security headers (specifically `Content-Security-Policy`) are currently missing.
* **How to Improve:**
  * Define a global HTML escaping helper `e($str)` used consistently across all template files.
  * Add a robust `Content-Security-Policy` header in `config/config.php` allowing only trusted script and font sources (Google Fonts, FontAwesome, CDNs).
  * Use client-side DOMPurify when previewing rich message variables (`{{1}}`, `{{2}}`).
* **Drawbacks & Precautions:** Overly strict CSP headers can break external CDNs, inline scripts, or embedded Google maps/fonts if not whitelisted accurately.

---

### 3. Add CSRF Protection
* **Current Status:** 🔴 High Risk
* **Codebase Reality:**
  * Anti-CSRF utilities (`getCsrfToken()`, `renderCsrfInput()`, `verifyCsrfToken()`) exist in `includes/config.php` and `$_SESSION['csrf_token']` is initialized.
  * **Critical Gap:** CSRF verification is only enforced on ~5 admin forms. Sensitive state-changing endpoints (e.g. `api/campaign-api.php`, `api/save_merchant_settings.php`, and `index.php?action=impersonate_client`) do NOT verify CSRF tokens.
* **How to Improve:**
  * Add automatic CSRF validation for all internal `POST`, `PUT`, and `DELETE` requests in entry points.
  * Convert all state-changing `GET` requests (such as client impersonation, lead deletions) to `POST` with CSRF tokens.
  * Whitelist external Webhooks (Meta Cloud API, Marg ERP Desktop Bridge) from CSRF checks.
* **Drawbacks & Precautions:** External webhooks must remain exempt; otherwise, incoming WhatsApp webhook notifications will fail with 403 Forbidden.

---

### 4. Validate File Uploads
* **Current Status:** 🟡 Partial / Vulnerability Present
* **Codebase Reality:**
  * `includes/config.php` contains a well-designed `secureFileUpload()` function checking max size (10MB), extension blacklist (`.php`, `.exe`, `.sh`), and MIME type validation via `finfo`.
  * **Critical Gap:** In `modules/leads/details.php` (line 123) and `modules/bank_accounts.php` (line 39), `secureFileUpload()` is bypassed and `move_uploaded_file()` is called directly without MIME checks or extension validation.
* **How to Improve:**
  * Enforce `secureFileUpload()` uniformly across all upload handlers.
  * Block SVG uploads or serve them with `Content-Disposition: attachment` to eliminate SVG-embedded script execution.
  * Add `.htaccess` in `uploads/` with `php_flag engine off` and `Options -Indexes`.
* **Drawbacks & Precautions:** Legitimate users uploading uncommon image formats or non-standard PDFs may get rejected if MIME whitelists are too narrow.

---

### 5. Fix Broken Object-Level Authorization (BOLA / IDOR)
* **Current Status:** 🟡 Needs Hardening
* **Codebase Reality:**
  * While tenant tables provide schema isolation, several API endpoints fetch or modify records (`lead_id`, `ticket_id`, `invoice_id`) using `WHERE id = ?` without verifying whether the record belongs to the active tenant or assigned agent.
  * In `index.php?action=export_csv`, any authenticated user (regardless of role) can dump all leads.
* **How to Improve:**
  * Always bind the current user/tenant context: `WHERE id = :id AND (tenant_id = :tenant_id OR user_id = :user_id)`.
  * Add permission authorization guards before handling export, deletion, or viewing commands.
* **Drawbacks & Precautions:** Shared access between team managers and subordinates needs explicit role-aware query scoping.

---

### 6. Add Rate Limiting
* **Current Status:** 🔴 High Risk
* **Codebase Reality:**
  * Anti-brute force lockout exists only in `auth/login.php` (5 failed attempts per 15 minutes).
  * **Critical Gap:** No rate limiting on `auth/otp-reset.php`, `auth/verify-otp.php`, `api/webhook.php`, or `api/marg_erp_gateway.php`. An attacker could brute-force 6-digit OTP codes or flood the Marg ERP gateway.
* **How to Improve:**
  * Implement a lightweight MySQL or session-based rate limiter table (`rate_limits`: ip, endpoint, hits, window_expiry).
  * Enforce 3 OTP requests per minute, 5 verification attempts per 10 minutes, and 60 Marg ERP gateway calls per minute per API key.
* **Drawbacks & Precautions:** If multiple office users access the CRM behind a single corporate NAT/IP, aggregate rate limits could temporarily block legitimate staff unless keyed by account/token.

---

### 7. Secure JWT Secrets
* **Current Status:** 🟢 Safe (Session-Based Architecture)
* **Codebase Reality:**
  * The web application uses PHP native sessions (`PHPSESSID`) configured with `HttpOnly`, `SameSite: Lax`, and a 5-hour lifetime.
  * Native JWT is not currently deployed (only referenced in mobile app technical specifications).
* **How to Improve:**
  * When developing the mobile app API, generate a cryptographically strong 256-bit random key stored strictly in `.env` (`JWT_SECRET`).
  * Enforce standard token expiration (`exp` = 1 hour) with refresh tokens.
* **Drawbacks & Precautions:** Expired JWTs require robust mobile app token refresh logic to avoid unexpected user logouts.

---

### 8. Keep API Secrets Server-Side
* **Current Status:** 🔴 Critical Risk
* **Codebase Reality:**
  * In `config/config.php` (lines 48–105), the **Hostinger database password (`Liahshsrahinahs%$#@12345`), Hostinger IP (`31.97.2.36`), and Meta WhatsApp permanent token** are hardcoded.
  * In `api/webhook.php` (line 36), direct browser GET requests output `'verify_token' => VERIFY_TOKEN` in plain JSON.
* **How to Improve:**
  * Migrate all database credentials and API secrets into a `.env` file located outside the web root.
  * Add `.env` to `.gitignore` so secrets are never pushed to GitHub.
  * Remove `verify_token` reflection from `api/webhook.php`.
  * Rotate the live database password and Meta Access Token.
* **Drawbacks & Precautions:** When deploying to production servers (Hostinger), environment variables or `.env` must be maintained manually.

---

### 9. Hash Passwords Securely
* **Current Status:** 🟡 Good / Minor Cleanup Needed
* **Codebase Reality:**
  * Passwords use `password_hash($pwd, PASSWORD_DEFAULT)` (Bcrypt `$2y$`) with auto-rehash upgrade on login.
  * **Vulnerability:** `setup_db.php` (line 146) contains hardcoded logic: `$hash = password_hash('password123', PASSWORD_DEFAULT);` which overwrites user passwords.
* **How to Improve:**
  * Remove or password-protect `setup_db.php`.
  * Enforce minimum password strength (at least 8 characters, mixed case, number, symbol).
* **Drawbacks & Precautions:** Strict password complexity validation may require existing users with weak passwords to reset their credentials.

---

### 10. Add Multi-Factor Authentication (MFA)
* **Current Status:** 🟡 Partial
* **Codebase Reality:**
  * Email OTP verification is used during user signup (`verify-otp.php`) and password resets (`otp-reset.php`).
  * Admin / Super Admin logins do not have mandatory two-factor authentication.
* **How to Improve:**
  * Add an optional or role-enforced TOTP (Google Authenticator / Microsoft Authenticator) 2FA using standard RFC 6238 time-based tokens.
  * Alternatively, offer Email OTP verification upon login for Admin roles.
* **Drawbacks & Precautions:** Extra authentication step required during login; requires account recovery flow if a user loses their authenticator app.

---

### 11. Tighten CORS Settings
* **Current Status:** 🔴 High Risk
* **Codebase Reality:**
  * Wildcard `Access-Control-Allow-Origin: *` is set in:
    - `api/cors.php`
    - `api/marg_erp_gateway.php`
    - `api/marg_local_bridge.php`
    - `api/download_bridge.php`
    - `api/whatsapp_web_engine.php`
* **How to Improve:**
  * Replace wildcard `*` with an explicit origin whitelist:
    `$allowed_origins = ['https://friendlyaisolution.com', 'https://ladder-giver-splendid.ngrok-free.dev', 'http://localhost'];`
  * Omit CORS headers entirely for backend-to-backend endpoints (`marg_erp_gateway.php`), as desktop Marg ERP (WinHttp) does not use browser CORS.
* **Drawbacks & Precautions:** If new client frontends or subdomains are added, they must be registered in the allowed origins array.

---

### 12. Remove Auth Tokens from Local Storage
* **Current Status:** 🟢 Fully Safe & Compliant
* **Codebase Reality:**
  * Comprehensive scan confirms **NO** auth tokens, JWTs, or passwords are saved in `localStorage`.
  * `localStorage` stores only non-sensitive UI preferences: `theme`, `sidebar_autohide_enabled`, table column preferences, and filter presets.
  * Session credentials live strictly inside `HttpOnly` cookies.
* **How to Improve:** Maintain this architectural standard; ensure future mobile/SPA frontends use `HttpOnly` cookies or secure platform storage.
* **Drawbacks & Precautions:** None.

---

### 13. Enforce Permissions Server-Side (RBAC)
* **Current Status:** 🟡 Partial
* **Codebase Reality:**
  * Roles (`Super Admin`, `Admin`, `Staff`, `Client`) exist in session.
  * Some operations (e.g. `action=export_csv` in `index.php`, direct module loads) do not check role capabilities before execution.
  * `setup_db.php` has zero authorization checks.
* **How to Improve:**
  * Build a central permission checker: `checkPermission('export_leads')` and `checkRole(['Super Admin', 'Admin'])`.
  * Protect all module entry points by verifying authentication and role authorization.
* **Drawbacks & Precautions:** If staff members need access to specific reports, fine-grained permission assignment must be configured in Admin settings.

---

### 14. Enable Row-Level Security / Tenant Isolation
* **Current Status:** 🟡 Good Architectural Foundation
* **Codebase Reality:**
  * `TenantAwarePDO` provides multi-tenant table segregation (`t_company_leads`, etc.).
  * Shared tables (`merchant_waba_settings`, `users`, `activity_logs`) contain records from multiple tenants and rely on manual `WHERE user_id = ?` clauses.
* **How to Improve:**
  * Audit all SQL queries against shared tables to verify that `user_id` or `tenant_id` filters are never omitted.
  * Add automated tests to verify cross-tenant data isolation.
* **Drawbacks & Precautions:** Requires strict discipline during query authoring to prevent accidental data leaks.

---

### 15. Verify Webhook Signatures
* **Current Status:** 🔴 Critical Risk
* **Codebase Reality:**
  * In `api/webhook.php`, GET requests verify `hub_verify_token`.
  * **Critical Gap:** Incoming POST requests (Meta WhatsApp messages, status updates) **do NOT verify `X-Hub-Signature-256`**. Anyone on the internet can spoof a POST request to inject fake messages or trigger bot workflows.
* **How to Improve:**
  * Verify Meta HMAC-SHA256 signature on every incoming POST:
    ```php
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, APP_SECRET);
    if (!hash_equals($expected, $signature)) {
        http_response_code(403);
        exit('Invalid signature');
    }
    ```
* **Drawbacks & Precautions:** `APP_SECRET` must be correctly configured in Meta App Dashboard; an incorrect key will reject legitimate Meta webhooks.

---

### 16. Check for SSRF (Server-Side Request Forgery)
* **Current Status:** 🟡 Medium Risk
* **Codebase Reality:**
  * `curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false)` is used across cURL requests (Meta API, AI Service, LanguageTool, Gateway), disabling certificate verification.
  * Custom webhook URLs configured by merchants are pinged without validating against internal private IP addresses (e.g. `127.0.0.1`, `192.168.x.x`, `169.254.169.254`).
* **How to Improve:**
  * Enable SSL verification in production: `CURLOPT_SSL_VERIFYPEER => true`.
  * Filter outgoing webhook destination URLs to prevent connections to loopback/private IP ranges.
* **Drawbacks & Precautions:** Localhost development without valid SSL certificates may require an explicit local development check.

---

### 17. Remove Exposed Source Maps & Development Artifacts
* **Current Status:** 🟡 Low-Medium
* **Codebase Reality:**
  * No `.map` files exist in public `assets/`.
  * `whatsapp_engine/node_modules/` contains ~250 `.map` and source files and resides inside the public web root (`htdocs/marglead/whatsapp_engine`).
* **How to Improve:**
  * Add `.htaccess` inside `whatsapp_engine/` denying all web access (`Deny from all`).
  * Add `*.map` to `.gitignore`.
* **Drawbacks & Precautions:** None. The WhatsApp engine runs on Node.js port 3000 and should never be served via Apache.

---

### 18. Change Default Credentials
* **Current Status:** 🔴 High Risk
* **Codebase Reality:**
  * Default seeds exist: `'password123'` in `setup_db.php`.
  * Live database credentials exist in source code.
* **How to Improve:**
  * Delete or lock down `setup_db.php` in production environments.
  * Rotate Hostinger database credentials once the repository is private.
* **Drawbacks & Precautions:** New database deployments should be managed via versioned migration scripts rather than an open web setup page.

---

### 19. Keep Sensitive Data Out of Logs
* **Current Status:** 🔴 High Risk
* **Codebase Reality:**
  * The `logs/` directory contains `api.log`, `webhook.log`, and `bridge.log`.
  * **Critical Gap:** `logs/` does not have an `.htaccess` file. Anyone navigating to `https://friendlyaisolution.com/logs/api.log` can download full logs containing phone numbers, invoice details, and auth headers.
* **How to Improve:**
  * Immediately place an `.htaccess` inside `logs/` with `Deny from all`.
  * Mask phone numbers (`98765*****`) and tokens before writing to log files.
* **Drawbacks & Precautions:** Fully masked tokens in logs make it slightly harder to copy-paste exact payloads during local debugging.

---

### 20. Update Vulnerable Dependencies
* **Current Status:** 🟢 Mostly Up-to-Date
* **Codebase Reality:**
  * Composer: `phpmailer/phpmailer: ^7.1` (latest stable).
  * Node.js: `express: ^4.19.2`, `@whiskeysockets/baileys: ^6.6.0`, `qrcode: ^1.5.3`.
* **How to Improve:**
  * Upgrade `express` to `^4.21.2` or latest LTS to resolve minor known CVEs.
  * Keep Baileys updated to ensure compatibility with WhatsApp protocol updates.
* **Drawbacks & Precautions:** Baileys updates must be tested against current WhatsApp Web pairing workflows.

---

## ⚡ Additional Recommended Security Protections

1. **HTTP Security Headers:**
   Add clickjacking and MIME sniffing protections to all HTTP responses:
   - `X-Frame-Options: SAMEORIGIN`
   - `X-Content-Type-Options: nosniff`
   - `Referrer-Policy: strict-origin-when-cross-origin`
   - `Strict-Transport-Security: max-age=31536000; includeSubDomains` (for HTTPS)

2. **Session Hijacking Mitigation:**
   - Execute `session_regenerate_id(true)` upon successful user login.
   - Bind sessions to client user-agent hash to prevent session cookie theft across different browsers.

3. **Database Port Lockdown on Hostinger:**
   - Restrict Hostinger MySQL Remote Database (Port 3306) to only the specific live web server IP and trusted developer static IPs rather than open `%` (any IP).

---

## 🚀 Recommended Implementation Phases

When you give the instruction to implement, the work will proceed in these 3 structured phases:

* **Phase 1: Immediate Lockdown (Quick Wins - 0% Risk to Existing Features):**
  1. Add `logs/.htaccess` (`Deny from all`).
  2. Add `whatsapp_engine/.htaccess` (`Deny from all`).
  3. Restrict or delete `setup_db.php`.
  4. Fix file upload vulnerabilities in `modules/leads/details.php` and `modules/bank_accounts.php` using `secureFileUpload()`.
  5. Remove public token disclosure from `api/webhook.php`.

* **Phase 2: Secrets & External Webhooks Hardening:**
  1. Move database passwords and Meta keys to `.env` (add to `.gitignore`).
  2. Implement Meta Webhook HMAC-SHA256 signature verification in `api/webhook.php`.
  3. Replace wildcard CORS `*` with an explicit origin whitelist.

* **Phase 3: Deep Application Security & RBAC:**
  1. Enforce universal CSRF checking on internal POST endpoints.
  2. Add rate limiting on OTP and public endpoints.
  3. Enforce server-side role checks on exports and administrative actions.
  4. Enable SSL peer verification on production cURL requests.

---
*End of Document. Awaiting user instruction before applying code modifications.*
