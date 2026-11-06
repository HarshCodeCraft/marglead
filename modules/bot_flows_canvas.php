<?php
/**
 * Marg Soft Solution - Visual WhatsApp Bot & Flow Automation Builder
 * Unified Interactive Canvas Workspace with Multi-Journey Switcher, Cable Routing, and Live Simulation.
 */

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
}

$allWorkflows = [];
if ($db_connected && $pdo) {
    try {
        $stmtWfs = $pdo->query("SELECT id, name, trigger_keyword, status, nodes_json, connections_json FROM bot_flow_automations ORDER BY id ASC");
        $allWorkflows = $stmtWfs ? $stmtWfs->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable $e) {}
}

// Select active workflow (from $_GET['id'] or default to first)
$activeWfId = intval($_GET['id'] ?? ($allWorkflows[0]['id'] ?? 1));
$activeWf = null;
foreach ($allWorkflows as $w) {
    if ((int)$w['id'] === $activeWfId) {
        $activeWf = $w;
        break;
    }
}
if (!$activeWf && !empty($allWorkflows)) {
    $activeWf = $allWorkflows[0];
    $activeWfId = (int)$activeWf['id'];
}
?>

<div class="canvas-module-wrapper">
    <!-- Top Action Toolbar -->
    <div class="canvas-top-bar">
        <!-- Left: Workflow Switcher & Management -->
        <div class="canvas-title-group">
            <div class="canvas-title-flex">
                <i data-lucide="workflow" style="width: 22px; height: 22px; color: #3b82f6; flex-shrink: 0;"></i>
                
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <label class="text-xs font-semibold text-muted" style="margin: 0;">Journey:</label>
                    <select id="activeWorkflowSelector" class="input-styled font-bold text-xs" style="min-width: 260px; max-width: 320px; font-size: 0.85rem;" onchange="switchWorkflow(this.value)">
                        <?php foreach ($allWorkflows as $wf): ?>
                            <option value="<?php echo $wf['id']; ?>" <?php echo ((int)$wf['id'] === $activeWfId) ? 'selected' : ''; ?>>
                                #WF-<?php echo sprintf('%02d', $wf['id']); ?>: <?php echo htmlspecialchars($wf['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="openCreateWorkflowModal()" title="Create New Flow Automation">
                        <i data-lucide="plus" style="width: 13px; height: 13px; color: #10b981;"></i>
                        <span>New</span>
                    </button>

                    <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="cloneCurrentWorkflow()" title="Duplicate Current Workflow">
                        <i data-lucide="copy" style="width: 13px; height: 13px;"></i>
                    </button>

                    <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="deleteCurrentWorkflow()" title="Delete Current Workflow" style="color: #ef4444;">
                        <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                    </button>

                    <button type="button" onclick="toggleCurrentWorkflowStatus()" style="background: none; border: none; cursor: pointer; padding: 0;" title="Click to toggle status">
                        <span id="canvasStatusBadge" class="status-badge <?php echo (($activeWf['status'] ?? 'ACTIVE') === 'ACTIVE') ? 'status-badge-published' : 'status-badge-draft'; ?>">
                            <?php echo htmlspecialchars($activeWf['status'] ?? 'ACTIVE'); ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Right: Canvas Tools & Actions -->
        <div class="canvas-actions-group">
            <div class="canvas-zoom-controls">
                <button type="button" class="btn-canvas-tool" onclick="zoomCanvas(-0.1)" title="Zoom Out">
                    <i data-lucide="minus" style="width: 14px; height: 14px;"></i>
                </button>
                <span id="canvasZoomLabel" class="canvas-zoom-text font-mono">100%</span>
                <button type="button" class="btn-canvas-tool" onclick="zoomCanvas(0.1)" title="Zoom In">
                    <i data-lucide="plus" style="width: 14px; height: 14px;"></i>
                </button>
                <button type="button" class="btn-canvas-tool" onclick="resetCanvasView()" title="Reset Canvas View">
                    <i data-lucide="maximize-2" style="width: 14px; height: 14px;"></i>
                </button>
            </div>

            <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="syncMetaApprovedResources()" id="btnSyncMetaCanvas" title="Sync live approved templates and published flows from Meta Graph API">
                <i data-lucide="refresh-cw" style="width: 14px; height: 14px; color: #3b82f6;"></i>
                <span>Sync Meta</span>
            </button>

            <div class="dropdown" style="position: relative; display: inline-block;">
                <button type="button" class="btn-pill btn-pill-outline" onclick="toggleAddNodeDropdown(event)">
                    <i data-lucide="plus-circle" style="width: 14px; height: 14px; color: #10b981;"></i>
                    <span>+ Add Block</span>
                    <i data-lucide="chevron-down" style="width: 12px; height: 12px;"></i>
                </button>
                <div id="addNodeDropdownMenu" class="canvas-dropdown-menu" style="display: none;">
                    <a href="javascript:void(0)" onclick="addNode('trigger')" class="dropdown-item-flex">
                        <i data-lucide="zap" style="color: #f59e0b; width: 14px; height: 14px;"></i>
                        <div>
                            <strong>Keyword Trigger</strong>
                            <div class="text-xs text-muted">Customer incoming keywords</div>
                        </div>
                    </a>
                    <a href="javascript:void(0)" onclick="addNode('template')" class="dropdown-item-flex">
                        <i data-lucide="message-square" style="color: #3b82f6; width: 14px; height: 14px;"></i>
                        <div>
                            <strong>Meta Template Message</strong>
                            <div class="text-xs text-muted">Interactive message with action buttons</div>
                        </div>
                    </a>
                    <a href="javascript:void(0)" onclick="addNode('flow')" class="dropdown-item-flex">
                        <i data-lucide="git-pull-request" style="color: #10b981; width: 14px; height: 14px;"></i>
                        <div>
                            <strong>Meta WhatsApp Flow</strong>
                            <div class="text-xs text-muted">Native form (Tickets, Demo, Sales)</div>
                        </div>
                    </a>
                    <a href="javascript:void(0)" onclick="addNode('action')" class="dropdown-item-flex">
                        <i data-lucide="check-circle" style="color: #8b5cf6; width: 14px; height: 14px;"></i>
                        <div>
                            <strong>CRM Action / Ticket</strong>
                            <div class="text-xs text-muted">Auto-create ticket or lead</div>
                        </div>
                    </a>
                </div>
            </div>

            <button type="button" class="btn-pill btn-pill-outline" onclick="openSimulationModal()">
                <i data-lucide="play" style="width: 14px; height: 14px; color: #f59e0b;"></i>
                <span>Test Flow</span>
            </button>

            <button type="button" class="btn-pill btn-pill-outline text-xs" onclick="saveWorkflowAutomation(true)" title="Save current canvas blocks as a new flow automation journey">
                <i data-lucide="plus-circle" style="width: 13px; height: 13px; color: #10b981;"></i>
                <span>Save as New</span>
            </button>

            <button type="button" id="btnSaveWorkflow" class="btn-pill btn-pill-dark" onclick="saveWorkflowAutomation(false)" title="Save changes to current flow automation">
                <i data-lucide="save" style="width: 14px; height: 14px;"></i>
                <span>Save Changes</span>
            </button>
        </div>
    </div>

    <!-- Secondary Info Bar -->
    <div class="canvas-sub-header">
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <span class="status-indicator-dot"></span>
            <span class="text-xs font-semibold text-muted">Automation Title:</span>
            <input type="text" id="canvasWorkflowName" class="workflow-name-input font-bold" value="<?php echo htmlspecialchars($activeWf['name'] ?? 'Master WhatsApp Support & Sales Flow'); ?>" title="Click to rename workflow">
            <span class="text-xs text-muted">|</span>
            <span class="text-xs text-muted">Trigger Keywords:</span>
            <input type="text" id="canvasWorkflowKeywords" class="workflow-keywords-input font-mono text-xs" value="<?php echo htmlspecialchars($activeWf['trigger_keyword'] ?? 'HI, SUPPORT, TICKET'); ?>" placeholder="e.g. TICKET, DEMO" title="Keywords that activate this flow">
        </div>
        <div class="text-xs text-muted">
            Tip: Drag ports on the right to connect with ports on the left. Click any cable to remove it.
        </div>
    </div>

    <!-- Infinite Canvas Viewport -->
    <div class="canvas-viewport" id="canvasViewport">
        <!-- Dot-grid background layer -->
        <div class="canvas-grid-stage" id="canvasGridStage">
            <!-- SVG Cable Lines Layer -->
            <svg id="canvasSvgLayer" class="canvas-svg-layer"></svg>

            <!-- Dynamic Nodes Container -->
            <div id="canvasNodesContainer" class="canvas-nodes-container"></div>
        </div>
    </div>
</div>

