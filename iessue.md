Phase 1: Complete Project Discovery & Read-Only Security Audit Report

Target Application: Core PHP + MySQL Multi-Tenant SaaS CRM (Marg CRM / Friendly AI Solution)
Audit Mode: Strictly Read-Only (Zero Files Modified, Zero Migrations Run, Zero Production Data Altered)
Security Classification: Confidential / Enterprise Architecture & Security Assessment

1. Project Architecture Overview

The system is a monolithic Core PHP application running on PHP 8.x with a MySQL backend. It operates primarily on Apache (with .htaccess rewrite rules) and supports both web desktop browsers and mobile client applications (Capacitor/Cordova/Native mobile frameworks).

Core Architectural Layers
Routing & Presentation Layer: Orchestrated via index.php, which functions as a central router handling public marketing pages (landing.php, pricing.php, terms.php), unauthenticated redirects, and authenticated CRM modules (modules/).
Database & Data Access Layer: Managed via includes/db.php and includes/tenant_helper.php. Database queries use native PHP Data Objects (PDO) with a custom decorator class (TenantAwarePDO) designed to intercept SQL queries and rewrite table names dynamically.
API & Integration Layer: Grouped within the api/ directory. Handles WhatsApp Business Cloud API integrations (Meta Graph API), Webhooks (api/webhook.php), WhatsApp Flows (api/flow-endpoint.php), Client Setup package downloads (api/download_bridge.php), Mobile authentication tokens (api/cors.php), and automated KYC verifications (api/submit_customer_kyc.php).
Background Automation & Scheduling: Managed via cron_scheduler.php, executing periodic tasks such as reminder dispatches, overdue payment notifications, daily digest mailers, and temporary file purging.
2. Complete Module Inventory

The codebase contains 492 scanned project files across PHP, SQL, and configuration scripts. Below is the structured classification:

A. Authentication & Session Management
auth/login.php: Multi-identifier login handler (Email, Company Code, Phone), password validation, single-device session token issuance, brute-force throttling.
auth/logout.php: Session destruction, active session token invalidation.
auth/register.php: User registration and onboarding pipeline.
auth/verify-otp.php: 6-digit email verification OTP handler with direct 1-click link processing.
auth/otp-reset.php & auth/change-password.php: Self-service password recovery with cryptographic reset tokens.
B. Core Operational Modules (modules/)
Leads & Pipeline: modules/leads/list.php, modules/leads/details.php, modules/leads/form.php, modules/leads/import.php, modules/leads/modern_home.php, modules/pipeline.php.
Follow-ups & Demos: modules/followups.php, modules/demo.php.
Commercials & Invoicing: modules/quotation/create.php, modules/quotation/list.php, modules/quotation/view.php, modules/payments.php, modules/bank_accounts.php, modules/renewals.php.
Post-Sales Operations: modules/installation.php, modules/training.php, modules/support.php.
Directory & Partners: modules/clients.php, modules/sub_partners.php.
C. WhatsApp & Meta Cloud API Modules
modules/team_inbox.php: Real-time chat console with canned replies, ticket integration, and 24-hour Meta service window status.
modules/broadcast_campaigns.php: Bulk template broadcasts, Excel file dispatch, campaign analytics.
modules/bot_flows.php & modules/bot_flow_builder.php: Visual node builder for WhatsApp interactive flows and auto-replies.
modules/merchant_waba_settings.php & modules/whatsapp_settings.php: Per-tenant Meta WABA credentials, phone number ID, and access token settings.
D. Administrative & Governance Modules (modules/admin/)
modules/admin/users.php: Internal user lifecycle, role assignments, profile approvals.
modules/admin/permissions.php: Granular Role-Based Access Control (RBAC) matrix configuration.
modules/admin/crm_clients.php: Multi-tenant client provisioning, database/table lifecycle, plan subscriptions, tenant impersonation.
modules/admin/reports.php: Executive KPI aggregation and conversion reports.
modules/admin/settings.php: Global system configuration parameters.
modules/customer_kyc_admin.php: Verification and approval console for customer KYC submissions.
E. Public API Endpoints (api/)
api/auth.php, api/check_session.php, api/mobile_auth.php: Mobile token generation and session heartbeat.
api/cors.php: Global API middleware, CORS headers, mobile token verification, API key gatekeeper.
api/webhook.php: Inbound Meta Graph API webhook event receiver and signature verification.
api/flow-endpoint.php: WhatsApp Flow dynamic data exchange (RSA OAEP decryption + AES-128-GCM).
api/campaign-api.php: Broadcast queue ingestion and campaign status polling.
api/inbox-api.php: WhatsApp message sending, media uploads, canned responses.
api/download_bridge.php: Batch setup script generator for desktop Marg ERP integration.
api/submit_customer_kyc.php: Document validation and file upload processor for business KYC.
3. Database Relationship Overview

