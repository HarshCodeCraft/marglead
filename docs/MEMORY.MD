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

## 4. Active Golden Reference: Marg ERP Editions & Pricing (Verified from margcompusoft.com)

* **Marg ERP Nano:** ₹5,550 + 18% GST (Nano 1.0 to 4.0; Max 2 users @ ₹3,000/extra user, Max 2 companies @ ₹3,000/extra company)
* **Marg Basic Edition:** Single User — ₹10,300 + 18% GST (Limited Edition; Max 2 users @ ₹3,000/extra user, Max 2 companies @ ₹3,000/extra company)
* **Marg Silver Edition:** 1 User Full Rights + 1 View Only — ₹13,900 + 18% GST (Pharma/Retail/Inventory + Expiry + GST + WhatsApp, ₹3,000/extra user or company)
* **Marg Gold Edition:** Multi-User — ₹26,000 + 18% GST (Unlimited Users & Unlimited Extra Companies)
* **Key Strengths to Pitch:** 60%+ pharmaceutical market share in India, near-expiry alerts, automatic distributor invoice CSV/Excel import, 100% drug license and narcotic compliance.

---

## 5. Implemented: WhatsApp AI Sales Assistant & Granular Team Inbox Access
* **Dual-Track Webhook Routing (`api/webhook.php`):**
  * **Track A (Support Desk):** Pure greetings ("Hi", "Hello") send Welcome buttons (`[Sales]`, `[Support]`). Direct technical issue queries (e.g. "print error", "not working", "ticket") or clicking `[Support]` instantly trigger Meta WhatsApp Ticket Flow (`FLOW_ID`). 100% regression-free.
  * **Track B (AI Sales Assistant):** Direct questions or clicking `[Sales]` trigger `handleAISalesAssistantInteraction()`. AI answers in Hinglish, follows strict Lakshman Rekhas (official pricing only, zero data leak, support handoff), and autonomously schedules software demos in `demos`, creates leads in `leads`, and logs follow-ups.
* **Centralized AI Adapter (`includes/ai_service.php`):**
  * Reads configuration dynamically from `ai_bot_settings` table (with env variable fallback).
  * Isolated REST API call to Google Gemini 1.5/2.0 Flash with JSON structured response (`reply_text`, `action`, `lead_details`).
  * Graceful timeout and network failure fallback message.
* **Team Inbox Granular Visibility & Channels (`modules/team_inbox.php` & `api/inbox-api.php`):**
  * Channel tagging: `[All]`, `[🎫 Support Tickets]`, `[🤖 AI Sales]`.
  * Employee visibility modes in `users.inbox_visibility`:
    * `default`: Auto-segmented by role (Support Executives see Support Tickets, Sales Executives see Sales Leads, Admins see All).
    * `support_assigned`: Only tickets assigned to this employee.
    * `support_all`: All company support tickets.
    * `sales_assigned`: Only leads assigned to this employee.
    * `sales_all`: All sales and AI lead chats.
    * `all`: Full company access.
  * **Human Takeover & AI Muting:** When an agent replies manually from Team Inbox, `muteAISession()` automatically silences the AI for that contact. Header has `[🤖 AI Active • Takeover]` and `[👤 Agent Active • Resume AI]` toggle button.
* **Admin Control Center (`modules/admin/ai_sales_settings.php`):**
  * Master Bot Switch, API Key manager, Marg ERP pricing matrix editor, System Prompt & Knowledge Base editor, and live interactive AI Test Simulator.
