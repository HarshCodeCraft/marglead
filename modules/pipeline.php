<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$pipeline_leads = [];
foreach ($PIPELINE_STAGES as $stage_key => $stage_val) {
    $pipeline_leads[$stage_key] = [];
}

$user_role = $_SESSION['user_role'] ?? 'Sales Executive';
$user_name = $_SESSION['user_name'] ?? '';
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

if ($db_connected && $pdo) {
    try {
        if ($is_admin) {
            $stmt = $pdo->query("SELECT * FROM leads ORDER BY created_at DESC");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM leads WHERE assigned_to = ? ORDER BY created_at DESC");
            $stmt->execute([$user_name]);
        }
        $db_leads = $stmt->fetchAll();
        foreach ($db_leads as $l) {
            $stage = $l['status'];
            if (!isset($pipeline_leads[$stage])) {
                $pipeline_leads[$stage] = [];
            }
            $pipeline_leads[$stage][] = [
                'id' => $l['id'],
                'name' => $l['name'],
                'company' => $l['company'],
                'priority' => $l['priority'],
                'budget' => '₹' . number_format($l['budget'], 0)
            ];
        }
    } catch (PDOException $e) {
        // Fallback
    }
}


?>

<div class="pipeline-container">
    <!-- Header Controls Row -->
    <div class="flex justify-between align-center mb-6">
        <div>
            <h2 style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 700;" class="mb-1">Visual Lead Pipeline</h2>
            <p class="text-muted text-sm">Drag and drop client cards between stages to update status, track negotiations, and review payment and installation statuses.</p>
        </div>
        <div class="flex gap-2">
            <button class="btn btn-secondary text-sm" onclick="alert('Filtering pipeline views...');">
                <i data-lucide="sliders-horizontal" style="width: 16px; height: 16px;"></i>
                <span>Filter Board</span>
            </button>
            <a href="index.php?page=leads" class="btn btn-secondary text-sm">
                <i data-lucide="list" style="width: 16px; height: 16px;"></i>
                <span>Directory View</span>
            </a>
        </div>
    </div>

    <!-- Scrollable 16 Columns Board -->
    <div class="pipeline-wrapper">
        <?php foreach ($PIPELINE_STAGES as $key => $stage): ?>
            <?php 
            $leads_in_stage = isset($pipeline_leads[$key]) ? $pipeline_leads[$key] : [];
            $lead_count = count($leads_in_stage);
            ?>
            <div class="pipeline-column" data-stage="<?php echo $key; ?>" style="--column-color: <?php echo $stage['color']; ?>;">
                <!-- Column Header -->
                <div class="column-header">
                    <span class="column-title">
                        <span class="column-indicator"></span>
                        <?php echo $stage['label']; ?>
                    </span>
                    <span class="column-counter"><?php echo $lead_count; ?></span>
                </div>
                
                <!-- Cards Container -->
                <div class="cards-list">
                    <?php foreach ($leads_in_stage as $lead): ?>
                        <div class="kanban-card" id="card-<?php echo $lead['id']; ?>" draggable="true">
                            <div class="card-lead-id"><?php echo $lead['id']; ?></div>
                            <div class="card-lead-name">
                                <a href="index.php?page=lead_details&id=<?php echo $lead['id']; ?>" class="text-main hover-primary">
                                    <?php echo $lead['name']; ?>
                                </a>
                            </div>
                            <div class="card-lead-company"><?php echo $lead['company']; ?></div>
                            <div class="card-lead-footer">
                                <?php echo getPriorityBadge($lead['priority']); ?>
                                <span class="card-lead-budget"><?php echo $lead['budget']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
