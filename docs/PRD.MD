# Product Requirements Document (PRD)
## Project: MargLead CRM - WhatsApp AI Sales & Autonomous Lead Conversion Engine

---

## 1. Executive Summary
MargLead CRM provides pharmaceutical, retail, and manufacturing businesses with enterprise lead management, support ticketing, and WhatsApp communication. 

This initiative introduces a **24/7 Generative AI Sales Assistant** directly integrated into the existing WhatsApp Business Cloud API webhook. The AI acts as a smart, persuasive sales representative that educates incoming leads about Marg ERP software (pricing, editions, industry features), answers questions in natural Hindi/Hinglish, and autonomously converts interested prospects into qualified **CRM Leads** with booked **Software Demos**—all while preserving the current customer support and ticket generation system with **zero conflict**.

---

## 2. Problem Statement
1. **Missed After-Hours Inquiries:** Customers messaging during off-hours, weekends, or busy periods do not receive instant sales consultation, leading to drop-offs.
2. **Support vs. Sales Conflict:** Team members already use WhatsApp for generating customer support tickets. Any automated AI layer must never interfere with existing support workflows, ticket creation forms, or ticket status notifications.
3. **Manual Lead Entry Friction:** Converting a WhatsApp conversation into a structured CRM lead requires manual data entry by telecallers, causing delays in scheduling software demos.

---

## 3. Goals & Objectives
* **Zero Disruption to Existing Workflows:** Current team ticket creation and customer `[Support]` button workflows must run identically without alterations.
* **Autonomous Sales Consultation:** AI answers software questions (Pharma, Retail, GST, Multi-User, Barcode, Pricing) using verified Marg ERP domain data.
* **Frictionless Demo Booking:** The AI detects customer buying intent and offers a *"Book Free Demo"* action, capturing customer name, phone, product requirements, and preferred timing.
* **Instant CRM Lead Insertion:** Leads agreeing to a demo are automatically inserted into the `leads` and `demos` tables in MySQL, tagged with source `WhatsApp AI`, and assigned to active sales executives.
* **Centralized AI Provider Architecture:** API keys and provider models (Google Gemini, OpenAI, Claude) must be centralized in CRM Settings so that keys or providers can be swapped instantly without modifying codebase files.

---

## 4. User Personas & Scenarios

### Persona 1: Prospective Buyer (Chemist / Retailer)
* **Goal:** Understand if Marg ERP supports expiry tracking and pharmacy drug licenses, and how much it costs.
* **Flow:** Sends *"Hi, pharma ke liye software chahiye"*. AI greets, explains Marg Silver Pharma features (Near-expiry alerts, Batch tracking, GST billing), states pricing, and asks to schedule a free 15-minute online demo.

### Persona 2: Existing Customer Needing Technical Help
* **Goal:** Resolve an invoice error or data corruption.
* **Flow:** Types *"Hi"* or *"Support"*. System displays `[Sales]` and `[Support]` buttons. Customer taps `[Support]`, fills ticket details via WhatsApp Flow, receives ticket confirmation ID. AI does not interfere.

### Persona 3: CRM Sales Telecaller / Admin
* **Goal:** Receive structured, pre-qualified leads ready for software installation/demo walkthrough.
* **Flow:** Receives real-time CRM notification: *"New Lead [TASMAFIVE SOLUTIONS] registered via WhatsApp AI - Demo Requested"*. Telecaller opens lead profile with full conversation history and initiates Google Meet / Anydesk walkthrough.

---

## 5. Functional Requirements

### 5.1 Two-Track Message Router
* **Track A (Support Pipeline):**
  * Trigger: Customer taps `[Support]`, types *"ticket"*, or has an active open support session.
  * Action: Executes existing `api/webhook.php` ticket generation and notification flow.
* **Track B (AI Sales Pipeline):**
  * Trigger: Customer taps `[Sales]`, inquires about software, features, pricing, or types general business questions.
  * Action: Routes conversation through Centralized AI Engine (`askAIAssistant()`).

### 5.2 Conversational AI Intelligence
* Natural multilingual comprehension: Pure Hindi, Romanized Hindi (Hinglish), and English.
* Context memory: Retains last 6–10 messages per customer session (stored in session cache or DB).
* Verified pricing guardrails: Only quotes authorized prices (Basic ₹8,999, Silver ₹12,600, Gold ₹25,200 + GST).
* Soft demo pitch: Proactively offers a 15-minute live demo every 2–3 messages.

### 5.3 Autonomous Lead & Demo Capture
* Detects affirmative intent (*"haan demo chahiye"*, *"kal kara do"*, *"book demo"*).
* Generates structured JSON payload:
  * `lead_name`: Business or contact name.
  * `phone`: WhatsApp sender MSISDN.
  * `enq_for`: Product edition (e.g., Marg Silver Pharma).
  * `scheduled_at`: Preferred date/time.
  * `remarks`: Summary of customer's business needs.
* Inserts row into `leads` table (`source = 'WhatsApp AI'`, `status = 'new'`).
* Inserts row into `demos` table (`status = 'scheduled'`).
* Sends WhatsApp confirmation card with demo details and WhatsApp interactive buttons.

### 5.4 Centralized Configuration & Admin Controls
* Admin UI page: `index.php?page=ai_sales_settings`.
* Ability to toggle AI Bot ON/OFF globally or per business number.
* Input fields for API Key, Active Model, Custom Offers, and FAQs.
* Single-click save updates DB table `system_settings`.

---

## 6. Non-Functional Requirements
* **Response Latency:** End-to-end WhatsApp reply generation < 2.5 seconds.
* **Data Security:** API keys stored securely; no customer financial credentials requested or logged.
* **Reliability:** Graceful fallback if AI API times out (e.g. *"Our executive is connecting with you shortly"*).
* **Audit Trail:** Every AI lead creation logged to CRM `timeline` table.
