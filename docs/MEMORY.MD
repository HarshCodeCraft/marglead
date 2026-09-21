# Persistent Project Memory, Architecture & System Inventory (MEMORY.MD)
## Project: MargLead CRM - Complete Technical State & Decision Log

---

## 1. Project Overview & System Scope
**MargLead CRM** is an enterprise-grade Lead Management, Software Demonstration, Client AMC Portfolio, Support Ticketing, and WhatsApp Automation platform specifically engineered for Marg ERP sales partners, distributors, and support teams.

The application operates on a LAMP/WAMP stack (PHP 8.x + MySQL/MariaDB + Apache) deployed locally via XAMPP and live on Hostinger Cloud.

---

## 2. Inventory of Implemented Core Modules & Capabilities

### 2.1 Lead Management & Sales Pipeline (`modules/leads/`)
* **Leads Directory (`list.php`):**
  * Pipeline Stages: `Fresh`, `Followup`, `Future Prospect`, `Demo Scheduled`, `Demo Done`, `Installation Done`, `Not Required`, `Won`, `Lost`.
  * Multi-dimensional filtering: Date, Source, Priority (`Hot`, `Warm`, `Cold`), Pipeline Status, Assigned Employee, and Group/Stage.
  * Search: Full-text search across Lead ID, Name, Firm/Company, GST Number, Phone (with international +91 normalization), Email, Address, Source, Tags, Remarks.
  * Display: Responsive grid cards with Contact Person, Firm Name, Monospace GST Badge, Click-to-Call, Smartphone QR Dial pad generator, and Source tagging.
  * Export: Custom CSV/Excel exporter with dynamic column toggling (`exp-source`, `exp-status`, `exp-priority`).
* **Lead Profile & 360° History (`details.php`):**
  * Complete client directory parameters, contact person, primary mobile with direct WhatsApp/Call triggers.
  * Location display with City, State, Address, and formatted GST Number badge.
  * Upcoming & Past Follow-up timeline, scheduled software demos, support tickets history, and call logs.
  * Activity Timeline: Granular audit trail tracking who modified the lead, assigned operators, or logged notes.
* **Lead Creation & Editing (`form.php`):**
  * International country code selector (+91 default) with exact 10-digit validation.
  * Real-time duplicate phone number detection modal with match lookup.
  * Additional fields: Contact Person / Legal Name, GST Number (uppercase formatted, 15 chars max), Enq_For product selector, Remarks.
  * Post-creation Immediate Follow-up UX Wizard modal for scheduling the next call before navigating away.

---

### 2.2 Universal Spreadsheet Import Engine (`modules/leads/import.php`)
* **Multi-Strategy Parser:** Automatically parses `.xlsx` (OpenXML Zip), `.xls` (BIFF8 / XML 2003 / HTML table exports), `.csv`, and `.tsv`.
* **GST Portal & GSTN Auto-Detection:**
  * When an Excel file containing GST data (e.g. `Legal Name`, `Trade Name`, `GST No.`, `Mobile No.`, `State`, `City`, `Pincode`, `Date`) is uploaded, the system:
    * Mappings: `Trade Name` &rarr; Firm / Company Name & Lead Title.
    * Mappings: `Legal Name` &rarr; Contact Person / Proprietor.
    * Mappings: `GST No.` &rarr; Dedicated `gst` column (uppercase, alphanumeric).
    * Mappings: `Mobile No.` &rarr; Clean 10-digit primary phone.
    * Mappings: `City` + `State` + `Pincode` &rarr; Auto-combined formatted address (e.g., *"Kanpur, Uttar Pradesh - 208015"*).
    * Mappings: `Date` &rarr; Parsed into `created_at` timestamp.
    * Auto-Locks Source to **`GSTN Data`**.
* **Duplicate Detection:** Cross-references database duplicates by both Clean 10-digit Mobile Number AND GST Number.
* **Preview Grid Table:** Displays row-by-row validation with dedicated `GST No.` badge, contact person, firm name, duplicate match indicator, and batch source selector.

---

### 2.3 Software Demo Scheduling Hub (`modules/demo.php`)
* **Capabilities:**
  * Schedules Online Demos (Google Meet, AnyDesk, TeamViewer) and Onsite Office visits.
  * Assigns Host Engineers from the internal filtered employee list.
  * Auto-updates Lead pipeline status to `demo_scheduled`.
  * Auto-creates a corresponding entry in `followups` table so upcoming demos appear in follow-up calendars.
  * Logs demo creation event to `timeline`.

---

### 2.4 Follow-up & Reminder System (`modules/followups.php`)
* **Capabilities:**
  * Daily scheduled follow-ups hub with Pending, Missed, and Completed tabs.
  * Action types: `Call`, `WhatsApp`, `Demo`, `Visit`, `Renewal`.
  * Quick status toggles, note additions, and rescheduling controls.
  * Auto-linking to active telecallers and sales executives.

---

