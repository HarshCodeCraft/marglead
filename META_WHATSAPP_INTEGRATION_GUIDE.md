# Meta WhatsApp Cloud API & WhatsApp Flows Integration Guide

Complete step-by-step production guide for setting up **WhatsApp Cloud API**, **WhatsApp Flows**, **Webhooks**, and **Dynamic License Lookup** with the **Marg Lead CRM Ticket Management System**.

---

## 1. Prerequisites & Environment Configuration

### Base & Endpoint URLs (Configured via ngrok)
- **Base URL**: `https://ladder-giver-splendid.ngrok-free.dev/marglead/`
- **Webhook Endpoint URL**: `https://ladder-giver-splendid.ngrok-free.dev/marglead/api/webhook.php`
- **Flow Data Endpoint URL**: `https://ladder-giver-splendid.ngrok-free.dev/marglead/api/flow-endpoint.php`

### Project Configuration File
All API tokens and credentials are kept in `config/config.php`:
```php
define('PHONE_NUMBER_ID', '100609346387812');
define('BUSINESS_ACCOUNT_ID', '100459873456123');
define('ACCESS_TOKEN', 'EAAG...YOUR_PERMANENT_META_ACCESS_TOKEN');
define('VERIFY_TOKEN', 'marglead_whatsapp_token_2026');
define('APP_SECRET', '1a2b3c4d5e6f7g8h9i0j');
define('FLOW_ID', '2356038494923110');
```

---

## 2. Step-by-Step Meta Developer Setup

