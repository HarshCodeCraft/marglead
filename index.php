<?php
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

// Enforce authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

// Export Leads CSV File Receiver
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if ($db_connected && $pdo) {
        try {
            $stmt = $pdo->query("SELECT id, name, contact_person, company, phone, email, address, source, priority, status, assigned_to, budget, enq_for, remarks, tags, created_at FROM leads ORDER BY created_at DESC");
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=Leads_Directory_Export_' . date('Y-m-d_H-i') . '.csv');
            
            $output = fopen('php://output', 'w');
            
            // Output BOM for Excel UTF-8 compatibility
            fputs($output, "\xEF\xBB\xBF");
            
            // CSV Header Row
            fputcsv($output, ['Lead ID', 'Customer Name', 'Contact Person', 'Company / Group', 'Phone', 'Email', 'Address', 'Source', 'Priority', 'Status', 'Assigned Exec', 'Budget', 'Enq For', 'Remarks', 'Tags', 'Created Date']);
            
            foreach ($leads as $l) {
                fputcsv($output, [
                    $l['id'],
                    $l['name'],
                    $l['contact_person'] ?? '',
                    $l['company'],
                    $l['phone'],
                    $l['email'] ?? '',
                    $l['address'] ?? '',
                    $l['source'] ?? '',
                    $l['priority'] ?? '',
                    $l['status'] ?? '',
                    $l['assigned_to'] ?? '',
                    $l['budget'] ?? 0,
                    $l['enq_for'] ?? '',
                    $l['remarks'] ?? '',
                    $l['tags'] ?? '',
                    $l['created_at']
                ]);
            }
            
            fclose($output);
            exit;
        } catch (PDOException $e) {
            die("Export error: " . $e->getMessage());
        }
    } else {
        die("Database offline. Cannot export.");
    }
}

