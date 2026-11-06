# Phase 5: Database, Hardcoded Values & Configuration Security Audit Report

**Target Application:** Marg ERP CRM / Friendly AI Solution SaaS  
**Audit Scope:** Sensitive Secrets, Configuration Hygiene, MySQL Schema & Performance, Data Retention, and SaaS Configuration Isolation  
**Audit Mode:** Strictly Read-Only (Non-Destructive Static & Architectural Analysis)  
**Security Classification:** Highly Confidential / Enterprise Infrastructure Assessment  

---

## Executive Summary

A comprehensive assessment was performed on the configuration management, database security, credential storage, and multi-tenant data lifecycle of the CRM application.

### Critical Verdict: HIGH RISK IN CREDENTIAL MANAGEMENT & SCHEMA HYGIENE
1. **Critical Secret Sprawl Across Version-Controlled Code:** Production database credentials, Hostinger business SMTP email credentials, Meta Graph API System Access Tokens, and RSA private keys are hardcoded in plaintext inside PHP scripts without `.env` separation.
2. **Plaintext Aadhaar & PAN Storage (Regulatory Non-Compliance):** Full 12-digit Indian Aadhaar numbers and 10-character PAN cards are stored in raw unencrypted database columns in `customer_kyc_details`, violating statutory UIDAI and data privacy standards.
3. **Severe Performance Bottleneck (Missing Core Indexes):** Primary transactional tables (`leads`, `followups`, `support_tickets`) lack indexes on heavily filtered columns (`status`, `assigned_to`, `group_stage`, `scheduled_at`, `phone`), forcing MySQL to execute full table scans on every page load and search.
4. **Permanent Data Loss without Pre-Drop Snapshots:** Tenant deletion in `modules/admin/crm_clients.php` executes raw `DROP TABLE` / `DROP DATABASE` queries with zero automatic backup snapshots, while leaving orphaned administrator accounts in the master `users` table.

---

## Check 1: Hardcoded Values & Credential Sprawl Audit

*All secrets, credentials, and tokens are partially masked and redacted in accordance with enterprise disclosure policies.*

| File Path | Line(s) | Secret Type | Masked / Redacted Sample | Potential Risk |
| :--- | :--- | :--- | :--- | :--- |
| `config/config.php` | 51–53 | Production MySQL Credentials | `u978...` / `Liah...12345` | Direct unauthorized database connection if MySQL port is reachable. |
| `config/config.php` | 64–70 | Hostinger Remote DB Fallback | `u978...` / `Liah...12345` | Exposure of production database credentials in source code. |
| `includes/mailer.php` | 106–107 | Hostinger SMTP Credentials | `support@...` / `Liah...12345` | **Credential Reuse:** SMTP and Database share identical credentials. High-volume spam relay risk. |
| `config/config.php` | 91–93 | Meta Graph Cloud API Token | `EAAj...ZC1AZD` (Permanent System Token) | Full access to Meta WhatsApp Business Account (WABA), messages, and billing. |
| `config/config.php` | 95 | Meta WhatsApp App Secret | `fddf...c5fa` | Capability to forge signed webhook payloads and decrypt flow data. |
| `config/config.php` | 99 | Webhook Verify Token | `marg_..._secret` | Webhook verification spoofing on Meta App Dashboard. |
| `config/config.php` | 77 | Oracle Cloud VPS Web Engine IP | `http://140.238.167.58:3000` | Network reconnaissance and targeted port attacks against node service. |
| `config/private_key.pem` | 1–30 | 2048-Bit RSA Private Key | `-----BEGIN RSA PRIVATE KEY-----` | Compromise allows offline decryption of customer WhatsApp Flow data. |
| `api/cors.php` | 219 | Static Master API Key Fallback | `MARG_CRM_SECURE_..._2026` | Instant Super Admin authentication bypass when environment variable is omitted. |
| `api/download_bridge.php`| 11 | Fallback Client API Key | `MARG-WABA-7DE...EF2C` | Leaked in downloadable batch setup scripts. |
| `schema.sql` | 24, 269–272| Default Seed Account Passwords | Plaintext `'123456'` | Instant account compromise via dictionary matching on newly seeded installations. |
| `api/campaign-api.php` | 477–484 | Master Banking Fallback Data | Deepak Awasthi UPI & HDFC Account | Cross-tenant data bleed in automated customer broadcasts. |

---

## Check 2: Configuration & Environment Security Audit

### 2.1 Absence of `.env` Architecture
- **Inspection:** No `.env` or `.env.local` files exist in the project root.
- **Flaw:** All configuration parameters are directly assigned to PHP global constants in `config/config.php`.
- **Consequence:** Pushing code to GitHub, Bitbucket, or public git repos exposes all production credentials, Meta API keys, and database passwords.

