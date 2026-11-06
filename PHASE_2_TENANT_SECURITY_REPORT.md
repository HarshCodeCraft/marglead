# Phase 2: Multi-Tenant Data Isolation Security Audit Report

**Target Application:** Marg ERP CRM / Friendly AI Solution Multi-Tenant SaaS  
**Primary Security Objective:** Company A must NEVER access Company B's data, and Company B must NEVER access Company A's data across all modules, database queries, API endpoints, AJAX requests, exports, reports, and uploaded documents.  
**Audit Mode:** Strictly Read-Only (Non-Destructive Static & Dynamic Code Assessment)  
**Security Classification:** Highly Confidential / Enterprise SaaS Isolation Assessment  

---

## Executive Summary

An exhaustive multi-tenant security audit was conducted across the Core PHP application to evaluate multi-tenant data boundary enforcement, session binding, database query scoping, Indirect Object References (IDOR), and storage isolation.

### Critical Verdict: FAILED MULTI-TENANT ISOLATION
The current multi-tenant implementation contains systemic design flaws, architectural bypass vectors, and missing tenant scopes. In multiple core modules and API endpoints, **Company A can read, modify, exfiltrate, or delete Company B's confidential records**.

### Top High-Severity Tenancy Violations Identified:
1. **Unrestricted Tenant Impersonation via Parameter Tampering:** Any user possessing an `'Admin'` role can supply arbitrary tenant prefixes via `index.php?action=impersonate_client&db=t_target_` to bind their active session to a competitor's database schema.
2. **Mobile API Master Database Bleed:** In `api/cors.php`, database connections are initialized before the mobile bearer token is resolved. Consequently, all mobile API requests execute against the master database (`pdo_master`), bypassing tenant isolation.
3. **Database Tables Lack `tenant_id` / `company_id` Columns:** Primary transactional entities (`leads`, `followups`, `quotations`, `invoices`, `support_tickets`, `client_directory`, `demos`) contain no `tenant_id` or `company_id` columns. Tenant separation relies entirely on regex table rewriting or separate MySQL schemas.
4. **Public Unauthenticated Storage for Sensitive Customer Documents:** Customer KYC identity records (PAN cards, Aadhaar cards, UDYAM certificates), business invoices, and WhatsApp media are stored in a flat public directory (`uploads/`) without tenant subfolder segregation or token-gated access control.
5. **Centralized Webhook & Inbox Contamination:** Meta Cloud API inbound webhook events (`api/webhook.php`) write all incoming customer messages directly to master `message_logs` and `chat_conversations`. In contrast, tenant workspaces query tenant-prefixed tables, leading to either total data starvation or cross-tenant chat leakage.

---

## Step 1: Tenant Identification & Session Binding Audit

### 1.1 Tenant Context Resolution Flow
Tenant context is evaluated in `includes/db.php` inside `resolveAndBindTenantSession()`:
```
Request Received
       |
       v
Check $_SESSION['impersonate_tenant_db'] ---> If set, immediately return target DB
       | (empty)
       v
Check User Role & Session
       |
       +---> If 'Tenant Admin' or $is_tenant:
       |        Lookup tenant_companies by tenant_id > 1, owner_email, or company_code
       |        Populate $_SESSION['tenant_db'], $_SESSION['tenant_id'], $_SESSION['tenant_code']
       |
       +---> If Internal Employee (company_id = NULL or 0):
                No tenant DB bound; falls back to Master DB (u978772385_friendlyaidata)
```

### 1.2 Identified Tenant Identification Vulnerabilities

#### A. Unauthorized Session Impersonation (Critical Isolation Bypass)
- **File:** `index.php`
- **Lines:** 86–97
- **Vulnerability:**
  ```php
  if (isset($_GET['action']) && $_GET['action'] === 'impersonate_client') {
      $target_db = trim($_GET['db'] ?? '');
      $target_company = trim($_GET['company'] ?? '');
      $user_role = $_SESSION['user_role'] ?? '';
      if (!empty($target_db) && ($user_role === 'Super Admin' || $user_role === 'Admin')) {
          $_SESSION['impersonate_tenant_db'] = $target_db;
          $_SESSION['impersonate_company_name'] = $target_company;
      }
      header("Location: index.php?page=dashboard");
      exit;
  }
  ```
