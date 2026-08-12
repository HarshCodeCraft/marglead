<?php
/**
 * Marg CRM - WhatsApp Broadcast & Campaign API Endpoint
 */

require_once __DIR__ . '/cors.php';

header('Content-Type: application/json; charset=utf-8');

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_campaigns';

try {
    switch ($action) {
        // ---------------------------------------------------------
        // 1. List Campaigns
        // ---------------------------------------------------------
        case 'get_campaigns':
            $stmt = $pdo->query("SELECT * FROM broadcast_campaigns ORDER BY id DESC");
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($campaigns as &$c) {
                $total = max(1, intval($c['total_contacts']));
                $sent = intval($c['sent_count']);
                $c['progress_percent'] = min(100, round(($sent / $total) * 100));
                $c['formatted_created'] = date('d M Y, h:i A', strtotime($c['created_at']));
            }

            sendJsonResponse(['success' => true, 'campaigns' => $campaigns]);
            break;

        // ---------------------------------------------------------
        // 2. Campaign Details & Audience List
        // ---------------------------------------------------------
        case 'get_campaign_details':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) sendJsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);

            $stmtC = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC->execute([$id]);
            $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) sendJsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);

            $stmtA = $pdo->prepare("SELECT * FROM campaign_audience WHERE campaign_id = ? ORDER BY id ASC LIMIT 500");
            $stmtA->execute([$id]);
            $audience = $stmtA->fetchAll(PDO::FETCH_ASSOC);

            sendJsonResponse(['success' => true, 'campaign' => $campaign, 'audience' => $audience]);
            break;

        // ---------------------------------------------------------
        // 3. Create Campaign (Clients / Leads / CSV Upload)
        // ---------------------------------------------------------
        case 'create_campaign':
            $name = trim($_POST['name'] ?? '');
            $templateName = trim($_POST['template_name'] ?? 'amc_renewal_reminder');
            $targetType = trim($_POST['target_type'] ?? 'clients');
            $customMessage = trim($_POST['custom_message'] ?? '');
            $delaySeconds = max(1, intval($_POST['delay_seconds'] ?? 2));
            $userActor = $_SESSION['user_name'] ?? 'Support Executive';
            $userRole  = $_SESSION['user_role'] ?? 'Executive';

            if (empty($name)) {
                sendJsonResponse(['success' => false, 'message' => 'Campaign Name is required'], 400);
            }

            // If Admin, auto-approve; else require Admin approval
            $initialStatus = in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager']) ? 'approved' : 'pending_approval';

            // Insert campaign record
            $stmtIns = $pdo->prepare("INSERT INTO broadcast_campaigns (name, template_name, target_type, custom_message, delay_seconds, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->execute([$name, $templateName, $targetType, $customMessage, $delaySeconds, $initialStatus, $userActor]);
            $campaignId = $pdo->lastInsertId();

            $selectedPhones = isset($_POST['selected_phones']) ? (array)$_POST['selected_phones'] : [];

            $contacts = [];

            if ($targetType === 'clients') {
                // Fetch contacts from client_directory
                try {
                    $stmtCD = $pdo->query("SELECT customer_id as id, party_name as name, company_using as company, mobile as phone FROM client_directory WHERE mobile IS NOT NULL AND mobile != '' LIMIT 1000");
                    $cdList = $stmtCD ? $stmtCD->fetchAll(PDO::FETCH_ASSOC) : [];
                    foreach ($cdList as $ac) {
                        $p = preg_replace('/[^0-9]/', '', $ac['phone']);
                        if (strlen($p) >= 10) {
                            if (strlen($p) == 10) $p = '91' . $p;
                            if (empty($selectedPhones) || in_array($p, $selectedPhones)) {
                                $contacts[$p] = [
                                    'mobile' => $p,
                                    'name'   => !empty($ac['name']) ? $ac['name'] : 'Valued Customer',
                                    'company'=> !empty($ac['company']) ? $ac['company'] : 'Marg Customer'
                                ];
                            }
                        }
                    }
                } catch (Throwable $eCD) {}

                try {
                    $stmtTC = $pdo->query("SELECT id, name, company_name as company, mobile as phone FROM tenant_companies WHERE mobile IS NOT NULL AND mobile != '' LIMIT 1000");
                    $tcList = $stmtTC ? $stmtTC->fetchAll(PDO::FETCH_ASSOC) : [];
                    foreach ($tcList as $tc) {
                        $p = preg_replace('/[^0-9]/', '', $tc['phone']);
                        if (strlen($p) >= 10) {
                            if (strlen($p) == 10) $p = '91' . $p;
                            if (empty($selectedPhones) || in_array($p, $selectedPhones)) {
                                $contacts[$p] = [
                                    'mobile' => $p,
                                    'name'   => !empty($tc['name']) ? $tc['name'] : 'Tenant Client',
                                    'company'=> !empty($tc['company']) ? $tc['company'] : 'Marg Partner'
                                ];
                            }
                        }
                    }
                } catch (Throwable $eTC) {}
            } elseif ($targetType === 'leads') {
                $stmtLd = $pdo->query("SELECT id, name, company, phone FROM leads WHERE phone IS NOT NULL AND phone != '' LIMIT 1000");
                $ldList = $stmtLd ? $stmtLd->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($ldList as $lc) {
                    $p = preg_replace('/[^0-9]/', '', $lc['phone']);
                    if (strlen($p) >= 10) {
                        if (strlen($p) == 10) $p = '91' . $p;
                        if (empty($selectedPhones) || in_array($p, $selectedPhones)) {
                            $contacts[$p] = [
                                'mobile' => $p,
                                'name'   => !empty($lc['name']) ? $lc['name'] : 'Lead Contact',
                                'company'=> !empty($lc['company']) ? $lc['company'] : 'Prospect'
                            ];
                        }
                    }
                }
            } elseif ($targetType === 'csv' && isset($_FILES['csv_file'])) {
                $file = $_FILES['csv_file']['tmp_name'];
                if (($handle = fopen($file, "r")) !== FALSE) {
                    $header = fgetcsv($handle, 1000, ",");
                    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $rawPhone = $row[0] ?? '';
                        $rawName  = $row[1] ?? 'Customer';
                        $rawComp  = $row[2] ?? 'Company';
                        $p = preg_replace('/[^0-9]/', '', $rawPhone);
                        if (strlen($p) >= 10) {
                            if (strlen($p) == 10) $p = '91' . $p;
                            $contacts[$p] = [
                                'mobile' => $p,
                                'name'   => $rawName,
                                'company'=> $rawComp
                            ];
                        }
                    }
                    fclose($handle);
                }
            }

            // Insert contacts into campaign_audience
            $stmtAud = $pdo->prepare("INSERT INTO campaign_audience (campaign_id, mobile, customer_name, company_name, status) VALUES (?, ?, ?, ?, 'pending')");
            foreach ($contacts as $cnt) {
                $stmtAud->execute([$campaignId, $cnt['mobile'], $cnt['name'], $cnt['company']]);
            }

            $totalCount = count($contacts);
            $stmtUpd = $pdo->prepare("UPDATE broadcast_campaigns SET total_contacts = ?, pending_count = ? WHERE id = ?");
            $stmtUpd->execute([$totalCount, $totalCount, $campaignId]);

            $msg = ($initialStatus === 'pending_approval')
                ? "Campaign created with $totalCount contacts and submitted for Admin Approval!"
                : "Campaign created and approved with $totalCount contacts!";

            sendJsonResponse([
                'success' => true,
                'message' => $msg,
                'campaign_id' => $campaignId,
                'status' => $initialStatus,
                'total_contacts' => $totalCount
            ]);
            break;

        // ---------------------------------------------------------
        // 4. Admin Approve / Reject Actions
        // ---------------------------------------------------------
        case 'approve_campaign':
            $id = intval($_POST['id'] ?? 0);
            $userActor = $_SESSION['user_name'] ?? 'Admin';
            $userRole  = $_SESSION['user_role'] ?? 'Admin';

            if (!in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager'])) {
                sendJsonResponse(['success' => false, 'message' => 'Only Admins can approve campaigns.'], 403);
            }

            $stmtApp = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmtApp->execute([$userActor, $id]);

            sendJsonResponse(['success' => true, 'message' => 'Campaign approved successfully!']);
            break;

        case 'reject_campaign':
            $id = intval($_POST['id'] ?? 0);
            $userActor = $_SESSION['user_name'] ?? 'Admin';
            $userRole  = $_SESSION['user_role'] ?? 'Admin';

            if (!in_array($userRole, ['Super Admin', 'Admin', 'Regional Manager'])) {
                sendJsonResponse(['success' => false, 'message' => 'Only Admins can reject campaigns.'], 403);
            }

            $stmtRej = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmtRej->execute([$userActor, $id]);

            $stmtCanAud = $pdo->prepare("UPDATE campaign_audience SET status = 'cancelled' WHERE campaign_id = ? AND status = 'pending'");
            $stmtCanAud->execute([$id]);

            sendJsonResponse(['success' => true, 'message' => 'Campaign rejected.']);
            break;

        // ---------------------------------------------------------
        // 5. Toggle Campaign Status (Start, Pause, Resume, Cancel)
        // ---------------------------------------------------------
        case 'toggle_status':
            $id = intval($_POST['id'] ?? 0);
            $newStatus = strtolower(trim($_POST['status'] ?? 'running')); // 'running', 'paused', 'cancelled'

            if (!$id || !in_array($newStatus, ['running', 'paused', 'cancelled'])) {
                sendJsonResponse(['success' => false, 'message' => 'Invalid status change parameters'], 400);
            }

            // Check current campaign approval status
            $stmtChk = $pdo->prepare("SELECT status FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtChk->execute([$id]);
            $curSt = $stmtChk->fetchColumn();

            if (in_array($curSt, ['pending_approval', 'rejected'])) {
                sendJsonResponse(['success' => false, 'message' => '🔒 Campaign requires Admin Approval before it can be started.'], 403);
            }

            $stmtSt = $pdo->prepare("UPDATE broadcast_campaigns SET status = ? WHERE id = ?");
            $stmtSt->execute([$newStatus, $id]);

            if ($newStatus === 'cancelled') {
                $stmtCanAud = $pdo->prepare("UPDATE campaign_audience SET status = 'cancelled' WHERE campaign_id = ? AND status = 'pending'");
                $stmtCanAud->execute([$id]);
            }

            sendJsonResponse(['success' => true, 'message' => 'Campaign status updated to ' . ucfirst($newStatus)]);
            break;

        // ---------------------------------------------------------
        // 5. Batch Message Dispatcher
        // ---------------------------------------------------------
        case 'process_batch':
            $id = intval($_REQUEST['id'] ?? 0);
            $batchSize = max(1, min(10, intval($_REQUEST['batch_size'] ?? 5)));

            if (!$id) sendJsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);

            // Fetch campaign
            $stmtC = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC->execute([$id]);
            $campaign = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) sendJsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);

            if ($campaign['status'] !== 'running') {
                sendJsonResponse(['success' => true, 'message' => 'Campaign is currently ' . $campaign['status'], 'status' => $campaign['status']]);
            }

            // Fetch next pending contacts batch
            $stmtAud = $pdo->prepare("SELECT * FROM campaign_audience WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC LIMIT $batchSize");
            $stmtAud->execute([$id]);
            $pendingBatch = $stmtAud->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pendingBatch)) {
                // Mark campaign as completed
                $stmtDone = $pdo->prepare("UPDATE broadcast_campaigns SET status = 'completed' WHERE id = ?");
                $stmtDone->execute([$id]);
                sendJsonResponse(['success' => true, 'message' => 'Campaign completed!', 'status' => 'completed']);
            }

            require_once __DIR__ . '/whatsapp-api.php';
            $whatsapp = new WhatsAppAPI($pdo);

            $processedCount = 0;
            $sentInc = 0;
            $failInc = 0;

            foreach ($pendingBatch as $item) {
                $phone = $item['mobile'];
                $custName = $item['customer_name'] ?? 'Valued Customer';
                $template = $campaign['template_name'];

                $res = null;

                if ($template === 'amc_renewal_reminder') {
                    $msgText = "⏰ *Marg ERP - AMC Renewal Reminder*\n\nDear {$custName},\nYour Marg ERP Software AMC renewal is due.\n\nTo ensure uninterrupted billing & GST return filings, kindly renew your AMC service.\n\nFor payment details or assistance, call: *7523830026*\n\nThank you for choosing Marg Soft Solution!";
                    $res = $whatsapp->sendText($phone, $msgText);
                } elseif ($template === 'bank_details_share') {
                    $msgText = "🏦 *Marg Soft Solution - Official Bank Account Details*\n\nAccount Name: *MARG SOFT SOLUTION*\nBank Name: *HDFC Bank*\nA/C No: *50200067891234*\nIFSC Code: *HDFC0001234*\nBranch: *Main Branch*\nUPI ID: *margsoft@upi*\n\nPlease share payment screenshot after transfer. Thank you! 🙏";
                    $res = $whatsapp->sendText($phone, $msgText);
                } elseif ($template === 'billing_invoice_alert') {
                    $msgText = "📄 *Marg ERP Billing & Invoice Alert*\n\nDear {$custName},\nYour monthly invoice statement is ready.\n\nKindly complete payment to avoid service interruption.\n\nCall *7523830026* for invoice details.\nThank you! 🙏";
                    $res = $whatsapp->sendText($phone, $msgText);
                } else {
                    // Send template or custom message
                    $msgText = !empty($campaign['custom_message']) ? $campaign['custom_message'] : "Welcome to Marg Soft Solution! How can we assist your business today?";
                    $res = $whatsapp->sendText($phone, $msgText);
                }

                $processedCount++;

                if (!empty($res['success']) && $res['success']) {
                    $sentInc++;
                    $updAud = $pdo->prepare("UPDATE campaign_audience SET status = 'sent', sent_at = NOW() WHERE id = ?");
                    $updAud->execute([$item['id']]);
                } else {
                    $failInc++;
                    $errText = $res['error']['message'] ?? 'Dispatch failed';
                    $updAud = $pdo->prepare("UPDATE campaign_audience SET status = 'failed', error_message = ? WHERE id = ?");
                    $updAud->execute([$errText, $item['id']]);
                }

                // Small delay to respect rate limit
                usleep(max(200000, intval($campaign['delay_seconds']) * 200000));
            }

            // Update campaign stats
            $stmtUpdC = $pdo->prepare("UPDATE broadcast_campaigns SET sent_count = sent_count + ?, failed_count = failed_count + ?, pending_count = GREATEST(0, pending_count - ?) WHERE id = ?");
            $stmtUpdC->execute([$sentInc, $failInc, $processedCount, $id]);

            // Re-fetch updated campaign row
            $stmtC2 = $pdo->prepare("SELECT * FROM broadcast_campaigns WHERE id = ? LIMIT 1");
            $stmtC2->execute([$id]);
            $updatedCampaign = $stmtC2->fetch(PDO::FETCH_ASSOC);

            sendJsonResponse([
                'success' => true,
                'processed' => $processedCount,
                'sent' => $sentInc,
                'failed' => $failInc,
                'campaign' => $updatedCampaign
            ]);
            break;

        default:
            sendJsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    sendJsonResponse(['success' => false, 'message' => 'Campaign API Error: ' . $e->getMessage()], 500);
}
