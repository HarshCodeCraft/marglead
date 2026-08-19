<?php
/**
 * Marg Soft Solutions - Public Landing Page & SaaS Portal
 * 
 * Matches exact dark-mode glassmorphism design with CTA hero card, Pricing, FAQs,
 * WhatsApp Cloud API showcases, and full footer navigation.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_logged_in = isset($_SESSION['user_id']);
$user_name = $_SESSION['user_name'] ?? 'User';

// Fetch approved customer reviews for continuous marquee ratings section
$public_reviews = [];
if ($db_connected && $pdo) {
    try {
        $stmtR = $pdo->query("SELECT * FROM customer_reviews WHERE status = 'Approved' ORDER BY id DESC LIMIT 10");
        $public_reviews = $stmtR->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        $public_reviews = [];
    }
}
if (empty($public_reviews)) {
    $public_reviews = [
        ['name' => 'Rajesh Sharma', 'company' => 'Gantavya Pharmacy', 'city' => 'Kanpur, UP', 'rating' => 5.0, 'review_text' => 'Marg ERP 9+ is the backbone of our 4-counter pharma shop. Outstanding billing speed, auto GST return filing, and instant WhatsApp bill delivery to patients!', 'service_name' => 'Marg ERP 9+', 'source' => 'Google Verified'],
        ['name' => 'Dr. Satish Verma', 'company' => 'Verma Diagnostic Clinic', 'city' => 'Mumbai, MH', 'rating' => 5.0, 'review_text' => 'Switched to Marg Books & Cloud CRM last year. Multi-user billing and inventory tracking work smoothly anytime from mobile phone. Excellent priority support team!', 'service_name' => 'Marg Books & Cloud', 'source' => 'Google Verified'],
        ['name' => 'Vikram Patel', 'company' => 'Metro Chemicals & Co.', 'city' => 'Ahmedabad, GJ', 'rating' => 4.9, 'review_text' => 'Automated payment reminders on WhatsApp saved us lakhs of rupees in overdue market receivables. Highly recommended software suite for stockists.', 'service_name' => 'WhatsApp CRM', 'source' => 'Google Verified'],
        ['name' => 'Amit S. Malhotra', 'company' => 'Apex Pharma Solutions', 'city' => 'New Delhi, DL', 'rating' => 5.0, 'review_text' => 'Best ERP solution in India for pharma distributors. Dedicated account manager and 24/7 technical help team are extremely fast and supportive.', 'service_name' => 'Marg Gold Edition', 'source' => 'Google Verified'],
        ['name' => 'Sanjay Singhal', 'company' => 'Singhal Steels Pvt Ltd', 'city' => 'Ludhiana, PB', 'rating' => 5.0, 'review_text' => 'Hosting Marg ERP 9+ on Marg Cloud VPC gives us 100% data security and remote access across 5 branch offices with top NVMe speed.', 'service_name' => 'Marg Cloud VPC', 'source' => 'Google Verified']
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marg Soft Solutions - India's #1 Marg ERP 9+ Integrated CRM & WhatsApp Cloud API</title>
    <meta name="description" content="Automate Marg ERP 9+ Bills, Invoices & Outstanding Reminders directly from your official WhatsApp Business number. Join 50,000+ growing businesses.">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        :root {
            --bg-dark: #0b0f19;
            --bg-app: #0b0f19;
            --bg-card: #121826;
            --bg-card-glass: rgba(18, 24, 38, 0.85);
            --border-color: #1e293b;
            --border-card: #1f293d;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --primary-gradient: linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%);
            --accent-purple: #7c3aed;
            --accent-cyan: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --text-sidebar: #64748b;
            --font-heading: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-body: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(at 10% 10%, rgba(59, 130, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 90% 90%, rgba(6, 182, 212, 0.12) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(124, 58, 237, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            font-family: var(--font-body);
            line-height: 1.6;
            overflow-x: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* Container */
        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        /* Navbar */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(18, 24, 38, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 0;
        }

        .navbar-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 1.25rem;
            letter-spacing: -0.02em;
            color: #ffffff;
        }

        .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }

        .nav-link {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .nav-link:hover {
            color: #ffffff;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1.35rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            border: 1px solid transparent;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-main);
            border-color: var(--border-color);
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }

        .btn-cyan {
            background: #00b4d8;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(0, 180, 216, 0.3);
        }

        .btn-cyan:hover {
            background: #0096c7;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 180, 216, 0.4);
        }

        /* Hero Section */
        .hero {
            padding: 5rem 0 4rem 0;
            text-align: center;
            position: relative;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 1rem;
            border-radius: 9999px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.25);
            color: #60a5fa;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .hero-title {
            font-family: var(--font-heading);
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.03em;
            margin-bottom: 1.5rem;
            color: #ffffff;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-title span {
            background: linear-gradient(135deg, #60a5fa 0%, #38bdf8 50%, #22d3ee 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            font-size: 1.125rem;
            color: var(--text-muted);
            max-width: 680px;
            margin: 0 auto 2.5rem auto;
            line-height: 1.7;
        }

        .hero-cta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 4rem;
        }

        /* Hero Preview Screen Mockup */
        .preview-card {
            background: rgba(18, 24, 38, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6), 0 0 40px rgba(59, 130, 246, 0.15);
            backdrop-filter: blur(20px);
            margin: 0 auto;
            max-width: 1050px;
            text-align: left;
        }

        .mockup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .dots {
            display: flex;
            gap: 0.5rem;
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .dot-red { background: #ef4444; }
        .dot-yellow { background: #f59e0b; }
        .dot-green { background: #10b981; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-top: 3rem;
            text-align: center;
        }

        .stat-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .stat-number {
            font-family: var(--font-heading);
            font-size: 2rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Features Section */
        .section {
            padding: 6rem 0;
        }

        .section-title {
            text-align: center;
            font-family: var(--font-heading);
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: #ffffff;
        }

        .section-subtitle {
            text-align: center;
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto 3.5rem auto;
            font-size: 1rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
        }

        .feature-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            border-color: rgba(59, 130, 246, 0.4);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
        }

        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: rgba(59, 130, 246, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #60a5fa;
            margin-bottom: 1.25rem;
        }

        .feature-title {
            font-family: var(--font-heading);
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: #ffffff;
        }

        .feature-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* Pricing Section */
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.75rem;
            align-items: stretch;
        }

        #pricing_erp.pricing-grid {
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
        }

        .pricing-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2.25rem 1.5rem 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: visible !important;
        }

        .pricing-card.featured {
            border-color: var(--accent-cyan);
            background: linear-gradient(180deg, rgba(6, 182, 212, 0.08) 0%, rgba(18, 24, 38, 0.9) 100%);
            box-shadow: 0 20px 40px rgba(6, 182, 212, 0.15);
        }

        .featured-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: #00b4d8;
            color: #ffffff;
            font-size: 0.725rem;
            font-weight: 800;
            padding: 4px 16px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            z-index: 10;
            white-space: nowrap;
            box-shadow: 0 4px 12px rgba(0, 180, 216, 0.4);
        }

        .plan-name {
            font-family: var(--font-heading);
            font-size: 1.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 0.5rem;
        }

        .plan-price {
            font-family: var(--font-heading);
            font-size: 2.5rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 1.5rem;
        }

        .plan-price span {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .plan-arc {
            font-size: 0.825rem;
            color: var(--accent-cyan);
            font-weight: 600;
            margin-top: -1rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(6, 182, 212, 0.08);
            border: 1px solid rgba(6, 182, 212, 0.2);
            padding: 4px 10px;
            border-radius: 8px;
            width: fit-content;
        }

        .plan-features {
            list-style: none;
            padding: 0;
            margin-bottom: 2rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .plan-feature-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        /* Pricing Product Radio Tabs */
        .pricing-tabs {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            margin: 2.5rem auto 3rem auto;
            background: rgba(18, 24, 38, 0.8);
            border: 1px solid var(--border-color);
            padding: 8px;
            border-radius: 9999px;
            max-width: fit-content;
            flex-wrap: wrap;
            box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(12px);
        }

        .pricing-tab-label {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 10px 24px;
            border-radius: 9999px;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
            border: 1px solid transparent;
        }

        .pricing-tab-label input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid var(--text-muted);
            border-radius: 50%;
            outline: none;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
            margin: 0;
            flex-shrink: 0;
        }

        .pricing-tab-label input[type="radio"]:checked {
            border-color: var(--accent-cyan);
            background: var(--accent-cyan);
            box-shadow: 0 0 12px rgba(6, 182, 212, 0.6);
        }

        .pricing-tab-label input[type="radio"]:checked::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 6px;
            height: 6px;
            background: #080c14;
            border-radius: 50%;
        }

        .pricing-tab-label.active,
        .pricing-tab-label:has(input[type="radio"]:checked) {
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.22) 0%, rgba(59, 130, 246, 0.22) 100%);
            color: #ffffff;
            border-color: var(--accent-cyan);
            box-shadow: 0 4px 20px rgba(6, 182, 212, 0.2);
        }

        .pricing-tab-label:hover:not(.active) {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }

        .pricing-tab-content {
            display: none;
            animation: fadeInGrid 0.35s ease-out forwards;
        }

        .pricing-tab-content.active {
            display: grid;
        }

        @keyframes fadeInGrid {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* FAQ Section */
        .faq-accordion {
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* Continuous Running Marquee Carousel for Customer Ratings */
        .ratings-marquee-wrapper {
            position: relative;
            overflow: hidden;
            width: 100%;
            padding: 1.5rem 0;
            mask-image: linear-gradient(to right, transparent 0%, black 10%, black 90%, transparent 100%);
            -webkit-mask-image: linear-gradient(to right, transparent 0%, black 10%, black 90%, transparent 100%);
        }

        .ratings-marquee-track {
            display: flex;
            gap: 1.5rem;
            width: max-content;
            animation: marqueeScroll 35s linear infinite;
        }

        .ratings-marquee-wrapper:hover .ratings-marquee-track {
            animation-play-state: paused;
        }

        @keyframes marqueeScroll {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .rating-card {
            width: min(380px, 85vw);
            flex-shrink: 0;
            background: rgba(18, 24, 38, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4), 0 0 20px rgba(6, 182, 212, 0.05);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .rating-card:hover {
            border-color: var(--accent-cyan);
            transform: translateY(-6px) scale(1.01);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.6), 0 0 25px rgba(6, 182, 212, 0.2);
        }

        .faq-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .faq-question {
            width: 100%;
            padding: 1.25rem 1.5rem;
            background: transparent;
            border: none;
            color: #ffffff;
            font-family: var(--font-body);
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            text-align: left;
        }

        .faq-answer {
            padding: 0 1.5rem 1.25rem 1.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.6;
            display: none;
        }

        .faq-item.active .faq-answer {
            display: block;
        }

        /* CTA Hero Card (Exact Match to User Screenshot) */
        .cta-banner-card {
            background: linear-gradient(135deg, #1d4ed8 0%, #0284c7 50%, #06b6d4 100%);
            border-radius: 24px;
            padding: 4.5rem 2rem;
            text-align: center;
            box-shadow: 0 25px 60px rgba(2, 132, 199, 0.3);
            margin: 4rem auto;
            max-width: 1000px;
            position: relative;
            overflow: hidden;
        }

        .cta-banner-title {
            font-family: var(--font-heading);
            font-size: 2.75rem;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .cta-banner-subtitle {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
            max-width: 540px;
            margin: 0 auto 2rem auto;
            line-height: 1.6;
        }

        .cta-banner-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
        }

        /* Footer (Exact Match to User Screenshot) */
        .footer {
            border-top: 1px solid var(--border-color);
            padding: 4rem 0 2rem 0;
            background: #060911;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 3rem;
            margin-bottom: 4rem;
        }

        .footer-brand {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .footer-brand p {
            font-size: 0.875rem;
            color: var(--text-muted);
            max-width: 320px;
            line-height: 1.6;
        }

        .newsletter-form {
            display: flex;
            gap: 0.5rem;
            max-width: 340px;
            margin-top: 0.5rem;
        }

        .newsletter-input {
            flex: 1;
            background: rgba(18, 24, 38, 0.8);
            border: 1px solid var(--border-color);
            border-radius: 9999px;
            padding: 0.6rem 1.25rem;
            font-size: 0.85rem;
            color: #ffffff;
            outline: none;
        }

        .newsletter-input:focus {
            border-color: var(--primary);
        }

        .footer-col-title {
            font-family: var(--font-heading);
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #ffffff;
            margin-bottom: 1.25rem;
        }

        .footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .footer-link {
            font-size: 0.875rem;
            color: var(--text-muted);
            transition: color 0.2s ease;
        }

        .footer-link:hover {
            color: #ffffff;
        }

        .footer-bottom {
            border-top: 1px solid var(--border-color);
            padding-top: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .social-links {
            display: flex;
            gap: 1rem;
        }

        .social-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            transition: all 0.2s ease;
        }

        .social-icon:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        /* Lead Capture Modal Styling */
        .lead-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(4, 6, 11, 0.88);
            backdrop-filter: blur(16px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            animation: fadeInModal 0.25s ease-out forwards;
        }

        .lead-modal-container {
            background: rgba(18, 24, 38, 0.95);
            border: 1px solid var(--border-color);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8), 0 0 30px rgba(6, 182, 212, 0.15);
            border-radius: 24px;
            width: 100%;
            max-width: 540px;
            padding: 2rem;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .lead-modal-close {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 34px;
            height: 34px;
            border-radius: 50%;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .lead-modal-close:hover {
            color: #ffffff;
            background: rgba(239, 68, 68, 0.2);
            border-color: #ef4444;
        }

        @keyframes fadeInModal {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        /* Mouse Cursor Spotlight & Card Interactive Glow (Landing Page Only) */
        #mouse-spotlight {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            pointer-events: none;
            z-index: 2;
            background: radial-gradient(600px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(6, 182, 212, 0.14), rgba(59, 130, 246, 0.05) 40%, transparent 80%);
            transition: opacity 0.3s ease;
        }

        /* Mouse Hover Dynamic Spotlight on Cards */
        .feature-card, .showcase-card, .hero-cta-card, .faq-item, .stat-item {
            position: relative;
            overflow: hidden;
            transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 0.3s ease, border-color 0.3s ease;
            will-change: transform;
        }

        .pricing-card {
            position: relative;
            overflow: visible !important;
            transition: transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 0.3s ease, border-color 0.3s ease;
            will-change: transform;
        }

        .feature-card::after, .pricing-card::after, .showcase-card::after, .stat-item::after {
            content: '';
            position: absolute;
            top: var(--card-y, -100%);
            left: var(--card-x, -100%);
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.22) 0%, rgba(59, 130, 246, 0.08) 50%, transparent 70%);
            transform: translate(-50%, -50%);
            pointer-events: none;
            border-radius: 50%;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover::after, .pricing-card:hover::after, .showcase-card:hover::after, .stat-item:hover::after {
            opacity: 1;
        }

        .feature-card:hover, .showcase-card:hover, .stat-item:hover {
            border-color: rgba(6, 182, 212, 0.4);
            box-shadow: 0 12px 30px rgba(6, 182, 212, 0.15);
        }

        /* Mobile Hamburger Button */
        .mobile-menu-btn {
            display: none;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            border-radius: 10px;
            padding: 8px 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--accent-cyan);
        }

        /* Comprehensive Multi-Device Responsiveness */
        @media (max-width: 1200px) {
            #pricing_erp.pricing-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
            .pricing-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1.5rem;
            }
            .hero-title {
                font-size: 2.75rem;
            }
        }

        @media (max-width: 900px) {
            .pricing-grid, #pricing_erp.pricing-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .nav-links {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: rgba(18, 24, 38, 0.98);
                backdrop-filter: blur(20px);
                flex-direction: column;
                padding: 1.5rem;
                border-bottom: 1px solid var(--border-color);
                gap: 1.25rem;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.8);
            }

            .nav-links.active {
                display: flex;
            }

            .hero-title {
                font-size: 2.2rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }

            .hero-actions {
                flex-direction: column;
                width: 100%;
            }

            .hero-actions .btn {
                width: 100%;
            }

            .pricing-tabs {
                max-width: 100%;
                overflow-x: auto;
                justify-content: flex-start;
                padding: 6px;
                border-radius: 20px;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .pricing-tab-label {
                padding: 8px 16px;
                font-size: 0.85rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .footer-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .cta-banner-title {
                font-size: 1.85rem;
            }

            .section {
                padding: 3.5rem 0;
            }

            .pricing-grid, #pricing_erp.pricing-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0 1rem;
            }

            .hero {
                padding: 3rem 0 2.5rem 0;
            }

            .hero-title {
                font-size: 1.75rem;
                line-height: 1.3;
            }

            .logo span {
                font-size: 1.05rem;
            }

            .nav-actions {
                gap: 0.5rem;
            }

            .btn {
                padding: 0.55rem 1rem;
                font-size: 0.8rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .pricing-card {
                padding: 1.75rem 1.25rem;
            }

            .plan-name {
                font-size: 1.25rem;
            }

            .plan-price {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Mouse Spotlight Effect Overlay -->
    <div id="mouse-spotlight"></div>

    <!-- Sticky Navbar -->
    <nav class="navbar">
        <div class="container navbar-inner">
            <a href="index.php" class="logo">
                <div class="logo-icon">
                    <i data-lucide="layers" style="width: 22px; height: 22px;"></i>
                </div>
                <span>MARG SOFT SOLUTIONS</span>
            </a>

            <ul class="nav-links">
                <li><a href="#features" class="nav-link">Features</a></li>
                <li><a href="#whatsapp" class="nav-link">WhatsApp Cloud API</a></li>
                <li><a href="#pricing" class="nav-link">Pricing</a></li>
                <li><a href="#faq" class="nav-link">FAQ</a></li>
                <li><a href="index.php?page=contact" class="nav-link">Contact Us</a></li>
            </ul>

            <div class="nav-actions">
                <?php if ($is_logged_in): ?>
                    <a href="index.php?page=dashboard" class="btn btn-cyan">
                        <i data-lucide="layout-dashboard" style="width: 16px; height: 16px;"></i>
                        <span>Go to Dashboard</span>
                    </a>
                <?php else: ?>
                    <a href="auth/login.php" class="btn btn-ghost">Sign In</a>
                    <button type="button" onclick="openLeadModal()" class="btn btn-cyan">
                        <span>Contact Us</span>
                        <i data-lucide="message-square" style="width: 16px; height: 16px;"></i>
                    </button>
                <?php endif; ?>
                <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Toggle Navigation Menu">
                    <i data-lucide="menu" style="width: 20px; height: 20px;"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="badge-pill">
                <i data-lucide="zap" style="width: 14px; height: 14px;"></i>
                <span>India's #1 Marg ERP 9+ WhatsApp Integrated CRM</span>
            </div>

            <h1 class="hero-title">
                Automate Bills, Reminders & Growth with <span>WhatsApp Cloud API</span>
            </h1>

            <p class="hero-subtitle">
                Connect your Marg ERP 9+ directly to your official WhatsApp Business number. Send instant GST bill PDFs, collect outstanding payments, and automate customer support — seamlessly.
            </p>

            <div class="hero-cta">
                <button type="button" onclick="openLeadModal()" class="btn btn-primary">
                    <span>Contact Us →</span>
                </button>
                <button type="button" onclick="openLeadModal('Book a Demo')" class="btn btn-cyan">
                    <span>Book a demo</span>
                </button>
            </div>

            <!-- Live Product Mockup -->
            <div class="preview-card">
                <div class="mockup-header">
                    <div class="dots">
                        <div class="dot dot-red"></div>
                        <div class="dot dot-yellow"></div>
                        <div class="dot dot-green"></div>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">marg_crm_dashboard_v2026.php</div>
                    <div style="font-size: 0.75rem; color: #10b981; display: flex; align-items: center; gap: 4px;">
                        <i data-lucide="wifi" style="width: 12px; height: 12px;"></i> Marg ERP 9+ Connected Live
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 12px; padding: 1rem;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Today's Invoices Sent</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #ffffff;">1,492 Invoices</div>
                        <div style="font-size: 0.7rem; color: #10b981;">↑ 99.8% WhatsApp Delivery Rate</div>
                    </div>
                    <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 12px; padding: 1rem;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Outstanding Collected</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #ffffff;">₹4,85,200</div>
                        <div style="font-size: 0.7rem; color: #10b981;">Auto UPI Reminders Sent</div>
                    </div>
                    <div style="background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.2); border-radius: 12px; padding: 1rem;">
                        <div style="font-size: 0.75rem; color: #94a3b8;">Meta Embedded Cloud API</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #25D366;">Active Connected</div>
                        <div style="font-size: 0.7rem; color: #94a3b8;">0% Risk Direct Customer Card</div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-number">50,000+</div>
                    <div class="stat-label">Active Marg ERP Retailers & Distributors</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">10M+</div>
                    <div class="stat-label">WhatsApp Bill PDFs Delivered</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">99.9%</div>
                    <div class="stat-label">Uptime & Instant Delivery</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">2 Mins</div>
                    <div class="stat-label">Meta Embedded 1-Click Setup</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section">
        <div class="container">
            <h2 class="section-title">Everything you need to automate your Marg ERP business</h2>
            <p class="section-subtitle">Supercharge sales, automate invoice dispatch, and manage support tickets with built-in multi-tenant SaaS intelligence.</p>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i data-lucide="file-text" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Automated Bill & Invoice WhatsApp Dispatch</h3>
                    <p class="feature-desc">When a sale bill is saved in Marg ERP 9+, Marg CRM generates a formatted PDF and instantly delivers it to your customer's WhatsApp number.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(37, 211, 102, 0.15); color: #25D366;">
                        <i data-lucide="message-square" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Meta Embedded Signup (1-Click)</h3>
                    <p class="feature-desc">Connect your official WhatsApp Business number in 2 minutes. Meta bills your business credit card directly — zero risk for your agency.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <i data-lucide="credit-card" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Automated Payment & Outstanding Reminders</h3>
                    <p class="feature-desc">Automatically alert party ledgers with payment due dates and UPI payment links to collect pending payments 3x faster.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(147, 51, 234, 0.15); color: #c084fc;">
                        <i data-lucide="users" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Team Inbox & Multi-Agent Support</h3>
                    <p class="feature-desc">Assign WhatsApp customer inquiries to dedicated support technicians with full ticket ID tracking (e.g. TCK-9021).</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(6, 182, 212, 0.15); color: #22d3ee;">
                        <i data-lucide="building-2" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Multi-Tenant SaaS Management</h3>
                    <p class="feature-desc">Provision isolated MySQL databases for each branch or enterprise client with 1-click Super Admin impersonation.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon" style="background: rgba(239, 68, 68, 0.15); color: #f87171;">
                        <i data-lucide="shield-check" style="width: 26px; height: 26px;"></i>
                    </div>
                    <h3 class="feature-title">Enterprise Data Security & Backups</h3>
                    <p class="feature-desc">Bank-grade SSL encryption, role-based access control (RBAC), and automated daily database backups ensure total safety.</p>
                </div>
            </div>
        </div>
    <!-- Trust Badges & Enterprise Highlights Section -->
    <section class="section" style="padding: 3rem 0; background: rgba(6, 182, 212, 0.03); border-top: 1px solid rgba(255, 255, 255, 0.05); border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
        <div class="container">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; text-align: center;">
                <div style="background: rgba(18, 24, 38, 0.6); border: 1px solid var(--border-color); padding: 1.25rem 1rem; border-radius: 16px; display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                    <i data-lucide="check-circle-2" style="width: 28px; height: 28px; color: #10b981;"></i>
                    <span style="font-size: 0.9rem; font-weight: 700; color: #ffffff;">Official Meta WABA Partner</span>
                    <span style="font-size: 0.775rem; color: var(--text-muted);">1-Click Embedded Facebook Signup</span>
                </div>
                <div style="background: rgba(18, 24, 38, 0.6); border: 1px solid var(--border-color); padding: 1.25rem 1rem; border-radius: 16px; display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                    <i data-lucide="file-check-2" style="width: 28px; height: 28px; color: var(--accent-cyan);"></i>
                    <span style="font-size: 0.9rem; font-weight: 700; color: #ffffff;">Auto GST & E-Way Filing</span>
                    <span style="font-size: 0.775rem; color: var(--text-muted);">Direct Portal Return Uploads</span>
                </div>
                <div style="background: rgba(18, 24, 38, 0.6); border: 1px solid var(--border-color); padding: 1.25rem 1rem; border-radius: 16px; display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                    <i data-lucide="shield-check" style="width: 28px; height: 28px; color: #3b82f6;"></i>
                    <span style="font-size: 0.9rem; font-weight: 700; color: #ffffff;">Multi-Tenant DB Isolation</span>
                    <span style="font-size: 0.775rem; color: var(--text-muted);">100% Dedicated Database per Client</span>
                </div>
                <div style="background: rgba(18, 24, 38, 0.6); border: 1px solid var(--border-color); padding: 1.25rem 1rem; border-radius: 16px; display: flex; flex-direction: column; align-items: center; gap: 0.5rem;">
                    <i data-lucide="headset" style="width: 28px; height: 28px; color: #f59e0b;"></i>
                    <span style="font-size: 0.9rem; font-weight: 700; color: #ffffff;">24/7 Priority Support</span>
                    <span style="font-size: 0.775rem; color: var(--text-muted);">Kanpur Onboarding Specialist Team</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Customer Ratings & Google Verified Reviews Section (Continuous Running Carousel) -->
    <section id="ratings" class="section" style="background: rgba(18, 24, 38, 0.4); padding: 5rem 0; position: relative;">
        <div class="container">
            <div class="section-header text-center" style="margin-bottom: 2.5rem;">
                <h2 class="section-title">What Our Clients Say</h2>
                <p class="section-subtitle" style="max-width: 680px; margin: 0 auto;">
                    Real feedback from verified Marg ERP 9+, Marg Books, and WhatsApp Cloud API users across India.
                </p>
            </div>

            <div class="ratings-marquee-wrapper">
                <div class="ratings-marquee-track">
                    <?php 
                    $review_loop = array_merge($public_reviews, $public_reviews);
                    foreach ($review_loop as $rev): 
                        $stars_cnt = min(5, max(1, round(floatval($rev['rating']))));
                    ?>
                        <div class="rating-card">
                            <div>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                                    <div style="display: flex; gap: 3px; color: #f59e0b;">
                                        <?php for ($s = 0; $s < $stars_cnt; $s++): ?>
                                            <i data-lucide="star" style="width: 16px; height: 16px; fill: #f59e0b; color: #f59e0b;"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span style="font-size: 0.725rem; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; padding: 3px 10px; border-radius: 9999px; font-weight: 700; display: flex; align-items: center; gap: 4px;">
                                        ✓ <?php echo htmlspecialchars($rev['source'] ?? 'Google Verified'); ?>
                                    </span>
                                </div>
                                <p style="font-size: 0.9rem; color: #e2e8f0; line-height: 1.65; margin-bottom: 1.25rem; font-style: italic; font-weight: 400;">
                                    "<?php echo htmlspecialchars($rev['review_text']); ?>"
                                </p>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1rem; margin-top: 0.5rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #06b6d4); color: #ffffff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.25);">
                                        <?php echo strtoupper(substr($rev['name'], 0, 1)); ?>
                                    </div>
                                    <div style="display: flex; flex-direction: column;">
                                        <span style="font-size: 0.875rem; font-weight: 700; color: #ffffff;"><?php echo htmlspecialchars($rev['name']); ?></span>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($rev['company']); ?> &bull; <?php echo htmlspecialchars($rev['city']); ?></span>
                                    </div>
                                </div>
                                <span style="font-size: 0.725rem; color: var(--accent-cyan); background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.25); padding: 3px 10px; border-radius: 8px; font-weight: 600;">
                                    <?php echo htmlspecialchars($rev['service_name']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="section" style="background: rgba(8, 12, 20, 0.5);">
        <div class="container">
            <h2 class="section-title">Simple, transparent pricing for every business</h2>
            <p class="section-subtitle">No hidden fees. Select your product to view tailored plans & features.</p>

            <!-- Radio Button Selector Tabs -->
            <div class="pricing-tabs" role="radiogroup" aria-label="Product Pricing Selection">
                <label class="pricing-tab-label active" onclick="switchPricingTab('pricing_erp')">
                    <input type="radio" name="pricing_product" value="pricing_erp" checked>
                    <i data-lucide="layers" style="width: 18px; height: 18px;"></i>
                    Marg ERP 9+
                </label>
                <label class="pricing-tab-label" onclick="switchPricingTab('pricing_books')">
                    <input type="radio" name="pricing_product" value="pricing_books">
                    <i data-lucide="book-open" style="width: 18px; height: 18px;"></i>
                    Marg Books
                </label>
                <label class="pricing-tab-label" onclick="switchPricingTab('pricing_cloud')">
                    <input type="radio" name="pricing_product" value="pricing_cloud">
                    <i data-lucide="cloud" style="width: 18px; height: 18px;"></i>
                    Marg Cloud
                </label>
                <label class="pricing-tab-label" onclick="switchPricingTab('pricing_crm')">
                    <input type="radio" name="pricing_product" value="pricing_crm">
                    <i data-lucide="message-square" style="width: 18px; height: 18px;"></i>
                    CRM / Marketing
                </label>
            </div>

            <!-- Tab 1: Marg ERP 9+ -->
            <div id="pricing_erp" class="pricing-tab-content pricing-grid active">
                <!-- Nano Edition -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Nano Edition</div>
                        <div class="plan-price">₹5,550 <span>/ initial + 18% GST</span></div>
                        <div class="plan-arc"><i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i> Annual Renewal (ARC): ₹2,400 / yr</div>
                        <p class="text-xs text-muted mb-4">Entry-level version for micro retailers & small 1-2 user shops.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 User License (Max 2 Users)</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Max 2 Companies Support</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> GST Return in Excel, CSV & JSON</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> ₹3,000 / Extra User or Company</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Standard Telephonic & Online Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg ERP 9+ Nano Edition')" class="btn btn-ghost w-full">Contact Us</button>
                </div>

                <!-- Basic Edition -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Basic Edition</div>
                        <div class="plan-price">₹10,300 <span>/ initial + 18% GST</span></div>
                        <div class="plan-arc"><i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i> Annual Renewal (ARC): ₹3,990 / yr</div>
                        <p class="text-xs text-muted mb-4">Limited edition for small retail chemists & single-user dukan setup.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 User Full Rights (Max 2 Users)</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Max 2 Companies Support</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Single Store Inventory & Billing</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> GST Filing & E-Way Bills</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Standard Email & Phone Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg ERP 9+ Basic Edition')" class="btn btn-ghost w-full">Contact Us</button>
                </div>

                <!-- Silver (Featured) -->
                <div class="pricing-card featured">
                    <div class="featured-badge">MOST POPULAR</div>
                    <div>
                        <div class="plan-name">Silver Edition</div>
                        <div class="plan-price">₹13,900 <span>/ initial + 18% GST</span></div>
                        <div class="plan-arc"><i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i> Annual Renewal (ARC): ₹4,780 / yr</div>
                        <p class="text-xs text-muted mb-4">Standard edition for growing stockists & pharma distributors needing multi-user access.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 Full User + 1 View-Only User</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Multi-Company & Barcode Management</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Batch, Expiry & Rate Margin Tracking</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> WhatsApp Bill & Payment Reminders</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Priority Dealer Support & 2 Free Visits</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg ERP 9+ Silver Edition')" class="btn btn-cyan w-full">Contact Us</button>
                </div>

                <!-- Gold -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Gold Edition</div>
                        <div class="plan-price">₹26,000 <span>/ initial + 18% GST</span></div>
                        <div class="plan-arc"><i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i> Annual Renewal (ARC): ₹9,550 / yr</div>
                        <p class="text-xs text-muted mb-4">Comprehensive enterprise edition for multi-branch distributor networks & large firms.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Unlimited Users & Unlimited Companies</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Multi-Godown & Multi-Currency Support</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Advanced Audit Trail & Multi-Location Sync</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Custom Invoice Formats & Auto Reports</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Dedicated Account Manager & 24/7 Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg ERP 9+ Gold Edition')" class="btn btn-ghost w-full">Contact Sales</button>
                </div>
            </div>

            <!-- Tab 2: Marg Books -->
            <div id="pricing_books" class="pricing-tab-content pricing-grid">
                <!-- Basic Plan -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Books Basic</div>
                        <div class="plan-price">₹5,400 <span>/ year + GST</span></div>
                        <p class="text-xs text-muted mb-4">Modern cloud accounting & invoicing accessible anytime from web & mobile apps.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 User Cloud Access</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Cloud Invoicing & Expense Tracking</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Online GST Filing & Reconciliation</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Web & Android/iOS Mobile App</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg Books Basic Plan')" class="btn btn-ghost w-full">Contact Us</button>
                </div>

                <!-- Silver Plan (Featured) -->
                <div class="pricing-card featured">
                    <div class="featured-badge">RECOMMENDED</div>
                    <div>
                        <div class="plan-name">Books Silver</div>
                        <div class="plan-price">₹8,100 <span>/ year + GST</span></div>
                        <p class="text-xs text-muted mb-4">Ideal for multi-counter retail stores with automated e-invoicing & UPI payments.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 2 Full Users + Mobile Access</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Direct 1-Click E-Way Bill & E-Invoices</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Auto Bank Reconciliation & QR Payments</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Automated Stock Audit & Reorder Alerts</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Live Chat & Priority Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg Books Silver Plan')" class="btn btn-cyan w-full">Contact Us</button>
                </div>

                <!-- Gold Plan -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Books Gold</div>
                        <div class="plan-price">₹15,300 <span>/ year + GST</span></div>
                        <p class="text-xs text-muted mb-4">Full-fledged cloud accounting suite for enterprise stockists & multi-firm businesses.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 5 Cloud Users (Expandable)</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Multi-Firm & Multi-Branch Cloud Management</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Advanced Inventory Matrix & Custom BI</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Rest API Access & ERP Data Migration</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg Books Gold Plan')" class="btn btn-ghost w-full">Contact Us</button>
                </div>
            </div>

            <!-- Tab 3: Marg Cloud -->
            <div id="pricing_cloud" class="pricing-tab-content pricing-grid">
                <!-- Cloud Starter -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Cloud Starter</div>
                        <div class="plan-price">₹7,500 <span>/ year + GST</span></div>
                        <p class="text-xs text-muted mb-4">Host existing desktop Marg ERP 9+ software on high-speed remote cloud servers.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 1 Concurrent Cloud User Access</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> High-Speed Remote VM Server Hosting</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Automated Daily Cloud Backups</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Secure Access from PC, Mac, or Tablet</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg Cloud Starter')" class="btn btn-ghost w-full">Contact Us</button>
                </div>

                <!-- Cloud Pro (Featured) -->
                <div class="pricing-card featured">
                    <div class="featured-badge">HIGH PERFORMANCE</div>
                    <div>
                        <div class="plan-name">Cloud Pro</div>
                        <div class="plan-price">₹10,800 <span>/ year + GST</span></div>
                        <p class="text-xs text-muted mb-4">Dedicated high-performance cloud instance for multi-user distributor teams.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 3 Concurrent Cloud Users Access</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Ultra-Fast NVMe SSD Server Storage</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 99.9% Uptime SLA Guarantee & Failover</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> SSL Encrypted Tunnel & Ransomware Guard</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> 24/7 Cloud Support & Auto Updates</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg Cloud Pro')" class="btn btn-cyan w-full">Contact Us</button>
                </div>

                <!-- Enterprise Dedicated Cloud -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Enterprise VPC</div>
                        <div class="plan-price">₹45,000 <span>/ month + GST</span></div>
                        <p class="text-xs text-muted mb-4">Dedicated Virtual Private Cloud (VPC) infrastructure for large multi-branch enterprises.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Unlimited Users & Dedicated Server Resources</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Isolated Virtual Private Cloud (VPC) Network</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Custom Data Retention & Dedicated VPN</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Dedicated Cloud Architect & Priority Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('Marg Cloud Enterprise')" class="btn btn-ghost w-full">Request Quote</button>
                </div>
            </div>

            <!-- Tab 4: CRM / Marketing -->
            <div id="pricing_crm" class="pricing-tab-content pricing-grid">
                <!-- CRM Starter -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">CRM Starter</div>
                        <div class="plan-price">₹999 <span>/ month</span></div>
                        <p class="text-xs text-muted mb-4">Essential WhatsApp CRM & lead capture for single retail branch or small teams.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> WhatsApp Lead Capture & Auto-Welcome</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Contact List Management & Tagging</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Quick Reply Templates & Basic Analytics</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Standard Email & Chat Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('CRM Starter')" class="btn btn-ghost w-full">Contact Us</button>
                </div>

                <!-- Marketing Pro (Featured) -->
                <div class="pricing-card featured">
                    <div class="featured-badge">MOST POPULAR</div>
                    <div>
                        <div class="plan-name">Marketing Pro</div>
                        <div class="plan-price">₹2,499 <span>/ month</span></div>
                        <p class="text-xs text-muted mb-4">Powerful campaign automation & team inbox for growing marketing teams.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Meta Official WABA & 1-Click Setup</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Bulk Broadcast Messaging & Auto Follow-ups</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Shared Multi-Agent Team Support Inbox</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Dynamic Lead Scoring & Campaign Analytics</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Marg ERP Sync for Auto Payment Reminders</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('CRM Marketing Pro')" class="btn btn-cyan w-full">Contact Us</button>
                </div>

                <!-- Enterprise CRM Suite -->
                <div class="pricing-card">
                    <div>
                        <div class="plan-name">Enterprise CRM</div>
                        <div class="plan-price">Custom <span>/ enterprise plan</span></div>
                        <p class="text-xs text-muted mb-4">Omnichannel CRM & automated marketing workflows for large enterprise distributors.</p>

                        <ul class="plan-features">
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Multi-Branch Lead Routing & Round-Robin</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Custom Webhook Integrations & API Access</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> AI-Powered Chatbot & Custom Flow Builder</li>
                            <li class="plan-feature-item"><i data-lucide="check" style="width: 16px; height: 16px; color: #10b981;"></i> Dedicated CRM Manager & 24/7 SLA Support</li>
                        </ul>
                    </div>
                    <button type="button" onclick="openLeadModal('CRM Enterprise Suite')" class="btn btn-ghost w-full">Contact Enterprise Team</button>
                </div>
            </div>
        </div>
    </section>

    <!-- Official Office Headquarters & Interactive Google Map Section -->
    <section id="location" class="section" style="padding: 4.5rem 0; border-top: 1px solid var(--border-color); background: rgba(11, 15, 25, 0.6);">
        <div class="container">
            <div class="section-header text-center" style="margin-bottom: 2.5rem;">
                <div style="display: inline-flex; align-items: center; gap: 6px; background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3); padding: 6px 16px; border-radius: 9999px; color: var(--accent-cyan); font-size: 0.85rem; font-weight: 700; margin-bottom: 1rem;">
                    <i data-lucide="map-pin" style="width: 16px; height: 16px;"></i>
                    <span>Official Headquarters & Location</span>
                </div>
                <h2 class="section-title">Visit Our Kanpur Office</h2>
                <p class="section-subtitle">Walk in for live ERP demonstrations, custom onboarding, and direct technical consultations.</p>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 2rem; align-items: center; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; padding: 1.75rem; box-shadow: 0 20px 50px rgba(0,0,0,0.4);">
                <!-- Office Details Card -->
                <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                    <div>
                        <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--accent-cyan); font-weight: 800;">Head Office Address</span>
                        <h3 style="font-family: var(--font-heading); font-size: 1.35rem; font-weight: 800; color: #ffffff; margin-top: 4px;">Marg Soft Solutions Pvt Ltd</h3>
                    </div>

                    <div style="display: flex; gap: 1rem; align-items: flex-start;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.3); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i data-lucide="building-2" style="width: 20px; height: 20px;"></i>
                        </div>
                        <div>
                            <span style="font-size: 0.85rem; font-weight: 700; color: #ffffff; display: block; margin-bottom: 2px;">Kanpur Campus Address:</span>
                            <p style="font-size: 0.9rem; color: var(--text-muted); line-height: 1.5;">
                                63/2-C, Ist Floor, Chauraha, Canal Rd,<br>
                                Ghasiyari Mandi, Kanpur,<br>
                                Uttar Pradesh 208001
                            </p>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; align-items: flex-start;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i data-lucide="phone-call" style="width: 20px; height: 20px;"></i>
                        </div>
                        <div>
                            <span style="font-size: 0.85rem; font-weight: 700; color: #ffffff; display: block; margin-bottom: 2px;">Support & Sales Hotline:</span>
                            <p style="font-size: 0.9rem; color: var(--text-muted);">+91 98390 00000 / +91 0512 230000</p>
                            <span style="font-size: 0.75rem; color: var(--text-sidebar);">Mon - Sat: 9:30 AM to 7:00 PM</span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 0.5rem;">
                        <a href="https://maps.google.com/?q=63/2-C,+Ist+Floor,+Chauraha,+Canal+Rd,+Ghasiyari+Mandi,+Kanpur,+Uttar+Pradesh+208001" target="_blank" class="btn btn-cyan" style="padding: 0.65rem 1.35rem; font-size: 0.85rem; font-weight: 700;">
                            <i data-lucide="navigation" style="width: 16px; height: 16px;"></i>
                            <span>Get Map Directions →</span>
                        </a>
                    </div>
                </div>

                <!-- Embedded Interactive Google Map -->
                <div style="width: 100%; height: 320px; border-radius: 14px; overflow: hidden; border: 1px solid var(--border-color); box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                    <iframe 
                        title="Marg Soft Solutions Kanpur Office Location Map" 
                        width="100%" 
                        height="100%" 
                        style="border:0;" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade" 
                        src="https://maps.google.com/maps?q=63/2-C,+Ist+Floor,+Chauraha,+Canal+Rd,+Ghasiyari+Mandi,+Kanpur,+Uttar+Pradesh+208001&t=&z=16&ie=UTF8&iwloc=&output=embed">
                    </iframe>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section id="faq" class="section">
        <div class="container">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Got questions? We've got answers.</p>

            <div class="faq-accordion">
                <div class="faq-item active">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>What support do you provide?</span>
                        <i data-lucide="minus" style="width: 18px; height: 18px; color: var(--accent-cyan);"></i>
                    </button>
                    <div class="faq-answer">
                        We provide 24/7 technical support via phone, email, and live WhatsApp. Our dedicated onboarding specialists assist you with 2-minute Meta Cloud API setup, AnyDesk/TeamViewer remote support, and Marg ERP 9+ integration configuration.
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>Do I need a Meta Developer account to connect WhatsApp?</span>
                        <i data-lucide="plus" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
                    </button>
                    <div class="faq-answer">
                        No! With our official <strong>Meta Embedded Signup</strong> feature, you simply click "Connect WhatsApp with Meta", log into Facebook in a popup window, enter your OTP, and connect your business number in under 2 minutes without touching any developer portal.
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>Will Meta bill my bank account directly?</span>
                        <i data-lucide="plus" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
                    </button>
                    <div class="faq-answer">
                        Yes! With Direct Customer Billing, Meta deducts message fees directly from your registered credit/debit card in Meta Business Manager. Alternatively, you can use our Shared Central Number model with wallet recharge.
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" onclick="toggleFaq(this)">
                        <span>Can I send Bill PDFs and Payment links directly from Marg ERP 9+?</span>
                        <i data-lucide="plus" style="width: 18px; height: 18px; color: var(--text-muted);"></i>
                    </button>
                    <div class="faq-answer">
                        Yes! Marg ERP 9+ integrates directly with our Meta Cloud WABA engine. Invoices, GST bills, and ledger statements are automatically attached as PDFs with 1-click WhatsApp messaging.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Hero Banner Card -->
    <div class="container">
        <div class="cta-banner-card">
            <h2 class="cta-banner-title">Ready to automate your business?</h2>
            <p class="cta-banner-subtitle">
                Join 50,000+ growing teams. Contact us today to get a customized quote for your dukan or enterprise branch.
            </p>

            <div class="cta-banner-actions">
                <button type="button" onclick="openLeadModal()" class="btn btn-primary">
                    <span>Contact Us →</span>
                </button>
                <button type="button" onclick="openLeadModal('Book a Demo')" class="btn btn-cyan">
                    <span>Book a demo</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <!-- Brand Info -->
                <div class="footer-brand">
                    <a href="index.php" class="logo">
                        <div class="logo-icon">
                            <i data-lucide="layers" style="width: 22px; height: 22px;"></i>
                        </div>
                        <span>MARG SOFT SOLUTIONS</span>
                    </a>
                    <p style="margin-bottom: 1.25rem;">
                        India's #1 ERP for retailers, distributors, pharmacies and enterprises — automate billing, inventory and growth.
                    </p>

                    <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing!');">
                        <input type="email" class="newsletter-input" placeholder="you@company.com" required>
                        <button type="submit" class="btn btn-cyan" style="padding: 0.6rem 1.25rem;">Subscribe</button>
                    </form>
                </div>

                <!-- Column 1: Product -->
                <div>
                    <h4 class="footer-col-title">Product</h4>
                    <ul class="footer-links">
                        <li><a href="#features" class="footer-link">Features</a></li>
                        <li><a href="#pricing" class="footer-link">Pricing</a></li>
                        <li><a href="index.php?page=whatsapp_settings" class="footer-link">Integrations</a></li>
                        <li><a href="#ratings" class="footer-link">Customer Ratings</a></li>
                        <li><a href="#location" class="footer-link">Office Location</a></li>
                    </ul>
                </div>

                <!-- Column 2: Company -->
                <div>
                    <h4 class="footer-col-title">Company</h4>
                    <ul class="footer-links">
                        <li><a href="index.php?page=contact" class="footer-link">About</a></li>
                        <li><a href="javascript:void(0)" onclick="openRateUsModal()" class="footer-link">Rate Us / Submit Review</a></li>
                        <li><a href="index.php?page=contact" class="footer-link">Careers</a></li>
                        <li><a href="index.php?page=crm_clients" class="footer-link">Customers</a></li>
                        <li><a href="index.php?page=contact" class="footer-link">Contact Us</a></li>
                    </ul>
                </div>

                <!-- Column 3: Resources -->
                <div>
                    <h4 class="footer-col-title">Resources</h4>
                    <ul class="footer-links">
                        <li><a href="index.php?page=privacy" class="footer-link">Docs</a></li>
                        <li><a href="index.php?page=terms" class="footer-link">Guides</a></li>
                        <li><a href="index.php?page=privacy" class="footer-link">Privacy Policy</a></li>
                        <li><a href="index.php?page=terms" class="footer-link">Terms of Service</a></li>
                        <li><a href="index.php?page=refund" class="footer-link">Refund Policy</a></li>
                    </ul>
                </div>
            </div>

            <!-- Footer Bottom Bar -->
            <div class="footer-bottom">
                <div>© 2026 Marg Soft Solutions Pvt Ltd. All rights reserved.</div>
                <div class="social-links" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <a href="https://www.instagram.com/margerpltdmargsoftsolution/" target="_blank" class="social-icon" title="Instagram: @margerpltdmargsoftsolution"><i data-lucide="instagram" style="width: 18px; height: 18px; color: #e1306c;"></i></a>
                    <a href="https://www.facebook.com/margerpltdmargsoftsolution/" target="_blank" class="social-icon" title="Facebook: Marg ERP Ltd Marg Soft Solution"><i data-lucide="facebook" style="width: 18px; height: 18px; color: #1877f2;"></i></a>
                    <a href="https://maps.google.com/?q=63/2-C,+Ist+Floor,+Chauraha,+Canal+Rd,+Ghasiyari+Mandi,+Kanpur,+Uttar+Pradesh+208001" target="_blank" class="social-icon" title="Google Maps Headquarters"><i data-lucide="map-pin" style="width: 18px; height: 18px; color: #ea4335;"></i></a>
                    <a href="https://youtube.com" target="_blank" class="social-icon" title="YouTube Official Channel"><i data-lucide="youtube" style="width: 18px; height: 18px; color: #ff0000;"></i></a>
                    <a href="https://wa.me/919839000000" target="_blank" class="social-icon" title="WhatsApp Official Support"><i data-lucide="message-circle" style="width: 18px; height: 18px; color: #25d366;"></i></a>
                    <a href="https://linkedin.com" target="_blank" class="social-icon" title="LinkedIn Corporate"><i data-lucide="linkedin" style="width: 18px; height: 18px; color: #0a66c2;"></i></a>
                    <a href="https://twitter.com" target="_blank" class="social-icon" title="Twitter / X"><i data-lucide="twitter" style="width: 18px; height: 18px; color: #1da1f2;"></i></a>
                    <a href="https://github.com/HarshCodeCraft/marglead" target="_blank" class="social-icon" title="GitHub Repository"><i data-lucide="github" style="width: 18px; height: 18px;"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Lead Capture Contact Modal -->
    <div id="leadModal" class="lead-modal-overlay" style="display: none;">
        <div class="lead-modal-container">
            <button class="lead-modal-close" onclick="closeLeadModal()" aria-label="Close modal">&times;</button>
            
            <div class="lead-modal-header" style="text-align: center; margin-bottom: 1.25rem;">
                <div class="logo-icon" style="margin: 0 auto 0.75rem auto;">
                    <i data-lucide="message-square" style="width: 22px; height: 22px;"></i>
                </div>
                <h3 style="font-family: var(--font-heading); font-size: 1.4rem; font-weight: 800; color: #ffffff;">Contact Us & Get Instant Quote</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Fill in your details. Our expert team will get in touch with you shortly.</p>
            </div>

            <div id="leadFormAlert" style="display: none; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem;"></div>

            <!-- Selected Plan Pill Badge (Removable) -->
            <div id="selectedPlanBadge" style="display: none; align-items: center; justify-content: space-between; background: linear-gradient(135deg, rgba(6, 182, 212, 0.15) 0%, rgba(59, 130, 246, 0.15) 100%); border: 1px solid var(--accent-cyan); padding: 8px 14px; border-radius: 9999px; margin-bottom: 1rem; font-size: 0.85rem; color: #ffffff;">
                <div style="display: flex; align-items: center; gap: 0.5rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <i data-lucide="check-circle-2" style="width: 16px; height: 16px; color: var(--accent-cyan); flex-shrink: 0;"></i>
                    <span>Selected Plan: <strong id="selectedPlanText" style="color: #ffffff;">Marg ERP 9+ Silver Edition</strong></span>
                </div>
                <button type="button" onclick="clearSelectedPlan()" title="Remove plan selection" style="background: rgba(255,255,255,0.08); border: none; color: #94a3b8; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; font-size: 1.1rem; line-height: 1; margin-left: 8px; flex-shrink: 0; transition: all 0.2s ease;">&times;</button>
            </div>

            <form id="contactLeadForm" onsubmit="submitLeadForm(event)">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Full Name <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" name="name" id="lead_name" required placeholder="Enter your full name" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Phone Number <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="tel" name="phone" id="lead_phone" required placeholder="10-digit mobile number" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Email Address <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="email" name="email" id="lead_email" required placeholder="name@company.com" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Firm / Company Name
                        </label>
                        <input type="text" name="company" id="lead_company" placeholder="e.g. Apex Pharma / Store" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                </div>

                <!-- Product Requirement Checkboxes -->
                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.5rem;">
                        Select Requirement(s):
                    </label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.65rem; background: var(--bg-dark); padding: 0.85rem; border-radius: 12px; border: 1px solid var(--border-color);">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #ffffff; cursor: pointer;">
                            <input type="checkbox" name="products[]" value="Marg ERP 9+" id="chk_marg_erp" style="width: 16px; height: 16px; accent-color: var(--accent-cyan);">
                            <span>Marg ERP 9+</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #ffffff; cursor: pointer;">
                            <input type="checkbox" name="products[]" value="Marg Books" id="chk_marg_books" style="width: 16px; height: 16px; accent-color: var(--accent-cyan);">
                            <span>Marg Books</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #ffffff; cursor: pointer;">
                            <input type="checkbox" name="products[]" value="Marg Cloud" id="chk_marg_cloud" style="width: 16px; height: 16px; accent-color: var(--accent-cyan);">
                            <span>Marg Cloud</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #ffffff; cursor: pointer;">
                            <input type="checkbox" name="products[]" value="Marketing & CRM" id="chk_marketing" style="width: 16px; height: 16px; accent-color: var(--accent-cyan);">
                            <span>Marketing & CRM</span>
                        </label>
                    </div>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                        Additional Requirements / Notes
                    </label>
                    <textarea name="remarks" id="lead_remarks" rows="2" placeholder="Mention any specific query or preferred call time..." style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.875rem; outline: none; resize: vertical;"></textarea>
                </div>

                <button type="submit" id="btnSubmitLead" class="btn btn-cyan w-full" style="padding: 0.8rem; font-size: 0.95rem; font-weight: 700; width: 100%;">
                    <span>Submit Inquiry</span>
                    <i data-lucide="send" style="width: 16px; height: 16px;"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Initialize Lucide Icons, Mouse Effects & Modal JS -->
    <script>
        lucide.createIcons();

        // Lead Modal Functions
        let currentSelectedPlan = '';

        function openLeadModal(presetRequirement = '') {
            const modal = document.getElementById('leadModal');
            const alertBox = document.getElementById('leadFormAlert');
            const badge = document.getElementById('selectedPlanBadge');
            const planText = document.getElementById('selectedPlanText');
            if (alertBox) alertBox.style.display = 'none';

            // Reset checkboxes
            document.querySelectorAll('#contactLeadForm input[type="checkbox"]').forEach(chk => {
                chk.checked = false;
            });

            currentSelectedPlan = presetRequirement;

            if (presetRequirement && presetRequirement !== 'Book a Demo') {
                if (badge && planText) {
                    planText.textContent = presetRequirement;
                    badge.style.display = 'flex';
                }

                // Auto-check corresponding product requirement checkbox
                if (presetRequirement.includes('ERP')) {
                    document.getElementById('chk_marg_erp').checked = true;
                } else if (presetRequirement.includes('Book')) {
                    document.getElementById('chk_marg_books').checked = true;
                } else if (presetRequirement.includes('Cloud')) {
                    document.getElementById('chk_marg_cloud').checked = true;
                } else if (presetRequirement.includes('CRM') || presetRequirement.includes('Marketing')) {
                    document.getElementById('chk_marketing').checked = true;
                } else {
                    document.getElementById('chk_marg_erp').checked = true;
                }
            } else {
                if (badge) badge.style.display = 'none';
                document.getElementById('chk_marg_erp').checked = true;
            }

            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
            lucide.createIcons();
        }

        function clearSelectedPlan() {
            currentSelectedPlan = '';
            const badge = document.getElementById('selectedPlanBadge');
            if (badge) badge.style.display = 'none';
        }

        function closeLeadModal() {
            const modal = document.getElementById('leadModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        async function submitLeadForm(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitLead');
            const alertBox = document.getElementById('leadFormAlert');
            
            const name = document.getElementById('lead_name').value.trim();
            const phone = document.getElementById('lead_phone').value.trim();
            const email = document.getElementById('lead_email').value.trim();
            const company = document.getElementById('lead_company').value.trim();
            let remarks = document.getElementById('lead_remarks').value.trim();

            if (currentSelectedPlan) {
                remarks = `Requested Edition/Plan: [${currentSelectedPlan}] | ` + remarks;
            }

            const selectedProducts = [];
            document.querySelectorAll('#contactLeadForm input[type="checkbox"]:checked').forEach(chk => {
                selectedProducts.push(chk.value);
            });

            if (!name || !phone || !email) {
                alertBox.className = 'alert alert-error';
                alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                alertBox.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                alertBox.style.color = '#f87171';
                alertBox.innerHTML = '⚠️ Name, Phone, and Email are mandatory fields.';
                alertBox.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span>Submitting Inquiry...</span>';

            try {
                const response = await fetch('api/submit_lead.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name, phone, email, company, remarks,
                        products: selectedProducts
                    })
                });

                const data = await response.json();

                if (data.success) {
                    alertBox.style.background = 'rgba(16, 185, 129, 0.15)';
                    alertBox.style.border = '1px solid rgba(16, 185, 129, 0.3)';
                    alertBox.style.color = '#34d399';
                    alertBox.innerHTML = '✅ ' + data.message;
                    alertBox.style.display = 'block';
                    document.getElementById('contactLeadForm').reset();
                    clearSelectedPlan();
                    setTimeout(() => {
                        closeLeadModal();
                    }, 2500);
                } else {
                    alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                    alertBox.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                    alertBox.style.color = '#f87171';
                    alertBox.innerHTML = '❌ ' + (data.message || 'Error submitting lead.');
                    alertBox.style.display = 'block';
                }
            } catch (err) {
                alertBox.style.background = 'rgba(239, 68, 68, 0.15)';
                alertBox.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                alertBox.style.color = '#f87171';
                alertBox.innerHTML = '❌ Network error. Please try again.';
                alertBox.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Submit Inquiry</span><i data-lucide="send" style="width: 16px; height: 16px;"></i>';
                lucide.createIcons();
            }
        }

        // Global Mouse Spotlight & Cursor Tracking (Landing Page Only)
        document.addEventListener('mousemove', (e) => {
            const spotlight = document.getElementById('mouse-spotlight');
            if (spotlight) {
                spotlight.style.setProperty('--mouse-x', e.clientX + 'px');
                spotlight.style.setProperty('--mouse-y', e.clientY + 'px');
            }
        });

        // Dynamic Card 3D Tilt & Mouse Light Follow Effect
        function initMouseCardEffects() {
            document.querySelectorAll('.feature-card, .pricing-card, .showcase-card, .faq-item, .stat-item').forEach(card => {
                card.addEventListener('mousemove', (e) => {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    card.style.setProperty('--card-x', x + 'px');
                    card.style.setProperty('--card-y', y + 'px');

                    const centerX = rect.width / 2;
                    const centerY = rect.height / 2;
                    const rotateX = ((y - centerY) / centerY) * -3;
                    const rotateY = ((x - centerX) / centerX) * 3;

                    card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-3px)`;
                });

                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'perspective(1000px) rotateX(0deg) rotateY(0deg) translateY(0px)';
                });
            });
        }

        document.addEventListener('DOMContentLoaded', initMouseCardEffects);
        initMouseCardEffects();

        function toggleMobileMenu() {
            const navLinks = document.querySelector('.nav-links');
            if (navLinks) {
                navLinks.classList.toggle('active');
                lucide.createIcons();
            }
        }

        function switchPricingTab(tabId) {
            document.querySelectorAll('.pricing-tab-content').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelectorAll('.pricing-tab-label').forEach(el => {
                el.classList.remove('active');
            });

            const targetGrid = document.getElementById(tabId);
            if (targetGrid) {
                targetGrid.classList.add('active');
            }

            const activeRadio = document.querySelector(`input[name="pricing_product"][value="${tabId}"]`);
            if (activeRadio) {
                activeRadio.checked = true;
                activeRadio.closest('.pricing-tab-label').classList.add('active');
            }

            lucide.createIcons();
            initMouseCardEffects();
        }

        function toggleFaq(btn) {
            const item = btn.parentElement;
            const isActive = item.classList.contains('active');
            
            document.querySelectorAll('.faq-item').forEach(i => {
                i.classList.remove('active');
                const icon = i.querySelector('.faq-question i');
                if (icon) {
                    icon.setAttribute('data-lucide', 'plus');
                    icon.style.color = 'var(--text-muted)';
                }
            });

            if (!isActive) {
                item.classList.add('active');
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.setAttribute('data-lucide', 'minus');
                    icon.style.color = 'var(--accent-cyan)';
                }
            }
            lucide.createIcons();
        }
    </script>
    <!-- Modal: Public "Rate Us / Submit Review" -->
    <div id="rateUsModal" class="lead-modal-overlay" style="display: none;">
        <div class="lead-modal-container" style="max-width: 520px;">
            <button class="lead-modal-close" onclick="closeRateUsModal()" aria-label="Close modal">&times;</button>
            
            <div class="lead-modal-header" style="text-align: center; margin-bottom: 1.25rem;">
                <div class="logo-icon" style="margin: 0 auto 0.75rem auto; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <i data-lucide="star" style="width: 22px; height: 22px; fill: #ffffff;"></i>
                </div>
                <h3 style="font-family: var(--font-heading); font-size: 1.4rem; font-weight: 800; color: #ffffff;">Rate Our Services</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">Share your honest rating and feedback with Marg Soft Solutions.</p>
            </div>

            <div id="rateFormAlert" style="display: none; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-size: 0.85rem;"></div>

            <form id="rateUsForm" onsubmit="submitRateUsForm(event)">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Your Name <span style="color: #ef4444;">*</span>
                        </label>
                        <input type="text" name="name" id="rate_name" required placeholder="Full Name" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Company / Firm Name
                        </label>
                        <input type="text" name="company" id="rate_company" placeholder="e.g. Gantavya Pharmacy" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            City / Location
                        </label>
                        <input type="text" name="city" id="rate_city" placeholder="e.g. Kanpur, UP" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                            Select Rating (Stars) <span style="color: #ef4444;">*</span>
                        </label>
                        <select name="rating" id="rate_stars" required style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                            <option value="5.0" selected>5.0 ★★★★★ (Excellent)</option>
                            <option value="4.9">4.9 ★★★★★ (Superb)</option>
                            <option value="4.5">4.5 ★★★★☆ (Very Good)</option>
                            <option value="4.0">4.0 ★★★★☆ (Good)</option>
                            <option value="3.0">3.0 ★★★☆☆ (Average)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                        Product / Service Used
                    </label>
                    <select name="service_name" id="rate_service" style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.9rem; outline: none;">
                        <option value="Marg ERP 9+">Marg ERP 9+</option>
                        <option value="Marg Books">Marg Books</option>
                        <option value="Marg Cloud VPC">Marg Cloud VPC</option>
                        <option value="WhatsApp CRM & Billing">WhatsApp CRM & Billing</option>
                    </select>
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.35rem;">
                        Your Detailed Review <span style="color: #ef4444;">*</span>
                    </label>
                    <textarea name="review_text" id="rate_text" rows="3" required placeholder="Write your experience with Marg ERP software and service support..." style="width: 100%; padding: 0.65rem 0.9rem; background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 10px; color: #ffffff; font-size: 0.875rem; outline: none; resize: vertical;"></textarea>
                </div>

                <button type="submit" id="btnSubmitRate" class="btn btn-cyan w-full" style="padding: 0.8rem; font-size: 0.95rem; font-weight: 700; width: 100%;">
                    <span>Submit Rating & Review →</span>
                </button>
            </form>
        </div>
    </div>

    <script>
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
                    alertBox.style.background = 'rgba(16, 185, 129, 0.2)';
                    alertBox.style.border = '1px solid #10b981';
                    alertBox.style.color = '#34d399';
                    alertBox.textContent = res.message;

                    document.getElementById('rateUsForm').reset();
                    setTimeout(() => {
                        closeRateUsModal();
                        alertBox.style.display = 'none';
                        window.location.reload();
                    }, 2000);
                } else {
                    alertBox.style.display = 'block';
                    alertBox.style.background = 'rgba(239, 68, 68, 0.2)';
                    alertBox.style.border = '1px solid #ef4444';
                    alertBox.style.color = '#fca5a5';
                    alertBox.textContent = res.message || 'Failed to submit review.';
                }
            } catch (err) {
                alertBox.style.display = 'block';
                alertBox.style.background = 'rgba(239, 68, 68, 0.2)';
                alertBox.style.border = '1px solid #ef4444';
                alertBox.style.color = '#fca5a5';
                alertBox.textContent = 'Server communication error. Please try again.';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span>Submit Rating & Review →</span>';
            }
        }
    </script>
</body>
</html>
