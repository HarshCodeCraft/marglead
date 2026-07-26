<?php
require_once __DIR__ . '/cors.php';

if (!$db_connected || !$pdo) {
    sendJsonResponse(['success' => false, 'message' => 'Database offline.'], 500);
}

try {
    // Lead stats
    $total_leads = $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
    $hot_leads = $pdo->query("SELECT COUNT(*) FROM leads WHERE priority = 'hot'")->fetchColumn();
    $won_leads = $pdo->query("SELECT COUNT(*) FROM leads WHERE status IN ('won', 'active')")->fetchColumn();
    
    // Financial stats
    $pipeline_value = $pdo->query("SELECT COALESCE(SUM(budget), 0) FROM leads WHERE status NOT IN ('lost', 'dropped')")->fetchColumn();
    
    // Followups & tickets
    $pending_followups = $pdo->query("SELECT COUNT(*) FROM followups WHERE status = 'pending'")->fetchColumn();
    $open_tickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
    $critical_tickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE priority = 'critical'")->fetchColumn();
    
    // Recent Leads
    $recent_leads = $pdo->query("SELECT id, name, company, phone, status, priority, budget, created_at FROM leads ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent Support Tickets
    $recent_tickets = $pdo->query("SELECT id, customer_name, subject, priority, status, assigned_to, date_created FROM support_tickets ORDER BY date_created DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

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
