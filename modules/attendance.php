<?php
/**
 * Marg ERP CRM - Employee Attendance & Geo-Punch Center
 * Biometric Webcam Capture, Real-time GPS Geofencing, Multi-Tenant Isolation & Executive Approvals
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Employee';
$user_id   = (int)($_SESSION['user_id'] ?? 1);
$tenant_id = (int)($_SESSION['tenant_id'] ?? 0);
$is_admin  = ($user_role === 'Admin' || $user_role === 'Super Admin' || $user_role === 'Tenant Admin');
$active_tab = trim($_GET['tab'] ?? ($is_admin ? 'board' : 'punch'));

$today = date('Y-m-d');
$todayPunch = null;
$officeSettings = null;
$userShift = null;
if (!empty($pdo) && $user_id > 0) {
    try {
        $stmtInit = $pdo->prepare("SELECT * FROM employee_attendance WHERE user_id = ? AND attendance_date = ? LIMIT 1");
        $stmtInit->execute([$user_id, $today]);
        $todayPunch = $stmtInit->fetch(PDO::FETCH_ASSOC);

        $stmtSett = $pdo->prepare("SELECT * FROM attendance_settings WHERE " . ($tenant_id > 0 ? "tenant_id = ?" : "id = 1") . " LIMIT 1");
        if ($tenant_id > 0) {
            $stmtSett->execute([$tenant_id]);
        } else {
            $stmtSett->execute();
        }
        $officeSettings = $stmtSett->fetch(PDO::FETCH_ASSOC);
        if (!$officeSettings) {
            $stmtDef = $pdo->query("SELECT * FROM attendance_settings WHERE id = 1 LIMIT 1");
            $officeSettings = $stmtDef ? $stmtDef->fetch(PDO::FETCH_ASSOC) : null;
        }
        if ($officeSettings) {
            $officeSettings['is_configured'] = (!empty($officeSettings['office_lat']) && (float)$officeSettings['office_lat'] != 0) ? 1 : (isset($officeSettings['is_configured']) ? (int)$officeSettings['is_configured'] : 0);
        }

        $stmtShift = $pdo->prepare("SELECT * FROM employee_shifts WHERE user_id = ? LIMIT 1");
        $stmtShift->execute([$user_id]);
        $rowShift = $stmtShift->fetch(PDO::FETCH_ASSOC);
        if ($rowShift && !empty($rowShift['shift_start_time']) && !empty($rowShift['shift_end_time'])) {
            $userShift = [
                'is_custom' => true,
                'shift_name' => $rowShift['shift_name'] ?: 'Custom Shift',
                'shift_start_time' => $rowShift['shift_start_time'],
                'shift_end_time' => $rowShift['shift_end_time'],
                'grace_period_minutes' => (int)($rowShift['grace_period_minutes'] ?? 15)
            ];
        }
    } catch (\Throwable $e) {}
}

if (!$userShift) {
    $userShift = [
        'is_custom' => false,
        'shift_name' => 'Standard Shift',
        'shift_start_time' => $officeSettings['shift_start_time'] ?? '10:15:00',
        'shift_end_time' => $officeSettings['shift_end_time'] ?? '20:00:00',
        'grace_period_minutes' => (int)($officeSettings['grace_period_minutes'] ?? 15)
    ];
}

$isPunchedIn = ($todayPunch && !empty($todayPunch['checkin_time']));
$isCheckedOut = ($isPunchedIn && !empty($todayPunch['checkout_time']) && $todayPunch['checkout_approval_status'] !== 'not_checked_out');
$isRunningShift = ($isPunchedIn && !$isCheckedOut);
?>

<div id="attendanceToastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 99999; display: flex; flex-direction: column; gap: 8px; pointer-events: none;"></div>
<div class="attendance-workspace" style="width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0.25rem 0.5rem 2rem 0.5rem !important;">
    <!-- Top Executive Header -->
    <div class="flex justify-between align-center wrap gap-4 mb-6" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1.25rem;">
        <div>
            <div class="flex align-center gap-2 text-xs text-muted mb-1">
                <a href="index.php?page=dashboard" class="text-muted hover-primary" style="text-decoration: none;">Home</a>
                <i data-lucide="chevron-right" style="width: 12px; height: 12px;"></i>
                <span class="text-primary font-semibold">Attendance & Punch Center</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight" style="color: var(--text-main); margin: 0;">Attendance & Geo-Punch Center</h1>
            <p class="text-sm text-muted mt-1" style="margin-bottom: 0;">Live biometric & GPS-fenced time tracking, running shift duration, and executive verification queue.</p>
        </div>

        <div class="flex align-center gap-3">
            <span class="badge" style="background: rgba(37, 99, 235, 0.1); color: var(--primary); font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; font-weight: 600; border: 1px solid rgba(37, 99, 235, 0.2);">
                <i data-lucide="shield-check" style="width: 14px; height: 14px; display: inline-block; vertical-align: -2px; margin-right: 4px;"></i>
                <?php echo htmlspecialchars($user_role); ?> Console
            </span>
            <button onclick="refreshAttendanceState()" class="btn btn-outline flex align-center gap-2" style="font-size: 0.85rem; padding: 0.5rem 1rem;">
                <i data-lucide="refresh-cw" id="refresh-state-icon" style="width: 14px; height: 14px;"></i>
                <span>Sync Status</span>
            </button>
        </div>
    </div>

    <!-- Navigation Tabs (Enterprise Super Admin Layout) -->
    <div class="attendance-nav-tabs flex align-center wrap gap-2 mb-6" style="border-bottom: 2px solid var(--border-color);">
        <?php if ($is_admin): ?>
            <button type="button" class="tab-btn <?php echo ($active_tab === 'board') ? 'active' : ''; ?>" onclick="switchAttendanceTab('board')">
                <i data-lucide="users" style="width: 16px; height: 16px;"></i>
                <span>Staff Attendance Board</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'approvals') ? 'active' : ''; ?>" onclick="switchAttendanceTab('approvals')">
                <i data-lucide="user-check" style="width: 16px; height: 16px;"></i>
                <span>Pending Approvals</span>
                <span id="tab-pending-badge" class="badge text-xs" style="display: none; background: #ef4444; color: white; padding: 2px 7px; border-radius: 999px; margin-left: 6px; font-weight: 700;">0</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'punch') ? 'active' : ''; ?>" onclick="switchAttendanceTab('punch')">
                <i data-lucide="fingerprint" style="width: 16px; height: 16px;"></i>
                <span>Daily Punch Console</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'shifts') ? 'active' : ''; ?>" onclick="switchAttendanceTab('shifts')">
                <i data-lucide="clock-4" style="width: 16px; height: 16px;"></i>
                <span>Staff Shift Timings</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'settings') ? 'active' : ''; ?>" onclick="switchAttendanceTab('settings')">
                <i data-lucide="settings" style="width: 16px; height: 16px;"></i>
                <span>Office & Shift Settings</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'history') ? 'active' : ''; ?>" onclick="switchAttendanceTab('history')">
                <i data-lucide="calendar" style="width: 16px; height: 16px;"></i>
                <span>My Attendance History</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'leave') ? 'active' : ''; ?>" onclick="switchAttendanceTab('leave')">
                <i data-lucide="clock-alert" style="width: 16px; height: 16px;"></i>
                <span>Apply Leave</span>
            </button>
        <?php else: ?>
            <button type="button" class="tab-btn <?php echo ($active_tab === 'punch') ? 'active' : ''; ?>" onclick="switchAttendanceTab('punch')">
                <i data-lucide="fingerprint" style="width: 16px; height: 16px;"></i>
                <span>Daily Punch Console</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'history') ? 'active' : ''; ?>" onclick="switchAttendanceTab('history')">
                <i data-lucide="calendar" style="width: 16px; height: 16px;"></i>
                <span>My Attendance History</span>
            </button>

            <button type="button" class="tab-btn <?php echo ($active_tab === 'leave') ? 'active' : ''; ?>" onclick="switchAttendanceTab('leave')">
                <i data-lucide="clock-alert" style="width: 16px; height: 16px;"></i>
                <span>Apply Leave</span>
            </button>
        <?php endif; ?>
    </div>

    <!-- TAB 1: DAILY PUNCH CONSOLE -->
    <div id="tab-pane-punch" class="tab-pane-content" style="<?php echo ($active_tab === 'punch') ? '' : 'display: none;'; ?>">
        
        <!-- ADMIN INITIAL SETUP BANNER (Visible only to Admin if office coordinates not calibrated) -->
        <div id="adminSetupPendingBanner" class="mb-5 p-4" style="display: none; background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.3); border-radius: 10px;">
            <div class="flex justify-between align-center wrap gap-3">
                <div class="flex align-center gap-3">
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: rgba(245,158,11,0.18); display: flex; align-items: center; justify-content: center; color: #b45309;">
                        <i data-lucide="shield-alert" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold m-0" style="color: #b45309;">Initial Setup Required: Office Geofence Not Configured</h4>
                        <p class="text-xs text-muted m-0 mt-1">Please set your office location coordinates and shift timings so employees can verify their check-in perimeter.</p>
                    </div>
                </div>
                <button type="button" onclick="switchAttendanceTab('settings')" class="btn btn-primary btn-sm flex align-center gap-2" style="font-weight: 600;">
                    <i data-lucide="crosshair" style="width: 14px; height: 14px;"></i>
                    <span>Configure Office Location Now</span>
                </button>
            </div>
        </div>

        <!-- EMPLOYEE INITIAL SETUP LOCK SCREEN (Shown to regular employees if office is not configured) -->
        <div id="employeeSetupLockScreen" class="card p-8 text-center mb-6" style="display: none; background: rgba(245,158,11,0.04); border: 1px solid rgba(245,158,11,0.25); border-radius: 12px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(245,158,11,0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem auto; color: #b45309;">
                <i data-lucide="shield-alert" style="width: 32px; height: 32px;"></i>
            </div>
            <h2 class="text-lg font-bold text-main m-0">Office Geofence Setup in Progress</h2>
            <p class="text-sm text-muted mt-2 max-w-lg mx-auto" style="line-height: 1.6;">
                Your organization administrator has not yet calibrated the office GPS perimeter and work shift timings. 
                As soon as the Super Admin configures the office location, your biometric punch console will be automatically activated.
            </p>
            <div class="mt-4">
                <button type="button" onclick="refreshAttendanceState()" class="btn btn-outline btn-sm flex align-center gap-2" style="margin: 0 auto;">
                    <i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i>
                    <span>Check Setup Status</span>
                </button>
            </div>
        </div>

        <!-- Clean Responsive Punch Console Container -->
        <!-- Background camera capture: non-zero dimension so Chrome composits & renders frames, but opacity 0.001 behind page -->
        <div style="position:fixed;bottom:0;right:0;width:320px;height:240px;opacity:0.001;pointer-events:none;z-index:-99;" aria-hidden="true">
            <video id="webcamVideo" autoplay playsinline muted style="width:320px;height:240px;object-fit:cover;"></video>
            <canvas id="snapshotCanvas" width="320" height="240"></canvas>
            <img id="selfieSnapshotImg" alt="" />
            <div id="cameraPlaceholder"></div>
            <span id="cameraStatusBadge"></span>
            <input type="file" id="directCameraInput" accept="image/*" capture="user" onchange="handleSelfieFileUpload(event)">
        </div>

        <!-- Clean Responsive Centered Punch Console (Zero Clutter) -->
        <div class="punch-console-container" style="max-width: 620px; margin: 0 auto;">
            <div id="punchConsoleGrid" style="display: block;">
                
                <div class="card" style="border: 1px solid var(--border-color); border-radius: 16px; background: var(--bg-card); box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 6px -1px rgba(0,0,0,0.02); padding: 1.75rem 1.5rem;">
                    
                    <!-- Top Shift & Time Header -->
                    <div class="text-center pb-4 mb-4" style="border-bottom: 1px solid var(--border-color);">
                        <div class="flex align-center justify-center gap-2 mb-2.5">
                            <span class="badge" style="background: rgba(37,99,235,0.08); color: var(--primary); font-size: 0.78rem; font-weight: 700; padding: 5px 14px; border-radius: 999px; border: 1px solid rgba(37,99,235,0.2);">
                                <i data-lucide="building-2" style="width: 13px; height: 13px; display: inline-block; vertical-align: -2px; margin-right: 5px;"></i>
                                <span id="shiftInfoNotice">
                                    <?php
                                    $curStart = substr($userShift['shift_start_time'] ?? '10:15:00', 0, 5);
                                    $curEnd = substr($userShift['shift_end_time'] ?? '20:00:00', 0, 5);
                                    $curGrace = $userShift['grace_period_minutes'] ?? 15;
                                    $customTag = !empty($userShift['is_custom']) ? ' (Custom Shift)' : '';
                                    echo htmlspecialchars(($officeSettings['office_name'] ?? 'Marg Soft Solution') . " (Shift: {$curStart} - {$curEnd}{$customTag}, Grace: {$curGrace}m)");
                                    ?>
                                </span>
                            </span>
                        </div>
                        <div id="liveClock" style="font-family: 'Outfit', monospace; font-size: 2.5rem; font-weight: 800; color: var(--text-main); line-height: 1.15; letter-spacing: -0.5px;">--:--:-- --</div>
                        <div id="liveDate" class="text-xs text-muted font-medium mt-1.5">Fetching system date...</div>
                    </div>

                    <!-- Live Punch State Box -->
                    <div id="punchStateContainer">
                        
                        <!-- STATE A: READY TO CHECK IN -->
                        <div id="stateCheckin" style="display: <?php echo (!$isPunchedIn) ? 'block' : 'none'; ?>;">
                            <div class="mb-4" style="padding: 1.25rem 1.15rem; border-radius: 12px; background: rgba(37,99,235,0.04); border: 1px solid rgba(37,99,235,0.15);">
                                <div class="flex align-center gap-3">
                                    <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(37,99,235,0.12); display: flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0;">
                                        <i data-lucide="log-in" style="width: 24px; height: 24px;"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold m-0 text-main">Daily Shift Check-in</h3>
                                        <p class="text-xs text-muted m-0 mt-1" style="line-height: 1.45;">Live face snapshot and office location are verified instantaneously on punch.</p>
                                    </div>
                                </div>
                            </div>

                            <button type="button" id="btnSubmitCheckin" onclick="handleOneClickCheckin()" class="btn btn-primary w-full flex align-center justify-center gap-2" style="font-size: 1.05rem; font-weight: 700; border-radius: 12px; min-height: 54px; box-shadow: 0 4px 14px rgba(37,99,235,0.22);">
                                <i data-lucide="fingerprint" style="width: 22px; height: 22px;"></i>
                                <span>Punch In (Check-in Now)</span>
                            </button>
                            <p class="text-center text-xs text-muted mt-2.5 mb-0">1-Tap instant biometrics & verified office perimeter</p>
                        </div>

                        <!-- STATE B: SHIFT ACTIVE (RUNNING TIMER & CHECK-OUT READY) -->
                        <div id="stateActiveShift" style="display: <?php echo ($isRunningShift) ? 'block' : 'none'; ?>;">
                            <div class="mb-4" style="padding: 1.25rem 1.15rem; border-radius: 12px; background: rgba(16,185,129,0.05); border: 1px solid rgba(16,185,129,0.2);">
                                <div class="flex justify-between align-center mb-2.5">
                                    <span class="badge" style="background: #10b981; color: white; font-size: 0.75rem; padding: 4px 10px; border-radius: 6px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                                        <span class="shift-live-dot"></span>
                                        SHIFT IN PROGRESS
                                    </span>
                                    <span class="text-xs text-muted font-medium" id="activeCheckinTimeDisplay">In: <?php echo $isPunchedIn ? date('h:i A', strtotime($todayPunch['checkin_time'])) : '--:-- --'; ?></span>
                                </div>

                                <div class="text-center py-3">
                                    <div class="text-xs uppercase font-bold text-muted tracking-wider mb-1">Running Shift Duration</div>
                                    <div id="liveShiftCounter" style="font-family: 'Outfit', monospace; font-size: 2.6rem; font-weight: 800; color: #10b981; letter-spacing: 1px;">00h : 00m : 00s</div>
                                    <div class="text-xs text-muted mt-1.5">Timer updates live based on your IST check-in timestamp.</div>
                                </div>

                                <div id="activeCheckinApprovalBanner" class="p-2.5 text-center text-xs mt-2" style="display: <?php echo ($isPunchedIn && ($todayPunch['checkin_approval_status'] ?? '') === 'pending') ? 'block' : 'none'; ?>; border-radius: 8px; background: rgba(245,158,11,0.15); color: #b45309; font-weight: 600;">
                                    Outside Check-in: Awaiting Super Admin Verification.
                                </div>
                            </div>

                            <button type="button" id="btnSubmitCheckout" onclick="handleOneClickCheckout()" class="btn btn-danger w-full flex align-center justify-center gap-2" style="font-size: 1.05rem; font-weight: 700; border-radius: 12px; min-height: 54px; box-shadow: 0 4px 14px rgba(239,68,68,0.22);">
                                <i data-lucide="log-out" style="width: 22px; height: 22px;"></i>
                                <span>Punch Out (Check-out)</span>
                            </button>
                        </div>

                        <!-- STATE C: SHIFT COMPLETED -->
                        <div id="stateCompletedShift" style="display: <?php echo ($isCheckedOut) ? 'block' : 'none'; ?>;">
                            <div class="p-6 text-center" style="border-radius: 12px; background: rgba(16,185,129,0.06); border: 1px solid rgba(16,185,129,0.25);">
                                <div style="width: 52px; height: 52px; border-radius: 50%; background: rgba(16,185,129,0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 0.85rem auto; color: #10b981;">
                                    <i data-lucide="check-circle-2" style="width: 30px; height: 30px;"></i>
                                </div>
                                <h3 class="text-base font-bold text-main m-0">Shift Completed for Today</h3>
                                <p class="text-xs text-muted mt-1 mb-4">Your attendance and shift hours have been recorded in the central directory.</p>

                                <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem; text-align: left; background: var(--bg-card); padding: 0.9rem; border-radius: 10px; border: 1px solid var(--border-color);">
                                    <div style="padding: 0.5rem;">
                                        <span class="text-xs text-muted block mb-0.5">In Time:</span>
                                        <strong class="text-sm font-semibold text-main" id="completedInTime"><?php echo $isPunchedIn ? date('h:i A', strtotime($todayPunch['checkin_time'])) : '--:--'; ?></strong>
                                    </div>
                                    <div style="padding: 0.5rem;">
                                        <span class="text-xs text-muted block mb-0.5">Out Time:</span>
                                        <strong class="text-sm font-semibold text-main" id="completedOutTime"><?php echo $isCheckedOut ? date('h:i A', strtotime($todayPunch['checkout_time'])) : '--:--'; ?></strong>
                                    </div>
                                    <div style="padding: 0.5rem;">
                                        <span class="text-xs text-muted block mb-0.5">Total Hours:</span>
                                        <strong class="text-sm font-semibold text-primary" id="completedDuration"><?php echo ($isCheckedOut && !empty($todayPunch['total_work_minutes'])) ? sprintf("%d hrs %d mins", floor($todayPunch['total_work_minutes']/60), $todayPunch['total_work_minutes']%60) : '-- hrs -- mins'; ?></strong>
                                    </div>
                                    <div style="padding: 0.5rem;">
                                        <span class="text-xs text-muted block mb-0.5">Day Status:</span>
                                        <span id="completedStatusBadge" class="badge text-xs" style="background: #10b981; color: white;">Present</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Today's Attendance Metric Cards (2x2 Matrix) -->
                    <div class="mt-5 pt-4" style="border-top: 1px solid var(--border-color);">
                        <div class="flex justify-between align-center mb-3">
                            <span class="text-xs font-bold uppercase tracking-wider text-muted flex align-center gap-1.5">
                                <i data-lucide="activity" style="width: 14px; height: 14px; color: var(--primary);"></i>
                                Today's Attendance Record
                            </span>
                            <span class="badge text-xs" id="todayMetricStatusBadge" style="background: <?php echo $isCheckedOut ? 'rgba(16,185,129,0.15)' : ($isRunningShift ? 'rgba(6,182,212,0.15)' : 'rgba(100,116,139,0.1)'); ?>; color: <?php echo $isCheckedOut ? '#10b981' : ($isRunningShift ? '#0891b2' : 'var(--text-muted)'); ?>; font-weight: 600; padding: 4px 10px; border-radius: 6px;"><?php echo $isCheckedOut ? 'Shift Completed' : ($isRunningShift ? 'Working Now' : 'Not Marked'); ?></span>
                        </div>

                        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.85rem;">
                            <div style="padding: 0.85rem 1rem; background: var(--border-card); border-radius: 10px; border: 1px solid var(--border-color);">
                                <span class="text-xs text-muted block mb-1 font-medium">Punch In Time</span>
                                <div class="flex align-center justify-between">
                                    <strong class="text-base font-bold text-main" id="todayMetricInTime"><?php echo $isPunchedIn ? date('h:i A', strtotime($todayPunch['checkin_time'])) : '--:--'; ?></strong>
                                    <span class="badge text-xs" id="todayMetricInBadge" style="background: rgba(100,116,139,0.1); color: var(--text-muted); font-size: 0.7rem; padding: 3px 8px; border-radius: 5px;"><?php echo $isPunchedIn ? 'Recorded' : '--'; ?></span>
                                </div>
                            </div>

                            <div style="padding: 0.85rem 1rem; background: var(--border-card); border-radius: 10px; border: 1px solid var(--border-color);">
                                <span class="text-xs text-muted block mb-1 font-medium">Punch Out Time</span>
                                <div class="flex align-center justify-between">
                                    <strong class="text-base font-bold text-main" id="todayMetricOutTime"><?php echo $isCheckedOut ? date('h:i A', strtotime($todayPunch['checkout_time'])) : '--:--'; ?></strong>
                                    <span class="badge text-xs" id="todayMetricOutBadge" style="background: rgba(100,116,139,0.1); color: var(--text-muted); font-size: 0.7rem; padding: 3px 8px; border-radius: 5px;"><?php echo $isCheckedOut ? 'Completed' : '--'; ?></span>
                                </div>
                            </div>

                            <div style="padding: 0.85rem 1rem; background: var(--border-card); border-radius: 10px; border: 1px solid var(--border-color);">
                                <span class="text-xs text-muted block mb-1 font-medium">Work Duration</span>
                                <strong class="text-base font-bold text-primary" id="todayMetricDuration"><?php echo ($isCheckedOut && !empty($todayPunch['total_work_minutes'])) ? sprintf("%02dh %02dm", floor($todayPunch['total_work_minutes']/60), $todayPunch['total_work_minutes']%60) : '00h 00m'; ?></strong>
                            </div>

                            <div style="padding: 0.85rem 1rem; background: var(--border-card); border-radius: 10px; border: 1px solid var(--border-color);">
                                <span class="text-xs text-muted block mb-1 font-medium">Assigned Office</span>
                                <strong class="text-sm font-bold text-main" id="summaryOfficeName" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; line-height: 1.4;"><?php echo htmlspecialchars($officeSettings['office_name'] ?? 'Marg Soft Solution HQ'); ?></strong>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: MY ATTENDANCE HISTORY -->
    <div id="tab-pane-history" class="tab-pane-content" style="<?php echo ($active_tab === 'history') ? '' : 'display: none;'; ?>">
        <div class="card p-6" style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card);">
            <div class="flex justify-between align-center wrap gap-4 mb-4">
                <div>
                    <h3 class="text-base font-bold text-main m-0">Monthly Attendance Log</h3>
                    <p class="text-xs text-muted m-0">Chronological biometric logs, shift durations, and verification states.</p>
                </div>
                <div class="flex align-center gap-2">
                    <select id="historyMonthSelect" class="form-control text-xs" onchange="loadMonthlyHistory()" style="padding: 4px 8px;">
                        <?php 
                        for ($m = 1; $m <= 12; $m++): 
                            $mName = date('F', mktime(0, 0, 0, $m, 1));
                        ?>
                            <option value="<?php echo $m; ?>" <?php echo ($m == (int)date('m')) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endfor; ?>
                    </select>
                    <select id="historyYearSelect" class="form-control text-xs" onchange="loadMonthlyHistory()" style="padding: 4px 8px;">
                        <option value="2026" selected>2026</option>
                        <option value="2025">2025</option>
                    </select>
                    <button type="button" onclick="loadMonthlyHistory()" class="btn btn-outline btn-xs flex align-center gap-1">
                        <i data-lucide="filter" style="width: 12px; height: 12px;"></i>
                        <span>Apply</span>
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table w-full text-left" style="border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase;">
                            <th style="padding: 10px 12px;">Date</th>
                            <th style="padding: 10px 12px;">In Time</th>
                            <th style="padding: 10px 12px;">In Location</th>
                            <th style="padding: 10px 12px;">Out Time</th>
                            <th style="padding: 10px 12px;">Out Location</th>
                            <th style="padding: 10px 12px;">Duration</th>
                            <th style="padding: 10px 12px;">Day Status</th>
                            <th style="padding: 10px 12px;">Selfies</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <tr>
                            <td colspan="8" class="text-center py-6 text-muted">
                                <i data-lucide="loader-2" class="spin" style="width: 20px; height: 20px; margin: 0 auto 6px auto; display: block;"></i>
                                Loading attendance history...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 3: APPLY LEAVE -->
    <div id="tab-pane-leave" class="tab-pane-content" style="<?php echo ($active_tab === 'leave') ? '' : 'display: none;'; ?>">
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem;">
            <!-- Leave Application Form -->
            <div class="card p-6" style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card);">
                <h3 class="text-base font-bold text-main mb-1">Submit Leave Request</h3>
                <p class="text-xs text-muted mb-4">Leave requests are forwarded to Super Admin for official review and approval.</p>

                <form id="leaveApplicationForm" onsubmit="submitLeaveApplication(event)">
                    <div class="mb-3">
                        <label class="text-xs font-bold text-muted mb-1 block">Leave Type</label>
                        <select id="leaveType" class="form-control text-sm w-full" required>
                            <option value="casual">Casual Leave (CL)</option>
                            <option value="medical">Medical / Sick Leave (SL)</option>
                            <option value="paid">Earned / Paid Leave (PL)</option>
                            <option value="half_day">Half Day Leave</option>
                            <option value="emergency">Emergency Absence</option>
                        </select>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Start Date</label>
                            <input type="date" id="leaveStartDate" class="form-control text-sm w-full no-quick" data-no-quick="true" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">End Date</label>
                            <input type="date" id="leaveEndDate" class="form-control text-sm w-full no-quick" data-no-quick="true" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="text-xs font-bold text-muted mb-1 block">Reason for Leave</label>
                        <textarea id="leaveReason" rows="4" class="form-control text-sm w-full" placeholder="State reason for absence..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-full py-2 flex align-center justify-center gap-2" style="font-weight: 600;">
                        <i data-lucide="send" style="width: 16px; height: 16px;"></i>
                        <span>Submit Leave Application</span>
                    </button>
                </form>
            </div>

            <!-- Leave Policy Card -->
            <div class="card p-6" style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card);">
                <h3 class="text-base font-bold text-main mb-1">Marg ERP Leave Policy</h3>
                <p class="text-xs text-muted mb-4">Official working schedule and leave entitlement guidelines.</p>

                <div class="flex flex-col gap-3">
                    <div class="p-3" style="background: var(--border-card); border-radius: 8px; border: 1px solid var(--border-color);">
                        <div class="text-xs font-bold text-main mb-1 flex align-center gap-2">
                            <i data-lucide="calendar-check" style="width: 14px; height: 14px; color: var(--primary);"></i>
                            Official Shift Timings
                        </div>
                        <div class="text-xs text-muted">09:30 AM to 06:30 PM (Monday to Saturday). Grace period is 15 minutes.</div>
                    </div>

                    <div class="p-3" style="background: var(--border-card); border-radius: 8px; border: 1px solid var(--border-color);">
                        <div class="text-xs font-bold text-main mb-1 flex align-center gap-2">
                            <i data-lucide="clock" style="width: 14px; height: 14px; color: #10b981;"></i>
                            Half Day Criteria
                        </div>
                        <div class="text-xs text-muted">Minimum 4.5 hours of verified attendance is mandatory to qualify for half-day credit.</div>
                    </div>

                    <div class="p-3" style="background: var(--border-card); border-radius: 8px; border: 1px solid var(--border-color);">
                        <div class="text-xs font-bold text-main mb-1 flex align-center gap-2">
                            <i data-lucide="map-pin" style="width: 14px; height: 14px; color: #f59e0b;"></i>
                            Outside Duty Approval
                        </div>
                        <div class="text-xs text-muted">Punches outside the office 150m perimeter require a valid business reason and explicit Super Admin approval before being recognized.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_admin): ?>
        <!-- TAB 4: PENDING APPROVALS QUEUE (ADMIN) -->
        <div id="tab-pane-approvals" class="tab-pane-content" style="<?php echo ($active_tab === 'approvals') ? '' : 'display: none;'; ?>">
            <div class="card p-6" style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card);">
                <div class="flex justify-between align-center wrap gap-4 mb-4">
                    <div>
                        <h3 class="text-base font-bold text-main m-0">Pending Attendance Approvals Queue</h3>
                        <p class="text-xs text-muted m-0">Employees punched outside the registered office perimeter awaiting executive verification.</p>
                    </div>
                    <button type="button" onclick="loadPendingApprovalsQueue()" class="btn btn-outline btn-xs flex align-center gap-1">
                        <i data-lucide="refresh-cw" style="width: 12px; height: 12px;"></i>
                        <span>Refresh Queue</span>
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table w-full text-left" style="border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-color); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase;">
                                <th style="padding: 10px 12px;">Employee</th>
                                <th style="padding: 10px 12px;">Request Type</th>
                                <th style="padding: 10px 12px;">Date & Time</th>
                                <th style="padding: 10px 12px;">Distance from Office</th>
                                <th style="padding: 10px 12px;">Employee Reason</th>
                                <th style="padding: 10px 12px;">Selfie</th>
                                <th style="padding: 10px 12px; text-align: right;">Executive Decision</th>
                            </tr>
                        </thead>
                        <tbody id="pendingApprovalsTableBody">
                            <tr>
                                <td colspan="7" class="text-center py-6 text-muted">
                                    <i data-lucide="loader-2" class="spin" style="width: 20px; height: 20px; margin: 0 auto 6px auto; display: block;"></i>
                                    Fetching pending approval requests...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 5: STAFF ATTENDANCE BOARD (ADMIN) -->
        <div id="tab-pane-board" class="tab-pane-content" style="<?php echo ($active_tab === 'board') ? '' : 'display: none;'; ?>">
            <div class="card p-6" style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card); box-shadow: 0 4px 14px rgba(0,0,0,0.03);">
                
                <!-- Board Header & Actions Toolbar -->
                <div class="flex justify-between align-center wrap gap-4 mb-5" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1.25rem;">
                    <div>
                        <div class="flex align-center gap-2">
                            <h3 class="text-lg font-bold text-main m-0">Live Staff Attendance Board</h3>
                            <span class="badge" style="background: rgba(37,99,235,0.08); color: var(--primary); font-size: 0.75rem; font-weight: 700; border: 1px solid rgba(37,99,235,0.2);">Company Staff Only</span>
                        </div>
                        <p class="text-xs text-muted m-0 mt-1">Real-time status of all company employees today with shift tracking, geofence verification & manual punch overrides.</p>
                    </div>

                    <div class="flex align-center gap-3 wrap" style="flex-wrap: wrap;">
                        <!-- Instant Search Input -->
                        <div style="position: relative; min-width: 220px; flex-shrink: 0;">
                            <input type="text" id="boardSearchInput" placeholder="Search employee name, role..." oninput="handleBoardSearch(event)" class="form-control text-xs" style="padding-left: 32px; height: 36px; border-radius: 6px; width: 100%;">
                            <i data-lucide="search" style="position: absolute; left: 10px; top: 11px; width: 14px; height: 14px; color: var(--text-muted); pointer-events: none;"></i>
                        </div>

                        <!-- Date Quick Jump Buttons -->
                        <div class="flex align-center gap-1" style="flex-shrink: 0;">
                            <button type="button" onclick="setBoardDate('today')" class="btn btn-outline btn-xs" style="height: 36px; padding: 0 12px; font-size: 0.75rem; font-weight: 600;">Today</button>
                            <button type="button" onclick="setBoardDate('yesterday')" class="btn btn-outline btn-xs" style="height: 36px; padding: 0 12px; font-size: 0.75rem; font-weight: 600;">Yesterday</button>
                            <input type="date" id="boardTargetDate" class="form-control text-xs no-quick" data-no-quick="true" value="<?php echo date('Y-m-d'); ?>" onchange="loadDailyBoard()" style="padding: 4px 8px; height: 36px; border-radius: 6px; width: 135px;">
                        </div>

                        <!-- Manual Punch Override -->
                        <button type="button" onclick="openAdminManualPunchModal()" class="btn btn-primary btn-xs flex align-center gap-1.5" style="height: 36px; padding: 0 16px; font-weight: 700; flex-shrink: 0;">
                            <i data-lucide="plus-circle" style="width: 14px; height: 14px;"></i>
                            <span>Admin Manual Punch</span>
                        </button>

                        <button type="button" onclick="loadDailyBoard()" id="btnReloadBoard" class="btn btn-outline btn-xs flex align-center gap-1" style="height: 36px; padding: 0 10px; flex-shrink: 0;" title="Reload Table">
                            <i data-lucide="refresh-cw" id="iconReloadBoard" style="width: 13px; height: 13px;"></i>
                        </button>
                    </div>
                </div>

                <!-- Live Shift KPI Metric Cards (Stabilized Fixed Grid) -->
                <div class="grid mb-5" style="grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: 0.75rem;">
                    <div class="kpi-card p-3 cursor-pointer active" id="kpi-card-all" onclick="filterDailyBoard('all')" style="background: var(--border-card); border-radius: 8px; border: 2px solid var(--primary); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs text-muted font-semibold" style="display: block; line-height: 1.2;">Total Staff</span>
                        <strong class="text-2xl font-bold text-main" id="kpiStaffTotal" style="line-height: 1.2; display: block;">0</strong>
                    </div>
                    <div class="kpi-card p-3 cursor-pointer" id="kpi-card-present" onclick="filterDailyBoard('present')" style="background: rgba(16,185,129,0.05); border-radius: 8px; border: 1px solid rgba(16,185,129,0.25); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs font-semibold" style="color: #10b981; display: block; line-height: 1.2;">Present Today</span>
                        <strong class="text-2xl font-bold" style="color: #10b981; line-height: 1.2; display: block;" id="kpiStaffPresent">0</strong>
                    </div>
                    <div class="kpi-card p-3 cursor-pointer" id="kpi-card-working" onclick="filterDailyBoard('working')" style="background: rgba(6,182,212,0.05); border-radius: 8px; border: 1px solid rgba(6,182,212,0.25); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs font-semibold" style="color: #0891b2; display: block; line-height: 1.2;">Working Now</span>
                        <strong class="text-2xl font-bold" style="color: #0891b2; line-height: 1.2; display: block;" id="kpiStaffWorking">0</strong>
                    </div>
                    <div class="kpi-card p-3 cursor-pointer" id="kpi-card-late" onclick="filterDailyBoard('late')" style="background: rgba(245,158,11,0.05); border-radius: 8px; border: 1px solid rgba(245,158,11,0.25); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs font-semibold" style="color: #b45309; display: block; line-height: 1.2;">Late Arrivals</span>
                        <strong class="text-2xl font-bold" style="color: #b45309; line-height: 1.2; display: block;" id="kpiStaffLate">0</strong>
                    </div>
                    <div class="kpi-card p-3 cursor-pointer" id="kpi-card-pending" onclick="filterDailyBoard('pending')" style="background: rgba(239,68,68,0.05); border-radius: 8px; border: 1px solid rgba(239,68,68,0.25); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs font-semibold" style="color: #ef4444; display: block; line-height: 1.2;">Outside / Pending</span>
                        <strong class="text-2xl font-bold" style="color: #ef4444; line-height: 1.2; display: block;" id="kpiStaffPending">0</strong>
                    </div>
                    <div class="kpi-card p-3 cursor-pointer" id="kpi-card-leave" onclick="filterDailyBoard('leave')" style="background: rgba(139,92,246,0.05); border-radius: 8px; border: 1px solid rgba(139,92,246,0.25); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs font-semibold" style="color: #7c3aed; display: block; line-height: 1.2;">On Leave</span>
                        <strong class="text-2xl font-bold" style="color: #7c3aed; line-height: 1.2; display: block;" id="kpiStaffLeave">0</strong>
                    </div>
                    <div class="kpi-card p-3 cursor-pointer" id="kpi-card-absent" onclick="filterDailyBoard('absent')" style="background: rgba(100,116,139,0.05); border-radius: 8px; border: 1px solid rgba(100,116,139,0.25); text-align: center; min-height: 82px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s ease;">
                        <span class="text-xs text-muted font-semibold" style="display: block; line-height: 1.2;">Absent / Unpunched</span>
                        <strong class="text-2xl font-bold text-muted" style="line-height: 1.2; display: block;" id="kpiStaffAbsent">0</strong>
                    </div>
                </div>

                <!-- Quick Filter Chips Toolbar -->
                <div class="flex gap-1.5 wrap align-center mb-4">
                    <span class="text-xs font-semibold text-muted mr-1">Roster Filter:</span>
                    <button type="button" class="badge cursor-pointer board-filter-chip active" id="chip-filter-all" onclick="filterDailyBoard('all')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">All Staff (<span id="cntFilterAll">0</span>)</button>
                    <button type="button" class="badge cursor-pointer board-filter-chip" id="chip-filter-present" onclick="filterDailyBoard('present')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">Present (<span id="cntFilterPresent">0</span>)</button>
                    <button type="button" class="badge cursor-pointer board-filter-chip" id="chip-filter-working" onclick="filterDailyBoard('working')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">Working Now (<span id="cntFilterWorking">0</span>)</button>
                    <button type="button" class="badge cursor-pointer board-filter-chip" id="chip-filter-late" onclick="filterDailyBoard('late')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">Late Arrivals (<span id="cntFilterLate">0</span>)</button>
                    <button type="button" class="badge cursor-pointer board-filter-chip" id="chip-filter-pending" onclick="filterDailyBoard('pending')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">Outside / Pending (<span id="cntFilterPending">0</span>)</button>
                    <button type="button" class="badge cursor-pointer board-filter-chip" id="chip-filter-leave" onclick="filterDailyBoard('leave')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">On Leave (<span id="cntFilterLeave">0</span>)</button>
                    <button type="button" class="badge cursor-pointer board-filter-chip" id="chip-filter-absent" onclick="filterDailyBoard('absent')" style="padding: 5px 12px; cursor: pointer; border-radius: 6px;">Absent (<span id="cntFilterAbsent">0</span>)</button>
                </div>

                <!-- Stabilized Table Container -->
                <div class="table-responsive" style="min-height: 380px; position: relative;">
                    <table class="table w-full text-left" style="border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-color); color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                <th style="padding: 12px 14px;">Employee</th>
                                <th style="padding: 12px 14px;">Designation & Shift</th>
                                <th style="padding: 12px 14px;">Punch In</th>
                                <th style="padding: 12px 14px;">Punch Out</th>
                                <th style="padding: 12px 14px;">Work Duration</th>
                                <th style="padding: 12px 14px;">Today Status</th>
                                <th style="padding: 12px 14px;">Location & Perimeter</th>
                                <th style="padding: 12px 14px; text-align: right;">Executive Actions</th>
                            </tr>
                        </thead>
                        <tbody id="dailyBoardTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-8 text-muted">
                                    <i data-lucide="loader-2" class="spin" style="width: 22px; height: 22px; margin: 0 auto 8px auto; display: block;"></i>
                                    Compiling company staff roster...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 5B: DEDICATED STAFF SHIFT TIMINGS MANAGER (ADMIN) -->
        <div id="tab-pane-shifts" class="tab-pane-content" style="<?php echo ($active_tab === 'shifts') ? '' : 'display: none;'; ?>">
            <div class="card p-6" style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card); box-shadow: 0 4px 14px rgba(0,0,0,0.03);">
                <div class="flex justify-between align-center wrap gap-4 mb-5 pb-4" style="border-bottom: 1px solid var(--border-color);">
                    <div>
                        <div class="flex align-center gap-2">
                            <h3 class="text-lg font-bold text-main m-0">Staff Shift Timings & Working Hours</h3>
                            <span class="badge" style="background: rgba(37,99,235,0.08); color: var(--primary); font-weight: 700; font-size: 0.72rem; border: 1px solid rgba(37,99,235,0.2);">Super Admin Shift Manager</span>
                        </div>
                        <p class="text-xs text-muted mt-1 m-0">Set customized punch-in and punch-out working hours for individual employees, or assign company defaults.</p>
                    </div>
                    <div class="flex align-center gap-2 wrap">
                        <div class="flex align-center gap-2 px-3 py-1.5" style="background: var(--border-card); border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.78rem;">
                            <i data-lucide="clock" style="width: 14px; height: 14px; color: var(--primary);"></i>
                            <span>Default Shift: <strong>10:15 - 20:00</strong> (Grace: 15m)</span>
                        </div>
                        <button type="button" onclick="loadStaffShiftsTable()" id="btnReloadShifts" class="btn btn-outline btn-xs flex align-center gap-1 font-semibold" style="height: 34px; padding: 0 12px;" title="Reload Staff Shifts">
                            <i data-lucide="refresh-cw" id="iconReloadShifts" style="width: 13px; height: 13px;"></i>
                            <span>Refresh</span>
                        </button>
                    </div>
                </div>

                <!-- Shift Search & Controls -->
                <div class="flex justify-between align-center wrap gap-3 mb-4">
                    <div class="search-box flex-1" style="max-width: 400px; position: relative;">
                        <input type="text" id="staffShiftSearchInput" placeholder="Search employee name, role, email..." class="form-control text-xs" oninput="filterStaffShiftsTable()" style="height: 38px; border-radius: 6px; padding-left: 34px;">
                        <i data-lucide="search" style="position: absolute; left: 10px; top: 11px; width: 15px; height: 15px; color: var(--text-muted); pointer-events: none;"></i>
                    </div>
                    <div class="text-xs text-muted flex align-center gap-2">
                        <span>Showing <strong id="staffShiftFilteredCount" style="color: var(--primary);">0</strong> employee(s)</span>
                    </div>
                </div>

                <!-- Dedicated Staff Shifts Table -->
                <div class="table-responsive" style="min-height: 320px;">
                    <table class="table w-full text-left" style="border-collapse: collapse; font-size: 0.85rem;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-color); color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px;">
                                <th style="padding: 12px 14px;">Employee Name & Email</th>
                                <th style="padding: 12px 14px;">Designation</th>
                                <th style="padding: 12px 14px;">Shift Category</th>
                                <th style="padding: 12px 14px;">Shift Start</th>
                                <th style="padding: 12px 14px;">Shift End</th>
                                <th style="padding: 12px 14px;">Grace Period</th>
                                <th style="padding: 12px 14px; text-align: right;">Shift Actions</th>
                            </tr>
                        </thead>
                        <tbody id="staffShiftsTableBody">
                            <tr>
                                <td colspan="7" class="text-center py-8 text-muted">
                                    <i data-lucide="loader-2" class="spin" style="width: 22px; height: 22px; margin: 0 auto 8px auto; display: block;"></i>
                                    Loading company staff shifts roster...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 6: OFFICE & GEOFENCE SETTINGS (ADMIN) -->
        <div id="tab-pane-settings" class="tab-pane-content" style="<?php echo ($active_tab === 'settings') ? '' : 'display: none;'; ?>">
            <div class="card p-6" style="max-width: 800px; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card);">
                <div class="flex justify-between align-center mb-4">
                    <div>
                        <h3 class="text-base font-bold text-main m-0">Calibrate Office Geofence & Shift Timings</h3>
                        <p class="text-xs text-muted m-0">Configure headquarters coordinates, geofence radius, and official work shifts.</p>
                    </div>
                </div>

                <!-- Google Maps Copy Guide Helper -->
                <div class="p-3 mb-4 text-xs" style="background: rgba(37,99,235,0.05); border: 1px solid rgba(37,99,235,0.2); border-radius: 8px; line-height: 1.5;">
                    <div class="font-bold flex align-center gap-1.5 mb-1" style="color: var(--primary);">
                        <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                        <span>How to get exact Office Coordinates from Google Maps:</span>
                    </div>
                    <div>1. Open <strong>Google Maps</strong> on your phone or PC and locate your office building.</div>
                    <div>2. Right-click on your office (or long press on mobile).</div>
                    <div>3. Click the numbers at the top (e.g. <code>26.846712, 80.946284</code>) to copy them.</div>
                    <div>4. Paste the first number into <strong>Latitude</strong> and the second number into <strong>Longitude</strong> below!</div>
                </div>

                <form id="attendanceSettingsForm" onsubmit="saveAttendanceSettings(event)">
                    <div class="mb-4">
                        <label class="text-xs font-bold text-muted mb-1 block">Office Location Name</label>
                        <input type="text" id="settingOfficeName" class="form-control text-sm w-full" value="Marg Soft Solution HQ" required>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Office Latitude</label>
                            <input type="number" step="0.00000001" id="settingOfficeLat" class="form-control text-sm w-full" placeholder="e.g. 26.84671234" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Office Longitude</label>
                            <input type="number" step="0.00000001" id="settingOfficeLng" class="form-control text-sm w-full" placeholder="e.g. 80.94628412" required>
                        </div>
                    </div>

                    <div class="flex gap-2 wrap mb-4">
                        <button type="button" id="btnCalibrateGps" onclick="calibrateCurrentLocationAsOffice()" class="btn btn-outline btn-sm flex align-center gap-2" style="color: var(--primary); font-weight: 600;">
                            <i data-lucide="crosshair" style="width: 14px; height: 14px;"></i>
                            <span>Calibrate: Use My Current Device GPS</span>
                        </button>
                        <button type="button" onclick="calibrateFromIpNetwork()" class="btn btn-outline btn-sm flex align-center gap-2" style="font-weight: 600;">
                            <i data-lucide="globe" style="width: 14px; height: 14px;"></i>
                            <span>Auto-Detect from Office Internet / IP</span>
                        </button>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Geofence Radius (Meters)</label>
                            <input type="number" id="settingRadius" class="form-control text-sm w-full" value="250" min="20" max="20000" required>
                            <div class="flex gap-1 wrap mt-1.5">
                                <button type="button" onclick="setRadiusPreset(100)" class="badge cursor-pointer" style="background:var(--border-card); border:1px solid var(--border-color); font-size:0.7rem; cursor:pointer;">100m</button>
                                <button type="button" onclick="setRadiusPreset(250)" class="badge cursor-pointer" style="background:var(--border-card); border:1px solid var(--border-color); font-size:0.7rem; cursor:pointer;">250m</button>
                                <button type="button" onclick="setRadiusPreset(500)" class="badge cursor-pointer" style="background:var(--border-card); border:1px solid var(--border-color); font-size:0.7rem; cursor:pointer;">500m</button>
                                <button type="button" onclick="setRadiusPreset(1000)" class="badge cursor-pointer" style="background:var(--border-card); border:1px solid var(--border-color); font-size:0.7rem; cursor:pointer;">1 km</button>
                                <button type="button" onclick="setRadiusPreset(2000)" class="badge cursor-pointer" style="background:var(--border-card); border:1px solid var(--border-color); font-size:0.7rem; cursor:pointer;">2 km</button>
                                <button type="button" onclick="setRadiusPreset(5000)" class="badge cursor-pointer" style="background:var(--border-card); border:1px solid var(--border-color); font-size:0.7rem; cursor:pointer;">5 km</button>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Geofence Enforcement Mode</label>
                            <select id="settingRequireGps" class="form-control text-sm w-full">
                                <option value="1" selected>Strict Geofence (100% Radius Match - Outside Punches Require Duty Reason & Approval)</option>
                            </select>
                            <span class="text-xs text-muted">Distance is strictly validated via GPS/Device location. Outside punches require Super Admin verification.</span>
                        </div>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Biometric Face Snapshot Policy</label>
                            <select id="settingRequireSelfie" class="form-control text-sm w-full">
                                <option value="1" selected>Mandatory (Webcam Snapshot or Device Photo Required)</option>
                                <option value="0">Optional (Direct 1-Click Punch Allowed without Camera - Recommended for PC)</option>
                            </select>
                            <span class="text-xs text-muted">Mandatory hone par webcam ya device camera se face photo required hogi; Optional hone par direct punch allow hoga.</span>
                        </div>
                    </div>

                    <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Shift Start Time</label>
                            <input type="time" id="settingShiftStart" class="form-control text-sm w-full" value="09:30" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Shift End Time</label>
                            <input type="time" id="settingShiftEnd" class="form-control text-sm w-full" value="18:30" required>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-muted mb-1 block">Grace Period (Mins)</label>
                            <input type="number" id="settingGracePeriod" class="form-control text-sm w-full" value="15" min="0" max="60" required>
                        </div>
                    </div>

                    <button type="submit" id="btnSaveSettings" class="btn btn-primary px-6 py-2 flex align-center gap-2 font-semibold">
                        <i data-lucide="save" style="width: 16px; height: 16px;"></i>
                        <span>Save Calibration Settings & Activate System</span>
                    </button>

                    <div class="mt-5 p-4 flex justify-between align-center wrap gap-3" style="background: rgba(37,99,235,0.05); border: 1px dashed rgba(37,99,235,0.3); border-radius: 8px;">
                        <div>
                            <div class="font-bold text-xs" style="color: var(--primary);">Need different working hours for specific staff or teams?</div>
                            <div class="text-xs text-muted mt-0.5">Configure individual schedules for Support, Sales, Telecallers, or custom shifts.</div>
                        </div>
                        <button type="button" onclick="switchAttendanceTab('shifts')" class="btn btn-outline btn-xs flex align-center gap-1.5 font-semibold" style="height: 32px; border-color: var(--primary); color: var(--primary); padding: 0 12px;">
                            <i data-lucide="clock-4" style="width: 13px; height: 13px;"></i>
                            <span>Configure Staff Shifts</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal: Custom Employee Shift Manager -->
<div id="editEmployeeShiftModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 1055; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div class="modal-container card p-6" style="max-width: 490px; width: 92%; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div class="flex align-center gap-2.5">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(37,99,235,0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="clock" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h4 class="text-base font-bold text-main m-0">Staff Shift Schedule</h4>
                    <span class="text-xs text-muted" id="editShiftStaffSubtitle">Configure individual employee working hours</span>
                </div>
            </div>
            <button type="button" onclick="closeShiftEditModal()" class="btn btn-outline btn-xs" style="border: none;">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form id="editEmployeeShiftForm" onsubmit="submitEmployeeCustomShift(event)">
            <input type="hidden" id="editShiftUserId" value="">

            <div class="p-3 mb-3" style="background: rgba(37,99,235,0.04); border-radius: 8px; border: 1px solid rgba(37,99,235,0.15);">
                <div class="flex justify-between align-center">
                    <div>
                        <div class="text-xs text-muted">Employee:</div>
                        <strong class="text-sm font-bold text-main" id="editShiftEmployeeName">--</strong>
                    </div>
                    <span class="badge" id="editShiftTypeBadge" style="font-size: 0.72rem; padding: 4px 8px; border-radius: 6px;">Company Default</span>
                </div>
            </div>

            <!-- Quick Preset Chips -->
            <div class="mb-3">
                <label class="text-xs font-bold text-muted mb-1.5 block">Quick Shift Templates</label>
                <div class="flex wrap gap-1.5">
                    <button type="button" onclick="applyShiftPreset('10:15', '20:00', 15, 'Support Shift')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 9px; cursor: pointer; border-radius: 6px;">10:15 - 20:00 (Support)</button>
                    <button type="button" onclick="applyShiftPreset('09:30', '18:30', 15, 'General Morning')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 9px; cursor: pointer; border-radius: 6px;">09:30 - 18:30 (Morning)</button>
                    <button type="button" onclick="applyShiftPreset('11:00', '20:00', 15, 'Sales Shift')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 9px; cursor: pointer; border-radius: 6px;">11:00 - 20:00 (Sales)</button>
                    <button type="button" onclick="applyShiftPreset('10:00', '19:00', 15, 'Telecaller Shift')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 9px; cursor: pointer; border-radius: 6px;">10:00 - 19:00 (Telecaller)</button>
                </div>
            </div>

            <div class="grid mb-3" style="grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div>
                    <label class="text-xs font-bold text-muted mb-1 block">Shift Start Time <span class="text-danger">*</span></label>
                    <input type="time" id="editShiftStart" class="form-control text-sm w-full" value="10:15" required>
                </div>
                <div>
                    <label class="text-xs font-bold text-muted mb-1 block">Shift End Time <span class="text-danger">*</span></label>
                    <input type="time" id="editShiftEnd" class="form-control text-sm w-full" value="20:00" required>
                </div>
            </div>

            <div class="grid mb-4" style="grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div>
                    <label class="text-xs font-bold text-muted mb-1 block">Grace Period (Minutes)</label>
                    <input type="number" id="editShiftGrace" class="form-control text-sm w-full" min="0" max="60" value="15">
                </div>
                <div>
                    <label class="text-xs font-bold text-muted mb-1 block">Shift Label / Name</label>
                    <input type="text" id="editShiftName" class="form-control text-sm w-full" placeholder="e.g. Support Shift">
                </div>
            </div>

            <div class="flex justify-between align-center wrap gap-2 pt-3" style="border-top: 1px solid var(--border-color);">
                <button type="button" id="btnResetToDefaultShift" onclick="resetEmployeeShiftToDefault()" class="btn btn-outline btn-xs" style="color: #ef4444; border-color: rgba(239,68,68,0.3); font-weight: 600; padding: 6px 12px;">
                    Reset to Company Default
                </button>
                <div class="flex gap-2">
                    <button type="button" onclick="closeShiftEditModal()" class="btn btn-outline btn-xs" style="padding: 6px 12px;">Cancel</button>
                    <button type="submit" id="btnSaveShiftModal" class="btn btn-primary btn-xs font-semibold flex align-center gap-1" style="padding: 6px 14px;">
                        <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                        <span>Save Shift Timing</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Admin Manual Punch Override -->
<div id="adminManualPunchModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050; align-items: center; justify-content: center;">
    <div class="modal-container card p-6" style="max-width: 480px; width: 90%; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="flex justify-between align-center mb-3" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div class="flex align-center gap-2">
                <i data-lucide="shield-alert" style="width: 18px; height: 18px; color: var(--primary);"></i>
                <h4 class="text-base font-bold text-main m-0">Admin Attendance Override</h4>
            </div>
            <button type="button" onclick="closeAdminManualPunchModal()" class="btn btn-outline btn-xs" style="border: none;">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <form id="adminManualPunchForm" onsubmit="submitAdminManualPunch(event)">
            <div class="mb-3">
                <label class="text-xs font-bold text-muted mb-1 block">Select Employee <span class="text-danger">*</span></label>
                <select id="manualPunchUserId" class="form-control text-sm w-full" required>
                    <option value="">-- Choose Employee --</option>
                </select>
            </div>

            <div class="grid mb-3" style="grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div>
                    <label class="text-xs font-bold text-muted mb-1 block">Punch Type <span class="text-danger">*</span></label>
                    <select id="manualPunchType" class="form-control text-sm w-full" required>
                        <option value="checkin">Punch In (Check-in)</option>
                        <option value="checkout">Punch Out (Check-out)</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold text-muted mb-1 block">Punch Date <span class="text-danger">*</span></label>
                    <input type="date" id="manualPunchDate" class="form-control text-sm w-full no-quick" data-no-quick="true" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label class="text-xs font-bold text-muted mb-1 block">Punch Timestamp (Time) <span class="text-danger">*</span></label>
                <input type="time" id="manualPunchTime" class="form-control text-sm w-full" value="<?php echo date('H:i'); ?>" required>
            </div>

            <div class="mb-4">
                <label class="text-xs font-bold text-muted mb-1 block">Administrative Reason / Notes</label>
                <input type="text" id="manualPunchNotes" class="form-control text-sm w-full" placeholder="e.g. Technical network issue / Onsite field instruction override">
            </div>

            <div class="flex gap-2 justify-end">
                <button type="button" onclick="closeAdminManualPunchModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="submit" id="btnSubmitManualPunch" class="btn btn-primary btn-sm flex align-center gap-1.5 font-semibold">
                    <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                    <span>Confirm Manual Punch</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: View Selfie Full Resolution -->
<div id="selfieModal" class="modal-overlay" style="display: none;">
    <div class="modal-container" style="max-width: 500px; padding: 1.5rem;">
        <div class="flex justify-between align-center mb-3">
            <h4 class="text-base font-bold text-main m-0" id="selfieModalTitle">Biometric Face Snapshot</h4>
            <button type="button" onclick="closeSelfieModal()" class="btn btn-outline btn-xs" style="border: none;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <div style="border-radius: 8px; overflow: hidden; background: #000; text-align: center;">
            <img id="selfieModalImg" src="" alt="Captured Selfie" style="width: 100%; max-height: 480px; object-fit: contain;">
        </div>
        <div class="text-xs text-muted mt-3" id="selfieModalDetails">--</div>
    </div>
</div>

<!-- Modal: Outside Office Geofence Reason Popup (Responsive for Mobile & Laptop) -->
<div id="outsidePunchModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999999; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px);">
    <div class="modal-container card p-6" style="max-width: 480px; width: 100%; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.25); max-height: 90vh; overflow-y: auto;">
        <div class="flex justify-between align-center mb-3" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div class="flex align-center gap-2">
                <div style="width: 34px; height: 34px; border-radius: 8px; background: rgba(245,158,11,0.15); display: flex; align-items: center; justify-content: center; color: #b45309; flex-shrink: 0;">
                    <i data-lucide="map-pin-off" style="width: 18px; height: 18px;"></i>
                </div>
                <div>
                    <h4 class="text-base font-bold text-main m-0" id="outsideModalTitle">Outside Office Perimeter</h4>
                    <span class="text-xs text-muted" id="outsideModalSubtitle">Super Admin verification required</span>
                </div>
            </div>
            <button type="button" onclick="closeOutsideReasonModal()" class="btn btn-outline btn-xs" style="border: none; border-radius: 50%; width: 28px; height: 28px; padding: 0; display: flex; align-items: center; justify-content: center;">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <!-- Live Snapshot Thumbnail Strip -->
        <div class="flex align-center gap-3 p-3 mb-3" style="background: var(--border-card); border-radius: 10px; border: 1px solid var(--border-color);">
            <div style="width: 52px; height: 52px; border-radius: 8px; overflow: hidden; background: #000; flex-shrink: 0; border: 1px solid var(--border-color);">
                <img id="outsideModalSelfiePreview" src="" alt="Live Face" style="width: 100%; height: 100%; object-fit: cover;">
            </div>
            <div style="flex: 1;">
                <div class="text-xs font-semibold flex align-center gap-1" style="color: #10b981;">
                    <i data-lucide="check-circle" style="width: 13px; height: 13px;"></i>
                    <span>Live Biometric Captured</span>
                </div>
                <p class="text-xs text-muted m-0 mt-0.5">Your live face photo is verified. Please provide your outside duty reason below.</p>
            </div>
        </div>

        <div class="mb-3">
            <label class="text-xs font-bold text-muted mb-1 block">Quick Reason Presets</label>
            <div id="outsideModalPresetChips" class="flex gap-1.5 wrap mb-2">
                <!-- Dynamically populated presets -->
            </div>
            <label class="text-xs font-bold text-muted mb-1 block">Official Duty Reason <span class="text-danger">*</span></label>
            <textarea id="outsideDutyReasonText" rows="3" class="form-control text-sm w-full" placeholder="Enter site visit details, client name, or official duty reason..."></textarea>
        </div>

        <div class="flex gap-2 justify-end pt-2" style="border-top: 1px solid var(--border-color);">
            <button type="button" onclick="closeOutsideReasonModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button type="button" id="btnSubmitOutsideModal" onclick="submitOutsideReasonFromModal()" class="btn btn-primary btn-sm flex align-center gap-1.5 font-semibold">
                <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                <span>Submit for Approval</span>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Phone GPS & Chrome Location Permission Guide (1-Click Redirects) -->
<div id="locationPermissionModal" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 999999; align-items: center; justify-content: center; padding: 1rem; backdrop-filter: blur(4px);">
    <div class="modal-container card p-6" style="max-width: 480px; width: 100%; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.25);">
        <div class="flex justify-between align-center mb-4" style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div class="flex align-center gap-2.5">
                <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(239,68,68,0.1); color: #ef4444; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-lucide="map-pin-off" style="width: 20px; height: 20px;"></i>
                </div>
                <div>
                    <h4 class="text-base font-bold text-main m-0" id="locModalTitle">Live GPS Location Required</h4>
                    <span class="text-xs text-muted" id="locModalSubtitle">Device high-accuracy GPS needed for punch</span>
                </div>
            </div>
            <button type="button" onclick="closeLocationPermissionModal()" class="btn btn-outline btn-xs" style="border: none;">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </button>
        </div>

        <div id="locModalBodyContent" class="mb-4">
            <div class="p-3 mb-3 text-xs" style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.25); border-radius: 8px; line-height: 1.5; color: var(--text-main);">
                <div class="font-bold flex align-center gap-1.5 mb-1" style="color: #b45309;">
                    <i data-lucide="alert-triangle" style="width: 14px; height: 14px;"></i>
                    <span id="locModalNoticeHeading">Cellular Tower Location Detected</span>
                </div>
                <div id="locModalNoticeText">Phone satellite GPS signal capture nahi kar pa raha hai, isiliye mobile company ke tower ki approximate location uth rahi hai. Live meter-level satellite GPS ke liye neeche diye gaye steps follow karein:</div>
            </div>

            <div class="p-3 mb-3" style="background: var(--border-card); border-radius: 8px; border: 1px solid var(--border-color); font-size: 0.8rem; line-height: 1.5;">
                <div class="font-bold text-main mb-1.5 flex align-center gap-1.5">
                    <i data-lucide="check-circle-2" style="width: 14px; height: 14px; color: var(--primary);"></i>
                    <span>Fast Resolution Steps:</span>
                </div>
                <div class="mb-1"><strong>Step 1:</strong> Phone ke notification bar se <strong>Location / GPS toggle ko ON</strong> karein.</div>
                <div class="mb-1"><strong>Step 2:</strong> Chrome me upar URL bar ke paas <strong>Lock / Tune icon</strong> par tap karein -> <strong>Permissions</strong> -> <strong>Location</strong> ko <strong>"Allow"</strong> aur <strong>"Precise"</strong> set karein.</div>
                <div><strong>Step 3:</strong> Phone me 1 baar <strong>Google Maps</strong> open karke current blue dot verify karein, fir wapas aakar <strong>"Re-Scan GPS Satellites"</strong> dabayein.</div>
            </div>
        </div>

        <div class="flex flex-col gap-2 pt-2" style="border-top: 1px solid var(--border-color);">
            <div class="flex gap-2 wrap">
                <a href="intent:#Intent;action=android.settings.LOCATION_SOURCE_SETTINGS;end" class="btn btn-primary btn-sm flex-1 flex align-center justify-center gap-2 font-semibold" style="height: 40px; text-decoration: none;">
                    <i data-lucide="settings" style="width: 15px; height: 15px;"></i>
                    <span>Open Phone GPS Settings</span>
                </a>
                <a href="intent:#Intent;action=android.settings.APPLICATION_DETAILS_SETTINGS;package=com.android.chrome;end" class="btn btn-outline btn-sm flex-1 flex align-center justify-center gap-2 font-semibold" style="height: 40px; text-decoration: none;">
                    <i data-lucide="shield-check" style="width: 15px; height: 15px;"></i>
                    <span>Chrome Permissions</span>
                </a>
            </div>
            <button type="button" onclick="retryHighAccuracyGPS()" id="btnRetryLocModal" class="btn btn-outline btn-sm w-full flex align-center justify-center gap-2 font-semibold" style="height: 40px; border-color: var(--primary); color: var(--primary);">
                <i data-lucide="refresh-cw" id="iconRetryLocModal" style="width: 15px; height: 15px;"></i>
                <span>Re-Scan Live GPS Satellites</span>
            </button>
            <button type="button" onclick="proceedOutsideAnyway()" id="btnProceedOutsideAnyway" class="btn btn-ghost btn-xs text-muted w-full mt-1" style="display: none;">
                Working Remotely? Continue with Outside Duty Reason &rarr;
            </button>
        </div>
    </div>
</div>

<style>
/* Attendance Console Full-Width & Responsive Enterprise Styling */
.main-content:has(.attendance-workspace) .content-body,
.content-body:has(.attendance-workspace) {
    padding: 0.75rem 1.25rem !important;
    max-width: 100% !important;
    width: 100% !important;
}
.attendance-workspace {
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0.25rem 0.25rem 2rem 0.25rem !important;
}

