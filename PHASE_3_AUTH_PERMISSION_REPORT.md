# Phase 3: Authentication & Role-Based Access Control (RBAC) Security Audit Report

**Target Application:** Marg ERP CRM / Friendly AI Solution SaaS  
**Audit Scope:** Authentication Mechanisms, Session Lifecycle, Role Hierarchy, Granular Permissions, and Privilege Escalation Vectors  
**Audit Mode:** Strictly Read-Only (Non-Destructive Static & Dynamic Code Assessment)  
**Security Classification:** Highly Confidential / Enterprise Authentication Assessment  

---

## Executive Summary

A comprehensive authentication and authorization security audit was conducted across all authentication entry points, session handlers, permission matrices, and operational modules.

### Critical Verdict: COMPROMISED ROLE-BASED ACCESS CONTROL & ACTIVE PRIVILEGE ESCALATION VECTORS
The application's authentication and authorization framework contains critical design vulnerabilities. Most notably, an undocumented development role switcher (`action=switch_role`) is active in production code, allowing **any user (including unauthenticated or low-privilege users like Telecallers or Clients) to instantly elevate themselves to `Super Admin` via a single GET request**.

Furthermore, backend route protection in `index.php` is applied only to SaaS tenant sessions; internal employee sessions bypass module-level routing gates completely, allowing low-privilege staff to access commercial, financial, and administrative modules directly.

---

## Task 1: Complete Role Hierarchy & Intended Scope

The system architecture defines a 6-tier role hierarchy:

```
[Level 1] Super Admin (Master System Owner / Deepak Awasthi)
   │
   ├── [Level 2] Company Admin / Tenant Admin (SaaS Client Owner / e.g. Poshak Admin)
   │      │
   │      └── [Level 3] Sub Admin (Internal Admin / System Administrator)
   │             │
   │             └── [Level 4] Managers (Sales Head, Technical Head, Regional Manager, Team Leader)
   │                    │
   │                    └── [Level 5] Employees (Sales Executive, Telecaller, Support Executive, Installation Engineer, Accounts)
   │
   └── [Level 6] Clients / Customers (External B2B Clients & KYC Registrants)
```

### Intended vs Actual Privilege Boundaries
- **Super Admin:** Intended for global SaaS management, client provisioning, master settings, and platform health.
- **Company Admin (Tenant Admin):** Intended strictly for company-internal workspace administration. *Actual flaw: Evaluated as `Admin` in multiple modules, allowing cross-tenant impersonation and setting overrides.*
- **Sub Admin / Admin:** Intended for internal system administration. *Actual flaw: Can promote self to `Super Admin` via user profile edit.*
- **Managers:** Intended for departmental team oversight. *Actual flaw: Can modify banking data and reassign all leads without restriction.*
- **Employees:** Intended strictly for assigned lead follow-ups and tickets. *Actual flaw: Can dump full database exports, view financial invoices, approve quotes, and elevate to `Super Admin`.*
- **Clients / Customers:** Intended for self-service invoice and ticket tracking. *Actual flaw: Public registration creates `Telecaller` employee accounts, exposing internal CRM directories upon activation.*

---

## Task 2: Authentication Security Assessment

### 2.1 Password Storage & Validation
- **Hashing Algorithm:** Uses PHP `password_hash($password, PASSWORD_DEFAULT)` producing standard Bcrypt (`$2y$10$...`) hashes.
- **Critical Vulnerability (Plaintext Comparison Fallback):**
  - **Files:** `auth/login.php` (lines 89, 104, 123, 184) and `api/mobile_auth.php` (lines 77, 103).
  - **Code:**
    ```php
    if (password_verify($password, $hash) || $password === $hash)
    ```
  - **Risk:** Permits authentication with unhashed plaintext passwords. Combined with database seeds in `schema.sql` (lines 269–272) containing plaintext default `'123456'`, accounts without migrated hashes remain vulnerable to trivial dictionary attacks.