- **Flaw:** When tenant accounts are provisioned via `includes/tenant_helper.php` (line 73), the tenant administrator's role is inserted as `'Admin'`. The impersonation handler does not verify `isSystemAdminRole()`; it tests strictly whether `$user_role === 'Admin'`. As a result, Company A Admin can pass `?action=impersonate_client&db=t_companyb_` and hijack Company B's workspace.
- **Risk:** Complete cross-company account takeover and tenant hopping.

#### B. API Authentication Execution Order Flaw (Master Bleed)
- **File:** `api/cors.php` (lines 50, 102–185)
- **Flaw:** Line 50 includes `includes/db.php`, which immediately executes `resolveAndBindTenantSession()`. At this point, the HTTP request has not been parsed and `$_SESSION` is empty. The database connection defaults to `$pdo_master`. When `requireApiAuth()` subsequently parses the `Bearer` token and resolves `tenant_id`, it updates `$_SESSION['tenant_id']` but never reinitializes `$pdo` to the tenant's isolated PDO decorator (`TenantAwarePDO`).
- **Risk:** All mobile API requests operate on the master database, exposing cross-company data.

#### C. Employee Tenant Disconnect
- **File:** `modules/admin/users.php` (lines 232–235) and `auth/login.php` (lines 178, 251–256)
- **Flaw:** When a tenant administrator creates a new staff operator (Sales Executive, Telecaller) via `modules/admin/users.php`, the query inserts the record without writing `company_id`. During login, `auth/login.php` only binds tenant context if `$user['role'] === 'Tenant Admin' || !empty($user['company_id'])`. Because the employee's `company_id` is null, the employee is assigned to the master database context instead of their company.

---

## Step 2: Database Query Audit

### 2.1 Absence of `tenant_id` / `company_id` Column in Data Schema
Inspection of `schema.sql` and live MySQL DDL reveals that operational tables lack a multi-tenant anchor column:

| Table Name | Primary Key | Contains `tenant_id`? | Contains `company_id`? | Isolation Mechanism Relied Upon |
| :--- | :--- | :--- | :--- | :--- |
| `leads` | `id` (VARCHAR) | No | No | Regex Table Prefixing (`t_{slug}_leads`) |
| `followups` | `id` (INT) | No | No | Foreign Key to `leads(id)` |
| `demos` | `id` (VARCHAR) | No | No | Foreign Key to `leads(id)` |
| `quotations` | `id` (VARCHAR) | No | No | Foreign Key to `leads(id)` |
| `invoices` | `id` (VARCHAR) | No | No | Foreign Key to `leads(id)` |
| `installations` | `id` (VARCHAR) | No | No | Foreign Key to `leads(id)` |
| `trainings` | `id` (VARCHAR) | No | No | Foreign Key to `leads(id)` |
| `support_tickets` | `id` (VARCHAR) | No | No | Table Prefixing |
| `client_directory` | `id` (INT) | No | No | Table Prefixing |
| `broadcast_campaigns` | `id` (INT) | No | No | Table Prefixing |
| `campaign_audience` | `id` (INT) | No | No | Table Prefixing |
| `bank_accounts` | `id` (INT) | No | No | Table Prefixing |
| `lead_documents` | `id` (INT) | No | No | Foreign Key to `leads(id)` |

### 2.2 Unsafe Query Patterns & Bypasses

#### A. Direct `$pdo_master` Queries in Tenant Settings
- **File:** `modules/merchant_waba_settings.php`
- **Line:** 230
- **Query:**
  ```php
  $pdo_master->prepare("UPDATE merchant_waba_settings SET send_bill_pdf = ?, gateway_type = ?, phone_number_id = ?, waba_id = ?, access_token = ?, business_phone = ?, web_api_url = ?, web_api_token = ?, web_api_instance_id = ?, tenant_api_key = ? WHERE id = ?");
  ```
