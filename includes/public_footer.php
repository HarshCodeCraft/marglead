<?php
/**
 * Shared Modern Public Footer & Lead Capture Modal - Friendly AI Solution
 */
?>
<!-- Public Modern Footer -->
<footer class="public-footer">
    <div class="public-container">
        <div class="footer-grid">
            <!-- Col 1: Brand Info -->
            <div class="footer-col brand-col">
                <a href="index.php" class="brand-logo footer-logo mb-4">
                    <div class="brand-logo-icon">
                        <i data-lucide="layers"></i>
                    </div>
                    <div class="brand-logo-text">
                        <span class="brand-title">FRIENDLY AI</span>
                        <span class="brand-subtitle">SOLUTION</span>
                    </div>
                </a>
                <p class="footer-desc">
                    India's leading intelligent SaaS suite for Marg ERP 9+ integration, Meta WhatsApp Cloud API automation, and smart payment recovery. Empowering over 50,000+ modern retailers, distributors & pharma enterprises.
                </p>
                <div class="footer-trust-badges">
                    <div class="trust-badge">
                        <i data-lucide="shield-check" style="color: #10b981;"></i>
                        <span>Meta Tech Partner Verified</span>
                    </div>
                    <div class="trust-badge">
                        <i data-lucide="lock" style="color: #2563eb;"></i>
                        <span>256-Bit SSL Cloud Encrypted</span>
                    </div>
                </div>
            </div>

            <!-- Col 2: Solutions & Modules -->
            <div class="footer-col">
                <h4 class="footer-heading">Solutions</h4>
                <ul class="footer-links">
                    <li><a href="index.php?page=features#marg-sync"><i data-lucide="chevron-right"></i> Marg ERP 9+ Direct Sync</a></li>
                    <li><a href="index.php?page=whatsapp"><i data-lucide="chevron-right"></i> WhatsApp Cloud API Suite</a></li>
                    <li><a href="index.php?page=features#payment-recovery"><i data-lucide="chevron-right"></i> Auto Payment & UPI Reminders</a></li>
                    <li><a href="index.php?page=features#cloud-vpc"><i data-lucide="chevron-right"></i> Marg Cloud NVMe VPC</a></li>
                    <li><a href="index.php?page=features#sales-pipeline"><i data-lucide="chevron-right"></i> Real-Time Sales CRM Pipeline</a></li>
                </ul>
            </div>

            <!-- Col 3: Quick Navigation -->
            <div class="footer-col">
                <h4 class="footer-heading">Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="index.php"><i data-lucide="chevron-right"></i> Home Overview</a></li>
                    <li><a href="index.php?page=pricing"><i data-lucide="chevron-right"></i> Plans & Pricing</a></li>
                    <li><a href="index.php?page=contact"><i data-lucide="chevron-right"></i> Contact & Helpdesk</a></li>
                    <li><a href="index.php?page=kyc"><i data-lucide="chevron-right"></i> Customer KYC Form</a></li>
                    <li><a href="auth/login.php"><i data-lucide="chevron-right"></i> Client Portal Login</a></li>
                    <li><a href="javascript:void(0)" onclick="openRateUsModal()"><i data-lucide="star" style="color: #f59e0b;"></i> Submit Review / Rate Us</a></li>
                </ul>
            </div>

            <!-- Col 4: Legal & Contact -->
            <div class="footer-col">
                <h4 class="footer-heading">Compliance & Support</h4>
                <ul class="footer-links">
                    <li><a href="index.php?page=privacy"><i data-lucide="chevron-right"></i> Privacy Policy</a></li>
                    <li><a href="index.php?page=terms"><i data-lucide="chevron-right"></i> Terms & Conditions</a></li>
                    <li><a href="index.php?page=refund"><i data-lucide="chevron-right"></i> Refund & Cancellation</a></li>
                </ul>
                <div class="footer-contact-box mt-4">
                    <div class="contact-line">
                        <i data-lucide="mail"></i>
                        <span>support@friendlyaisolution.com</span>
                    </div>
                    <div class="contact-line">
                        <i data-lucide="phone-call"></i>
                        <span>+91 91708 97089 (Sales Hotline)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Bottom Bar -->
        <div class="footer-bottom">
            <div class="copyright-text">
                &copy; <?php echo date('Y'); ?> <strong>Friendly AI Solution</strong>. All Rights Reserved. Built for speed, reliability & enterprise compliance.
            </div>
            <div class="footer-social-links">
                <a href="https://friendlyaisolution.com" class="social-btn" title="Official Website" target="_blank" rel="noopener"><i data-lucide="globe"></i></a>
                <a href="https://wa.me/919170897089" class="social-btn" title="Chat on WhatsApp" target="_blank" rel="noopener"><i data-lucide="message-circle"></i></a>
                <a href="mailto:support@friendlyaisolution.com" class="social-btn" title="Email Us"><i data-lucide="mail"></i></a>
            </div>
        </div>
    </div>
</footer>