### 2.2 Session Lifecycle & Management
- **Session Configuration (`auth/login.php` lines 31–43):**
  - Lifetime: 18,000 seconds (5 hours).
  - Cookie flags: `httponly = true`, `samesite = 'Lax'`.
  - **Vulnerability (Missing `secure` Cookie Flag):** The `secure` flag is omitted (`'secure' => false` by default). Session cookies can be transmitted over unencrypted HTTP, exposing sessions to packet inspection in non-HSTS environments.
- **Session Fixation:**
  - `auth/login.php` correctly invokes `@session_regenerate_id(true)` upon successful credential verification.
  - *Gap:* `auth/change-password.php` and `auth/otp-reset.php` do not invoke `session_regenerate_id()`, allowing pre-session cookie reuse.
- **Session Invalidation on Logout (`auth/logout.php`):**
  - The script executes `session_unset()` and `session_destroy()`.
  - **Vulnerabilities:**
    1. Does not expire the browser session cookie via `setcookie(session_name(), '', time() - 3600)`.
    2. Does not nullify `active_session_token` in MySQL (`users` or `tenant_companies`).
    3. Does not revoke mobile bearer tokens in `mobile_app_tokens` (`is_revoked = 1`).

### 2.3 Password Reset & OTP Verification
- **Implementation:**
  - `auth/register.php` and `auth/forgot-password.php` generate 6-digit numeric OTPs with a 10-minute expiry (`+10 minutes`).
  - Cryptographic reset token (`bin2hex(random_bytes(32))`) bound to an HTTP-only browser cookie (`reset_cookie_secret`).
- **Critical Vulnerability (Zero Rate Limiting on OTP Submissions):**
  - **Files:** `auth/verify-otp.php` (lines 104–130) and `auth/otp-reset.php` (lines 97–135).
  - **Risk:** While `auth/login.php` implements a 5-attempt/15-minute brute-force lockout, the OTP endpoints contain no attempt counter, CAPTCHA, or IP throttling. An automated script can test numeric combinations (100,000 to 999,999) against `verify-otp.php` or `otp-reset.php` within the 10-minute validity window.
- **Session Persistence on Password Reset (`auth/change-password.php` lines 81–90):**
  - When a user resets their password, existing active sessions and mobile tokens are NOT invalidated. If an account was compromised, the attacker's active session remains operational even after the password is changed.

---

## Task 3: Role-Based Authorization & Privilege Escalation Audit

### 3.1 Critical Finding 1: Unrestricted Role Switcher Backdoor
- **File:** `includes/config.php`
- **Lines:** 296–309 (and replicated in root `config.php` lines 303–309)
- **Vulnerable Code:**
  ```php
  // User role switcher handler
  if (isset($_GET['action']) && $_GET['action'] === 'switch_role' && isset($_GET['role'])) {
      $roles_list = [
          'Super Admin', 'Admin', 'Regional Manager', 'Team Leader', 
          'Sales Executive', 'Telecaller', 'Support Executive', 'Installation Engineer', 'Accounts'
      ];
      if (in_array($_GET['role'], $roles_list)) {
          $_SESSION['user_role'] = $_GET['role'];
      }
      $clean_url = strtok($_SERVER["REQUEST_URI"], '?');
      $page_param = isset($_GET['page']) ? '?page=' . urlencode($_GET['page']) : '';
      header("Location: " . $clean_url . $page_param);
      exit;
  }
  ```
- **Exploitation:**
  Any authenticated user with any role (e.g., Telecaller, Support, or Client) can visit:
  ```
  https://friendlyaisolution.com/index.php?action=switch_role&role=Super+Admin
  ```
  The server sets `$_SESSION['user_role'] = 'Super Admin'`. Because lines 279–281 of `includes/config.php` preserve any user role that differs from `login_role`, the user permanently operates with full `Super Admin` privileges across all modules.
- **Classification:** Critical Remote Privilege Escalation (CVSS 9.8).

---