- **Flaw:** By querying `$pdo_master` directly with a single `WHERE id = ?` clause, tenant isolation is bypassed. Company A can modify the Meta API tokens and webhook configurations of Company B by submitting Company B's record ID.

#### B. Mass Lead Export without Tenant Filtering
- **File:** `index.php`
- **Lines:** 107–153
- **Query:**
  ```php
  $stmt = $pdo->query("SELECT id, name, contact_person, company, phone, email, address, source, priority, status, assigned_to, budget, enq_for, remarks, tags, created_at FROM leads ORDER BY created_at DESC");
  ```
- **Flaw:** If an administrative or employee user accesses this endpoint while in master database context or via session contamination, the query dumps all records across the master table without scoping to a specific tenant.

#### C. Unsafe Bulk Operations
- **File:** `index.php`
- **Lines:** 358, 363, 368, 373
- **Query:**
  ```php
  $stmt = $pdo->prepare("UPDATE leads SET assigned_to = ?, assigned_by = ?, updated_at = NOW() WHERE id IN ($in_clause)");
  ```
- **Flaw:** Missing tenant ownership predicate (`AND company_id = ?`). Relies entirely on the table rewriter. If executed under master context, leads belonging to other companies can be reassigned or dropped.

#### D. Cross-Tenant Audience Processing in Broadcast Engine
- **File:** `api/campaign-api.php`
- **Lines:** 926–931
- **Query:**
  ```php
  $stmtAud = $pdo->prepare("SELECT * FROM campaign_audience WHERE campaign_id = ? AND status = 'processing' ORDER BY id ASC LIMIT $batchSize");
  ```
- **Flaw:** Relies exclusively on `campaign_id`. Does not verify that the campaign belongs to the authenticated user's tenant before executing message dispatches.

---

## Step 3: Hardcoded Tenant Audit

The code scan identified several instances of hardcoded IDs, static tenant fallbacks, and parameter-controlled company IDs:

| File Path | Line | Code Finding | Classification | Risk Description |
| :--- | :--- | :--- | :--- | :--- |
| `index.php` | 88 | `$target_db = trim($_GET['db'] ?? '');` | URL-supplied Tenant DB | Direct tenant database selection via GET parameter. |
| `api/campaign-api.php` | 49 | `$userId = $_SESSION['user_id'] ?? 1;` | Static User ID Fallback | Defaulting to Super Admin User 1 when session user is missing. |
| `api/campaign-api.php` | 472–484 | Hardcoded HDFC Account & Deepak Awasthi UPI ID | Fallback Master Data | Master tenant commercial bank details dispatched in tenant broadcasts. |
| `modules/bank_accounts.php` | 7 | `$user_id = $_SESSION['user_id'] ?? 1;` | Static User ID Fallback | Grants default administrative context to unassigned sessions. |
| `modules/bulk_broadcast.php` | 15 | `$user_id = $_SESSION['user_id'] ?? 1;` | Static User ID Fallback | Broadcast operations default to master owner user ID. |
| `modules/sub_partners.php` | 12 | `$user_id = $_SESSION['user_id'] ?? 1;` | Static User ID Fallback | Sub-partner modifications default to master account. |
| `modules/admin/permissions.php`| 12 | `$user_id = $_SESSION['user_id'] ?? 1;` | Static User ID Fallback | Permission resolver falls back to User 1. |
| `modules/admin/crm_clients.php`| 351–354 | `SELECT * FROM tenant_companies WHERE id = ?` via `$_GET['tenant_id']` | URL Parameter Object Lookup | Tenant configuration download based on unvalidated GET parameter. |

---

## Step 4: Insecure Direct Object Reference (IDOR) Audit

A total of 91 SQL queries across the application filter records strictly by primary record ID without verifying tenant ownership:

### 4.1 Critical IDOR Vectors

#### Vector 1: Lead Details & Modification
- **Endpoints:**
  - `index.php?page=lead_details&id={ID}`
  - `index.php?page=lead_form&id={ID}`
  - `api/leads.php?id={ID}`
