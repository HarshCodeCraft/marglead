/**
 * Lead Details Folder - Interactive Tabs and Mock Actions Controls
 */

document.addEventListener('DOMContentLoaded', () => {
    // 12-Tab Switching Core Logic
    const tabLinks = document.querySelectorAll('.tab-link');
    const tabPanes = document.querySelectorAll('.tab-pane');

    tabLinks.forEach(link => {
        link.addEventListener('click', () => {
            const targetPaneId = link.getAttribute('data-tab');
            const targetPane = document.getElementById(targetPaneId);

            // Deactivate all links and panes
            tabLinks.forEach(l => l.classList.remove('active'));
            tabPanes.forEach(p => p.classList.remove('active'));

            // Activate chosen components
            link.classList.add('active');
            if (targetPane) {
                targetPane.classList.add('active');
            }

            // Trigger window resize event to redraw any graphs or charts inside tabs if needed
            window.dispatchEvent(new Event('resize'));
        });
    });

    // Installation checklist dynamics (update percentage progress bar)
    const checkBoxes = document.querySelectorAll('.install-checklist-item input[type="checkbox"]');
    const progressBar = document.getElementById('install-progress-bar');
    const progressLabel = document.getElementById('install-progress-percentage');

    const updateInstallProgress = () => {
        if (!checkBoxes.length || !progressBar) return;
        
        const checkedCount = Array.from(checkBoxes).filter(cb => cb.checked).length;
        const percent = Math.round((checkedCount / checkBoxes.length) * 100);
        
        progressBar.style.width = `${percent}%`;
        progressLabel.textContent = `${percent}% Completed`;
        
        if (percent === 100) {
            progressBar.style.backgroundColor = 'var(--success)';
        } else {
            progressBar.style.backgroundColor = 'var(--primary)';
        }
    };

    checkBoxes.forEach(cb => {
        cb.addEventListener('change', () => {
            updateInstallProgress();
            
            // Gather all checked checkbox values
            const checkedValues = Array.from(checkBoxes)
                                      .filter(box => box.checked)
                                      .map(box => box.value);
                                      
            const leadIdElement = document.getElementById('lead-id-val');
            if (!leadIdElement) return;
            const leadId = leadIdElement.value;
            
            // Send AJAX autosave request
            const formData = new FormData();
            formData.append('action', 'save_install_status');
            formData.append('lead_id', leadId);
            checkedValues.forEach(val => formData.append('items[]', val));
            
            fetch('index.php?page=lead_details&id=' + leadId, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Installation status auto-saved.');
                } else {
                    console.error('Auto-save failed:', data.message);
                }
            })
            .catch(err => {
                console.error('AJAX error during auto-save:', err);
            });
        });
    });

    // Run once at loading
    updateInstallProgress();
});

// Helper: Append a mock message to the WhatsApp chat box
function sendMockWhatsAppMessage() {
    const textInput = document.getElementById('wa-message-input');
    const chatContainer = document.getElementById('wa-chat-thread');
    
    if (!textInput || !textInput.value.trim() || !chatContainer) return;
    
    const messageText = textInput.value.trim();
    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    
    const messageHTML = `
        <div class="chat-message sent flex flex-col align-end" style="align-self: flex-end; max-width: 70%; background-color: var(--primary-light); color: var(--text-main); border: 1px solid var(--border-color); padding: 0.5rem 1rem; border-radius: 12px 12px 0 12px; margin-bottom: 0.75rem; border-left: 3px solid var(--primary);">
            <div class="message-text text-sm">${messageText}</div>
            <div class="message-time text-xs text-muted" style="margin-top: 0.25rem;">${time} • Sent via API</div>
        </div>
    `;
    
    chatContainer.insertAdjacentHTML('beforeend', messageHTML);
    textInput.value = '';
    
    // Auto Scroll to bottom
    chatContainer.scrollTop = chatContainer.scrollHeight;
}