### 2.5 Client & AMC Portfolio Directory (`modules/clients.php` & `client_directory`)
* **Capabilities:**
  * Client Directory tracking Marg ERP software customers.
  * Fields: Marg Customer ID, Party Name, Software Type (Marg Basic/Silver/Gold), User Type (Single User, Multi-User, LAN), No. of Users, AMC Due Date, Party Status (`Running`, `Closed`), Category (`Category A`, `B`, `C`), Area, City, State.
  * Direct WhatsApp AMC renewal reminder dispatch.

---

### 2.6 Customer Support & Ticketing Desk (`modules/support.php`)
* **Capabilities:**
  * Support tickets registered via WhatsApp bot or web portal.
  * Ticket status: `Open`, `In Progress`, `Resolved`, `Closed`.
  * Assignment to Support Engineers / Technicians.
  * Automated WhatsApp notifications upon ticket generation and resolution/closure.

---

### 2.7 WhatsApp Communication Dual-Gateway
* **Track 1: Official Meta WhatsApp Cloud API (`v20.0`):**
  * Webhook listener (`api/webhook.php`) with cryptographic payload signature verification.
  * Interactive WhatsApp buttons (`[Sales]`, `[Support]`), lists, and WhatsApp Flow forms for ticket creation.
* **Track 2: Unofficial/Web Baileys Engine (`whatsapp_engine/`):**
  * Node.js + `@whiskeysockets/baileys` socket server for direct QR pairing.
  * Bulk Broadcast Campaigns (`modules/broadcast_campaigns.php`, `modules/bulk_broadcast.php`) with image attachments and delivery logging.

---

### 2.8 Universal Role-Based Access Control & Employee Filtering
* **Internal Staff Roles:** `Super Admin`, `Admin`, `Support Executive`, `Telecaller`, `Sales Executive`.
* **External Portal Roles:** `Tenant Admin`, `Tenant User`, `Client`, `Customer`.
* **Enforced Cleansing Rule:** Across all employee/operator dropdowns (`list.php`, `form.php`, `details.php`, `followups.php`, `clients.php`, `demo.php`, `support.php`, `import.php`), client/tenant roles are strictly excluded:
  ```sql
  WHERE status = 'Active' 
    AND LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant')
  ```

---

### 2.9 Live Database Migration Engine (`update_live_db.php`)
* **Purpose:** Single-click browser-accessible script to synchronize live Hostinger database schema with local development updates.
* **Automations Included:**
  * Checks and adds `category`, `area`, `no_of_companies` to `client_directory`.
  * Checks and adds `tags`, `enq_for`, `contact_person`, `assigned_by`, `installation_status`, `gst` to `leads`.
  * Fixes `city` and `state` column lengths.
  * Normalizes legacy `status = NULL` leads to `'new'`.

---

## 3. Key Historical Bug Fixes & Gotchas

| Issue Encountered | Root Cause | Permanent Fix Implemented |
| :--- | :--- | :--- |
| **GST Leads missing in Leads Directory filter** | 3,014 leads had `status = NULL`. In SQL, `LOWER(status) NOT IN ('won', ...)` evaluates to `NULL` (False), excluding all rows. | Updated WHERE clause to `(status IS NULL OR TRIM(status) = '' OR LOWER(status) NOT IN (...))` and normalized DB records to `'new'`. |
| **Undefined array key `"assigned_to"` in `import.php`** | `col_indices` array didn't pre-initialize keys, throwing PHP warning on spreadsheets without assignee columns. | Initialized all `$field_mappings` keys dynamically to `-1` and used null-coalescing `($col_indices['...'] ?? -1)`. |
| **Clients showing up in Employee dropdown** | Queries did `SELECT name FROM users WHERE status = 'Active'` without role filters, including `Tenant Admin` client accounts like *ENA ORCHID FASHIONS*. | Added `AND LOWER(role) NOT IN ('client', 'customer', 'tenant admin', 'tenant user', 'tenant')` across all modules. |
| **GitHub Push Blocked by Secret Scanning** | An active GCP API key was committed to `config/config.php:82`. | Removed hardcoded secret, used `getenv('GEMINI_API_KEY') ?: ''`, amended commit, and pushed cleanly. |

---

## 4. Active Golden Reference: Marg ERP Editions & Pricing

* **Marg Basic Edition:** Single User — ₹8,999 + 18% GST
* **Marg Silver Edition:** Single User Pharma/Retail/Inventory + Expiry + GST + WhatsApp — ₹12,600 + 18% GST
* **Marg Gold Edition:** Multi-User Unlimited LAN Nodes — ₹25,200 + 18% GST
* **Key Strengths to Pitch:** 60%+ pharmaceutical market share in India, near-expiry alerts, automatic distributor invoice CSV/Excel import, 100% drug license and narcotic compliance.

---

## 5. Upcoming Architecture: WhatsApp AI Sales Engine
* **Isolation:** Two-track webhook router in `api/webhook.php`.
* **Track A (Support):** Untouched existing ticket creation and resolution flow.
* **Track B (Sales):** Centralized Gemini 1.5/2.0 Flash AI assistant answering questions in Hinglish and autonomously inserting leads into `leads` and `demos` tables upon agreement.
* **Centralization:** Single API key and model setting managed via `includes/ai_service.php` and DB table `system_settings`.