- **Source Code (`modules/leads/details.php` line 523):**
  ```php
  $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
  $stmt->execute([$id]);
  $lead = $stmt->fetch();
  ```
- **Evaluation:** No tenant validation is performed. In dedicated DB mode, cross-lookup is prevented only by schema separation. In shared DB or master context, supplying another company's `lead_id` (e.g. `LD-9021`) displays full client contact details, calling history, budget, and commercial notes.

#### Vector 2: Lead Document Deletion
- **Endpoint:** `index.php?page=lead_details&id={LEAD_ID}` (POST `action=delete_lead_document`)
- **Source Code (`modules/leads/details.php` lines 498–507):**
  ```php
  $stmtF = $pdo->prepare("SELECT * FROM lead_documents WHERE id = ?");
  $stmtF->execute([$docId]);
  $docRec = $stmtF->fetch();
  if ($docRec) {
      @unlink(__DIR__ . '/../../' . $docRec['file_path']);
      $pdo->prepare("DELETE FROM lead_documents WHERE id = ?")->execute([$docId]);
  }
  ```
- **Evaluation:** The endpoint takes `doc_id` from POST data without checking whether `doc_id` belongs to the current tenant or the active lead. Submitting arbitrary `doc_id` values deletes documents from the server filesystem and database.

#### Vector 3: Support Ticket State Alteration
- **Endpoints:**
  - `api/tickets.php` (line 81)
  - `modules/support.php` (lines 617, 746)
- **Source Code:**
  ```php
  $stmt = $pdo->prepare("UPDATE support_tickets SET status = ?, resolved_date = ... WHERE id = ?");
  $stmt->execute([$status, $ticket_id]);
  ```
- **Evaluation:** Tickets can be resolved, modified, or reassigned without checking tenant association.

#### Vector 4: Quotation Lifecycle Manipulation
- **Endpoint:** `index.php?page=quotation_list` (POST `action=approve` / `action=reject`)
- **Source Code (`modules/quotation/list.php` lines 21–25):**
  ```php
  if ($act === 'approve') {
      $pdo->prepare("UPDATE quotations SET status = 'approved' WHERE id = ?")->execute([$quote_id]);
  }
  ```
- **Evaluation:** Any authenticated user can force commercial approval or rejection of quotes belonging to other companies by passing the quotation ID.

---

## Step 5: Cross-Tenant Security Simulation Matrix

A theoretical simulation was conducted across 4 distinct tenant personas to assess data boundary enforcement:

| Test Operation | Company A Admin | Company A Employee | Company B Admin | Company B Employee | Result / Boundary Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **View Lead Record** | Can view Comp B lead via impersonation parameter | Blocked in UI; Accessible if token used against `/api/leads.php` | Can view Comp A lead via impersonation parameter | Blocked in UI; Accessible via `/api/leads.php` | **FAILED (Isolation Broken)** |
| **Edit / Update Lead** | Can modify Comp B lead via `impersonate_client` | Blocked within tenant UI | Can modify Comp A lead via `impersonate_client` | Blocked within tenant UI | **FAILED (Isolation Broken)** |
| **Delete Documents** | Can delete Comp B files by targeting `doc_id` | Can delete Comp B files if possessing document ID | Can delete Comp A files by targeting `doc_id` | Can delete Comp A files if possessing document ID | **FAILED (Critical IDOR)** |
| **Download Attachments**| Can download any document via public URL path | Can download any document via public URL path | Can download any document via public URL path | Can download any document via public URL path | **FAILED (Public URL Access)** |
| **Export Leads CSV** | Exports Comp B leads after setting impersonation | Master export dumps master leads without tenant filter | Exports Comp A leads after setting impersonation | Master export dumps master leads without tenant filter | **FAILED (Unsafe Export)** |
| **Operational Reports**| Scoped to active DB prefix; leakable via impersonate | Scoped to active DB prefix | Scoped to active DB prefix; leakable via impersonate | Scoped to active DB prefix | **FAILED (Impersonation Leak)** |
| **WhatsApp Team Inbox**| Starved of incoming messages or sees all master chats | Starved of incoming messages or sees all master chats | Starved of incoming messages or sees all master chats | Starved of incoming messages or sees all master chats | **FAILED (Webhook Architecture Bug)** |
| **Broadcast Campaigns**| Can trigger dispatch of Comp B campaign by ID | Can trigger dispatch of Comp B campaign by ID | Can trigger dispatch of Comp A campaign by ID | Can trigger dispatch of Comp A campaign by ID | **FAILED (IDOR Execution)** |