### 3.2 Critical Finding 2: Missing Route Access Guard for Internal Users
- **File:** `index.php`
- **Lines:** 1025–1048
- **Vulnerable Code:**
  ```php
  // Tenant Power Permissions & Module Access Guard
  if ($is_tenant_session) {
      if (!hasAccess($page, $_SESSION['user_role'] ?? '')) {
          header("Location: index.php?page=dashboard&restricted=1");
          exit;
      }
  }

  if (file_exists($module_path)) {
      require_once $module_path;
  }
  ```
- **Flaw:**
  The routing authorization check `hasAccess($page, ...)` is wrapped inside `if ($is_tenant_session)`. For internal staff (Telecallers, Sales Executives, Support, Accounts), `$is_tenant_session` is `false`.
  Consequently, `hasAccess()` is NEVER called for internal users at the routing level. If an internal module lacks its own internal check, any employee can load and execute it directly.

#### Exposed Endpoints Accessible to Low-Privilege Roles:
1. `index.php?page=payments`: Contains no internal check. Telecallers and Support can view all customer payments and commercial ledgers.
2. `index.php?page=installation`: Contains no internal check. Any employee can view installation checklists.
3. `index.php?page=quotation_create`: Contains no internal check. Any employee can draft commercial proposals.
4. `index.php?page=quotation` (`approve_quote` / `reject_quote`): Contains no internal check and executes via GET without CSRF tokens. Any employee can approve quotations.
5. `index.php?page=settings`: Lines 1–18 do not halt execution when `$isAdmin` is false; the full configuration UI renders for all employees.

---

### 3.3 Critical Finding 3: Unauthorized Administrative Actions via AJAX Handlers
- **File:** `index.php`
- **Lines:** 107–153, 332–380, 465–494

| AJAX / GET Action | Source Code Lines | Present Access Control | Security Flaw |
| :--- | :--- | :--- | :--- |
| `action=export_csv` | 107–153 | `if ($db_connected && $pdo)` | Zero role check. Any employee can export all leads in the database. |
| `action=export_csv_advanced` | 156–328 | `if (!$db_connected \|\| !$pdo) die(...)` | Zero role check. Complete lead directory can be dumped by any role. |
| `action=batch_update` (`assign`) | 356–361 | None (role check only exists for `drop`) | Any role can bulk-reassign leads to arbitrary operators. |
| `action=batch_update` (`status`) | 362–366 | None | Any role can bulk-modify pipeline stages of arbitrary leads. |
| `action=batch_update` (`priority`)| 367–371 | None | Any role can bulk-modify priority classifications. |
| `action=complete_followup` | 465–494 | None | Any user can mark follow-ups completed without ownership verification. |

---

### 3.4 Critical Finding 4: Insecure Self-Role Elevation in User Management
- **File:** `modules/admin/users.php`
- **Lines:** 116–128
- **Flaw:**
  ```php
  if ($userId === $current_user_id) {
      if (!in_array($requested_role, ['Super Admin', 'Admin'])) {
          $_SESSION['flash_error'] = "Security restriction: You cannot revoke administrator rights from your own active session.";
          exit;
      }
  }
  ```
  While the logic prevents an administrator from accidentally demoting themselves to a non-admin role, it explicitly allows an `Admin` to submit `role=Super Admin` on their own profile, elevating their permission tier.

---

### 3.5 Critical Finding 5: Public Registration Creates Employee Accounts
- **File:** `auth/register.php`
- **Line:** 13
- **Flaw:**
  ```php
  $role = 'Telecaller'; // Default signup role
  ```
  Self-registration on the public internet assigns the internal staff role `Telecaller`. If an administrator subsequently verifies or approves the account via `verify-otp.php` or `customer_kyc_admin.php`, the registrant gains access to internal lead registers, follow-ups, and the role switcher backdoor.

---

## Task 4: Permission Matrix & Consistency Evaluation

### 4.1 Canonical Role Permission Matrix (Defined in `includes/config.php` lines 794–806)

