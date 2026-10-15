<?php
/**
 * MargLead Social Media & Ads Module Database Migration
 * Creates: social_accounts, social_posts, social_analytics, social_ad_campaigns
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (!$pdo) {
    die("Error: Database connection not available.\n");
}

echo "Migrating Social Media Schema...\n";

try {
    // 1. Social Accounts (Facebook Pages, Instagram Accounts)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `social_accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tenant_id` INT NOT NULL DEFAULT 1,
        `user_id` INT NOT NULL DEFAULT 1,
        `platform` ENUM('facebook_page', 'instagram', 'linkedin') NOT NULL DEFAULT 'facebook_page',
        `account_id` VARCHAR(120) NOT NULL,
        `account_name` VARCHAR(255) NOT NULL,
        `username` VARCHAR(150) NULL,
        `profile_picture_url` TEXT NULL,
        `access_token` TEXT NOT NULL,
        `token_expires_at` DATETIME NULL,
        `followers_count` INT NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `meta_raw_data` JSON NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_tenant_platform_acc` (`tenant_id`, `platform`, `account_id`),
        INDEX `idx_tenant_active` (`tenant_id`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] Table 'social_accounts' ready.\n";

    // 2. Social Posts & Reels (Lightweight, stores Meta CDN links or temporary buffer)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `social_posts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tenant_id` INT NOT NULL DEFAULT 1,
        `user_id` INT NOT NULL DEFAULT 1,
        `account_id` INT NOT NULL,
        `post_type` ENUM('post', 'reel', 'story', 'carousel') NOT NULL DEFAULT 'post',
        `caption` TEXT NULL,
        `media_url` TEXT NULL,
        `external_post_id` VARCHAR(150) NULL,
        `permalink` TEXT NULL,
        `status` ENUM('draft', 'scheduled', 'publishing', 'published', 'failed') NOT NULL DEFAULT 'draft',
        `scheduled_at` DATETIME NULL,
        `published_at` DATETIME NULL,
        `error_message` TEXT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_tenant_status` (`tenant_id`, `status`),
        INDEX `idx_scheduled` (`status`, `scheduled_at`),
        INDEX `idx_account_posts` (`account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] Table 'social_posts' ready.\n";

    // 3. Social Analytics Cache (Zero server load, stores live metric numbers)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `social_analytics` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tenant_id` INT NOT NULL DEFAULT 1,
        `post_id` INT NOT NULL,
        `external_post_id` VARCHAR(150) NOT NULL,
        `impressions` INT NOT NULL DEFAULT 0,
        `reach` INT NOT NULL DEFAULT 0,
        `likes_count` INT NOT NULL DEFAULT 0,
        `comments_count` INT NOT NULL DEFAULT 0,
        `shares_count` INT NOT NULL DEFAULT 0,
        `clicks_count` INT NOT NULL DEFAULT 0,
        `last_synced_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_post_analytics` (`post_id`),
        INDEX `idx_tenant_analytics` (`tenant_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] Table 'social_analytics' ready.\n";

    // 4. Social Ad Campaigns (Simplified Meta Ads Manager)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `social_ad_campaigns` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `tenant_id` INT NOT NULL DEFAULT 1,
        `user_id` INT NOT NULL DEFAULT 1,
        `ad_account_id` VARCHAR(120) NOT NULL,
        `campaign_name` VARCHAR(255) NOT NULL,
        `meta_campaign_id` VARCHAR(120) NULL,
        `objective` ENUM('MESSAGES', 'LEAD_GENERATION', 'OUTCOME_TRAFFIC') NOT NULL DEFAULT 'MESSAGES',
        `daily_budget_inr` DECIMAL(10,2) NOT NULL DEFAULT 300.00,
        `target_city` VARCHAR(100) NULL,
        `status` ENUM('DRAFT', 'ACTIVE', 'PAUSED', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'DRAFT',
        `total_spend_inr` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `leads_or_messages` INT NOT NULL DEFAULT 0,
        `cost_per_lead_inr` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_tenant_ad_status` (`tenant_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] Table 'social_ad_campaigns' ready.\n";

    echo "Social Media migration completed successfully.\n";
} catch (\PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