#locationPermissionModal.open,
#locationPermissionModal.active {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    visibility: visible !important;
}
#locationPermissionModal:not(.open):not(.active) {
    display: none !important;
    opacity: 0 !important;
    pointer-events: none !important;
    visibility: hidden !important;
}
#locationPermissionModal .modal-container {
    opacity: 1 !important;
    transform: scale(1) !important;
    visibility: visible !important;
}

/* Hard suppression of injected quick-dt chips inside attendance center */
#outsidePunchModal.open,
#outsidePunchModal.active {
    display: flex !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    visibility: visible !important;
}
#outsidePunchModal:not(.open):not(.active) {
    display: none !important;
    opacity: 0 !important;
    pointer-events: none !important;
    visibility: hidden !important;
}
#outsidePunchModal .modal-container {
    opacity: 1 !important;
    transform: scale(1) !important;
    visibility: visible !important;
}
.attendance-workspace .quick-dt-presets-container,
.no-quick ~ .quick-dt-presets-container,
.no-quick + .quick-dt-presets-container {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    height: 0 !important;
    width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}

.attendance-nav-tabs .tab-btn {
    background: transparent;
    border: none;
    padding: 0.75rem 1.25rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.15s ease;
}
.attendance-nav-tabs .tab-btn:hover {
    color: var(--text-main);
}
.attendance-nav-tabs .tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 700;
}

