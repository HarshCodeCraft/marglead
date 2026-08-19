# 🚀 Hostinger Live Production Deployment Checklist & File Guide
**Project**: Marg Soft Solution - Lead & WhatsApp CRM (`marglead`)  
**Target Host**: Hostinger Shared / Cloud Hosting (hPanel)

---

## 📁 1. Files & Code Locations to Update Before Live Upload

| File Path | What to Edit / Change | Reason |
| :--- | :--- | :--- |
| `config/config.php` | • Change `NGROK_URL` / `BASE_URL` to your Live Domain (e.g. `https://yourdomain.com` or `https://crm.yourdomain.com/marglead`)<br>• Update `DB_HOST` to `'localhost'`<br>• Update `DB_PORT` to `'3306'`<br>• Update `DB_NAME`, `DB_USER`, `DB_PASS` to Hostinger DB credentials | Set live production domain and Hostinger MySQL database connection parameters. |
| `schema.sql` | • Import into Hostinger **phpMyAdmin** database. | Creates all database tables (`users`, `leads`, `tickets`, `support_tickets`, `merchant_waba_settings`, `tenant_whatsapp_configs`, `bot_flows`, etc.). |
| `.htaccess` | • Ensure uploaded to root folder (`public_html/` or `public_html/marglead/`) | Enforces HTTPS redirection, disables directory browsing, and sets up clean URLs. |
| `config/private_key.pem`<br>`config/public_key.pem` | • Upload both RSA key files to `config/` directory.<br>• Ensure permissions are set to `600` or `644`. | Required for decrypting Meta WhatsApp Flow data payload on Hostinger. |
| Directory Permissions | • `uploads/` directory -> `755` permission<br>• `logs/` directory -> `755` permission | Allows uploading ticket attachments, invoice PDFs, and logging webhooks. |

---

## 🛠️ 2. Detailed Step-by-Step Deployment Instructions

### Step 1: Create MySQL Database in Hostinger hPanel
1. Log in to **Hostinger hPanel**.
2. Go to **Databases** -> **MySQL Databases**.
3. Create a new database & username:
   - **Database Name**: `u123456789_margcrm` *(example)*
   - **Database Username**: `u123456789_marguser` *(example)*
   - **Password**: `YourStrongPassword123!`
4. Click **Enter phpMyAdmin**, select your new database, and click **Import**.
5. Choose `schema.sql` from your project folder and click **Go**.

---

### Step 2: Edit `config/config.php` for Live Production

Open `config/config.php` on your editor before uploading and update these lines:

```php
// 1. Live Domain URL Settings
define('NGROK_URL', 'https://friendlyaisolution.com'); // Live domain
define('BASE_URL', rtrim(NGROK_URL, '/') . '/');

// 2. WhatsApp Meta Cloud API Configuration
define('GRAPH_API_VERSION', 'v20.0');
define('META_APP_ID', '2484306222079451');
define('PHONE_NUMBER_ID', '1230372206833600');
define('BUSINESS_ACCOUNT_ID', '1230372206833600');
define('ACCESS_TOKEN', 'YOUR_META_PERMANENT_ACCESS_TOKEN');
define('VERIFY_TOKEN', 'marglead_whatsapp_token_2026');
define('FLOW_ID', '1838065533836150');

// 3. Hostinger MySQL Database Credentials
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u978772385_friendlyaidata'); // Hostinger DB Name
define('DB_USER', 'u978772385_friendlyaidata'); // Hostinger DB User
define('DB_PASS', 'YourStrongPassword123!'); // Hostinger DB Password
define('DB_CHARSET', 'utf8mb4');
```

---

### Step 3: Update Database Configuration Tables (Via phpMyAdmin or Setup Script)

Run this SQL query in Hostinger **phpMyAdmin SQL tab** to ensure `merchant_waba_settings` & `tenant_whatsapp_configs` have your live phone ID:

```sql
-- Update Merchant WABA Settings for User 1
UPDATE merchant_waba_settings 
SET phone_number_id = '1230372206833600', 
    waba_id = '1230372206833600', 
    business_phone = '+91 92773 87778', 
    status = 'Active' 
WHERE user_id = 1;

-- Update Tenant WhatsApp Configs for User 1
INSERT INTO tenant_whatsapp_configs (user_id, firm_name, waba_id, phone_number_id, display_phone_number, verified_name, signup_method, status)
VALUES (1, 'marg soft solution', '1230372206833600', '1230372206833600', '+91 92773 87778', 'marg soft solution', 'manual', 'active')
ON DUPLICATE KEY UPDATE 
    firm_name = VALUES(firm_name), 
    display_phone_number = VALUES(display_phone_number), 
    status = 'active';
```

---

### Step 4: Update URLs in Meta Developer Dashboard

Log in to **developers.facebook.com** and update your webhook & flow endpoints:

1. **WhatsApp Webhook Callback URL**:
   - Go to **WhatsApp** -> **Configuration** -> **Webhook**.
   - Edit Callback URL: `https://yourdomain.com/api/webhook.php` *(or `https://yourdomain.com/marglead/api/webhook.php`)*
   - Verify Token: `marglead_whatsapp_token_2026`
   - Fields Subscribed: `messages`, `messaging_postbacks`, `message_template_status_update`.

2. **WhatsApp Flow Data Endpoint**:
   - Go to **WhatsApp Business Manager** -> **Flows** -> **Endpoint Settings**.
   - Set Endpoint URL: `https://yourdomain.com/api/flow-endpoint.php`
   - Run **Health Check** (Should show `200 OK` and active status).

3. **Re-Register Public Key for Production Domain**:
   - Open browser or postman and execute:
     `https://yourdomain.com/api/register-public-key.php`
   - Response will be: `{"success": true, "message": "RSA Public Key registered with Meta Graph API successfully!"}`

---

### Step 5: Hostinger Cron Job Setup (Automated Campaigns & Follow-ups)

1. Open Hostinger **hPanel** -> **Advanced** -> **Cron Jobs**.
2. Add Command:
   ```bash
   /usr/bin/php /home/u123456789/public_html/cron_scheduler.php >/dev/null 2>&1
   ```
3. Set Interval: **Every 5 Minutes** (`*/5 * * * *`).

---

## 🎯 Verification Checklist

- [ ] Uploaded all project files to Hostinger `public_html/`.
- [ ] Imported `schema.sql` into Hostinger phpMyAdmin.
- [ ] Updated `config/config.php` with Live Domain & Hostinger DB password.
- [ ] Created `uploads/` and `logs/` folders with write permissions (`755`).
- [ ] Updated Webhook URL in Meta Dashboard to `https://yourdomain.com/api/webhook.php`.
- [ ] Updated Flow Endpoint URL in Meta Dashboard to `https://yourdomain.com/api/flow-endpoint.php`.
- [ ] Tested sending a WhatsApp message and receiving flow response on live server.