The master database (u978772385_friendlyaidata) contains 22+ primary relational tables defined in schema.sql:

[tenant_companies] (1) <--- (N) [users] (company_id)
        |
        +--- (Per-Tenant / Dedicated DB or Prefix t_{slug}_)
                |
                +---> [leads] (id: LD-XXXX)
                |       |
                |       +---> (1:N) [timeline] (lead_id)
                |       +---> (1:N) [followups] (lead_id)
                |       +---> (1:N) [demos] (lead_id)
                |       +---> (1:N) [quotations] (lead_id)
                |       +---> (1:N) [invoices] (lead_id)
                |       +---> (1:N) [installations] (lead_id)
                |       +---> (1:N) [trainings] (lead_id)
                |       +---> (1:N) [renewals] (lead_id)
                |
                +---> [client_directory] (customer_id, party_name, mobile)
                +---> [support_tickets] (id: TCK-XXXX, customer_name, lead_id)
                +---> [bot_flows] (flow_id, screens_json)
                +---> [merchant_waba_settings] (waba_id, phone_number_id)
                +---> [chat_conversations] & [message_logs]
Critical Keys & Multi-Tenant Linkage
Tenant Anchor: tenant_companies.id is the primary key. company_code is unique slug (e.g., poshak).
User Relationship: In users, company_id links the employee or administrator to tenant_companies.id.
Operational Tables: Tables such as leads, client_directory, invoices, and followups do not store a company_id column by default. Data isolation relies entirely on database separation or table prefixing (t_{company_code}_{table}).
Foreign Key Cascades: Lead sub-tables (timeline, followups, demos, quotations, invoices, installations, trainings, renewals) enforce ON DELETE CASCADE linked to leads.id.
4. Authentication Flow
User Input (Email / Code / Phone + Password)
                 |
                 v
       [auth/login.php]
                 |
        +--------+--------+
        | Anti-Brute Force| (5 attempts / 15 min check on login_attempts)
        +--------+--------+
                 |
                 +---> 1. Check `tenant_companies` (Tenant Admin / Company Login)
                 |         - Password Verification (Bcrypt or Legacy Plaintext)
                 |         - Issue Single-Device Token (`active_session_token`)
                 |         - Set `$_SESSION['tenant_db']`, `$_SESSION['tenant_id']`
                 |
                 +---> 2. Check `users` (Internal Staff & Super Admins)
                           - Password Verification (Bcrypt or Auto-Upgrade Plaintext)
                           - Status Check: Unverified (Send OTP) | Pending | Active
                           - Single-Device Token Update
                           - Populate Session Context (`user_id`, `user_role`, `permissions`)
Session Lifetime & Storage
Configured in auth/login.php (lines 31-40) with an explicit 5-hour lifetime (session.gc_maxlifetime = 18000, cookie_lifetime = 18000, httponly = true, samesite = 'Lax').
Single-device login enforcement stores a 64-character random token (active_session_token) in both $_SESSION and the corresponding database record, terminating older active sessions upon concurrent login.
5. Authorization Flow

Authorization operates across two distinct tiers:

Tier 1: Multi-Tenant Session Isolation
During bootstrap (includes/db.php), resolveAndBindTenantSession() checks $_SESSION['tenant_db'] or $_SESSION['impersonate_tenant_db'].
If the current tenant uses an isolated database (marg_crm_{slug}), PDO establishes a dedicated connection.
If using table prefixing (t_{slug}_), PDO wraps database operations via TenantAwarePDO.
Tier 2: Role-Based Access Control (RBAC)
Roles defined in the system: Super Admin, Admin, Tenant Admin, Regional Manager, Team Leader, Sales Executive, Installation Engineer, Support Engineer, Telecaller.
Module routing checks are managed via hasAccess($module, $role) defined in includes/config.php and loaded from modules/admin/permissions.php.
In index.php, tenant accounts are restricted to permitted modules stored in $_SESSION['tenant_allowed_modules'].
6. Multi-Tenant Architecture