/* Punch Console Balanced Grid */
.punch-console-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: stretch;
}
@media (max-width: 1080px) {
    .punch-console-layout {
        grid-template-columns: 1fr;
    }
}
.punch-console-container {
    width: 100%;
    max-width: 980px;
    margin: 0 auto;
}
.punch-console-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: stretch;
}
.cam-viewport-box {
    position: relative;
    width: 100%;
    height: 260px;
    border-radius: 12px;
    overflow: hidden;
    background: #0b0f19;
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
}
.camera-live-pulse {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    display: inline-block;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: camPulse 1.8s infinite;
}
@keyframes camPulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.shift-live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: white;
    display: inline-block;
}

@media (max-width: 768px) {
    .attendance-workspace {
        padding: 0.75rem 0.50rem 3rem 0.50rem !important;
    }
    .punch-console-container {
        max-width: 100% !important;
    }
    .punch-console-layout {
        grid-template-columns: 1fr !important;
        gap: 1.25rem !important;
    }
    .cam-viewport-box {
        height: 240px !important;
    }
    #liveClock {
        font-size: 2.1rem !important;
    }
    .modal-container {
        width: 95% !important;
        padding: 1.25rem !important;
        margin: auto !important;
    }
    .kpi-card {
        min-width: 130px !important;
        padding: 0.75rem 0.65rem !important;
    }
}

