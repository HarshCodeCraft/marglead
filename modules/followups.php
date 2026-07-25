<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$fup_leads = [];
if ($db_connected && $pdo) {
    try {
        $fup_leads = $pdo->query("SELECT id, name, company FROM leads ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}
// No mock fallback for leads

// Prepare July 2026 grid variables (starts on Wednesday, 31 days)
$days_in_month = 31;
$start_weekday = 3; // Wednesday (0: Sun, 1: Mon, 2: Tue, 3: Wed, ...)
$total_cells = 42;  // 6 rows * 7 columns

$calendar_events = [];
$today_agenda = [];

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

if ($db_connected && $pdo) {
    try {
        if ($is_admin) {
            $stmtFup = $pdo->query("SELECT f.*, l.name as lead_name, l.company as lead_company, l.priority as lead_priority FROM followups f JOIN leads l ON f.lead_id = l.id ORDER BY f.scheduled_at ASC");
        } else {
            $stmtFup = $pdo->prepare("SELECT f.*, l.name as lead_name, l.company as lead_company, l.priority as lead_priority FROM followups f JOIN leads l ON f.lead_id = l.id WHERE (f.assigned_to = ? OR l.assigned_to = ?) ORDER BY f.scheduled_at ASC");
            $stmtFup->execute([$user_name, $user_name]);
        }
        $db_fups = $stmtFup->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($db_fups as $f) {
            $scheduled = strtotime($f['scheduled_at']);
            $f_month = date('n', $scheduled);
            $f_year = date('Y', $scheduled);
            $f_day = intval(date('j', $scheduled));
            
            $class = 'cold';
            if ($f['status'] === 'completed') {
                $class = 'completed';
            } elseif ($f['status'] === 'missed') {
                $class = 'hot';
            } elseif ($f['lead_priority'] === 'hot') {
                $class = 'hot';
            } elseif ($f['lead_priority'] === 'warm') {
                $class = 'warm';
            }
            
            if ($f_month == 7 && $f_year == 2026) {
                if (!isset($calendar_events[$f_day])) {
                    $calendar_events[$f_day] = [];
                }
                $calendar_events[$f_day][] = [
                    'title' => $f['lead_company'] . " (" . $f['action_type'] . ")",
                    'time' => date('h:i A', $scheduled),
                    'class' => $class
                ];
            }
            
            if ($f_month == 7 && $f_year == 2026 && $f_day == 22) {
                $today_agenda[] = [
                    'time' => date('h:i A', $scheduled),
                    'priority' => ucfirst($f['lead_priority']),
                    'priority_class' => ($f['lead_priority'] === 'hot' || $f['status'] === 'missed') ? 'danger' : (($f['lead_priority'] === 'warm') ? 'warning' : 'info'),
                    'company' => $f['lead_company'],
                    'remarks' => $f['remarks'] ?: "Follow-up " . $f['action_type']
                ];
            }
        }
    } catch (PDOException $e) {}
}

// No calendar events mock fallback
?>

<div class="followups-planner-container">
    <!-- Header Controls -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Follow-up Calendar Planner</h2>
            <p class="text-muted text-sm">Schedule phone calls, set demo dates, track renewal reminders, and review pending customer engagements.</p>
        </div>
        <button class="btn btn-primary text-sm" onclick="window.openModal('schedule-followup-modal')">
            <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
            <span>Schedule Follow-up</span>
        </button>
    </div>

    <!-- Calendar Layout: Double Column Grid (List panel + Calendar Grid) -->
    <div class="grid" style="grid-template-columns: 280px minmax(0, 1fr); gap: 1.5rem; align-items: start;">
        
        <!-- Left: Action Lists Column -->
        <div class="flex flex-col gap-4">
            <!-- Dynamic Filters -->
            <div class="card p-4 flex flex-col gap-2" style="border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold mb-2" style="text-transform: uppercase; letter-spacing: 0.05em;">Planner Filters</h4>
                <button class="btn btn-primary active calendar-filter-btn text-xs" data-filter="all" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="layers" style="width: 14px; height: 14px;"></i>
                    <span>All Follow-ups</span>
                </button>
                <button class="btn btn-secondary calendar-filter-btn text-xs" data-filter="hot" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="alert-circle" style="width: 14px; height: 14px; color: var(--danger);"></i>
                    <span>Missed Follow-ups</span>
                </button>
                <button class="btn btn-secondary calendar-filter-btn text-xs" data-filter="warm" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="clock" style="width: 14px; height: 14px; color: var(--warning);"></i>
                    <span>Today's Follow-ups</span>
                </button>
                <button class="btn btn-secondary calendar-filter-btn text-xs" data-filter="completed" style="justify-content: flex-start; padding: 0.5rem 1rem;">
                    <i data-lucide="check-circle-2" style="width: 14px; height: 14px; color: var(--success);"></i>
                    <span>Completed Actions</span>
                </button>
            </div>

            <!-- Today's Follow-ups Panel -->
            <div class="card p-4" style="border: 1px solid var(--border-color);">
                <h4 class="text-xs text-muted font-bold mb-3" style="text-transform: uppercase; letter-spacing: 0.05em;">Today's Agenda</h4>
                <div class="flex flex-col gap-3">
                    <?php if (empty($today_agenda)): ?>
                        <div class="text-center py-4 text-xs text-muted">
                            No follow-ups scheduled for today.
                        </div>
                    <?php else: ?>
                        <?php foreach ($today_agenda as $item): ?>
                            <div class="agenda-item p-3" style="background-color: var(--<?php echo $item['priority_class']; ?>-light); border-left: 3px solid var(--<?php echo $item['priority_class']; ?>); border-radius: var(--border-radius-sm); margin-bottom: 0.5rem;">
                                <div class="flex justify-between align-center mb-1">
                                    <span class="text-xs font-semibold"><?php echo htmlspecialchars($item['time']); ?></span>
                                    <span class="badge text-xs" style="--badge-bg: var(--<?php echo $item['priority_class']; ?>-light); --badge-color: var(--<?php echo $item['priority_class']; ?>);"><?php echo htmlspecialchars($item['priority']); ?></span>
                                </div>
                                <h5 class="text-sm font-semibold mb-1"><?php echo htmlspecialchars($item['company']); ?></h5>
                                <p class="text-xs text-muted"><?php echo htmlspecialchars($item['remarks']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Month Calendar Grid -->
        <div class="calendar-container">
            <!-- Calendar Header controls -->
            <div class="calendar-header-row">
                <h3 class="calendar-month-title">July 2026</h3>
                <div class="flex align-center gap-1">
                    <button class="btn btn-icon btn-sm" id="prev-month"><i data-lucide="chevron-left" style="width: 16px; height: 16px;"></i></button>
                    <button class="btn btn-icon btn-sm" id="next-month"><i data-lucide="chevron-right" style="width: 16px; height: 16px;"></i></button>
                </div>
            </div>

            <!-- Days of Week Header row -->
            <div class="calendar-weekdays-grid">
                <div class="weekday-cell">Sun</div>
                <div class="weekday-cell">Mon</div>
                <div class="weekday-cell">Tue</div>
                <div class="weekday-cell">Wed</div>
                <div class="weekday-cell">Thu</div>
                <div class="weekday-cell">Fri</div>
                <div class="weekday-cell">Sat</div>
            </div>

            <!-- 42 Days Matrix Grid -->
            <div class="calendar-days-grid">
                <?php
                // 1. Previous month trailing cells
                $prev_month_days = 30; // June has 30 days
                $trail_count = $start_weekday;
                for ($t = $trail_count - 1; $t >= 0; $t--) {
                    $day_num = $prev_month_days - $t;
                    echo '<div class="day-cell inactive">
                            <span class="day-num">' . $day_num . '</span>
                          </div>';
                }

                // 2. Current Month active cells
                for ($d = 1; $d <= $days_in_month; $d++) {
                    $is_current = ($d === 22) ? 'current-day' : '';
                    echo '<div class="day-cell ' . $is_current . '">
                            <span class="day-num">' . $d . '</span>';
                    
                    // Render events mapping to day cell
                    if (isset($calendar_events[$d])) {
                        foreach ($calendar_events[$d] as $ev) {
                            echo '<div class="calendar-event ' . $ev['class'] . '" title="' . $ev['title'] . '">
                                    ' . $ev['time'] . ' ' . $ev['title'] . '
                                  </div>';
                        }
                    }
                    
                    echo '</div>';
                }

                // 3. Next month trailing cells
                $rendered = $start_weekday + $days_in_month;
                $remaining = $total_cells - $rendered;
                for ($n = 1; $n <= $remaining; $n++) {
                    echo '<div class="day-cell inactive">
                            <span class="day-num">' . $n . '</span>
                          </div>';
                }
                ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Schedule Follow-up (Reused across planner) -->
<div id="schedule-followup-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="m-0" style="font-family: var(--font-heading);">Schedule Follow-up reminder</h3>
            <button class="btn-icon" onclick="window.closeModal('schedule-followup-modal')"><i data-lucide="x" style="width: 16px; height: 16px;"></i></button>
        </div>
        <form class="modal-body flex flex-col gap-4" action="index.php?action=schedule_followup" method="POST">
            <input type="hidden" name="assigned_to" value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'System'); ?>">
            <div class="form-group m-0">
                <label class="form-label text-xs">Date & Time</label>
                <input type="datetime-local" name="scheduled_at" class="form-control" required value="2026-07-22T10:00">
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Action Type</label>
                <select name="action_type" class="form-control">
                    <option value="Trail Installed">Trail Installed</option>
                    <option value="Data Input Follow Up">Data Input Follow Up</option>
                    <option value="Payment Followup">Payment Followup</option>
                    <option value="Rest Amt Followup">Rest Amt Followup</option>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Target Customer Lead</label>
                <select name="lead_id" class="form-control" required>
                    <?php foreach ($fup_leads as $ld): ?>
                        <option value="<?php echo htmlspecialchars($ld['id']); ?>">
                            <?php echo htmlspecialchars($ld['company'] . ' (' . $ld['name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group m-0">
                <label class="form-label text-xs">Reminder notes / Instructions</label>
                <textarea name="remarks" class="form-control" rows="3" placeholder="Input specific goals..."></textarea>
            </div>
            <div class="flex gap-4 mt-2">
                <label class="flex align-center gap-2 text-xs font-semibold pointer">
                    <input type="checkbox" name="send_email" value="1" checked style="width: 14px; height: 14px;">
                    <span>Send Email Notification</span>
                </label>
                <label class="flex align-center gap-2 text-xs font-semibold pointer">
                    <input type="checkbox" name="send_sms" value="1" checked style="width: 14px; height: 14px;">
                    <span>Send Free Carrier SMS</span>
                </label>
            </div>
            
            <div class="form-group mt-2">
                <label class="form-label text-xs">SMS Notification Targets</label>
                <div class="flex flex-col gap-2" style="background: var(--border-card); padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                    <label class="flex align-center gap-2 text-xs pointer">
                        <input type="checkbox" name="sms_targets[]" value="client" checked style="width: 14px; height: 14px;">
                        <span>Client Phone:</span>
                        <input type="text" name="sms_client_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="9876543210" placeholder="Client Phone">
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer mt-1">
                        <input type="checkbox" name="sms_targets[]" value="employee" checked style="width: 14px; height: 14px;">
                        <span>Employee Phone:</span>
                        <input type="text" name="sms_employee_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="9012345678" placeholder="Employee Phone">
                    </label>
                    <label class="flex align-center gap-2 text-xs pointer mt-1">
                        <input type="checkbox" name="sms_targets[]" value="admin" checked style="width: 14px; height: 14px;">
                        <span>Admin Phone:</span>
                        <input type="text" name="sms_admin_phone" class="form-control text-xs" style="height: 24px; padding: 2px 8px; width: 140px; margin-left: auto;" value="7860510928">
                    </label>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary text-sm" onclick="window.closeModal('schedule-followup-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary text-sm">Save Reminder</button>
            </div>
        </form>
    </div>
</div>