<!-- Modal: Create New Automation -->
<div id="createWorkflowModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; width: 100%; max-width: 480px; box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="workflow" style="width: 18px; height: 18px; color: #3b82f6;"></i>
                <h3 style="margin: 0; font-family: var(--font-heading); font-size: 1.15rem;">Create New Flow Automation</h3>
            </div>
            <button type="button" onclick="closeCreateWorkflowModal()" style="background: none; border: none; font-size: 1.35rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <form id="createWorkflowForm" onsubmit="submitCreateWorkflow(event)" style="display: flex; flex-direction: column; gap: 1rem;">
            <div>
                <label class="form-label font-semibold text-xs mb-1">Automation Journey Name *</label>
                <input type="text" id="newWfName" class="input-styled font-bold text-xs" required placeholder="e.g. Sales Enquiry & Demo Flow">
            </div>

            <div>
                <label class="form-label font-semibold text-xs mb-1">Trigger Keywords (Comma separated)</label>
                <input type="text" id="newWfKeywords" class="input-styled text-xs font-mono" placeholder="e.g. DEMO, BUY, PRICING, DISCOUNT">
                <span class="text-xs text-muted">Customer typing any of these keywords will activate this workflow.</span>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 0.5rem;">
                <button type="button" class="btn-pill btn-pill-outline" onclick="closeCreateWorkflowModal()">Cancel</button>
                <button type="submit" class="btn-pill btn-pill-dark">
                    <i data-lucide="arrow-right" style="width: 14px; height: 14px;"></i>
                    <span>Create Automation</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Test Simulation -->
<div id="simModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: var(--bg-card, #ffffff); border: 1px solid var(--border-color); border-radius: 14px; padding: 1.5rem; width: 100%; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="smartphone" style="width: 18px; height: 18px; color: #10b981;"></i>
                <h3 style="margin: 0; font-size: 1.1rem; font-family: var(--font-heading);">WhatsApp Flow Simulator</h3>
            </div>
            <button type="button" onclick="closeSimulationModal()" style="background: none; border: none; font-size: 1.35rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <p class="text-xs text-muted mb-3">Type an incoming customer message to test your automated flow execution path in real time.</p>

        <div class="sim-chat-window" id="simChatWindow">
            <div class="sim-chat-bubble-system">
                Simulation session initiated. Send a test keyword (e.g. <code>TICKET</code>, <code>SUPPORT</code>, or <code>DEMO</code>).
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
            <input type="text" id="simInputMessage" class="input-styled font-bold text-xs" placeholder="Type a message (e.g. TICKET, DEMO)..." onkeypress="if(event.key==='Enter') executeSimStep()">
            <button type="button" class="btn-pill btn-pill-primary" onclick="executeSimStep()">
                <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                Send
            </button>
        </div>
    </div>
</div>

<style>
/* Modern Unified Canvas Styling */
.canvas-module-wrapper {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    height: calc(100vh - 120px);
    min-height: 650px;
    position: relative;
    user-select: none;
}

.canvas-top-bar {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 0.75rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}
.canvas-title-flex {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.canvas-actions-group {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
}

.canvas-zoom-controls {
    display: flex;
    align-items: center;
    background: var(--bg-body, rgba(0,0,0,0.04));
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 2px 4px;
}
.btn-canvas-tool {
    background: none;
    border: none;
    padding: 5px 8px;
    cursor: pointer;
    color: var(--text-muted);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-canvas-tool:hover {
    background: rgba(0,0,0,0.05);
    color: var(--text-main);
}
.canvas-zoom-text {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0 6px;
    min-width: 44px;
    text-align: center;
    color: var(--text-main);
}

.canvas-dropdown-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    min-width: 250px;
    z-index: 1000;
    padding: 0.35rem;
}
.dropdown-item-flex {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.65rem 0.85rem;
    text-decoration: none;
    color: var(--text-main);
    border-radius: 6px;
    transition: background 0.15s ease;
}
.dropdown-item-flex:hover {
    background: var(--bg-body, rgba(0,0,0,0.04));
}

.canvas-sub-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.3rem 0.5rem;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.status-indicator-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    display: inline-block;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
}
.workflow-name-input {
    background: transparent;
    border: 1px dashed transparent;
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--text-main);
    padding: 2px 6px;
    border-radius: 4px;
    min-width: 250px;
}
.workflow-name-input:hover, .workflow-name-input:focus {
    border-color: var(--border-color);
    background: var(--bg-card);
    outline: none;
}
.workflow-keywords-input {
    background: transparent;
    border: 1px dashed transparent;
    color: var(--primary, #3b82f6);
    padding: 2px 6px;
    border-radius: 4px;
    min-width: 180px;
}
.workflow-keywords-input:hover, .workflow-keywords-input:focus {
    border-color: var(--border-color);
    background: var(--bg-card);
    outline: none;
}

/* Viewport & Grid Stage */
.canvas-viewport {
    flex: 1;
    border: 1px solid var(--border-color);
    border-radius: 14px;
    background-color: var(--bg-body, #f8fafc);
    background-image: radial-gradient(var(--border-color, #cbd5e1) 1.2px, transparent 1.2px);
    background-size: 24px 24px;
    overflow: hidden;
    position: relative;
    cursor: grab;
}
.canvas-viewport.panning {
    cursor: grabbing;
}
.canvas-grid-stage {
    position: absolute;
    top: 0;
    left: 0;
    width: 5000px;
    height: 5000px;
    transform-origin: 0 0;
}
.canvas-svg-layer {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 1;
}
.cable-path {
    fill: none;
    stroke: #64748b;
    stroke-width: 2.5px;
    stroke-linecap: round;
    pointer-events: stroke;
    cursor: pointer;
    transition: stroke 0.2s ease, stroke-width 0.2s ease;
}
.cable-path:hover {
    stroke: #ef4444;
    stroke-width: 4px;
}
.cable-path.active-pulse {
    stroke: #10b981;
    stroke-width: 3.5px;
    animation: cablePulse 1.2s infinite ease-in-out;
}
@keyframes cablePulse {
    0% { stroke-dasharray: 6 6; stroke-dashoffset: 24; }
    100% { stroke-dasharray: 6 6; stroke-dashoffset: 0; }
}

/* Node Elements */
.canvas-nodes-container {
    position: absolute;
    inset: 0;
    z-index: 2;
    pointer-events: none;
}
.canvas-node {
    position: absolute;
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 12px;
    width: 310px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    pointer-events: auto;
    cursor: default;
    transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.canvas-node:hover {
    box-shadow: 0 12px 30px rgba(0,0,0,0.12);
}
.canvas-node.sim-highlight {
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.4) !important;
}

.node-header {
    padding: 0.65rem 0.85rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-top-left-radius: 11px;
    border-top-right-radius: 11px;
    cursor: grab;
    background: var(--bg-body, rgba(0,0,0,0.02));
}
.node-header:active {
    cursor: grabbing;
}
.node-title-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.node-title-text {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-main);
}
.node-btn-delete {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 1.15rem;
    padding: 0 3px;
    line-height: 1;
}
.node-btn-delete:hover {
    color: #ef4444;
}

.node-body {
    padding: 0.85rem;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    font-size: 0.775rem;
}

/* Ports */
.port {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--bg-card, #ffffff);
    border: 2.5px solid #64748b;
    position: absolute;
    cursor: crosshair;
    z-index: 10;
    transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}
.port:hover {
    transform: scale(1.35);
    border-color: #3b82f6;
    background: #3b82f6;
}
.port.port-in {
    left: -8px;
    top: 50%;
    transform: translateY(-50%);
}
.port.port-in:hover {
    transform: translateY(-50%) scale(1.35);
}
.port.port-out {
    right: -8px;
    top: 50%;
    transform: translateY(-50%);
}
.port.port-out:hover {
    transform: translateY(-50%) scale(1.35);
}
.port-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 4px 0;
    position: relative;
}
.port-row .port-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-muted);
}

/* Chat Simulator Window */
.sim-chat-window {
    height: 260px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    background: var(--bg-body, #f8fafc);
    padding: 0.85rem;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.sim-chat-bubble-system {
    background: rgba(0,0,0,0.05);
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    font-size: 0.75rem;
    color: var(--text-muted);
    text-align: center;
}
.sim-chat-bubble-user {
    align-self: flex-end;
    background: #25D366;
    color: #ffffff;
    padding: 0.5rem 0.85rem;
    border-radius: 10px 10px 0 10px;
    font-size: 0.8rem;
    font-weight: 600;
    max-width: 80%;
}
.sim-chat-bubble-bot {
    align-self: flex-start;
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    padding: 0.6rem 0.85rem;
    border-radius: 10px 10px 10px 0;
    font-size: 0.8rem;
    max-width: 85%;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
}
</style>

<script>
// =========================================================================
// Global Utility & Safety Functions (Zero ReferenceError)
// =========================================================================
window.escapeHtml = window.escapeHtml || function(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};
var escapeHtml = window.escapeHtml;

let currentWorkflowId = <?php echo $activeWfId; ?>;
let canvasScale = 1.0;
let canvasPanX = 40;
let canvasPanY = 40;
let isPanning = false;
let startPanMouseX = 0;
let startPanMouseY = 0;

let workflowNodes = [];
let workflowConnections = [];
let availableFlows = [];
let availableTemplates = [];

let activeDragPort = null;
let activeDraggingNode = null;
let dragNodeOffsetX = 0;
let dragNodeOffsetY = 0;

function initCanvasModule() {
    initCanvasEvents();
    loadCanvasResources(() => {
        loadWorkflowCanvasData(currentWorkflowId);
    });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCanvasModule);
} else {
    initCanvasModule();
}

// 1. Resources & Workflow Loading
function loadCanvasResources(cb) {
    fetch('api/bot_flows.php?action=get_canvas_resources')
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            availableFlows = data.flows || [];
            availableTemplates = data.templates || [];
        }
        if (typeof cb === 'function') cb();
    })
    .catch(() => {
        if (typeof cb === 'function') cb();
    });
}