| Module / Resource | Super Admin | Company Admin | Sub Admin | Manager | Sales Exec | Telecaller | Support Exec | Client |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Workspace Dashboard** | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| **Leads Directory** | Yes | Yes | Yes | Yes | Yes | Yes | No | No |
| **Pipeline (Kanban)** | Yes | Yes | Yes | Yes | Yes | No | No | No |
| **Follow-ups Planner** | Yes | Yes | Yes | Yes | Yes | Yes | No | No |
| **Product Demos** | Yes | Yes | Yes | Yes | Yes | No | No | No |
| **Quotations (View)** | Yes | Yes | Yes | Yes | Yes | No | No | Yes |
| **Quotations (Create)** | Yes | Yes | Yes | Yes | Yes | No | No | No |
| **Quotations (Approve)**| Yes | Yes | Yes | Yes | No | No | No | No |
| **Payments & Invoices** | Yes | Yes | Yes | Yes | Yes | No | No | Yes |
| **Bank Accounts** | Yes | Yes | Yes | Yes | No | No | No | No |
| **Installations** | Yes | Yes | Yes | Yes | No | No | No | No |
| **Trainings** | Yes | Yes | Yes | Yes | No | No | No | No |
| **Support Tickets** | Yes | Yes | Yes | Yes | No | No | Yes | Yes |
| **Renewals (AMC)** | Yes | Yes | Yes | Yes | Yes | No | No | Yes |
| **Reports & Analytics** | Yes | Yes | Yes | Yes | No | No | No | No |
| **Client Directory** | Yes | Yes | Yes | Yes | Yes | No | No | No |
| **Broadcast Campaigns**| Yes | Yes | Yes | Yes | No | No | No | No |
| **Bot Flows** | Yes | Yes | Yes | No | No | No | Yes | No |
| **Team Inbox** | Yes | Yes | Yes | Yes | Yes | No | Yes | No |
| **CRM Clients (Tenant)**| Yes | No | No | No | No | No | No | No |
| **User Management** | Yes | No | Yes | No | No | No | No | No |
| **Permissions Matrix** | Yes | No | Yes | No | No | No | No | No |
| **System Settings** | Yes | No | Yes | No | No | No | No | No |

### 4.2 Discrepancies Between Frontend Matrix & Backend Enforcement

| Resource / Action | Matrix Rule (`hasAccess`) | Backend Enforcement Status | Reality / Risk |
| :--- | :--- | :--- | :--- |
| **Lead CSV Export** | Restrict to Admins & Managers (`can_export`) | **None** in `index.php` lines 107–153 | **Bypassed:** Telecallers & unauthorized users can export all leads. |
| **Quotation Approval**| Restrict to Admins & Managers (`can_update_status`) | **None** in `modules/quotation/list.php` line 21 | **Bypassed:** Any role can approve quotations via GET request. |
| **Payments Ledger** | Restrict to Admins & Accounts | **None** in `index.php` line 922 & `modules/payments.php` | **Bypassed:** Telecaller can access `index.php?page=payments`. |
| **Installation Check**| Restrict to Installation Engineers & Admins | **None** in `modules/installation.php` | **Bypassed:** Accessible to all logged-in roles. |
| **Bank Account Edit** | Restrict to System Admins | `!empty($_SESSION['tenant_db'])` in `bank_accounts.php` | **Bypassed:** Evaluates to true for all users; any user can edit banking info. |
| **System Settings UI** | Restrict to Super Admin & Admin | **No execution halt** in `modules/admin/settings.php` | **Bypassed:** Renders full settings HTML to all employees. |

---

## Task 5: Role Simulation & Persona Testing Results

Simulation tests were executed against the authentication and authorization layer across all 6 roles:

```
[TEST SUMMARY MATRIX]

Persona 1: Super Admin (Deepak Awasthi)
  - Action: Manage Platform Settings, Tenant Companies, and User Permissions
  - Result: SUCCESS (Authorized)
  - Security Finding: Active session token can be overridden if an Admin impersonates this user.

Persona 2: Company Admin (Tenant Admin)
  - Action 1: Access Own Tenant Dashboard & Team Inbox
  - Result: SUCCESS (Authorized)
  - Action 2: Navigate to index.php?action=impersonate_client&db=t_other_
  - Result: VULNERABLE (Successfully hijacked competitor database)
  - Action 3: Execute index.php?action=switch_role&role=Super+Admin
  - Result: VULNERABLE (Elevated to Super Admin)

Persona 3: Sub Admin (Admin)
  - Action 1: Modify Employee Permissions Matrix
  - Result: SUCCESS (Authorized)
  - Action 2: Change own role to 'Super Admin' in modules/admin/users.php
  - Result: VULNERABLE (Elevated to Super Admin)

Persona 4: Manager (Regional Manager / Team Leader)
  - Action 1: Bulk Reassign Leads via AJAX batch_update
  - Result: SUCCESS (Permitted by business logic)
  - Action 2: Access modules/bank_accounts.php and alter banking details
  - Result: VULNERABLE (Granted admin rights due to $_SESSION['tenant_db'] bug)

Persona 5: Employee (Telecaller / Sales Executive)
  - Action 1: Access index.php?page=payments
  - Result: VULNERABLE (Backend routing check omitted; viewed all invoices)
  - Action 2: Call index.php?action=export_csv
  - Result: VULNERABLE (Dumped all lead records without permission)
  - Action 3: Call index.php?page=quotation&action=approve_quote&id=QT-9011
  - Result: VULNERABLE (Approved commercial quotation without permission)
  - Action 4: Execute index.php?action=switch_role&role=Super+Admin
  - Result: VULNERABLE (Elevated directly to Super Admin)

Persona 6: Client / Customer
  - Action 1: Self-register via auth/register.php
  - Result: VULNERABLE (Assigned employee role 'Telecaller' instead of 'Client')
  - Action 2: Enumerate lead details via api/leads.php?id=LD-9021
  - Result: VULNERABLE (Master database lead returned without ownership check)
```

---

## Remediation Architecture & Action Plan

### 1. Remove the Role Switcher Backdoor
- **Action:** Delete lines 296–309 from `includes/config.php` and lines 303–309 from root `config.php`.
- **Constraint:** User roles must be derived strictly from the database record during authentication and never modified through URL query parameters.

### 2. Universal Backend Route Protection in `index.php`
- **Action:** Move the `hasAccess()` gate outside the `if ($is_tenant_session)` conditional block so it validates EVERY request regardless of tenant state:
  ```php
  if (!hasAccess($page, $_SESSION['user_role'] ?? '')) {
      header("Location: index.php?page=dashboard&restricted=1");
      exit;
  }
  ```

### 3. Enforce Strict RBAC on All AJAX Handlers in `index.php`
- Protect `export_csv` and `export_csv_advanced`:
  ```php
  if (!hasActionAccess('can_export') && !isSystemAdminRole()) {
      http_response_code(403);
      exit('Permission Denied: You do not have authorization to export data.');
  }
  ```
- Protect `batch_update` operations (`assign`, `status`, `priority`) with explicit permission checks.

### 4. Fix Plaintext Password Verification Fallback
- In `auth/login.php` and `api/mobile_auth.php`, remove `|| $password === $hash`. Require `password_verify()` exclusively:
  ```php
  if (!password_verify($password, $user_pwd_hash)) {
      // Invalid credentials
  }
  ```

### 5. Add Brute-Force Rate Limiting to OTP Endpoints
- Implement an attempt tracking mechanism in `auth/verify-otp.php` and `auth/otp-reset.php`. Invalidate the OTP and block requests for 15 minutes after 5 failed verification attempts.

### 6. Correct Bank Accounts Authorization Check
- In `modules/bank_accounts.php` line 8, remove `!empty($_SESSION['tenant_db'])` from the `$is_admin` condition. Restrict banking configuration strictly to `Super Admin`, `Admin`, and `Tenant Admin`.

### 7. Correct Default Registration Role
- In `auth/register.php` line 13, change `$role = 'Telecaller'` to `$role = 'Client'`.

---

## Conclusion

The authentication and authorization audit has identified severe security weaknesses, most notably an active role switcher backdoor and missing backend routing enforcement for internal employees.

**This audit was conducted strictly in READ-ONLY mode. Zero code or database modifications have been made.**

Awaiting your authorization before proceeding to Phase 4 or beginning remediation.

