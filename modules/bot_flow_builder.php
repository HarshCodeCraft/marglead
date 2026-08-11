<?php
/**
 * Marg Soft Solution - Interactive WhatsApp Flow Builder & Smartphone Simulator
 * Screenshots 2 & 3 Implementation
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$flow_id = trim($_GET['q'] ?? $_GET['id'] ?? '2356038494923110');

// Initial default fallback data matching screenshot
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
            $flowData['name'] = $row['name'];
            $flowData['category'] = $row['category'];
            $flowData['status'] = $row['status'];
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

<div class="bot-flows-container">
    <div class="bot-module-layout" style="grid-template-columns: 80px 1fr;">
        
        <!-- Left Subnav Bar -->
        <div class="bot-subnav">
            <a href="index.php?page=bot_flows" class="bot-subnav-item">
                <i data-lucide="bot" style="width: 22px; height: 22px;"></i>
                <span>Bots</span>
            </a>
            <a href="index.php?page=bot_flows" class="bot-subnav-item active">
                <i data-lucide="git-fork" style="width: 22px; height: 22px;"></i>
                <span>Flows</span>
            </a>
            <a href="#" class="bot-subnav-item">
                <i data-lucide="file-text" style="width: 22px; height: 22px;"></i>
                <span>Events</span>
            </a>
            <a href="#" class="bot-subnav-item">
                <i data-lucide="users" style="width: 22px; height: 22px;"></i>
                <span>Inggers</span>
            </a>
            <a href="#" class="bot-subnav-item">
                <i data-lucide="repeat" style="width: 22px; height: 22px;"></i>
                <span>Re-Engagement</span>
            </a>
            <a href="#" class="bot-subnav-item">
                <i data-lucide="book-open" style="width: 22px; height: 22px;"></i>
                <span>Reports</span>
            </a>
        </div>

        <!-- Flow Builder 3-Column Interface -->
        <div class="flow-builder-container">
            
            <!-- COLUMN 1: SCREENS -->
            <div class="builder-card">
                <h3 class="builder-column-title">Screens</h3>
                
                <div id="screensList" style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <!-- Rendered by JS -->
                </div>

                <!-- Add New Screen Box -->
                <div class="add-screen-box">
                    <div class="add-screen-header">
                        <span><i data-lucide="plus-circle" style="width: 16px; height: 16px; display: inline-block; vertical-align: text-top;"></i> Add New</span>
                        <i data-lucide="chevron-up" style="width: 16px; height: 16px;"></i>
                    </div>
                    
                    <div style="position: relative;">
                        <input type="text" id="newScreenNameInput" class="input-styled" maxlength="20" placeholder="Screen name" oninput="updateNewScreenCharCount(this)">
                        <div class="char-counter" id="newScreenCharCounter" style="margin-top: 4px;">0 / 20</div>
                    </div>

                    <div style="text-align: right;">
                        <button type="button" class="btn-pill btn-pill-primary" style="background-color: #047857; padding: 0.35rem 1.25rem;" onclick="addNewScreen()">Add</button>
                    </div>
                </div>

                <div style="margin-top: auto; padding-top: 1rem;">
                    <button type="button" class="btn-pill btn-pill-primary" style="background-color: #047857; width: 100%; justify-content: center;">
                        Endpoint
                    </button>
                </div>
            </div>

            <!-- COLUMN 2: EDIT CONTENT -->
            <div class="builder-card" style="overflow-y: auto; max-height: calc(100vh - 120px);">
                <h3 class="builder-column-title">Edit Content</h3>

                <!-- 1. Screen Title Section -->
                <div class="content-block">
                    <div class="content-block-header">
                        <span>Screen Title</span>
                        <i data-lucide="chevron-up" style="width: 16px; height: 16px;"></i>
                    </div>
                    <div>
                        <input type="text" id="editScreenTitle" class="input-styled" maxlength="20" oninput="handleScreenTitleChange(this)">
                        <div class="char-counter" id="screenTitleCounter">0 / 20</div>
                    </div>
                </div>

                <!-- 2. Body Text Section -->
                <div class="content-block">
                    <div class="content-block-header">
                        <span>+ Body</span>
                        <i data-lucide="chevron-up" style="width: 16px; height: 16px;"></i>
                    </div>
                    <div>
                        <textarea id="editScreenBody" class="input-styled" maxlength="4096" oninput="handleScreenBodyChange(this)"></textarea>
                        <div class="char-counter" id="screenBodyCounter">0 / 4096</div>
                    </div>
                </div>

                <!-- 3. Dynamic Form Field Components Container -->
                <div id="componentsContainer" style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <!-- Rendered by JS -->
                </div>

                <!-- Add Component Field Button -->
                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <select id="addFieldTypeSelect" class="input-styled" style="width: 70%;">
                        <option value="Short Answer">Short Answer (Text)</option>
                        <option value="Dropdown">Dropdown (Choice)</option>
                        <option value="Text Area">Text Area (Long Text)</option>
                    </select>
                    <button type="button" class="btn-pill btn-pill-outline" style="width: 30%; justify-content: center;" onclick="addNewComponentField()">+ Add Field</button>
                </div>

                <!-- 4. Footer Section -->
                <div class="content-block" style="margin-top: 1rem;">
                    <div class="content-block-header">
                        <span>Footer</span>
                        <i data-lucide="chevron-up" style="width: 16px; height: 16px;"></i>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <div>
                            <input type="text" id="editFooterLabel" class="input-styled" maxlength="30" oninput="handleFooterLabelChange(this)">
                            <div class="char-counter" id="footerLabelCounter">0 / 30</div>
                        </div>

                        <div>
                            <label class="form-label font-semibold text-xs text-muted mb-1" style="display: block;">On-click action</label>
                            <select id="editFooterAction" class="input-styled" onchange="handleFooterActionChange(this)">
                                <option value="Complete">Complete</option>
                                <option value="Navigate to Next Screen">Navigate to Next Screen</option>
                                <option value="Data Exchange">Data Exchange</option>
                            </select>
                        </div>
                    </div>
                </div>

            </div>

            <!-- COLUMN 3: LIVE PREVIEW SMARTPHONE SIMULATOR -->
            <div class="builder-card builder-preview-column">
                <h3 class="builder-column-title">Preview</h3>

                <div class="phone-mockup-frame">
                    <!-- WhatsApp Header -->
                    <div class="phone-header-bar">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <a href="#" style="color: #ffffff; text-decoration: none;">&times;</a>
                            <span class="phone-header-title" id="previewHeaderTitle">Welcome to Marg Soft</span>
                        </div>
                        <i data-lucide="more-vertical" style="width: 16px; height: 16px;"></i>
                    </div>

                    <!-- WhatsApp Card Body -->
                    <div class="phone-body-content">
                        <div class="whatsapp-flow-card">
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
</div>

<!-- Bottom Control Action Bar -->
<div class="builder-bottom-bar">
    <button type="button" class="btn-pill btn-pill-primary" style="background-color: #047857;" onclick="saveFlowData(false)">
        Save
    </button>
    <button type="button" class="btn-pill btn-pill-primary" style="background-color: #065f46;" onclick="saveFlowData(true)">
        Save & Preview
    </button>
</div>

<!-- Floating Help Widget -->
<button type="button" class="floating-need-help" style="bottom: 70px;" onclick="alert('Marg Flow Builder: Interactive form helper ready.')">
    Need Help?
</button>

<script>
// Initial state object from PHP backend
let currentFlow = <?php echo json_encode($flowData); ?>;
let activeScreenIndex = 0;

document.addEventListener('DOMContentLoaded', () => {
    renderScreensList();
    loadActiveScreenToEditor();
});

function renderScreensList() {
    const container = document.getElementById('screensList');
    container.innerHTML = '';

    currentFlow.screens.forEach((screen, idx) => {
        const item = document.createElement('div');
        item.className = 'screen-item ' + (idx === activeScreenIndex ? 'active' : '');
        item.innerHTML = `<i data-lucide="plus" style="width:16px; height:16px;"></i> <span>${escapeHtml(screen.name || 'Screen ' + (idx + 1))}</span>`;
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
        block.innerHTML = `
            <div class="content-block-header">
                <span>+ ${escapeHtml(comp.type || 'Short Answer')}</span>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="trash-2" style="width: 16px; height: 16px; color: #ef4444; cursor: pointer;" onclick="deleteComponentField(${idx})"></i>
                    <i data-lucide="chevron-up" style="width: 16px; height: 16px;"></i>
                </div>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                <div>
                    <input type="text" class="input-styled" value="${escapeHtml(comp.label || '')}" maxlength="20" placeholder="Label / Field Title" oninput="updateComponentProp(${idx}, 'label', this.value, this)">
                    <div class="char-counter">${(comp.label || '').length} / 20</div>
                </div>

                <div>
                    <input type="text" class="input-styled" value="${escapeHtml(comp.helper || '')}" maxlength="80" placeholder="Helper text (optional)" oninput="updateComponentProp(${idx}, 'helper', this.value, this)">
                    <div class="char-counter">${(comp.helper || '').length} / 80</div>
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <label class="toggle-switch">
                        <input type="checkbox" ${comp.required ? 'checked' : ''} onchange="updateComponentProp(${idx}, 'required', this.checked)">
                        <span class="toggle-slider"></span>
                        <span>Required</span>
                    </label>
                </div>
            </div>
        `;
        container.appendChild(block);
    });
    if (window.lucide) lucide.createIcons();
}

function renderLivePhonePreview(screen) {
    document.getElementById('previewHeaderTitle').innerText = screen.title || screen.name || 'Welcome to Marg Soft';
    document.getElementById('previewCardBody').innerText = screen.body || 'Please Provide Your Info and Problem Here..';
    document.getElementById('previewFooterBtn').innerText = screen.footer_label || 'Submit';

    const fieldsContainer = document.getElementById('previewFieldsContainer');
    fieldsContainer.innerHTML = '';

    if (screen.components && screen.components.length > 0) {
        screen.components.forEach(c => {
            const fieldGroup = document.createElement('div');
            fieldGroup.className = 'flow-preview-field';
            
            const label = document.createElement('div');
            label.className = 'flow-preview-label';
            label.innerText = c.label + (c.required ? ' *' : '');
            fieldGroup.appendChild(label);

            if (c.type === 'Dropdown') {
                const select = document.createElement('select');
                select.className = 'flow-preview-input';
                const opts = c.options || [c.label, 'GST Error', 'Printer Setup'];
                opts.forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt;
                    o.innerText = opt;
                    select.appendChild(o);
                });
                fieldGroup.appendChild(select);
            } else if (c.type === 'Text Area') {
                const textarea = document.createElement('textarea');
                textarea.className = 'flow-preview-input';
                textarea.style.height = '50px';
                textarea.placeholder = c.helper || c.label;
                fieldGroup.appendChild(textarea);
            } else {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'flow-preview-input';
                input.placeholder = c.helper || c.label;
                fieldGroup.appendChild(input);
            }

            fieldsContainer.appendChild(fieldGroup);
        });
    }
}

// Editor Event Handlers
function updateNewScreenCharCount(input) {
    document.getElementById('newScreenCharCounter').innerText = `${input.value.length} / 20`;
}

function addNewScreen() {
    const input = document.getElementById('newScreenNameInput');
    const name = input.value.trim();
    if (!name) return;

    currentFlow.screens.push({
        id: 'screen_' + (currentFlow.screens.length + 1),
        name: name,
        title: name,
        body: 'Provide info and details here..',
        components: [
            { id: 'c1', type: 'Short Answer', label: 'License Number', helper: 'Client Id', required: true }
        ],
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
        label: type === 'Dropdown' ? 'Bill Format Issue' : 'New Field',
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
            alert('Flow configuration saved successfully!');
            if (isPreview) {
                window.location.href = 'index.php?page=bot_flows';
            }
        } else {
            alert('Save failed: ' + data.message);
        }
    })
    .catch(err => alert('Save error: ' + err));
}

function escapeHtml(str) {
    return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
</script>
