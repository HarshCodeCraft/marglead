<?php
/**
 * Marg CRM - WhatsApp AI Sales Bot & Demo Booking Setup Workspace
 * 
 * Dedicated control panel for training the WhatsApp AI Assistant,
 * configuring verified Marg ERP pricing, guardrails, and testing responses in real time.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ai_service.php';

$role = $_SESSION['user_role'] ?? 'Sales Executive';
$is_admin = ($role === 'Super Admin' || $role === 'Admin');

if (!$is_admin) {
    header('Location: index.php?page=dashboard');
    exit;
}

$message = '';
$message_type = 'success';

// Handle AJAX Simulator Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_ai_simulator') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $testMsg = trim($_POST['test_message'] ?? '');
    if (empty($testMsg)) {
        echo json_encode(['success' => false, 'message' => 'Please type a test message']);
        exit;
    }

    $simContext = [
        'customer_name' => 'Demo User',
        'firm_name' => 'Kalyan Medicos',
        'city' => 'Kanpur',
        'phone' => '+91 9876543210'
    ];

    $res = callAIService([], $testMsg, $simContext, $pdo);
    echo json_encode($res);
    exit;
}

// Handle Form Submission: Save AI Bot Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ai_settings') {
    $bot_enabled = isset($_POST['bot_enabled']) ? 1 : 0;
    $ai_provider = trim($_POST['ai_provider'] ?? 'gemini');
    $api_key = trim($_POST['api_key'] ?? '');
    $ai_model = trim($_POST['ai_model'] ?? 'gemini-3.1-flash-lite');
    $pricing_nano = trim($_POST['pricing_nano'] ?? '₹5,550 + 18% GST');
    $pricing_basic = trim($_POST['pricing_basic'] ?? '₹10,300 + 18% GST');
    $pricing_silver = trim($_POST['pricing_silver'] ?? '₹13,900 + 18% GST');
    $pricing_gold = trim($_POST['pricing_gold'] ?? '₹26,000 + 18% GST');
    $system_prompt = trim($_POST['system_prompt'] ?? '');
    $knowledge_base = trim($_POST['knowledge_base'] ?? '');
    $show_sales_chats = isset($_POST['show_sales_chats_in_inbox']) ? 1 : 0;
    $mute_on_reply = isset($_POST['mute_on_human_reply']) ? 1 : 0;

    try {
        // If API key is empty in POST, check if we should keep existing DB key
        if (empty($api_key)) {
            $currKeyStmt = $pdo->query("SELECT api_key FROM ai_bot_settings WHERE id = 1 LIMIT 1");
            $api_key = $currKeyStmt ? ($currKeyStmt->fetchColumn() ?: '') : '';
        }

        $stmtUpd = $pdo->prepare("INSERT INTO ai_bot_settings 
            (id, bot_enabled, ai_provider, api_key, ai_model, pricing_nano, pricing_basic, pricing_silver, pricing_gold, system_prompt, knowledge_base, show_sales_chats_in_inbox, mute_on_human_reply)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            bot_enabled = VALUES(bot_enabled),
            ai_provider = VALUES(ai_provider),
            api_key = VALUES(api_key),
            ai_model = VALUES(ai_model),
            pricing_nano = VALUES(pricing_nano),
            pricing_basic = VALUES(pricing_basic),
            pricing_silver = VALUES(pricing_silver),
            pricing_gold = VALUES(pricing_gold),
            system_prompt = VALUES(system_prompt),
            knowledge_base = VALUES(knowledge_base),
            show_sales_chats_in_inbox = VALUES(show_sales_chats_in_inbox),
            mute_on_human_reply = VALUES(mute_on_human_reply)");

        $stmtUpd->execute([
            $bot_enabled, $ai_provider, $api_key, $ai_model,
            $pricing_nano, $pricing_basic, $pricing_silver, $pricing_gold,
            $system_prompt, $knowledge_base, $show_sales_chats, $mute_on_reply
        ]);

        $message = "AI Sales Bot configuration & knowledge base updated successfully!";
        $message_type = "success";
    } catch (Throwable $e) {
        $message = "Error saving settings: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Load current settings
$settings = getAISettings($pdo);
$activeKeyMasked = !empty($settings['api_key']) ? substr($settings['api_key'], 0, 8) . '••••••••••••' . substr($settings['api_key'], -4) : '';
?>

<div class="content-wrapper" style="padding: 1.25rem 1.5rem; max-width: 1400px; margin: 0 auto;">
    
    <!-- Top Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.2rem;">
                <span style="background: rgba(16, 185, 129, 0.12); color: #10b981; padding: 6px 10px; border-radius: 8px; font-weight: 700; font-size: 1.1rem; display: inline-flex; align-items: center; gap: 6px;">
                    <i data-lucide="bot" style="width: 20px; height: 20px;"></i>
                </span>
                <h1 style="margin: 0; font-size: 1.35rem; font-weight: 800; color: var(--text-main, #0f172a);">
                    WhatsApp AI Sales Bot & Demo Booking Setup
                </h1>
                <span class="badge" style="background: <?php echo !empty($settings['bot_enabled']) ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'; ?>; color: <?php echo !empty($settings['bot_enabled']) ? '#10b981' : '#ef4444'; ?>; font-weight: 700; font-size: 0.75rem; padding: 3px 8px; border-radius: 12px;">
                    <?php echo !empty($settings['bot_enabled']) ? '🟢 BOT ACTIVE' : '🔴 BOT DISABLED'; ?>
                </span>
            </div>
            <p style="margin: 0; font-size: 0.82rem; color: var(--text-muted, #64748b);">
                Configure Google Gemini AI instructions, official Marg ERP pricing, guardrails, and auto demo scheduling.
            </p>
        </div>
        <div style="display: flex; gap: 0.6rem;">
            <a href="index.php?page=team_inbox" class="btn btn-sm btn-outline" style="border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                <i data-lucide="message-square" style="width: 14px; height: 14px;"></i> Open Team Inbox
            </a>
            <a href="index.php?page=demo" class="btn btn-sm btn-outline" style="border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                <i data-lucide="presentation" style="width: 14px; height: 14px;"></i> Demo Hub
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?>" style="padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="<?php echo ($message_type === 'success') ? 'check-circle' : 'alert-triangle'; ?>" style="width: 16px; height: 16px;"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 1.25rem; align-items: start;">
        
        <!-- LEFT COLUMN: SETTINGS FORM -->
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="save_ai_settings">

            <!-- Card 1: Engine & Master Switch -->
            <div class="card" style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color, #f1f5f9); padding-bottom: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="cpu" style="width: 18px; height: 18px; color: var(--primary, #2563eb);"></i>
                        <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700;">AI Engine & Credentials</h3>
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 0.82rem; font-weight: 700;">
                        <span>Enable WhatsApp AI Bot:</span>
                        <input type="checkbox" name="bot_enabled" value="1" <?php echo !empty($settings['bot_enabled']) ? 'checked' : ''; ?> style="width: 18px; height: 18px; cursor: pointer; accent-color: #10b981;">
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 0.85rem;">
                    <div>
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted, #64748b); display: block; margin-bottom: 4px;">AI Provider</label>
                        <select name="ai_provider" class="form-control" style="height: 38px; font-size: 0.82rem; border-radius: 8px;">
                            <option value="gemini" <?php echo (($settings['ai_provider'] ?? '') === 'gemini') ? 'selected' : ''; ?>>Google Gemini Flash (Recommended)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted, #64748b); display: block; margin-bottom: 4px;">Model Version</label>
                        <select name="ai_model" class="form-control" style="height: 38px; font-size: 0.82rem; border-radius: 8px;">
                            <option value="gemini-3.1-flash-lite" <?php echo (empty($settings['ai_model']) || ($settings['ai_model'] ?? '') === 'gemini-3.1-flash-lite' || strpos($settings['ai_model'], '1.5') !== false || strpos($settings['ai_model'], '2.0') !== false) ? 'selected' : ''; ?>>Gemini 3.1 Flash-Lite (Recommended - Fast & Free Quota)</option>
                            <option value="gemini-3.5-flash-lite" <?php echo (($settings['ai_model'] ?? '') === 'gemini-3.5-flash-lite') ? 'selected' : ''; ?>>Gemini 3.5 Flash-Lite (High Accuracy)</option>
                            <option value="gemini-3.6-flash" <?php echo (($settings['ai_model'] ?? '') === 'gemini-3.6-flash') ? 'selected' : ''; ?>>Gemini 3.6 Flash (Advanced Next-Gen)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted, #64748b); display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Google Gemini API Key</span>
                        <?php if (!empty($activeKeyMasked)): ?>
                            <span style="color: #10b981; font-weight: 600;">Active: <?php echo htmlspecialchars($activeKeyMasked); ?></span>
                        <?php endif; ?>
                    </label>
                    <div style="position: relative;">
                        <input type="password" id="apiKeyField" name="api_key" value="" placeholder="Leave blank to keep existing key, or paste new key" class="form-control" style="height: 38px; font-size: 0.82rem; border-radius: 8px; padding-right: 2.5rem;">
                        <button type="button" onclick="toggleKeyVisibility()" style="position: absolute; right: 8px; top: 8px; background: none; border: none; cursor: pointer; color: var(--text-muted);">
                            <i data-lucide="eye" id="eyeIcon" style="width: 16px; height: 16px;"></i>
                        </button>
                    </div>
                    <span style="font-size: 0.7rem; color: var(--text-muted); margin-top: 3px; display: block;">
                        Your key is stored securely and never exposed to website visitors or customers.
                    </span>
                </div>
            </div>

            <!-- Card 2: Marg ERP Pricing Matrix -->
            <div class="card" style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.85rem; border-bottom: 1px solid var(--border-color, #f1f5f9); padding-bottom: 0.6rem;">
                    <i data-lucide="tag" style="width: 18px; height: 18px; color: #10b981;"></i>
                    <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700;">Marg ERP Editions & Official Pricing</h3>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem;">
                    <div>
                        <label style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Marg Nano Edition</label>
                        <input type="text" name="pricing_nano" value="<?php echo htmlspecialchars($settings['pricing_nano'] ?? '₹5,550 + 18% GST'); ?>" class="form-control text-xs font-semibold" style="height: 36px; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Basic Edition (Single User)</label>
                        <input type="text" name="pricing_basic" value="<?php echo htmlspecialchars($settings['pricing_basic'] ?? '₹10,300 + 18% GST'); ?>" class="form-control text-xs font-semibold" style="height: 36px; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Silver Edition (Pharma/Retail)</label>
                        <input type="text" name="pricing_silver" value="<?php echo htmlspecialchars($settings['pricing_silver'] ?? '₹13,900 + 18% GST'); ?>" class="form-control text-xs font-semibold" style="height: 36px; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Gold Edition (Unlimited Users)</label>
                        <input type="text" name="pricing_gold" value="<?php echo htmlspecialchars($settings['pricing_gold'] ?? '₹26,000 + 18% GST'); ?>" class="form-control text-xs font-semibold" style="height: 36px; border-radius: 8px;">
                    </div>
                </div>
            </div>

            <!-- Card 3: System Prompt & Guardrails -->
            <div class="card" style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.85rem; border-bottom: 1px solid var(--border-color, #f1f5f9); padding-bottom: 0.6rem;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="shield" style="width: 18px; height: 18px; color: #8b5cf6;"></i>
                        <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700;">AI Persona & Conversational Guardrails</h3>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <span class="badge" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6; font-size: 0.65rem; padding: 2px 6px;">🛡️ Anti-Leakage</span>
                        <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; font-size: 0.65rem; padding: 2px 6px;">🛡️ Fixed Price</span>
                    </div>
                </div>

                <div style="margin-bottom: 0.85rem;">
                    <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">System Prompt (Core Instructions)</label>
                    <textarea name="system_prompt" rows="5" class="form-control" style="font-size: 0.8rem; line-height: 1.45; border-radius: 8px; font-family: monospace;"><?php echo htmlspecialchars($settings['system_prompt'] ?? ''); ?></textarea>
                </div>

                <div>
                    <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Marg ERP Knowledge Base & Custom FAQs</label>
                    <textarea name="knowledge_base" rows="5" class="form-control" style="font-size: 0.8rem; line-height: 1.45; border-radius: 8px; font-family: monospace;"><?php echo htmlspecialchars($settings['knowledge_base'] ?? ''); ?></textarea>
                    <span style="font-size: 0.7rem; color: var(--text-muted); margin-top: 3px; display: block;">
                        Add specific FAQs here (e.g. "Do you provide barcode scanner support? Yes, 100% plug & play").
                    </span>
                </div>
            </div>

            <!-- Card 4: Inbox & Human Takeover Controls -->
            <div class="card" style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.85rem; border-bottom: 1px solid var(--border-color, #f1f5f9); padding-bottom: 0.6rem;">
                    <i data-lucide="sliders" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                    <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700;">Team Inbox & Human Handover Settings</h3>
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 0.82rem;">
                        <input type="checkbox" name="show_sales_chats_in_inbox" value="1" <?php echo !empty($settings['show_sales_chats_in_inbox']) ? 'checked' : ''; ?> style="width: 16px; height: 16px; accent-color: #2563eb;">
                        <span><strong>Show AI Sales chats in Team Inbox:</strong> Allows sales operators to see active bot leads in real time.</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin: 0; font-size: 0.82rem;">
                        <input type="checkbox" name="mute_on_human_reply" value="1" <?php echo !empty($settings['mute_on_human_reply']) ? 'checked' : ''; ?> style="width: 16px; height: 16px; accent-color: #2563eb;">
                        <span><strong>Auto-Mute AI on Human Reply:</strong> If a team member types in Team Inbox, AI stops auto-replying on that chat.</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.5rem; font-weight: 700; border-radius: 8px; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px;">
                <i data-lucide="save" style="width: 16px; height: 16px;"></i> Save AI Settings & Knowledge Base
            </button>
        </form>

        <!-- RIGHT COLUMN: INTERACTIVE AI TEST SIMULATOR -->
        <div>
            <div class="card" style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #e2e8f0); border-radius: 12px; padding: 1.25rem; box-shadow: 0 4px 16px rgba(0,0,0,0.04); position: sticky; top: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; border-bottom: 1px solid var(--border-color, #f1f5f9); padding-bottom: 0.6rem;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="sparkles" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                        <h3 style="margin: 0; font-size: 0.98rem; font-weight: 700;">Live AI Test Simulator</h3>
                    </div>
                    <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #10b981; font-size: 0.65rem; padding: 2px 6px;">
                        Playground
                    </span>
                </div>

                <p style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 0.85rem;">
                    Test how your AI bot speaks to customers, extracts demo details, or handles support questions before going live.
                </p>

                <!-- Quick Test Prompts -->
                <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 0.85rem;">
                    <button type="button" onclick="setSimInput('Marg ERP Silver ka price kya hai?')" class="btn-pill text-xs" style="background: rgba(37,99,235,0.08); color: #2563eb; border: 1px solid rgba(37,99,235,0.15); padding: 3px 7px; font-size: 0.68rem; cursor: pointer;">
                        💰 "Silver price?"
                    </button>
                    <button type="button" onclick="setSimInput('Kya pharmacy ke liye expiry alert milta hai?')" class="btn-pill text-xs" style="background: rgba(16,185,129,0.08); color: #059669; border: 1px solid rgba(16,185,129,0.15); padding: 3px 7px; font-size: 0.68rem; cursor: pointer;">
                        💊 "Expiry feature?"
                    </button>
                    <button type="button" onclick="setSimInput('Haan mujhe kal 3 baje demo chahiye, shop name Sharma Medicos Kanpur')" class="btn-pill text-xs" style="background: rgba(245,158,11,0.08); color: #d97706; border: 1px solid rgba(245,158,11,0.15); padding: 3px 7px; font-size: 0.68rem; cursor: pointer;">
                        💻 "Book Demo"
                    </button>
                    <button type="button" onclick="setSimInput('Mera invoice print nahi ho raha error aa raha hai')" class="btn-pill text-xs" style="background: rgba(239,68,68,0.08); color: #dc2626; border: 1px solid rgba(239,68,68,0.15); padding: 3px 7px; font-size: 0.68rem; cursor: pointer;">
                        🎫 "Support error"
                    </button>
                </div>

                <!-- Chat History Window -->
                <div id="simChatWindow" style="height: 320px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; margin-bottom: 0.75rem;">
                    <div style="align-self: flex-start; max-width: 85%; background: white; border: 1px solid #e2e8f0; border-radius: 8px 8px 8px 2px; padding: 0.6rem 0.75rem; font-size: 0.8rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <strong style="color: #10b981; font-size: 0.72rem; display: block; margin-bottom: 2px;">🤖 Marg AI Assistant</strong>
                        Namaste! Marg ERP sales desk me aapka swagat hai. Aap Marg Basic, Silver ya Gold kis software ka demo dekhna chahenge?
                    </div>
                </div>

                <!-- Simulator Input Form -->
                <div style="display: flex; gap: 6px;">
                    <input type="text" id="simTextInput" placeholder="Type a message as a customer..." class="form-control" style="height: 38px; font-size: 0.82rem; border-radius: 8px;" onkeypress="if(event.key === 'Enter'){ sendSimMessage(); event.preventDefault(); }">
                    <button type="button" id="simSendBtn" onclick="sendSimMessage()" class="btn btn-primary" style="height: 38px; padding: 0 14px; border-radius: 8px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">
                        <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleKeyVisibility() {
    const field = document.getElementById('apiKeyField');
    const icon = document.getElementById('eyeIcon');
    if (field.type === 'password') {
        field.type = 'text';
        icon.setAttribute('data-lucide', 'eye-off');
    } else {
        field.type = 'password';
        icon.setAttribute('data-lucide', 'eye');
    }
    if (window.lucide) lucide.createIcons();
}

function setSimInput(text) {
    const input = document.getElementById('simTextInput');
    if (input) {
        input.value = text;
        input.focus();
    }
}

function sendSimMessage() {
    const input = document.getElementById('simTextInput');
    const msg = input.value.trim();
    if (!msg) return;

    const chatWin = document.getElementById('simChatWindow');
    const sendBtn = document.getElementById('simSendBtn');

    // Append User Bubble
    const userBubble = document.createElement('div');
    userBubble.style.cssText = 'align-self: flex-end; max-width: 85%; background: #2563eb; color: white; border-radius: 8px 8px 2px 8px; padding: 0.6rem 0.75rem; font-size: 0.8rem;';
    userBubble.innerHTML = `<strong style="font-size: 0.72rem; display: block; opacity: 0.85; margin-bottom: 2px;">👤 Customer</strong>${escapeHtmlSim(msg)}`;
    chatWin.appendChild(userBubble);
    input.value = '';
    chatWin.scrollTop = chatWin.scrollHeight;

    // Loading indicator
    const loadingBubble = document.createElement('div');
    loadingBubble.id = 'simLoadingBubble';
    loadingBubble.style.cssText = 'align-self: flex-start; max-width: 85%; background: white; border: 1px solid #e2e8f0; border-radius: 8px 8px 8px 2px; padding: 0.6rem 0.75rem; font-size: 0.8rem; color: #64748b;';
    loadingBubble.innerHTML = '<em>Thinking & analyzing with Gemini...</em>';
    chatWin.appendChild(loadingBubble);
    chatWin.scrollTop = chatWin.scrollHeight;

    sendBtn.disabled = true;

    const payload = {
        test_message: msg
    };

    fetch('api/ai-simulator.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(async r => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Server returned invalid JSON: ' + text.substring(0, 150));
        }
    })
    .then(data => {
        const lb = document.getElementById('simLoadingBubble');
        if (lb) lb.remove();
        sendBtn.disabled = false;

        if (data.success === false && data.message) {
            const errBubble = document.createElement('div');
            errBubble.style.cssText = 'align-self: flex-start; max-width: 85%; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.8rem;';
            errBubble.innerHTML = `<strong>Notice:</strong> ${escapeHtmlSim(data.message)}`;
            chatWin.appendChild(errBubble);
            chatWin.scrollTop = chatWin.scrollHeight;
            return;
        }

        const replyContent = data.reply || (data.raw_err ? 'Error: ' + data.raw_err : 'No response generated.');

        const aiBubble = document.createElement('div');
        aiBubble.style.cssText = 'align-self: flex-start; max-width: 85%; background: white; border: 1px solid #e2e8f0; border-radius: 8px 8px 8px 2px; padding: 0.6rem 0.75rem; font-size: 0.8rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);';
        
        let actionBadge = '';
        if (data.action === 'BOOK_DEMO') {
            actionBadge = `<div style="background: rgba(16,185,129,0.12); color: #059669; font-weight: 700; font-size: 0.7rem; padding: 3px 6px; border-radius: 4px; margin-top: 6px;">🎯 Action Triggered: BOOK_DEMO (Auto CRM Lead & Demo will be created)</div>`;
        } else if (data.action === 'SUPPORT_HANDOFF') {
            actionBadge = `<div style="background: rgba(239,68,68,0.12); color: #dc2626; font-weight: 700; font-size: 0.7rem; padding: 3px 6px; border-radius: 4px; margin-top: 6px;">🎫 Action Triggered: SUPPORT_HANDOFF (Auto Support Flow Form sent)</div>`;
        }

        aiBubble.innerHTML = `
            <strong style="color: #10b981; font-size: 0.72rem; display: block; margin-bottom: 2px;">🤖 Marg AI Assistant</strong>
            ${escapeHtmlSim(replyContent).replace(/\n/g, '<br>')}
            ${actionBadge}
        `;
        chatWin.appendChild(aiBubble);
        chatWin.scrollTop = chatWin.scrollHeight;
    })
    .catch(err => {
        const lb = document.getElementById('simLoadingBubble');
        if (lb) lb.remove();
        sendBtn.disabled = false;

        const errBubble = document.createElement('div');
        errBubble.style.cssText = 'align-self: flex-start; max-width: 85%; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.8rem;';
        errBubble.innerHTML = `<strong>Error:</strong> ${escapeHtmlSim(err.message || 'Error communicating with AI engine.')}`;
        chatWin.appendChild(errBubble);
        chatWin.scrollTop = chatWin.scrollHeight;
    });
}

function escapeHtmlSim(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
</script>