function syncMetaApprovedResources() {
    const btn = document.getElementById('btnSyncMetaCanvas');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader-2" style="width:13px; height:13px; margin-right:4px;"></i> <span>Syncing...</span>`;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    fetch('api/bot_flows.php?action=sync_meta_canvas_resources')
    .then(res => res.json())
    .then(result => {
        loadCanvasResources(() => {
            alert(result.message || 'Meta resources synced successfully!');
            renderAllNodes();
            renderAllCables();
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    })
    .catch(err => {
        alert('Network error syncing from Meta: ' + err.message);
    })
    .finally(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="refresh-cw" style="width:13px; height:13px; color:#3b82f6; margin-right:4px;"></i> <span>Sync Meta</span>`;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    });
}

function loadWorkflowCanvasData(id) {
    const url = 'api/bot_flows.php?action=get_workflow' + (id > 0 ? '&id=' + id : '');
    fetch(url)
    .then(res => res.json())
    .then(data => {
        if (data.success && data.workflow) {
            const wf = data.workflow;
            currentWorkflowId = wf.id;
            
            const nameEl = document.getElementById('canvasWorkflowName');
            if (nameEl) nameEl.value = wf.name || 'New Flow Automation';

            const kwEl = document.getElementById('canvasWorkflowKeywords');
            if (kwEl) kwEl.value = wf.trigger_keyword || 'HI, SUPPORT, TICKET';

            const badge = document.getElementById('canvasStatusBadge');
            if (badge) {
                badge.innerText = wf.status || 'ACTIVE';
                badge.className = 'status-badge ' + (wf.status === 'ACTIVE' ? 'status-badge-published' : 'status-badge-draft');
            }

            workflowNodes = wf.nodes || [];
            workflowConnections = wf.connections || [];
            renderAllNodes();
            renderAllCables();
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    })
    .catch(err => {
        console.error('Failed loading workflow data:', err);
    });
}

function switchWorkflow(id) {
    if (!id) return;
    currentWorkflowId = parseInt(id, 10);
    // Update URL query string without reloading page
    const newUrl = new URL(window.location.href);
    newUrl.searchParams.set('id', currentWorkflowId);
    window.history.pushState({}, '', newUrl);

    loadWorkflowCanvasData(currentWorkflowId);
}

// 2. Render Nodes on Canvas
function renderAllNodes() {
    const container = document.getElementById('canvasNodesContainer');
    if (!container) return;
    container.innerHTML = '';

    workflowNodes.forEach(node => {
        const el = document.createElement('div');
        el.className = 'canvas-node';
        el.id = node.id;
        el.style.left = node.x + 'px';
        el.style.top = node.y + 'px';

        let iconName = 'zap';
        let iconColor = '#f59e0b';
        let badgeText = 'TRIGGER';
        let badgeBg = 'rgba(245,158,11,0.15)';

        if (node.type === 'template') {
            iconName = 'message-square';
            iconColor = '#3b82f6';
            badgeText = 'META TEMPLATE';
            badgeBg = 'rgba(59,130,246,0.15)';
        } else if (node.type === 'flow') {
            iconName = 'git-pull-request';
            iconColor = '#10b981';
            badgeText = 'META FLOW';
            badgeBg = 'rgba(16,185,129,0.15)';
        } else if (node.type === 'action') {
            iconName = 'check-circle';
            iconColor = '#8b5cf6';
            badgeText = 'CRM ACTION';
            badgeBg = 'rgba(139,92,246,0.15)';
        }

        let bodyHtml = '';
        if (node.type === 'trigger') {
            bodyHtml = `
                <div class="text-xs text-muted mb-1 font-semibold">Trigger Keywords:</div>
                <input type="text" class="input-styled text-xs font-bold" value="${escapeHtml(node.data?.keywords || '')}" onchange="updateNodeData('${node.id}', 'keywords', this.value)">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:6px;">
                    <span class="text-xs text-muted">Match:</span>
                    <select class="input-styled text-xs" style="width:130px; padding:3px 6px;" onchange="updateNodeData('${node.id}', 'match_type', this.value)">
                        <option value="contains" ${node.data?.match_type === 'contains' ? 'selected' : ''}>Contains</option>
                        <option value="exact" ${node.data?.match_type === 'exact' ? 'selected' : ''}>Exact Match</option>
                    </select>
                </div>
                <div class="port-row mt-2" style="justify-content: flex-end;">
                    <span class="port-label mr-2">Trigger Out</span>
                    <div class="port port-out" data-node="${node.id}" data-port="out" onmousedown="startCableDrag(event, '${node.id}', 'out')" title="Connect to next step"></div>
                </div>
            `;
        } else if (node.type === 'template') {
            let tplOpts = '';
            if (!availableTemplates || availableTemplates.length === 0) {
                tplOpts = `<option value="">No Approved Templates Found (Click Sync Meta)</option>`;
            } else {
                tplOpts = `<option value="">-- Select Meta Approved Template --</option>`;
                availableTemplates.forEach(t => {
                    const isSel = (node.data?.slug === t.slug || node.data?.template_name === t.slug || node.data?.template_name === t.title);
                    tplOpts += `<option value="${escapeHtml(t.slug)}" ${isSel ? 'selected' : ''}>${escapeHtml(t.title)} (${escapeHtml(t.slug)})</option>`;
                });
            }

            let btnsHtml = '';
            const nodeBtns = node.data?.buttons || [];
            if (nodeBtns.length > 0) {
                nodeBtns.forEach(btn => {
                    const bId = btn.id || 'btn_action';
                    const bTitle = btn.title || btn.label || 'Action';
                    const bType = btn.type || 'BUTTON';
                    btnsHtml += `
                        <div class="port-row">
                            <div style="display:flex; align-items:center; gap:6px; max-width:85%;">
                                <span class="badge font-bold" style="background:rgba(59,130,246,0.1); color:#3b82f6; font-size:0.62rem; padding:2px 5px;">${escapeHtml(bType)}</span>
                                <span class="port-label font-bold" style="color:var(--text-main); font-size:0.75rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">[ ${escapeHtml(bTitle)} ]</span>
                            </div>
                            <div class="port port-out" data-node="${node.id}" data-port="${escapeHtml(bId)}" onmousedown="startCableDrag(event, '${node.id}', '${escapeHtml(bId)}')" title="Connect this button action"></div>
                        </div>
                    `;
                });
            } else {
                btnsHtml = `
                    <div class="port-row" style="justify-content: flex-end;">
                        <span class="port-label mr-2">Default Out</span>
                        <div class="port port-out" data-node="${node.id}" data-port="out" onmousedown="startCableDrag(event, '${node.id}', 'out')" title="Connect to next step"></div>
                    </div>
                `;
            }

            bodyHtml = `
                <div class="port port-in" data-node="${node.id}" data-port="in" onmouseup="finishCableDrag(event, '${node.id}', 'in')" title="Inbound trigger"></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                    <span class="text-xs text-muted font-semibold">Meta Approved Template:</span>
                    <span class="badge" style="background:rgba(16,185,129,0.15); color:#10b981; font-size:0.65rem; font-weight:700;">APPROVED</span>
                </div>
                <select class="input-styled text-xs font-bold" style="width:100%; margin-bottom:8px;" onchange="onSelectApprovedTemplate('${node.id}', this.value)">
                    ${tplOpts}
                </select>
                ${node.data?.header_text ? `<div class="text-xs text-muted font-semibold mb-1">Header: <span class="font-normal text-main">${escapeHtml(node.data.header_text)}</span></div>` : ''}
                <div class="text-xs text-muted mb-1 font-semibold">Body Preview:</div>
                <div style="background:var(--bg-body, rgba(0,0,0,0.02)); border:1px solid var(--border-color); border-radius:6px; padding:7px 9px; font-size:0.75rem; color:var(--text-main); max-height:85px; overflow-y:auto; line-height:1.4; white-space:pre-wrap;">${escapeHtml(node.data?.body_text || 'Select an approved template from the dropdown above.')}</div>
                ${node.data?.footer_text ? `<div class="text-xs text-muted mt-1" style="font-style:italic;">Footer: ${escapeHtml(node.data.footer_text)}</div>` : ''}
                <div class="text-xs font-semibold text-muted mt-2 mb-1">Button Action Ports:</div>
                ${btnsHtml}
            `;
        } else if (node.type === 'flow') {
            let flowOpts = '';
            if (!availableFlows || availableFlows.length === 0) {
                flowOpts = `<option value="">No Published Flows Found (Click Sync Meta)</option>`;
            } else {
                flowOpts = `<option value="">-- Select Meta Published Flow --</option>`;
                availableFlows.forEach(f => {
                    const isSel = (node.data?.flow_id === f.flow_id);
                    flowOpts += `<option value="${escapeHtml(f.flow_id)}" ${isSel ? 'selected' : ''}>${escapeHtml(f.name)} (${escapeHtml(f.flow_id)})</option>`;
                });
            }

            const currentFlow = availableFlows.find(f => f.flow_id === node.data?.flow_id);
            let screenOpts = '';
            const screensList = (currentFlow && currentFlow.screens && currentFlow.screens.length > 0) ? currentFlow.screens : (node.data?.screens || []);
            if (screensList.length > 0) {
                screensList.forEach(sc => {
                    const scId = sc.id || 'WELCOME_SCREEN';
                    const scTitle = sc.title || sc.name || scId;
                    screenOpts += `<option value="${escapeHtml(scId)}" ${node.data?.screen === scId ? 'selected' : ''}>${escapeHtml(scTitle)} (${escapeHtml(scId)})</option>`;
                });
            } else {
                screenOpts = `<option value="${node.data?.screen || 'WELCOME_SCREEN'}">${escapeHtml(node.data?.screen || 'WELCOME_SCREEN')}</option>`;
            }

            bodyHtml = `
                <div class="port port-in" data-node="${node.id}" data-port="in" onmouseup="finishCableDrag(event, '${node.id}', 'in')" title="Input from button"></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                    <span class="text-xs text-muted font-semibold">Meta WhatsApp Flow:</span>
                    <span class="badge" style="background:rgba(16,185,129,0.15); color:#10b981; font-size:0.65rem; font-weight:700;">PUBLISHED</span>
                </div>
                <select class="input-styled text-xs font-bold" style="width:100%; margin-bottom:8px;" onchange="onSelectApprovedFlow('${node.id}', this.value)">
                    ${flowOpts}
                </select>
                <div class="text-xs text-muted mb-1 font-semibold">Starting Screen:</div>
                <select class="input-styled text-xs font-mono" style="width:100%; margin-bottom:8px;" onchange="updateNodeData('${node.id}', 'screen', this.value)">
                    ${screenOpts}
                </select>
                <div class="text-xs text-muted mb-2" style="display:flex; align-items:center; justify-content:space-between;">
                    <span>Category:</span>
                    <span class="font-bold text-main">${escapeHtml(node.data?.category || currentFlow?.category || 'OTHER')}</span>
                </div>
                <div class="text-xs text-muted font-semibold mb-1">Card Header Title:</div>
                <input type="text" class="input-styled text-xs font-bold mb-2" style="width:100%;" placeholder="e.g. Schedule Live Demo / Support Desk" value="${escapeHtml(node.data?.header_text || '')}" oninput="updateNodeData('${node.id}', 'header_text', this.value)">
                <div class="text-xs text-muted font-semibold mb-1">Button Label (Max 20 chars):</div>
                <input type="text" class="input-styled text-xs font-bold mb-2" style="width:100%;" maxlength="20" placeholder="e.g. Book Demo / Create Ticket" value="${escapeHtml(node.data?.cta_text || '')}" oninput="updateNodeData('${node.id}', 'cta_text', this.value)">
                <div class="text-xs text-muted font-semibold mb-1">Body Text:</div>
                <textarea class="input-styled text-xs mb-2" style="width:100%; height:46px; resize:vertical;" placeholder="Kripya neeche button par click karke details submit karein:" oninput="updateNodeData('${node.id}', 'body_text', this.value)">${escapeHtml(node.data?.body_text || '')}</textarea>
                <div class="port-row mt-1" style="justify-content: flex-end;">
                    <span class="port-label mr-2">On Form Submit</span>
                    <div class="port port-out" data-node="${node.id}" data-port="out" onmousedown="startCableDrag(event, '${node.id}', 'out')" title="Connect on submission"></div>
                </div>
            `;
        } else if (node.type === 'action') {
            bodyHtml = `
                <div class="port port-in" data-node="${node.id}" data-port="in" onmouseup="finishCableDrag(event, '${node.id}', 'in')" title="Flow submission input"></div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                    <span class="text-xs text-muted font-semibold">CRM Action:</span>
                    <span class="badge" style="background:rgba(139,92,246,0.15); color:#8b5cf6; font-size:0.65rem; font-weight:700;">CRM READY</span>
                </div>
                <select class="input-styled text-xs font-bold" style="width:100%; margin-bottom:8px;" onchange="updateNodeData('${node.id}', 'action_type', this.value)">
                    <option value="create_support_ticket" ${node.data?.action_type === 'create_support_ticket' ? 'selected' : ''}>Create Support Ticket (TK-2026)</option>
                    <option value="create_sales_lead" ${node.data?.action_type === 'create_sales_lead' ? 'selected' : ''}>Create Sales Lead &amp; Demo (Assign Sahil)</option>
                    <option value="send_whatsapp_invoice" ${node.data?.action_type === 'send_whatsapp_invoice' ? 'selected' : ''}>Dispatch Marg ERP Bill PDF</option>
                    <option value="custom_json" ${node.data?.action_type === 'custom_json' ? 'selected' : ''}>Store Custom Flow JSON Data</option>
                </select>
                <div class="text-xs text-muted" style="background:var(--bg-body, rgba(0,0,0,0.03)); padding:8px; border-radius:6px; line-height:1.4;">
                    Bypasses 24-hr session limit via Meta Approved Resolution Template &amp; alerts staff.
                </div>
            `;
        }

        el.innerHTML = `
            <div class="node-header" onmousedown="startNodeDrag(event, '${node.id}')">
                <div class="node-title-group">
                    <i data-lucide="${iconName}" style="width:14px; height:14px; color:${iconColor};"></i>
                    <span class="node-title-text">${escapeHtml(node.title)}</span>
                </div>
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <span class="badge" style="background:${badgeBg}; color:${iconColor}; font-size:0.65rem; font-weight:700;">${badgeText}</span>
                    <button type="button" class="node-btn-delete" onclick="deleteNode('${node.id}')" title="Delete Block">&times;</button>
                </div>
            </div>
            <div class="node-body">
                ${bodyHtml}
            </div>
        `;

        container.appendChild(el);
    });
}

function onSelectApprovedTemplate(nodeId, slug) {
    const node = workflowNodes.find(n => n.id === nodeId);
    if (!node) return;
    const tpl = availableTemplates.find(t => t.slug === slug);
    if (tpl) {
        node.data.slug = tpl.slug;
        node.data.template_id = tpl.id;
        node.data.template_name = tpl.title;
        node.data.header_text = tpl.header_text || '';
        node.data.header_type = tpl.header_type || 'none';
        node.data.header_content = tpl.header_content || '';
        node.data.header_image = tpl.header_content || '';
        node.data.body_text = tpl.body_text || '';
        node.data.footer_text = tpl.footer_text || '';
        node.data.category = tpl.category || 'General';
        node.data.buttons = (tpl.buttons && tpl.buttons.length > 0) ? tpl.buttons : [];
        node.title = tpl.title;
    } else {
        node.data.slug = '';
        node.data.template_name = '';
        node.data.body_text = '';
        node.data.buttons = [];
    }
    renderAllNodes();
    renderAllCables();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function onSelectApprovedFlow(nodeId, flowId) {
    const node = workflowNodes.find(n => n.id === nodeId);
    if (!node) return;
    const flow = availableFlows.find(f => f.flow_id === flowId);
    if (flow) {
        node.data.flow_id = flow.flow_id;
        node.data.flow_name = flow.name;
        node.data.category = flow.category || 'OTHER';
        node.data.status = flow.status || 'PUBLISHED';
        node.data.screens = flow.screens || [];
        node.data.screen = (flow.screens && flow.screens[0]?.id) ? flow.screens[0].id : (node.data.screen || 'WELCOME_SCREEN');
        node.title = flow.name;

        // Auto-suggest clean customer-facing texts if not set
        const fLower = (flow.name || '').toLowerCase();
        if (!node.data.header_text) {
            if (fLower.includes('demo') || fLower.includes('sale')) {
                node.data.header_text = 'Marg ERP Live Demo Booking';
            } else if (fLower.includes('ticket') || fLower.includes('support')) {
                node.data.header_text = 'Marg Technical Support Desk';
            } else {
                node.data.header_text = flow.name;
            }
        }
        if (!node.data.cta_text) {
            if (fLower.includes('demo') || fLower.includes('sale')) {
                node.data.cta_text = 'Book Demo';
            } else if (fLower.includes('ticket') || fLower.includes('support')) {
                node.data.cta_text = 'Create Ticket';
            } else {
                node.data.cta_text = 'Open Form';
            }
        }
        if (!node.data.body_text) {
            if (fLower.includes('demo') || fLower.includes('sale')) {
                node.data.body_text = 'Apna live product demo aur pricing walkthrough schedule karne ke liye neeche button par click karein:';
            } else if (fLower.includes('ticket') || fLower.includes('support')) {
                node.data.body_text = 'Kripya neeche button par click karke apni technical problem submit karein, hamari support team turant connect karegi:';
            } else {
                node.data.body_text = 'Kripya neeche diye gaye button par click karke details submit karein:';
            }
        }
    } else {
        node.data.flow_id = '';
        node.data.flow_name = '';
    }
    renderAllNodes();
    renderAllCables();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function updateNodeData(nodeId, key, value) {
    const node = workflowNodes.find(n => n.id === nodeId);
    if (node) {
        if (!node.data) node.data = {};
        node.data[key] = value;
        if (node.type === 'trigger' && key === 'keywords') {
            const kwEl = document.getElementById('canvasWorkflowKeywords');
            if (kwEl) kwEl.value = value;
        }
    }
}

function deleteNode(nodeId) {
    if (confirm('Delete this automation block and its connected cables?')) {
        workflowNodes = workflowNodes.filter(n => n.id !== nodeId);
        workflowConnections = workflowConnections.filter(c => c.from !== nodeId && c.to !== nodeId);
        renderAllNodes();
        renderAllCables();
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

function addNode(type) {
    const menu = document.getElementById('addNodeDropdownMenu');
    if (menu) menu.style.display = 'none';

    const newId = 'node_' + type + '_' + Date.now();
    const spawnX = Math.round((-canvasPanX + 180) / canvasScale);
    const spawnY = Math.round((-canvasPanY + 180) / canvasScale);

    let newNode = {
        id: newId,
        type: type,
        title: type.toUpperCase() + ' BLOCK',
        x: Math.max(40, spawnX),
        y: Math.max(40, spawnY),
        data: {}
    };

    if (type === 'trigger') {
        newNode.title = 'Keyword Trigger';
        newNode.data = { keywords: 'NEW_KEYWORD', match_type: 'contains' };
    } else if (type === 'template') {
        const firstTpl = availableTemplates[0] || null;
        newNode.title = firstTpl ? firstTpl.title : 'Meta Template Message';
        newNode.data = {
            slug: firstTpl ? firstTpl.slug : '',
            template_id: firstTpl ? firstTpl.id : 0,
            template_name: firstTpl ? firstTpl.title : '',
            header_text: firstTpl ? (firstTpl.header_text || '') : '',
            body_text: firstTpl ? (firstTpl.body_text || '') : 'Please choose an option:',
            footer_text: firstTpl ? (firstTpl.footer_text || '') : '',
            category: firstTpl ? (firstTpl.category || 'General') : 'General',
            buttons: (firstTpl?.buttons && firstTpl.buttons.length > 0) ? firstTpl.buttons : [{ id: 'btn_1', label: 'Option 1', title: 'Option 1', type: 'QUICK_REPLY' }]
        };
    } else if (type === 'flow') {
        const firstFlow = availableFlows[0] || null;
        newNode.title = firstFlow ? firstFlow.name : 'Meta WhatsApp Flow';
        newNode.data = {
            flow_id: firstFlow ? firstFlow.flow_id : '869669339414580',
            flow_name: firstFlow ? firstFlow.name : 'Marg Tickets',
            category: firstFlow ? (firstFlow.category || 'OTHER') : 'OTHER',
            status: firstFlow ? (firstFlow.status || 'PUBLISHED') : 'PUBLISHED',
            screens: firstFlow?.screens || [],
            screen: (firstFlow?.screens && firstFlow.screens[0]?.id) ? firstFlow.screens[0].id : 'WELCOME_SCREEN'
        };
    } else if (type === 'action') {
        newNode.title = 'CRM Action';
        newNode.data = { action_type: 'create_support_ticket' };
    }

    workflowNodes.push(newNode);
    renderAllNodes();
    renderAllCables();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// 3. Cable Connectors Rendering (Bézier Curves)
function renderAllCables() {
    const svg = document.getElementById('canvasSvgLayer');
    if (!svg) return;
    svg.innerHTML = '';

    workflowConnections.forEach((conn, idx) => {
        const fromPortEl = document.querySelector(`.port[data-node="${conn.from}"][data-port="${conn.fromPort}"]`);
        const toPortEl = document.querySelector(`.port[data-node="${conn.to}"][data-port="${conn.toPort}"]`);

        if (fromPortEl && toPortEl) {
            const p1 = getPortCanvasCoordinates(fromPortEl);
            const p2 = getPortCanvasCoordinates(toPortEl);

            const dx = Math.abs(p2.x - p1.x) * 0.55;
            const cx1 = p1.x + Math.max(40, dx);
            const cy1 = p1.y;
            const cx2 = p2.x - Math.max(40, dx);
            const cy2 = p2.y;

            const d = `M ${p1.x} ${p1.y} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${p2.x} ${p2.y}`;

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            path.setAttribute('class', 'cable-path');
            path.setAttribute('id', `cable_${idx}`);
            path.onclick = () => {
                if (confirm('Disconnect this cable connection?')) {
                    workflowConnections.splice(idx, 1);
                    renderAllCables();
                }
            };

            svg.appendChild(path);
        }
    });
}

function getPortCanvasCoordinates(portEl) {
    const rect = portEl.getBoundingClientRect();
    const stage = document.getElementById('canvasGridStage');
    const stageRect = stage.getBoundingClientRect();

    const x = (rect.left + rect.width / 2 - stageRect.left) / canvasScale;
    const y = (rect.top + rect.height / 2 - stageRect.top) / canvasScale;
    return { x, y };
}

function startCableDrag(e, nodeId, portId) {
    e.stopPropagation();
    e.preventDefault();

    activeDragPort = { nodeId, portId, startX: e.clientX, startY: e.clientY };

    const svg = document.getElementById('canvasSvgLayer');
    const tempPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    tempPath.setAttribute('id', 'tempCablePath');
    tempPath.setAttribute('class', 'cable-path active-pulse');
    svg.appendChild(tempPath);

    window.addEventListener('mousemove', onCableDragMove);
    window.addEventListener('mouseup', onCableDragEnd);
}

function onCableDragMove(e) {
    if (!activeDragPort) return;

    const fromPortEl = document.querySelector(`.port[data-node="${activeDragPort.nodeId}"][data-port="${activeDragPort.portId}"]`);
    if (!fromPortEl) return;

    const p1 = getPortCanvasCoordinates(fromPortEl);
    const stageRect = document.getElementById('canvasGridStage').getBoundingClientRect();
    const p2 = {
        x: (e.clientX - stageRect.left) / canvasScale,
        y: (e.clientY - stageRect.top) / canvasScale
    };

    const dx = Math.abs(p2.x - p1.x) * 0.55;
    const cx1 = p1.x + Math.max(40, dx);
    const cy1 = p1.y;
    const cx2 = p2.x - Math.max(40, dx);
    const cy2 = p2.y;

    const d = `M ${p1.x} ${p1.y} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${p2.x} ${p2.y}`;
    const tempPath = document.getElementById('tempCablePath');
    if (tempPath) tempPath.setAttribute('d', d);
}

function finishCableDrag(e, targetNodeId, targetPortId) {
    if (!activeDragPort) return;

    if (activeDragPort.nodeId !== targetNodeId) {
        const exists = workflowConnections.some(c => 
            c.from === activeDragPort.nodeId && 
            c.fromPort === activeDragPort.portId && 
            c.to === targetNodeId && 
            c.toPort === targetPortId
        );

        if (!exists) {
            workflowConnections.push({
                from: activeDragPort.nodeId,
                fromPort: activeDragPort.portId,
                to: targetNodeId,
                toPort: targetPortId
            });
        }
    }

    onCableDragEnd();
}

function onCableDragEnd() {
    activeDragPort = null;
    const tempPath = document.getElementById('tempCablePath');
    if (tempPath) tempPath.remove();
    window.removeEventListener('mousemove', onCableDragMove);
    window.removeEventListener('mouseup', onCableDragEnd);
    renderAllCables();
}

// 4. Node Dragging
function startNodeDrag(e, nodeId) {
    if (e.target.closest('.port') || e.target.closest('button') || e.target.closest('input') || e.target.closest('select')) {
        return;
    }
    e.preventDefault();

    const node = workflowNodes.find(n => n.id === nodeId);
    if (!node) return;

    activeDraggingNode = node;
    dragNodeOffsetX = e.clientX / canvasScale - node.x;
    dragNodeOffsetY = e.clientY / canvasScale - node.y;

    window.addEventListener('mousemove', onNodeDragMove);
    window.addEventListener('mouseup', onNodeDragEnd);
}

function onNodeDragMove(e) {
    if (!activeDraggingNode) return;

    activeDraggingNode.x = Math.round(e.clientX / canvasScale - dragNodeOffsetX);
    activeDraggingNode.y = Math.round(e.clientY / canvasScale - dragNodeOffsetY);

    const el = document.getElementById(activeDraggingNode.id);
    if (el) {
        el.style.left = activeDraggingNode.x + 'px';
        el.style.top = activeDraggingNode.y + 'px';
    }

    renderAllCables();
}

function onNodeDragEnd() {
    activeDraggingNode = null;
    window.removeEventListener('mousemove', onNodeDragMove);
    window.removeEventListener('mouseup', onNodeDragEnd);
}

// 5. Canvas Pan & Zoom
function initCanvasEvents() {
    const viewport = document.getElementById('canvasViewport');
    if (!viewport) return;

    viewport.addEventListener('mousedown', (e) => {
        if (e.target.closest('.canvas-node') || e.target.closest('.port')) return;
        isPanning = true;
        viewport.classList.add('panning');
        startPanMouseX = e.clientX - canvasPanX;
        startPanMouseY = e.clientY - canvasPanY;
    });

    window.addEventListener('mousemove', (e) => {
        if (!isPanning) return;
        canvasPanX = e.clientX - startPanMouseX;
        canvasPanY = e.clientY - startPanMouseY;
        applyCanvasTransform();
    });

    window.addEventListener('mouseup', () => {
        isPanning = false;
        viewport.classList.remove('panning');
    });

    viewport.addEventListener('wheel', (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.05 : 0.05;
        zoomCanvas(delta);
    }, { passive: false });

    const kwInput = document.getElementById('canvasWorkflowKeywords');
    if (kwInput) {
        kwInput.addEventListener('input', (e) => {
            const trigNode = workflowNodes.find(n => n.type === 'trigger');
            if (trigNode) {
                if (!trigNode.data) trigNode.data = {};
                trigNode.data.keywords = e.target.value;
                const nodeKwInput = document.querySelector(`.canvas-node#${trigNode.id} input`);
                if (nodeKwInput) nodeKwInput.value = e.target.value;
            }
        });
    }
}

