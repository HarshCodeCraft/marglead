-- Marg ERP CRM & Lead Management System Database Schema
-- Target Database: marg_crm

CREATE DATABASE IF NOT EXISTS marg_crm;
USE marg_crm;

-- 1. Users & Roles Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT 'Active',
    permissions TEXT NULL,
    profile_photo VARCHAR(255) NULL,
    otp_code VARCHAR(10) NULL,
    otp_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Leads Table
CREATE TABLE IF NOT EXISTS leads (
    id VARCHAR(20) PRIMARY KEY, -- E.g. LD-9021
    name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100) NULL,
    company VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL,
    phone VARCHAR(20) NOT NULL,
    secondary_phone VARCHAR(20) NULL,
    city VARCHAR(50) NULL,
    state VARCHAR(50) NULL,
    address TEXT NULL,
    gst VARCHAR(15) NULL,
    source VARCHAR(50) DEFAULT 'Website',
    priority VARCHAR(20) DEFAULT 'warm', -- hot, warm, cold
    tags VARCHAR(255) NULL,
    status VARCHAR(50) DEFAULT 'new', -- matches pipeline stages
    assigned_to VARCHAR(100) NULL, -- refers to user name or id
    assigned_by VARCHAR(100) NULL, -- tracks user name who assigned the lead
    budget DECIMAL(12, 2) DEFAULT 0.00,
    products VARCHAR(100) NULL,
    enq_for VARCHAR(255) NULL,
    remarks TEXT NULL,
    installation_status VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Timeline / Activity Logs
CREATE TABLE IF NOT EXISTS timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id VARCHAR(20) NOT NULL,
    log_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actor VARCHAR(100) NOT NULL,
    action_taken TEXT NOT NULL,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Follow-up Planner Table
CREATE TABLE IF NOT EXISTS followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id VARCHAR(20) NOT NULL,
    action_type VARCHAR(50) NOT NULL, -- call, demo, email, visit
    scheduled_at DATETIME NOT NULL,
    remarks TEXT NULL,
    status VARCHAR(20) DEFAULT 'pending', -- pending, completed, missed
    assigned_to VARCHAR(100) NOT NULL,
    send_email TINYINT DEFAULT 0,
    send_sms TINYINT DEFAULT 0,
    email_sent TINYINT DEFAULT 0,
    sms_sent TINYINT DEFAULT 0,
    sms_targets TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Product Demos Table
