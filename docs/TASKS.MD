# Project Implementation Tasks (TASKS.MD)
## Project: MargLead CRM - WhatsApp AI Sales & Autonomous Lead Conversion Engine

---

## Phase 1: Centralized AI Service Adapter
- [ ] **Task 1.1:** Create `includes/ai_service.php` as a universal AI adapter.
  - Implement `callAIService($userPrompt, $history, $systemInstructions)` supporting Google Gemini 1.5/2.0 Flash REST API.
  - Add fallback logic to read `ai_api_key` from `system_settings`, constant `GEMINI_API_KEY`, or environment variables.
  - Implement JSON output parsing mode for structured lead intent detection.
- [ ] **Task 1.2:** Implement unit test script in `scratch/test_ai_service.php` to verify Gemini API connection, response latency, and Hinglish output.

---

## Phase 2: Database Schema & Session Memory
- [ ] **Task 2.1:** Create table `ai_chat_sessions` in MySQL.
  - Columns: `phone VARCHAR(20) PRIMARY KEY`, `conversation_history JSON`, `current_state VARCHAR(50)`, `last_interaction TIMESTAMP`, `lead_id VARCHAR(50)`.
- [ ] **Task 2.2:** Seed default configuration settings into `system_settings`:
  - `ai_sales_enabled = '1'`
  - `ai_provider = 'gemini'`
  - `ai_model = 'gemini-1.5-flash'`
  - `ai_current_offer = ''`
  - `ai_custom_faqs = '[]'`
  - `ai_default_assignee = 'POORNIMA BAJPAI'`
- [ ] **Task 2.3:** Add schema migration updates into `update_live_db.php`.

---

## Phase 3: Webhook Dual-Track Router (`api/webhook.php`)
- [ ] **Task 3.1:** Inspect existing `api/webhook.php` support and ticket flow to map all existing triggers.
- [ ] **Task 3.2:** Implement Track isolation:
  - If incoming payload is `Support` or an existing ticket flow &rarr; Route directly to existing support logic.
  - If incoming payload is `Sales` or general text message &rarr; Route to AI Sales Handler.
- [ ] **Task 3.3:** Maintain conversation memory: fetch previous 6 messages from `ai_chat_sessions` and append user message before dispatching to `callAIService()`.
- [ ] **Task 3.4:** Send AI response back to user via Meta WhatsApp Cloud API text endpoint.

---

## Phase 4: Autonomous Lead & Demo Creation Pipeline
- [ ] **Task 4.1:** Implement intent parser inside AI webhook response listener:
  - Detect `CREATE_LEAD_AND_DEMO` action block.
  - Extract `customer_name`, `product`, `scheduled_at`, `city`, `remarks`.
- [ ] **Task 4.2:** Database insertion logic:
  - Generate unique `LD-XXXXXX` ID.
  - Insert row into `leads` table (`source = 'WhatsApp AI'`, `status = 'new'`).
  - Generate `DM-XXX` ID and insert row into `demos` table (`status = 'scheduled'`).
  - Insert pending followup entry in `followups`.
  - Log activity into `timeline`.
  - Create notification in `notifications` for assigned sales executive.
- [ ] **Task 4.3:** Send WhatsApp Demo Confirmation template/interactive message with demo details to the customer.

---

## Phase 5: CRM Admin Control Panel
- [ ] **Task 5.1:** Create `modules/admin/ai_sales_settings.php` for controlling the AI bot.
  - Master toggle: Enable/Disable AI Sales Assistant.
  - API Credentials input with "Test Connection" button.
  - Active Promotional Offers textarea.
  - FAQ list manager with Add/Remove Question-Answer rows.
  - Lead auto-assignment dropdown (using filtered employee list).
- [ ] **Task 5.2:** Add menu link in CRM Sidebar under Admin/Settings.

---

## Phase 6: End-to-End Testing & Verification
- [ ] **Task 6.1:** Simulate Support message &rarr; Confirm Ticket creation flow works 100% identically without AI disruption.
- [ ] **Task 6.2:** Simulate Sales inquiry (*"Pharma Marg silver price kya hai?"*) &rarr; Verify natural Hinglish explanation.
- [ ] **Task 6.3:** Simulate Demo agreement (*"Kal 11 baje demo karwa do"*) &rarr; Verify Lead & Demo creation in MySQL.
- [ ] **Task 6.4:** Verify leads show up in **Leads Directory** under source `WhatsApp AI`.
- [ ] **Task 6.5:** Git commit and push all documentation and code updates.
