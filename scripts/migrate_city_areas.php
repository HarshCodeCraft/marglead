<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

global $pdo;
if (!$pdo) {
    echo "ERROR: PDO connection not established.\n";
    exit(1);
}

try {
    // 1. Create Base Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `city_areas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `state` VARCHAR(100) NULL,
            `city` VARCHAR(100) NOT NULL,
            `area_name` VARCHAR(150) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_city_area` (`city`, `area_name`),
            INDEX `idx_city` (`city`),
            INDEX `idx_state_city` (`state`, `city`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "SUCCESS: Table `city_areas` created/verified successfully.\n";

    // 2. Seed popular standard areas for major cities
    $popularAreas = [
        // Uttar Pradesh - Kanpur
        ['Uttar Pradesh', 'Kanpur', 'Civil Lines'],
        ['Uttar Pradesh', 'Kanpur', 'Kalyanpur'],
        ['Uttar Pradesh', 'Kanpur', 'Kakadeo'],
        ['Uttar Pradesh', 'Kanpur', 'Govind Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Kidwai Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Swaroop Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Barra'],
        ['Uttar Pradesh', 'Kanpur', 'Lajpat Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Gumti No. 5'],
        ['Uttar Pradesh', 'Kanpur', 'Chakeri'],
        ['Uttar Pradesh', 'Kanpur', 'Fazalganj'],
        ['Uttar Pradesh', 'Kanpur', 'Panki'],
        ['Uttar Pradesh', 'Kanpur', 'Sharda Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Vikas Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Shyam Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Yashoda Nagar'],
        ['Uttar Pradesh', 'Kanpur', 'Armapur'],
        ['Uttar Pradesh', 'Kanpur', 'Cantt'],
        ['Uttar Pradesh', 'Kanpur', 'General Ganj'],
        ['Uttar Pradesh', 'Kanpur', 'Nayaganj'],
        ['Uttar Pradesh', 'Kanpur', 'Birhana Road'],

        // Uttar Pradesh - Lucknow
        ['Uttar Pradesh', 'Lucknow', 'Hazratganj'],
        ['Uttar Pradesh', 'Lucknow', 'Gomti Nagar'],
        ['Uttar Pradesh', 'Lucknow', 'Alambagh'],
        ['Uttar Pradesh', 'Lucknow', 'Indira Nagar'],
        ['Uttar Pradesh', 'Lucknow', 'Mahanagar'],
        ['Uttar Pradesh', 'Lucknow', 'Charbagh'],
        ['Uttar Pradesh', 'Lucknow', 'Aminabad'],
        ['Uttar Pradesh', 'Lucknow', 'Jankipuram'],
        ['Uttar Pradesh', 'Lucknow', 'Ashiyana'],
        ['Uttar Pradesh', 'Lucknow', 'Chowk'],
        ['Uttar Pradesh', 'Lucknow', 'Vikas Nagar'],
        ['Uttar Pradesh', 'Lucknow', 'Rajajipuram'],

        // Delhi
        ['Delhi', 'Delhi', 'Connaught Place'],
        ['Delhi', 'Delhi', 'Karol Bagh'],
        ['Delhi', 'Delhi', 'Lajpat Nagar'],
        ['Delhi', 'Delhi', 'Chandni Chowk'],
        ['Delhi', 'Delhi', 'Rohini'],
        ['Delhi', 'Delhi', 'Dwarka'],
        ['Delhi', 'Delhi', 'Pitampura'],
        ['Delhi', 'Delhi', 'Janakpuri'],
        ['Delhi', 'Delhi', 'Saket'],
        ['Delhi', 'Delhi', 'South Extension'],
        ['Delhi', 'Delhi', 'Nehru Place'],
        ['Delhi', 'Delhi', 'Laxmi Nagar'],

        // Uttar Pradesh - Noida
        ['Uttar Pradesh', 'Noida', 'Sector 18'],
        ['Uttar Pradesh', 'Noida', 'Sector 62'],
        ['Uttar Pradesh', 'Noida', 'Sector 15'],
        ['Uttar Pradesh', 'Noida', 'Sector 137'],
        ['Uttar Pradesh', 'Noida', 'Sector 50'],
        ['Uttar Pradesh', 'Noida', 'Sector 76'],
        ['Uttar Pradesh', 'Noida', 'Sector 128'],

        // Uttar Pradesh - Varanasi
        ['Uttar Pradesh', 'Varanasi', 'Sigra'],
        ['Uttar Pradesh', 'Varanasi', 'Lanka'],
        ['Uttar Pradesh', 'Varanasi', 'Godowlia'],
        ['Uttar Pradesh', 'Varanasi', 'Cantonment'],
        ['Uttar Pradesh', 'Varanasi', 'Bhelupur'],
        ['Uttar Pradesh', 'Varanasi', 'Shivpur'],
        ['Uttar Pradesh', 'Varanasi', 'Pandeypur'],

        // Uttar Pradesh - Agra
        ['Uttar Pradesh', 'Agra', 'Sanjay Place'],
        ['Uttar Pradesh', 'Agra', 'Tajganj'],
        ['Uttar Pradesh', 'Agra', 'Kamla Nagar'],
        ['Uttar Pradesh', 'Agra', 'Dayalbagh'],
        ['Uttar Pradesh', 'Agra', 'Shahganj'],
        ['Uttar Pradesh', 'Agra', 'Civil Lines'],

        // Maharashtra - Mumbai
        ['Maharashtra', 'Mumbai', 'Andheri East'],
        ['Maharashtra', 'Mumbai', 'Andheri West'],
        ['Maharashtra', 'Mumbai', 'Bandra'],
        ['Maharashtra', 'Mumbai', 'Borivali'],
        ['Maharashtra', 'Mumbai', 'Dadar'],
        ['Maharashtra', 'Mumbai', 'Goregaon'],
        ['Maharashtra', 'Mumbai', 'Juhu'],
        ['Maharashtra', 'Mumbai', 'Kandivali'],
        ['Maharashtra', 'Mumbai', 'Malad'],
        ['Maharashtra', 'Mumbai', 'Powai'],
        ['Maharashtra', 'Mumbai', 'Thane'],
        ['Maharashtra', 'Mumbai', 'Vashi'],

        // Rajasthan - Jaipur
        ['Rajasthan', 'Jaipur', 'Malviya Nagar'],
        ['Rajasthan', 'Jaipur', 'Vaishali Nagar'],
        ['Rajasthan', 'Jaipur', 'Mansarovar'],
        ['Rajasthan', 'Jaipur', 'C-Scheme'],
        ['Rajasthan', 'Jaipur', 'Raja Park'],
        ['Rajasthan', 'Jaipur', 'Tonk Road'],
        ['Rajasthan', 'Jaipur', 'Jagatpura']
    ];

    $insStmt = $pdo->prepare("INSERT IGNORE INTO `city_areas` (`state`, `city`, `area_name`) VALUES (?, ?, ?)");
    foreach ($popularAreas as $pa) {
        $insStmt->execute($pa);
    }

    // 3. Check if client_directory exists and seed distinct areas
    $count = 0;
    try {
        $count = $pdo->exec("
            INSERT IGNORE INTO `city_areas` (`state`, `city`, `area_name`)
            SELECT DISTINCT `state`, `city`, TRIM(`area`)
            FROM `client_directory`
            WHERE `area` IS NOT NULL AND TRIM(`area`) != '' 
              AND `city` IS NOT NULL AND TRIM(`city`) != ''
        ");
        echo "SUCCESS: Seeded " . intval($count) . " areas from `client_directory`.\n";
    } catch (Exception $ex) {
        echo "NOTE: Seed skipped: " . $ex->getMessage() . "\n";
    }

    // 4. Show current row count
    $total = $pdo->query("SELECT COUNT(*) FROM `city_areas`")->fetchColumn();
    echo "TOTAL: Total records in `city_areas`: " . intval($total) . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
