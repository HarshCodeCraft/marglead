<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

try {
    $auth = getAuthUserContext();
    $req_assigned = trim($_GET['assigned_to'] ?? '');

    // SECURITY ENFORCEMENT: Non-admin users can ONLY view their own statistics
    $assigned_to = '';
    if (!$auth['isAdmin'] && !empty($auth['name'])) {
        $assigned_to = $auth['name'];
    } elseif (!empty($req_assigned) && strtolower($req_assigned) !== 'all') {
        $assigned_to = $req_assigned;
    }

    $filterUser = !empty($assigned_to);

    if ($filterUser) {
        $like = '%' . $assigned_to . '%';
        
        $total_leads_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to LIKE ?");
        $total_leads_stmt->execute([$like]);
        $total_leads = $total_leads_stmt->fetchColumn();

        $hot_leads_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE priority = 'hot' AND assigned_to LIKE ?");
        $hot_leads_stmt->execute([$like]);
        $hot_leads = $hot_leads_stmt->fetchColumn();

        $won_leads_stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE status IN ('won', 'active') AND assigned_to LIKE ?");
        $won_leads_stmt->execute([$like]);
        $won_leads = $won_leads_stmt->fetchColumn();

        $pipeline_stmt = $pdo->prepare("SELECT COALESCE(SUM(budget), 0) FROM leads WHERE status NOT IN ('lost', 'dropped') AND assigned_to LIKE ?");
        $pipeline_stmt->execute([$like]);
        $pipeline_value = $pipeline_stmt->fetchColumn();

        $pending_followups_stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE status = 'pending' AND assigned_to LIKE ?");
        $pending_followups_stmt->execute([$like]);
        $pending_followups = $pending_followups_stmt->fetchColumn();

        $open_tickets_stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE status = 'open' AND assigned_to LIKE ?");
        $open_tickets_stmt->execute([$like]);
        $open_tickets = $open_tickets_stmt->fetchColumn();

        $critical_tickets_stmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE priority = 'critical' AND assigned_to LIKE ?");
        $critical_tickets_stmt->execute([$like]);
        $critical_tickets = $critical_tickets_stmt->fetchColumn();

        $recent_leads_stmt = $pdo->prepare("SELECT id, name, company, phone, status, priority, budget, created_at FROM leads WHERE assigned_to LIKE ? ORDER BY created_at DESC LIMIT 5");
        $recent_leads_stmt->execute([$like]);
        $recent_leads = $recent_leads_stmt->fetchAll(PDO::FETCH_ASSOC);

        $recent_tickets_stmt = $pdo->prepare("SELECT id, customer_name, subject, priority, status, assigned_to, date_created FROM support_tickets WHERE assigned_to LIKE ? ORDER BY date_created DESC LIMIT 5");
        $recent_tickets_stmt->execute([$like]);
        $recent_tickets = $recent_tickets_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $total_leads = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
        $hot_leads = $pdo->query("SELECT COUNT(*) FROM leads WHERE priority = 'hot'")->fetchColumn();
        $won_leads = $pdo->query("SELECT COUNT(*) FROM leads WHERE status IN ('won', 'active')")->fetchColumn();
        $pipeline_value = $pdo->query("SELECT COALESCE(SUM(budget), 0) FROM leads WHERE status NOT IN ('lost', 'dropped')")->fetchColumn();
        $pending_followups = $pdo->query("SELECT COUNT(*) FROM followups WHERE status = 'pending'")->fetchColumn();
        $open_tickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
        $critical_tickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE priority = 'critical'")->fetchColumn();
        
        $recent_leads = $pdo->query("SELECT id, name, company, phone, status, priority, budget, created_at FROM leads ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $recent_tickets = $pdo->query("SELECT id, customer_name, subject, priority, status, assigned_to, date_created FROM support_tickets ORDER BY date_created DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    }

    sendJsonResponse([
        'success' => true,
        'stats' => [
            'total_leads' => (int)$total_leads,
            'hot_leads' => (int)$hot_leads,
            'won_leads' => (int)$won_leads,
            'pipeline_value' => (float)$pipeline_value,
            'pending_followups' => (int)$pending_followups,
            'open_tickets' => (int)$open_tickets,
            'critical_tickets' => (int)$critical_tickets
        ],
        'recent_leads' => $recent_leads,
        'recent_tickets' => $recent_tickets
    ]);
} catch (PDOException $e) {
    sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