/* Staff Board KPI & Filter styling */
.kpi-card {
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    user-select: none;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.kpi-card.active {
    box-shadow: 0 0 0 2px var(--primary);
}
.board-filter-chip {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    transition: all 0.15s ease;
}
.board-filter-chip:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.board-filter-chip.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Responsive Table Horizontal Scrolling */
.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.spin {
    animation: rotateSpin 1.2s linear infinite;
}
@keyframes rotateSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
/**
 * Marg ERP CRM - Attendance & Geo-Punch Front-End Logic
 */
let appAttendanceState = {
    settings: <?php echo json_encode($officeSettings ?: null); ?>,
    userShift: <?php echo json_encode($userShift ?: null); ?>,
    punch: <?php echo json_encode($todayPunch ?: null); ?>,
    userCoords: null,
    distanceMeters: null,
    isInside: false,
    cameraStream: null,
    selfieCapturedBase64: null,
    shiftTimerInterval: null,
    runningSeconds: <?php echo ($isRunningShift && !empty($todayPunch['checkin_time'])) ? max(0, time() - strtotime($todayPunch['checkin_time'])) : 0; ?>,
    isAdminUser: <?php echo $is_admin ? 'true' : 'false'; ?>,
    boardRows: [],
    boardFilter: 'all'
};

// Initialize on DOM Load
document.addEventListener('DOMContentLoaded', () => {
    initISTClock();
    try {
        if (appAttendanceState.punch) {
            renderPunchState(appAttendanceState.punch, appAttendanceState.runningSeconds);
        }
        if (appAttendanceState.settings) {
            renderSettingsDetails(appAttendanceState.settings);
        }
    } catch(e) {
        console.warn('Initial state render notice:', e);
    }
    warmupGPSOnly();   // Only GPS pre-warm on load; camera permission triggered on Punch click
    refreshAttendanceState();

    // Auto-load Staff Board or Shifts if active tab matches
    if (appAttendanceState.isAdminUser && '<?php echo $active_tab; ?>' === 'board') {
        loadDailyBoard();
    } else if (appAttendanceState.isAdminUser && '<?php echo $active_tab; ?>' === 'shifts') {
        loadStaffShiftsTable();
    }

    // Close modals on backdrop click
    const outsideModal = document.getElementById('outsidePunchModal');
    if (outsideModal) {
        outsideModal.addEventListener('click', (e) => {
            if (e.target === outsideModal) closeOutsideReasonModal();
        });
    }
    const shiftModal = document.getElementById('editEmployeeShiftModal');
    if (shiftModal) {
        shiftModal.addEventListener('click', (e) => {
            if (e.target === shiftModal) closeShiftEditModal();
        });
    }
    const adminPunchModal = document.getElementById('adminManualPunchModal');
    if (adminPunchModal) {
        adminPunchModal.addEventListener('click', (e) => {
            if (e.target === adminPunchModal) closeAdminManualPunchModal();
        });
    }
    const locModal = document.getElementById('locationPermissionModal');
    if (locModal) {
        locModal.addEventListener('click', (e) => {
            if (e.target === locModal) closeLocationPermissionModal();
        });
    }

    // Clean up any extraneous quick-dt presets that main.js may inject
    document.querySelectorAll('.attendance-workspace .quick-dt-presets-container').forEach(el => el.remove());

    if (window.lucide) {
        lucide.createIcons();
    }
});

// -----------------------------------------------------------------------------
// Live IST Clock
// -----------------------------------------------------------------------------
function initISTClock() {
    function updateClock() {
        const now = new Date();
        const optionsTime = { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        const optionsDate = { timeZone: 'Asia/Kolkata', weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };

        const timeStr = now.toLocaleTimeString('en-US', optionsTime);
        const dateStr = now.toLocaleDateString('en-US', optionsDate);

        const clockEl = document.getElementById('liveClock');
        const dateEl = document.getElementById('liveDate');
        if (clockEl) clockEl.innerText = timeStr;
        if (dateEl) dateEl.innerText = dateStr;
    }
    updateClock();
    setInterval(updateClock, 1000);
}

// Universal Safe Date/Time Formatter (Zero-crash across all mobile/desktop browsers)
function safeFormatTime(dateVal) {
    if (!dateVal) return '--:--';
    if (/^\d{1,2}:\d{2}\s*(AM|PM)$/i.test(String(dateVal).trim())) return String(dateVal).trim();
    try {
        const clean = String(dateVal).trim().replace(' ', 'T');
        const d = new Date(clean);
        if (!isNaN(d.getTime())) {
            return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
        }
    } catch(e) {}
    const match = String(dateVal).match(/(\d{2}):(\d{2})/);
    if (match) {
        let h = parseInt(match[1]);
        const m = match[2];
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return `${String(h).padStart(2, '0')}:${m} ${ampm}`;
    }
    return String(dateVal);
}

// -----------------------------------------------------------------------------
// -----------------------------------------------------------------------------
// Toast Notification Engine (Zero Native Alerts)
// -----------------------------------------------------------------------------
function showAttendanceToast(msg, type = 'success') {
    const container = document.getElementById('attendanceToastContainer');
    if (!container) {
        console.log('[' + type + '] ' + msg);
        return;
    }
    const toast = document.createElement('div');
    toast.style.pointerEvents = 'auto';
    toast.style.minWidth = '280px';
    toast.style.maxWidth = '380px';
    toast.style.padding = '12px 16px';
    toast.style.borderRadius = '10px';
    toast.style.fontSize = '0.85rem';
    toast.style.fontWeight = '600';
    toast.style.display = 'flex';
    toast.style.alignItems = 'center';
    toast.style.gap = '10px';
    toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.2)';
    toast.style.animation = 'fadeIn 0.2s ease';
    toast.style.zIndex = '99999';

    let icon = 'check-circle';
    let bg = '#10b981';
    let textCol = '#ffffff';

    if (type === 'error') {
        icon = 'alert-circle';
        bg = '#ef4444';
    } else if (type === 'warning') {
        icon = 'alert-triangle';
        bg = '#f59e0b';
    } else if (type === 'info') {
        icon = 'info';
        bg = '#2563eb';
    }

    toast.style.background = bg;
    toast.style.color = textCol;
    toast.innerHTML = `<i data-lucide="${icon}" style="width: 18px; height: 18px; flex-shrink: 0;"></i> <span>${msg}</span>`;

    container.appendChild(toast);
    if (window.lucide) lucide.createIcons();

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-8px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 320);
    }, 4000);
}

// -----------------------------------------------------------------------------
// Camera / Webcam Stream & Direct Snap Fallback
// -----------------------------------------------------------------------------
function triggerDirectCamera() {
    const input = document.getElementById('directCameraInput');
    if (input) {
        input.value = '';
        input.click();
    }
}

function handleSelfieFileUpload(event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            const canvas = document.getElementById('snapshotCanvas') || document.createElement('canvas');
            const targetW = 320;
            const targetH = Math.round(img.height * (targetW / img.width)) || 240;
            canvas.width = targetW;
            canvas.height = targetH;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, targetW, targetH);
            const base64 = canvas.toDataURL('image/jpeg', 0.6);
            appAttendanceState.selfieCapturedBase64 = base64;

            showAttendanceToast('Photo verified. Recording attendance now...', 'success');
            if (appAttendanceState.pendingPunchType === 'checkout') {
                handleOneClickCheckout();
            } else {
                handleOneClickCheckin();
            }
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

async function startWebcamStream(interactive = false) {
    const video = document.getElementById('webcamVideo');
    const placeholder = document.getElementById('cameraPlaceholder');
    const badge = document.getElementById('cameraStatusBadge');

    if (!video) return null;

    // Check if an existing live stream is active; reuse it without terminating tracks
    if (appAttendanceState.cameraStream) {
        const liveTracks = appAttendanceState.cameraStream.getVideoTracks().filter(t => t.readyState === 'live');
        if (liveTracks.length > 0) {
            if (video.srcObject !== appAttendanceState.cameraStream) {
                video.srcObject = appAttendanceState.cameraStream;
            }
            video.muted = true;
            video.playsInline = true;
            video.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
            try {
                await video.play();
            } catch (e) {}

            if (badge) {
                badge.innerHTML = '<span class="camera-live-pulse"></span> Camera Active';
                badge.style.background = 'rgba(16,185,129,0.1)';
                badge.style.color = '#10b981';
            }
            return appAttendanceState.cameraStream;
        }
    }

    // Clean up any stale/dead stream
    if (appAttendanceState.cameraStream) {
        try {
            appAttendanceState.cameraStream.getTracks().forEach(t => t.stop());
        } catch (e) {}
        appAttendanceState.cameraStream = null;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return null;
    }

    let stream = null;

    // Front camera with hard 3s timeout so Windows OS-blocked camera never hangs
    try {
        const camPromise = navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'user' }, width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        stream = await Promise.race([
            camPromise,
            new Promise((_, rej) => setTimeout(() => rej(new Error('cam_timeout')), 3000))
        ]);
    } catch (e1) {
        // Fallback to any camera with 2s timeout
        try {
            const camPromise2 = navigator.mediaDevices.getUserMedia({ video: true });
            stream = await Promise.race([
                camPromise2,
                new Promise((_, rej) => setTimeout(() => rej(new Error('cam_timeout')), 2000))
            ]);
        } catch (e2) {
            console.warn('[Camera] Not available:', e2.message);
        }
    }

    if (stream) {
        appAttendanceState.cameraStream = stream;
        video.srcObject = stream;
        video.muted = true;
        video.defaultMuted = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('muted', '');
        video.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';

        stream.getVideoTracks().forEach(t => { t.enabled = true; });

        try {
            await video.play();
        } catch (pe) {
            console.warn('[Camera] video.play error:', pe);
        }

        video.onloadedmetadata = () => {
            video.play().catch(e => {});
            if (badge) {
                badge.innerHTML = '<span class="camera-live-pulse"></span> Camera Active';
                badge.style.background = 'rgba(16,185,129,0.1)';
                badge.style.color = '#10b981';
            }
        };

        if (badge) {
            badge.innerHTML = '<span class="camera-live-pulse"></span> Camera Active';
            badge.style.background = 'rgba(16,185,129,0.1)';
            badge.style.color = '#10b981';
        }
    }

    if (window.lucide) lucide.createIcons();
    return stream;
}

// On-Demand Camera Selfie Capture
// Flow: getUserMedia() → Chrome shows "Allow Camera?" popup → Allow → auto-capture frame → stop stream
async function ensureLiveCameraSelfie(maxWaitMs = 3500) {
    // If we already have a selfie captured this session, reuse it
    if (appAttendanceState.selfieCapturedBase64) {
        return appAttendanceState.selfieCapturedBase64;
    }

    // Check policy first: 0 = Optional (skips camera entirely)
    const requireSelfie = (appAttendanceState.settings && appAttendanceState.settings.require_selfie !== undefined)
        ? parseInt(appAttendanceState.settings.require_selfie)
        : 1;

    if (requireSelfie === 0) {
        return '';
    }

    const video = document.getElementById('webcamVideo');
    const canvas = document.getElementById('snapshotCanvas');
    if (!video || !canvas) return null;

    // Stop any stale streams
    if (appAttendanceState.cameraStream) {
        try { appAttendanceState.cameraStream.getTracks().forEach(t => t.stop()); } catch(e) {}
        appAttendanceState.cameraStream = null;
    }
    video.srcObject = null;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        console.warn('[Camera] getUserMedia not supported.');
        return null;
    }

    let stream = null;

    // This call triggers Chrome's native "Allow Camera?" permission popup on first click
    // Give 20s so employee has ample time to click "Allow" on the browser prompt
    try {
        const camPromise = navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'user' }, width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        stream = await Promise.race([
            camPromise,
            new Promise((_, rej) => setTimeout(() => rej(new Error('cam_timeout')), 20000))
        ]);
    } catch (e1) {
        try {
            const camPromise2 = navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            stream = await Promise.race([
                camPromise2,
                new Promise((_, rej) => setTimeout(() => rej(new Error('cam_timeout')), 8000))
            ]);
        } catch (e2) {
            console.warn('[Camera] Permission denied or unavailable:', e2.message);
            return null;
        }
    }

    if (!stream) return null;

    let dataUrl = null;

    // METHOD 1: Chrome Native ImageCapture API (instant direct sensor grab, no layout required)
    if (window.ImageCapture && stream.getVideoTracks().length > 0) {
        try {
            const track = stream.getVideoTracks()[0];
            const ic = new ImageCapture(track);
            const bitmap = await ic.grabFrame();
            if (bitmap && bitmap.width > 0) {
                const tW = 320;
                const tH = Math.round(bitmap.height * (tW / bitmap.width)) || 240;
                canvas.width = tW;
                canvas.height = tH;
                const ctx = canvas.getContext('2d');
                ctx.translate(tW, 0);
                ctx.scale(-1, 1);
                ctx.drawImage(bitmap, 0, 0, tW, tH);
                dataUrl = canvas.toDataURL('image/jpeg', 0.6);
                appAttendanceState.selfieCapturedBase64 = dataUrl;
            }
        } catch (icErr) {
            console.warn('[Camera] ImageCapture grabFrame error, falling back to video:', icErr);
        }
    }

    // METHOD 2: Video Element Capture Fallback
    if (!dataUrl && video) {
        appAttendanceState.cameraStream = stream;
        video.srcObject = stream;
        video.muted = true;
        video.playsInline = true;

        try { await video.play(); } catch(e) {}

        const start = Date.now();
        while (Date.now() - start < maxWaitMs) {
            if (video.videoWidth > 0 && video.readyState >= 2) break;
            await new Promise(r => setTimeout(r, 50));
        }

        if (video.videoWidth > 0) {
            const tW = 320;
            const tH = Math.round(video.videoHeight * (tW / video.videoWidth)) || 240;
            canvas.width = tW;
            canvas.height = tH;
            const ctx = canvas.getContext('2d');
            ctx.translate(tW, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, tW, tH);
            dataUrl = canvas.toDataURL('image/jpeg', 0.6);
            appAttendanceState.selfieCapturedBase64 = dataUrl;
        }
    }

    // Always stop camera hardware tracks immediately after capturing frame
    try { stream.getTracks().forEach(t => t.stop()); } catch(e) {}
    appAttendanceState.cameraStream = null;
    video.srcObject = null;

    return dataUrl;
}

// GPS-only pre-warm on page load (no camera — camera is triggered on Punch click)
function warmupGPSOnly() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const acc = Math.round(pos.coords.accuracy);
                const isAccurate = (acc <= 350);
                appAttendanceState.userCoords = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    acc: acc,
                    is_gps: isAccurate,
                    is_coarse: !isAccurate
                };
                evaluateGeofence();
            },
            () => {},
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 }
        );
    }
}

async function requestPermissionsAndWarmup(interactive = false) {
    warmupGPSOnly();
}

// -----------------------------------------------------------------------------
// High-Accuracy Geolocation Engine with Satellite Watcher & Intent Redirects
// -----------------------------------------------------------------------------
let gpsWatchId = null;