<!-- Lead Capture / Book a Demo Modal -->
<div id="publicLeadModal" class="lead-modal-overlay" style="display: none;">
    <div class="lead-modal-container">
        <button class="lead-modal-close" onclick="closeLeadModal()" aria-label="Close modal">&times;</button>
        
        <div class="lead-modal-header">
            <div class="modal-badge-pill">
                <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i>
                <span>Fast 15-Minute Response</span>
            </div>
            <h3 class="modal-title">Get In Touch with Friendly AI Solution</h3>
            <p class="modal-subtitle">Connect with our Marg ERP & WhatsApp Cloud API specialists for a free live demo and custom quote.</p>
        </div>

        <div id="leadModalAlert" style="display: none; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem;"></div>

        <form id="publicLeadForm" onsubmit="submitLeadModalForm(event)">
            <input type="hidden" id="lead_source_page" name="source_page" value="<?php echo htmlspecialchars($current_page); ?>">
            
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Full Name <span class="req">*</span></label>
                    <input type="text" id="modal_lead_name" name="name" required placeholder="e.g. Rahul Sharma" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">WhatsApp Mobile <span class="req">*</span></label>
                    <input type="tel" id="modal_lead_phone" name="phone" required placeholder="e.g. 9876543210" class="form-input">
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Business / Firm Name <span class="req">*</span></label>
                    <input type="text" id="modal_lead_company" name="company" required placeholder="e.g. Apex Pharma & Distributors" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Work Email <span class="req">*</span></label>
                    <input type="email" id="modal_lead_email" name="email" required placeholder="name@company.com" class="form-input">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">I am interested in (Select all that apply):</label>
                <div class="checkbox-options-grid">
                    <label class="checkbox-card">
                        <input type="checkbox" name="product_interest" value="Marg ERP 9+ WhatsApp Automation" checked>
                        <span>WhatsApp Bill & Ledger PDF</span>
                    </label>
                    <label class="checkbox-card">
                        <input type="checkbox" name="product_interest" value="Automated Payment UPI Reminders">
                        <span>Auto Payment Reminders</span>
                    </label>
                    <label class="checkbox-card">
                        <input type="checkbox" name="product_interest" value="Marg Cloud VPC Remote Access">
                        <span>Marg Cloud NVMe VPC</span>
                    </label>
                    <label class="checkbox-card">
                        <input type="checkbox" name="product_interest" value="Meta Official Green Tick API">
                        <span>Meta Official Green Tick</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Specific Requirement / Notes (Optional)</label>
                <textarea id="modal_lead_remarks" name="remarks" rows="2" placeholder="Tell us about your current Marg ERP version, number of counters or any custom workflows..." class="form-input"></textarea>
            </div>

            <button type="submit" id="btnSubmitLeadModal" class="btn-brand btn-brand-primary w-full" style="padding: 0.9rem; font-size: 0.95rem; justify-content: center; width: 100%;">
                <span>Request Live Demo & Quote →</span>
            </button>
        </form>
    </div>
</div>

<!-- Modal: Public "Rate Us / Submit Review" -->
<div id="rateUsModal" class="lead-modal-overlay" style="display: none;">
    <div class="lead-modal-container" style="max-width: 520px;">
        <button class="lead-modal-close" onclick="closeRateUsModal()" aria-label="Close modal">&times;</button>
        
        <div class="lead-modal-header" style="text-align: center; margin-bottom: 1.25rem;">
            <div class="brand-logo-icon" style="margin: 0 auto 0.75rem auto; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                <i data-lucide="star" style="width: 22px; height: 22px; fill: #ffffff; color: #ffffff;"></i>
            </div>
            <h3 class="modal-title">Rate Our Services</h3>
            <p class="modal-subtitle">Share your honest rating and feedback with Friendly AI Solution.</p>
        </div>

        <div id="rateFormAlert" style="display: none; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem;"></div>

        <form id="rateUsForm" onsubmit="submitRateUsForm(event)">
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Your Name <span class="req">*</span></label>
                    <input type="text" name="name" id="rate_name" required placeholder="Full Name" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Company / Firm Name</label>
                    <input type="text" name="company" id="rate_company" placeholder="e.g. Gantavya Pharmacy" class="form-input">
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">City / State</label>
                    <input type="text" name="city" id="rate_city" placeholder="e.g. Kanpur, UP" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Rating (Stars) <span class="req">*</span></label>
                    <select name="rating" id="rate_stars" required class="form-input">
                        <option value="5.0" selected>5.0 ★★★★★ (Outstanding)</option>
                        <option value="4.9">4.9 ★★★★★ (Superb)</option>
                        <option value="4.5">4.5 ★★★★☆ (Very Good)</option>
                        <option value="4.0">4.0 ★★★★☆ (Good)</option>
                        <option value="3.0">3.0 ★★★☆☆ (Average)</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Product / Service Used</label>
                <select name="service_name" id="rate_service" class="form-input">
                    <option value="Marg ERP 9+ WhatsApp Automation">Marg ERP 9+ WhatsApp Automation</option>
                    <option value="Marg Books & Cloud CRM">Marg Books & Cloud CRM</option>
                    <option value="Marg Cloud NVMe VPC">Marg Cloud NVMe VPC</option>
                    <option value="WhatsApp Payment Reminders">WhatsApp Payment Reminders</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Your Detailed Review <span class="req">*</span></label>
                <textarea name="review_text" id="rate_text" rows="3" required placeholder="Write your experience with Friendly AI Solution software and technical support..." class="form-input"></textarea>
            </div>

            <button type="submit" id="btnSubmitRate" class="btn-brand btn-brand-primary w-full" style="padding: 0.85rem; font-size: 0.95rem; justify-content: center; width: 100%;">
                <span>Submit Rating & Review →</span>
            </button>
        </form>
    </div>