// Batch Actions AJAX Receiver
if (isset($_POST['action']) && $_POST['action'] === 'batch_update') {
    header('Content-Type: application/json');
    $ids = isset($_POST['lead_ids']) ? json_decode($_POST['lead_ids'], true) : [];
    $batch_action = $_POST['batch_action'] ?? '';
    $val = $_POST['target_value'] ?? '';
    $user_role = $_SESSION['user_role'] ?? 'Admin';

    if (empty($ids) || !is_array($ids)) {
        echo json_encode(['success' => false, 'message' => 'No valid leads selected for batch action.']);
        exit;
    }

    if ($batch_action === 'drop') {
        $allowed_roles = ['Super Admin', 'Admin', 'Regional Manager', 'Team Leader'];
        if (!in_array($user_role, $allowed_roles)) {
            echo json_encode(['success' => false, 'message' => 'Permission Denied: Only Administrators or Managers can authorize dropping leads.']);
            exit;
        }
    }

    if ($db_connected && $pdo) {
        try {
            $in_clause = implode(',', array_fill(0, count($ids), '?'));
            
            if ($batch_action === 'assign') {
                $stmt = $pdo->prepare("UPDATE leads SET assigned_to = ? WHERE id IN ($in_clause)");
                $params = array_merge([$val], $ids);
                $stmt->execute($params);
                $msg = "Successfully assigned " . count($ids) . " lead(s) to " . htmlspecialchars($val) . ".";
            } elseif ($batch_action === 'status') {
                $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id IN ($in_clause)");
                $params = array_merge([$val], $ids);
                $stmt->execute($params);
                $msg = "Successfully updated status of " . count($ids) . " lead(s).";
            } elseif ($batch_action === 'priority') {
                $stmt = $pdo->prepare("UPDATE leads SET priority = ? WHERE id IN ($in_clause)");
                $params = array_merge([$val], $ids);
                $stmt->execute($params);
                $msg = "Successfully updated priority of " . count($ids) . " lead(s) to " . ucfirst($val) . ".";
            } elseif ($batch_action === 'drop') {
                $stmt = $pdo->prepare("UPDATE leads SET status = 'dropped' WHERE id IN ($in_clause)");
                $stmt->execute($ids);
                $msg = "Successfully moved " . count($ids) . " lead(s) to DROPPED status. No leads were deleted.";
            } elseif ($batch_action === 'restore' || $batch_action === 'reactivate') {
                $target_stage = !empty($val) ? $val : 'new';
                $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id IN ($in_clause)");
                $params = array_merge([$target_stage], $ids);
                $stmt->execute($params);
                $msg = "Successfully re-activated " . count($ids) . " lead(s) back to Active Lead status.";
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid batch action specified.']);
                exit;
            }

            echo json_encode(['success' => true, 'message' => $msg]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database offline.']);
        exit;
    }
}

// Global Search AJAX receiver
if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($db_connected && $pdo && !empty($q)) {
        try {
            $stmt = $pdo->prepare("SELECT id, name, company, phone, source, priority FROM leads WHERE name LIKE ? OR company LIKE ? OR phone LIKE ? OR id LIKE ? ORDER BY created_at DESC LIMIT 10");
            $like = '%' . $q . '%';
            $stmt->execute([$like, $like, $like, $like]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }
}

// Quick Follow-up Data Fetch AJAX receiver
if (isset($_GET['action']) && $_GET['action'] === 'get_lead_json') {
    header('Content-Type: application/json');
    if ($db_connected && $pdo && isset($_GET['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
            $stmt->execute([$_GET['id']]);
            $l = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($l) {
                // Fetch followup history for this lead
                $stmtFup = $pdo->prepare("SELECT * FROM followups WHERE lead_id = ? ORDER BY scheduled_at DESC LIMIT 15");
                $stmtFup->execute([$_GET['id']]);
                $fupHistory = $stmtFup->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'lead' => [
                        'id' => $l['id'],
                        'name' => $l['name'],
                        'company' => $l['company'],
                        'city' => $l['city'] ?? '',
                        'phone' => $l['phone'],
                        'email' => $l['email'] ?? '',
                        'source' => $l['source'] ?? 'Website',
                        'priority' => $l['priority'] ?? 'warm',
                        'status' => $l['status'] ?? 'new',
                        'assigned' => $l['assigned_to'] ?? '',
                        'address' => $l['address'] ?? '',
                        'tags' => $l['tags'] ?? '',
                        'enq_for' => $l['enq_for'] ?? '',
                        'contact_person' => $l['contact_person'] ?? '',
                        'remarks' => $l['remarks'] ?? ''
                    ],
                    'followup_history' => $fupHistory
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Lead not found.']);
            }
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
}

// Quick Follow-up Data Save AJAX receiver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_followup_save') {
    header('Content-Type: application/json');
    if ($db_connected && $pdo) {
        try {
            $lead_id = $_POST['lead_id'];
            $company = $_POST['company'] ?? '';
            $status = $_POST['status'] ?? '';
            $assigned_to = $_POST['assigned_to'] ?? '';
            $tags = $_POST['tags'] ?? '';
            $address = $_POST['address'] ?? '';
            $source = $_POST['source'] ?? '';
            $enq_for = $_POST['enq_for'] ?? '';
            $contact_person = $_POST['contact_person'] ?? '';
            $remarks = $_POST['remarks'] ?? '';
            
            // 1. Update leads table
            if (!empty($status)) {
                $upd = $pdo->prepare("UPDATE leads SET company = ?, status = ?, assigned_to = ?, tags = ?, address = ?, source = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
                $upd->execute([$company, $status, $assigned_to, $tags, $address, $source, $enq_for, $contact_person, $remarks, $lead_id]);
            } else {
                $upd = $pdo->prepare("UPDATE leads SET company = ?, assigned_to = ?, tags = ?, address = ?, source = ?, enq_for = ?, contact_person = ?, remarks = ? WHERE id = ?");
                $upd->execute([$company, $assigned_to, $tags, $address, $source, $enq_for, $contact_person, $remarks, $lead_id]);
            }
            
            // 2. Insert or update follow-up reminder
            $action_type = $_POST['action_type'] ?? ($_POST['fup_type'] ?? 'Call');
            $fup_assigned_to = !empty($_POST['fup_assigned_to']) ? $_POST['fup_assigned_to'] : ($assigned_to ?: ($_SESSION['user_name'] ?? 'Unassigned'));
            $fup_notes = $_POST['fup_notes'] ?? ($_POST['fup_comment'] ?? '');
            
            $scheduled_at = $_POST['scheduled_at'] ?? '';
            if (empty($scheduled_at)) {
                $reminder_date = $_POST['reminder_date'] ?? '';
                $reminder_time = $_POST['reminder_time'] ?? '';
                if (!empty($reminder_date)) {
                    $scheduled_at = $reminder_date . ' ' . (!empty($reminder_time) ? $reminder_time : '12:00:00');
                } else {
                    $scheduled_at = date('Y-m-d H:i:s');
                }
            }
            $scheduled_at = str_replace('T', ' ', $scheduled_at);
            if (strlen($scheduled_at) === 16) {
                $scheduled_at .= ':00';
            }

            $send_email = isset($_POST['send_email']) ? 1 : 0;
            $send_sms = isset($_POST['send_sms']) ? 1 : 0;

            if (!empty($action_type) || !empty($scheduled_at) || !empty($fup_notes)) {
                // Check if a pending follow-up already exists for this lead
                $chkFup = $pdo->prepare("SELECT id FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $chkFup->execute([$lead_id]);
                $existFup = $chkFup->fetchColumn();
                
                if ($existFup) {
                    $updFup = $pdo->prepare("UPDATE followups SET action_type = ?, scheduled_at = ?, remarks = ?, assigned_to = ?, send_email = ?, send_sms = ? WHERE id = ?");
                    $updFup->execute([$action_type, $scheduled_at, $fup_notes ?: 'Follow-up reminder updated', $fup_assigned_to, $send_email, $send_sms, $existFup]);
                } else {
                    $insFup = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, status, assigned_to, send_email, send_sms) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)");
                    $insFup->execute([$lead_id, $action_type, $scheduled_at, $fup_notes ?: 'Follow-up reminder set', $fup_assigned_to, $send_email, $send_sms]);
                }
            }
            
            // 3. Log timeline event
            $log = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, 'Lead details and follow-up quick filled from Leads Directory')");
            $log->execute([$lead_id, $_SESSION['user_name'] ?? 'System Operator']);
            
            echo json_encode(['success' => true, 'message' => 'Lead details and follow-up saved successfully!']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database is not connected.']);
        exit;
    }
}

// Handle profile updates
if (isset($_GET['action']) && $_GET['action'] === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $new_password = $_POST['new_password'];
    
    $user_id = $_SESSION['user_id'] ?? 1;
    
    // Handle avatar upload
    $photo_path = $_SESSION['user_photo'] ?? null;
    if ($db_connected && $pdo) {
        try {
            $stmtPhoto = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $stmtPhoto->execute([$user_id]);
            $dbPhoto = $stmtPhoto->fetchColumn();
            if (!empty($dbPhoto)) {
                $photo_path = $dbPhoto;
            }
        } catch (PDOException $e) {}
    }
    
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['profile_photo']['tmp_name'];
        $file_name = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['profile_photo']['name']));
        if (empty($file_name)) {
            $file_name = 'avatar.jpg';
        }
        $upload_dir = __DIR__ . '/uploads/avatars/';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }
        $unique_name = time() . '_' . rand(1000, 9999) . '_' . $file_name;
        $target_file = $upload_dir . $unique_name;
        if (move_uploaded_file($tmp_name, $target_file)) {
            $photo_path = 'uploads/avatars/' . $unique_name;
        }
    }
    
    if ($db_connected && $pdo) {
        try {
            // Check if email already used by another user
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, $user_id]);
            if ($check->fetch()) {
                $_SESSION['flash_error'] = "The email address is already in use by another user.";
            } else {
                if (!empty($new_password)) {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ?, profile_photo = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $hash, $photo_path, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, profile_photo = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $photo_path, $user_id]);
                }
                
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_photo'] = $photo_path;
                $_SESSION['flash_success'] = "Your profile details have been successfully updated.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to update profile: " . $e->getMessage();
        }
    } else {
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_photo'] = $photo_path;
        $_SESSION['flash_success'] = "Database offline. Profile updates simulated successfully.";
    }
    
    header("Location: index.php?page=settings");
    exit;
}