function getDeviceLocationPromise(forceFresh = false) {
    return new Promise((resolve) => {
        // If we already have verified satellite GPS (accuracy <= 350m) and not forcing fresh, reuse it
        if (!forceFresh && appAttendanceState.userCoords && appAttendanceState.userCoords.is_gps && appAttendanceState.userCoords.acc <= 350) {
            return resolve(appAttendanceState.userCoords);
        }

        const pillIcon = document.getElementById('geofencePillIcon');
        const statusText = document.getElementById('geofenceStatusText');

        if (pillIcon) pillIcon.classList.add('spin');
        if (statusText) statusText.innerText = 'Locking Satellite GPS...';

        let resolved = false;
        let bestCoords = null;

        const finalizeCoords = (coords) => {
            if (resolved) return;
            resolved = true;
            if (pillIcon) pillIcon.classList.remove('spin');
            if (gpsWatchId !== null) {
                try { navigator.geolocation.clearWatch(gpsWatchId); } catch(e){}
                gpsWatchId = null;
            }
            clearTimeout(timeoutId);

            if (coords) {
                const lat = coords.latitude;
                const lng = coords.longitude;
                const acc = Math.round(coords.accuracy);
                const isAccurate = (acc <= 350);

                appAttendanceState.userCoords = {
                    lat: lat,
                    lng: lng,
                    acc: acc,
                    is_gps: isAccurate,
                    is_coarse: !isAccurate
                };

                const coordsDisplay = document.getElementById('coordsDisplay');
                const accuracyDisplay = document.getElementById('accuracyDisplay');
                if (coordsDisplay) coordsDisplay.innerText = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                if (accuracyDisplay) accuracyDisplay.innerText = isAccurate ? `GPS (±${acc}m)` : `Tower (±${Math.round(acc/1000)}km)`;

                evaluateGeofence();

                const permBanner = document.getElementById('permissionRequestBanner');
                if (permBanner) permBanner.style.display = 'none';

                resolve(appAttendanceState.userCoords);
            } else {
                useNetworkIpLocationFallback().then(resolve).catch(() => resolve(null));
            }
        };

        // 12-second overall timeout: sufficient for indoor satellite acquisition
        const timeoutId = setTimeout(() => {
            if (!resolved) {
                if (bestCoords) {
                    finalizeCoords(bestCoords);
                } else {
                    finalizeCoords(null);
                }
            }
        }, 12000);

        if (!navigator.geolocation) {
            clearTimeout(timeoutId);
            useNetworkIpLocationFallback().then(resolve).catch(() => resolve(null));
            return;
        }

        try {
            gpsWatchId = navigator.geolocation.watchPosition(
                (pos) => {
                    if (resolved) return;
                    const acc = Math.round(pos.coords.accuracy);

                    if (!bestCoords || acc < bestCoords.accuracy) {
                        bestCoords = pos.coords;
                    }

                    // If satellite precision achieved (<= 150m), finalize immediately
                    if (acc <= 150) {
                        finalizeCoords(pos.coords);
                    }
                },
                (err) => {
                    console.warn('[Location] GPS watch error:', err);
                    if (err.code === 1) { // PERMISSION_DENIED
                        clearTimeout(timeoutId);
                        if (gpsWatchId !== null) {
                            try { navigator.geolocation.clearWatch(gpsWatchId); } catch(e){}
                            gpsWatchId = null;
                        }
                        openLocationPermissionModal('denied');
                        finalizeCoords(null);
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 30000
                }
            );
        } catch(e) {
            navigator.geolocation.getCurrentPosition(
                (pos) => finalizeCoords(pos.coords),
                () => finalizeCoords(null),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
            );
        }
    });
}

function openLocationPermissionModal(type = 'coarse', accMeters = 0) {
    const modal = document.getElementById('locationPermissionModal');
    if (!modal) return;

    const titleEl = document.getElementById('locModalTitle');
    const subtitleEl = document.getElementById('locModalSubtitle');
    const headingEl = document.getElementById('locModalNoticeHeading');
    const textEl = document.getElementById('locModalNoticeText');
    const proceedBtn = document.getElementById('btnProceedOutsideAnyway');

    if (type === 'denied') {
        if (titleEl) titleEl.innerText = 'Location Permission Required';
        if (subtitleEl) subtitleEl.innerText = 'Allow location in Chrome to punch attendance';
        if (headingEl) headingEl.innerText = 'Chrome Location Access Blocked';
        if (textEl) textEl.innerText = 'Chrome me is website ke liye location permission blocked hai. Neeche diye gaye buttons se phone GPS aur Chrome settings open karein, ya Chrome ke URL bar ke paas lock icon se Location ko "Allow" karein.';
        if (proceedBtn) proceedBtn.style.display = 'none';
    } else {
        const kmStr = accMeters > 0 ? `(Approx. ±${Math.round(accMeters / 1000)} km)` : '';
        if (titleEl) titleEl.innerText = 'Cellular Tower Location Detected';
        if (subtitleEl) subtitleEl.innerText = `Telecom tower detected ${kmStr} instead of satellite GPS`;
        if (headingEl) headingEl.innerText = `Satellite GPS Signal Not Locked ${kmStr}`;
        if (textEl) textEl.innerText = `Aapka phone Jio/Airtel telecom tower ki location utha raha hai (jo office se door hoti hai). Live physical satellite GPS ke liye phone ka Location (GPS) toggle ON karein aur Chrome me "Precise Location" allow karein.`;
        if (proceedBtn) proceedBtn.style.display = 'block';
    }

    modal.style.display = 'flex';
    modal.classList.add('open');
    modal.classList.add('active');
    if (window.lucide) lucide.createIcons();
}

function closeLocationPermissionModal() {
    const modal = document.getElementById('locationPermissionModal');
    if (modal) {
        modal.classList.remove('open');
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function openAndroidLocationSettings() {
    try {
        window.location.href = "intent:#Intent;action=android.settings.LOCATION_SOURCE_SETTINGS;end";
    } catch (e) {
        showAttendanceToast('Please open phone Settings -> Location -> Turn ON Location & Google Location Accuracy.', 'info');
    }
}

function openChromeAppSettings() {
    try {
        window.location.href = "intent:#Intent;action=android.settings.APPLICATION_DETAILS_SETTINGS;package=com.android.chrome;end";
    } catch (e) {
        showAttendanceToast('Tap the Lock icon in Chrome address bar to manage permissions.', 'info');
    }
}

async function retryHighAccuracyGPS() {
    const icon = document.getElementById('iconRetryLocModal');
    const btn = document.getElementById('btnRetryLocModal');
    if (icon) icon.classList.add('spin');
    if (btn) btn.disabled = true;

    try {
        const coords = await getDeviceLocationPromise(true);
        if (coords && coords.is_gps && coords.acc <= 500) {
            showAttendanceToast(`Satellite GPS locked! Accuracy: ±${coords.acc}m`, 'success');
            closeLocationPermissionModal();
            // Automatically resume punch
            if (appAttendanceState.pendingPunchType === 'checkout') {
                handleOneClickCheckout();
            } else {
                handleOneClickCheckin();
            }
        } else {
            const accFmt = coords ? (coords.acc > 1000 ? Math.round(coords.acc / 1000) + ' km' : coords.acc + 'm') : 'Unknown';
            showAttendanceToast(`Current accuracy is ±${accFmt}. Please ensure phone Location & Precise Location are enabled.`, 'warning');
            const contBtn = document.getElementById('btnProceedOutsideAnyway');
            if (contBtn) contBtn.style.display = 'block';
        }
    } catch(e) {
        showAttendanceToast('Could not acquire GPS: ' + e.message, 'error');
    } finally {
        if (icon) icon.classList.remove('spin');
        if (btn) btn.disabled = false;
        if (window.lucide) lucide.createIcons();
    }
}

function proceedOutsideAnyway() {
    closeLocationPermissionModal();
    const punchType = appAttendanceState.pendingPunchType || 'checkin';
    const selfie = appAttendanceState.selfieCapturedBase64 || '';
    openOutsideReasonModal(punchType, selfie, appAttendanceState.distanceMeters, appAttendanceState.settings?.geofence_radius_meters || 100);
}

async function useNetworkIpLocationFallback() {
    const statusText = document.getElementById('geofenceStatusText');
    const coordsDisplay = document.getElementById('coordsDisplay');
    const accuracyDisplay = document.getElementById('accuracyDisplay');
    const permPrompt = document.getElementById('locationPermissionPrompt');

    if (statusText) statusText.innerText = 'Resolving Office Network...';

    // When GPS is unavailable (e.g. desktop PC / laptop without GPS chip),
    // use the configured office coordinates so employees in the office are not falsely marked 70km away
    if (appAttendanceState.settings && appAttendanceState.settings.office_lat && appAttendanceState.settings.office_lng) {
        appAttendanceState.userCoords = {
            lat: parseFloat(appAttendanceState.settings.office_lat),
            lng: parseFloat(appAttendanceState.settings.office_lng),
            acc: 50,
            is_fallback: true,
            city: 'Office HQ'
        };
        if (coordsDisplay) coordsDisplay.innerText = `${appAttendanceState.userCoords.lat.toFixed(5)}, ${appAttendanceState.userCoords.lng.toFixed(5)}`;
        if (accuracyDisplay) accuracyDisplay.innerText = 'Office Network (PC)';
        evaluateGeofence();
        if (permPrompt) permPrompt.style.display = 'none';
        return appAttendanceState.userCoords;
    }

    if (permPrompt) permPrompt.style.display = 'none';
    return null;
}

async function acquireDeviceLocation(interactive = false) {
    try {
        await getDeviceLocationPromise();
    } catch (e) {
        if (interactive) {
            const prompt = document.getElementById('locationPermissionPrompt');
            if (prompt) prompt.style.display = 'block';
        }
    }
}

function evaluateGeofence() {
    const pill = document.getElementById('geofencePill');
    const statusText = document.getElementById('geofenceStatusText');
    const distanceText = document.getElementById('geofenceDistanceText');
    const outsideCheckinAlert = document.getElementById('outsideCheckinAlert');
    const outsideCheckoutAlert = document.getElementById('outsideCheckoutAlert');

    const isConfigured = Boolean(appAttendanceState.settings && appAttendanceState.settings.is_configured == 1 && appAttendanceState.settings.office_lat);

    if (!isConfigured) {
        if (pill) {
            pill.style.background = 'rgba(245,158,11,0.08)';
            pill.style.borderColor = 'rgba(245,158,11,0.25)';
        }
        if (statusText) {
            statusText.innerText = 'Office Geofence Pending';
            statusText.style.color = '#b45309';
        }
        if (distanceText) distanceText.innerText = 'Super Admin must calibrate office GPS coordinates first.';
        if (outsideCheckinAlert) outsideCheckinAlert.style.display = 'none';
        if (outsideCheckoutAlert) outsideCheckoutAlert.style.display = 'none';
        return;
    }

    if (!appAttendanceState.userCoords) return;

    const uLat = appAttendanceState.userCoords.lat;
    const uLng = appAttendanceState.userCoords.lng;
    const oLat = parseFloat(appAttendanceState.settings.office_lat);
    const oLng = parseFloat(appAttendanceState.settings.office_lng);
    const radius = parseFloat(appAttendanceState.settings.geofence_radius_meters || 100);

    // Client Haversine Formula (meters)
    const dLat = (oLat - uLat) * (Math.PI / 180);
    const dLng = (oLng - uLng) * (Math.PI / 180);
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(uLat * (Math.PI / 180)) * Math.cos(oLat * (Math.PI / 180)) *
              Math.sin(dLng / 2) * Math.sin(dLng / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    const distMeters = Math.round(6371000 * c);

    appAttendanceState.distanceMeters = distMeters;
    appAttendanceState.isInside = (distMeters <= radius);

    const qBar = document.getElementById('adminQuickCalibrateBar');
    const qCoords = document.getElementById('quickCalibrateCoordsText');

    if (appAttendanceState.isInside) {
        if (pill) {
            pill.style.background = 'rgba(16,185,129,0.1)';
            pill.style.borderColor = 'rgba(16,185,129,0.3)';
        }
        if (statusText) {
            statusText.innerText = 'Inside Office Geofence';
            statusText.style.color = '#10b981';
        }
        if (distanceText) distanceText.innerText = `Verified in perimeter (Distance: ${distMeters}m / Allowed: ${radius}m).`;
        if (outsideCheckinAlert) outsideCheckinAlert.style.display = 'none';
        if (outsideCheckoutAlert) outsideCheckoutAlert.style.display = 'none';
        if (qBar) qBar.style.display = 'none';
    } else {
        if (pill) {
            pill.style.background = 'rgba(239,68,68,0.08)';
            pill.style.borderColor = 'rgba(239,68,68,0.25)';
        }
        if (statusText) {
            statusText.innerText = 'Outside Office Perimeter';
            statusText.style.color = '#ef4444';
        }
        if (distanceText) distanceText.innerText = `Distance: ${distMeters > 1000 ? (distMeters/1000).toFixed(2) + ' km' : distMeters + 'm'} (Allowed: ${radius}m). Super Admin approval required.`;
        if (outsideCheckinAlert) outsideCheckinAlert.style.display = 'block';
        if (outsideCheckoutAlert) outsideCheckoutAlert.style.display = 'block';

        if (qBar && appAttendanceState.isAdminUser) {
            qBar.style.display = 'block';
            if (qCoords) qCoords.innerText = `Detected: ${uLat.toFixed(5)}, ${uLng.toFixed(5)}`;
        }
    }
}

// -----------------------------------------------------------------------------
// Central State Synchronization from Server API
// -----------------------------------------------------------------------------
async function refreshAttendanceState() {
    const icon = document.getElementById('refresh-state-icon');
    if (icon) icon.classList.add('spin');

    try {
        const res = await fetch('api/attendance.php?action=get_status');
        const data = await res.json();

        if (data.success) {
            appAttendanceState.settings = data.settings;
            if (data.user_shift) {
                appAttendanceState.userShift = data.user_shift;
            }
            appAttendanceState.punch = data.punch;
            appAttendanceState.runningSeconds = data.running_seconds || 0;

            const isConfigured = Boolean(data.settings && ((data.settings.is_configured == 1) || (data.settings.office_lat && parseFloat(data.settings.office_lat) !== 0)));

            // Handle Setup Lock vs Active States
            const adminBanner = document.getElementById('adminSetupPendingBanner');
            const employeeLock = document.getElementById('employeeSetupLockScreen');
            const consoleGrid = document.getElementById('punchConsoleGrid');

            if (!isConfigured) {
                if (appAttendanceState.isAdminUser) {
                    if (adminBanner) adminBanner.style.display = 'block';
                    if (employeeLock) employeeLock.style.display = 'none';
                    if (consoleGrid) consoleGrid.style.display = 'block';
                } else {
                    if (adminBanner) adminBanner.style.display = 'none';
                    if (employeeLock) employeeLock.style.display = 'block';
                    if (consoleGrid) consoleGrid.style.display = 'none';
                }
            } else {
                if (adminBanner) adminBanner.style.display = 'none';
                if (employeeLock) employeeLock.style.display = 'none';
                if (consoleGrid) consoleGrid.style.display = 'block';
            }

            try {
                renderSettingsDetails(data.settings);
            } catch (errSet) {
                console.warn('renderSettingsDetails notice:', errSet);
            }

            try {
                renderPunchState(data.punch, data.running_seconds);
            } catch (errPunch) {
                console.warn('renderPunchState notice:', errPunch);
            }

            if (data.admin_stats && document.getElementById('tab-pending-badge')) {
                const badge = document.getElementById('tab-pending-badge');
                const cnt = data.admin_stats.pending_approvals_count || 0;
                badge.innerText = cnt;
                badge.style.display = cnt > 0 ? 'inline-block' : 'none';
            }

            evaluateGeofence();
        } else {
            console.warn('Sync status API message:', data.message);
            // Fallback: If not punched, ensure checkin button is visible
            if (!appAttendanceState.punch || !appAttendanceState.punch.checkin_time) {
                const sCheckin = document.getElementById('stateCheckin');
                if (sCheckin) sCheckin.style.display = 'block';
            }
        }
    } catch (e) {
        console.error('Failed to sync attendance state:', e);
        // Fallback: If not punched, ensure checkin button is visible
        if (!appAttendanceState.punch || !appAttendanceState.punch.checkin_time) {
            const sCheckin = document.getElementById('stateCheckin');
            if (sCheckin) sCheckin.style.display = 'block';
        }
    } finally {
        if (icon) icon.classList.remove('spin');
        if (window.lucide) lucide.createIcons();
    }
}

function renderSettingsDetails(settings) {
    if (!settings) return;
    const officeNameEl = document.getElementById('summaryOfficeName');
    const officeRadiusEl = document.getElementById('summaryOfficeRadius');
    const shiftNoticeEl = document.getElementById('shiftInfoNotice');

    if (officeNameEl) officeNameEl.innerText = settings.office_name || 'Office Not Configured';
    if (officeRadiusEl) officeRadiusEl.innerText = `${settings.geofence_radius_meters || 150} meters`;
    if (shiftNoticeEl) {
        const uShift = appAttendanceState.userShift || {
            shift_start_time: settings.shift_start_time,
            shift_end_time: settings.shift_end_time,
            grace_period_minutes: settings.grace_period_minutes,
            is_custom: false
        };
        const sStart = (uShift.shift_start_time || '10:15:00').substring(0, 5);
        const sEnd = (uShift.shift_end_time || '20:00:00').substring(0, 5);
        const sGrace = uShift.grace_period_minutes || 15;
        const sCust = uShift.is_custom ? ' (Custom Shift)' : '';
        const offName = settings.office_name || 'Marg Soft Solution HQ';
        shiftNoticeEl.innerText = `${offName} (Shift: ${sStart} - ${sEnd}${sCust}, Grace: ${sGrace}m)`;
    }

    const fName = document.getElementById('settingOfficeName');
    const fLat = document.getElementById('settingOfficeLat');
    const fLng = document.getElementById('settingOfficeLng');
    const fRad = document.getElementById('settingRadius');
    const fGps = document.getElementById('settingRequireGps');
    const fStart = document.getElementById('settingShiftStart');
    const fEnd = document.getElementById('settingShiftEnd');
    const fGrace = document.getElementById('settingGracePeriod');

    if (fName && settings.office_name) fName.value = settings.office_name;
    if (fLat && settings.office_lat) fLat.value = settings.office_lat;
    if (fLng && settings.office_lng) fLng.value = settings.office_lng;
    if (fRad && settings.geofence_radius_meters) fRad.value = settings.geofence_radius_meters;
    if (fGps && settings.require_gps !== undefined) fGps.value = settings.require_gps;
    if (fStart && settings.shift_start_time) fStart.value = settings.shift_start_time.substring(0, 5);
    if (fEnd && settings.shift_end_time) fEnd.value = settings.shift_end_time.substring(0, 5);
    if (fGrace && settings.grace_period_minutes) fGrace.value = settings.grace_period_minutes;

    const fSelfie = document.getElementById('settingRequireSelfie');
    if (fSelfie && settings.require_selfie !== undefined) fSelfie.value = settings.require_selfie;

    const camBadge = document.getElementById('cameraStatusBadge');
    if (camBadge && settings.require_selfie == 0) {
        camBadge.innerText = 'Selfie Optional';
        camBadge.style.background = 'rgba(100,116,139,0.1)';
        camBadge.style.color = 'var(--text-muted)';
    }
}

function renderPunchState(punch, runningSecs) {
    const sCheckin = document.getElementById('stateCheckin');
    const sActive = document.getElementById('stateActiveShift');
    const sCompleted = document.getElementById('stateCompletedShift');
    const approvalPill = document.getElementById('summaryCheckinApproval');

    if (appAttendanceState.shiftTimerInterval) {
        clearInterval(appAttendanceState.shiftTimerInterval);
        appAttendanceState.shiftTimerInterval = null;
    }

    if (!punch || !punch.checkin_time) {
        if (sCheckin) sCheckin.style.display = 'block';
        if (sActive) sActive.style.display = 'none';
        if (sCompleted) sCompleted.style.display = 'none';
        if (approvalPill) {
            approvalPill.innerText = 'Not Punched Today';
            approvalPill.style.background = 'rgba(100,116,139,0.1)';
            approvalPill.style.color = 'var(--text-muted)';
        }
    } else if (punch.checkin_time && (!punch.checkout_time || punch.checkout_approval_status === 'not_checked_out')) {
        if (sCheckin) sCheckin.style.display = 'none';
        if (sActive) sActive.style.display = 'block';
        if (sCompleted) sCompleted.style.display = 'none';

        const inTimeStr = safeFormatTime(punch.checkin_time);
        const inDisplay = document.getElementById('activeCheckinTimeDisplay');
        if (inDisplay) inDisplay.innerText = `Checked in at ${inTimeStr}`;

        const pendingBanner = document.getElementById('activeCheckinApprovalBanner');
        if (pendingBanner) {
            pendingBanner.style.display = (punch.checkin_approval_status === 'pending') ? 'block' : 'none';
        }

        if (approvalPill) {
            if (punch.checkin_approval_status === 'pending') {
                approvalPill.innerText = 'Outside (Pending Admin Approval)';
                approvalPill.style.background = 'rgba(245,158,11,0.15)';
                approvalPill.style.color = '#b45309';
            } else {
                approvalPill.innerText = 'Verified Inside Office';
                approvalPill.style.background = 'rgba(16,185,129,0.15)';
                approvalPill.style.color = '#10b981';
            }
        }

        let currentSeconds = runningSecs || 0;
        const counterEl = document.getElementById('liveShiftCounter');
        function tickShiftTimer() {
            currentSeconds++;
            const hrs = String(Math.floor(currentSeconds / 3600)).padStart(2, '0');
            const mins = String(Math.floor((currentSeconds % 3600) / 60)).padStart(2, '0');
            const secs = String(currentSeconds % 60).padStart(2, '0');
            if (counterEl) counterEl.innerText = `${hrs}h : ${mins}m : ${secs}s`;
            const mDur = document.getElementById('todayMetricDuration');
            if (mDur) mDur.innerText = `${hrs}h ${mins}m`;
        }
        tickShiftTimer();
        appAttendanceState.shiftTimerInterval = setInterval(tickShiftTimer, 1000);
    } else {
        if (sCheckin) sCheckin.style.display = 'none';
        if (sActive) sActive.style.display = 'none';
        if (sCompleted) sCompleted.style.display = 'block';

        const inTimeStr = safeFormatTime(punch.checkin_time);
        const outTimeStr = safeFormatTime(punch.checkout_time);

        const inEl = document.getElementById('completedInTime');
        const outEl = document.getElementById('completedOutTime');
        const durEl = document.getElementById('completedDuration');
        const statEl = document.getElementById('completedStatusBadge');

        if (inEl) inEl.innerText = inTimeStr;
        if (outEl) outEl.innerText = outTimeStr;

        const wMins = punch.total_work_minutes || 0;
        if (durEl) durEl.innerText = `${Math.floor(wMins / 60)} hrs ${wMins % 60} mins`;

        if (statEl) {
            if (punch.status === 'pending_approval' || punch.checkout_approval_status === 'pending') {
                statEl.innerText = 'Pending Admin Approval';
                statEl.style.background = '#f59e0b';
            } else {
                statEl.innerText = 'Shift Completed';
                statEl.style.background = '#10b981';
            }
        }

        if (approvalPill) {
            approvalPill.innerText = 'Shift Concluded';
            approvalPill.style.background = 'rgba(16,185,129,0.15)';
            approvalPill.style.color = '#10b981';
        }
    }

    // Synchronize 2x2 Attendance Summary Metrics Card
    const mInTime = document.getElementById('todayMetricInTime');
    const mInBadge = document.getElementById('todayMetricInBadge');
    const mOutTime = document.getElementById('todayMetricOutTime');
    const mOutBadge = document.getElementById('todayMetricOutBadge');
    const mDur = document.getElementById('todayMetricDuration');
    const mStat = document.getElementById('todayMetricStatusBadge');

    if (!punch || !punch.checkin_time) {
        if (mInTime) mInTime.innerText = '--:--';
        if (mInBadge) { mInBadge.innerText = 'Unpunched'; mInBadge.style.color = 'var(--text-muted)'; mInBadge.style.background = 'rgba(100,116,139,0.1)'; }
        if (mOutTime) mOutTime.innerText = '--:--';
        if (mOutBadge) { mOutBadge.innerText = '--'; mOutBadge.style.color = 'var(--text-muted)'; mOutBadge.style.background = 'rgba(100,116,139,0.1)'; }
        if (mDur) mDur.innerText = '00h 00m';
        if (mStat) { mStat.innerText = 'Not Marked'; mStat.style.background = 'rgba(100,116,139,0.1)'; mStat.style.color = 'var(--text-muted)'; }
    } else if (punch.checkin_time && (!punch.checkout_time || punch.checkout_approval_status === 'not_checked_out')) {
        const inTimeStr = safeFormatTime(punch.checkin_time);
        if (mInTime) mInTime.innerText = inTimeStr;
        if (mInBadge) {
            const isOutside = (punch.checkin_approval_status === 'pending');
            mInBadge.innerText = isOutside ? 'Outside' : 'Office HQ (0m)';
            mInBadge.style.color = isOutside ? '#b45309' : '#10b981';
            mInBadge.style.background = isOutside ? 'rgba(245,158,11,0.15)' : 'rgba(16,185,129,0.15)';
        }
        if (mOutTime) mOutTime.innerText = '--:--';
        if (mOutBadge) { mOutBadge.innerText = 'Running'; mOutBadge.style.color = '#0891b2'; mOutBadge.style.background = 'rgba(6,182,212,0.15)'; }
        if (mStat) { mStat.innerText = 'Working Now'; mStat.style.background = 'rgba(6,182,212,0.15)'; mStat.style.color = '#0891b2'; }
    } else {
        const inTimeStr = safeFormatTime(punch.checkin_time);
        const outTimeStr = safeFormatTime(punch.checkout_time);
        const wMins = punch.total_work_minutes || 0;
        if (mInTime) mInTime.innerText = inTimeStr;
        if (mInBadge) {
            const isOutside = (punch.checkin_approval_status === 'pending');
            mInBadge.innerText = isOutside ? 'Outside' : 'Office HQ';
            mInBadge.style.color = isOutside ? '#b45309' : '#10b981';
            mInBadge.style.background = isOutside ? 'rgba(245,158,11,0.15)' : 'rgba(16,185,129,0.15)';
        }
        if (mOutTime) mOutTime.innerText = outTimeStr;
        if (mOutBadge) {
            const isOutside = (punch.checkout_approval_status === 'pending');
            mOutBadge.innerText = isOutside ? 'Outside' : 'Office HQ';
            mOutBadge.style.color = isOutside ? '#b45309' : '#10b981';
            mOutBadge.style.background = isOutside ? 'rgba(245,158,11,0.15)' : 'rgba(16,185,129,0.15)';
        }
        if (mDur) mDur.innerText = `${Math.floor(wMins / 60)}h ${wMins % 60}m`;
        if (mStat) {
            const isPending = (punch.status === 'pending_approval' || punch.checkout_approval_status === 'pending');
            mStat.innerText = isPending ? 'Pending Approval' : 'Shift Completed';
            mStat.style.background = isPending ? 'rgba(245,158,11,0.15)' : 'rgba(16,185,129,0.15)';
            mStat.style.color = isPending ? '#b45309' : '#10b981';
        }
    }
}

// -----------------------------------------------------------------------------
// PUNCH IN & PUNCH OUT STREAMLINED 1-CLICK LIVE FLOW
// -----------------------------------------------------------------------------
function captureLiveSnapshotFromVideo() {
    const video = document.getElementById('webcamVideo');
    const canvas = document.getElementById('snapshotCanvas');
    if (!video || !canvas) return null;

    if (video.videoWidth === 0 || video.videoHeight === 0) {
        return null;
    }

    // Compress to 320x240 at quality 0.6 — keeps file ~15KB (vs 80KB at full res)
    // Faster POST upload on mobile/slow networks and still sufficient for biometric record
    const targetW = 320;
    const targetH = Math.round(video.videoHeight * (targetW / video.videoWidth));
    canvas.width = targetW;
    canvas.height = targetH;
    const ctx = canvas.getContext('2d');
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, 0, 0, targetW, targetH);

    const dataUrl = canvas.toDataURL('image/jpeg', 0.6);
    appAttendanceState.selfieCapturedBase64 = dataUrl;

    const preview = document.getElementById('selfieSnapshotImg');
    if (preview) {
        preview.src = dataUrl;
        // preview is in off-screen container — no display change needed
    }
    // Do NOT touch video.style.display — video lives in off-screen fixed container

    return dataUrl;
}

function retakeSelfieSnapshot() {
    const video = document.getElementById('webcamVideo');
    const preview = document.getElementById('selfieSnapshotImg');
    appAttendanceState.selfieCapturedBase64 = null;
    if (preview) preview.style.display = 'none';
    if (video) video.style.display = 'block';
}

function checkIsInsideOffice() {
    if (!appAttendanceState.settings || !appAttendanceState.settings.office_lat || !appAttendanceState.settings.office_lng) {
        return true;
    }
    if (!appAttendanceState.userCoords || !appAttendanceState.userCoords.lat || !appAttendanceState.userCoords.lng) {
        return false;
    }

    const uLat = appAttendanceState.userCoords.lat;
    const uLng = appAttendanceState.userCoords.lng;
    const oLat = parseFloat(appAttendanceState.settings.office_lat);
    const oLng = parseFloat(appAttendanceState.settings.office_lng);
    const radius = parseFloat(appAttendanceState.settings.geofence_radius_meters || 100);

    // Haversine formula (meters)
    const dLat = (oLat - uLat) * (Math.PI / 180);
    const dLng = (oLng - uLng) * (Math.PI / 180);
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(uLat * (Math.PI / 180)) * Math.cos(oLat * (Math.PI / 180)) *
              Math.sin(dLng / 2) * Math.sin(dLng / 2);
    const dist = Math.round(6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));

    appAttendanceState.distanceMeters = dist;
    appAttendanceState.isInside = (dist <= radius);
    return appAttendanceState.isInside;
}

let outsideModalContext = {
    action: 'checkin',
    selfieBase64: null,
    distance: 0,
    radius: 0
};

function openOutsideReasonModal(action = 'checkin', selfieBase64 = null, dist = 0, radius = 0) {
    outsideModalContext.action = action;
    outsideModalContext.selfieBase64 = selfieBase64;
    outsideModalContext.distance = dist || appAttendanceState.distanceMeters || 0;
    outsideModalContext.radius = radius || (appAttendanceState.settings ? appAttendanceState.settings.geofence_radius_meters : 100);

    const modal = document.getElementById('outsidePunchModal');
    const titleEl = document.getElementById('outsideModalTitle');
    const subtitleEl = document.getElementById('outsideModalSubtitle');
    const previewImg = document.getElementById('outsideModalSelfiePreview');
    const reasonText = document.getElementById('outsideDutyReasonText');
    const chipsContainer = document.getElementById('outsideModalPresetChips');

    if (!modal) return;

    if (reasonText) reasonText.value = '';

    if (previewImg && selfieBase64) {
        previewImg.src = selfieBase64;
    }

    const distFmt = outsideModalContext.distance > 1000 ? (outsideModalContext.distance / 1000).toFixed(2) + ' km' : outsideModalContext.distance + ' meters';

    if (action === 'checkin') {
        if (titleEl) titleEl.innerText = 'Outside Office Check-in';
        if (subtitleEl) subtitleEl.innerText = `You are ${distFmt} from office perimeter (Allowed: ${outsideModalContext.radius}m). Official duty reason required for Super Admin approval.`;
        if (chipsContainer) {
            chipsContainer.innerHTML = `
                <button type="button" onclick="selectOutsidePreset('Client Site Visit / On-site Client Meeting')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Client Site Visit</button>
                <button type="button" onclick="selectOutsidePreset('Field Sales Demo & Customer Pitch')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Field Sales Demo</button>
                <button type="button" onclick="selectOutsidePreset('Work From Home (Approved Remote Shift)')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Work From Home</button>
                <button type="button" onclick="selectOutsidePreset('Official Outdoor Duty / Traveling')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Official Travel</button>
            `;
        }
    } else {
        if (titleEl) titleEl.innerText = 'Outside Office Check-out';
        if (subtitleEl) subtitleEl.innerText = `You are ${distFmt} from office perimeter (Allowed: ${outsideModalContext.radius}m). Official duty reason required for Super Admin approval.`;
        if (chipsContainer) {
            chipsContainer.innerHTML = `
                <button type="button" onclick="selectOutsidePreset('Departing directly from client office')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Client Site Exit</button>
                <button type="button" onclick="selectOutsidePreset('Shift concluded from remote work location')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Remote Shift Done</button>
                <button type="button" onclick="selectOutsidePreset('Field duty completed for the day')" class="badge cursor-pointer" style="background: rgba(37,99,235,0.08); color: var(--primary); border: 1px solid rgba(37,99,235,0.25); font-size: 0.72rem; padding: 5px 10px; cursor: pointer; border-radius: 6px;">Field Duty Completed</button>
            `;
        }
    }

    modal.classList.add('open');
    modal.classList.add('active');
    modal.style.display = 'flex';
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    showAttendanceToast(`Outside office perimeter (${distFmt}). Select reason to submit.`, 'info');
    if (window.lucide) lucide.createIcons();
    if (reasonText) setTimeout(() => reasonText.focus(), 150);
}

function selectOutsidePreset(presetText) {
    const reasonText = document.getElementById('outsideDutyReasonText');
    if (reasonText) {
        reasonText.value = presetText;
        reasonText.focus();
    }
}

function closeOutsideReasonModal() {
    const modal = document.getElementById('outsidePunchModal');
    if (modal) {
        modal.classList.remove('open');
        modal.classList.remove('active');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
    }
    retakeSelfieSnapshot();
}

async function submitOutsideReasonFromModal() {
    const reasonText = document.getElementById('outsideDutyReasonText');
    const reason = reasonText ? reasonText.value.trim() : '';

    if (!reason) {
        showAttendanceToast('Please specify official duty reason for outside punch.', 'warning');
        if (reasonText) reasonText.focus();
        return;
    }

    if (outsideModalContext.action === 'checkin') {
        await executeCheckinRequest(reason, outsideModalContext.selfieBase64);
    } else {
        await executeCheckoutRequest(reason, outsideModalContext.selfieBase64);
    }
}

async function handleOneClickCheckin() {
    const isConfigured = Boolean(appAttendanceState.settings && ((appAttendanceState.settings.is_configured == 1) || (appAttendanceState.settings.office_lat && parseFloat(appAttendanceState.settings.office_lat) !== 0)));
    if (!isConfigured) {
        showAttendanceToast('Office location setup is pending. Please contact Super Admin.', 'error');
        return;
    }

    const btn = document.getElementById('btnSubmitCheckin');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 18px; height: 18px;"></i> Verifying & Recording Check-in...';
        if (window.lucide) lucide.createIcons();
    }

    // Hard safety timer: button ALWAYS resets within 15s no matter what
    const safetyTimer = setTimeout(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="fingerprint" style="width: 22px; height: 22px;"></i> <span>Punch In (Check-in Now)</span>';
            if (window.lucide) lucide.createIcons();
        }
        showAttendanceToast('Verification timed out. Please try again.', 'error');
    }, 30000);

    try {
        // 1. Live biometric photo capture (ImageCapture or video snapshot)
        let liveSelfie = await ensureLiveCameraSelfie(3500);

        if (liveSelfie === null) {
            const requireSelfie = (appAttendanceState.settings && appAttendanceState.settings.require_selfie !== undefined)
                ? parseInt(appAttendanceState.settings.require_selfie)
                : 1;

            if (requireSelfie === 1) {
                // Biometric policy is mandatory: trigger direct camera snap
                appAttendanceState.pendingPunchType = 'checkin';
                triggerDirectCamera();
                showAttendanceToast('Webcam stream unavailable. Please snap a live photo to verify attendance.', 'info');
                return;
            } else {
                liveSelfie = ''; // Optional mode: proceed without selfie
            }
        }

        // 2. Exact location matching (Fast GPS or high-accuracy satellite lock)
        appAttendanceState.pendingPunchType = 'checkin';
        let coords = await getDeviceLocationPromise(true);

        if (!coords || !coords.lat) {
            openLocationPermissionModal('denied');
            showAttendanceToast('Location access is required for attendance verification.', 'warning');
            return;
        }

        // If phone returned an inaccurate cellular tower fix (> 800m), show guidance modal to lock real GPS
        if (coords.acc > 800 || coords.is_coarse) {
            openLocationPermissionModal('coarse', coords.acc);
            return;
        }

        const isInside = checkIsInsideOffice();

        // 3. If Outside Office Perimeter -> Open Responsive Reason Modal for Super Admin Approval!
        if (!isInside) {
            openOutsideReasonModal('checkin', liveSelfie || '', appAttendanceState.distanceMeters, appAttendanceState.settings.geofence_radius_meters);
            return;
        }

        // 4. If Inside -> Immediate 1-Click Check-in Submission!
        await executeCheckinRequest('', liveSelfie || '');
    } catch (err) {
        console.error('[Checkin] Process error:', err);
        showAttendanceToast('Check-in error: ' + (err.message || 'Verification failed.'), 'error');
    } finally {
        clearTimeout(safetyTimer);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="fingerprint" style="width: 22px; height: 22px;"></i> <span>Punch In (Check-in Now)</span>';
            if (window.lucide) lucide.createIcons();
        }
    }
}

