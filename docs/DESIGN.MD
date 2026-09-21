# UI / UX & System Integration Design (DESIGN.MD)
## Project: MargLead CRM - WhatsApp AI Sales & Autonomous Lead Conversion Engine

---

## 1. CRM Admin Settings UI Design (`index.php?page=ai_sales_settings`)

A dedicated administrative interface built within MargLead CRM allowing non-technical managers to control, train, and test the AI Sales Assistant without code modifications.

### 1.1 Visual Layout Specifications
* **Theme:** Consistent with MargLead Dark/Light design tokens (`var(--bg-card)`, `var(--primary)`, `var(--border-color)`).
* **Header Bar:**
  * Title: *"AI Sales & WhatsApp Agent Control Center"*
  * Live Status Badge: `[ Status: ACTIVE (Gemini 1.5 Flash) ]` with green ping dot.
  * Master Kill-Switch Toggle: `[ Enable / Disable AI Sales Bot ]`.

### 1.2 Form Sections

#### Section 1: AI Provider & API Credentials
* **Active Engine Selector:** Dropdown (`Google Gemini (Recommended)`, `OpenAI (GPT-4o)`, `Anthropic (Claude 3.5 Sonnet)`).
* **API Key:** Password-masked input field with visibility toggle `[ Show/Hide ]` and `[ Test Connection ]` button.
* **Model ID:** Configurable text input (defaults to `gemini-1.5-flash` / `gemini-2.0-flash`).
* **Temperature Slider:** Range slider from `0.0` (Strict/Factual) to `1.0` (Creative/Engaging), defaulted to `0.4`.

#### Section 2: Current Promotional Offers & Announcements
* **Active Campaign Pitch Text Area:**
  * Example: *"Diwali Special: Marg Silver par 10% instant discount + 1 Year free WhatsApp billing package."*
  * Dynamic Injection: Text entered here is automatically appended to the AI's real-time prompt context.

#### Section 3: Dynamic Knowledge Base & FAQs Manager
* **Interactive FAQ Repeater:**
  * Add Question / Answer pairs (e.g., *"Does it work on Mac?"*, *"Can I convert from Tally data?"*).
  * Instant Add/Remove buttons.
* **Custom Knowledge Upload:**
  * Rich-text box for custom business policies, payment QR links, and partner credentials.

#### Section 4: Lead Auto-Assignment & Notification Rules
* **Default Assignee for AI Leads:** Dropdown populated from filtered employees list (`POORNIMA BAJPAI`, `SAHIL SAVITA`, `MOIN KHAN`, etc.).
* **Alert Notifications:** Checkboxes for `[ Send WhatsApp Alert to Telecaller ]`, `[ Send Email Alert ]`, `[ Sound Bell on Dashboard ]`.

---

## 2. WhatsApp Message & Interactive Button Design

### 2.1 Initial Welcome Greeting (Menu Card)
Sent when an unknown prospect sends a greeting (*"Hi"*, *"Hello"*):
```
👋 Namaste! Welcome to Marg Soft Solution.
Aapka business aage badhane ke liye hum aapki kya sahayata kar sakte hain?

Kripya neeche diye gaye vikalp me se chunein:
```
* **Interactive Button 1:** `🛍️ New Software / Pricing` (Triggers Track B - AI Sales Engine)
* **Interactive Button 2:** `🛠️ Technical Support` (Triggers Track A - Existing Ticket Flow)

---

### 2.2 AI Consultation Message Formatting
When answering customer queries:
```
✅ Chemist & Pharma shops ke liye **Marg ERP Silver Edition** sabse best solution hai!

Isme aapko milta hai:
• 🔔 Expiry Alerts (Bikne se pehle expiry ki jankari)
• 💊 100% Drug License & Narcotic Register Compliance
• ⚡ 5-second GST Invoicing & WhatsApp Billing

💰 Price: ₹12,600 + GST (Single System)

Kya kal subah 11:00 AM par aapki shop ke liye ek **Free 15-Minute Online Demo** schedule kar dein?
```

---

### 2.3 Demo Confirmation Card (Instant Lead Capture)
Sent as soon as customer agrees to a demonstration:
```
🎉 Badhai ho! Aapka Free Demo schedule ho gaya hai.

📋 **Demo Details:**
• Business: [Firm Name]
• Software: Marg Silver Pharma Edition
• Date & Time: Tomorrow, 11:00 AM
• Mode: Online Google Meet / AnyDesk Walkthrough

Hamare senior product expert aapse demo link ke sath WhatsApp par judenge. 🚀
```
* **Interactive Button:** `📅 Reschedule Demo`

---

## 3. Database Schema & Data Models

### 3.1 Table: `ai_chat_sessions`
Tracks ongoing WhatsApp conversation state and context:
```sql
CREATE TABLE IF NOT EXISTS ai_chat_sessions (
    phone VARCHAR(20) PRIMARY KEY,
    conversation_history JSON NOT NULL,
    current_state VARCHAR(50) DEFAULT 'idle',
    last_interaction TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lead_id VARCHAR(50) NULL,
    INDEX idx_last_interaction (last_interaction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 Additions to `system_settings`
New configuration keys managed via the admin UI:
* `ai_sales_enabled`: `'1'` or `'0'`
* `ai_provider`: `'gemini'` | `'openai'`
* `ai_api_key`: Encrypted/plaintext API key
* `ai_model`: `'gemini-1.5-flash'`
* `ai_current_offer`: Text description of active discounts
* `ai_custom_faqs`: JSON array of `{question, answer}`
* `ai_default_assignee`: Employee name for incoming leads