The CRM implements a Hybrid Multi-Tenant Model:

Dedicated Database Model (Primary):

Implemented in includes/tenant_helper.php (provisionNewCrmClient).
Attempts CREATE DATABASE marg_crm_{slug} and provisions full table schema from schema.sql.
Each tenant connects to their isolated MySQL database, preventing cross-tenant leakage at the schema level.

Table-Prefix Fallback Model (Shared Hosting / Hostinger):

Used when MySQL privilege restrictions (Error 1044: Access denied for CREATE DATABASE) prevent creating separate schemas.
All tenant tables are created inside the master database with a prefix: t_{codeSlug}_{table} (e.g., t_poshak_leads, t_poshak_quotations).
TenantAwarePDO in includes/db.php intercepts all SQL queries using regex (/\b(FROM|JOIN|INTO|UPDATE)\s+(?table?)\b/i) and dynamically injects the table prefix before execution.
7. Potential Security Weaknesses (Evidence-Based)

The following vulnerabilities and security weaknesses were identified through static code inspection:

Finding 1: Hardcoded Master API Key Backdoor (Critical)
File Path: c:/xampp/htdocs/marglead/api/cors.php
Function/Endpoint: requireApiAuth()
Line Numbers: 219–230
Potential Risk: Complete Authentication Bypass (CVSS 9.8).
Reason: If the environment variable MARG_API_KEY is not set on the server, api/cors.php falls back to the static string 'MARG_CRM_SECURE_API_KEY_2026'. Any unauthenticated request supplying ?api_key=MARG_CRM_SECURE_API_KEY_2026 in the query string or X-API-KEY header is instantly granted Super Admin privileges ('isAdmin' => true, 'role' => 'Super Admin').
Recommended Next Action: Remove hardcoded fallback strings. If the environment variable is missing, reject the request with HTTP 500 or 401 instead of granting Super Admin access.
Finding 2: Plaintext Password Authentication Fallback (High)
File Path: c:/xampp/htdocs/marglead/auth/login.php
Function/Endpoint: Credential validation
Line Numbers: 89, 104, 123, 184
Potential Risk: Authentication Weakness & Password Exposure.
Reason: Login logic explicitly permits plaintext passwords with password_verify($password, $hash) || $password === $hash. Combined with schema.sql lines 268-272 which seed accounts with plaintext '123456', accounts without migrated hashes remain vulnerable to dictionary attacks and plaintext credential leakage.
Recommended Next Action: Enforce password_verify() exclusively. Migrate all legacy plaintext passwords to PASSWORD_BCRYPT or PASSWORD_ARGON2ID during maintenance and purge plaintext seeds.
Finding 3: Webhook HMAC Signature Verification Failure Not Enforced (High)
File Path: c:/xampp/htdocs/marglead/api/webhook.php
Function/Endpoint: Meta Webhook POST Handler
Line Numbers: 64–69
Potential Risk: Inbound Webhook Forgery & Message Spoofing.
Reason: When the Meta HMAC SHA-256 signature check fails, the application executes:
php
if (!verify_meta_signature($rawPayload, APP_SECRET, $sigHeader)) {
    write_log('error', "Webhook HMAC Signature Verification Failed! Continuing processing...");
}
Execution continues despite verification failure. An attacker can forge arbitrary incoming customer messages, WhatsApp delivery statuses, or customer inputs.
Recommended Next Action: Return http_response_code(403) and exit; immediately if signature verification fails.
Finding 4: Unauthenticated & Unencrypted WhatsApp Flow Endpoint Processing (High)
File Path: c:/xampp/htdocs/marglead/api/flow-endpoint.php
Function/Endpoint: Flow Request Ingestion
Line Numbers: 43, 98, 150–152
Potential Risk: Unauthorized Data Manipulation & Database Poisoning.
Reason: The code supports unencrypted plaintext payloads ($requestData = $requestJson; when $isEncrypted is false). An external actor can send raw JSON POST requests to api/flow-endpoint.php without RSA encryption or session authentication, triggering ticket generation, database modifications, or customer data enumeration.
Recommended Next Action: Restrict unencrypted execution strictly to local development (localhost / 127.0.0.1). In production, reject unencrypted requests from Meta.
Finding 5: Anti-CSRF Token Bypass on KYC Submissions (Medium / High)
File Path: c:/xampp/htdocs/marglead/api/submit_customer_kyc.php
Function/Endpoint: Form Submission Handler
Line Numbers: 18–22
Potential Risk: Cross-Site Request Forgery (CSRF).
Reason: The verification check is structured as:
php
if (isset($_POST['csrf_token']) && !verifyCsrfToken($_POST['csrf_token'])) { ... }
If an attacker submits a forged form without including csrf_token, the isset() check evaluates to false, bypassing the anti-CSRF mechanism completely.
Recommended Next Action: Change the validation logic to mandatory: if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])).
Finding 6: Missing Authorization Gate on Full Leads CSV Export (High)
File Path: c:/xampp/htdocs/marglead/index.php
Function/Endpoint: action=export_csv
Line Numbers: 106–153
Potential Risk: Mass Data Exfiltration / Privilege Escalation.
Reason: The export handler queries all records from the leads table and streams them as a CSV file to any logged-in user without evaluating user_role or checking whether the user has export permissions. Low-privilege accounts (e.g., Telecallers) can dump the entire customer database.
Recommended Next Action: Enforce hasAccess('leads_export', $_SESSION['user_role']) or require System Administrator role before executing data exports.
Finding 7: Broad Administrator Privilege Bug in Bank Accounts Module (Medium)
File Path: c:/xampp/htdocs/marglead/modules/bank_accounts.php
Function/Endpoint: Authorization Evaluation
Line Number: 8
Potential Risk: Privilege Escalation / Unauthorized Financial Modification.
Reason: The condition evaluates $is_admin as:
php
$is_admin = (... || !empty($_SESSION['tenant_db']));
In auth/login.php (line 249), $_SESSION['tenant_db'] is populated for every user upon login. Consequently, non-administrative staff (e.g., Sales Executives) are evaluated as $is_admin = true and can create, modify, or delete banking records.
Recommended Next Action: Remove !empty($_SESSION['tenant_db']) from the $is_admin check and evaluate strictly against authorized administrative roles (Super Admin, Admin, Tenant Admin).
Finding 8: Open Redirect Vulnerability in Post-Login Handler (Medium)
File Path: c:/xampp/htdocs/marglead/auth/login.php
Function/Endpoint: Post-Login Redirection
Line Numbers: 171–173
Potential Risk: Phishing / Open Redirect.
Reason: The redirection logic checks:
php
header("Location: " . (strpos($redirect, 'http') === 0 || strpos($redirect, '..') === 0 ? $redirect : '../' . ltrim($redirect, '/')));
Because strpos($redirect, 'http') === 0 is accepted, an attacker can craft a phishing link (auth/login.php?redirect=https://malicious-site.com) that redirects the user to an external domain post-authentication.
Recommended Next Action: Enforce domain validation on $redirect to ensure it only points to relative application paths.
Finding 9: Unrestricted OTP Brute Force Risk (Medium)
File Path: c:/xampp/htdocs/marglead/auth/verify-otp.php and c:/xampp/htdocs/marglead/auth/otp-reset.php
Function/Endpoint: OTP Verification Handler
Line Numbers: auth/verify-otp.php (104–130), auth/otp-reset.php (97–130)
Potential Risk: Account Takeover via Automated Guessing.
Reason: While auth/login.php implements a 5-attempt/15-minute brute-force lockout, the OTP verification endpoints lack attempt counters or rate limits. A 6-digit numeric OTP (1,000,000 combinations) can be subjected to automated enumeration within its 10-minute validity window.
Recommended Next Action: Add an otp_attempts counter to users. Invalidate the OTP and lock attempts after 5 consecutive failures.
Finding 10: SVG Upload Allowed in File Upload Handler (Medium)
File Path: c:/xampp/htdocs/marglead/includes/config.php
Function/Endpoint: secureFileUpload()
Line Numbers: 108–119
Potential Risk: Stored Cross-Site Scripting (XSS).
Reason: image/svg+xml is included in $default_allowed_mimes. SVG files can contain embedded  tags or XML events (onload). If an uploaded SVG is served inline or viewed in a browser, the embedded script executes in the context of the user's session.
Recommended Next Action: Disallow SVG uploads in public file uploaders, or sanitize SVGs using DOMPurify/xml-sanitizer and serve uploads with Content-Disposition: attachment and X-Content-Type-Options: nosniff.
Finding 11: Multi-Tenancy Regex Query Interception Leak Vector (Architectural)
File Path: c:/xampp/htdocs/marglead/includes/db.php
Function/Endpoint: TenantAwarePDO::rewriteQuery()
Line Numbers: 42–130
Potential Risk: Cross-Tenant Data Leakage in Shared DB Mode.
Reason: TenantAwarePDO uses regular expressions to prefix table names (FROM leads -> FROM t_{slug}_leads). Complex queries containing nested subqueries, views, non-standard whitespace, or aliases not anticipated by the regex will fail to prefix the table, causing the query to execute against the master database table instead of the tenant's isolated table.
Recommended Next Action: Transition from regex SQL manipulation to dedicated schema separation (MySQL users/schemas per tenant) or explicit parameterized tenancy filtering (WHERE tenant_id = ?).
8. Suspicious Hardcoded Values & Credentials (Sanitized)

All credentials, keys, and secrets have been redacted to prevent sensitive exposure.

File Path	Line(s)	Description	Classification
config/config.php	48–56	Production DB credentials (DB_USER, DB_PASS) committed in code	Hardcoded Secret
config/config.php	63–70	Remote Hostinger fallback database credentials	Hardcoded Secret
config/config.php	77	Hardcoded external VPS IP address (http://140.238.167.58:3000)	Network Disclosure
config/config.php	89–108	Meta Permanent Access Token (EAAj...), App Secret, Webhook Verify Token	Cloud API Secrets
api/cors.php	219	Hardcoded API backdoor key (MARG_CRM_...)	Static API Token
api/download_bridge.php	11	Fallback API key (MARG-WABA-...) baked into setup batch scripts	Static API Key
schema.sql	24, 269–272	Plaintext default user passwords (123456) in database seeds	Weak Default Credential
config/private_key.pem	N/A	RSA 2048-bit private key file stored in filesystem	Cryptographic Key
9. Files Requiring Deeper Inspection (High-Priority Queue)

Before moving to remediation, the following files require focused evaluation:

includes/tenant_helper.php: Review user account insertion logic to ensure tenant users cannot collide with master system administrators.
api/inbox-api.php: Audit file attachment handling and 24-hour Meta messaging window enforcement.
api/download_bridge.php: Inspect VBScript and batch generation logic for command injection vectors.
modules/leads/import.php: Inspect Excel/CSV parser (fgetcsv / PhpSpreadsheet) for CSV Formula Injection (DDE injection).
modules/admin/crm_clients.php: Validate Super Admin impersonation logic (impersonate_tenant_db) to guarantee session audit logging.
10. Recommended Security Audit & Remediation Plan
Stage 1: Critical Credentials & API Perimeter Lockdown
Move all database credentials, Meta tokens, and API secrets from config/config.php into a protected .env file outside the webroot.
Remove the fallback master API key in api/cors.php. Require valid per-tenant or per-user bearer tokens.
Add strict signature validation rejection (HTTP 403) in api/webhook.php.
Stage 2: Authentication Hardening & Session Security
Discontinue plaintext password fallback comparisons in auth/login.php.
Implement attempt counters and temporary lockouts on auth/verify-otp.php and auth/otp-reset.php.
Restrict post-login redirect targets to local relative URLs.
Stage 3: RBAC & Multi-Tenant Isolation Verification
Audit all export handlers (action=export_csv) in index.php and add permission gates.
Correct the authorization condition in modules/bank_accounts.php.
Reinforce TenantAwarePDO or transition to schema-per-tenant isolation to eliminate regex query bypass risks.
