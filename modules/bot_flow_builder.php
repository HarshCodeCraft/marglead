<?php
/**
 * Marg Soft Solution - Interactive Meta WhatsApp Flow Builder & Live Smartphone Simulator
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$flow_id = trim($_GET['q'] ?? $_GET['id'] ?? FLOW_ID);

// Initial default fallback data
$flowData = [
    'id' => 1,
    'flow_id' => $flow_id,
    'name' => 'Ticket',
    'category' => 'SIGN IN',
    'status' => 'PUBLISHED',
    'screens' => [
        [
            'id' => 'screen_1',
            'name' => 'Welcome to Marg Soft',
            'title' => 'Welcome to Marg Soft',
            'body' => 'Please Provide Your Info and Problem Here..',
            'components' => [
                ['id' => 'c1', 'type' => 'Short Answer', 'label' => 'License Number', 'helper' => 'Client Id', 'required' => true],
                ['id' => 'c2', 'type' => 'Dropdown', 'label' => 'Bill Format Issue', 'helper' => '', 'options' => ['Bill Format Issue', 'GST Error', 'Printer Setup'], 'required' => false],
                ['id' => 'c3', 'type' => 'Text Area', 'label' => 'Problem', 'helper' => 'Describe issue', 'required' => true],
                ['id' => 'c4', 'type' => 'Short Answer', 'label' => 'Call Back Number', 'helper' => 'Call Back Number', 'required' => true]
            ],
            'footer_label' => 'Submit',
            'footer_action' => 'Complete'
        ]
    ]
];

if ($db_connected && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM bot_flows WHERE flow_id = ? OR id = ? LIMIT 1");
        $stmt->execute([$flow_id, $flow_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $flowData['id'] = $row['id'];
            $flowData['flow_id'] = $row['flow_id'];
            $flowData['name'] = !empty($row['name']) ? $row['name'] : 'Ticket';
            $flowData['category'] = !empty($row['category']) ? $row['category'] : 'SIGN IN';
            $flowData['status'] = !empty($row['status']) ? $row['status'] : 'PUBLISHED';
            if (!empty($row['screens_json'])) {
                $decoded = json_decode($row['screens_json'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $flowData['screens'] = $decoded;
                }
            }
        }
    } catch (PDOException $e) {}
}
?>

<style>
/* Flow Builder Clean Layout */
.flow-builder-wrapper {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding-bottom: 2rem;
}