---

## Step 6: Detailed Remediation Architecture & Action Plan

To establish enterprise-grade multi-tenant data isolation, the following architectural remediations must be implemented:

### 1. Enforce Server-Side Tenant Scoping in All Queries
- Add an explicit `tenant_id INT NOT NULL` column to every operational table (`leads`, `followups`, `quotations`, `invoices`, `support_tickets`, `client_directory`, `demos`, `lead_documents`, `broadcast_campaigns`, `merchant_waba_settings`).
- Add composite indexes: `INDEX idx_tenant_id (tenant_id)`, `INDEX idx_tenant_lead (tenant_id, id)`.
- Never rely solely on table prefixing. Enforce parameterized tenant validation on every query:
  ```sql
  SELECT * FROM leads WHERE id = :id AND tenant_id = :tenant_id LIMIT 1;
  UPDATE quotations SET status = :status WHERE id = :id AND tenant_id = :tenant_id;
  DELETE FROM lead_documents WHERE id = :id AND tenant_id = :tenant_id;
  ```

### 2. Secure Tenant Impersonation
- In `index.php`, replace role check `($user_role === 'Super Admin' || $user_role === 'Admin')` with strict master check:
  ```php
  if (!isSystemAdminRole() || empty($_SESSION['is_master_admin'])) {
      http_response_code(403);
      exit('Unauthorized: Impersonation restricted to Master Super Administrators.');
  }
  ```
- Generate an immutable audit log entry in `activity_logs` whenever an impersonation session begins or terminates.

### 3. Fix API Authentication & Tenant DB Binding Order
- In `api/cors.php`, restructure `requireApiAuth()` so that immediately after validating a Bearer token or API key, the tenant database context is resolved and `$pdo` is re-bound to the tenant before any API controller executes:
  ```php
  $_SESSION['tenant_id'] = $tenantId;
  $_SESSION['tenant_db'] = $tenantDb;
  $pdo = TenantAwarePDO::createFromExisting($pdo_master, $tenantDb);
  ```

### 4. Implement Storage Segregation & Token-Gated Downloads
- Restructure the storage filesystem by tenant: `uploads/{tenant_id}/{category}/{filename}`.
- Block direct HTTP access to `uploads/` via Apache configuration:
  ```apache
  Require all denied
  ```
- Route all document downloads through an authenticated proxy (`download.php?doc_id=...`):
  ```php
  $stmt = $pdo->prepare("SELECT file_path, doc_name FROM lead_documents WHERE id = ? AND tenant_id = ?");
  $stmt->execute([$docId, $currentTenantId]);
  $doc = $stmt->fetch();
  if (!$doc) {
      http_response_code(404);
      exit('Document not found or access denied.');
  }
  // Stream file safely with Content-Disposition: attachment
  ```

### 5. Multi-Tenant Inbound Webhook Routing
- In `api/webhook.php`, extract the Meta `phone_number_id` from the payload header.
- Query `tenant_whatsapp_configs` on master to resolve which `tenant_id` owns the recipient number.
- Bind `$pdo` to that tenant's database context before inserting into `message_logs` and `chat_conversations`.

---

## Conclusion

The multi-tenant isolation mechanism currently exhibits critical vulnerabilities that permit cross-tenant data leakage, session impersonation, and direct object tampering. 

**This phase was executed strictly in READ-ONLY mode. Zero code or database modifications have been made.**

Awaiting your authorization before proceeding to the remediation phase.

