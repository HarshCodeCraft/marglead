<?php
/**
 * MargLead Social Media & Ads Management
 * Unified Meta Suite (Facebook Pages, Instagram, and Express Ads)
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
}

$tenant_id = (int)($_SESSION['tenant_id'] ?? 1);
$user_id   = (int)($_SESSION['user_id'] ?? 1);

// Pre-fetch connected accounts for initial load
$connectedAccounts = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM social_accounts WHERE tenant_id = ? ORDER BY platform ASC, id DESC");
        $stmt->execute([$tenant_id]);
        $connectedAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}
?>

<div class="social-management-wrapper">
    <!-- Header Section -->
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="font-family: var(--font-heading); font-size: 1.5rem; font-weight: 700; margin: 0 0 0.25rem 0; color: var(--text-main);">
                Social Media & Ads Suite
            </h1>
            <p style="margin: 0; font-size: 0.875rem; color: var(--text-muted);">
                Unified multi-channel publishing, engagement monitoring, and express Meta ad campaigns.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <button type="button" class="btn btn-primary" onclick="openConnectModal()" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.1rem; border-radius: var(--border-radius-sm); font-size: 0.875rem; font-weight: 600;">
                <i data-lucide="plus-circle" style="width: 16px; height: 16px;"></i>
                <span>Connect Account</span>
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="social-tabs" style="display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem; overflow-x: auto;">
        <button type="button" class="tab-btn active" onclick="switchSocialTab('channels', this)" style="padding: 0.75rem 1.25rem; font-size: 0.875rem; font-weight: 600; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid var(--primary); color: var(--primary); display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="layers" style="width: 16px; height: 16px;"></i>
            <span>Connected Channels</span>
            <span class="badge" style="background: var(--border-color); color: var(--text-main); font-size: 0.7rem; padding: 2px 6px; border-radius: 4px;"><?php echo count($connectedAccounts); ?></span>
        </button>
        <button type="button" class="tab-btn" onclick="switchSocialTab('publisher', this)" style="padding: 0.75rem 1.25rem; font-size: 0.875rem; font-weight: 600; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="pen-tool" style="width: 16px; height: 16px;"></i>
            <span>Content Studio & Scheduler</span>
        </button>
        <button type="button" class="tab-btn" onclick="switchSocialTab('feed', this)" style="padding: 0.75rem 1.25rem; font-size: 0.875rem; font-weight: 600; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="activity" style="width: 16px; height: 16px;"></i>
            <span>Feed & Analytics</span>
        </button>
        <button type="button" class="tab-btn" onclick="switchSocialTab('ads', this)" style="padding: 0.75rem 1.25rem; font-size: 0.875rem; font-weight: 600; border: none; background: transparent; cursor: pointer; border-bottom: 2px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="trending-up" style="width: 16px; height: 16px;"></i>
            <span>Express Ads Manager</span>
        </button>
    </div>

    <!-- Alert Container -->
    <div id="socialAlertContainer" style="display: none; margin-bottom: 1.25rem; padding: 0.875rem 1rem; border-radius: var(--border-radius-sm); font-size: 0.875rem;"></div>

    <!-- ================= TAB 1: CONNECTED CHANNELS ================= -->
    <div id="tabContent_channels" class="social-tab-pane">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem;">
            <?php if (empty($connectedAccounts)): ?>
                <div style="grid-column: 1 / -1; background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: var(--border-radius-md); padding: 3rem 1.5rem; text-align: center;">
                    <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                        <i data-lucide="share-2" style="width: 24px; height: 24px;"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.125rem; font-weight: 600; color: var(--text-main);">No Channels Connected</h3>
                    <p style="margin: 0 0 1.5rem 0; font-size: 0.875rem; color: var(--text-muted); max-width: 460px; margin-left: auto; margin-right: auto;">
                        Connect your official Facebook Page or Instagram Business account to publish posts, schedule reels, and launch targeted ad campaigns.
                    </p>
                    <button type="button" class="btn btn-primary" onclick="openConnectModal()" style="padding: 0.6rem 1.2rem; font-weight: 600; border-radius: var(--border-radius-sm);">
                        Connect First Channel
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($connectedAccounts as $acc): ?>
                    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.25rem; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--primary); font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; font-weight: 600; text-transform: uppercase;">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $acc['platform'])); ?>
                                </span>
                                <span style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: #10b981; font-weight: 600;">
                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981;"></span> Active
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.875rem; margin-bottom: 1rem;">
                                <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--border-color); overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <?php if (!empty($acc['profile_picture_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($acc['profile_picture_url']); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <i data-lucide="user" style="width: 20px; height: 20px; color: var(--text-muted);"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="overflow: hidden;">
                                    <h4 style="margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo htmlspecialchars($acc['account_name']); ?>
                                    </h4>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                                        ID: <?php echo htmlspecialchars($acc['account_id']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border-color); padding-top: 0.875rem; margin-top: 0.5rem;">
                            <span style="font-size: 0.75rem; color: var(--text-muted);">
                                Connected on <?php echo date('d M Y', strtotime($acc['created_at'])); ?>
                            </span>
                            <button type="button" class="btn btn-outline-danger" onclick="disconnectAccount(<?php echo (int)$acc['id']; ?>)" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; border: 1px solid #ef4444; color: #ef4444; background: transparent; border-radius: 4px; cursor: pointer;">
                                Disconnect
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= TAB 2: CONTENT STUDIO & SCHEDULER ================= -->
    <div id="tabContent_publisher" class="social-tab-pane" style="display: none;">
        <div style="display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; align-items: start;">
            <!-- Composer Form -->
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                <h3 style="margin: 0 0 1.25rem 0; font-size: 1.125rem; font-weight: 600; color: var(--text-main);">
                    Create Social Media Post
                </h3>
                <form id="socialPostForm" onsubmit="handlePostSubmit(event)">
                    <!-- Channel Selector -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem;">Select Target Channel *</label>
                        <select name="account_id" id="postAccountId" required style="width: 100%; padding: 0.65rem 0.875rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.875rem;">
                            <option value="">-- Choose Channel --</option>
                            <?php foreach ($connectedAccounts as $acc): ?>
                                <option value="<?php echo (int)$acc['id']; ?>">
                                    <?php echo htmlspecialchars($acc['account_name'] . ' (' . str_replace('_', ' ', $acc['platform']) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Post Type Selector -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem;">Format</label>
                        <div style="display: flex; gap: 0.75rem;">
                            <label style="flex: 1; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); text-align: center; cursor: pointer; font-size: 0.825rem; font-weight: 500;">
                                <input type="radio" name="post_type" value="post" checked onchange="updatePostType(this.value)"> Regular Post
                            </label>
                            <label style="flex: 1; padding: 0.6rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); text-align: center; cursor: pointer; font-size: 0.825rem; font-weight: 500;">
                                <input type="radio" name="post_type" value="reel" onchange="updatePostType(this.value)"> Reel / Video
                            </label>
                        </div>
                    </div>

                    <!-- Caption & Text Area -->
                    <div style="margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <label style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Caption / Description *</label>
                            <span id="captionCharCount" style="font-size: 0.75rem; color: var(--text-muted);">0 / 2,200</span>
                        </div>
                        <textarea name="caption" id="postCaptionInput" rows="5" placeholder="Write your post caption, promotional message, or hashtags here..." required oninput="handleCaptionInput(this.value)" style="width: 100%; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-family: var(--font-body); font-size: 0.875rem; resize: vertical; box-sizing: border-box;"></textarea>
                    </div>

                    <!-- Media Upload (Zero Local Storage Auto-Purged) -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem;">Attach Media (Optional for Feed Post)</label>
                        <input type="file" name="media_file" id="postMediaInput" accept="image/jpeg,image/png,image/webp,video/mp4" onchange="handleMediaPreview(this)" style="width: 100%; font-size: 0.825rem; color: var(--text-muted);">
                        <p style="margin: 0.35rem 0 0 0; font-size: 0.75rem; color: var(--text-muted);">
                            Supported: JPG, PNG, WEBP, MP4 (Max 50MB). Files are transferred directly to Meta CDN with zero permanent server storage.
                        </p>
                    </div>

                    <!-- Publishing Mode: Now vs Scheduled -->
                    <div style="margin-bottom: 1.5rem; padding: 1rem; background: var(--bg-app); border-radius: var(--border-radius-sm); border: 1px solid var(--border-color);">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem;">Delivery Schedule</label>
                        <div style="display: flex; gap: 1rem; margin-bottom: 0.875rem;">
                            <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; cursor: pointer;">
                                <input type="radio" name="publish_mode" value="now" checked onchange="toggleScheduleDate(false)">
                                <span>Publish Immediately</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; cursor: pointer;">
                                <input type="radio" name="publish_mode" value="schedule" onchange="toggleScheduleDate(true)">
                                <span>Schedule for Later</span>
                            </label>
                        </div>
                        <div id="scheduleDatePickerGroup" style="display: none;">
                            <input type="datetime-local" name="scheduled_at" id="scheduledAtInput" style="padding: 0.5rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.85rem;">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" id="btnSubmitPost" class="btn btn-primary" style="padding: 0.65rem 1.5rem; font-weight: 600; border-radius: var(--border-radius-sm); display: inline-flex; align-items: center; gap: 0.5rem;">
                            <i data-lucide="send" style="width: 16px; height: 16px;"></i>
                            <span id="submitBtnText">Publish Post</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Live Feed Preview (Mobile Frame) -->
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.25rem; box-shadow: var(--shadow-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                    <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase;">Live Feed Preview</span>
                    <span class="badge" style="background: var(--bg-app); color: var(--text-muted); font-size: 0.7rem; padding: 2px 6px; border-radius: 4px;">META SIMULATOR</span>
                </div>

                <div style="border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-card); overflow: hidden;">
                    <!-- Post Header -->
                    <div style="padding: 0.75rem; display: flex; align-items: center; gap: 0.6rem;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--border-color); display: flex; align-items: center; justify-content: center;">
                            <i data-lucide="user" style="width: 16px; height: 16px; color: var(--text-muted);"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);" id="previewChannelName">Select Channel</div>
                            <div style="font-size: 0.7rem; color: var(--text-muted);">Just now · Public</div>
                        </div>
                    </div>

                    <!-- Post Media Container -->
                    <div id="previewMediaContainer" style="width: 100%; height: 200px; background: var(--bg-app); display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 0.75rem; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
                        <span>Media Preview Area</span>
                    </div>

                    <!-- Post Body / Caption -->
                    <div style="padding: 0.875rem;">
                        <p id="previewCaptionText" style="margin: 0; font-size: 0.825rem; color: var(--text-main); white-space: pre-wrap; line-height: 1.4;">
                            Your caption text will appear here dynamically as you type...
                        </p>
                    </div>

                    <!-- Post Action Bar -->
                    <div style="padding: 0.6rem 0.875rem; border-top: 1px solid var(--border-color); display: flex; justify-content: space-around; color: var(--text-muted); font-size: 0.75rem;">
                        <span style="display: inline-flex; align-items: center; gap: 0.35rem;"><i data-lucide="thumbs-up" style="width: 14px; height: 14px;"></i> Like</span>
                        <span style="display: inline-flex; align-items: center; gap: 0.35rem;"><i data-lucide="message-square" style="width: 14px; height: 14px;"></i> Comment</span>
                        <span style="display: inline-flex; align-items: center; gap: 0.35rem;"><i data-lucide="share" style="width: 14px; height: 14px;"></i> Share</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= TAB 3: FEED & ANALYTICS ================= -->
    <div id="tabContent_feed" class="social-tab-pane" style="display: none;">
        <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.25rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h3 style="margin: 0; font-size: 1.125rem; font-weight: 600; color: var(--text-main);">
                    Published & Scheduled Content
                </h3>
                <button type="button" class="btn btn-outline-secondary" onclick="loadSocialFeed()" style="padding: 0.4rem 0.85rem; font-size: 0.8rem; border-radius: var(--border-radius-sm); border: 1px solid var(--border-color); background: transparent; color: var(--text-main); cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i>
                    <span>Refresh Feed</span>
                </button>
            </div>

            <div id="feedListContainer">
                <div style="text-align: center; padding: 2.5rem; color: var(--text-muted); font-size: 0.875rem;">
                    Loading recent social feed...
                </div>
            </div>
        </div>
    </div>

    <!-- ================= TAB 4: EXPRESS ADS MANAGER ================= -->
    <div id="tabContent_ads" class="social-tab-pane" style="display: none;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start;">
            <!-- 3-Step Ad Launcher Form -->
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.25rem;">
                    <div style="width: 32px; height: 32px; border-radius: 6px; background: rgba(59, 130, 246, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="zap" style="width: 18px; height: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.05rem; font-weight: 600; color: var(--text-main);">Express Ads Launcher</h3>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Launch Facebook & Instagram campaigns in 3 simple steps</span>
                    </div>
                </div>

                <form id="expressAdForm" onsubmit="handleAdSubmit(event)">
                    <!-- Step 1: Campaign Name & Objective -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.4rem;">Campaign Name *</label>
                        <input type="text" name="campaign_name" placeholder="e.g. Festive Mega Promo Campaign" required style="width: 100%; padding: 0.65rem 0.875rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.875rem; box-sizing: border-box;">
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.4rem;">Campaign Goal *</label>
                        <select name="objective" style="width: 100%; padding: 0.65rem 0.875rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.875rem;">
                            <option value="MESSAGES">Get Direct WhatsApp Enquiries (Click-to-WhatsApp)</option>
                            <option value="LEAD_GENERATION">Generate Verified Leads (Instant Form)</option>
                            <option value="OUTCOME_TRAFFIC">Direct Traffic to Website / Landing Page</option>
                        </select>
                    </div>

                    <!-- Step 2: Target Location -->
                    <div style="margin-bottom: 1.25rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.4rem;">Target Geographic Region</label>
                        <input type="text" name="target_city" placeholder="e.g. Delhi NCR, Mumbai, or All India" value="All India" style="width: 100%; padding: 0.65rem 0.875rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.875rem; box-sizing: border-box;">
                    </div>

                    <!-- Step 3: Daily Budget -->
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; font-size: 0.825rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.4rem;">Daily Budget (INR) *</label>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span style="font-weight: 600; font-size: 0.875rem; color: var(--text-muted);">INR</span>
                            <input type="number" name="daily_budget" min="100" max="50000" step="50" value="500" required style="flex: 1; padding: 0.65rem 0.875rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.875rem; box-sizing: border-box;">
                        </div>
                        <span style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.35rem;">
                            Estimated reach: 2,500 - 8,000 targeted business owners per day.
                        </span>
                    </div>

                    <button type="submit" id="btnLaunchAd" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-weight: 600; border-radius: var(--border-radius-sm); display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <i data-lucide="check-circle-2" style="width: 16px; height: 16px;"></i>
                        <span>Activate Express Ad Campaign</span>
                    </button>
                </form>
            </div>

            <!-- Active Ad Campaigns Table -->
            <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); padding: 1.25rem; box-shadow: var(--shadow-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="margin: 0; font-size: 1.05rem; font-weight: 600; color: var(--text-main);">
                        Campaign Performance
                    </h3>
                    <button type="button" class="btn btn-outline-secondary" onclick="loadAdCampaigns()" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; border: 1px solid var(--border-color); background: transparent; color: var(--text-main); border-radius: 4px; cursor: pointer;">
                        Refresh Ads
                    </button>
                </div>
                <div id="adCampaignsListContainer">
                    <div style="text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.85rem;">
                        Loading ad campaigns...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Connect Account Modal -->
<div id="connectAccountModal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-md); width: 100%; max-width: 540px; padding: 1.5rem; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
            <div>
                <h3 style="margin: 0; font-size: 1.125rem; font-weight: 600; color: var(--text-main);">Connect Social Channel</h3>
                <span style="font-size: 0.75rem; color: var(--text-muted);">Link your official Facebook Page and Instagram Business handle</span>
            </div>
            <button type="button" onclick="closeConnectModal()" style="border: none; background: transparent; color: var(--text-muted); cursor: pointer; font-size: 1.25rem; padding: 0.25rem;">
                &times;
            </button>
        </div>

        <!-- Option 1: 1-Click Meta Login (Recommended for regular users) -->
        <div style="background: rgba(24, 119, 242, 0.05); border: 1px solid rgba(24, 119, 242, 0.2); border-radius: var(--border-radius-sm); padding: 1.25rem; text-align: center; margin-bottom: 1.25rem;">
            <div style="font-size: 0.85rem; color: var(--text-main); font-weight: 600; margin-bottom: 0.35rem;">
                Method 1: Automatic 1-Click Facebook Connect
            </div>
            <p style="margin: 0 0 1rem 0; font-size: 0.785rem; color: var(--text-muted); line-height: 1.4;">
                Log in to Facebook to automatically detect your Pages and linked Instagram accounts. Zero manual IDs or tokens required.
            </p>
            <button type="button" id="btnFbOAuth" class="btn" onclick="loginWithFacebook()" style="background: #1877F2; color: #ffffff; border: none; padding: 0.65rem 1.4rem; font-size: 0.85rem; font-weight: 600; border-radius: var(--border-radius-sm); display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <i data-lucide="log-in" style="width: 16px; height: 16px;"></i>
                <span>Continue with Facebook</span>
            </button>
            <div id="fbOAuthStatus" style="display: none; margin-top: 0.75rem; font-size: 0.8rem; color: var(--text-muted);"></div>
        </div>

        <!-- Discovered Pages List Container -->
        <div id="discoveredPagesContainer" style="display: none; margin-bottom: 1.25rem;">
            <h4 style="margin: 0 0 0.5rem 0; font-size: 0.825rem; font-weight: 600; color: var(--text-main);">Available Pages Found:</h4>
            <div id="discoveredPagesList" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
        </div>

        <!-- Option 2: Collapsible Manual Setup for Custom Tokens -->
        <div style="border-top: 1px solid var(--border-color); padding-top: 0.875rem;">
            <button type="button" onclick="toggleManualSetup()" style="border: none; background: transparent; color: var(--text-muted); font-size: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 0.25rem 0;">
                <span style="font-weight: 500;">Method 2: Manual Page ID &amp; Token (Advanced / Developers)</span>
                <i data-lucide="chevron-down" id="manualChevronIcon" style="width: 14px; height: 14px;"></i>
            </button>

            <form id="connectChannelForm" onsubmit="handleConnectSubmit(event)" style="display: none; margin-top: 1rem;">
                <div style="background: var(--bg-app); border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); padding: 0.75rem; margin-bottom: 1rem; font-size: 0.75rem; color: var(--text-muted); line-height: 1.4;">
                    <strong style="color: var(--text-main); display: block; margin-bottom: 0.25rem;">How to find Page ID &amp; Token manually:</strong>
                    1. <strong>Page ID:</strong> Open your Facebook Page &gt; Click <em>About</em> &gt; <em>Page transparency</em> &gt; Copy <em>Page ID</em>.<br>
                    2. <strong>Token:</strong> Go to <em>developers.facebook.com/tools/explorer</em>, select your App &amp; Page, and generate a Page Access Token.
                </div>

                <div style="margin-bottom: 0.875rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">Platform *</label>
                    <select name="platform" id="connectPlatform" required style="width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.825rem;">
                        <option value="facebook_page">Facebook Page</option>
                        <option value="instagram">Instagram Professional Account</option>
                        <option value="linkedin">LinkedIn Company Page</option>
                    </select>
                </div>

                <div style="margin-bottom: 0.875rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">Channel Display Name *</label>
                    <input type="text" name="account_name" placeholder="e.g. Acme Retail Official" required style="width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.825rem; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 0.875rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">Account / Page ID *</label>
                    <input type="text" name="account_id" placeholder="e.g. 100293847562019" required style="width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.825rem; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">Access Token *</label>
                    <input type="password" name="access_token" placeholder="Enter Page Access Token" required style="width: 100%; padding: 0.6rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); color: var(--text-main); font-size: 0.825rem; box-sizing: border-box;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                    <button type="submit" id="btnSaveChannel" class="btn btn-primary" style="padding: 0.55rem 1.1rem; font-size: 0.825rem; font-weight: 600; border-radius: var(--border-radius-sm);">
                        Save Channel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Meta Facebook JS SDK Integration -->
<script>
window.fbAsyncInit = function() {
    FB.init({
        appId      : '<?php echo htmlspecialchars(defined('META_APP_ID') ? META_APP_ID : '2484306222079451'); ?>',
        cookie     : true,
        xfbml      : true,
        version    : 'v20.0'
    });
};
(function(d, s, id){
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {return;}
    js = d.createElement(s); js.id = id;
    js.src = "https://connect.facebook.net/en_US/sdk.js";
    fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));

// 1-Click Facebook Login Handler
function loginWithFacebook() {
    var statusEl = document.getElementById('fbOAuthStatus');
    var btn = document.getElementById('btnFbOAuth');
    if (typeof FB === 'undefined') {
        showSocialAlert('Facebook SDK is loading. Please check your network or try again in a moment.', 'error');
        return;
    }

    btn.disabled = true;
    if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.textContent = 'Connecting to Facebook authorization dialog...';
    }

    FB.login(function(response) {
        btn.disabled = false;
        if (response.authResponse && response.authResponse.accessToken) {
            if (statusEl) statusEl.textContent = 'Authorization granted. Detecting your Facebook & Instagram pages...';
            fetchUserPagesFromMeta(response.authResponse.accessToken);
        } else {
            if (statusEl) statusEl.style.display = 'none';
            showSocialAlert('Facebook authorization was cancelled by the user.', 'error');
        }
    }, {
        scope: 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_metadata,instagram_basic,instagram_content_publish'
    });
}

// Fetch discovered pages from server
function fetchUserPagesFromMeta(userToken) {
    var statusEl = document.getElementById('fbOAuthStatus');
    var container = document.getElementById('discoveredPagesContainer');
    var list = document.getElementById('discoveredPagesList');

    var fd = new FormData();
    fd.append('action', 'fetch_user_pages');
    fd.append('user_access_token', userToken);

    fetch('api/social-api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (statusEl) statusEl.style.display = 'none';
        if (!data.success || !data.pages || data.pages.length === 0) {
            showSocialAlert('No active Facebook Pages found for this account. Ensure you have Admin access to at least one Facebook Page.', 'error');
            return;
        }

        container.style.display = 'block';
        list.innerHTML = '';

        data.pages.forEach(function(p) {
            var itemHtml = document.createElement('div');
            itemHtml.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-app); gap: 0.75rem;';

            var leftCol = document.createElement('div');
            leftCol.style.cssText = 'display: flex; align-items: center; gap: 0.6rem; overflow: hidden;';
            
            var avatar = document.createElement('img');
            avatar.src = p.picture_url || 'assets/image.png';
            avatar.style.cssText = 'width: 34px; height: 34px; border-radius: 50%; object-fit: cover; background: var(--border-color); flex-shrink: 0;';

            var nameGroup = document.createElement('div');
            nameGroup.style.cssText = 'overflow: hidden;';
            nameGroup.innerHTML = '<div style="font-size: 0.825rem; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + escapeHtml(p.page_name) + '</div><div style="font-size: 0.7rem; color: var(--text-muted);">Page ID: ' + escapeHtml(p.page_id) + '</div>';

            leftCol.appendChild(avatar);
            leftCol.appendChild(nameGroup);

            var connectBtn = document.createElement('button');
            connectBtn.type = 'button';
            connectBtn.className = 'btn btn-primary';
            connectBtn.style.cssText = 'padding: 0.4rem 0.85rem; font-size: 0.75rem; font-weight: 600; border-radius: 4px; white-space: nowrap; cursor: pointer;';
            connectBtn.textContent = 'Connect Page';
            connectBtn.onclick = function() {
                connectDiscoveredPage(p.page_id, p.page_name, p.page_access_token, p.picture_url, 'facebook_page', connectBtn);
            };

            itemHtml.appendChild(leftCol);
            itemHtml.appendChild(connectBtn);
            list.appendChild(itemHtml);

            // If Instagram is linked, show a 1-click connect button for Instagram too
            if (p.instagram_account && p.instagram_account.id) {
                var igItem = document.createElement('div');
                igItem.style.cssText = 'display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; border: 1px solid rgba(225, 48, 108, 0.25); border-radius: var(--border-radius-sm); background: rgba(225, 48, 108, 0.04); gap: 0.75rem;';

                var igLeftCol = document.createElement('div');
                igLeftCol.style.cssText = 'display: flex; align-items: center; gap: 0.6rem; overflow: hidden;';
                
                var igAvatar = document.createElement('img');
                igAvatar.src = p.instagram_account.picture_url || 'assets/image.png';
                igAvatar.style.cssText = 'width: 34px; height: 34px; border-radius: 50%; object-fit: cover; background: var(--border-color); flex-shrink: 0;';

                var igNameGroup = document.createElement('div');
                igNameGroup.style.cssText = 'overflow: hidden;';
                igNameGroup.innerHTML = '<div style="font-size: 0.825rem; font-weight: 600; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">@' + escapeHtml(p.instagram_account.username || p.page_name) + ' (Instagram)</div><div style="font-size: 0.7rem; color: var(--text-muted);">IG ID: ' + escapeHtml(p.instagram_account.id) + '</div>';

                igLeftCol.appendChild(igAvatar);
                igLeftCol.appendChild(igNameGroup);

                var igConnectBtn = document.createElement('button');
                igConnectBtn.type = 'button';
                igConnectBtn.className = 'btn';
                igConnectBtn.style.cssText = 'background: #E1306C; color: #fff; padding: 0.4rem 0.85rem; font-size: 0.75rem; font-weight: 600; border-radius: 4px; white-space: nowrap; cursor: pointer; border: none;';
                igConnectBtn.textContent = 'Connect Instagram';
                igConnectBtn.onclick = function() {
                    connectDiscoveredPage(p.instagram_account.id, p.instagram_account.username || p.page_name, p.page_access_token, p.instagram_account.picture_url, 'instagram', igConnectBtn);
                };

                igItem.appendChild(igLeftCol);
                igItem.appendChild(igConnectBtn);
                list.appendChild(igItem);
            }
        });
        if (window.lucide) lucide.createIcons();
    })
    .catch(function(err) {
        if (statusEl) statusEl.style.display = 'none';
        showSocialAlert('Could not retrieve pages: ' + err.message, 'error');
    });
}

// Quick 1-click connect action
function connectDiscoveredPage(id, name, token, picUrl, platform, btn) {
    btn.disabled = true;
    btn.textContent = 'Connecting...';

    var fd = new FormData();
    fd.append('action', 'connect_account');
    fd.append('platform', platform);
    fd.append('account_id', id);
    fd.append('account_name', name);
    fd.append('access_token', token);
    fd.append('profile_picture_url', picUrl || '');

    fetch('api/social-api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Connected';
        btn.style.background = '#10b981';
        if (data.success) {
            showSocialAlert(data.message, 'success');
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            showSocialAlert(data.message || 'Connection failed.', 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Connect';
        showSocialAlert('Connection error: ' + err.message, 'error');
    });
}

// Toggle manual form
function toggleManualSetup() {
    var form = document.getElementById('connectChannelForm');
    var icon = document.getElementById('manualChevronIcon');
    if (!form) return;
    if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
        if (icon) icon.style.transform = 'rotate(180deg)';
    } else {
        form.style.display = 'none';
        if (icon) icon.style.transform = 'rotate(0deg)';
    }
}

// Tab Switching Controller
function switchSocialTab(tabId, el) {
    document.querySelectorAll('.social-tab-pane').forEach(function(pane) {
        pane.style.display = 'none';
    });
    document.querySelectorAll('.social-tabs .tab-btn').forEach(function(btn) {
        btn.classList.remove('active');
        btn.style.borderBottomColor = 'transparent';
        btn.style.color = 'var(--text-muted)';
    });

    var targetPane = document.getElementById('tabContent_' + tabId);
    if (targetPane) {
        targetPane.style.display = 'block';
    }
    if (el) {
        el.classList.add('active');
        el.style.borderBottomColor = 'var(--primary)';
        el.style.color = 'var(--primary)';
    }

    if (tabId === 'feed') {
        loadSocialFeed();
    } else if (tabId === 'ads') {
        loadAdCampaigns();
    }
    if (window.lucide) {
        lucide.createIcons();
    }
}

// Alert Dispatcher
function showSocialAlert(msg, type) {
    var c = document.getElementById('socialAlertContainer');
    if (!c) return;
    c.style.display = 'block';
    if (type === 'success') {
        c.style.background = 'rgba(16, 185, 129, 0.12)';
        c.style.color = '#10b981';
        c.style.border = '1px solid rgba(16, 185, 129, 0.25)';
    } else {
        c.style.background = 'rgba(239, 68, 68, 0.12)';
        c.style.color = '#ef4444';
        c.style.border = '1px solid rgba(239, 68, 68, 0.25)';
    }
    c.textContent = msg;
    setTimeout(function() {
        c.style.display = 'none';
    }, 6000);
}

// Modal Controllers
function openConnectModal() {
    var m = document.getElementById('connectAccountModal');
    if (m) m.style.display = 'flex';
    if (window.lucide) lucide.createIcons();
}
function closeConnectModal() {
    var m = document.getElementById('connectAccountModal');
    if (m) m.style.display = 'none';
}

// Handle Connect Submit
function handleConnectSubmit(e) {
    e.preventDefault();
    var form = e.target;
    var btn = document.getElementById('btnSaveChannel');
    btn.disabled = true;
    btn.textContent = 'Verifying...';

    var fd = new FormData(form);
    fd.append('action', 'connect_account');

    fetch('api/social-api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Save & Verify';
        if (data.success) {
            closeConnectModal();
            showSocialAlert(data.message, 'success');
            setTimeout(function() { window.location.reload(); }, 1000);
        } else {
            showSocialAlert(data.message || 'Failed to connect channel.', 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Save & Verify';
        showSocialAlert('Connection error: ' + err.message, 'error');
    });
}

// Disconnect Account
function disconnectAccount(accId) {
    if (!confirm('Are you sure you want to disconnect this channel?')) return;
    var fd = new FormData();
    fd.append('action', 'disconnect_account');
    fd.append('id', accId);

    fetch('api/social-api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            showSocialAlert(data.message, 'success');
            setTimeout(function() { window.location.reload(); }, 800);
        } else {
            showSocialAlert(data.message || 'Could not disconnect account.', 'error');
        }
    })
    .catch(function(err) {
        showSocialAlert('Network error: ' + err.message, 'error');
    });
}

// Post Submission Handler
function handlePostSubmit(e) {
    e.preventDefault();
    var form = e.target;
    var btn = document.getElementById('btnSubmitPost');
    var btnText = document.getElementById('submitBtnText');
    btn.disabled = true;
    btnText.textContent = 'Publishing...';

    var fd = new FormData(form);
    fd.append('action', 'publish_post');

    fetch('api/social-api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btnText.textContent = 'Publish Post';
        if (data.success) {
            showSocialAlert(data.message, 'success');
            form.reset();
            handleCaptionInput('');
            document.getElementById('previewMediaContainer').innerHTML = '<span>Media Preview Area</span>';
            switchSocialTab('feed', document.querySelectorAll('.social-tabs .tab-btn')[2]);
        } else {
            showSocialAlert(data.message || 'Post publishing failed.', 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btnText.textContent = 'Publish Post';
        showSocialAlert('Publishing error: ' + err.message, 'error');
    });
}

// Dynamic Live Preview Handlers
function handleCaptionInput(val) {
    var p = document.getElementById('previewCaptionText');
    var counter = document.getElementById('captionCharCount');
    if (counter) counter.textContent = val.length + ' / 2,200';
    if (p) {
        p.textContent = val.trim() ? val : 'Your caption text will appear here dynamically as you type...';
    }
}

function handleMediaPreview(input) {
    var container = document.getElementById('previewMediaContainer');
    if (!container) return;
    if (input.files && input.files[0]) {
        var file = input.files[0];
        var reader = new FileReader();
        reader.onload = function(e) {
            if (file.type.indexOf('video') !== -1) {
                container.innerHTML = '<video src="' + e.target.result + '" style="width: 100%; height: 200px; object-fit: cover;" controls></video>';
            } else {
                container.innerHTML = '<img src="' + e.target.result + '" style="width: 100%; height: 200px; object-fit: cover;" alt="Preview">';
            }
        };
        reader.readAsDataURL(file);
    } else {
        container.innerHTML = '<span>Media Preview Area</span>';
    }
}

function updatePostType(type) {
    var btnText = document.getElementById('submitBtnText');
    if (btnText) {
        btnText.textContent = type === 'reel' ? 'Publish Reel' : 'Publish Post';
    }
}

function toggleScheduleDate(show) {
    var el = document.getElementById('scheduleDatePickerGroup');
    var btnText = document.getElementById('submitBtnText');
    if (el) el.style.display = show ? 'block' : 'none';
    if (btnText) {
        btnText.textContent = show ? 'Schedule Post' : 'Publish Post';
    }
}

// Feed Loader
function loadSocialFeed() {
    var container = document.getElementById('feedListContainer');
    if (!container) return;
    container.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.875rem;">Loading recent social feed...</div>';

    fetch('api/social-api.php?action=get_feed')
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success || !data.feed || data.feed.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 2.5rem; color: var(--text-muted); font-size: 0.875rem;">No social posts published or scheduled yet.</div>';
            return;
        }

        var html = '<div style="display: flex; flex-direction: column; gap: 1rem;">';
        data.feed.forEach(function(item) {
            var badgeClass = item.status === 'published' ? 'background: rgba(16,185,129,0.12); color: #10b981;' : 'background: rgba(59,130,246,0.12); color: #3b82f6;';
            html += '<div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card); flex-wrap: wrap; gap: 0.75rem;">';
            html += '  <div style="flex: 1; min-width: 260px;">';
            html += '    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">';
            html += '      <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-main);">' + escapeHtml(item.account_name || 'Social Account') + '</span>';
            html += '      <span style="font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 600; text-transform: uppercase; ' + badgeClass + '">' + escapeHtml(item.status) + '</span>';
            html += '      <span style="font-size: 0.75rem; color: var(--text-muted);">' + escapeHtml(item.published_at || item.created_at) + '</span>';
            html += '    </div>';
            html += '    <div style="font-size: 0.85rem; color: var(--text-main); margin-bottom: 0.5rem; line-height: 1.4;">' + escapeHtml(item.caption ? (item.caption.substring(0, 140) + (item.caption.length > 140 ? '...' : '')) : 'No caption') + '</div>';
            html += '    <div style="display: flex; gap: 1.25rem; font-size: 0.75rem; color: var(--text-muted);">';
            html += '      <span>Likes: <strong>' + item.likes_count + '</strong></span>';
            html += '      <span>Comments: <strong>' + item.comments_count + '</strong></span>';
            html += '      <span>Reach: <strong>' + item.reach + '</strong></span>';
            html += '    </div>';
            html += '  </div>';
            if (item.permalink) {
                html += '  <div>';
                html += '    <a href="' + escapeHtml(item.permalink) + '" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; border: 1px solid var(--border-color); text-decoration: none; border-radius: 4px; color: var(--text-main); display: inline-flex; align-items: center; gap: 0.35rem;">';
                html += '      <span>View on Meta</span>';
                html += '    </a>';
                html += '  </div>';
            }
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
        if (window.lucide) lucide.createIcons();
    })
    .catch(function(err) {
        container.innerHTML = '<div style="text-align: center; padding: 2rem; color: #ef4444; font-size: 0.875rem;">Failed to load feed: ' + escapeHtml(err.message) + '</div>';
    });
}

// Ads Loader
function loadAdCampaigns() {
    var container = document.getElementById('adCampaignsListContainer');
    if (!container) return;
    container.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.85rem;">Loading ad campaigns...</div>';

    fetch('api/social-api.php?action=get_ad_campaigns')
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success || !data.campaigns || data.campaigns.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.85rem;">No ad campaigns created yet. Use the launcher on the left to start your first campaign.</div>';
            return;
        }

        var html = '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
        data.campaigns.forEach(function(c) {
            html += '<div style="padding: 0.875rem; border: 1px solid var(--border-color); border-radius: var(--border-radius-sm); background: var(--bg-card);">';
            html += '  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">';
            html += '    <span style="font-size: 0.875rem; font-weight: 600; color: var(--text-main);">' + escapeHtml(c.campaign_name) + '</span>';
            html += '    <span style="font-size: 0.7rem; font-weight: 600; padding: 2px 6px; border-radius: 4px; background: rgba(16,185,129,0.12); color: #10b981;">' + escapeHtml(c.status) + '</span>';
            html += '  </div>';
            html += '  <div style="display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--text-muted);">';
            html += '    <span>Budget: INR ' + escapeHtml(c.daily_budget_inr) + '/day</span>';
            html += '    <span>Goal: ' + escapeHtml(c.objective) + '</span>';
            html += '    <span>Target: ' + escapeHtml(c.target_city || 'All India') + '</span>';
            html += '  </div>';
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
        if (window.lucide) lucide.createIcons();
    })
    .catch(function(err) {
        container.innerHTML = '<div style="text-align: center; padding: 1.5rem; color: #ef4444; font-size: 0.85rem;">Error loading ads: ' + escapeHtml(err.message) + '</div>';
    });
}

function handleAdSubmit(e) {
    e.preventDefault();
    var form = e.target;
    var btn = document.getElementById('btnLaunchAd');
    btn.disabled = true;

    var fd = new FormData(form);
    fd.append('action', 'create_ad_campaign');

    fetch('api/social-api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        if (data.success) {
            showSocialAlert(data.message, 'success');
            form.reset();
            loadAdCampaigns();
        } else {
            showSocialAlert(data.message || 'Ad creation failed.', 'error');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        showSocialAlert('Error: ' + err.message, 'error');
    });
}

// XSS Sanitizer helper
function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('postAccountId');
    if (sel) {
        sel.addEventListener('change', function() {
            var label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : 'Select Channel';
            var prev = document.getElementById('previewChannelName');
            if (prev) prev.textContent = label;
        });
    }
    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>