async function executeCheckinRequest(reason = '', selfieBase64 = null) {
    const btn = document.getElementById('btnSubmitCheckin');
    const modalBtn = document.getElementById('btnSubmitOutsideModal');

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 18px; height: 18px;"></i> Recording Check-in...';
        if (window.lucide) lucide.createIcons();
    }
    if (modalBtn) {
        modalBtn.disabled = true;
        modalBtn.innerText = 'Submitting...';
    }

    try {
        const payload = {
            action: 'checkin',
            lat: appAttendanceState.userCoords ? appAttendanceState.userCoords.lat : 0,
            lng: appAttendanceState.userCoords ? appAttendanceState.userCoords.lng : 0,
            selfie: selfieBase64 || appAttendanceState.selfieCapturedBase64 || '',
            reason: reason
        };

        const fetchController = new AbortController();
        const fetchTimeout = setTimeout(() => fetchController.abort(), 12000);

        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: fetchController.signal
        });
        clearTimeout(fetchTimeout);

        const data = await res.json();
        if (data.success) {
            closeOutsideReasonModal();
            showAttendanceToast(data.message || 'Check-in recorded successfully!', 'success');

            // Immediate Instant Switch to Active Shift View
            const formattedIn = safeFormatTime(data.checkin_time || new Date());
            const sCheckin = document.getElementById('stateCheckin');
            const sActive = document.getElementById('stateActiveShift');
            const sCompleted = document.getElementById('stateCompletedShift');
            const inDisplay = document.getElementById('activeCheckinTimeDisplay');
            const mInTime = document.getElementById('todayMetricInTime');
            const mStat = document.getElementById('todayMetricStatusBadge');

            if (sCheckin) sCheckin.style.display = 'none';
            if (sActive) sActive.style.display = 'block';
            if (sCompleted) sCompleted.style.display = 'none';
            if (inDisplay) inDisplay.innerText = `Checked in at ${formattedIn}`;
            if (mInTime) mInTime.innerText = formattedIn;
            if (mStat) {
                mStat.innerText = 'Working Now';
                mStat.style.background = 'rgba(6,182,212,0.15)';
                mStat.style.color = '#0891b2';
            }

            await refreshAttendanceState();
            if (appAttendanceState.isAdminUser && typeof loadDailyBoard === 'function') {
                loadDailyBoard();
            }
        } else {
            if (data.require_reason) {
                openOutsideReasonModal('checkin', selfieBase64, data.distance, data.radius);
            }
            showAttendanceToast(data.message || 'Check-in failed.', 'error');
            retakeSelfieSnapshot();
        }
    } catch (err) {
        if (err.name === 'AbortError') {
            showAttendanceToast('Server response timed out. Please try again.', 'error');
        } else {
            showAttendanceToast('Check-in error: ' + err.message, 'error');
        }
        retakeSelfieSnapshot();
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="fingerprint" style="width: 22px; height: 22px;"></i> Punch In (Check-in Now)';
            if (window.lucide) lucide.createIcons();
        }
        if (modalBtn) {
            modalBtn.disabled = false;
            modalBtn.innerText = 'Submit for Approval';
        }
    }
}

async function handleOneClickCheckout() {
    const btn = document.getElementById('btnSubmitCheckout');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 18px; height: 18px;"></i> Recording Check-out...';
        if (window.lucide) lucide.createIcons();
    }

    // Hard safety timer: button ALWAYS resets within 15s no matter what
    const safetyTimer = setTimeout(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="log-out" style="width: 22px; height: 22px;"></i> <span>Punch Out (Check-out)</span>';
            if (window.lucide) lucide.createIcons();
        }
        showAttendanceToast('Verification timed out. Please try again.', 'error');
    }, 30000);

    try {
        // 1. Live biometric photo capture (ImageCapture or video snapshot)
        let liveSelfie = await ensureLiveCameraSelfie(3500);

        if (liveSelfie === null) {
            const requireSelfie = (appAttendanceState.settings && appAttendanceState.settings.require_selfie !== undefined)
                ? parseInt(appAttendanceState.settings.require_selfie)
                : 1;

            if (requireSelfie === 1) {
                // Biometric policy is mandatory: trigger direct camera snap
                appAttendanceState.pendingPunchType = 'checkout';
                triggerDirectCamera();
                showAttendanceToast('Webcam stream unavailable. Please snap a live photo to verify attendance.', 'info');
                return;
            } else {
                liveSelfie = ''; // Optional mode: proceed without selfie
            }
        }

        // 2. Exact location matching (Fast GPS or high-accuracy satellite lock)
        appAttendanceState.pendingPunchType = 'checkout';
        let coords = await getDeviceLocationPromise(true);

        if (!coords || !coords.lat) {
            openLocationPermissionModal('denied');
            showAttendanceToast('Unable to determine location coordinates.', 'warning');
            return;
        }

        // If phone returned an inaccurate cellular tower fix (> 800m), show guidance modal to lock real GPS
        if (coords.acc > 800 || coords.is_coarse) {
            openLocationPermissionModal('coarse', coords.acc);
            return;
        }

        const isInside = checkIsInsideOffice();

        // 3. If Outside -> Open Responsive Reason Modal for Super Admin Approval!
        if (!isInside) {
            openOutsideReasonModal('checkout', liveSelfie || '', appAttendanceState.distanceMeters, appAttendanceState.settings.geofence_radius_meters);
            return;
        }

        // 4. If Inside -> Immediate 1-Click Check-out!
        await executeCheckoutRequest('', liveSelfie || '');
    } catch (err) {
        console.error('[Checkout] Process error:', err);
        showAttendanceToast('Check-out error: ' + (err.message || 'Verification failed.'), 'error');
    } finally {
        clearTimeout(safetyTimer);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="log-out" style="width: 22px; height: 22px;"></i> <span>Punch Out (Check-out)</span>';
            if (window.lucide) lucide.createIcons();
        }
    }
}

async function executeCheckoutRequest(reason = '', selfieBase64 = null) {
    const btn = document.getElementById('btnSubmitCheckout');
    const modalBtn = document.getElementById('btnSubmitOutsideModal');

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 18px; height: 18px;"></i> Recording Check-out...';
        if (window.lucide) lucide.createIcons();
    }
    if (modalBtn) {
        modalBtn.disabled = true;
        modalBtn.innerText = 'Submitting...';
    }

    try {
        const payload = {
            action: 'checkout',
            lat: appAttendanceState.userCoords ? appAttendanceState.userCoords.lat : 0,
            lng: appAttendanceState.userCoords ? appAttendanceState.userCoords.lng : 0,
            selfie: selfieBase64 || appAttendanceState.selfieCapturedBase64 || '',
            reason: reason
        };

        const fetchController2 = new AbortController();
        const fetchTimeout2 = setTimeout(() => fetchController2.abort(), 12000);

        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: fetchController2.signal
        });
        clearTimeout(fetchTimeout2);

        const data = await res.json();
        if (data.success) {
            closeOutsideReasonModal();
            showAttendanceToast(data.message || 'Check-out verified successfully!', 'success');
            await refreshAttendanceState();
            if (appAttendanceState.isAdminUser && typeof loadDailyBoard === 'function') {
                loadDailyBoard();
            }
        } else {
            if (data.require_reason) {
                openOutsideReasonModal('checkout', selfieBase64, data.distance, data.radius);
            }
            showAttendanceToast(data.message || 'Check-out failed.', 'error');
            retakeSelfieSnapshot();
        }
    } catch (err) {
        if (err.name === 'AbortError') {
            showAttendanceToast('Server response timed out. Please try again.', 'error');
        } else {
            showAttendanceToast('Check-out error: ' + err.message, 'error');
        }
        retakeSelfieSnapshot();
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="log-out" style="width: 22px; height: 22px;"></i> Punch Out (Check-out)';
            if (window.lucide) lucide.createIcons();
        }
        if (modalBtn) {
            modalBtn.disabled = false;
            modalBtn.innerText = 'Submit for Approval';
        }
    }
}

window.submitAttendanceCheckin = handleOneClickCheckin;
window.submitAttendanceCheckout = handleOneClickCheckout;

// Tab Switching
// -----------------------------------------------------------------------------
function switchAttendanceTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-pane-content').forEach(pane => pane.style.display = 'none');

    const activeBtn = document.querySelector(`.tab-btn[onclick="switchAttendanceTab('${tabName}')"]`);
    const activePane = document.getElementById(`tab-pane-${tabName}`);

    if (activeBtn) activeBtn.classList.add('active');
    if (activePane) activePane.style.display = 'block';

    if (tabName === 'history') {
        loadMonthlyHistory();
    } else if (tabName === 'approvals') {
        loadPendingApprovalsQueue();
    } else if (tabName === 'board') {
        loadDailyBoard();
    } else if (tabName === 'shifts') {
        loadStaffShiftsTable();
    }

    if (window.lucide) lucide.createIcons();
}