### Step 1: Create Meta Developer App
1. Go to [Meta Developers Portal](https://developers.facebook.com/).
2. Click **My Apps** &rarr; **Create App**.
3. Select **Business** as the App Type.
4. Name your application (e.g. `Marg Lead Support Bot`) and attach your Meta Business Account.

### Step 2: Add WhatsApp Product
1. In the App Dashboard, locate **WhatsApp** and click **Set up**.
2. Go to **API Setup**.
3. Copy your **Phone Number ID** and **WhatsApp Business Account ID**.
4. Send a test message to your registered test phone number to confirm connectivity.

### Step 3: Generate Permanent System User Access Token
1. Go to [Meta Business Manager](https://business.facebook.com/settings).
2. Go to **Users** &rarr; **System Users** &rarr; **Add**.
3. Name the system user `WhatsApp Bot Admin` with role **Admin**.
4. Click **Add Assets** &rarr; select your WhatsApp Business Account &rarr; enable **Full Control**.
5. Click **Generate New Token** &rarr; select your App &rarr; check permissions:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
6. Copy the generated permanent token and paste it into `config/config.php` as `ACCESS_TOKEN`.

---

## 3. Webhook Registration & Verification

1. In Meta Developer Console, go to **WhatsApp** &rarr; **Configuration**.
2. In the **Webhook** section, click **Edit**.
3. Enter:
   - **Callback URL**: `https://ladder-giver-splendid.ngrok-free.dev/marglead/api/webhook.php`
   - **Verify Token**: `marglead_whatsapp_token_2026`
4. Click **Verify and Save**. (Your PHP script handles GET challenge verification automatically).
5. Under **Webhook Fields**, click **Subscribe** next to `messages`.

---

## 4. WhatsApp Flow Creation & Encryption Setup

### RSA Key Pair Generation
The system has generated a 2048-bit RSA key pair in your `config/` directory:
- **Private Key**: `config/private_key.pem`
- **Public Key**: `config/public_key.pem`

When Meta WhatsApp Manager asks for your **Flow Endpoint Public Key**, copy the contents of `config/public_key.pem` and paste it into WhatsApp Manager.

### Flow Setup Steps
1. Go to **WhatsApp Manager** (`https://business.facebook.com/wa/manage/home`).
2. Go to **Account Tools** &rarr; **Flows** &rarr; Click **Create Flow**.
3. Name: `Support Ticket`
4. Category: **Customer Support** / **Sign In**.
5. Select **Endpoint** mode and set your Data Endpoint URL:
   ```
   https://ladder-giver-splendid.ngrok-free.dev/marglead/api/flow-endpoint.php
   ```
6. Upload your `public_key.pem` contents in the Public Key field.

### Flow Builder JSON Specification
Paste the following Flow JSON into the Flow Builder:

```json
{
  "version": "3.0",
  "screens": [
    {
      "id": "screen_1",
      "title": "Welcome to Marg Soft",
      "data": {
        "license_number": { "type": "string", "__example__": "1352947" },
        "customer_name": { "type": "string", "__example__": "Rajesh Sharma" },
        "firm_name": { "type": "string", "__example__": "Marg Soft Solution" },
        "mobile_number": { "type": "string", "__example__": "9876543210" },
        "email_address": { "type": "string", "__example__": "rajesh@margsoft.com" },
        "amc_expiry_date": { "type": "string", "__example__": "2026-12-31" }
      },
      "terminal": false,
      "layout": {
        "type": "SingleColumnLayout",
        "children": [
          {
            "type": "TextSubheading",
            "text": "Please Provide Your Info and Problem Here.."
          },
          {
            "type": "TextInput",
            "name": "license_number",
            "label": "License Number",
            "helper-text": "Client Id",
            "required": true,
            "input-type": "text",
            "init-value": "${data.license_number}",
            "on-change-action": {
              "name": "data_exchange",
              "payload": {
                "license_number": "${form.license_number}"
              }
            }
          },
          {
            "type": "TextInput",
            "name": "customer_name",
            "label": "Customer Name",
            "required": true,
            "input-type": "text",
            "init-value": "${data.customer_name}"
          },
          {
            "type": "TextInput",
            "name": "firm_name",
            "label": "Firm Name",
            "required": false,
            "input-type": "text",
            "init-value": "${data.firm_name}"
          },
          {
            "type": "Dropdown",
            "name": "issue_category",
            "label": "Subject",
            "required": true,
            "options": [
              { "id": "Installation", "title": "Installation" },
              { "id": "Renewal", "title": "Renewal" },
              { "id": "Technical Support", "title": "Technical Support" },
              { "id": "Billing", "title": "Billing" },
              { "id": "Software Error", "title": "Software Error" },
              { "id": "Training", "title": "Training" },
              { "id": "New Feature Request", "title": "New Feature Request" },
              { "id": "Other", "title": "Other" }
            ]
          },
          {
            "type": "Dropdown",
            "name": "priority",
            "label": "Priority",
            "required": true,
            "options": [
              { "id": "Low", "title": "Low" },
              { "id": "Medium", "title": "Medium" },
              { "id": "High", "title": "High" }
            ]
          },
          {
            "type": "TextArea",
            "name": "description",
            "label": "Problem",
            "helper-text": "Describe problem",
            "required": true,
            "max-length": 600
          },
          {
            "type": "TextInput",
            "name": "mobile_number",
            "label": "Call Back Number",
            "helper-text": "Call Back Number",
            "required": true,
            "input-type": "phone",
            "init-value": "${data.mobile_number}"
          },
          {
            "type": "Footer",
            "label": "Submit",
            "on-click-action": {
              "name": "data_exchange",
              "payload": {
                "action": "submit",
                "license_number": "${form.license_number}",
                "customer_name": "${form.customer_name}",
                "firm_name": "${form.firm_name}",
                "issue_category": "${form.issue_category}",
                "priority": "${form.priority}",
                "description": "${form.description}",
                "mobile_number": "${form.mobile_number}"
              }
            }
          }
        ]
      }
    },
    {
      "id": "SUCCESS",
      "title": "Ticket Submitted",
      "terminal": true,
      "layout": {
        "type": "SingleColumnLayout",
        "children": [
          {
            "type": "TextBody",
            "text": "Your support ticket has been registered successfully. Our engineer will contact you shortly."
          }
        ]
      }
    }
  ]
}
```

6. Click **Save** and **Publish**.
7. Copy the generated **Flow ID** into `config/config.php`.

---

## 5. Required Payload Examples

### A. Incoming Webhook Text Message ("Hi")
```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "100459873456123",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "15550123456",
              "phone_number_id": "100609346387812"
            },
            "contacts": [
              {
                "profile": { "name": "Rajesh" },
                "wa_id": "919876543210"
              }
            ],
            "messages": [
              {
                "from": "919876543210",
                "id": "wamid.HBgMOTE5ODc2NTQzMjEwFQIAERgSQjREOUExNjVDMzQ1RkE2N0E2AA==",
                "timestamp": "1722700000",
                "text": { "body": "Hi" },
                "type": "text"
              }
            ]
          },
          "field": "messages"
        }
      ]
    }
  ]
}
```

---

### B. Outbound Reply Buttons Message (Sales / Support)
```json
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "919876543210",
  "type": "interactive",
  "interactive": {
    "type": "button",
    "header": { "type": "text", "text": "Marg Soft Solution" },
    "body": { "text": "Welcome to ABC Software.\n\nThank you for contacting us.\n\nPlease choose one of the following options." },
    "footer": { "text": "Please select an option" },
    "action": {
      "buttons": [
        { "type": "reply", "reply": { "id": "btn_sales", "title": "Sales" } },
        { "type": "reply", "reply": { "id": "btn_support", "title": "Support" } }
      ]
    }
  }
}
```

---

### C. Outbound WhatsApp Flow Message
```json
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "919876543210",
  "type": "interactive",
  "interactive": {
    "type": "flow",
    "header": { "type": "text", "text": "Marg Help soft solution" },
    "body": { "text": "Provide info and problem here" },
    "footer": { "text": "Managed by Marg soft solution." },
    "action": {
      "name": "flow",
      "parameters": {
        "flow_message_version": "3",
        "flow_token": "flow_token_1722700000_1234",
        "flow_id": "2356038494923110",
        "flow_cta": "Create Ticket",
        "flow_action": "navigate",
        "flow_action_payload": {
          "screen": "screen_1"
        }
      }
    }
  }
}
```

---

### D. Flow Endpoint Request (Dynamic License Lookup)
**Request from Meta Flow Engine to `/api/flow-endpoint.php`**:
```json
{
  "version": "3.0",
  "action": "data_exchange",
  "flow_token": "flow_token_1722700000_1234",
  "screen": "screen_1",
  "data": {
    "license_number": "1352947"
  }
}
```

**Response returned by `/api/flow-endpoint.php`**:
```json
{
  "version": "3.0",
  "screen": "screen_1",
  "data": {
    "license_number": "1352947",
    "customer_name": "Rajesh Sharma",
    "firm_name": "Marg Soft Solution",
    "mobile_number": "9876543210",
    "email_address": "rajesh@margsoft.com",
    "amc_expiry_date": "2026-12-31",
    "license_status": "License Found",
    "is_found": true
  }
}
```

---

### E. Flow Endpoint Submission & Ticket Creation Confirmation Response
**Response returned by `/api/flow-endpoint.php` upon ticket creation**:
```json
{
  "version": "3.0",
  "screen": "SUCCESS",
  "data": {
    "extension_message_response": {
      "params": {
        "flow_token": "flow_token_1722700000_1234",
        "ticket_number": "TK-2026-000001",
        "status": "SUCCESS",
        "message": "Ticket Created Successfully"
      }
    }
  }
}
```

**Automated WhatsApp Confirmation Text sent to Customer**:
```text
✅ Ticket Created Successfully

Ticket Number
TK-2026-000001

Thank you for contacting ABC Software.

Our support engineer will contact you shortly.
```

---

## 6. Verification & End-to-End Testing Procedure

1. **Verify Webhook GET Request**:
   In browser or terminal:
   ```bash
   curl -i "https://ladder-giver-splendid.ngrok-free.dev/marglead/api/webhook.php?hub.mode=subscribe&hub.verify_token=marglead_whatsapp_token_2026&hub.challenge=123456"
   ```
   Expect HTTP 200 with body `123456`.

2. **Verify Dynamic License Lookup Endpoint**:
   ```bash
   curl -X POST -H "Content-Type: application/json" -d '{"action":"data_exchange","data":{"license_number":"1352947"}}' "https://ladder-giver-splendid.ngrok-free.dev/marglead/api/flow-endpoint.php"
   ```
   Expect JSON returning `license_status: "License Found"` and customer details.

3. **Verify Flow Ticket Submission Endpoint**:
   ```bash
   curl -X POST -H "Content-Type: application/json" -d '{"action":"submit","data":{"license_number":"1352947","customer_name":"Rajesh Sharma","firm_name":"Marg Soft Solution","issue_category":"Software Error","priority":"High","description":"GST API sync failing on billing screen","mobile_number":"919876543210"}}' "https://ladder-giver-splendid.ngrok-free.dev/marglead/api/flow-endpoint.php"
   ```
   Expect JSON returning `screen: "SUCCESS"` and a new ticket number e.g. `TK-2026-000001`.

4. **Verify Admin Dashboard**:
   Open in browser:
   `https://ladder-giver-splendid.ngrok-free.dev/marglead/admin/dashboard.php`
