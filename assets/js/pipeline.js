/**
 * Lead Status Pipeline - HTML5 Drag & Drop controller
 */

document.addEventListener('DOMContentLoaded', () => {
    const cards = document.querySelectorAll('.kanban-card');
    const columns = document.querySelectorAll('.pipeline-column');

    cards.forEach(card => {
        // Drag Start
        card.addEventListener('dragstart', (e) => {
            card.classList.add('dragging');
            e.dataTransfer.setData('text/plain', card.id);
            e.dataTransfer.effectAllowed = 'move';
        });

        // Drag End
        card.addEventListener('dragend', () => {
            card.classList.remove('dragging');
            
            // Clean up all columns highlights
            columns.forEach(col => col.classList.remove('drag-over'));
        });
    });

    columns.forEach(column => {
        const list = column.querySelector('.cards-list');

        // Drag Over Column
        column.addEventListener('dragover', (e) => {
            e.preventDefault(); // Required to allow drop
            column.classList.add('drag-over');
        });

        // Drag Leave Column
        column.addEventListener('dragleave', () => {
            column.classList.remove('drag-over');
        });

        // Drop Card in Column
        column.addEventListener('drop', (e) => {
            e.preventDefault();
            column.classList.remove('drag-over');

            const cardId = e.dataTransfer.getData('text/plain');
            const card = document.getElementById(cardId);
            
            if (card && list) {
                // Keep track of source column to adjust count
                const sourceColumn = card.closest('.pipeline-column');
                
                // Append card to new list
                list.appendChild(card);
                
                // Recalculate columns header counters
                updateColumnCounters(sourceColumn);
                updateColumnCounters(column);

                // Show confirmation toast
                showToastNotification(`Lead status updated to: ${column.querySelector('.column-title').textContent.trim()}`);
            }
        });
    });

    // Helper: Recalculate the lead card counters in each column
    function updateColumnCounters(colEl) {
        if (!colEl) return;
        const counter = colEl.querySelector('.column-counter');
        const count = colEl.querySelectorAll('.kanban-card').length;
        if (counter) {
            counter.textContent = count;
        }
    }

    // Helper: Display floating feedback toast
    function showToastNotification(message) {
        // Check if toast container exists
        let toast = document.getElementById('pipeline-toast-notification');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'pipeline-toast-notification';
            toast.style.position = 'fixed';
            toast.style.bottom = '2rem';
            toast.style.right = '2rem';
            toast.style.backgroundColor = 'var(--primary)';
            toast.style.color = '#ffffff';
            toast.style.padding = '0.75rem 1.5rem';
            toast.style.borderRadius = 'var(--border-radius-sm)';
            toast.style.boxShadow = 'var(--shadow-lg)';
            toast.style.zIndex = '10000';
            toast.style.fontSize = '0.875rem';
            toast.style.fontWeight = '600';
            toast.style.transition = 'opacity var(--transition-fast)';
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.style.opacity = '1';

        setTimeout(() => {
            toast.style.opacity = '0';
        }, 2500);
    }
});