// Handle scheduling followups/reminders
if (isset($_GET['action']) && $_GET['action'] === 'schedule_followup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lead_id = isset($_POST['lead_id']) ? trim($_POST['lead_id']) : '';
    $scheduled_at = isset($_POST['scheduled_at']) ? trim($_POST['scheduled_at']) : '';
    $action_type = isset($_POST['action_type']) ? trim($_POST['action_type']) : 'Call';
    $assigned_to = isset($_POST['assigned_to']) ? trim($_POST['assigned_to']) : '';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    
    $send_email = isset($_POST['send_email']) ? 1 : 0;
    $send_sms = isset($_POST['send_sms']) ? 1 : 0;
    
    if ($db_connected && $pdo) {
        try {
            // Find lead details
            $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
            $stmtLead->execute([$lead_id]);
            $lead = $stmtLead->fetch();
            
            if ($lead) {
                // Insert into database with trigger flags
                $targets_list = [];
                $sms_targets = isset($_POST['sms_targets']) ? $_POST['sms_targets'] : [];
                if (in_array('client', $sms_targets) && !empty($_POST['sms_client_phone'])) {
                    $targets_list[] = ['phone' => trim($_POST['sms_client_phone']), 'role' => 'Client'];
                }
                if (in_array('employee', $sms_targets) && !empty($_POST['sms_employee_phone'])) {
                    $targets_list[] = ['phone' => trim($_POST['sms_employee_phone']), 'role' => 'Employee'];
                }
                if (in_array('admin', $sms_targets) && !empty($_POST['sms_admin_phone'])) {
                    $targets_list[] = ['phone' => trim($_POST['sms_admin_phone']), 'role' => 'Admin'];
                }
                $sms_targets_json = json_encode($targets_list);

                // Check if a pending follow-up already exists for this lead
                $chkFup = $pdo->prepare("SELECT id FROM followups WHERE lead_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $chkFup->execute([$lead_id]);
                $existFup = $chkFup->fetchColumn();

                if ($existFup) {
                    $stmt = $pdo->prepare("UPDATE followups SET action_type = ?, scheduled_at = ?, remarks = ?, assigned_to = ?, send_email = ?, send_sms = ?, sms_targets = ? WHERE id = ?");
                    $stmt->execute([$action_type, $scheduled_at, $remarks, $assigned_to, $send_email, $send_sms, $sms_targets_json, $existFup]);
                    $fup_id = $existFup;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO followups (lead_id, action_type, scheduled_at, remarks, assigned_to, send_email, send_sms, email_sent, sms_sent, sms_targets, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'pending')");
                    $stmt->execute([$lead_id, $action_type, $scheduled_at, $remarks, $assigned_to, $send_email, $send_sms, $sms_targets_json]);
                    $fup_id = $pdo->lastInsertId();
                }
                
                // Write to activity timeline
                $stmtTime = $pdo->prepare("INSERT INTO timeline (lead_id, actor, action_taken) VALUES (?, ?, ?)");
                $action_desc = "Scheduled a follow-up " . htmlspecialchars($action_type) . " for " . htmlspecialchars($scheduled_at);
                $stmtTime->execute([$lead_id, $_SESSION['user_name'] ?? 'System', $action_desc]);
                
                // Determine if we should fire immediately (if time is now or in the past)
                $is_immediate = (strtotime($scheduled_at) <= time());
                
                // Send Email Notification if checked and immediate
                if ($is_immediate && $send_email && !empty($lead['email'])) {
                    $subject = "Marg Soft Solution: Scheduled Follow-up Notification";
                    $title = "Follow-up Scheduled Reminder";
                    $header = "Scheduled " . htmlspecialchars($action_type);
                    $subtitle = "Reminder for " . htmlspecialchars($lead['name']) . " on " . htmlspecialchars($scheduled_at);
                    
                    $body = "<p>Dear " . htmlspecialchars($lead['name']) . ",</p>";
                    $body .= "<p>This is a reminder that we have scheduled a follow-up <strong>" . htmlspecialchars($action_type) . "</strong> with you.</p>";
                    $body .= "<p><strong>Date & Time:</strong> " . htmlspecialchars($scheduled_at) . "</p>";
                    if (!empty($remarks)) {
                        $body .= "<p><strong>Notes:</strong> " . htmlspecialchars($remarks) . "</p>";
                    }
                    $body .= "<p>If you need to reschedule or have any queries, please let us know.</p>";
                    
                    $compiledMail = Mailer::wrapHTMLTemplate($title, $header, $subtitle, $body, "Launch CRM Dashboard", "http://localhost/marglead/auth/login.php");
                    if (Mailer::send($lead['email'], $subject, $compiledMail)) {
                        $pdo->prepare("UPDATE followups SET email_sent = 1 WHERE id = ?")->execute([$fup_id]);
                    }
                }
                
                // Process Free SMS (Carrier email-to-sms or mock simulator) if immediate
                if ($is_immediate && $send_sms && !empty($targets_list)) {
                    $sms_msg = "MARG SOFT SOLUTION: Scheduled " . $action_type . " for " . $lead['name'] . " on " . $scheduled_at . ". Notes: " . $remarks;
                    
                    $_SESSION['sms_simulation_batch'] = [
                        'message' => $sms_msg,
                        'targets' => $targets_list
                    ];
                    
                    foreach ($targets_list as $tgt) {
                        $stmtTime->execute([$lead_id, 'SMS Gateway', "Free Carrier SMS reminder dispatched to " . $tgt['role'] . " (" . htmlspecialchars($tgt['phone']) . ")"]);
                    }
                    
                    $pdo->prepare("UPDATE followups SET sms_sent = 1 WHERE id = ?")->execute([$fup_id]);
                }
                
                $_SESSION['flash_success'] = "Follow-up scheduled successfully. Alerts configured.";
            } else {
                $_SESSION['flash_error'] = "Invalid customer lead ID selected.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash_error'] = "Failed to schedule follow-up: " . $e->getMessage();
        }
    }
    
    // Redirect back
    $ref = $_SERVER['HTTP_REFERER'] ?? 'index.php?page=dashboard';
    header("Location: " . $ref);
    exit;
}

// Resolve URL query parameters, default to dashboard
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Enforce role-based access security check
if (!hasAccess($page, $_SESSION['user_role'])) {
    $_SESSION['access_denied_page'] = $page;
    $page = 'access_denied';
}

$module_path = '';

switch ($page) {
    case 'access_denied':
        $module_path = __DIR__ . '/modules/access_denied.php';
        break;
    case 'dashboard':
        $module_path = __DIR__ . '/modules/dashboard.php';
        break;
    case 'leads':
        $module_path = __DIR__ . '/modules/leads/list.php';
        break;
    case 'lead_details':
        $module_path = __DIR__ . '/modules/leads/details.php';
        break;
    case 'lead_form':
        $module_path = __DIR__ . '/modules/leads/form.php';
        break;
    case 'lead_import':
        $module_path = __DIR__ . '/modules/leads/import.php';
        break;
    case 'pipeline':
        $module_path = __DIR__ . '/modules/pipeline.php';
        break;
    case 'followups':
        $module_path = __DIR__ . '/modules/followups.php';
        break;
    case 'demo':
        $module_path = __DIR__ . '/modules/demo.php';
        break;
    case 'quotation':
        $module_path = __DIR__ . '/modules/quotation/list.php';
        break;
    case 'quotation_create':
        $module_path = __DIR__ . '/modules/quotation/create.php';
        break;
    case 'quotation_view':
        $module_path = __DIR__ . '/modules/quotation/view.php';
        break;
    case 'payments':
        $module_path = __DIR__ . '/modules/payments.php';
        break;
    case 'bank_accounts':
        $module_path = __DIR__ . '/modules/bank_accounts.php';
        break;
    case 'installation':
        $module_path = __DIR__ . '/modules/installation.php';
        break;
    case 'training':
        $module_path = __DIR__ . '/modules/training.php';
        break;
    case 'support':
        $module_path = __DIR__ . '/modules/support.php';
        break;
    case 'renewals':
        $module_path = __DIR__ . '/modules/renewals.php';
        break;
    case 'clients':
        $module_path = __DIR__ . '/modules/clients.php';
        break;
    case 'admin_users':
        $module_path = __DIR__ . '/modules/admin/users.php';
        break;
    case 'admin_permissions':
        $module_path = __DIR__ . '/modules/admin/permissions.php';
        break;
    case 'admin_reports':
        $module_path = __DIR__ . '/modules/admin/reports.php';
        break;
    case 'settings':
        $module_path = __DIR__ . '/modules/admin/settings.php';
        break;
    default:
        $module_path = __DIR__ . '/modules/dashboard.php';
        break;
}

if (file_exists($module_path)) {
    include_once __DIR__ . '/includes/header.php';
    include_once $module_path;
    include_once __DIR__ . '/includes/footer.php';
} else {
    // Visual Fallback for uncreated panels
    include_once __DIR__ . '/includes/header.php';
    echo '
    <div class="card p-6 text-center" style="max-width: 500px; margin: 4rem auto; border: 1px solid var(--border-color);">
        <i data-lucide="alert-octagon" style="width: 48px; height: 48px; color: var(--danger); margin: 0 auto 1.5rem auto;"></i>
        <h2 class="mb-2" style="font-family: var(--font-heading);">Module Workspace Pending</h2>
        <p class="text-muted mb-4">The module [modules/' . htmlspecialchars($page) . '.php] has not been compiled or is currently under development.</p>
        <a href="index.php?page=dashboard" class="btn btn-primary">Return to Dashboard</a>
    </div>';
    include_once __DIR__ . '/includes/footer.php';
}