</div>

<!-- Core Public Interactive Scripts -->
<script>
    // Initialize Lucide Icons
    document.addEventListener("DOMContentLoaded", function() {
        if (window.lucide) {
            lucide.createIcons();
        }
    });

    // Mobile Navigation Toggle
    function toggleMobileNav() {
        const drawer = document.getElementById('mobileNavDrawer');
        const icon = document.getElementById('mobileNavIcon');
        if (drawer) {
            drawer.classList.toggle('open');
        }
    }

    // Lead Capture Modal
    function openLeadModal(prefSource = '') {
        const modal = document.getElementById('publicLeadModal');
        if (modal) {
            modal.style.display = 'flex';
            if (prefSource && document.getElementById('modal_lead_remarks')) {
                const cur = document.getElementById('modal_lead_remarks').value;
                if (!cur.includes(prefSource)) {
                    document.getElementById('modal_lead_remarks').placeholder = 'Requirement notes (' + prefSource + ')...';
                }
            }
        }
        if (window.lucide) {
            lucide.createIcons();
        }
    }

    function closeLeadModal() {
        const modal = document.getElementById('publicLeadModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Lead Modal Form Submission via API
    async function submitLeadModalForm(event) {
        event.preventDefault();
        const btn = document.getElementById('btnSubmitLeadModal');
        const alertBox = document.getElementById('leadModalAlert');
        btn.disabled = true;
        btn.innerHTML = '<span>Submitting Inquiry...</span>';

        const checkedProducts = [];
        document.querySelectorAll('input[name="product_interest"]:checked').forEach(cb => {
            checkedProducts.push(cb.value);
        });

        const payload = {
            name: document.getElementById('modal_lead_name').value.trim(),
            phone: document.getElementById('modal_lead_phone').value.trim(),
            company: document.getElementById('modal_lead_company').value.trim(),
            email: document.getElementById('modal_lead_email').value.trim(),
            products: checkedProducts,
            remarks: document.getElementById('modal_lead_remarks').value.trim()
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
                alertBox.textContent = '🎉 Thank you! ' + (res.message || 'Our Friendly AI Solution product team will reach out in 15 minutes.');

                document.getElementById('publicLeadForm').reset();
                setTimeout(() => {
                    closeLeadModal();
                    alertBox.style.display = 'none';
                }, 3000);
            } else {
                alertBox.style.display = 'block';
                alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                alertBox.style.border = '1px solid #ef4444';
                alertBox.style.color = '#dc2626';
                alertBox.textContent = res.message || 'Could not submit inquiry. Please check the inputs.';
            }
        } catch (err) {
            alertBox.style.display = 'block';
            alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
            alertBox.style.border = '1px solid #ef4444';
            alertBox.style.color = '#dc2626';
            alertBox.textContent = 'Connection error. Please call us directly at +91 91708 97089.';
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span>Request Live Demo & Quote →</span>';
        }
    }

    // Rate Us Modal Handlers
    function openRateUsModal() {
        const modal = document.getElementById('rateUsModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }

    function closeRateUsModal() {
        const modal = document.getElementById('rateUsModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    async function submitRateUsForm(event) {
        event.preventDefault();
        const btn = document.getElementById('btnSubmitRate');
        const alertBox = document.getElementById('rateFormAlert');
        btn.disabled = true;
        btn.innerHTML = '<span>Submitting Rating...</span>';

        const payload = {
            name: document.getElementById('rate_name').value.trim(),
            company: document.getElementById('rate_company').value.trim(),
            city: document.getElementById('rate_city').value.trim(),
            rating: parseFloat(document.getElementById('rate_stars').value),
            service_name: document.getElementById('rate_service').value,
            review_text: document.getElementById('rate_text').value.trim()
        };

        try {
            const response = await fetch('api/submit_review.php', {
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
                alertBox.textContent = res.message || 'Rating submitted successfully!';

                document.getElementById('rateUsForm').reset();
                setTimeout(() => {
                    closeRateUsModal();
                    alertBox.style.display = 'none';
                }, 2500);
            } else {
                alertBox.style.display = 'block';
                alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                alertBox.style.border = '1px solid #ef4444';
                alertBox.style.color = '#dc2626';
                alertBox.textContent = res.message || 'Failed to submit review.';
            }
        } catch (err) {
            alertBox.style.display = 'block';
            alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
            alertBox.style.border = '1px solid #ef4444';
            alertBox.style.color = '#dc2626';
            alertBox.textContent = 'Server communication error. Please try again.';
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span>Submit Rating & Review →</span>';
        }
    }
</script>