// -----------------------------------------------------------------------------
// Tab 2: Monthly History
// -----------------------------------------------------------------------------
async function loadMonthlyHistory() {
    const month = document.getElementById('historyMonthSelect')?.value || (new Date().getMonth() + 1);
    const year = document.getElementById('historyYearSelect')?.value || new Date().getFullYear();
    const tbody = document.getElementById('historyTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-muted"><i data-lucide="loader-2" class="spin" style="width: 20px; height: 20px; margin: 0 auto 6px auto; display: block;"></i> Fetching records...</td></tr>`;
    if (window.lucide) lucide.createIcons();

    try {
        const res = await fetch(`api/attendance.php?action=get_monthly_logs&month=${month}&year=${year}`);
        const data = await res.json();

        if (data.success && data.logs && data.logs.length > 0) {
            let html = '';
            data.logs.forEach(row => {
                const inTime = row.checkin_time ? new Date(row.checkin_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '--:--';
                const outTime = row.checkout_time ? new Date(row.checkout_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '--:--';
                const durHours = row.total_work_minutes ? `${Math.floor(row.total_work_minutes / 60)}h ${row.total_work_minutes % 60}m` : '--';

                let statusBadge = `<span class="badge" style="background:#10b981; color:white;">Present</span>`;
                if (row.status === 'pending_approval') {
                    statusBadge = `<span class="badge" style="background:#f59e0b; color:white;">Pending Approval</span>`;
                } else if (row.status === 'rejected') {
                    statusBadge = `<span class="badge" style="background:#ef4444; color:white;">Rejected</span>`;
                }

                let inLoc = row.checkin_is_inside == 1 ? '<span style="color:#10b981;">Office HQ</span>' : `<span style="color:#f59e0b;">Outside (${Math.round(row.checkin_distance_meters)}m)</span>`;
                let outLoc = row.checkout_time ? (row.checkout_is_inside == 1 ? '<span style="color:#10b981;">Office HQ</span>' : `<span style="color:#f59e0b;">Outside (${Math.round(row.checkout_distance_meters)}m)</span>`) : '--';

                let selfieBtns = '';
                if (row.checkin_selfie) {
                    selfieBtns += `<button type="button" onclick="viewSelfieModal('${row.checkin_selfie}', 'Check-in: ${row.employee_name} on ${row.attendance_date}')" class="btn btn-outline btn-xs" style="padding: 2px 6px;">In Selfie</button> `;
                }
                if (row.checkout_selfie) {
                    selfieBtns += `<button type="button" onclick="viewSelfieModal('${row.checkout_selfie}', 'Check-out: ${row.employee_name} on ${row.attendance_date}')" class="btn btn-outline btn-xs" style="padding: 2px 6px;">Out Selfie</button>`;
                }

                html += `
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 10px 12px; font-weight: 600;">${row.attendance_date}</td>
                    <td style="padding: 10px 12px;">${inTime}</td>
                    <td style="padding: 10px 12px;">${inLoc}</td>
                    <td style="padding: 10px 12px;">${outTime}</td>
                    <td style="padding: 10px 12px;">${outLoc}</td>
                    <td style="padding: 10px 12px; font-weight: 600;">${durHours}</td>
                    <td style="padding: 10px 12px;">${statusBadge}</td>
                    <td style="padding: 10px 12px;">${selfieBtns || '--'}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        } else {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-muted">No attendance punches recorded for this period.</td></tr>`;
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-6 text-danger">Failed to load attendance logs.</td></tr>`;
    }
}

// -----------------------------------------------------------------------------
// Tab 3: Leave Application Submission
// -----------------------------------------------------------------------------
async function submitLeaveApplication(e) {
    e.preventDefault();
    const leaveType = document.getElementById('leaveType').value;
    const startDate = document.getElementById('leaveStartDate').value;
    const endDate = document.getElementById('leaveEndDate').value;
    const reason = document.getElementById('leaveReason').value.trim();

    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'apply_leave', leave_type: leaveType, start_date: startDate, end_date: endDate, reason: reason })
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message, 'success');
            document.getElementById('leaveReason').value = '';
        } else {
            showAttendanceToast(data.message || 'Leave application failed.', 'error');
        }
    } catch (err) {
        showAttendanceToast('Failed to submit leave: ' + err.message, 'error');
    }
}

// -----------------------------------------------------------------------------
// Tab 4: Admin Pending Approvals Queue
// -----------------------------------------------------------------------------
async function loadPendingApprovalsQueue() {
    const tbody = document.getElementById('pendingApprovalsTableBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-muted"><i data-lucide="loader-2" class="spin" style="width: 20px; height: 20px; margin: 0 auto 6px auto; display: block;"></i> Refreshing approval requests...</td></tr>`;
    if (window.lucide) lucide.createIcons();

    try {
        const res = await fetch('api/attendance.php?action=get_pending_approvals');
        const data = await res.json();

        if (data.success && data.records && data.records.length > 0) {
            let html = '';
            data.records.forEach(row => {
                const isCheckinPending = (row.checkin_approval_status === 'pending');
                const reqType = isCheckinPending ? '<span class="badge" style="background:#3b82f6; color:white;">Check-in Outside</span>' : '<span class="badge" style="background:#8b5cf6; color:white;">Check-out Outside</span>';
                const time = isCheckinPending ? row.checkin_time : row.checkout_time;
                const distance = isCheckinPending ? Math.round(row.checkin_distance_meters) : Math.round(row.checkout_distance_meters);
                const reason = isCheckinPending ? (row.checkin_reason || 'No reason provided') : (row.checkout_reason || 'No reason provided');
                const selfie = isCheckinPending ? row.checkin_selfie : row.checkout_selfie;
                const lat = isCheckinPending ? row.checkin_lat : row.checkout_lat;
                const lng = isCheckinPending ? row.checkin_lng : row.checkout_lng;

                const mapLink = (lat && lng) ? `<a href="https://maps.google.com/?q=${lat},${lng}" target="_blank" class="text-xs text-primary flex align-center gap-1 mt-1 hover-underline"><i data-lucide="external-link" style="width: 12px; height: 12px;"></i> View on Google Maps</a>` : '';

                const selfieBtn = selfie ? `<button type="button" onclick="viewSelfieModal('${selfie}', '${row.employee_name}')" class="btn btn-outline btn-xs">View Selfie</button>` : '--';

                const reviewType = isCheckinPending ? 'checkin' : 'checkout';

                html += `
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 10px 12px;">
                        <strong class="text-main">${row.employee_name}</strong>
                        <div class="text-xs text-muted">${row.user_role || 'Staff'}</div>
                    </td>
                    <td style="padding: 10px 12px;">${reqType}</td>
                    <td style="padding: 10px 12px; font-size: 0.8rem;">${time}</td>
                    <td style="padding: 10px 12px;">
                        <strong style="color: #ef4444;">${distance > 1000 ? (distance/1000).toFixed(2) + ' km' : distance + ' meters'}</strong>
                        ${mapLink}
                    </td>
                    <td style="padding: 10px 12px; max-width: 220px; font-size: 0.8rem;">
                        <em>"${reason}"</em>
                    </td>
                    <td style="padding: 10px 12px;">${selfieBtn}</td>
                    <td style="padding: 10px 12px; text-align: right;">
                        <div class="flex align-center justify-end gap-2">
                            <button type="button" onclick="adminReviewPunch(${row.id}, '${reviewType}', 'approved')" class="btn btn-sm" style="background:#10b981; color:white; padding: 4px 10px; font-size: 0.75rem; font-weight: 700;">Approve</button>
                            <button type="button" onclick="adminReviewPunch(${row.id}, '${reviewType}', 'rejected')" class="btn btn-sm btn-outline text-danger" style="padding: 4px 10px; font-size: 0.75rem;">Reject</button>
                        </div>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        } else {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-muted">All outside attendance requests have been verified. No pending requests.</td></tr>`;
        }
        if (window.lucide) lucide.createIcons();
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-6 text-danger">Failed to fetch approval queue.</td></tr>`;
    }
}

async function adminReviewPunch(attendanceId, reviewType, decision, note = '') {
    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'admin_review',
                attendance_id: attendanceId,
                review_type: reviewType,
                decision: decision,
                notes: note || `${decision.toUpperCase()} by Super Admin`
            })
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message, 'success');
            loadPendingApprovalsQueue();
            loadDailyBoard();
            refreshAttendanceState();
        } else {
            showAttendanceToast(data.message || 'Action failed.', 'error');
        }
    } catch (e) {
        showAttendanceToast('Review error: ' + e.message, 'error');
    }
}

// -----------------------------------------------------------------------------
// Quick Reason Pill Selector & Radius Preset Helpers
// -----------------------------------------------------------------------------
function selectQuickReason(targetId, reasonText) {
    const el = document.getElementById(targetId);
    if (el) {
        el.value = reasonText;
        el.focus();
    }
}

function setRadiusPreset(meters) {
    const el = document.getElementById('settingRadius');
    if (el) {
        el.value = meters;
    }
}

async function quickSetOfficeToCurrentLocation() {
    if (!appAttendanceState.userCoords) {
        showAttendanceToast('Please wait for device location coordinates to be detected first.', 'warning');
        return;
    }

    const btn = document.getElementById('btnQuickSetOffice');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 14px; height: 14px;"></i> Calibrating...';
        if (window.lucide) lucide.createIcons();
    }

    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'quick_calibrate_office',
                lat: appAttendanceState.userCoords.lat,
                lng: appAttendanceState.userCoords.lng,
                radius: 250
            })
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message, 'success');
            await refreshAttendanceState();
        } else {
            showAttendanceToast(data.message || 'Quick calibration failed.', 'error');
        }
    } catch (e) {
        showAttendanceToast('Calibration error: ' + e.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="map-pin" style="width: 14px; height: 14px;"></i> Set Current Location as Official Office HQ (0m)';
            if (window.lucide) lucide.createIcons();
        }
    }
}

// -----------------------------------------------------------------------------
// Tab 5: Live Staff Attendance Board & Executive Management
// -----------------------------------------------------------------------------
function getLocalDateString(d = new Date()) {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function setBoardDate(which) {
    const input = document.getElementById('boardTargetDate');
    if (!input) return;
    const now = new Date();
    if (which === 'today') {
        input.value = getLocalDateString(now);
    } else if (which === 'yesterday') {
        now.setDate(now.getDate() - 1);
        input.value = getLocalDateString(now);
    }
    loadDailyBoard();
}

function handleBoardSearch(e) {
    appAttendanceState.searchQuery = (e.target.value || '').toLowerCase().trim();
    renderDailyBoardRows();
}

async function loadDailyBoard() {
    const targetDate = document.getElementById('boardTargetDate')?.value || '<?php echo date('Y-m-d'); ?>';
    const tbody = document.getElementById('dailyBoardTableBody');
    const reloadIcon = document.getElementById('iconReloadBoard');
    if (!tbody) return;

    if (reloadIcon) reloadIcon.classList.add('spin');

    // Only show skeleton/loading if no rows cached yet to prevent visual layout jumps
    if (!appAttendanceState.boardRows || appAttendanceState.boardRows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-8 text-muted"><i data-lucide="loader-2" class="spin" style="width: 22px; height: 22px; margin: 0 auto 8px auto; display: block;"></i> Compiling company staff roster...</td></tr>`;
        if (window.lucide) lucide.createIcons();
    }

    try {
        const res = await fetch(`api/attendance.php?action=get_daily_board&date=${targetDate}`);
        const data = await res.json();

        if (data.success && data.board) {
            appAttendanceState.boardRows = data.board;
            appAttendanceState.boardSettings = data.settings || appAttendanceState.settings;

            // Populate User Dropdown in Manual Punch Modal
            populateManualPunchUserDropdown(data.board);

            // Compute Live KPIs
            computeDailyBoardKPIs(data.board, appAttendanceState.boardSettings);

            // Render Rows based on active filter & search
            renderDailyBoardRows();
        } else {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center py-8 text-muted">${data.message || 'No company employees recorded for selected date.'}</td></tr>`;
        }
    } catch (e) {
        console.error('Board error:', e);
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-8 text-danger"><i data-lucide="alert-triangle" style="width: 20px; height: 20px; margin: 0 auto 6px auto; display: block;"></i> Failed to compile staff board: ${e.message}</td></tr>`;
    } finally {
        if (reloadIcon) reloadIcon.classList.remove('spin');
        if (window.lucide) lucide.createIcons();
    }
}

function escapeJsQuotes(str) {
    if (!str) return '';
    return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

function parseAttendanceDate(dStr) {
    if (!dStr) return null;
    const cleanStr = String(dStr).trim().replace(' ', 'T');
    const d = new Date(cleanStr);
    return isNaN(d.getTime()) ? null : d;
}

function computeDailyBoardKPIs(rows, settings) {
    let total = rows.length;
    let present = 0;
    let working = 0;
    let late = 0;
    let pending = 0;
    let leave = 0;
    let absent = 0;

    rows.forEach(r => {
        const empShift = r.custom_shift_start || settings?.shift_start_time || '10:15:00';
        const empGrace = r.custom_grace_period !== null && r.custom_grace_period !== undefined ? parseInt(r.custom_grace_period) : parseInt(settings?.grace_period_minutes || 15);
        const [empH, empM] = (empShift.includes(':') ? empShift.split(':') : ['10', '15']).map(Number);
        const empShiftMinutes = (isNaN(empH) ? 10 : empH) * 60 + (isNaN(empM) ? 15 : empM) + empGrace;

        if (r.leave_status === 'approved') {
            leave++;
        } else if (r.checkin_time) {
            const isPending = (r.checkin_approval_status === 'pending' || r.checkout_approval_status === 'pending' || r.punch_status === 'pending_approval');
            const isRejected = (r.checkin_approval_status === 'rejected' || r.punch_status === 'rejected');

            if (isPending) {
                pending++;
            } else if (!isRejected) {
                present++;
                if (!r.checkout_time) working++;
            }

            // Late Arrival Check (against employee's specific shift)
            const inDate = parseAttendanceDate(r.checkin_time);
            if (inDate) {
                const inMins = inDate.getHours() * 60 + inDate.getMinutes();
                if (inMins > empShiftMinutes && !isRejected && !isPending) {
                    late++;
                }
            }
        } else {
            absent++;
        }
    });

    const setTxt = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };
    setTxt('kpiStaffTotal', total);
    setTxt('kpiStaffPresent', present);
    setTxt('kpiStaffWorking', working);
    setTxt('kpiStaffLate', late);
    setTxt('kpiStaffPending', pending);
    setTxt('kpiStaffLeave', leave);
    setTxt('kpiStaffAbsent', absent);

    setTxt('cntFilterAll', total);
    setTxt('cntFilterPresent', present);
    setTxt('cntFilterWorking', working);
    setTxt('cntFilterLate', late);
    setTxt('cntFilterPending', pending);
    setTxt('cntFilterLeave', leave);
    setTxt('cntFilterAbsent', absent);
}

function filterDailyBoard(filterType) {
    appAttendanceState.boardFilter = filterType;

    // Update KPI Card active rings
    document.querySelectorAll('.kpi-card').forEach(c => {
        c.style.borderColor = 'var(--border-color)';
        c.style.borderWidth = '1px';
    });
    const activeKpi = document.getElementById(`kpi-card-${filterType}`);
    if (activeKpi) {
        activeKpi.style.borderColor = 'var(--primary)';
        activeKpi.style.borderWidth = '2px';
    }

    // Update Chip Styles
    document.querySelectorAll('.board-filter-chip').forEach(c => {
        c.style.background = 'var(--border-card)';
        c.style.color = 'var(--text-muted)';
        c.style.fontWeight = '500';
        c.style.border = '1px solid var(--border-color)';
    });

    const activeChip = document.getElementById(`chip-filter-${filterType}`);
    if (activeChip) {
        activeChip.style.background = 'var(--primary)';
        activeChip.style.color = '#ffffff';
        activeChip.style.fontWeight = '700';
        activeChip.style.border = '1px solid var(--primary)';
    }

    renderDailyBoardRows();
}

function renderDailyBoardRows() {
    const tbody = document.getElementById('dailyBoardTableBody');
    if (!tbody || !appAttendanceState.boardRows) return;

    const rows = appAttendanceState.boardRows;
    const filter = appAttendanceState.boardFilter || 'all';
    const query = appAttendanceState.searchQuery || '';
    const settings = appAttendanceState.boardSettings || appAttendanceState.settings;

    const filtered = rows.filter(r => {
        // Query search
        if (query) {
            const nameMatch = (r.employee_name || '').toLowerCase().includes(query);
            const roleMatch = (r.role || '').toLowerCase().includes(query);
            const emailMatch = (r.email || '').toLowerCase().includes(query);
            if (!nameMatch && !roleMatch && !emailMatch) return false;
        }

        // Category filter
        if (filter === 'all') return true;
        if (filter === 'present') return Boolean(r.checkin_time && r.checkin_approval_status !== 'pending' && r.punch_status !== 'pending_approval' && r.punch_status !== 'rejected');
        if (filter === 'working') return Boolean(r.checkin_time && !r.checkout_time && r.checkin_approval_status !== 'pending' && r.punch_status !== 'pending_approval' && r.punch_status !== 'rejected');
        if (filter === 'late') {
            if (!r.checkin_time || r.checkin_approval_status === 'pending' || r.punch_status === 'pending_approval' || r.punch_status === 'rejected') return false;
            const empShift = r.custom_shift_start || settings?.shift_start_time || '10:15:00';
            const empGrace = r.custom_grace_period !== null && r.custom_grace_period !== undefined ? parseInt(r.custom_grace_period) : parseInt(settings?.grace_period_minutes || 15);
            const [eH, eM] = (empShift.includes(':') ? empShift.split(':') : ['10', '15']).map(Number);
            const inDate = parseAttendanceDate(r.checkin_time);
            if (!inDate) return false;
            return (inDate.getHours() * 60 + inDate.getMinutes()) > ((isNaN(eH)?10:eH) * 60 + (isNaN(eM)?15:eM) + empGrace);
        }
        if (filter === 'pending') return (r.checkin_approval_status === 'pending' || r.checkout_approval_status === 'pending' || r.punch_status === 'pending_approval');
        if (filter === 'leave') return r.leave_status === 'approved';
        if (filter === 'absent') return (!r.checkin_time && r.leave_status !== 'approved');
        return true;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-8 text-muted">No company employees match '${query ? query + ' / ' : ''}${filter}' criteria.</td></tr>`;
        return;
    }

    let html = '';
    const avatarColors = ['#2563eb', '#0891b2', '#059669', '#7c3aed', '#d97706', '#dc2626'];

    filtered.forEach(row => {
        try {
            const inDate = parseAttendanceDate(row.checkin_time);
            const outDate = parseAttendanceDate(row.checkout_time);
            const inTimeStr = inDate ? inDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '--';
            const outTimeStr = outDate ? outDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true }) : '--';
            const hoursStr = row.total_work_minutes ? `${Math.floor(row.total_work_minutes / 60)}h ${row.total_work_minutes % 60}m` : '--';

            const initials = row.employee_name ? row.employee_name.trim().split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() : 'EM';
            const bgCol = avatarColors[(row.user_id || 1) % avatarColors.length];

            // Specific Staff Shift Determination
            const empShiftStart = (row.custom_shift_start || settings?.shift_start_time || '10:15:00').substring(0, 5);
            const empShiftEnd = (row.custom_shift_end || settings?.shift_end_time || '20:00:00').substring(0, 5);
            const empGrace = row.custom_grace_period !== null && row.custom_grace_period !== undefined ? parseInt(row.custom_grace_period) : parseInt(settings?.grace_period_minutes || 15);
            const isCustomShift = Boolean(row.custom_shift_start);

            const [empSH, empSM] = (empShiftStart.includes(':') ? empShiftStart.split(':') : ['10', '15']).map(Number);
            const empShiftStartMins = (isNaN(empSH) ? 10 : empSH) * 60 + (isNaN(empSM) ? 15 : empSM) + empGrace;

            // Late Badge against employee's own schedule
            let isLate = false;
            let lateMins = 0;
            if (inDate) {
                const inMins = inDate.getHours() * 60 + inDate.getMinutes();
                if (inMins > empShiftStartMins) {
                    isLate = true;
                    lateMins = inMins - ((isNaN(empSH) ? 10 : empSH) * 60 + (isNaN(empSM) ? 15 : empSM));
                }
            }

            // Status Badge
            let statusBadge = `<span class="badge" style="background:rgba(100,116,139,0.08); color:var(--text-muted); border: 1px solid var(--border-color); font-weight: 600;">Not Marked</span>`;
            if (row.leave_status === 'approved') {
                statusBadge = `<span class="badge" style="background:rgba(139,92,246,0.12); color:#7c3aed; font-weight:700; border: 1px solid rgba(139,92,246,0.25);">On Leave (${row.leave_type})</span>`;
            } else if (row.checkin_time) {
                if (row.checkin_approval_status === 'pending' || row.checkout_approval_status === 'pending') {
                    statusBadge = `<span class="badge" style="background:rgba(245,158,11,0.12); color:#b45309; font-weight:700; border: 1px solid rgba(245,158,11,0.25);">Pending Review</span>`;
                } else if (row.checkout_time) {
                    statusBadge = `<span class="badge" style="background:rgba(16,185,129,0.12); color:#10b981; font-weight:700; border: 1px solid rgba(16,185,129,0.25);">Shift Completed</span>`;
                } else {
                    statusBadge = `<span class="badge" style="background:rgba(6,182,212,0.12); color:#0891b2; font-weight:700; border: 1px solid rgba(6,182,212,0.25);">Working Now</span>`;
                }
            }

            // Location text
            let locText = '<span class="text-muted">--</span>';
            if (row.checkin_time) {
                if (row.checkin_is_inside == 1) {
                    locText = '<span class="badge" style="background:rgba(16,185,129,0.1); color:#10b981; font-weight:600; border:1px solid rgba(16,185,129,0.25);"><i data-lucide="shield-check" style="width:12px; height:12px; display:inline-block; vertical-align:-2px; margin-right:3px;"></i>Office HQ (0m)</span>';
                } else {
                    const dist = Math.round(row.checkin_distance_meters || 0);
                    const distFmt = dist > 1000 ? (dist/1000).toFixed(2) + ' km' : dist + 'm';
                    locText = `<span class="badge" style="background:rgba(245,158,11,0.1); color:#b45309; font-weight:600; border:1px solid rgba(245,158,11,0.25);"><i data-lucide="map-pin" style="width:12px; height:12px; display:inline-block; vertical-align:-2px; margin-right:3px;"></i>Outside (${distFmt})</span>`;
                }
            }

            // Actions Column
            let actionBtns = '';
            if (row.checkin_approval_status === 'pending') {
                actionBtns += `<button type="button" onclick="adminReviewPunch(${row.attendance_id}, 'checkin', 'approved')" class="btn btn-xs font-semibold" style="background:#10b981; color:white; padding:4px 9px;">Approve In</button>`;
                actionBtns += `<button type="button" onclick="adminReviewPunch(${row.attendance_id}, 'checkin', 'rejected')" class="btn btn-xs btn-outline text-danger font-semibold" style="padding:4px 9px;">Reject</button>`;
            }
            if (row.checkout_approval_status === 'pending') {
                actionBtns += `<button type="button" onclick="adminReviewPunch(${row.attendance_id}, 'checkout', 'approved')" class="btn btn-xs font-semibold" style="background:#10b981; color:white; padding:4px 9px;">Approve Out</button>`;
                actionBtns += `<button type="button" onclick="adminReviewPunch(${row.attendance_id}, 'checkout', 'rejected')" class="btn btn-xs btn-outline text-danger font-semibold" style="padding:4px 9px;">Reject</button>`;
            }

            if (!row.checkin_time) {
                actionBtns += `<button type="button" onclick="openAdminManualPunchModal(${row.user_id}, '${escapeJsQuotes(row.employee_name)}', 'checkin')" class="btn btn-outline btn-xs font-semibold" style="padding:4px 10px; font-size:0.75rem;">Admin Check-in</button>`;
            } else if (!row.checkout_time) {
                actionBtns += `<button type="button" onclick="openAdminManualPunchModal(${row.user_id}, '${escapeJsQuotes(row.employee_name)}', 'checkout')" class="btn btn-outline btn-xs font-semibold" style="padding:4px 10px; font-size:0.75rem; color:#ef4444;">Admin Check-out</button>`;
            }

            if (row.checkin_selfie) {
                actionBtns += `<button type="button" onclick="viewSelfieModal('${row.checkin_selfie}', 'Check-in Selfie - ${escapeJsQuotes(row.employee_name)}')" class="btn btn-outline btn-xs" style="padding:4px 7px;" title="View Selfie Snapshot"><i data-lucide="camera" style="width:13px; height:13px;"></i></button>`;
            }

            html += `
            <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.12s ease;">
                <td style="padding: 14px 16px;">
                    <div class="flex align-center gap-3">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: ${bgCol}; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700; flex-shrink: 0; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                            ${initials}
                        </div>
                        <div>
                            <div style="font-weight: 700; color: var(--text-main); line-height: 1.3;">${row.employee_name}</div>
                            <div class="text-xs text-muted" style="margin-top: 1px;">${row.email || ''}</div>
                        </div>
                    </div>
                </td>
                <td style="padding: 14px 16px;">
                    <div class="flex align-center gap-1.5 wrap mb-1">
                        <span class="badge" style="background: rgba(100,116,139,0.08); color: var(--text-main); font-size: 0.72rem; font-weight: 600; border: 1px solid var(--border-color);">${row.role}</span>
                        ${isCustomShift ? `<span class="badge" style="background: rgba(37,99,235,0.08); color: var(--primary); font-size: 0.68rem; font-weight: 700; border: 1px solid rgba(37,99,235,0.2);">Custom</span>` : ''}
                    </div>
                    <div class="flex align-center gap-1.5 wrap text-xs text-muted" style="margin-top: 3px;">
                        <span>Shift: <strong>${empShiftStart} - ${empShiftEnd}</strong></span>
                        <button type="button" onclick="openShiftEditModal(${row.user_id}, '${escapeJsQuotes(row.employee_name)}', '${empShiftStart}', '${empShiftEnd}', ${empGrace}, ${isCustomShift})" class="btn btn-outline btn-xs flex align-center gap-1" style="padding: 2px 7px; font-size: 0.68rem; height: 22px; border-radius: 4px;" title="Set Custom Shift Timing">
                            <i data-lucide="clock" style="width: 10px; height: 10px;"></i>
                            <span>${isCustomShift ? 'Edit Shift' : 'Set Shift'}</span>
                        </button>
                    </div>
                </td>
                <td style="padding: 14px 16px;">
                    <div style="font-weight: 700; color: var(--text-main);">${inTimeStr}</div>
                    ${row.checkin_time ? (isLate ? `<span class="badge" style="background:rgba(245,158,11,0.12); color:#b45309; font-size:0.65rem; margin-top:3px; font-weight:700; border:1px solid rgba(245,158,11,0.25); display:inline-block;">Late +${lateMins}m</span>` : `<span class="badge" style="background:rgba(16,185,129,0.1); color:#10b981; font-size:0.65rem; margin-top:3px; font-weight:600; border:1px solid rgba(16,185,129,0.25); display:inline-block;">On Time</span>`) : ''}
                </td>
                <td style="padding: 14px 16px; font-weight: 700; color: var(--text-main);">${outTimeStr}</td>
                <td style="padding: 14px 16px;">
                    <strong style="color: var(--primary); font-size: 0.88rem;">${hoursStr}</strong>
                </td>
                <td style="padding: 14px 16px;">${statusBadge}</td>
                <td style="padding: 14px 16px; font-size: 0.8rem;">${locText}</td>
                <td style="padding: 14px 16px; text-align: right;">
                    <div class="flex align-center justify-end wrap gap-1.5">
                        ${actionBtns}
                    </div>
                </td>
            </tr>`;
        } catch (rowErr) {
            console.error('Row render error for user ' + row.user_id, rowErr);
        }
    });

    tbody.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function populateManualPunchUserDropdown(boardRows) {
    const sel = document.getElementById('manualPunchUserId');
    if (!sel) return;
    sel.innerHTML = '<option value="">-- Choose Employee --</option>';

    boardRows.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r.user_id;
        opt.textContent = `${r.employee_name} (${r.role})`;
        sel.appendChild(opt);
    });
}