/* Top Header Bar */
.flow-builder-top-bar {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
    flex-wrap: wrap;
    gap: 1rem;
}
.flow-top-left {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.flow-breadcrumb {
    font-size: 0.8rem;
    color: var(--primary, #10b981);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.flow-breadcrumb:hover {
    text-decoration: underline;
}
.flow-builder-title {
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-main, #111827);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.flow-meta-tags {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Main 3-Column Layout */
.flow-builder-grid {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr) 340px;
    gap: 1.25rem;
    min-height: calc(100vh - 200px);
}
@media (max-width: 1200px) {
    .flow-builder-grid {
        grid-template-columns: 1fr;
    }
}

/* Column Cards */
.builder-card {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
}
.builder-column-title {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    color: var(--text-main, #111827);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Column 1: Screens List */
.screen-item {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #e5e7eb);
    background: var(--bg-body, #fafafa);
    cursor: pointer;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text-main, #111827);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: all 0.2s ease;
}
.screen-item:hover {
    background: rgba(16, 185, 129, 0.05);
    border-color: #10b981;
}
.screen-item.active {
    background: rgba(16, 185, 129, 0.12);
    border-color: #10b981;
    border-left: 4px solid #10b981;
    color: #047857;
}

.add-screen-box {
    background: var(--bg-body, #fafafa);
    border: 1px dashed var(--border-color, #d1d5db);
    border-radius: 8px;
    padding: 0.85rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

/* Column 2: Editor Inputs */
.content-block {
    background: var(--bg-body, #f9fafb);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 10px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.content-block-header {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--text-main, #111827);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.input-styled {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--border-color, #d1d5db);
    font-size: 0.88rem;
    background: var(--bg-card, #ffffff);
    color: var(--text-main, #111827);
    box-sizing: border-box;
    font-family: inherit;
    transition: border-color 0.2s ease;
}
.input-styled:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}
.char-counter {
    font-size: 0.7rem;
    color: var(--text-muted, #9ca3af);
    text-align: right;
    margin-top: 2px;
}

/* Toggle Switch */
.toggle-switch-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-main, #374151);
}
.switch {
    position: relative;
    display: inline-block;
    width: 38px;
    height: 20px;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 20px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 14px;
    width: 14px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: #10b981;
}
input:checked + .slider:before {
    transform: translateX(18px);
}

/* Column 3: Smartphone Simulator */
.phone-mockup-frame {
    width: 100%;
    max-width: 310px;
    margin: 0 auto;
    background: #0b141a;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 12px 32px rgba(0,0,0,0.2);
    border: 8px solid #1f2937;
    display: flex;
    flex-direction: column;
}
.phone-header-bar {
    background: #075e54;
    color: #ffffff;
    padding: 0.85rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.phone-header-title {
    font-weight: 700;
    font-size: 0.92rem;
}
.phone-body-content {
    background: #efeae2;
    padding: 1rem;
    min-height: 380px;
    display: flex;
    flex-direction: column;
}
.whatsapp-flow-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.flow-preview-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #111827;
}
.flow-preview-body {
    font-size: 0.82rem;
    color: #4b5563;
    line-height: 1.4;
}
.flow-preview-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.preview-field-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: #374151;
}
.preview-field-input {
    width: 100%;
    padding: 0.5rem;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    font-size: 0.8rem;
    background: #f9fafb;
    box-sizing: border-box;
}
.flow-preview-footer-btn {
    background: #00a884;
    color: #ffffff;
    text-align: center;
    padding: 0.65rem;
    border-radius: 20px;
    font-weight: 700;
    font-size: 0.88rem;
    margin-top: 0.5rem;
}
</style>

<div class="flow-builder-wrapper">
    
    <!-- Top Action Bar -->
    <div class="flow-builder-top-bar">
        <div class="flow-top-left">
            <a href="index.php?page=bot_flows" class="flow-breadcrumb">
                <i data-lucide="arrow-left" style="width: 14px; height: 14px;"></i>
                Back to WhatsApp Flows
            </a>
            <h1 class="flow-builder-title">
                Meta Flow Builder: <span class="text-primary"><?php echo htmlspecialchars($flowData['name']); ?></span>
            </h1>
        </div>

        <div style="display: flex; align-items: center; gap: 1rem;">
            <div class="flow-meta-tags">
                <span class="badge" style="background: rgba(59,130,246,0.1); color: #2563eb; font-family: monospace; font-size: 0.75rem;">
                    ID: <?php echo htmlspecialchars($flowData['flow_id']); ?>
                </span>
                <span class="badge badge-success text-xs">PUBLISHED</span>
            </div>
            
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn btn-secondary text-xs font-semibold" onclick="saveFlowData(false)">
                    <i data-lucide="save" style="width: 14px; height: 14px;"></i>
                    Save Flow
                </button>
                <button type="button" class="btn btn-primary text-xs font-bold" onclick="saveFlowData(true)">
                    <i data-lucide="play" style="width: 14px; height: 14px;"></i>
                    Save & Preview
                </button>
            </div>
        </div>
    </div>

    <!-- Main 3-Column Workspace Grid -->
    <div class="flow-builder-grid">
        
        <!-- COLUMN 1: SCREENS LIST -->
        <div class="builder-card">
            <h3 class="builder-column-title">
                <span>📱 Flow Screens</span>
                <span class="badge text-xs" style="background: rgba(16,185,129,0.1); color: #10b981;">Meta Flow</span>
            </h3>
            
            <div id="screensList" style="display: flex; flex-direction: column; gap: 0.6rem;">
                <!-- Rendered dynamically by JS -->
            </div>

            <!-- Add New Screen Box -->
            <div class="add-screen-box" style="margin-top: 0.5rem;">
                <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-main); display: flex; align-items: center; justify-content: space-between;">
                    <span>+ Add New Screen</span>
                </div>
                
                <div>
                    <input type="text" id="newScreenNameInput" class="input-styled text-xs" maxlength="20" placeholder="Screen name..." oninput="updateNewScreenCharCount(this)">
                    <div class="char-counter" id="newScreenCharCounter">0 / 20</div>
                </div>

                <button type="button" class="btn btn-primary text-xs font-bold w-full" style="justify-content: center;" onclick="addNewScreen()">
                    + Add Screen
                </button>
            </div>

            <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <div style="font-size: 0.75rem; color: var(--text-muted); text-align: center;">
                    Initial Screen: <strong class="text-primary font-mono">WELCOME_SCREEN</strong>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: SCREEN & COMPONENT EDITOR -->
        <div class="builder-card" style="overflow-y: auto;">
            <h3 class="builder-column-title">
                <span>✏️ Screen & Form Component Editor</span>
                <span class="text-xs text-muted" id="activeScreenIndicator">Editing Screen 1</span>
            </h3>

            <!-- 1. Screen Title Section -->
            <div class="content-block">
                <div class="content-block-header">
                    <span>Screen Title</span>
                    <span class="text-xs text-muted">Title shown at top of Flow</span>
                </div>
                <div>
                    <input type="text" id="editScreenTitle" class="input-styled font-bold" maxlength="20" oninput="handleScreenTitleChange(this)">
                    <div class="char-counter" id="screenTitleCounter">0 / 20</div>
                </div>
            </div>

            <!-- 2. Body Text Section -->
            <div class="content-block">
                <div class="content-block-header">
                    <span>Screen Description / Instructions Body</span>
                    <span class="text-xs text-muted">Main text card</span>
                </div>
                <div>
                    <textarea id="editScreenBody" class="input-styled" rows="3" maxlength="4096" oninput="handleScreenBodyChange(this)"></textarea>
                    <div class="char-counter" id="screenBodyCounter">0 / 4096</div>
                </div>
            </div>

            <!-- 3. Dynamic Form Field Components -->
            <div class="content-block" style="background: var(--bg-card);">
                <div class="content-block-header mb-1">
                    <span>📋 Form Fields (Components)</span>
                    <span class="text-xs text-muted">Customer inputs</span>
                </div>

                <div id="componentsContainer" style="display: flex; flex-direction: column; gap: 0.85rem;">
                    <!-- Component Field Cards Rendered by JS -->
                </div>

                <!-- Add Component Field Selector -->
                <div style="display: flex; gap: 0.5rem; margin-top: 1rem; border-top: 1px dashed var(--border-color); padding-top: 0.85rem;">
                    <select id="addFieldTypeSelect" class="input-styled text-xs" style="flex: 1;">
                        <option value="Short Answer">Short Answer (Single Line Text)</option>
                        <option value="Dropdown">Dropdown (Choice List)</option>
                        <option value="Text Area">Text Area (Multi-line Text)</option>
                    </select>
                    <button type="button" class="btn btn-secondary text-xs font-bold" style="white-space: nowrap;" onclick="addNewComponentField()">
                        + Add Field
                    </button>
                </div>
            </div>

            <!-- 4. Footer Section -->
            <div class="content-block">
                <div class="content-block-header">
                    <span>Footer Button Action</span>
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                    <div>
                        <label class="form-label text-xs">Button Text</label>
                        <input type="text" id="editFooterLabel" class="input-styled font-bold text-xs" maxlength="30" oninput="handleFooterLabelChange(this)">
                        <div class="char-counter" id="footerLabelCounter">0 / 30</div>
                    </div>

                    <div>
                        <label class="form-label text-xs">On-Click Action</label>
                        <select id="editFooterAction" class="input-styled text-xs" onchange="handleFooterActionChange(this)">
                            <option value="Complete">Complete Flow (Submit)</option>
                            <option value="Navigate to Next Screen">Navigate to Next Screen</option>
                        </select>
                    </div>
                </div>
            </div>

        </div>

        <!-- COLUMN 3: LIVE PREVIEW SMARTPHONE SIMULATOR -->
        <div class="builder-card">
            <h3 class="builder-column-title">
                <span>👁️ Live Smartphone Preview</span>
                <span class="badge text-xs" style="background: #00a884; color: white;">WhatsApp</span>
            </h3>

            <div class="phone-mockup-frame">
                <!-- WhatsApp Header -->
                <div class="phone-header-bar">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.1rem;">&larr;</span>
                        <span class="phone-header-title" id="previewHeaderTitle">Welcome to Marg Soft</span>
                    </div>
                    <i data-lucide="more-vertical" style="width: 16px; height: 16px;"></i>
                </div>

                <!-- WhatsApp Card Body -->
                <div class="phone-body-content">
                    <div class="whatsapp-flow-card">
                        <div class="flow-preview-title" id="previewCardTitle">
                            Welcome to Marg Soft
                        </div>
                        <div class="flow-preview-body" id="previewCardBody">
                            Please Provide Your Info and Problem Here..
                        </div>

                        <div id="previewFieldsContainer" style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <!-- Live Form Inputs Rendered Here -->
                        </div>

                        <div class="flow-preview-footer-btn" id="previewFooterBtn">
                            Submit
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
let currentFlow = <?php echo json_encode($flowData); ?>;
let activeScreenIndex = 0;

document.addEventListener('DOMContentLoaded', () => {
    renderScreensList();
    loadActiveScreenToEditor();
});

function renderScreensList() {
    const container = document.getElementById('screensList');
    container.innerHTML = '';

    if (!currentFlow.screens || currentFlow.screens.length === 0) {
        currentFlow.screens = [{
            id: 'screen_1',
            name: 'Welcome to Marg Soft',
            title: 'Welcome to Marg Soft',
            body: 'Please Provide Your Info and Problem Here..',
            components: [],
            footer_label: 'Submit',
            footer_action: 'Complete'
        }];
    }

    currentFlow.screens.forEach((screen, idx) => {
        const item = document.createElement('div');
        item.className = 'screen-item ' + (idx === activeScreenIndex ? 'active' : '');
        item.innerHTML = `
            <span>📱 ${escapeHtml(screen.name || 'Screen ' + (idx + 1))}</span>
            ${idx === activeScreenIndex ? '<span class="badge text-xs" style="background:#10b981; color:white;">Editing</span>' : ''}
        `;
        item.onclick = () => {
            activeScreenIndex = idx;
            renderScreensList();
            loadActiveScreenToEditor();
        };
        container.appendChild(item);
    });
    if (window.lucide) lucide.createIcons();
}

function loadActiveScreenToEditor() {
    const screen = currentFlow.screens[activeScreenIndex] || currentFlow.screens[0];
    if (!screen) return;

    document.getElementById('activeScreenIndicator').innerText = `Editing: ${screen.name || 'Screen ' + (activeScreenIndex + 1)}`;

    // Title
    const titleInput = document.getElementById('editScreenTitle');
    titleInput.value = screen.title || screen.name || '';
    document.getElementById('screenTitleCounter').innerText = `${titleInput.value.length} / 20`;

    // Body
    const bodyInput = document.getElementById('editScreenBody');
    bodyInput.value = screen.body || '';
    document.getElementById('screenBodyCounter').innerText = `${bodyInput.value.length} / 4096`;

    // Footer
    const footerLabelInput = document.getElementById('editFooterLabel');
    footerLabelInput.value = screen.footer_label || 'Submit';
    document.getElementById('footerLabelCounter').innerText = `${footerLabelInput.value.length} / 30`;

    const footerActionSelect = document.getElementById('editFooterAction');
    footerActionSelect.value = screen.footer_action || 'Complete';

    renderComponentsEditor(screen);
    renderLivePhonePreview(screen);
}

function renderComponentsEditor(screen) {
    const container = document.getElementById('componentsContainer');
    container.innerHTML = '';

    if (!screen.components) screen.components = [];

    screen.components.forEach((comp, idx) => {
        const block = document.createElement('div');
        block.className = 'content-block';
        block.style.cssText = 'border: 1px solid var(--border-color); background: var(--bg-body); border-radius: 8px; padding: 0.75rem;';
        
        block.innerHTML = `
            <div class="content-block-header mb-2">
                <span><strong class="text-primary">+ Field ${idx + 1}:</strong> ${escapeHtml(comp.type || 'Short Answer')}</span>
                <button type="button" class="btn-icon text-danger" title="Delete Field" onclick="deleteComponentField(${idx})">
                    <i data-lucide="trash-2" style="width: 15px; height: 15px;"></i>
                </button>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <div>
                    <label class="form-label text-xs">Field Title / Label</label>
                    <input type="text" class="input-styled text-xs font-bold" value="${escapeHtml(comp.label || '')}" maxlength="20" placeholder="e.g. License Number" oninput="updateComponentProp(${idx}, 'label', this.value, this)">
                    <div class="char-counter">${(comp.label || '').length} / 20</div>
                </div>

                <div>
                    <label class="form-label text-xs">Helper Placeholder Text (Optional)</label>
                    <input type="text" class="input-styled text-xs" value="${escapeHtml(comp.helper || '')}" maxlength="80" placeholder="e.g. Enter Client ID" oninput="updateComponentProp(${idx}, 'helper', this.value, this)">
                    <div class="char-counter">${(comp.helper || '').length} / 80</div>
                </div>

                <div class="toggle-switch-wrap mt-1">
                    <span>Required Field?</span>
                    <label class="switch">
                        <input type="checkbox" ${comp.required ? 'checked' : ''} onchange="updateComponentProp(${idx}, 'required', this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>
            </div>
        `;
        container.appendChild(block);
    });
    if (window.lucide) lucide.createIcons();
}

function renderLivePhonePreview(screen) {
    const title = screen.title || screen.name || 'Welcome to Marg Soft';
    document.getElementById('previewHeaderTitle').innerText = title;
    document.getElementById('previewCardTitle').innerText = title;
    document.getElementById('previewCardBody').innerText = screen.body || 'Please Provide Your Info and Problem Here..';
    document.getElementById('previewFooterBtn').innerText = screen.footer_label || 'Submit';

    const fieldsContainer = document.getElementById('previewFieldsContainer');
    fieldsContainer.innerHTML = '';

    if (screen.components && screen.components.length > 0) {
        screen.components.forEach(comp => {
            const f = document.createElement('div');
            f.className = 'flow-preview-field';

            const reqStar = comp.required ? '<span style="color: #ef4444;">*</span>' : '';
            const labelText = escapeHtml(comp.label || 'Field');

            if (comp.type === 'Dropdown') {
                f.innerHTML = `
                    <label class="preview-field-label">${labelText} ${reqStar}</label>
                    <select class="preview-field-input">
                        <option value="">Select option...</option>
                        <option>Bill Format Issue</option>
                        <option>GST Error</option>
                        <option>Printer Setup</option>
                    </select>
                `;
            } else if (comp.type === 'Text Area') {
                f.innerHTML = `
                    <label class="preview-field-label">${labelText} ${reqStar}</label>
                    <textarea class="preview-field-input" rows="2" placeholder="${escapeHtml(comp.helper || '')}"></textarea>
                `;
            } else {
                f.innerHTML = `
                    <label class="preview-field-label">${labelText} ${reqStar}</label>
                    <input type="text" class="preview-field-input" placeholder="${escapeHtml(comp.helper || '')}">
                `;
            }
            fieldsContainer.appendChild(f);
        });
    } else {
        fieldsContainer.innerHTML = '<div style="font-size: 0.75rem; color: #888; text-align: center; padding: 0.5rem;">No form fields added yet.</div>';
    }
}

function updateNewScreenCharCount(input) {
    document.getElementById('newScreenCharCounter').innerText = `${input.value.length} / 20`;
}

function addNewScreen() {
    const input = document.getElementById('newScreenNameInput');
    const name = input.value.trim();
    if (!name) {
        alert('Please enter a screen name.');
        return;
    }

    const newId = 'screen_' + (currentFlow.screens.length + 1);
    currentFlow.screens.push({
        id: newId,
        name: name,
        title: name,
        body: 'Please fill in details below.',
        components: [],
        footer_label: 'Submit',
        footer_action: 'Complete'
    });

    input.value = '';
    updateNewScreenCharCount(input);
    activeScreenIndex = currentFlow.screens.length - 1;
    renderScreensList();
    loadActiveScreenToEditor();
}

function handleScreenTitleChange(input) {
    const val = input.value;
    document.getElementById('screenTitleCounter').innerText = `${val.length} / 20`;
    currentFlow.screens[activeScreenIndex].title = val;
    currentFlow.screens[activeScreenIndex].name = val;
    renderLivePhonePreview(currentFlow.screens[activeScreenIndex]);
}

function handleScreenBodyChange(textarea) {
    const val = textarea.value;
    document.getElementById('screenBodyCounter').innerText = `${val.length} / 4096`;
    currentFlow.screens[activeScreenIndex].body = val;
    renderLivePhonePreview(currentFlow.screens[activeScreenIndex]);
}

function handleFooterLabelChange(input) {
    const val = input.value;
    document.getElementById('footerLabelCounter').innerText = `${val.length} / 30`;
    currentFlow.screens[activeScreenIndex].footer_label = val;
    renderLivePhonePreview(currentFlow.screens[activeScreenIndex]);
}

function handleFooterActionChange(select) {
    currentFlow.screens[activeScreenIndex].footer_action = select.value;
}

function addNewComponentField() {
    const select = document.getElementById('addFieldTypeSelect');
    const type = select.value;
    
    if (!currentFlow.screens[activeScreenIndex].components) {
        currentFlow.screens[activeScreenIndex].components = [];
    }

    currentFlow.screens[activeScreenIndex].components.push({
        id: 'c_' + Date.now(),
        type: type,
        label: type === 'Dropdown' ? 'Bill Format Issue' : (type === 'Text Area' ? 'Problem Description' : 'Short Answer'),
        helper: '',
        required: false
    });

    renderComponentsEditor(currentFlow.screens[activeScreenIndex]);
    renderLivePhonePreview(currentFlow.screens[activeScreenIndex]);
}

function updateComponentProp(index, prop, value, el) {
    currentFlow.screens[activeScreenIndex].components[index][prop] = value;
    if (el && el.nextElementSibling && el.nextElementSibling.classList.contains('char-counter')) {
        const max = prop === 'label' ? 20 : 80;
        el.nextElementSibling.innerText = `${(value || '').length} / ${max}`;
    }
    renderLivePhonePreview(currentFlow.screens[activeScreenIndex]);
}

function deleteComponentField(index) {
    currentFlow.screens[activeScreenIndex].components.splice(index, 1);
    renderComponentsEditor(currentFlow.screens[activeScreenIndex]);
    renderLivePhonePreview(currentFlow.screens[activeScreenIndex]);
}

function saveFlowData(isPreview) {
    const payload = {
        action: 'save',
        flow_id: currentFlow.flow_id,
        name: currentFlow.name,
        category: currentFlow.category,
        status: currentFlow.status,
        screens: currentFlow.screens
    };

    fetch('api/bot_flows.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Meta Flow configuration saved successfully!');
            if (isPreview) {
                window.location.href = 'index.php?page=bot_flows';
            }
        } else {
            alert('Save failed: ' + (data.message || 'Error'));
        }
    })
    .catch(err => alert('Save error: ' + err));
}

function escapeHtml(str) {
    return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
</script>