function zoomCanvas(delta) {
    canvasScale = Math.min(2.0, Math.max(0.4, canvasScale + delta));
    const label = document.getElementById('canvasZoomLabel');
    if (label) label.innerText = Math.round(canvasScale * 100) + '%';
    applyCanvasTransform();
    renderAllCables();
}

function resetCanvasView() {
    canvasScale = 1.0;
    canvasPanX = 40;
    canvasPanY = 40;
    const label = document.getElementById('canvasZoomLabel');
    if (label) label.innerText = '100%';
    applyCanvasTransform();
    renderAllCables();
}

function applyCanvasTransform() {
    const stage = document.getElementById('canvasGridStage');
    if (stage) {
        stage.style.transform = `translate(${canvasPanX}px, ${canvasPanY}px) scale(${canvasScale})`;
    }
}

function toggleAddNodeDropdown(e) {
    e.stopPropagation();
    const m = document.getElementById('addNodeDropdownMenu');
    if (m) m.style.display = (m.style.display === 'none' ? 'block' : 'none');
}
window.addEventListener('click', () => {
    const m = document.getElementById('addNodeDropdownMenu');
    if (m) m.style.display = 'none';
});

// 6. Save Workflow
function saveWorkflowAutomation(asNew = false) {
    const btn = document.getElementById('btnSaveWorkflow');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i data-lucide="loader" class="spin" style="width:14px; height:14px;"></i> Saving...`;
    }

    const name = document.getElementById('canvasWorkflowName').value || 'Master WhatsApp Support & Sales Flow';
    const keywords = document.getElementById('canvasWorkflowKeywords').value || 'HI, SUPPORT, TICKET';

    // Synchronize keywords to trigger node if present
    const trigNode = workflowNodes.find(n => n.type === 'trigger');
    if (trigNode) {
        if (!trigNode.data) trigNode.data = {};
        trigNode.data.keywords = keywords;
    }

    const targetId = asNew ? 0 : currentWorkflowId;

    const payload = {
        action: 'save_workflow',
        id: targetId,
        name: asNew ? (name + ' (Copy)') : name,
        trigger_keyword: keywords,
        status: 'ACTIVE',
        nodes: workflowNodes,
        connections: workflowConnections
    };

    fetch('api/bot_flows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="check" style="width:14px; height:14px;"></i> Saved!`;
            setTimeout(() => {
                btn.innerHTML = `<i data-lucide="save" style="width:14px; height:14px;"></i> <span>Save Changes</span>`;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }, 1800);
        }

        if (data.success) {
            if (asNew && data.id) {
                alert('Saved as New Flow Journey! Loading #WF-' + String(data.id).padStart(2, '0') + '...');
                window.location.href = 'index.php?page=bot_flows&tab=canvas&id=' + data.id;
                return;
            }

            if (data.id && currentWorkflowId === 0) {
                currentWorkflowId = data.id;
            }

            // Dynamically update the selector dropdown option
            const sel = document.getElementById('activeWorkflowSelector');
            if (sel) {
                let opt = sel.querySelector(`option[value="${currentWorkflowId}"]`);
                if (opt) {
                    opt.textContent = `#WF-${String(currentWorkflowId).padStart(2, '0')}: ${name}`;
                }
            }

            const nowTime = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            alert(`Flow Saved Successfully!\n\nJourney: #${String(currentWorkflowId).padStart(2, '0')} - "${name}"\nTriggers: ${keywords}\nStatus: ACTIVE\nSynced At: ${nowTime}`);
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i data-lucide="save" style="width:14px; height:14px;"></i> <span>Save Changes</span>`;
        }
        alert('Network error saving workflow: ' + err);
    });
}

// 7. Workflow Management (Create, Clone, Delete, Status Toggle)
function openCreateWorkflowModal() {
    const m = document.getElementById('createWorkflowModal');
    if (m) {
        m.style.display = 'flex';
        document.getElementById('newWfName').focus();
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeCreateWorkflowModal() {
    const m = document.getElementById('createWorkflowModal');
    if (m) m.style.display = 'none';
}

function submitCreateWorkflow(e) {
    e.preventDefault();
    const name = document.getElementById('newWfName').value.trim();
    const keywords = document.getElementById('newWfKeywords').value.trim() || 'ALL';

    if (!name) return;

    const defaultNodes = [
        {
            id: 'node_trigger_' + Date.now(),
            type: 'trigger',
            title: 'Keyword Trigger',
            x: 60,
            y: 180,
            data: { keywords: keywords, match_type: 'contains' }
        },
        {
            id: 'node_template_' + Date.now(),
            type: 'template',
            title: 'Welcome Message Menu',
            x: 430,
            y: 150,
            data: {
                template_name: 'welcome_prompt',
                header_text: name,
                body_text: 'Welcome! Please select an option to proceed:',
                buttons: [{ id: 'btn_1', label: 'Start Flow' }]
            }
        }
    ];

    const defaultConns = [
        { from: defaultNodes[0].id, fromPort: 'out', to: defaultNodes[1].id, toPort: 'in' }
    ];

    const payload = {
        action: 'save_workflow',
        id: 0,
        name: name,
        trigger_keyword: keywords,
        status: 'ACTIVE',
        nodes: defaultNodes,
        connections: defaultConns
    };

    fetch('api/bot_flows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.id) {
            window.location.href = 'index.php?page=bot_flows&tab=canvas&id=' + data.id;
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => alert('Network error creating workflow: ' + err));
}

function cloneCurrentWorkflow() {
    if (!confirm('Duplicate this flow automation journey?')) return;

    const fd = new FormData();
    fd.append('action', 'clone_workflow');
    fd.append('id', currentWorkflowId);

    fetch('api/bot_flows.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.id) {
            window.location.href = 'index.php?page=bot_flows&tab=canvas&id=' + data.id;
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function deleteCurrentWorkflow() {
    if (!confirm('Are you sure you want to permanently delete this flow automation?')) return;

    const fd = new FormData();
    fd.append('action', 'delete_workflow');
    fd.append('id', currentWorkflowId);

    fetch('api/bot_flows.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'index.php?page=bot_flows&tab=canvas';
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function toggleCurrentWorkflowStatus() {
    const fd = new FormData();
    fd.append('action', 'toggle_workflow_status');
    fd.append('id', currentWorkflowId);

    fetch('api/bot_flows.php', { method: 'POST', body: fd })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('canvasStatusBadge');
            if (badge) {
                badge.innerText = data.status;
                badge.className = 'status-badge ' + (data.status === 'ACTIVE' ? 'status-badge-published' : 'status-badge-draft');
            }
        } else {
            alert('Error: ' + data.message);
        }
    });
}

// 8. Test Simulation
function openSimulationModal() {
    const m = document.getElementById('simModal');
    if (m) {
        m.style.display = 'flex';
        document.getElementById('simInputMessage').focus();
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeSimulationModal() {
    const m = document.getElementById('simModal');
    if (m) m.style.display = 'none';
    document.querySelectorAll('.canvas-node').forEach(n => n.classList.remove('sim-highlight'));
}

function renderSimFlowFormHtml(flowNode) {
    const flowId = flowNode.data?.flow_id;
    const flowName = flowNode.data?.flow_name || flowNode.title || 'Marg WhatsApp Flow';
    const screenId = flowNode.data?.screen || 'WELCOME_SCREEN';
    
    // Find flow in availableFlows or flowNode.data
    const metaFlow = (availableFlows && availableFlows.find(f => f.flow_id === flowId)) || flowNode.data || {};
    const screen = (metaFlow.screens || []).find(s => s.id === screenId) || (metaFlow.screens && metaFlow.screens[0]) || null;
    const components = screen?.components || [];
    const screenTitle = screen?.title || screen?.name || flowName;
    const screenBody = screen?.body || 'Please fill in the form details below to proceed:';
    const footerLabel = screen?.footer_label || 'Submit Flow Form';

    let fieldsHtml = '';
    if (components.length > 0) {
        components.forEach((c, idx) => {
            const fieldId = 'sim_f_' + flowNode.id + '_' + (c.name || c.id || idx);
            const fieldName = c.name || c.id || ('field_' + idx);
            const label = c.label || fieldName;
            const isReq = !!c.required;
            const cType = (c.type || '').toLowerCase();

            fieldsHtml += `
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; color:var(--text-main); margin-bottom:2px;">
                        ${escapeHtml(label)} ${isReq ? '<span style="color:#ef4444;">*</span>' : ''}
                    </label>
            `;

            if (cType.includes('text area') || cType.includes('textarea')) {
                fieldsHtml += `<textarea id="${fieldId}" data-name="${escapeHtml(fieldName)}" data-label="${escapeHtml(label)}" class="input-styled text-xs sim-flow-field" rows="2" style="width:100%; font-size:0.75rem;" placeholder="${escapeHtml(c.helper || label)}">${fieldName === 'requirement' ? 'Marg ERP Silver edition for retail pharma inventory' : (fieldName === 'problem' ? 'Software printer setup issue' : '')}</textarea>`;
            } else if (cType.includes('time') || (c.options && c.options.length > 0)) {
                const opts = c.options || ['10:00 AM - 12:00 PM', '12:00 PM - 02:00 PM', '02:00 PM - 04:00 PM', '04:00 PM - 06:00 PM'];
                let optHtml = '';
                opts.forEach(op => {
                    optHtml += `<option value="${escapeHtml(op)}">${escapeHtml(op)}</option>`;
                });
                fieldsHtml += `<select id="${fieldId}" data-name="${escapeHtml(fieldName)}" data-label="${escapeHtml(label)}" class="input-styled text-xs font-mono sim-flow-field" style="width:100%; font-size:0.75rem;">${optHtml}</select>`;
            } else if (cType.includes('date')) {
                const today = new Date().toISOString().split('T')[0];
                fieldsHtml += `<input type="date" id="${fieldId}" data-name="${escapeHtml(fieldName)}" data-label="${escapeHtml(label)}" class="input-styled text-xs font-mono sim-flow-field" style="width:100%; font-size:0.75rem;" value="${today}">`;
            } else if (cType.includes('phone')) {
                fieldsHtml += `<input type="tel" id="${fieldId}" data-name="${escapeHtml(fieldName)}" data-label="${escapeHtml(label)}" class="input-styled text-xs font-mono sim-flow-field" style="width:100%; font-size:0.75rem;" placeholder="e.g. 9876543210" value="9876543210">`;
            } else {
                let defaultVal = '';
                if (fieldName.includes('name')) defaultVal = 'Rajesh Kumar (M/s Kumar Medicals)';
                if (fieldName.includes('license')) defaultVal = 'MG-2026-88192';
                fieldsHtml += `<input type="text" id="${fieldId}" data-name="${escapeHtml(fieldName)}" data-label="${escapeHtml(label)}" class="input-styled text-xs font-bold sim-flow-field" style="width:100%; font-size:0.75rem;" placeholder="${escapeHtml(c.helper || label)}" value="${escapeHtml(defaultVal)}">`;
            }
            fieldsHtml += `</div>`;
        });
    } else {
        const isSales = flowName.toLowerCase().includes('sale') || flowName.toLowerCase().includes('demo');
        if (isSales) {
            fieldsHtml = `
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; margin-bottom:2px;">Your Name / Firm Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" data-name="customer_name" data-label="Your Name / Firm Name" class="input-styled text-xs font-bold sim-flow-field" style="width:100%; font-size:0.75rem;" value="Rajesh Kumar (M/s Kumar Medicals)">
                </div>
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; margin-bottom:2px;">Mobile / Call Back Number <span style="color:#ef4444;">*</span></label>
                    <input type="tel" data-name="phone_number" data-label="Mobile Number" class="input-styled text-xs font-mono sim-flow-field" style="width:100%; font-size:0.75rem;" value="9876543210">
                </div>
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; margin-bottom:2px;">Software Requirement</label>
                    <textarea data-name="requirement" data-label="Requirement" class="input-styled text-xs sim-flow-field" rows="2" style="width:100%; font-size:0.75rem;">Marg ERP Silver Edition for retail pharma inventory and billing</textarea>
                </div>
            `;
        } else {
            fieldsHtml = `
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; margin-bottom:2px;">Marg License / Serial Number <span style="color:#ef4444;">*</span></label>
                    <input type="text" data-name="license_number" data-label="License Number" class="input-styled text-xs font-bold sim-flow-field" style="width:100%; font-size:0.75rem;" value="MG-2026-88192">
                </div>
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; margin-bottom:2px;">Problem Description <span style="color:#ef4444;">*</span></label>
                    <textarea data-name="problem" data-label="Problem Description" class="input-styled text-xs sim-flow-field" rows="2" style="width:100%; font-size:0.75rem;">E-Invoice JSON sync error in Marg ERP</textarea>
                </div>
                <div style="margin-bottom: 8px;">
                    <label style="display:block; font-size:0.7rem; font-weight:700; margin-bottom:2px;">Calling / WhatsApp Number</label>
                    <input type="tel" data-name="callback_number" data-label="Callback Number" class="input-styled text-xs font-mono sim-flow-field" style="width:100%; font-size:0.75rem;" value="9876543210">
                </div>
            `;
        }
    }

    return `
        <div class="sim-chat-bubble-bot" id="sim_flow_container_${escapeHtml(flowNode.id)}" style="border-left: 3px solid #10b981; max-width: 90%; background: var(--bg-card, #ffffff);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; border-bottom:1px solid var(--border-color, #e2e8f0); padding-bottom:5px;">
                <div style="font-weight:700; font-size:0.85rem; color:var(--text-main);">${escapeHtml(screenTitle)}</div>
                <span class="badge" style="background:rgba(16,185,129,0.15); color:#10b981; font-size:0.6rem; font-weight:700;">NATIVE META FLOW</span>
            </div>
            <div class="text-xs text-muted mb-2" style="font-size:0.72rem; line-height:1.3;">${escapeHtml(screenBody)}</div>
            <form onsubmit="event.preventDefault(); simSubmitFlow('${escapeHtml(flowNode.id)}');">
                <div style="background:var(--bg-body, rgba(0,0,0,0.02)); border:1px solid var(--border-color, #e2e8f0); border-radius:8px; padding:10px; margin-bottom:8px;">
                    ${fieldsHtml}
                </div>
                <div style="display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn-pill btn-pill-primary text-xs" style="padding:6px 14px; font-weight:700;">
                        <span>${escapeHtml(footerLabel)}</span>
                    </button>
                </div>
            </form>
        </div>
    `;
}

function executeSimStep() {
    const input = document.getElementById('simInputMessage');
    const text = input.value.trim().toUpperCase();
    if (!text) return;

    const chat = document.getElementById('simChatWindow');
    chat.innerHTML += `<div class="sim-chat-bubble-user">${escapeHtml(text)}</div>`;
    input.value = '';

    const matchedTrigger = workflowNodes.find(n => n.type === 'trigger' && (n.data?.keywords || '').toUpperCase().includes(text));

    if (matchedTrigger) {
        highlightNode(matchedTrigger.id);

        setTimeout(() => {
            const connToTemplate = workflowConnections.find(c => c.from === matchedTrigger.id);
            if (connToTemplate) {
                const targetNode = workflowNodes.find(n => n.id === connToTemplate.to);
                if (targetNode && targetNode.type === 'template') {
                    highlightNode(targetNode.id);
                    let btnsHtml = '';
                    const buttons = targetNode.data?.buttons || [];
                    if (buttons.length > 0) {
                        buttons.forEach(btn => {
                            const bId = btn.id || 'btn_action';
                            const bTitle = btn.title || btn.label || 'Action';
                            btnsHtml += `<button type="button" class="btn-pill btn-pill-outline text-xs" style="margin:2px 4px 2px 0;" onclick="simClickButton('${targetNode.id}', '${escapeHtml(bId)}')">${escapeHtml(bTitle)}</button>`;
                        });
                    }
                    chat.innerHTML += `
                        <div class="sim-chat-bubble-bot">
                            ${targetNode.data?.header_text ? `<strong>${escapeHtml(targetNode.data.header_text)}</strong><br>` : ''}
                            ${escapeHtml(targetNode.data?.body_text || 'Welcome to Marg ERP Support!')}<br><br>
                            ${btnsHtml}
                            ${targetNode.data?.footer_text ? `<br><span class="text-xs text-muted" style="font-size:0.7rem;">${escapeHtml(targetNode.data.footer_text)}</span>` : ''}
                        </div>
                    `;
                    chat.scrollTop = chat.scrollHeight;
                } else if (targetNode && targetNode.type === 'flow') {
                    highlightNode(targetNode.id);
                    chat.innerHTML += renderSimFlowFormHtml(targetNode);
                    chat.scrollTop = chat.scrollHeight;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            }
        }, 600);
    } else {
        chat.innerHTML += `<div class="sim-chat-bubble-system">No trigger matched "${escapeHtml(text)}". Please send a keyword configured in your Trigger block.</div>`;
        chat.scrollTop = chat.scrollHeight;
    }
}

function simClickButton(templateNodeId, buttonPortId) {
    const chat = document.getElementById('simChatWindow');
    const templateNode = workflowNodes.find(n => n.id === templateNodeId);
    const clickedBtn = templateNode?.data?.buttons?.find(b => b.id === buttonPortId);
    const btnLabel = clickedBtn?.title || clickedBtn?.label || buttonPortId;
    chat.innerHTML += `<div class="sim-chat-bubble-user">[Selected: ${escapeHtml(btnLabel)}]</div>`;

    const connToNext = workflowConnections.find(c => c.from === templateNodeId && (c.fromPort === buttonPortId || c.fromPort === 'out'));
    if (connToNext) {
        const nextNode = workflowNodes.find(n => n.id === connToNext.to);
        if (nextNode && nextNode.type === 'flow') {
            highlightNode(nextNode.id);
            setTimeout(() => {
                chat.innerHTML += renderSimFlowFormHtml(nextNode);
                chat.scrollTop = chat.scrollHeight;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }, 600);
        } else if (nextNode && nextNode.type === 'action') {
            highlightNode(nextNode.id);
            setTimeout(() => {
                const actionType = nextNode.data?.action_type || 'create_support_ticket';
                let actionLabel = 'Support Ticket Created: #TK-2026-9042';
                if (actionType === 'create_sales_lead') actionLabel = 'Sales Lead Assigned: Sahil Savita (Demo Booked)';
                if (actionType === 'send_whatsapp_invoice') actionLabel = 'Marg ERP Bill PDF Dispatched';
                chat.innerHTML += `
                    <div class="sim-chat-bubble-bot" style="border-left: 3px solid #8b5cf6;">
                        <strong>CRM Execution Complete</strong><br>
                        • ${actionLabel}<br>
                        • Meta Approved Confirmation Template delivered to customer.<br>
                        • Real-time notification sent to staff dashboard.
                    </div>
                `;
                chat.scrollTop = chat.scrollHeight;
            }, 600);
        }
    }
}

function simSubmitFlow(flowNodeId) {
    const chat = document.getElementById('simChatWindow');
    const container = document.getElementById('sim_flow_container_' + flowNodeId);
    
    // Gather entered values from the form
    const submittedData = {};
    let summaryListHtml = '';
    if (container) {
        const fields = container.querySelectorAll('.sim-flow-field');
        fields.forEach(f => {
            const name = f.getAttribute('data-name') || f.name || 'field';
            const label = f.getAttribute('data-label') || name;
            const val = f.value.trim() || 'N/A';
            submittedData[name] = val;
            summaryListHtml += `<div style="font-size:0.72rem; margin-top:2px;">• <strong>${escapeHtml(label)}:</strong> ${escapeHtml(val)}</div>`;
        });
    }

    const flowNode = workflowNodes.find(n => n.id === flowNodeId);
    const flowName = flowNode?.data?.flow_name || flowNode?.title || 'Meta WhatsApp Flow';

    // Disable the submit button on the form
    if (container) {
        const btn = container.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Submitted';
            btn.style.opacity = '0.6';
        }
    }

    // Render User submission bubble with the EXACT values they entered
    chat.innerHTML += `
        <div class="sim-chat-bubble-user" style="border-right: 3px solid #10b981; max-width: 85%;">
            <div style="font-size:0.75rem; font-weight:700; color:#10b981; margin-bottom:3px;">
                [Meta Flow Submitted: ${escapeHtml(flowName)}]
            </div>
            ${summaryListHtml || '<div style="font-size:0.72rem;">• Details filled and sent.</div>'}
        </div>
    `;
    chat.scrollTop = chat.scrollHeight;

    // Follow connection to next step
    const connToNext = workflowConnections.find(c => c.from === flowNodeId);
    if (connToNext) {
        const nextNode = workflowNodes.find(n => n.id === connToNext.to);
        if (nextNode) {
            highlightNode(nextNode.id);
            setTimeout(() => {
                if (nextNode.type === 'action') {
                    const actionType = nextNode.data?.action_type || 'create_support_ticket';
                    let title = 'CRM Action Executed';
                    let detailsHtml = '';
                    
                    if (actionType === 'create_sales_lead') {
                        const party = submittedData['customer_name'] || submittedData['full_name'] || 'Rajesh Kumar (M/s Kumar Medicals)';
                        const phone = submittedData['phone_number'] || submittedData['mobile_number'] || submittedData['phone'] || '9876543210';
                        title = 'Sales Lead Registered &amp; Assigned';
                        detailsHtml = `
                            • <strong>Lead Ref ID:</strong> #LD-2026-${Math.floor(1000 + Math.random() * 9000)}<br>
                            • <strong>Party Name:</strong> ${escapeHtml(party)}<br>
                            • <strong>Contact:</strong> ${escapeHtml(phone)}<br>
                            • <strong>Assigned Executive:</strong> Sahil Savita<br>
                            • <strong>Status:</strong> Demo Scheduled / Lead Active<br>
                            • Real-time CRM push notification delivered to sales dashboard.
                        `;
                    } else if (actionType === 'create_support_ticket') {
                        const lic = submittedData['license_number'] || submittedData['c1'] || 'MG-2026-88192';
                        const prob = submittedData['problem'] || submittedData['c2'] || 'Software support issue';
                        const cb = submittedData['callback_number'] || submittedData['phone_number'] || '9876543210';
                        title = 'Technical Support Ticket Generated';
                        detailsHtml = `
                            • <strong>Ticket ID:</strong> #TK-2026-${Math.floor(1000 + Math.random() * 9000)}<br>
                            • <strong>License No:</strong> ${escapeHtml(lic)}<br>
                            • <strong>Issue:</strong> ${escapeHtml(prob)}<br>
                            • <strong>Priority:</strong> High (AnyDesk / Phone Support)<br>
                            • <strong>Status:</strong> Open &amp; Assigned<br>
                            • Meta Approved Resolution Notification dispatched.
                        `;
                    } else if (actionType === 'send_whatsapp_invoice') {
                        title = 'Marg ERP Bill PDF Dispatched';
                        detailsHtml = `
                            • <strong>Invoice:</strong> INV-2026-0045<br>
                            • <strong>Document:</strong> Sale Bill PDF generated &amp; delivered via Meta Cloud API.<br>
                            • WhatsApp delivery confirmed (Double tick).
                        `;
                    } else {
                        title = 'Custom Flow Data Stored';
                        detailsHtml = `• All ${Object.keys(submittedData).length} custom fields stored securely in CRM database logs.`;
                    }

                    chat.innerHTML += `
                        <div class="sim-chat-bubble-bot" style="border-left: 3px solid #8b5cf6;">
                            <strong style="color:#6d28d9;">${title}</strong><br>
                            <div style="font-size:0.75rem; line-height:1.4; margin-top:4px;">
                                ${detailsHtml}
                            </div>
                        </div>
                    `;
                    chat.scrollTop = chat.scrollHeight;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                } else if (nextNode.type === 'template') {
                    // Next step is a Meta Template Message (like Marg Bill!)
                    let btnsHtml = '';
                    const buttons = nextNode.data?.buttons || [];
                    if (buttons.length > 0) {
                        buttons.forEach(btn => {
                            const bId = btn.id || 'btn_action';
                            const bTitle = btn.title || btn.label || 'Action';
                            btnsHtml += `<button type="button" class="btn-pill btn-pill-outline text-xs" style="margin:2px 4px 2px 0;" onclick="simClickButton('${nextNode.id}', '${escapeHtml(bId)}')">${escapeHtml(bTitle)}</button>`;
                        });
                    }
                    const cName = submittedData['customer_name'] || submittedData['full_name'] || 'Customer';
                    let tplBody = nextNode.data?.body_text || 'Thank you for submitting your enquiry.';
                    tplBody = tplBody.replace(/\{\{1\}\}/g, 'Marg Soft Solution')
                                     .replace(/\{\{2\}\}/g, cName)
                                     .replace(/\{\{3\}\}/g, 'INV-2026-0045')
                                     .replace(/\{\{4\}\}/g, '12,500');

                    chat.innerHTML += `
                        <div class="sim-chat-bubble-bot" style="border-left: 3px solid #3b82f6;">
                            <span class="badge" style="background:rgba(59,130,246,0.15); color:#3b82f6; font-size:0.6rem; font-weight:700; margin-bottom:4px; display:inline-block;">META APPROVED TEMPLATE</span><br>
                            ${nextNode.data?.header_text ? `<strong>${escapeHtml(nextNode.data.header_text)}</strong><br>` : ''}
                            <div style="white-space:pre-wrap; font-size:0.75rem; margin-top:4px; line-height:1.4;">${escapeHtml(tplBody)}</div><br>
                            ${btnsHtml}
                            ${nextNode.data?.footer_text ? `<span class="text-xs text-muted" style="font-size:0.7rem; display:block; margin-top:4px;">${escapeHtml(nextNode.data.footer_text)}</span>` : ''}
                        </div>
                    `;
                    chat.scrollTop = chat.scrollHeight;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                } else if (nextNode.type === 'flow') {
                    chat.innerHTML += renderSimFlowFormHtml(nextNode);
                    chat.scrollTop = chat.scrollHeight;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            }, 600);
        }
    }
}

function highlightNode(nodeId) {
    document.querySelectorAll('.canvas-node').forEach(n => n.classList.remove('sim-highlight'));
    const el = document.getElementById(nodeId);
    if (el) {
        el.classList.add('sim-highlight');
        setTimeout(() => el.classList.remove('sim-highlight'), 2500);
    }
}
</script>