### 2.2 Git Repository Directory Exposure (`.git/`)
- **Inspection:** A live `.git` repository folder exists inside the document root: `c:\xampp\htdocs\marglead\.git`.
- **Root `.htaccess` Rule Evaluation:**
  Lines 55–63 in `.htaccess`:
  ```apache
  <FilesMatch "^\.(?!htaccess)">
      Require all denied
  </FilesMatch>
  ```
  `FilesMatch` matches only file basenames. Directory requests such as `/.git/HEAD`, `/.git/config`, or `/.git/index` have non-dot basenames (`HEAD`, `config`, `index`).
- **Risk:** In environments where directory matching is not strictly enforced, tools like `git-dumper` can reconstruct the entire application source code and revision history.
- **Recommended Fix:** Add explicit directory rejection in `.htaccess`:
  ```apache
  RedirectMatch 404 /\.git
  ```

### 2.3 Error Reporting & Debug Output
- **Inspection:** `includes/config.php` lines 1–25 omit `ini_set('display_errors', '0')`.
- **Flaw:** Multiple controllers use raw `die("Error: " . $e->getMessage())` (e.g. `index.php` lines 148 and 251).
- **Risk:** Database connection errors, SQL syntax exceptions, and file system paths are output directly to the end-user browser, aiding targeted exploitation.

### 2.4 Cookie & Session Security Flags
- **Inspection (`auth/login.php` lines 38–41):**
  - `httponly`: Set to `true` (Protects against JavaScript access).
  - `samesite`: Set to `'Lax'` (Mitigates cross-site GET requests).
  - `secure`: **Omitted / False** (Cookie can be sent over unencrypted HTTP connections).
- **Risk:** Session cookie sniffing on public Wi-Fi networks if HTTPS is not strictly enforced by HSTS.

---

## Check 3: Database Security & Schema Hygiene Audit

### 3.1 Database User Privileges
- The configured database user (`u978772385_friendlyaidata`) possesses administrative Data Definition Language (`DDL`) privileges: `CREATE`, `DROP`, `ALTER`, `TRUNCATE`.
- **Architectural Risk:** In the event of SQL injection, an attacker can execute destructive schema alterations (`DROP TABLE`, `TRUNCATE TABLE users`) rather than being restricted strictly to DML (`SELECT`, `INSERT`, `UPDATE`).

### 3.2 Sensitive Data Plaintext Storage
- **Regulatory Non-Compliance (UIDAI Aadhaar Regulations):**
  In table `customer_kyc_details` (`schema.sql` lines 437–438):
  - `aadhaar_number VARCHAR(12) NOT NULL`
  - `pan_number VARCHAR(10) NOT NULL`
  Indian regulations mandate that raw 12-digit Aadhaar numbers must NEVER be stored in plaintext. They must either be masked (`XXXXXXXX1234`) or encrypted using AES-256 with keys managed in a Hardware Security Module (HSM) or secure key vault.
- **API Token Storage:**
  In `merchant_waba_settings`:
  - `access_token TEXT`: Permanent Meta System Access Tokens are stored in plaintext.
  - `web_api_token VARCHAR(255)`: Stored unhashed in plaintext.

### 3.3 Missing Database Indexes & Performance Audit
Analysis of query access patterns across `modules/leads/list.php`, `index.php`, and `modules/support.php` reveals missing composite and single-column indexes:

| Table Name | Heavily Filtered Columns Missing Indexes | Access Impact |
| :--- | :--- | :--- |
| `leads` | `status`, `assigned_to`, `group_stage`, `created_at`, `phone`, `city` | Full Table Scan on every search, pipeline filter, and pagination request. |
| `followups` | `status`, `scheduled_at`, `assigned_to` | Full Table Scan on every dashboard metric card calculation (`getDashboardCardCounts`). |
| `support_tickets`| `status`, `assigned_to`, `phone`, `date_created` | Slow ticket lookups in team inbox and support desk. |
| `timeline` | `log_time` | Sequential scans when generating lead activity feeds. |
| `campaign_audience`| `status`, `sent_at`, `campaign_id` | Slow campaign reservation locks during high-volume broadcasts. |

### 3.4 Foreign Key Integrity Gaps
- `support_tickets.lead_id` (`VARCHAR(50) NULL`) has no foreign key constraint linking to `leads(id)`.
- `campaign_audience.campaign_id` (`INT`) has no foreign key constraint linking to `broadcast_campaigns(id)`.
- `client_directory` operates as an unconstrained standalone table.

---

## Check 4: Data Retention & Lifecycle Audit

