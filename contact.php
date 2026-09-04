<?php
/**
 * Friendly AI Solution - Modern Dedicated Contact & Support Page
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$requested_page = 'contact';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Friendly AI Solution | Marg ERP & WhatsApp Automation</title>
    <meta name="description" content="Get in touch with Friendly AI Solution specialists for Marg ERP 9+ WhatsApp integration, custom demo, and pricing assistance.">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Modern Public SaaS Theme CSS -->
    <link rel="stylesheet" href="assets/css/public_theme.css?v=<?php echo time(); ?>">
</head>
<body>

    <!-- Shared Navigation -->
    <?php require_once __DIR__ . '/includes/public_nav.php'; ?>

    <!-- Contact Page Header -->
    <section class="hero-wrapper" style="padding-bottom: 2rem;">
        <div class="public-container">
            <div class="hero-content">
                <div class="hero-badge-pill">
                    <i data-lucide="headphones" style="width: 15px; height: 15px;"></i>
                    <span>We are here to help</span>
                </div>

                <h1 class="hero-main-title">
                    Let's Talk About Your <span>Marg ERP Automation</span>
                </h1>

                <p class="hero-lead-text">
                    Have questions about WhatsApp Cloud API setup, Marg Cloud VPC, or custom integrations? Our specialists respond within 15 minutes.
                </p>
            </div>
        </div>
    </section>

    <!-- Contact Split Section -->
    <section class="section-spacing" style="padding-top: 2rem;">
        <div class="public-container">
            <div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 3rem; align-items: start;">
                
                <!-- Left: Contact Details Cards -->
                <div>
                    <div class="feature-box" style="margin-bottom: 1.5rem;">
                        <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 1.25rem; color: var(--text-primary);">
                            Direct Hotlines & Support
                        </h3>

                        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                            <!-- Phone -->
                            <div style="display: flex; gap: 1rem; align-items: flex-start;">
                                <div class="metric-icon-box metric-icon-blue"><i data-lucide="phone-call"></i></div>
                                <div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">Sales Hotline</div>
                                    <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">+91 91708 97089</div>
                                    <div style="font-size: 0.78rem; color: #10b981;">Mon - Sat, 9:30 AM to 7:30 PM IST</div>
                                </div>
                            </div>

                            <!-- WhatsApp -->
                            <div style="display: flex; gap: 1rem; align-items: flex-start;">
                                <div class="metric-icon-box metric-icon-green"><i data-lucide="message-circle"></i></div>
                                <div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">WhatsApp Support</div>
                                    <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">+91 91708 97089</div>
                                    <a href="https://wa.me/919170897089" target="_blank" rel="noopener" style="font-size: 0.8rem; color: #2563eb; text-decoration: underline;">
                                        Click here to Chat on WhatsApp →
                                    </a>
                                </div>
                            </div>

                            <!-- Email -->
                            <div style="display: flex; gap: 1rem; align-items: flex-start;">
                                <div class="metric-icon-box metric-icon-purple"><i data-lucide="mail"></i></div>
                                <div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">Official Email</div>
                                    <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-primary);">support@friendlyaisolution.com</div>
                                    <div style="font-size: 0.78rem; color: var(--text-muted);">24/7 Ticket System</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Trust Box -->
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-xl); padding: 1.5rem; display: flex; align-items: center; gap: 1rem;">
                        <i data-lucide="clock" style="color: #059669; width: 32px; height: 32px; flex-shrink: 0;"></i>
                        <div>
                            <h4 style="font-weight: 700; color: #065f46; font-size: 0.95rem;">15-Minute Response SLA</h4>
                            <p style="font-size: 0.82rem; color: #047857; line-height: 1.4;">
                                We value your time. All submitted inquiries are directly dispatched to our certified Marg technical engineers.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Right: Interactive Contact Form -->
                <div class="mockup-panel" style="background: #ffffff; box-shadow: var(--shadow-lg); padding: 2.5rem; border-radius: var(--radius-xl);">
                    <h3 style="font-size: 1.5rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.5rem;">
                        Send Us a Message
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1.75rem;">
                        Fill out the details below and we will contact you immediately with personalized guidance.
                    </p>

                    <div id="contactPageAlert" style="display: none; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.88rem;"></div>

                    <form id="contactPageForm" onsubmit="submitContactPageForm(event)">
                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label">Your Name <span class="req">*</span></label>
                                <input type="text" id="contact_name" required placeholder="Full Name" class="form-input">
                            </div>
                            <div class="form-group">
                                <label class="form-label">WhatsApp Mobile <span class="req">*</span></label>
                                <input type="tel" id="contact_phone" required placeholder="10-digit mobile" class="form-input">
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label">Firm / Pharmacy Name <span class="req">*</span></label>
                                <input type="text" id="contact_company" required placeholder="Company Name" class="form-input">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address <span class="req">*</span></label>
                                <input type="email" id="contact_email" required placeholder="name@company.com" class="form-input">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Interested Products:</label>
                            <div class="checkbox-options-grid">
                                <label class="checkbox-card">
                                    <input type="checkbox" name="contact_products" value="Marg ERP 9+ WhatsApp Automation" checked>
                                    <span>Marg ERP 9+ WhatsApp</span>
                                </label>
                                <label class="checkbox-card">
                                    <input type="checkbox" name="contact_products" value="Automated Payment UPI Reminders">
                                    <span>Payment Reminders</span>
                                </label>
                                <label class="checkbox-card">
                                    <input type="checkbox" name="contact_products" value="Marg Cloud NVMe VPC">
                                    <span>Marg Cloud VPC</span>
                                </label>
                                <label class="checkbox-card">
                                    <input type="checkbox" name="contact_products" value="Meta Green Tick API">
                                    <span>Meta Green Tick API</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">How can we assist you? (Optional)</label>
                            <textarea id="contact_remarks" rows="3" placeholder="Tell us about your Marg ERP version, number of counters, or specific requirements..." class="form-input"></textarea>
                        </div>

                        <button type="submit" id="btnSubmitContactPage" class="btn-brand btn-brand-primary w-full" style="padding: 0.9rem; font-size: 0.95rem; justify-content: center; width: 100%;">
                            <span>Submit Message →</span>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </section>

    <!-- Shared Footer -->
    <?php require_once __DIR__ . '/includes/public_footer.php'; ?>

    <script>
        async function submitContactPageForm(event) {
            event.preventDefault();
            const btn = document.getElementById('btnSubmitContactPage');
            const alertBox = document.getElementById('contactPageAlert');
            btn.disabled = true;
            btn.innerHTML = '<span>Sending Message...</span>';

            const checkedProducts = [];
            document.querySelectorAll('input[name="contact_products"]:checked').forEach(cb => {
                checkedProducts.push(cb.value);
            });

            const payload = {
                name: document.getElementById('contact_name').value.trim(),
                phone: document.getElementById('contact_phone').value.trim(),
                company: document.getElementById('contact_company').value.trim(),
                email: document.getElementById('contact_email').value.trim(),
                products: checkedProducts,
                remarks: document.getElementById('contact_remarks').value.trim()
            };

            try {
                const response = await fetch('api/submit_lead.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const res = await response.json();

                if (res.success) {
                    alertBox.style.display = 'block';
                    alertBox.style.background = 'rgba(16, 185, 129, 0.15)';
                    alertBox.style.border = '1px solid #10b981';
                    alertBox.style.color = '#059669';
                    alertBox.textContent = '🎉 Thank you! ' + (res.message || 'Our team will contact you in 15 minutes.');
                    document.getElementById('contactPageForm').reset();
                } else {
                    alertBox.style.display = 'block';
                    alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                    alertBox.style.border = '1px solid #ef4444';
                    alertBox.style.color = '#dc2626';
                    alertBox.textContent = res.message || 'Could not send message.';
                }
            } catch (err) {
                alertBox.style.display = 'block';
                alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                alertBox.style.border = '1px solid #ef4444';
                alertBox.style.color = '#dc2626';
                alertBox.textContent = 'Connection error. Please call +91 91708 97089 directly.';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Submit Message →</span>';
            }
        }
    </script>
</body>
</html>