CREATE TABLE IF NOT EXISTS demos (
    id VARCHAR(20) PRIMARY KEY, -- E.g. DM-402
    lead_id VARCHAR(20) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    mode VARCHAR(50) DEFAULT 'Online',
    engineer VARCHAR(100) NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled', -- scheduled, completed, cancelled
    rating INT NULL, -- 1 to 5
    feedback TEXT NULL,
    cancel_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Quotations Table
CREATE TABLE IF NOT EXISTS quotations (
    id VARCHAR(20) PRIMARY KEY, -- E.g. QT-9011
    lead_id VARCHAR(20) NOT NULL,
    issue_date DATE NOT NULL,
    valid_until DATE NOT NULL,
    taxable_amount DECIMAL(12, 2) NOT NULL,
    gst_amount DECIMAL(12, 2) NOT NULL,
    grand_total DECIMAL(12, 2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Payments & Invoices Table
CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(20) PRIMARY KEY, -- E.g. INV-4509
    lead_id VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    date_issued DATE NOT NULL,
    due_date DATE NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL,
    paid_amount DECIMAL(12, 2) DEFAULT 0.00,
    balance_amount DECIMAL(12, 2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', -- paid, partial, pending
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Installation Table
CREATE TABLE IF NOT EXISTS installations (
    id VARCHAR(20) PRIMARY KEY, -- E.g. INS-201
    lead_id VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    city VARCHAR(50) NOT NULL,
    engineer VARCHAR(100) NOT NULL,
    scheduled_date DATETIME NOT NULL,
    checklist_done INT DEFAULT 0, -- completed checklist count
    checklist_total INT DEFAULT 5,
    status VARCHAR(20) DEFAULT 'assigned', -- assigned, in_progress, completed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. User Trainings Table
CREATE TABLE IF NOT EXISTS trainings (
    id VARCHAR(20) PRIMARY KEY, -- E.g. TRN-501
    lead_id VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    trainer VARCHAR(100) NOT NULL,
    schedule_date DATETIME NOT NULL,
    logged_hours INT DEFAULT 0,
    total_hours INT DEFAULT 6,
    status VARCHAR(20) DEFAULT 'scheduled', -- scheduled, active, certified
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Support Tickets Table
CREATE TABLE IF NOT EXISTS support_tickets (
    id VARCHAR(20) PRIMARY KEY, -- E.g. TCK-8902
    customer_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    priority VARCHAR(20) NOT NULL, -- critical, high, medium, low
    status VARCHAR(20) DEFAULT 'open', -- open, in_progress, resolved
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_to VARCHAR(100) NOT NULL,
    lead_id VARCHAR(50) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(100) NULL,
    product VARCHAR(255) NULL,
    renewal_date DATE NULL,
    address TEXT NULL,
    problem TEXT NULL,
    due_date DATE NULL,
    callback_number VARCHAR(50) NULL,
    custom_fields TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Client Directory Table (Old Client Details)
CREATE TABLE IF NOT EXISTS client_directory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sno INT NULL,                           -- S.No
    sw_type VARCHAR(50) DEFAULT 'Marg',     -- S/W Type
    customer_id VARCHAR(50) NOT NULL,       -- CUSTOMER ID
    subpartner_code VARCHAR(50) NULL,       -- SubPartner Code
    subpartner_name VARCHAR(150) NULL,      -- SubPartner Name
    party_name VARCHAR(255) NOT NULL,       -- Party Name
    company_using VARCHAR(150) NULL,        -- CompanyUsing
    address TEXT NULL,                      -- Address
    mobile VARCHAR(50) NULL,                -- Mobile
    email VARCHAR(150) NULL,                -- EmailID
    user_type VARCHAR(50) NULL,             -- User (e.g. Multi User / Single User)
    software_type VARCHAR(100) NULL,        -- Type (e.g. Marg ERP Silver)
    no_of_users INT DEFAULT 1,              -- NoOfUser
    contact_person VARCHAR(150) NULL,       -- Contact Person
    due_on DATE NULL,                       -- Due On
    act_on DATE NULL,                       -- Act On
    days INT DEFAULT 0,                     -- Days
    party_status VARCHAR(50) DEFAULT 'Running', -- Party Status
    city VARCHAR(100) NULL,                 -- City
    transferred_party VARCHAR(20) DEFAULT 'No', -- Transferred Party
    online_zip_code VARCHAR(20) NULL,       -- OnlineZipCode
    state VARCHAR(100) NULL,                -- State
    home_user VARCHAR(20) DEFAULT 'No',     -- Home User
    software_trade VARCHAR(150) NULL,       -- Software Trade
    version VARCHAR(50) NULL,               -- Version
    total_amount DECIMAL(12, 2) DEFAULT 0.00, -- Total Amount
    software_hit_date DATE NULL,            -- Software HitDate
    wallet_id VARCHAR(100) NULL,            -- Wallet Id
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_id (customer_id),
    INDEX idx_party_name (party_name),
    INDEX idx_mobile (mobile),
    INDEX idx_party_status (party_status),
    INDEX idx_city_state (city, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Renewals Table
CREATE TABLE IF NOT EXISTS renewals (
    id VARCHAR(20) PRIMARY KEY, -- E.g. RNW-902
    lead_id VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    product_name VARCHAR(100) NOT NULL,
    expiry_date DATE NOT NULL,
    days_remaining INT NOT NULL,
    renewal_fee DECIMAL(12, 2) NOT NULL,
    status VARCHAR(20) DEFAULT 'active', -- active, grace, expired
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role VARCHAR(50) NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL,
    type VARCHAR(20) DEFAULT 'info',
    unread TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Bot Flows Table
CREATE TABLE IF NOT EXISTS bot_flows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flow_id VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) DEFAULT 'SIGN IN',
    status VARCHAR(20) DEFAULT 'PUBLISHED',
    screens_json LONGTEXT NULL,
    raw_nodes_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==========================================================================
-- Initial Seeder Inserts
-- ==========================================================================


-- 1. Insert System Users (Passwords are set to password123 hashed)
INSERT IGNORE INTO users (name, email, password, role, status) VALUES
('Harsh Vardhan', 'admin@marglead.com', '123456', 'Admin', 'Active'),
('Amit Sen', 'amit.sen@marglead.com', '123456', 'Sales Executive', 'Active'),
('Vikas Patel', 'vikas@marglead.com', '123456', 'Installation Engineer', 'Active'),
('Sonal Mehta', 'sonal@marglead.com', '123456', 'Team Leader', 'Active');

-- 2. Insert Lead opportunities
INSERT IGNORE INTO leads (id, name, contact_person, company, email, phone, city, state, address, gst, source, priority, tags, status, assigned_to, budget, products, enq_for, remarks) VALUES
('LD-9021', 'Amit Sharma', 'Amit Sharma', 'Apex Pharma Solutions', 'amit.sharma@apexpharma.com', '+91 98765 43210', 'New Delhi', 'Delhi', 'Phase 2, Okhla Industrial Area', '07AAAAA1111A1Z1', 'Website', 'hot', 'Hot', 'new', 'Amit S.', 450000.00, 'Marg ERP Pro', 'Marg ERP Pro', 'Highly interested in automated billing and barcode features.'),
('LD-7890', 'Dr. Satish Verma', 'Dr. Satish Verma', 'Dr. Verma Diagnostic Clinic', 'drverma@diagnostic.in', '+91 99988 77766', 'Mumbai', 'Maharashtra', 'Clinic sector 4', NULL, 'Cold Calls', 'warm', 'Normal', 'contacted', 'Neha R.', 180000.00, 'Marg ERP Basic', 'Marg ERP Basic', 'Requires diagnostics configurations.'),
('LD-6512', 'Rajesh Gupta', 'Rajesh Gupta', 'Metro Chemicals & Co.', 'rgupta@metrochem.org', '+91 91234 56789', 'Ahmedabad', 'Gujarat', 'Industrial Area Zone 1', '24AAAAA2222B1Z3', 'Google Ads', 'hot', 'Hot', 'quotation_sent', 'Vikram K.', 800000.00, 'Marg ERP Gold', 'Marg ERP Gold', 'Approved price quotes sent.'),
('LD-5431', 'Meera Nair', 'Meera Nair', 'Kochi Retail Logistics', 'meera@nairlogistics.com', '+91 95432 10987', 'Kochi', 'Kerala', 'Marine Drive complex', NULL, 'Referrals', 'cold', 'Cold', 'interested', 'Sonal M.', 250000.00, 'Marg ERP Pro', 'Marg ERP Pro', 'Followup after next board meeting.'),
('LD-4320', 'Sanjay Singhal', 'Sanjay Singhal', 'Singhal Steels Pvt Ltd', 'sanjay@singhalsteel.com', '+91 98877 66554', 'Ludhiana', 'Punjab', 'Steel Compound sector', NULL, 'Exhibitions', 'hot', 'Hold For Payment', 'payment_pending', 'Rahul P.', 1200000.00, 'Marg ERP Gold', 'Marg ERP Gold', 'Awaiting advance payment verification.'),
('LD-3219', 'Arun Joshi', 'Arun Joshi', 'Joshi Medical Hall', 'arun@joshimedical.co.in', '+91 93210 98765', 'Pune', 'Maharashtra', 'Main square, Deccan', NULL, 'Website', 'warm', 'Negotiation', 'install_pending', 'Amit S.', 300000.00, 'Marg ERP Pro', 'Marg ERP Pro', 'Hardware is ready for deployment.');

-- 3. Insert Timeline Logs
INSERT IGNORE INTO timeline (lead_id, actor, action_taken) VALUES
('LD-9021', 'Amit Sen', 'Scheduled client demo for tomorrow 03:00 PM. Sent confirmation details on WhatsApp.'),
('LD-9021', 'System Bot', 'Sent SMS follow up automated alert to client.'),
('LD-9021', 'Admin Manager', 'Lead auto-assigned to sales executive Amit Sen.');

-- 4. Insert Followup items
INSERT IGNORE INTO followups (lead_id, action_type, scheduled_at, remarks, status, assigned_to) VALUES
('LD-9021', 'Product Demo', '2026-07-22 15:00:00', 'Execute Marg ERP Pro features run-through on client system.', 'pending', 'Amit S.'),
('LD-9021', 'Discovery Call', '2026-07-19 11:00:00', 'Gather requirements, confirm GST status and user counts.', 'completed', 'Amit S.');

-- 5. Insert Demos
INSERT IGNORE INTO demos (id, lead_id, scheduled_at, mode, engineer, status, rating, feedback) VALUES
('DM-402', 'LD-9021', '2026-07-22 15:00:00', 'Online (Google Meet)', 'Amit Sen', 'scheduled', NULL, NULL),
('DM-398', 'LD-7890', '2026-07-19 11:00:00', 'On-Site', 'Neha R.', 'completed', 4, 'High interest in pharmacy billing module. Requesting quote.'),
('DM-391', 'LD-5431', '2026-07-18 16:30:00', 'Online', 'Vikram K.', 'cancelled', NULL, 'Client requested reschedule due to internal audit.');

-- 6. Insert Quotation
INSERT IGNORE INTO quotations (id, lead_id, issue_date, valid_until, taxable_amount, gst_amount, grand_total, status, created_by) VALUES
('QT-9011', 'LD-9021', '2026-07-20', '2026-08-20', 375000.00, 67500.00, 442500.00, 'pending', 'Amit Sen'),
('QT-8902', 'LD-7890', '2026-07-19', '2026-08-19', 152542.37, 27457.63, 180000.00, 'approved', 'Neha R.'),
('QT-8891', 'LD-6512', '2026-07-15', '2026-08-15', 677966.10, 122033.90, 800000.00, 'approved', 'Vikram K.');

-- 7. Insert Invoices
INSERT IGNORE INTO invoices (id, lead_id, customer_name, date_issued, due_date, total_amount, paid_amount, balance_amount, status) VALUES
('INV-4509', 'LD-9021', 'Apex Pharma Solutions', '2026-07-20', '2026-07-28', 450000.00, 0.00, 450000.00, 'pending'),
('INV-4482', 'LD-7890', 'Dr. Verma Diagnostic Clinic', '2026-07-19', '2026-07-27', 180000.00, 180000.00, 0.00, 'paid'),
('INV-4391', 'LD-6512', 'Metro Chemicals & Co.', '2026-07-15', '2026-07-25', 800000.00, 400000.00, 400000.00, 'partial');

-- 8. Insert Installations
INSERT IGNORE INTO installations (id, lead_id, customer_name, city, engineer, scheduled_date, checklist_done, status) VALUES
('INS-201', 'LD-9021', 'Apex Pharma Solutions', 'New Delhi', 'Vikas Patel', '2026-07-24 10:00:00', 0, 'assigned'),
('INS-199', 'LD-7890', 'Dr. Verma Diagnostic Clinic', 'Mumbai', 'Praveen Kumar', '2026-07-20 14:00:00', 5, 'completed'),
('INS-194', 'LD-6512', 'Metro Chemicals & Co.', 'Ahmedabad', 'Anil Kumar', '2026-07-22 11:30:00', 3, 'in_progress');

-- 9. Insert Trainings
INSERT IGNORE INTO trainings (id, lead_id, customer_name, trainer, schedule_date, logged_hours, status) VALUES
('TRN-501', 'LD-9021', 'Apex Pharma Solutions', 'Prakash Raj', '2026-07-25 11:00:00', 0, 'scheduled'),
('TRN-492', 'LD-7890', 'Dr. Verma Diagnostic Clinic', 'Sonal Mehta', '2026-07-21 16:00:00', 6, 'certified'),
('TRN-487', 'LD-6512', 'Metro Chemicals & Co.', 'Prakash Raj', '2026-07-22 09:30:00', 3, 'active');

-- 10. Insert Support Tickets
INSERT IGNORE INTO support_tickets (id, customer_name, subject, priority, status, assigned_to) VALUES
('TCK-8902', 'Dr. Satish Verma Clinic', 'Printer configuration issues with receipt bills', 'high', 'open', 'Rahul P.'),
('TCK-8789', 'Metro Chemicals & Co.', 'GST return filing API mismatch error code 400', 'critical', 'in_progress', 'Amit S.');

-- 11. Insert Sample Data into Client Directory (Old Clients)
INSERT IGNORE INTO client_directory (
    sno, sw_type, customer_id, subpartner_code, subpartner_name, party_name, company_using, 
    address, mobile, email, user_type, software_type, no_of_users, contact_person, 
    due_on, act_on, days, party_status, city, transferred_party, online_zip_code, 
    state, home_user, software_trade, version, total_amount, software_hit_date, wallet_id
) VALUES (
    1, 
    'Marg', 
    '1352947', 
    NULL, 
    NULL, 
    'GANTAVYA PHARMACY', 
    '4', 
    'SIS HOSPITAL 3 COM 1/9 AMBEDKAR PURAM AWAS VIKAS NO.3, KALYANPUR, KANPUR NAGAR-208017 UTTAR PRADESH, INDIA', 
    '9340000000', 
    'sishospitalniramay@gmail.com', 
    'Multi User', 
    'Marg ERP Silver', 
    2, 
    'Mr. RAJESH', 
    NULL, 
    NULL, 
    -559, 
    'Running', 
    'Kanpur', 
    'No', 
    '208017', 
    'Uttar Pradesh', 
    'No', 
    'Pharmaceutical & Chemicals', 
    NULL, 
    4661.00, 
    NULL, 
    NULL
);

-- 12. Insert Renewals
INSERT IGNORE INTO renewals (id, lead_id, customer_name, product_name, expiry_date, days_remaining, renewal_fee, status) VALUES
('RNW-902', 'LD-9021', 'Apex Pharma Solutions', 'Marg ERP Pro License', '2026-08-15', 25, 45000.00, 'active'),
('RNW-889', 'LD-7890', 'Dr. Verma Diagnostic Clinic', 'Marg ERP Basic Suite', '2026-07-10', -11, 18000.00, 'expired'),
('RNW-851', 'LD-6512', 'Metro Chemicals & Co.', 'Marg ERP Gold Enterprise', '2026-07-28', 7, 80000.00, 'grace');

-- 13. Insert Notifications
INSERT IGNORE INTO notifications (id, user_id, role, title, message, type, unread, created_at) VALUES
(NULL, NULL, NULL, 'Test', 'Test', 'info', 1, CURRENT_TIMESTAMP);

-- 14. Insert Bot Flows (Matching Screenshot)
INSERT IGNORE INTO bot_flows (id, flow_id, name, category, status, screens_json) VALUES
(1, '2356038494923110', 'Ticket', 'SIGN IN', 'PUBLISHED', '[{"id":"screen_1","name":"Welcome to Marg Soft","title":"Welcome to Marg Soft","body":"Please Provide Your Info and Problem Here..","components":[{"id":"c1","type":"Short Answer","label":"License Number","helper":"Client Id","required":true},{"id":"c2","type":"Dropdown","label":"Bill Format Issue","helper":"","options":["Bill Format Issue","GST Error","Printer Setup"],"required":false},{"id":"c3","type":"Text Area","label":"Problem","helper":"Describe issue","required":true},{"id":"c4","type":"Short Answer","label":"Call Back Number","helper":"Call Back Number","required":true}],"footer_label":"Submit","footer_action":"Complete"}]'),
(2, '36230192503294106', 'Service', 'SIGN IN', 'PUBLISHED', '[{"id":"screen_1","name":"Service Enquiry","title":"Marg Service Center","body":"How can we assist your business today?","components":[{"id":"c1","type":"Short Answer","label":"Customer ID","helper":"Enter Marg ID","required":true},{"id":"c2","type":"Short Answer","label":"Service Required","helper":"AMC, Training, Barcode","required":true}],"footer_label":"Submit Request","footer_action":"Complete"}]'),
(3, '1303139711243346', 'Bot', 'SIGN IN', 'PUBLISHED', '[{"id":"screen_1","name":"Automated Bot Welcome","title":"Marg AI Assistant","body":"Select a topic to get immediate assistance.","components":[{"id":"c1","type":"Short Answer","label":"Query Summary","helper":"Type your query","required":true}],"footer_label":"Send","footer_action":"Complete"}]');

-- 15. Multi-Tenant SaaS SaaS Clients Table
CREATE TABLE IF NOT EXISTS tenant_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    company_code VARCHAR(100) NOT NULL UNIQUE,
    owner_name VARCHAR(255) NOT NULL,
    owner_email VARCHAR(255) NOT NULL UNIQUE,
    phone VARCHAR(50) DEFAULT NULL,
    db_name VARCHAR(100) NOT NULL,
    plan ENUM('Basic', 'Silver', 'Gold', 'Enterprise') DEFAULT 'Silver',
    status ENUM('Active', 'Suspended', 'Trial', 'Expired') DEFAULT 'Active',
    expiry_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (company_code),
    INDEX (status)
);

-- Seed Default Master Tenant (Marg Soft Solutions Owner CRM)
INSERT INTO tenant_companies (id, company_name, company_code, owner_name, owner_email, phone, db_name, plan, status, expiry_date) VALUES
(1, 'Marg Soft Solutions (Primary)', 'master', 'DEEPAK AWASTHI', 'admin@marglead.com', '+91 98765 43210', 'marg_crm', 'Enterprise', 'Active', '2030-12-31')
ON DUPLICATE KEY UPDATE company_name = VALUES(company_name);


