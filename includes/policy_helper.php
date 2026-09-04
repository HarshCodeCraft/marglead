<?php
/**
 * Policy & Legal Compliance Helper - Friendly AI Solution
 * Manages dynamic policy points for Privacy Policy, Terms & Conditions (with Continuation Policy),
 * and Refund & Cancellation Policy.
 * Supports dynamic Super Admin addition, editing, and sorting.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/includes/config.php';
require_once BASE_PATH . '/includes/db.php';

/**
 * Ensures policy_points table exists and is populated with default seed data
 */
function init_policy_points_table() {
    global $pdo, $db_connected;
    if (!$db_connected || !$pdo) {
        return false;
    }

    try {
        $table_sql = "CREATE TABLE IF NOT EXISTS policy_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_type VARCHAR(30) NOT NULL, -- 'privacy', 'terms', 'refund'
            section_number INT NOT NULL DEFAULT 1,
            section_title VARCHAR(255) NOT NULL,
            section_badge VARCHAR(100) NULL,
            icon VARCHAR(50) DEFAULT 'shield-check',
            content LONGTEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_policy_lookup (page_type, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $pdo->exec($table_sql);

        // Check if table is empty, if so seed defaults
        $checkStmt = $pdo->query("SELECT COUNT(*) FROM policy_points");
        $count = (int)$checkStmt->fetchColumn();
        if ($count === 0) {
            seed_default_policy_points();
        }
        return true;
    } catch (PDOException $e) {
        error_log("Policy points table initialization error: " . $e->getMessage());
        return false;
    }
}

/**
 * Returns the comprehensive system default points for all 3 policies
 */
function get_default_policy_data($type = null) {
    $defaults = [
        'terms' => [
            [
                'section_number' => 1,
                'section_title' => 'Acceptance of Terms & Digital Agreement',
                'section_badge' => 'Legal Enforceability',
                'icon' => 'file-check-2',
                'content' => "<p>By accessing, subscribing to, or utilizing <strong>Friendly AI Solution</strong> (\"the Platform\"), our Marg ERP 9+ Desktop Bridge connector, and associated WhatsApp Cloud API automations, you (\"the Client\", \"Merchant\", or \"Authorized User\") acknowledge and agree to be bound by these Terms and Conditions.</p>
<p>These terms constitute a legally binding electronic contract under the provisions of the <em>Indian Information Technology Act, 2000</em>. If you are accepting these terms on behalf of a partnership, private limited company, LLP, or enterprise, you represent and warrant that you hold express corporate authority to bind that legal entity.</p>"
            ],
            [
                'section_number' => 2,
                'section_title' => 'Service Scope & Marg ERP 9+ Direct Connectivity',
                'section_badge' => 'ERP & Cloud Sync',
                'icon' => 'layers',
                'content' => "<p>Friendly AI Solution provides an enterprise integration middleware that bridges on-premise Marg ERP 9+, Marg Books, and Cloud VPC servers directly with Meta's official WhatsApp Business Platform. The platform enables automated GST billing notifications, live outstanding ledger tracking, automated payment collection reminders, sales CRM pipelines, and multi-agent customer support inboxes.</p>
<ul>
    <li>The client is responsible for maintaining an active Marg ERP 9+ license and ensuring their local server or desktop meets minimum technical requirements (Windows 10/11 or Windows Server, active internet connection, Python 3.10+ / PowerShell runtime for local sync).</li>
    <li>Friendly AI Solution is an independent certified technology partner and automation provider. Marg ERP is a registered trademark of Marg Compusoft Pvt. Ltd.</li>
</ul>"
            ],
            [
                'section_number' => 3,
                'section_title' => 'Account Registration, KYC & Multi-Tenant Boundaries',
                'section_badge' => 'Identity & Governance',
                'icon' => 'user-check',
                'content' => "<p>To operate the platform, organizations must complete administrative registration and submit mandatory KYC verification including valid GSTIN, Trade Name, Director / Authorized Signatory identification, and official mobile contact details.</p>
<ul>
    <li>Accounts remain in an initial <code>Pending Verification</code> state until compliance review is completed by our operational team.</li>
    <li>Our platform operates on a strict multi-tenant architectural boundary: each client organization is provisioned with isolated data structures, role permissions, and token authorization. You are solely responsible for all actions conducted under your company's sub-user accounts.</li>
</ul>"
            ],
            [
                'section_number' => 4,
                'section_title' => 'Meta WhatsApp Cloud API Compliance & Continuation Policy',
                'section_badge' => 'Continuation Policy',
                'icon' => 'message-square',
                'content' => "<p>All WhatsApp communications routed through Friendly AI Solution strictly comply with <strong>Meta's WhatsApp Business Messaging Policy</strong> and Meta Developer Terms:</p>
<ul>
    <li><strong>Active Opt-In Mandate:</strong> Merchants must obtain verifiable consent (opt-in) from their customers, distributors, or patients before transmitting automated transaction bills or notifications via WhatsApp.</li>
    <li><strong>24-Hour Customer Care Window:</strong> Incoming user queries initiate a 24-hour service window during which free-form responses can be dispatched. Beyond 24 hours, only pre-approved Meta utility or marketing templates may be used.</li>
    <li><strong>Messaging Continuation Policy:</strong> To ensure uninterrupted delivery of critical accounting bills, clients must maintain sufficient conversation credit balances and an active WhatsApp Cloud API token. In the event of token expiration or quality rating downgrades by Meta (Low Quality / Flagged), our engine attempts intelligent failover and alerts the merchant immediately. Continued abuse of unsolicited broadcast limits will trigger automatic gateway suspension to prevent official number banning.</li>
</ul>"
            ],
            [
                'section_number' => 5,
                'section_title' => 'Anti-Spam Regulations & Messaging Fair-Use Rules',
                'section_badge' => 'Zero Spam Tolerance',
                'icon' => 'shield-alert',
                'content' => "<p>Friendly AI Solution strictly enforces a zero-tolerance policy against unsolicited bulk commercial spam, fraudulent schemes, unauthorized lottery notifications, or deceptive marketing practices.</p>
<ul>
    <li>Broadcast lists must only include contacts who have a direct, verifiable commercial relationship with the merchant.</li>
    <li>Clients must honor user opt-out requests (such as replies containing \"STOP\" or \"UNSUBSCRIBE\") instantly. Our bot flow engine automatically tags unsubscribed numbers to halt further automated dispatches.</li>
    <li>Accounts found violating Telecom Regulatory Authority of India (TRAI) DLT standards or Meta Messaging Policies will face immediate service restriction without refund eligibility.</li>
</ul>"
            ],
            [
                'section_number' => 6,
                'section_title' => 'Subscription Billing, ARC Cycle & Auto-Renewal Continuation',
                'section_badge' => 'Billing & ARC Policy',
                'icon' => 'credit-card',
                'content' => "<p>Subscriptions are billed in advance on an Annual, Bi-Annual, or Monthly billing cadence depending on the selected plan (Nano, Basic, Silver, Gold, or Cloud VPC Enterprise).</p>
<ul>
    <li><strong>Annual Resource Charges (ARC):</strong> Renewal fees cover ongoing Meta Cloud API server maintenance, automated daily cloud backups, security patches, and live technical helpdesk support.</li>
    <li><strong>Subscription Continuation Policy:</strong> Renewal invoices are automatically generated and sent to the merchant's registered WhatsApp and email 30 days prior to subscription expiry. A 7-day grace period is provided following expiry during which core billing sync continues. If renewal is not settled after the grace period, automated WhatsApp dispatches will pause while offline Marg ERP billing functions remain intact.</li>
    <li>All quoted pricing excludes statutory Goods & Services Tax (GST 18%), which will be billed additionally with full tax invoice credit.</li>
</ul>"
            ],
            [
                'section_number' => 7,
                'section_title' => 'Platform Availability, 99.9% Uptime SLA & Maintenance',
                'section_badge' => 'Service Level Agreement',
                'icon' => 'activity',
                'content' => "<p>We strive to provide a <strong>99.9% Service Availability SLA</strong> across our SaaS infrastructure, cloud webhook receivers, and Marg desktop bridge synchronization relays.</p>
<ul>
    <li><strong>Scheduled Maintenance:</strong> Core infrastructure upgrades and database optimizations are scheduled during non-peak business hours (typically 01:00 AM to 04:00 AM IST) with advance notice posted on the system status banner.</li>
    <li><strong>Emergency Outages:</strong> Friendly AI Solution is not liable for disruptions caused by force majeure events, telecom carrier routing failures, or unscheduled global Meta WhatsApp API outages outside of our reasonable control.</li>
</ul>"
            ],
            [
                'section_number' => 8,
                'section_title' => 'Intellectual Property Rights & SaaS License Grant',
                'section_badge' => 'IP & Proprietary Rights',
                'icon' => 'award',
                'content' => "<p>Friendly AI Solution grants you a non-exclusive, non-transferable, revocable license to access and use our SaaS web dashboard, local bridge scripts, and bot flow builder strictly for your internal business operations.</p>
<ul>
    <li>All proprietary software, code, algorithms, graphic assets, database schemas, and documentation remain the exclusive intellectual property of Friendly AI Solution.</li>
    <li>Reverse engineering, decompiling, sublicensing, white-labeling without authorization, or reselling our API endpoints is strictly prohibited and subject to legal prosecution.</li>
</ul>"
            ],
            [
                'section_number' => 9,
                'section_title' => 'Tenant Data Ownership & Unrestricted Data Portability',
                'section_badge' => 'Data Ownership',
                'icon' => 'database',
                'content' => "<p>You retain 100% full and unconditional ownership of all business records, inventory catalogs, customer directories, financial transactions, and lead data ingested into the system.</p>
<ul>
    <li>Friendly AI Solution claims zero ownership over merchant commercial data and acts strictly as a secure data processor.</li>
    <li>Authorized organization administrators can export complete customer, sales, quotation, and payment histories in standardized CSV or Microsoft Excel formats at any time without administrative lock-in.</li>
</ul>"
            ],
            [
                'section_number' => 10,
                'section_title' => 'Service Suspension, Continuation Termination & Data Archival',
                'section_badge' => 'Termination Policy',
                'icon' => 'power',
                'content' => "<p>Either party may terminate the service agreement by providing written notice 15 days prior to the conclusion of the active subscription cycle.</p>
<ul>
    <li>Upon termination, your account transitions into read-only archive mode for 30 calendar days, allowing administrators sufficient opportunity to download all historical ledgers, message reports, and customer KYC records.</li>
    <li>Following the 30-day post-termination period, tenant databases are cryptographically sanitized and purged from active production servers in accordance with enterprise data disposal standards.</li>
</ul>"
            ],
            [
                'section_number' => 11,
                'section_title' => 'Limitation of Liability & Indemnification',
                'section_badge' => 'Legal Safeguards',
                'icon' => 'alert-triangle',
                'content' => "<p>To the maximum extent permitted by applicable law, Friendly AI Solution and its directors, officers, and developers shall not be liable for any indirect, incidental, consequential, or punitive damages, including loss of business profits, goodwill, or market data arising out of or in connection with the use or inability to use the service.</p>
<p>Our aggregate monetary liability under any claim arising out of these terms shall not exceed the subscription fees actually paid by the client to Friendly AI Solution during the three (3) months preceding the incident giving rise to liability.</p>"
            ],
            [
                'section_number' => 12,
                'section_title' => 'Governing Law, Indian Jurisdiction & Grievance Redressal',
                'section_badge' => 'Jurisdiction',
                'icon' => 'scale',
                'content' => "<p>These Terms and Conditions shall be governed by and construed in accordance with the substantive laws of the Republic of India. Any legal dispute, controversy, or claim arising under or relating to these terms shall be subject to the exclusive jurisdiction of the competent civil courts located in <strong>Kanpur Nagar, Uttar Pradesh, India</strong>.</p>
<p>Prior to formal legal proceedings, both parties commit to attempting good-faith resolution through mutual discussions or fast-track arbitration under the <em>Arbitration and Conciliation Act, 1996</em>.</p>"
            ]
        ],
        'privacy' => [
            [
                'section_number' => 1,
                'section_title' => 'Information We Collect & ERP Sync Metadata',
                'section_badge' => 'Data Collection',
                'icon' => 'database',
                'content' => "<p>Friendly AI Solution collects only the information strictly necessary to provide seamless Marg ERP integration, cloud synchronization, and automated communication services:</p>
<ul>
    <li><strong>Merchant Account Details:</strong> Organization legal trade name, GSTIN, registered address, authorized contact person, official email address, and billing phone number.</li>
    <li><strong>Marg ERP Synchronization Data:</strong> Customer ledger names, phone numbers, invoice numbers, billing totals, GST breakup, due dates, outstanding balances, and quotation details extracted securely via local bridge queries.</li>
    <li><strong>WhatsApp Communication Logs:</strong> Message dispatch timestamps, delivery receipts (sent, delivered, read), template identifiers, and customer chat replies routed through webhooks.</li>
    <li><strong>Technical & Session Telemetry:</strong> User IP addresses, login timestamps, browser user-agent, operating system details, and system error diagnostic traces.</li>
</ul>"
            ],
            [
                'section_number' => 2,
                'section_title' => 'How We Process & Utilize Collected Information',
                'section_badge' => 'Processing Purpose',
                'icon' => 'cpu',
                'content' => "<p>Your data is processed strictly for authentic business operations and service fulfillment, specifically:</p>
<ul>
    <li>Dispatching automated GST invoice PDFs and payment reminders with dynamic UPI QR links directly to your retail and wholesale buyers on WhatsApp.</li>
    <li>Maintaining your unified Sales CRM, lead pipeline, follow-up planners, and support ticket desk.</li>
    <li>Monitoring synchronization health between your local Marg ERP 9+ accounting database and cloud dashboard.</li>
    <li>Providing prioritized customer support, bug fixes, and security audit verification.</li>
</ul>
<p><strong>We do NOT sell, lease, monetize, or trade your company data, client phone lists, or financial records to any third-party advertisers, data aggregators, or marketing firms under any circumstances.</strong></p>"
            ],
            [
                'section_number' => 3,
                'section_title' => 'Meta WhatsApp Cloud API & Messaging Data Governance',
                'section_badge' => 'Meta Compliance',
                'icon' => 'shield-check',
                'content' => "<p>Our platform integrates with Meta Platforms Inc. via official Graph API endpoints under verified Meta Tech Provider status:</p>
<ul>
    <li><strong>Transport Security:</strong> All WhatsApp message payloads travel over encrypted TLS 1.3 tunnels directly to Meta's verified Cloud API servers located in high-security data centers.</li>
    <li><strong>Customer Consent & Opt-In:</strong> Merchants must obtain explicit customer consent before sending automated notifications. Recipients can opt out at any time by texting \"STOP\", which immediately suspends automated outbound flows.</li>
    <li><strong>Strict Isolation:</strong> Chat transcripts and customer phone numbers are isolated within your dedicated tenant workspace and are never combined with other merchants' datasets.</li>
</ul>"
            ],
            [
                'section_number' => 4,
                'section_title' => 'Cryptographic Data Security & Role-Based Access Control',
                'section_badge' => '256-Bit Encryption',
                'icon' => 'lock',
                'content' => "<p>We implement defense-in-depth security measures to protect your proprietary business records against unauthorized access, loss, or disclosure:</p>
<ul>
    <li><strong>Encryption at Rest & Transit:</strong> Critical credentials, database connections, and API tokens are encrypted using industry-standard 256-bit AES algorithms. All web traffic requires modern HTTPS with automated SSL certificate renewal.</li>
    <li><strong>Role-Based Access Control (RBAC):</strong> Within your CRM workspace, fine-grained permission controls prevent unauthorized staff members from accessing financial ledgers, exporting customer directories, or reconfiguring WhatsApp settings.</li>
    <li><strong>SQL Injection & CSRF Defense:</strong> All database queries are executed via PDO prepared statements with strict parameter binding. State-changing requests require unique cryptographic anti-CSRF tokens.</li>
</ul>"
            ],
            [
                'section_number' => 5,
                'section_title' => 'Third-Party Service Providers & Cloud Infrastructure',
                'section_badge' => 'Infrastructure',
                'icon' => 'server',
                'content' => "<p>To deliver high-speed cloud operations, Friendly AI Solution collaborates with trusted, enterprise-grade infrastructure providers:</p>
<ul>
    <li><strong>Cloud VPC Hosting:</strong> Tier-4 data centers with automated NVMe SSD mirroring, daily offsite snapshots, DDoS protection, and 99.9% guaranteed network uptime.</li>
    <li><strong>Payment Gateways:</strong> RBI-licensed payment aggregators (e.g., Razorpay, Cashfree, PayU) for PCI-DSS compliant subscription billing. We do not store credit/debit card CVV numbers or banking PINs on our servers.</li>
    <li><strong>Meta WhatsApp Cloud API:</strong> Official Meta Platforms endpoints for enterprise messaging infrastructure.</li>
</ul>"
            ],
            [
                'section_number' => 6,
                'section_title' => 'Cookie Policy & Session Management',
                'section_badge' => 'Cookie Notice',
                'icon' => 'cookie',
                'content' => "<p>Our web applications use strictly essential HTTP cookies and secure session tokens necessary for user authentication, CSRF validation, and interface preference retention (such as theme and active table filters).</p>
<p>We do not deploy intrusive third-party behavioral tracking cookies, cross-site profiling beacons, or targeted advertising analytics on our CRM workspaces.</p>"
            ],
            [
                'section_number' => 7,
                'section_title' => 'Data Retention & The Right to Erasure / Export',
                'section_badge' => 'Data Rights',
                'icon' => 'trash-2',
                'content' => "<p>You maintain comprehensive control over your personal and organization data in full alignment with the <em>Digital Personal Data Protection (DPDP) Act, 2023</em>:</p>
<ul>
    <li><strong>Right to Access & Portability:</strong> You may inspect and export all leads, customer contacts, message histories, and payment logs in CSV/Excel format at any time.</li>
    <li><strong>Right to Rectification:</strong> Contact information, phone numbers, and profile details can be updated directly from the CRM settings panel.</li>
    <li><strong>Right to Erasure (\"Right to be Forgotten\"):</strong> Upon subscription termination or upon verified written request, all client-specific database tables and backup archives will be permanently sanitized and purged within 30 days.</li>
</ul>"
            ],
            [
                'section_number' => 8,
                'section_title' => 'Compliance with Indian DPDP Act 2023 & International Standards',
                'section_badge' => 'Regulatory Compliance',
                'icon' => 'check-circle-2',
                'content' => "<p>Friendly AI Solution is engineered to adhere strictly to the guidelines set forth under India's <strong>Digital Personal Data Protection Act, 2023 (DPDP Act)</strong> as well as international best practices for data processing (GDPR principles for data minimization and purpose limitation).</p>
<p>We process personal data solely on lawful grounds, implement reasonable security safeguards, and uphold strict protocols for detecting and reporting any unauthorized data access within statutory reporting timeframes.</p>"
            ],
            [
                'section_number' => 9,
                'section_title' => 'Grievance Officer & Data Compliance Contact Details',
                'section_badge' => 'Grievance Desk',
                'icon' => 'mail',
                'content' => "<p>In accordance with the Information Technology Act, 2000 and the DPDP Act 2023, if you have any questions, concerns, or grievances regarding our privacy practices or data processing, you may directly contact our designated Data Protection & Compliance Officer:</p>
<div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-top: 0.75rem;\">
    <p style=\"margin-bottom: 0.25rem;\"><strong>Designated Grievance Officer:</strong> Deepak Awasthi</p>
    <p style=\"margin-bottom: 0.25rem;\"><strong>Company:</strong> Friendly AI Solution</p>
    <p style=\"margin-bottom: 0.25rem;\"><strong>Official Email:</strong> <a href=\"mailto:privacy@friendlyaisolution.com\" style=\"color: #2563eb; font-weight: 600;\">privacy@friendlyaisolution.com</a></p>
    <p style=\"margin-bottom: 0.25rem;\"><strong>Compliance Hotline:</strong> +91 91708 97089 (Mon - Sat, 10:00 AM - 06:30 PM IST)</p>
    <p style=\"margin-bottom: 0;\"><strong>Corporate Address:</strong> Kanpur Nagar, Uttar Pradesh - 208001, India</p>
</div>"
            ]
        ],
        'refund' => [
            [
                'section_number' => 1,
                'section_title' => 'Subscription Cancellation Policy & Advance Notice',
                'section_badge' => 'Cancellation Terms',
                'icon' => 'calendar-x',
                'content' => "<p>At Friendly AI Solution, we are dedicated to providing enterprise-grade ERP automation. However, if you decide to cancel your subscription, you may do so at any time by contacting our support team or raising a ticket from your CRM dashboard.</p>
<ul>
    <li><strong>Monthly Subscriptions:</strong> Cancellation requests must be submitted at least 7 calendar days prior to the next scheduled monthly billing date.</li>
    <li><strong>Annual & Multi-Year Subscriptions:</strong> Cancellation requests must be submitted at least 15 calendar days prior to the annual renewal date to prevent automated renewal invoicing.</li>
    <li>Following cancellation, your service remains fully operational through the end of your prepaid billing period, with zero cancellation penalty fees.</li>
</ul>"
            ],
            [
                'section_number' => 2,
                'section_title' => '7-Day Evaluation Money-Back Guarantee',
                'section_badge' => '7-Day Guarantee',
                'icon' => 'badge-percent',
                'content' => "<p>We offer a transparent <strong>7-Day Money-Back Guarantee</strong> for all first-time new subscribers of our standard cloud software plans (Nano, Basic, Silver, and Gold).</p>
<ul>
    <li>If within 7 calendar days of your initial software activation date you determine that our cloud platform does not meet your technical expectations, you may request a 100% refund of the software subscription fee.</li>
    <li>To qualify, our technical support team will conduct a brief verification session to ensure the issue cannot be resolved via configuration assistance.</li>
    <li>The 7-day money-back guarantee applies only to initial subscriptions and does not apply to subsequent annual ARC renewals or plan upgrades.</li>
</ul>"
            ],
            [
                'section_number' => 3,
                'section_title' => 'Non-Refundable Products, On-Site Services & Meta Credits',
                'section_badge' => 'Exceptions',
                'icon' => 'alert-circle',
                'content' => "<p>The following products, direct pass-through costs, and customized services are strictly non-refundable:</p>
<ul>
    <li><strong>Consumed WhatsApp Cloud API Credits:</strong> Direct utility or marketing conversation fees billed by Meta Platforms Inc. that have already been dispatched.</li>
    <li><strong>On-Site Engineering Deployment & Training:</strong> Physical visits by our technical engineers for hardware networking, Marg ERP LAN setup, or on-premise staff training sessions once executed.</li>
    <li><strong>Official Marg Compusoft Third-Party Licenses:</strong> Official Marg ERP 9+ serial license keys, silver-to-gold edition upgrades, or barcode module licenses once registered with Marg Compusoft Pvt. Ltd.</li>
    <li><strong>Custom Software & API Development:</strong> Dedicated bespoke connector development or customized bot flow scripting tailored exclusively for a client's private workflow.</li>
</ul>"
            ],
            [
                'section_number' => 4,
                'section_title' => 'Cloud NVMe VPC & Dedicated Server Allocation Terms',
                'section_badge' => 'Cloud Server Terms',
                'icon' => 'server',
                'content' => "<p>For clients subscribing to Marg Cloud NVMe Virtual Private Cloud (VPC) hosting, cloud server instances are dedicatedly provisioned with allocated CPU cores, RAM, and isolated static IP addresses.</p>
<ul>
    <li>Cancellation of Cloud VPC plans within the first 7 days is eligible for a refund minus the standard one-time cloud server provisioning and initial setup charge (₹1,500 + GST).</li>
    <li>After 7 days, Cloud VPC hosting fees are non-refundable for the active billing cycle due to upstream data center infrastructure commitments.</li>
</ul>"
            ],
            [
                'section_number' => 5,
                'section_title' => 'Refund Request Submission & Verification Workflow',
                'section_badge' => 'Step-by-Step Flow',
                'icon' => 'workflow',
                'content' => "<p>To submit a refund request, clients must follow our structured, transparent verification process:</p>
<ol>
    <li><strong>Raise Refund Ticket:</strong> Send an email from your registered merchant email address to <a href=\"mailto:billing@friendlyaisolution.com\" style=\"color: #2563eb; font-weight: 600;\">billing@friendlyaisolution.com</a> with the subject line <em>\"Refund Request - [Your Company Name] - [Invoice Number]\"</em>.</li>
    <li><strong>Include Proof of Purchase:</strong> Attach your original GST tax invoice and payment transaction receipt (UPI UTR / Bank Reference Number).</li>
    <li><strong>Audit Verification:</strong> Our billing audit desk reviews the request within 24 to 48 business hours to verify eligibility under these published terms.</li>
    <li><strong>Approval & Settlement:</strong> Upon approval, written confirmation with refund transaction details will be dispatched immediately via email and WhatsApp.</li>
</ol>"
            ],
            [
                'section_number' => 6,
                'section_title' => 'Processing Timeline & Settlement directly to Source Account',
                'section_badge' => '5-7 Days Settlement',
                'icon' => 'banknote',
                'content' => "<p>All approved refunds are credited directly to the original payment source utilized at the time of purchase (Original Bank Account, UPI ID, or Credit/Debit Card):</p>
<ul>
    <li><strong>Settlement Timeline:</strong> 5 to 7 banking business days from the date of formal approval (processing speed varies based on your financial institution's clearing cycle).</li>
    <li>We do not issue physical cash refunds or third-party transfer settlements under any circumstances.</li>
    <li>Statutory GST (18%) previously remitted to the government will be adjusted via standard GST credit notes in accordance with Central GST Act provisions.</li>
</ul>"
            ],
            [
                'section_number' => 7,
                'section_title' => 'Platform Outage Credits & SLA Compensation',
                'section_badge' => 'SLA Compensation',
                'icon' => 'shield-check',
                'content' => "<p>In the unlikely event that Friendly AI Solution's core SaaS platform fails to meet our published 99.9% uptime SLA over a full billing month due to unannounced server failures, affected merchants are entitled to service extension credits:</p>
<ul>
    <li><strong>Outage Exceeding 1.5%:</strong> Complimentary 7-day subscription extension credited to the active term.</li>
    <li><strong>Outage Exceeding 5.0%:</strong> Complimentary 30-day subscription extension or equivalent billing credit applied toward subsequent ARC renewal.</li>
    <li>Outage claims must be logged within 14 days of the incident and exclude external Meta WhatsApp global downtime or client local internet failures.</li>
</ul>"
            ],
            [
                'section_number' => 8,
                'section_title' => 'Chargeback Resolution & Billing Helpdesk Contact',
                'section_badge' => 'Direct Support',
                'icon' => 'headset',
                'content' => "<p>We strongly encourage clients to reach out directly to our dedicated billing resolution desk before initiating a bank chargeback or dispute. Our team responds within 24 business hours to ensure fair, friendly, and rapid resolution.</p>
<div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-top: 0.75rem;\">
    <p style=\"margin-bottom: 0.25rem;\"><strong>Billing & Accounts Desk:</strong> Friendly AI Solution</p>
    <p style=\"margin-bottom: 0.25rem;\"><strong>Direct Billing Email:</strong> <a href=\"mailto:billing@friendlyaisolution.com\" style=\"color: #2563eb; font-weight: 600;\">billing@friendlyaisolution.com</a></p>
    <p style=\"margin-bottom: 0.25rem;\"><strong>Accounts Phone Hotline:</strong> +91 91708 97089</p>
    <p style=\"margin-bottom: 0;\"><strong>Support Hours:</strong> Monday through Saturday • 09:30 AM to 07:00 PM IST</p>
</div>"
            ]
        ]
    ];

    if ($type !== null) {
        return $defaults[$type] ?? [];
    }
    return $defaults;
}

/**
 * Seeds the policy_points table with default points if empty
 */
function seed_default_policy_points($force = false) {
    global $pdo, $db_connected;
    if (!$db_connected || !$pdo) {
        return false;
    }

    try {
        if ($force) {
            $pdo->exec("TRUNCATE TABLE policy_points");
        }

        $all_defaults = get_default_policy_data();
        $stmt = $pdo->prepare("INSERT INTO policy_points 
            (page_type, section_number, section_title, section_badge, icon, content, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)");

        foreach ($all_defaults as $page_type => $points) {
            foreach ($points as $idx => $pt) {
                $sort_order = ($idx + 1) * 10;
                $stmt->execute([
                    $page_type,
                    $pt['section_number'],
                    $pt['section_title'],
                    $pt['section_badge'],
                    $pt['icon'],
                    $pt['content'],
                    $sort_order
                ]);
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log("Failed to seed default policy points: " . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves policy points for a specific page type.
 * Falls back to system defaults if DB is unavailable.
 */
function get_policy_points($page_type, $active_only = true) {
    global $pdo, $db_connected;

    // Normalize page type
    if (in_array($page_type, ['terms', 'terms_conditions', 'terms_of_service'])) {
        $page_type = 'terms';
    } elseif (in_array($page_type, ['privacy', 'privacy_policy'])) {
        $page_type = 'privacy';
    } elseif (in_array($page_type, ['refund', 'refund_policy'])) {
        $page_type = 'refund';
    }

    if ($db_connected && $pdo) {
        try {
            init_policy_points_table();
            $sql = "SELECT * FROM policy_points WHERE page_type = ?";
            if ($active_only) {
                $sql .= " AND is_active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, section_number ASC, id ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$page_type]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($results)) {
                return $results;
            }
        } catch (PDOException $e) {
            error_log("Error fetching policy points for $page_type: " . $e->getMessage());
        }
    }

    // Fallback to built-in defaults if table is empty or error
    $defaults = get_default_policy_data($page_type);
    $mockList = [];
    foreach ($defaults as $i => $d) {
        $mockList[] = [
            'id' => $i + 1,
            'page_type' => $page_type,
            'section_number' => $d['section_number'],
            'section_title' => $d['section_title'],
            'section_badge' => $d['section_badge'],
            'icon' => $d['icon'],
            'content' => $d['content'],
            'sort_order' => ($i + 1) * 10,
            'is_active' => 1
        ];
    }
    return $mockList;
}

/**
 * Retrieves a single point by its ID
 */
function get_policy_point_by_id($id) {
    global $pdo, $db_connected;
    if (!$db_connected || !$pdo) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM policy_points WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Saves (inserts or updates) a policy point
 */
function save_policy_point($data) {
    global $pdo, $db_connected;
    if (!$db_connected || !$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable'];
    }

    init_policy_points_table();

    $id = !empty($data['id']) ? (int)$data['id'] : 0;
    $page_type = trim($data['page_type'] ?? 'terms');
    if (!in_array($page_type, ['privacy', 'terms', 'refund'])) {
        $page_type = 'terms';
    }
    $section_number = !empty($data['section_number']) ? (int)$data['section_number'] : 1;
    $section_title = trim($data['section_title'] ?? '');
    $section_badge = trim($data['section_badge'] ?? '');
    $icon = trim($data['icon'] ?? 'shield-check');
    $content = trim($data['content'] ?? '');
    $sort_order = isset($data['sort_order']) ? (int)$data['sort_order'] : 10;
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    if (empty($section_title)) {
        return ['success' => false, 'message' => 'Section Title cannot be empty'];
    }
    if (empty($content)) {
        return ['success' => false, 'message' => 'Section Content cannot be empty'];
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE policy_points SET 
                page_type = ?, 
                section_number = ?, 
                section_title = ?, 
                section_badge = ?, 
                icon = ?, 
                content = ?, 
                sort_order = ?, 
                is_active = ?
                WHERE id = ?");
            $stmt->execute([
                $page_type,
                $section_number,
                $section_title,
                $section_badge,
                $icon,
                $content,
                $sort_order,
                $is_active,
                $id
            ]);
            return ['success' => true, 'message' => 'Policy point updated successfully', 'id' => $id];
        } else {
            $stmt = $pdo->prepare("INSERT INTO policy_points 
                (page_type, section_number, section_title, section_badge, icon, content, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $page_type,
                $section_number,
                $section_title,
                $section_badge,
                $icon,
                $content,
                $sort_order,
                $is_active
            ]);
            return ['success' => true, 'message' => 'New policy point added successfully', 'id' => $pdo->lastInsertId()];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Deletes a policy point
 */
function delete_policy_point($id) {
    global $pdo, $db_connected;
    if (!$db_connected || !$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable'];
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM policy_points WHERE id = ?");
        $stmt->execute([(int)$id]);
        return ['success' => true, 'message' => 'Policy point deleted successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to delete: ' . $e->getMessage()];
    }
}

/**
 * Toggles active/inactive status of a policy point
 */
function toggle_policy_point_status($id) {
    global $pdo, $db_connected;
    if (!$db_connected || !$pdo) {
        return ['success' => false, 'message' => 'Database connection unavailable'];
    }
    try {
        $stmt = $pdo->prepare("UPDATE policy_points SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        $stmt->execute([(int)$id]);
        return ['success' => true, 'message' => 'Policy point status toggled'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to toggle status: ' . $e->getMessage()];
    }
}
