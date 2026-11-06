<?php
require_once __DIR__ . '/includes/db.php';
$stmt = $pdo->query("SELECT id, title, slug, meta_status, header_type, body_text FROM whatsapp_templates ORDER BY id DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total templates: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "- [{$r['meta_status']}] {$r['slug']} | {$r['title']} (Header: {$r['header_type']})\n";
}
unlink(__FILE__);