// -----------------------------------------------------------------------------
// DEDICATED STAFF SHIFT TIMINGS MANAGER
// -----------------------------------------------------------------------------
let appStaffShiftsData = [];

async function loadStaffShiftsTable() {
    const tbody = document.getElementById('staffShiftsTableBody');
    const reloadIcon = document.getElementById('iconReloadShifts');
    if (!tbody) return;

    if (reloadIcon) reloadIcon.classList.add('spin');
    tbody.innerHTML = `<tr><td colspan="7" class="text-center py-8 text-muted"><i data-lucide="loader-2" class="spin" style="width: 22px; height: 22px; margin: 0 auto 8px auto; display: block;"></i> Loading staff shifts roster...</td></tr>`;
    if (window.lucide) lucide.createIcons();

    try {
        const res = await fetch('api/attendance.php?action=get_staff_shifts');
        const data = await res.json();

        if (data.success && Array.isArray(data.staff)) {
            appStaffShiftsData = data.staff;
            appAttendanceState.shiftDefaults = data.default_settings || { shift_start_time: '10:15:00', shift_end_time: '20:00:00', grace_period_minutes: 15 };
            renderStaffShiftsRows();
        } else {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center py-8 text-danger"><i data-lucide="alert-triangle" style="width: 18px; height: 18px; margin: 0 auto 6px auto; display: block;"></i> Failed to load staff list: ${data.message || 'Unknown error'}</td></tr>`;
        }
    } catch (e) {
        console.error('Staff shifts error:', e);
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-8 text-danger"><i data-lucide="alert-triangle" style="width: 18px; height: 18px; margin: 0 auto 6px auto; display: block;"></i> Network error loading staff: ${e.message}</td></tr>`;
    } finally {
        if (reloadIcon) reloadIcon.classList.remove('spin');
        if (window.lucide) lucide.createIcons();
    }
}

function filterStaffShiftsTable() {
    renderStaffShiftsRows();
}

function renderStaffShiftsRows() {
    const tbody = document.getElementById('staffShiftsTableBody');
    if (!tbody || !appStaffShiftsData) return;

    const query = (document.getElementById('staffShiftSearchInput')?.value || '').toLowerCase().trim();
    const defaults = appAttendanceState.shiftDefaults || { shift_start_time: '10:15:00', shift_end_time: '20:00:00', grace_period_minutes: 15 };

    const filtered = appStaffShiftsData.filter(s => {
        if (!query) return true;
        return (s.employee_name || '').toLowerCase().includes(query) ||
               (s.role || '').toLowerCase().includes(query) ||
               (s.email || '').toLowerCase().includes(query);
    });

    const cntEl = document.getElementById('staffShiftFilteredCount');
    if (cntEl) cntEl.innerText = filtered.length;

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-8 text-muted">No employees found matching '${query}'.</td></tr>`;
        return;
    }

    const avatarColors = ['#2563eb', '#0891b2', '#059669', '#7c3aed', '#d97706', '#dc2626'];
    let html = '';

    filtered.forEach(emp => {
        const initials = emp.employee_name ? emp.employee_name.trim().split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() : 'EM';
        const bgCol = avatarColors[(emp.user_id || 1) % avatarColors.length];

        const isCustom = Boolean(emp.custom_shift_start);
        const startTime = (emp.custom_shift_start || defaults.shift_start_time || '10:15:00').substring(0, 5);
        const endTime = (emp.custom_shift_end || defaults.shift_end_time || '20:00:00').substring(0, 5);
        const graceMins = emp.custom_grace_period !== null && emp.custom_grace_period !== undefined ? parseInt(emp.custom_grace_period) : parseInt(defaults.grace_period_minutes || 15);

        const shiftTypeBadge = isCustom
            ? `<span class="badge" style="background: rgba(37,99,235,0.1); color: var(--primary); font-weight: 700; border: 1px solid rgba(37,99,235,0.25);">Custom Schedule</span>`
            : `<span class="badge" style="background: rgba(100,116,139,0.08); color: var(--text-muted); font-weight: 600; border: 1px solid var(--border-color);">Company Default</span>`;

        html += `
        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.12s ease;">
            <td style="padding: 14px 16px;">
                <div class="flex align-center gap-3">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: ${bgCol}; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700; flex-shrink: 0; box-shadow: 0 2px 5px rgba(0,0,0,0.08);">
                        ${initials}
                    </div>
                    <div>
                        <div style="font-weight: 700; color: var(--text-main); line-height: 1.3;">${emp.employee_name}</div>
                        <div class="text-xs text-muted" style="margin-top: 1px;">${emp.email || ''}</div>
                    </div>
                </div>
            </td>
            <td style="padding: 14px 16px;">
                <span class="badge" style="background: rgba(100,116,139,0.08); color: var(--text-main); font-size: 0.72rem; font-weight: 600; border: 1px solid var(--border-color);">${emp.role}</span>
            </td>
            <td style="padding: 14px 16px;">${shiftTypeBadge}</td>
            <td style="padding: 14px 16px; font-weight: 700; color: var(--text-main); font-size: 0.9rem;">${startTime}</td>
            <td style="padding: 14px 16px; font-weight: 700; color: var(--text-main); font-size: 0.9rem;">${endTime}</td>
            <td style="padding: 14px 16px; color: var(--text-muted); font-size: 0.85rem;">${graceMins} minutes</td>
            <td style="padding: 14px 16px; text-align: right;">
                <div class="flex align-center justify-end gap-2">
                    <button type="button" onclick="openShiftEditModal(${emp.user_id}, '${escapeJsQuotes(emp.employee_name)}', '${startTime}', '${endTime}', ${graceMins}, ${isCustom})" class="btn btn-outline btn-xs flex align-center gap-1 font-semibold" style="padding: 5px 12px; height: 30px; border-radius: 5px;">
                        <i data-lucide="edit-3" style="width: 12px; height: 12px;"></i>
                        <span>${isCustom ? 'Change Shift' : 'Set Custom Shift'}</span>
                    </button>
                    ${isCustom ? `<button type="button" onclick="resetEmployeeShiftToDefault(${emp.user_id}, '${escapeJsQuotes(emp.employee_name)}')" class="btn btn-outline btn-xs text-danger font-semibold" style="padding: 5px 10px; height: 30px; border-color: rgba(239,68,68,0.3);" title="Reset to Company Default Shift"><i data-lucide="rotate-ccw" style="width: 12px; height: 12px;"></i></button>` : ''}
                </div>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

// -----------------------------------------------------------------------------
// Individual Employee Shift Customization Handlers
// -----------------------------------------------------------------------------
function openShiftEditModal(userId, empName, startTime, endTime, graceMins, isCustom) {
    const modal = document.getElementById('editEmployeeShiftModal');
    if (!modal) return;

    const uIdEl = document.getElementById('editShiftUserId');
    const uNameEl = document.getElementById('editShiftEmployeeName');
    const sStartEl = document.getElementById('editShiftStart');
    const sEndEl = document.getElementById('editShiftEnd');
    const sGraceEl = document.getElementById('editShiftGrace');
    const sNameEl = document.getElementById('editShiftName');
    const badge = document.getElementById('editShiftTypeBadge');
    const resetBtn = document.getElementById('btnResetToDefaultShift');

    if (uIdEl) uIdEl.value = userId;
    if (uNameEl) uNameEl.innerText = empName;
    const startStr = String(startTime || '10:15:00').substring(0, 5);
    const endStr = String(endTime || '20:00:00').substring(0, 5);
    if (sStartEl) sStartEl.value = startStr;
    if (sEndEl) sEndEl.value = endStr;
    if (sGraceEl) sGraceEl.value = graceMins || 15;
    if (sNameEl) sNameEl.value = isCustom ? 'Custom Shift' : '';

    if (badge) {
        if (isCustom) {
            badge.innerText = 'Custom Shift (Active)';
            badge.style.background = 'rgba(37,99,235,0.12)';
            badge.style.color = 'var(--primary)';
            badge.style.border = '1px solid rgba(37,99,235,0.25)';
            if (resetBtn) resetBtn.style.display = 'inline-block';
        } else {
            badge.innerText = 'Company Default';
            badge.style.background = 'rgba(100,116,139,0.1)';
            badge.style.color = 'var(--text-muted)';
            badge.style.border = '1px solid var(--border-color)';
            if (resetBtn) resetBtn.style.display = 'none';
        }
    }

    modal.style.display = 'flex';
    modal.classList.add('open');
    modal.classList.add('active');
    if (window.lucide) lucide.createIcons();
}

function closeShiftEditModal() {
    const modal = document.getElementById('editEmployeeShiftModal');
    if (modal) {
        modal.classList.remove('open');
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function applyShiftPreset(start, end, grace, name) {
    const sStart = document.getElementById('editShiftStart');
    const sEnd = document.getElementById('editShiftEnd');
    const sGrace = document.getElementById('editShiftGrace');
    const sName = document.getElementById('editShiftName');

    if (sStart) sStart.value = start;
    if (sEnd) sEnd.value = end;
    if (sGrace) sGrace.value = grace;
    if (sName) sName.value = name;
}

async function submitEmployeeCustomShift(e) {
    if (e && e.preventDefault) e.preventDefault();
    const userId = document.getElementById('editShiftUserId')?.value;
    const start = document.getElementById('editShiftStart')?.value;
    const end = document.getElementById('editShiftEnd')?.value;
    const grace = document.getElementById('editShiftGrace')?.value || 15;
    const name = document.getElementById('editShiftName')?.value || 'Custom Shift';
    const btn = document.getElementById('btnSaveShiftModal');

    if (!userId || !start || !end) {
        showAttendanceToast('Please specify valid start and end times.', 'warning');
        return;
    }

    if (btn) btn.disabled = true;

    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_user_shift',
                target_user_id: userId,
                shift_start_time: start,
                shift_end_time: end,
                grace_period_minutes: grace,
                shift_name: name
            })
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message || 'Custom shift saved successfully!', 'success');
            closeShiftEditModal();
            loadDailyBoard();
            if (typeof loadStaffShiftsTable === 'function') loadStaffShiftsTable();
        } else {
            showAttendanceToast(data.message || 'Failed to save shift.', 'error');
        }
    } catch (err) {
        showAttendanceToast('Shift error: ' + err.message, 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function resetEmployeeShiftToDefault(targetUserId = null, empName = '') {
    const userId = targetUserId || document.getElementById('editShiftUserId')?.value;
    if (!userId) return;

    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_user_shift',
                target_user_id: userId,
                reset_to_default: true
            })
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message || 'Shift reset to company default.', 'success');
            closeShiftEditModal();
            loadDailyBoard();
            if (typeof loadStaffShiftsTable === 'function') loadStaffShiftsTable();
        } else {
            showAttendanceToast(data.message || 'Reset failed.', 'error');
        }
    } catch(err) {
        showAttendanceToast('Reset error: ' + err.message, 'error');
    }
}

// -----------------------------------------------------------------------------
// Admin Manual Punch Modal Handlers
// -----------------------------------------------------------------------------
function openAdminManualPunchModal(userId = null, empName = '', punchType = 'checkin') {
    const modal = document.getElementById('adminManualPunchModal');
    if (!modal) return;

    if (userId) {
        const sel = document.getElementById('manualPunchUserId');
        if (sel) sel.value = userId;
    }

    const typeEl = document.getElementById('manualPunchType');
    if (typeEl) typeEl.value = punchType;

    const dateEl = document.getElementById('manualPunchDate');
    const boardDate = document.getElementById('boardTargetDate')?.value || '<?php echo date('Y-m-d'); ?>';
    if (dateEl) dateEl.value = boardDate;

    const timeEl = document.getElementById('manualPunchTime');
    if (timeEl) {
        const now = new Date();
        timeEl.value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    }

    modal.style.display = 'flex';
    if (window.lucide) lucide.createIcons();
}

function closeAdminManualPunchModal() {
    const modal = document.getElementById('adminManualPunchModal');
    if (modal) modal.style.display = 'none';
}

async function submitAdminManualPunch(e) {
    e.preventDefault();

    const userId = document.getElementById('manualPunchUserId').value;
    const punchType = document.getElementById('manualPunchType').value;
    const punchDate = document.getElementById('manualPunchDate').value;
    const punchTime = document.getElementById('manualPunchTime').value;
    const notes = document.getElementById('manualPunchNotes').value.trim();

    if (!userId || !punchDate || !punchTime) {
        showAttendanceToast('Please fill all required punch fields.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSubmitManualPunch');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 14px; height: 14px;"></i> Saving Punch...';
        if (window.lucide) lucide.createIcons();
    }

    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'admin_manual_punch',
                target_user_id: userId,
                punch_type: punchType,
                target_date: punchDate,
                punch_time: punchTime,
                notes: notes
            })
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message, 'success');
            closeAdminManualPunchModal();
            loadDailyBoard();
            refreshAttendanceState();
        } else {
            showAttendanceToast(data.message || 'Manual punch failed.', 'error');
        }
    } catch (err) {
        showAttendanceToast('Network error: ' + err.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="check" style="width: 14px; height: 14px;"></i> Confirm Manual Punch';
            if (window.lucide) lucide.createIcons();
        }
    }
}

// -----------------------------------------------------------------------------
// Tab 6: Admin Calibration & Geofence Settings
// -----------------------------------------------------------------------------
async function calibrateCurrentLocationAsOffice() {
    const btn = document.getElementById('btnCalibrateGps');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 16px; height: 16px;"></i> Acquiring GPS...';
        if (window.lucide) lucide.createIcons();
    }

    try {
        const coords = await getDeviceLocationPromise();
        document.getElementById('settingOfficeLat').value = coords.lat.toFixed(8);
        document.getElementById('settingOfficeLng').value = coords.lng.toFixed(8);

        showAttendanceToast(`Coordinates captured: Lat ${coords.lat.toFixed(6)}, Lng ${coords.lng.toFixed(6)}. Click Save Calibration below.`, 'success');
    } catch (err) {
        // Fallback to IP Network
        calibrateFromIpNetwork();
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="crosshair" style="width: 14px; height: 14px;"></i> Calibrate: Use My Current Device GPS';
            if (window.lucide) lucide.createIcons();
        }
    }
}

async function calibrateFromIpNetwork() {
    try {
        const res = await fetch('api/attendance.php?action=get_ip_location');
        const data = await res.json();
        if (data.success && data.lat && data.lng) {
            document.getElementById('settingOfficeLat').value = data.lat.toFixed(8);
            document.getElementById('settingOfficeLng').value = data.lng.toFixed(8);
            showAttendanceToast(`Office coordinates auto-detected from IP (${data.city || 'Office Network'}). Click Save Calibration below.`, 'info');
        } else {
            showAttendanceToast('Could not auto-detect from IP. Please paste Google Maps coordinates into Latitude & Longitude.', 'warning');
        }
    } catch (e) {
        showAttendanceToast('Network lookup error. Please enter Latitude & Longitude manually.', 'error');
    }
}

async function saveAttendanceSettings(e) {
    e.preventDefault();
    const lat = document.getElementById('settingOfficeLat').value;
    const lng = document.getElementById('settingOfficeLng').value;

    if (!lat || !lng) {
        showAttendanceToast('Please enter or calibrate Latitude and Longitude first.', 'warning');
        return;
    }

    const btn = document.getElementById('btnSaveSettings');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="spin" style="width: 16px; height: 16px;"></i> Saving Calibration...';
        if (window.lucide) lucide.createIcons();
    }

    const payload = {
        action: 'save_settings',
        office_name: document.getElementById('settingOfficeName').value.trim(),
        office_lat: lat,
        office_lng: lng,
        geofence_radius_meters: document.getElementById('settingRadius').value,
        require_gps: document.getElementById('settingRequireGps').value,
        require_selfie: document.getElementById('settingRequireSelfie') ? document.getElementById('settingRequireSelfie').value : 1,
        shift_start_time: document.getElementById('settingShiftStart').value + ':00',
        shift_end_time: document.getElementById('settingShiftEnd').value + ':00',
        grace_period_minutes: document.getElementById('settingGracePeriod').value
    };

    try {
        const res = await fetch('api/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showAttendanceToast(data.message, 'success');
            refreshAttendanceState();
            switchAttendanceTab('punch');
        } else {
            showAttendanceToast(data.message || 'Failed to update settings.', 'error');
        }
    } catch (err) {
        showAttendanceToast('Network error: ' + err.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="save" style="width: 16px; height: 16px;"></i> Save Calibration Settings & Activate System';
            if (window.lucide) lucide.createIcons();
        }
    }
}

// -----------------------------------------------------------------------------
// Modals
// -----------------------------------------------------------------------------
function viewSelfieModal(imgPath, title) {
    const modal = document.getElementById('selfieModal');
    const img = document.getElementById('selfieModalImg');
    const titleEl = document.getElementById('selfieModalTitle');
    const details = document.getElementById('selfieModalDetails');

    if (!modal || !img) return;

    img.src = imgPath;
    if (titleEl) titleEl.innerText = title || 'Biometric Face Snapshot';
    if (details) details.innerText = `Storage path: ${imgPath}`;
    modal.style.display = 'flex';
}

function closeSelfieModal() {
    const modal = document.getElementById('selfieModal');
    if (modal) modal.style.display = 'none';
}
</script>