### 4.1 Soft Delete vs Hard Delete Inconsistencies
- **Leads:** Implements soft deletion (`status = 'dropped'`). Records are preserved in the database and can be reactivated.
- **Quotations & Invoices:** Hard status transitions (`approved`, `rejected`, `paid`). Records are preserved.
- **Lead Documents (`lead_documents`):** Hard delete via `DELETE FROM lead_documents WHERE id = ?` and immediate physical file deletion (`@unlink()`). No recovery mechanism or trash bin exists.
- **Users (`users`):** Hard delete via `DELETE FROM users WHERE id = ?`. Deleting a user orphans their name in `leads.assigned_to`, `timeline.actor`, and `followups.assigned_to`.

### 4.2 Destructive Tenant Deletion Lifecycle
In `modules/admin/crm_clients.php` (lines 490–522):
```php
$pdo_master->exec("DROP TABLE IF EXISTS `{$targetDb}{$tbl}`");
$pdo_master->prepare("DELETE FROM tenant_companies WHERE id = ?")->execute([$tenantId]);
```
- **Flaws Identified:**
  1. **No Automated Snapshot:** Tenant tables are dropped immediately without generating an archive dump. An accidental click by an administrator causes permanent, unrecoverable data loss.
  2. **Orphaned User Accounts:** Deleting the tenant drops the tenant record from `tenant_companies`, but does NOT remove the tenant admin account created in the master `users` table. The orphaned user persists in `users` with status `'Active'`.
  3. **File Residue:** Uploaded client documents in `uploads/` are not purged, consuming permanent disk storage.

### 4.3 Temporary Media Storage Accumulation
- Audio, image, and video media dispatched through WhatsApp are saved to `uploads/whatsapp/` and `uploads/broadcast_media/`.
- Temporary files accumulate on disk indefinitely unless manually triggered via `clean_media` in `api/helpers.php`, violating the project's zero-storage media policy.

---

## Check 5: SaaS Multi-Tenant Configuration Isolation

### 5.1 Setting Collision Vectors
- **WABA Settings Bleed:**
  In `modules/merchant_waba_settings.php` (line 230), updates are executed against `$pdo_master` with `WHERE id = ?`. An authenticated tenant can alter the WhatsApp Business Phone ID, permanent access token, and webhook configuration of another tenant by supplying that tenant's ID.
- **Impersonation Setting Leakage:**
  Because `$_SESSION['impersonate_tenant_db']` allows arbitrary database prefix binding without master audit logging, a compromised company administrator can alter tax, invoice prefix, and branding parameters of competitors.

---

## Remediation Architecture & Action Plan

### 1. Externalize Secrets to Protected `.env` Outside Webroot
- Move all database credentials, Meta tokens, SMTP passwords, and API keys into `.env`.
- Ensure `.env` is added to `.gitignore`.
- Load variables via `vlucas/phpdotenv` or a lightweight native PHP environment parser:
  ```php
  $db_pass = getenv('DB_PASS');
  $waba_token = getenv('META_PERMANENT_TOKEN');
  ```

### 2. Block `.git/` Directory Access in Apache
- In root `.htaccess`, add:
  ```apache
  RedirectMatch 404 /\.git
  ```

### 3. Encrypt Sensitive Regulatory KYC Data at Rest
- Encrypt `pan_number` and `aadhaar_number` before database insertion using AES-256-GCM:
  ```php
  $encryptedAadhaar = openssl_encrypt($rawAadhaar, 'aes-256-gcm', $encryptionKey, 0, $iv, $tag);
  ```
- Display only masked strings in user interfaces: `XXXXXXXX1234`.

### 4. Apply Database Performance Indexes
Execute the following index additions during maintenance:
```sql
ALTER TABLE leads ADD INDEX idx_leads_perf (status, group_stage, assigned_to, created_at);
ALTER TABLE leads ADD INDEX idx_leads_phone (phone);
ALTER TABLE followups ADD INDEX idx_followups_perf (status, scheduled_at, assigned_to);
ALTER TABLE support_tickets ADD INDEX idx_tickets_perf (status, assigned_to, date_created);
ALTER TABLE campaign_audience ADD INDEX idx_audience_perf (campaign_id, status);
```

### 5. Safe Tenant Deletion with Automated Snapshot
- Before executing `DROP TABLE`, dump the tenant's data to an encrypted `.sql.gz` archive in a secure backup folder.
- Purge orphaned accounts from the master `users` table upon tenant deletion.

---

## Conclusion

The database and configuration security audit identified critical exposures in plaintext credential storage, sensitive regulatory identity storage, missing database indexes, and un-snapshotted tenant deletion routines.

**This audit was performed strictly in READ-ONLY mode. Zero code or database modifications have been made.**

Awaiting your authorization before proceeding to the remediation phase.

