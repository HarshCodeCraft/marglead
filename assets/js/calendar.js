/**
 * Follow-up Planner - Calendar navigation and filters controller
 */

document.addEventListener('DOMContentLoaded', () => {
    const calendarMonthTitle = document.querySelector('.calendar-month-title');
    const prevMonthBtn = document.getElementById('prev-month');
    const nextMonthBtn = document.getElementById('next-month');
    const dayCells = document.querySelectorAll('.day-cell:not(.inactive)');

    // Mock Calendar navigation
    const months = [
        'January 2026', 'February 2026', 'March 2026', 'April 2026', 
        'May 2026', 'June 2026', 'July 2026', 'August 2026', 
        'September 2026', 'October 2026', 'November 2026', 'December 2026'
    ];
    let currentMonthIndex = 6; // July 2026 (Prototyping baseline)

    if (prevMonthBtn && nextMonthBtn && calendarMonthTitle) {
        prevMonthBtn.addEventListener('click', () => {
            if (currentMonthIndex > 0) {
                currentMonthIndex--;
                calendarMonthTitle.textContent = months[currentMonthIndex];
                mockRedrawCalendar();
            }
        });

        nextMonthBtn.addEventListener('click', () => {
            if (currentMonthIndex < months.length - 1) {
                currentMonthIndex++;
                calendarMonthTitle.textContent = months[currentMonthIndex];
                mockRedrawCalendar();
            }
        });
    }

    // Cell click handler to schedule a follow-up
    dayCells.forEach(cell => {
        cell.addEventListener('click', () => {
            const dayNum = cell.querySelector('.day-num').textContent.trim();
            // Format standard ISO date string
            const monthText = calendarMonthTitle ? calendarMonthTitle.textContent.split(' ')[0] : 'July';
            const yearText = calendarMonthTitle ? calendarMonthTitle.textContent.split(' ')[1] : '2026';
            
            const monthMap = {
                'January': '01', 'February': '02', 'March': '03', 'April': '04', 'May': '05', 'June': '06',
                'July': '07', 'August': '08', 'September': '09', 'October': '10', 'November': '11', 'December': '12'
            };
            const padDay = dayNum.padStart(2, '0');
            const formatStr = `${yearText}-${monthMap[monthText]}-${padDay}T10:00`;
            
            // Set value inside datetime input in the modal
            const dtInput = document.querySelector('#schedule-followup-modal input[type="datetime-local"]');
            if (dtInput) {
                dtInput.value = formatStr;
            }
            
            // Open modal
            if (typeof window.openModal === 'function') {
                window.openModal('schedule-followup-modal');
            }
        });
    });

    // Toggle Calendar Events view filters
    const filterButtons = document.querySelectorAll('.calendar-filter-btn');
    filterButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            filterButtons.forEach(b => b.classList.remove('active', 'btn-primary'));
            filterButtons.forEach(b => b.classList.add('btn-secondary'));
            
            btn.classList.remove('btn-secondary');
            btn.classList.add('active', 'btn-primary');

            const filterVal = btn.getAttribute('data-filter');
            const events = document.querySelectorAll('.calendar-event');
            
            events.forEach(event => {
                if (filterVal === 'all') {
                    event.style.display = 'block';
                } else {
                    const statusClass = event.className;
                    if (statusClass.includes(filterVal)) {
                        event.style.display = 'block';
                    } else {
                        event.style.display = 'none';
                    }
                }
            });
        });
    });

    // Helper: simulate redrawing cell events
    function mockRedrawCalendar() {
        // Just clear existing events from cells to show page change visual
        const events = document.querySelectorAll('.calendar-event');
        events.forEach(e => {
            // Randomly toggle visibility to simulate different dates load
            e.style.display = Math.random() > 0.4 ? 'block' : 'none';
        });
    }
});
