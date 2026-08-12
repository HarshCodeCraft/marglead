<?php
/**
 * Marg Soft Solution - WhatsApp Bots & Flows Management Directory
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Active Tab Resolution
$tab = strtolower(trim($_GET['tab'] ?? 'flows'));
if ($tab === 'inggers') {
    $tab = 'triggers';
}

// Ensure table exists
try {
    if ($db_connected && $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_flows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            flow_id VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            category VARCHAR(50) DEFAULT 'SIGN IN',
            status VARCHAR(20) DEFAULT 'PUBLISHED',
            screens_json LONGTEXT NULL,
            raw_nodes_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Seed default 3 flows if empty
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM bot_flows");
        if ($stmtCount->fetchColumn() == 0) {
            $defaultScreens = json_encode([
                [
                    "id" => "screen_1",
                    "name" => "Welcome to Marg Soft",
                    "title" => "Welcome to Marg Soft",
                    "body" => "Please Provide Your Info and Problem Here..",
                    "components" => [
                        ["id" => "c1", "type" => "Short Answer", "label" => "License Number", "helper" => "Client Id", "required" => true],
                        ["id" => "c2", "type" => "Dropdown", "label" => "Bill Format Issue", "helper" => "", "options" => ["Bill Format Issue", "GST Error", "Printer Setup"], "required" => false],
                        ["id" => "c3", "type" => "Text Area", "label" => "Problem", "helper" => "Describe issue", "required" => true],
                        ["id" => "c4", "type" => "Short Answer", "label" => "Call Back Number", "helper" => "Call Back Number", "required" => true]
                    ],
                    "footer_label" => "Submit",
                    "footer_action" => "Complete"
                ]
            ]);
            $stmtSeed = $pdo->prepare("INSERT INTO bot_flows (flow_id, name, category, status, screens_json) VALUES (?, ?, ?, ?, ?)");
            $stmtSeed->execute(['1838065533836150', 'Ticket', 'SIGN IN', 'PUBLISHED', $defaultScreens]);
            $stmtSeed->execute(['36230192503294106', 'Service', 'SIGN IN', 'PUBLISHED', $defaultScreens]);
            $stmtSeed->execute(['1303139711243346', 'Bot', 'SIGN IN', 'PUBLISHED', $defaultScreens]);
        }
    }
} catch (PDOException $e) {}

// Fetch flows list
$flows = [];
if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM bot_flows ORDER BY id ASC");
        $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
?>

<div class="bot-flows-container">
    <div class="bot-module-layout">
        
        <!-- Main Panel Area -->
        <div class="bot-main-panel">
            
            <?php if ($tab === 'bots'): ?>
                <!-- BOTS VIEW -->
                <div class="flows-header-bar">
                    <div>
                        <h1 class="flows-title">WhatsApp Bots & Auto-Responders</h1>
                        <p class="text-xs text-muted mb-0">Manage active automated bots, keyword responders, and webhook integration endpoints.</p>
                    </div>
                    <div class="flows-actions-right">
                        <button type="button" class="btn-pill btn-pill-outline" onclick="alert('Webhook status: Healthy (200 OK)')">
                            <i data-lucide="radio" style="width: 14px; height: 14px; color: #10b981;"></i>
                            Webhook Status
                        </button>
                        <button type="button" class="btn-pill btn-pill-dark" onclick="openCreateModal()">
                            <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                            Create Bot
                        </button>
                    </div>
                </div>

                <!-- Stat Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem;">
                        <div class="text-xs text-muted font-semibold">Active Bots</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-main);">4</div>
                        <span style="font-size: 0.725rem; color: #10b981;">● All operational</span>
                    </div>
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem;">
                        <div class="text-xs text-muted font-semibold">Automated Messages</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-main);">1,428</div>
                        <span style="font-size: 0.725rem; color: var(--primary);">+12% this week</span>
                    </div>
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem;">
                        <div class="text-xs text-muted font-semibold">Webhook Uptime</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;">99.8%</div>
                        <span style="font-size: 0.725rem; color: var(--text-muted);">Meta Cloud API Connected</span>
                    </div>
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1rem;">
                        <div class="text-xs text-muted font-semibold">Avg Response Time</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-main);">0.9s</div>
                        <span style="font-size: 0.725rem; color: #10b981;">Sub-second execution</span>
                    </div>
                </div>

                <!-- Bots Table -->
                <div class="flows-table-wrapper">
                    <table class="flows-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Bot Name</th>
                                <th>Category</th>
                                <th>Trigger Mechanism</th>
                                <th>Matched Flow / Endpoint</th>
                                <th>Status</th>
                                <th style="width: 100px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td><strong style="color: var(--text-main);">Marg AI Technical Support Bot</strong></td>
                                <td><span class="text-xs font-semibold text-muted">Tech Support</span></td>
                                <td><span class="badge" style="--badge-bg: rgba(59,130,246,0.1); --badge-color: #3b82f6;">Keyword + AI</span></td>
                                <td><code>Ticket Flow (1838065533836150)</code></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <a href="index.php?page=bot_flow_builder&q=1838065533836150" class="btn-pill btn-pill-outline text-xs">Configure</a>
                                </td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><strong style="color: var(--text-main);">Lead Qualification & Welcome Bot</strong></td>
                                <td><span class="text-xs font-semibold text-muted">Sales</span></td>
                                <td><span class="badge" style="--badge-bg: rgba(16,185,129,0.1); --badge-color: #10b981;">Incoming Greeting</span></td>
                                <td><code>Service Flow (36230192503294106)</code></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <a href="index.php?page=bot_flow_builder&q=36230192503294106" class="btn-pill btn-pill-outline text-xs">Configure</a>
                                </td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><strong style="color: var(--text-main);">Invoice & Billing Assistant Bot</strong></td>
                                <td><span class="text-xs font-semibold text-muted">Accounts</span></td>
                                <td><span class="badge" style="--badge-bg: rgba(245,158,11,0.1); --badge-color: #f59e0b;">Event / System Trigger</span></td>
                                <td><code>Bot Flow (1303139711243346)</code></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <a href="index.php?page=bot_flow_builder&q=1303139711243346" class="btn-pill btn-pill-outline text-xs">Configure</a>
                                </td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><strong style="color: var(--text-main);">Software License Renewal Bot</strong></td>
                                <td><span class="text-xs font-semibold text-muted">Operations</span></td>
                                <td><span class="badge" style="--badge-bg: rgba(139,92,246,0.1); --badge-color: #8b5cf6;">Cron Scheduler</span></td>
                                <td><code>Renewal Flow</code></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <a href="index.php?page=bot_flow_builder&q=1303139711243346" class="btn-pill btn-pill-outline text-xs">Configure</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($tab === 'events'): ?>
                <!-- EVENTS VIEW -->
                <div class="flows-header-bar">
                    <div>
                        <h1 class="flows-title">Bot & Flow Execution Events Log</h1>
                        <p class="text-xs text-muted mb-0">Real-time webhook payloads, screen completions, and conversation triggers.</p>
                    </div>
                    <div class="flows-actions-right">
                        <button type="button" class="btn-pill btn-pill-outline" onclick="alert('Exporting event logs CSV...')">
                            <i data-lucide="download" style="width: 14px; height: 14px;"></i>
                            Export Logs
                        </button>
                    </div>
                </div>

                <div class="flows-table-wrapper">
                    <table class="flows-table">
                        <thead>
                            <tr>
                                <th>Event ID</th>
                                <th>Timestamp</th>
                                <th>Client Phone</th>
                                <th>Flow / Bot</th>
                                <th>Event Type</th>
                                <th>Trigger Keyword</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="font-mono text-xs">#EVT-9042</td>
                                <td class="text-xs">2026-08-01 11:10:04</td>
                                <td class="font-semibold text-xs">+91 98765 43210</td>
                                <td>Ticket Flow</td>
                                <td><span class="badge" style="--badge-bg: rgba(59,130,246,0.1); --badge-color: #3b82f6;">Screen Submitted</span></td>
                                <td class="font-mono text-xs">TICKET</td>
                                <td><span class="status-badge status-badge-published">SUCCESS</span></td>
                            </tr>
                            <tr>
                                <td class="font-mono text-xs">#EVT-9041</td>
                                <td class="text-xs">2026-08-01 10:55:12</td>
                                <td class="font-semibold text-xs">+91 98123 45678</td>
                                <td>Service Flow</td>
                                <td><span class="badge" style="--badge-bg: rgba(16,185,129,0.1); --badge-color: #10b981;">Button Click</span></td>
                                <td class="font-mono text-xs">SERVICE</td>
                                <td><span class="status-badge status-badge-published">SUCCESS</span></td>
                            </tr>
                            <tr>
                                <td class="font-mono text-xs">#EVT-9040</td>
                                <td class="text-xs">2026-08-01 10:40:22</td>
                                <td class="font-semibold text-xs">+91 99000 11223</td>
                                <td>Marg AI Support</td>
                                <td><span class="badge" style="--badge-bg: rgba(139,92,246,0.1); --badge-color: #8b5cf6;">Keyword Match</span></td>
                                <td class="font-mono text-xs">HELP</td>
                                <td><span class="status-badge status-badge-published">SUCCESS</span></td>
                            </tr>
                            <tr>
                                <td class="font-mono text-xs">#EVT-9039</td>
                                <td class="text-xs">2026-08-01 09:20:15</td>
                                <td class="font-semibold text-xs">+91 98888 77766</td>
                                <td>Invoice Bot</td>
                                <td><span class="badge" style="--badge-bg: rgba(245,158,11,0.1); --badge-color: #f59e0b;">System Cron</span></td>
                                <td class="font-mono text-xs">BILL</td>
                                <td><span class="status-badge status-badge-published">SUCCESS</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($tab === 'triggers'): ?>
                <!-- TRIGGERS (INGGERS) VIEW -->
                <div class="flows-header-bar">
                    <div>
                        <h1 class="flows-title">Keyword & Event Triggers (Inggers)</h1>
                        <p class="text-xs text-muted mb-0">Configure rules for triggering specific WhatsApp flows and AI bots.</p>
                    </div>
                    <div class="flows-actions-right">
                        <button type="button" class="btn-pill btn-pill-dark" onclick="alert('Creating new trigger rule...')">
                            <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                            Add New Trigger
                        </button>
                    </div>
                </div>

                <div class="flows-table-wrapper">
                    <table class="flows-table">
                        <thead>
                            <tr>
                                <th>Rule ID</th>
                                <th>Trigger Mechanism</th>
                                <th>Keyword / Pattern</th>
                                <th>Target Flow / Bot</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th style="width: 100px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="font-mono text-xs">#TRG-01</td>
                                <td>Exact Match Keyword</td>
                                <td><span class="badge" style="--badge-bg: rgba(59,130,246,0.1); --badge-color: #3b82f6;">TICKET</span></td>
                                <td>Ticket Flow (1838065533836150)</td>
                                <td><span class="text-xs font-semibold" style="color: var(--danger);">High</span></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Editing trigger rule #TRG-01')" class="btn-pill btn-pill-outline text-xs">Edit</button>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-mono text-xs">#TRG-02</td>
                                <td>Exact Match Keyword</td>
                                <td><span class="badge" style="--badge-bg: rgba(16,185,129,0.1); --badge-color: #10b981;">SERVICE</span></td>
                                <td>Service Flow (36230192503294106)</td>
                                <td><span class="text-xs font-semibold" style="color: var(--danger);">High</span></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Editing trigger rule #TRG-02')" class="btn-pill btn-pill-outline text-xs">Edit</button>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-mono text-xs">#TRG-03</td>
                                <td>Exact Match Keyword</td>
                                <td><span class="badge" style="--badge-bg: rgba(139,92,246,0.1); --badge-color: #8b5cf6;">BOT</span></td>
                                <td>Bot Flow (1303139711243346)</td>
                                <td><span class="text-xs font-semibold" style="color: var(--warning);">Medium</span></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Editing trigger rule #TRG-03')" class="btn-pill btn-pill-outline text-xs">Edit</button>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-mono text-xs">#TRG-04</td>
                                <td>System Webhook Event</td>
                                <td><span class="badge" style="--badge-bg: rgba(245,158,11,0.1); --badge-color: #f59e0b;">LEAD_CREATED</span></td>
                                <td>Lead Qualification Bot</td>
                                <td><span class="text-xs font-semibold" style="color: var(--danger);">High</span></td>
                                <td><span class="status-badge status-badge-published">ACTIVE</span></td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Editing trigger rule #TRG-04')" class="btn-pill btn-pill-outline text-xs">Edit</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($tab === 're-engagement'): ?>
                <!-- RE-ENGAGEMENT VIEW -->
                <div class="flows-header-bar">
                    <div>
                        <h1 class="flows-title">Re-Engagement Sequences & Campaigns</h1>
                        <p class="text-xs text-muted mb-0">Automated drip workflows for dormant leads, pending demos, and renewal follow-ups.</p>
                    </div>
                    <div class="flows-actions-right">
                        <button type="button" class="btn-pill btn-pill-dark" onclick="alert('Creating new re-engagement campaign...')">
                            <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                            New Sequence
                        </button>
                    </div>
                </div>

                <div class="flows-table-wrapper">
                    <table class="flows-table">
                        <thead>
                            <tr>
                                <th>Campaign Name</th>
                                <th>Inactivity Trigger</th>
                                <th>Target Audience</th>
                                <th>Schedule / Drip Interval</th>
                                <th>Status</th>
                                <th>Conversion Rate</th>
                                <th style="width: 100px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong style="color: var(--text-main);">24-Hour Lead Re-Engagement Nudge</strong></td>
                                <td>No reply after 24 hours</td>
                                <td><span class="badge" style="--badge-bg: rgba(59,130,246,0.1); --badge-color: #3b82f6;">New Uncontacted Leads</span></td>
                                <td>1 Day Post-Capture</td>
                                <td><span class="status-badge status-badge-published">RUNNING</span></td>
                                <td><strong style="color: #10b981;">64%</strong> (182 Sent)</td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Managing sequence settings...')" class="btn-pill btn-pill-outline text-xs">Manage</button>
                                </td>
                            </tr>
                            <tr>
                                <td><strong style="color: var(--text-main);">Post-Demo Feedback & Follow-up</strong></td>
                                <td>48h after Demo Finished</td>
                                <td><span class="badge" style="--badge-bg: rgba(139,92,246,0.1); --badge-color: #8b5cf6;">Demo Completed</span></td>
                                <td>2 Days Post-Demo</td>
                                <td><span class="status-badge status-badge-published">RUNNING</span></td>
                                <td><strong style="color: #10b981;">42%</strong> (94 Sent)</td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Managing sequence settings...')" class="btn-pill btn-pill-outline text-xs">Manage</button>
                                </td>
                            </tr>
                            <tr>
                                <td><strong style="color: var(--text-main);">License Expiry 7-Day Renewal Blast</strong></td>
                                <td>7 Days before expiration</td>
                                <td><span class="badge" style="--badge-bg: rgba(245,158,11,0.1); --badge-color: #f59e0b;">Active Subscribers</span></td>
                                <td>Automated Cron</td>
                                <td><span class="status-badge status-badge-published">RUNNING</span></td>
                                <td><strong style="color: #10b981;">89%</strong> (310 Sent)</td>
                                <td style="text-align: center;">
                                    <button type="button" onclick="alert('Managing sequence settings...')" class="btn-pill btn-pill-outline text-xs">Manage</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($tab === 'reports'): ?>
                <!-- REPORTS VIEW -->
                <div class="flows-header-bar">
                    <div>
                        <h1 class="flows-title">Bot & Flow Analytics Reports</h1>
                        <p class="text-xs text-muted mb-0">Overview of bot engagement performance, completion metrics, and conversion analytics.</p>
                    </div>
                    <div class="flows-actions-right">
                        <button type="button" class="btn-pill btn-pill-outline" onclick="window.print()">
                            <i data-lucide="printer" style="width: 14px; height: 14px;"></i>
                            Print Summary
                        </button>
                    </div>
                </div>

                <!-- Analytics KPI Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1.25rem;">
                        <div class="text-xs text-muted font-semibold">Total Conversations</div>
                        <div style="font-size: 1.75rem; font-weight: 800; color: var(--text-main);">1,842</div>
                        <span style="font-size: 0.725rem; color: #10b981;">↑ +18.4% from last month</span>
                    </div>
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1.25rem;">
                        <div class="text-xs text-muted font-semibold">Flow Completion Rate</div>
                        <div style="font-size: 1.75rem; font-weight: 800; color: #10b981;">91.4%</div>
                        <span style="font-size: 0.725rem; color: var(--text-muted);">High interaction quality</span>
                    </div>
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1.25rem;">
                        <div class="text-xs text-muted font-semibold">User Drop-off Rate</div>
                        <div style="font-size: 1.75rem; font-weight: 800; color: var(--warning, #f59e0b);">8.6%</div>
                        <span style="font-size: 0.725rem; color: #10b981;">↓ -2.1% improvement</span>
                    </div>
                    <div style="background: var(--bg-body, rgba(255,255,255,0.03)); border: 1px solid var(--border-color); border-radius: 10px; padding: 1.25rem;">
                        <div class="text-xs text-muted font-semibold">Automated Leads Captured</div>
                        <div style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">412</div>
                        <span style="font-size: 0.725rem; color: #10b981;">Direct CRM syncing</span>
                    </div>
                </div>

                <!-- Breakdown Table -->
                <div class="flows-table-wrapper">
                    <h3 style="font-size: 1rem; font-family: var(--font-heading); margin-bottom: 0.75rem;">Flow Execution Summary</h3>
                    <table class="flows-table">
                        <thead>
                            <tr>
                                <th>Flow Name</th>
                                <th>Category</th>
                                <th>Total Sessions</th>
                                <th>Completed</th>
                                <th>Drop-offs</th>
                                <th>Avg Screen Time</th>
                                <th>Completion %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Ticket Flow</strong></td>
                                <td>SIGN IN</td>
                                <td>842</td>
                                <td>790</td>
                                <td>52</td>
                                <td>42 sec</td>
                                <td><strong style="color: #10b981;">93.8%</strong></td>
                            </tr>
                            <tr>
                                <td><strong>Service Flow</strong></td>
                                <td>SIGN IN</td>
                                <td>620</td>
                                <td>562</td>
                                <td>58</td>
                                <td>38 sec</td>
                                <td><strong style="color: #10b981;">90.6%</strong></td>
                            </tr>
                            <tr>
                                <td><strong>Bot Flow</strong></td>
                                <td>SIGN IN</td>
                                <td>380</td>
                                <td>332</td>
                                <td>48</td>
                                <td>25 sec</td>
                                <td><strong style="color: #10b981;">87.3%</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <!-- FLOWS VIEW (DEFAULT) -->
                <div class="flows-header-bar">
                    <h1 class="flows-title">Flows</h1>
                    
                    <div class="flows-actions-right">
                        <div class="search-flows-box">
                            <i data-lucide="search"></i>
                            <input type="text" id="searchFlowsInput" placeholder="Search Flows" onkeyup="filterFlowsTable()">
                        </div>
                        
                        <button type="button" class="btn-pill btn-pill-outline" onclick="openImportModal()">
                            <i data-lucide="upload" style="width: 14px; height: 14px;"></i>
                            Import
                        </button>
                        
                        <button type="button" class="btn-pill btn-pill-dark" onclick="openCreateModal()">
                            <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                            Add New
                        </button>
                    </div>
                </div>

                <!-- Flows Directory Table -->
                <div class="flows-table-wrapper">
                    <table class="flows-table" id="flowsTable">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAllFlows"></th>
                                <th style="width: 50px;">#</th>
                                <th>Flow Name</th>
                                <th>Flow ID</th>
                                <th>Categories</th>
                                <th>Status</th>
                                <th style="width: 100px; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($flows)): ?>
                                <?php foreach ($flows as $idx => $f): ?>
                                    <tr id="flow-row-<?php echo $f['id']; ?>">
                                        <td><input type="checkbox" class="flow-checkbox"></td>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td>
                                            <a href="index.php?page=bot_flow_builder&q=<?php echo htmlspecialchars($f['flow_id']); ?>" class="flow-link">
                                                <?php echo htmlspecialchars($f['name']); ?>
                                            </a>
                                        </td>
                                        <td class="font-mono text-xs"><?php echo htmlspecialchars($f['flow_id']); ?></td>
                                        <td><span class="text-xs text-muted font-semibold"><?php echo htmlspecialchars($f['category']); ?></span></td>
                                        <td>
                                            <span class="status-badge status-badge-published">
                                                <?php echo htmlspecialchars($f['status']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                                <a href="index.php?page=bot_flow_builder&q=<?php echo htmlspecialchars($f['flow_id']); ?>" title="Edit Flow" style="color: var(--text-muted);">
                                                    <i data-lucide="edit-3" style="width: 16px; height: 16px;"></i>
                                                </a>
                                                <button type="button" onclick="toggleFlowStatus(<?php echo $f['id']; ?>)" title="Toggle Status" style="background: none; border: none; cursor: pointer; color: #10b981;">
                                                    <i data-lucide="check-circle" style="width: 16px; height: 16px;"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center p-6 text-muted">No WhatsApp flows configured yet. Click "Add New" or "Import" to get started.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Bar -->
                <div class="flows-pagination">
                    <button type="button" class="page-btn">First</button>
                    <button type="button" class="page-btn">Prev</button>
                    <button type="button" class="page-btn active">1</button>
                    <button type="button" class="page-btn">Next</button>
                    <button type="button" class="page-btn">Last</button>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Floating Help Widget -->
<button type="button" class="floating-need-help" onclick="alert('Marg Bot Assistant: Support helpdesk ready.')">
    Need Help?
</button>

<!-- Import JSON Flow Modal -->
<div id="importModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card, #ffffff); border-radius: 12px; padding: 1.5rem; width: 100%; max-width: 550px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-family: var(--font-heading);">Import Bot Flow JSON</h3>
            <button type="button" onclick="closeImportModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>
        <p class="text-xs text-muted mb-3">Paste your Bot / WhatsApp Flow JSON payload (e.g. Service 01.json) or upload the .json file below.</p>

        <form id="importFlowForm" onsubmit="submitImportFlow(event)">
            <div class="form-group mb-3">
                <label class="form-label font-semibold text-xs mb-1">Paste JSON Payload</label>
                <textarea id="jsonPasteInput" class="input-styled" style="height: 120px; font-family: monospace; font-size: 0.75rem;" placeholder='[{"_id":"69feb0a0...","NodeType":"start",...}]'></textarea>
            </div>
            
            <div class="form-group mb-4">
                <label class="form-label font-semibold text-xs mb-1">Or Upload JSON File</label>
                <input type="file" id="jsonFileInput" accept=".json">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-pill btn-pill-outline" onclick="closeImportModal()">Cancel</button>
                <button type="submit" class="btn-pill btn-pill-primary">Import & Create Flow</button>
            </div>
        </form>
    </div>
</div>

<!-- Create New Flow Modal -->
<div id="createModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card, #ffffff); border-radius: 12px; padding: 1.5rem; width: 100%; max-width: 450px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-family: var(--font-heading);">Create New Flow</h3>
            <button type="button" onclick="closeCreateModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form id="createFlowForm" onsubmit="submitCreateFlow(event)">
            <div class="form-group mb-3">
                <label class="form-label font-semibold text-xs mb-1">Flow Name</label>
                <input type="text" id="newFlowName" class="input-styled" required placeholder="e.g. Ticket / Enquiry Flow">
            </div>
            
            <div class="form-group mb-3">
                <label class="form-label font-semibold text-xs mb-1">Flow ID (Optional)</label>
                <input type="text" id="newFlowId" class="input-styled" placeholder="Auto-generated if left empty">
            </div>

            <div class="form-group mb-4">
                <label class="form-label font-semibold text-xs mb-1">Category</label>
                <select id="newFlowCategory" class="input-styled">
                    <option value="SIGN IN">SIGN IN</option>
                    <option value="SUPPORT">SUPPORT</option>
                    <option value="SALES">SALES</option>
                    <option value="FEEDBACK">FEEDBACK</option>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn-pill btn-pill-outline" onclick="closeCreateModal()">Cancel</button>
                <button type="submit" class="btn-pill btn-pill-dark">Create Flow</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterFlowsTable() {
    const input = document.getElementById('searchFlowsInput').value.toLowerCase();
    const rows = document.querySelectorAll('#flowsTable tbody tr');
    rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = text.includes(input) ? '' : 'none';
    });
}

function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
}
function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

function openCreateModal() {
    document.getElementById('createModal').style.display = 'flex';
}
function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

function submitImportFlow(e) {
    e.preventDefault();
    const paste = document.getElementById('jsonPasteInput').value;
    const file = document.getElementById('jsonFileInput').files[0];
    
    const formData = new FormData();
    formData.append('action', 'import_json');
    if (paste.trim() !== '') {
        formData.append('json_data', paste);
    } else if (file) {
        formData.append('json_file', file);
    } else {
        alert('Please paste JSON data or select a file');
        return;
    }

    fetch('api/bot_flows.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = 'index.php?page=bot_flow_builder&q=' + data.flow_id;
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Import failed: ' + err));
}

function submitCreateFlow(e) {
    e.preventDefault();
    const name = document.getElementById('newFlowName').value;
    const flow_id = document.getElementById('newFlowId').value;
    const category = document.getElementById('newFlowCategory').value;

    const payload = {
        action: 'save',
        name: name,
        flow_id: flow_id,
        category: category,
        status: 'PUBLISHED',
        screens: [
            {
                id: 'screen_1',
                name: 'Welcome to Marg Soft',
                title: 'Welcome to Marg Soft',
                body: 'Please Provide Your Info and Problem Here..',
                components: [
                    { id: 'c1', type: 'Short Answer', label: 'License Number', helper: 'Client Id', required: true },
                    { id: 'c2', type: 'Short Answer', label: 'Call Back Number', helper: 'Call Back Number', required: true }
                ],
                footer_label: 'Submit',
                footer_action: 'Complete'
            }
        ]
    };

    fetch('api/bot_flows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'index.php?page=bot_flow_builder&q=' + data.flow_id;
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function toggleFlowStatus(id) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    formData.append('status', 'PUBLISHED');

    fetch('api/bot_flows.php', {
        method: 'POST',
        body: formData
    }).then(res => res.json()).then(data => {
        if (typeof refreshDataWithoutReload === 'function') {
            refreshDataWithoutReload(true);
        } else {
            location.reload();
        }
    });
}
</script>
