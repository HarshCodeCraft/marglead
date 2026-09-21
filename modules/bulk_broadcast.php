<?php
/**
 * Marg ERP CRM - Bulk Marketing Broadcast Engine & Smart Template Hub
 * High-performance WhatsApp marketing engine with interactive buttons,
 * live phone simulator, dynamic customer variables ({name}, {amount}, {due_date}), and CSV dispatches.
 */

if (!defined('APP_RUNNING')) {
    define('APP_RUNNING', true);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'] ?? 1;

// Fetch WABA settings
try {
    $stmtWaba = $pdo->prepare("SELECT * FROM merchant_waba_settings WHERE user_id = ? LIMIT 1");
    $stmtWaba->execute([$user_id]);
    $merchantWaba = $stmtWaba->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $merchantWaba = null;
}

// Fetch recent dispatch logs
try {
    $stmtLogs = $pdo->prepare("SELECT * FROM marg_erp_logs ORDER BY id DESC LIMIT 25");
    $stmtLogs->execute();
    $recentLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentLogs = [];
}
?>

<style>
.bulk-engine-container {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding-bottom: 2rem;
}

/* Header Card */
.engine-header-card {
    background: var(--bg-card, #ffffff);
    padding: 1.25rem 1.5rem;
    border-radius: 12px;
    border: 1px solid var(--border-color, #e2e8f0);
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

/* Metrics Row */
.engine-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.stat-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1.1rem;
    box-shadow: 0 4px 14px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.stat-val {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--text-main, #0f172a);
}

/* Navigation Bar */
.tab-nav-bar {
    display: flex;
    gap: 0.5rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    padding-bottom: 2px;
    margin-bottom: 1rem;
    overflow-x: auto;
}

.nav-tab-btn {
    padding: 0.6rem 1.1rem;
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: 8px 8px 0 0;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-muted, #64748b);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.nav-tab-btn:hover {
    background: rgba(37, 99, 235, 0.05);
    color: var(--primary, #2563eb);
}

.nav-tab-btn.active {
    background: var(--bg-card, #ffffff);
    color: var(--primary, #2563eb);
    border-color: var(--border-color, #e2e8f0);
    border-bottom-color: var(--bg-card, #ffffff);
    box-shadow: 0 -2px 6px rgba(0,0,0,0.03);
}

/* Campaign Card List */
.campaign-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.campaign-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.campaign-title {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-main, #0f172a);
}

.progress-bar-bg {
    width: 100%;
    height: 10px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #10b981, #059669);
    width: 0%;
    transition: width 0.4s ease;
}

/* Template Cards Grid */
.templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.1rem;
}

.template-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 14px;
    padding: 1.25rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 0.85rem;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.template-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}

.template-header-tag {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    display: inline-block;
    margin-bottom: 4px;
}

.template-body-preview {
    font-size: 0.82rem;
    line-height: 1.45;
    color: var(--text-main, #1e293b);
    background: var(--bg-app, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    padding: 0.75rem;
    border-radius: 8px;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 140px;
    overflow-y: auto;
}

.template-buttons-preview {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
}

.template-btn-pill {
    background: #ffffff;
    border: 1px solid #3b82f6;
    color: #2563eb;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 6px 12px;
    border-radius: 8px;
    text-align: center;
}

/* Modals */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(5px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 1rem;
    opacity: 0;
    pointer-events: none;
}

.modal-overlay.active,
.modal-overlay.open {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
}

.ai-preset-chip {
    background: rgba(37, 99, 235, 0.08);
    border: 1px solid rgba(37, 99, 235, 0.25);
    color: var(--primary, #2563eb);
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    cursor: pointer;
}

.ai-preset-chip:hover {
    background: #2563eb;
    color: white;
}

.modal-box-lg {
    background: var(--bg-card, #ffffff);
    border-radius: 20px;
    width: 100%;
    max-width: 1060px;
    padding: 1.75rem;
    box-shadow: 0 25px 60px -15px rgba(0,0,0,0.25), 0 0 0 1px var(--border-color, #e2e8f0);
    display: grid;
    grid-template-columns: 1.15fr 360px;
    gap: 1.75rem;
    max-height: 94vh;
    overflow-y: auto;
}

@media (max-width: 900px) {
    .modal-box-lg {
        grid-template-columns: 1fr;
    }
}

/* Custom styled inputs for form */
.input-styled {
    width: 100%;
    padding: 0.65rem 0.85rem;
    border: 1.5px solid var(--border-color, #cbd5e1);
    border-radius: 10px;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #0f172a);
    font-size: 0.82rem;
    outline: none;
    box-sizing: border-box;
    transition: all 0.2s ease;
    line-height: 1.45;
    font-family: inherit;
}

.input-styled:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.input-styled::placeholder {
    color: #94a3b8;
}

/* AI Assistant Card */
.ai-assistant-card {
    background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    border: 1.5px solid #bfdbfe;
    border-radius: 14px;
    padding: 1rem;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.05);
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}

/* AI Preset Chips */
.ai-preset-chip {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.ai-preset-chip:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
}

/* Variable Pills */
.var-pill-btn {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #1e293b;
    padding: 3px 9px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.var-pill-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #1d4ed8;
    transform: translateY(-1px);
}

/* Authentic WhatsApp Phone Simulator Mockup */
.wa-phone-mockup {
    background: #efeae2;
    border: 10px solid #1e293b;
    border-radius: 38px;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: 520px;
    position: relative;
}

.wa-phone-notch {
    background: #1e293b;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 0 10px;
}

.notch-speaker {
    width: 44px;
    height: 3.5px;
    background: #334155;
    border-radius: 4px;
}

.notch-cam {
    width: 6px;
    height: 6px;
    background: #0f172a;
    border-radius: 50%;
    border: 1px solid #334155;
}

.wa-phone-header {
    background: #008069;
    padding: 9px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.wa-phone-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #059669;
    border: 1.5px solid rgba(255,255,255,0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    color: white;
    font-size: 0.85rem;
    flex-shrink: 0;
}

.wa-phone-body {
    background: #efeae2 url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-835d-d93a777afe46.png');
    background-size: 320px auto;
    background-repeat: repeat;
    flex: 1;
    padding: 12px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}

.wa-date-divider {
    align-self: center;
    background: rgba(255, 255, 255, 0.85);
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 0.65rem;
    font-weight: 700;
    color: #54656f;
    margin-bottom: 10px;
    box-shadow: 0 1px 1px rgba(11,20,26,0.1);
    text-transform: uppercase;
}

.wa-msg-bubble {
    background: #ffffff;
    color: #111b21;
    border-radius: 8px 8px 8px 2px;
    padding: 10px 12px 6px 12px;
    font-size: 0.81rem;
    line-height: 1.48;
    box-shadow: 0 1px 2px rgba(11,20,26,0.15);
    position: relative;
    max-width: 95%;
    align-self: flex-start;
}

.wa-msg-header {
    font-weight: 700;
    color: #008069;
    margin-bottom: 6px;
    font-size: 0.82rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.wa-msg-footer {
    font-size: 0.7rem;
    color: #667781;
    margin-top: 6px;
    border-top: 1px dashed #e2e8f0;
    padding-top: 4px;
}

.wa-msg-time {
    font-size: 0.65rem;
    color: #667781;
    text-align: right;
    margin-top: 4px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
}

.wa-btn-container {
    display: flex;
    flex-direction: column;
    gap: 1px;
    margin-top: 8px;
    margin-left: -12px;
    margin-right: -12px;
    margin-bottom: -6px;
    border-top: 1px solid #e9edef;
    background: #ffffff;
    border-radius: 0 0 8px 2px;
    overflow: hidden;
}

.wa-interactive-btn {
    background: #ffffff;
    border: none;
    border-bottom: 1px solid #e9edef;
    color: #00a884;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 8px 10px;
    text-align: center;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.15s ease;
}

.wa-interactive-btn:last-child {
    border-bottom: none;
}

.wa-interactive-btn:hover {
    background: #f7f8fa;
}

 || '')}">Failed</span>`;

                html += `
                <tr>
                    <td><strong>+${a.mobile}</strong></td>
                    <td>${escapeHtml(a.customer_name || 'N/A')}</td>
                    <td>${escapeHtml(a.company_name || 'N/A')}</td>
                    <td>${stBadge}</td>
                    <td>${a.sent_at || '-'}</td>
                </tr>
                `;
            });
            body.innerHTML = html || `<tr><td colspan="5" style="text-align:center;">No contacts found.</td></tr>`;
        }
    });
}

function closeAudienceModal() {
    const modal = document.getElementById('audienceModal');
    if (modal) {
        modal.classList.remove('active');
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

const bulkCampaignCooldowns = {};

function runActiveCampaignsLoop() {
    if (runningCampaignIds.size === 0) return;

    const now = Date.now();

    runningCampaignIds.forEach(id => {
        const nextAllowed = bulkCampaignCooldowns[id] || 0;
        if (now < nextAllowed) return;

        bulkCampaignCooldowns[id] = now + 999999;

        fetch(`api/campaign-api.php?action=process_batch&id=${id}&batch_size=1`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.campaign) {
                if (data.status === 'completed' || data.campaign.status === 'completed' || data.status === 'paused') {
                    runningCampaignIds.delete(id);
                    delete bulkCampaignCooldowns[id];
                    fetchCampaigns();
                    return;
                }
                const delaySec = parseInt(data.delay_seconds !== undefined ? data.delay_seconds : (data.campaign.delay_seconds || 0));
                bulkCampaignCooldowns[id] = Date.now() + (Math.max(1, delaySec) * 1000);
            } else {
                bulkCampaignCooldowns[id] = Date.now() + 10000;
            }
        })
        .catch(err => {
            console.error(err);
            bulkCampaignCooldowns[id] = Date.now() + 15000;
        });
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;").replace(/\n/g, '<br>');
}

function syncMetaTemplates() {
    const btn = document.getElementById('btnSyncMeta');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:13px; height:13px;"></i> Syncing from Meta...`;
    }

    fetch('api/campaign-api.php?action=sync_meta_templates')
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px;"></i> <span>Sync Meta Approved Templates</span>`;
        }

        if (data.success) {
            alert(data.message);
            if (typeof fetchTemplates === 'function') fetchTemplates();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px;"></i> <span>Sync Meta Approved Templates</span>`;
        }
        alert('Network error syncing templates from Meta.');
    });
}
</script>
