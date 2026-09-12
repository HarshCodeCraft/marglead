<?php
/**
 * Marg Soft Solution / Friendly AI Solution - Customer Details & KYC Document Submission Portal
 * Public & CRM Integrated Customer Registration & KYC Portal (Light Theme Enterprise Edition)
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Onboarding &amp; KYC Verification - Friendly AI Solution</title>
    <meta name="description" content="Secure customer registration & KYC document submission portal. Fast, encrypted, and compliant.">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --primary-border: #bfdbfe;
            --accent: #6366f1;
            --success: #059669;
            --success-light: #ecfdf5;
            --warning: #d97706;
            --warning-light: #fffbeb;
            --danger: #dc2626;
            --danger-light: #fef2f2;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --input-bg: #f8fafc;
            --input-focus-bg: #ffffff;
            --input-border: #cbd5e1;
            --input-border-focus: #2563eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 35px -10px rgba(15, 23, 42, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-page);
            background-image: 
                radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.06) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.05) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(99, 102, 241, 0.04) 0px, transparent 50%);
            color: var(--text-body);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 2.5rem 1rem;
            line-height: 1.5;
        }

        .container {
            width: 100%;
            max-width: 880px;
        }

        /* Top Brand Header */
        .header-brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header-brand .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #ffffff;
            border: 1px solid var(--card-border);
            padding: 0.35rem 1rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
        }

        .header-brand .logo {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--text-heading);
            text-decoration: none;
            letter-spacing: -0.5px;
            margin-bottom: 0.35rem;
        }

        .header-brand .logo i {
            color: var(--primary);
            font-size: 2rem;
        }

        .header-brand p {
            color: var(--text-muted);
            font-size: 0.95rem;
            max-width: 540px;
            margin: 0 auto;
        }

        /* Main Enterprise KYC Card */
        .kyc-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2.75rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .kyc-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #2563eb, #6366f1, #10b981);
        }

        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            margin-top: 0.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            color: var(--text-heading);
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .section-title .section-hint {
            font-family: 'Inter', sans-serif;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-left: auto;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem 0.5rem;
            }
            .kyc-card {
                padding: 1.5rem;
                border-radius: 16px;
            }
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 1.1rem;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-group label .req {
            color: var(--danger);
            font-weight: 700;
            margin-left: 2px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i.prefix-icon {
            position: absolute;
            left: 1rem;
            color: var(--text-muted);
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .form-control {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            padding: 0.8rem 1rem 0.8rem 2.65rem;
            color: var(--text-heading);
            font-family: inherit;
            font-size: 0.925rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .form-control::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .form-control:focus {
            outline: none;
            background: var(--input-focus-bg);
            border-color: var(--input-border-focus);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        .form-control:focus + i.prefix-icon {
            color: var(--primary);
        }

        /* Business Type Selector Radio Cards */
        .radio-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        @media (max-width: 640px) {
            .radio-cards {
                grid-template-columns: 1fr;
            }
        }

        .radio-card {
            position: relative;
            cursor: pointer;
        }

        .radio-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .radio-content {
            background: #ffffff;
            border: 2px solid var(--card-border);
            border-radius: 14px;
            padding: 1.25rem 1.15rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.25s ease;
            box-shadow: var(--shadow-sm);
        }

        .radio-card:hover .radio-content {
            border-color: #93c5fd;
            background: #f8faff;
        }

        .radio-card input[type="radio"]:checked + .radio-content {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 8px 20px -4px rgba(37, 99, 235, 0.18);
        }

        .radio-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--text-muted);
            transition: all 0.25s;
            flex-shrink: 0;
        }

        .radio-card input[type="radio"]:checked + .radio-content .radio-icon {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.35);
        }

        .radio-text h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-text p {
            font-size: 0.775rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* Document Component Block */
        .doc-block {
            background: #f8fafc;
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 1.35rem;
            margin-bottom: 1.25rem;
            transition: all 0.2s ease;
        }

        .doc-block:hover {
            border-color: #cbd5e1;
            box-shadow: var(--shadow-sm);
        }

        .doc-header {
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .doc-title {
            font-weight: 700;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-heading);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.65rem;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .badge-mandatory {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .badge-optional {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .badge-either {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* File Upload Box */
        .file-upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 10px;
            padding: 1.15rem 1rem;
            text-align: center;
            cursor: pointer;
            background: #ffffff;
            transition: all 0.25s ease;
            position: relative;
        }

        .file-upload-box:hover {
            border-color: var(--primary);
            background: #f0f7ff;
        }

        .file-upload-box input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-upload-box i {
            font-size: 1.6rem;
            color: var(--primary);
            margin-bottom: 0.35rem;
        }

        .file-upload-box p {
            font-size: 0.825rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .file-upload-box .file-preview {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--success);
            margin-top: 0.5rem;
            display: none;
            background: var(--success-light);
            border: 1px solid #a7f3d0;
            padding: 0.35rem 0.65rem;
            border-radius: 6px;
            word-break: break-all;
        }

        /* Notice Banner */
        .notice-banner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
            line-height: 1.4;
        }

        .notice-banner i {
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        /* Single Line Small Checkbox Container */
        .compact-terms-box {
            margin-top: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            cursor: pointer;
            user-select: none;
            background: #f8fafc;
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 0.8rem 1rem;
            transition: all 0.2s ease;
        }

        .checkbox-container:hover {
            border-color: var(--primary);
            background: #f0f7ff;
        }

        .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-container .label-text {
            font-size: 0.85rem;
            color: var(--text-body);
            font-weight: 500;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        /* Main Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 1.05rem;
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.4);
            transition: all 0.25s ease;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px -5px rgba(37, 99, 235, 0.5);
        }

        .btn-submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .alert-box {
            padding: 0.95rem 1.15rem;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            display: none;
        }

        .alert-box.danger {
            background: var(--danger-light);
            border: 1px solid #fca5a5;
            color: var(--danger);
        }

        .alert-box.success {
            background: var(--success-light);
            border: 1px solid #a7f3d0;
            color: var(--success);
        }

        /* Modal Dialog Styling (Light Theme) */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: all 0.25s ease;
            padding: 1rem;
        }

        .modal-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-card {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 18px;
            max-width: 540px;
            width: 100%;
            padding: 1.75rem;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
            transform: translateY(15px);
            transition: transform 0.25s ease;
        }

        .modal-backdrop.active .modal-card {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 0.85rem;
            margin-bottom: 1.15rem;
        }

        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.25rem;
            cursor: pointer;
            transition: color 0.2s;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-close:hover {
            color: var(--text-heading);
            background: #f1f5f9;
        }

        .modal-body {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.35rem;
        }

        .dec-point {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            font-size: 0.875rem;
        }

        .dec-point i {
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .dec-point strong {
            color: var(--text-heading);
            display: block;
            margin-bottom: 2px;
            font-weight: 600;
        }

        .dec-point p {
            color: var(--text-muted);
            font-size: 0.825rem;
            line-height: 1.4;
        }

        .modal-footer {
            text-align: right;
            border-top: 1px solid var(--card-border);
            padding-top: 1rem;
        }

        .btn-modal-agree {
            background: var(--primary);
            color: #ffffff;
            border: none;
            padding: 0.6rem 1.35rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-modal-agree:hover {
            background: var(--primary-hover);
        }

        /* Footer trust note */
        .footer-trust {
            text-align: center;
            margin-top: 1.75rem;
            font-size: 0.825rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .footer-trust span {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Top Brand Area -->
    <div class="header-brand">
        <div class="brand-badge">
            <i class="fa-solid fa-shield-check" style="color: #059669;"></i> Government Compliant KYC Verification
        </div>
        <br>
        <a href="index.php" class="logo">
            <i class="fa-solid fa-shield-halved"></i> <?php echo defined('APP_NAME') ? APP_NAME : 'Friendly AI Solution'; ?>
        </a>
        <p>Customer Onboarding &amp; Business Verification Portal. Secure, automated, and confidential.</p>
    </div>

    <!-- Main Registration & KYC Card -->
    <div class="kyc-card">
        <div id="alertBox" class="alert-box"></div>

        <form id="kycForm" enctype="multipart/form-data" onsubmit="handleKycSubmit(event)">
            <?php echo renderCsrfInput(); ?>

            <!-- SECTION 1: Personal & Business Info -->
            <div class="section-title">
                <i class="fa-solid fa-id-card"></i> 1. Basic Details &amp; Login Credentials
                <span class="section-hint"><span class="req">*</span> Required fields</span>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Full Name <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="text" id="full_name" name="full_name" class="form-control" placeholder="e.g. Rahul Sharma" required>
                        <i class="fa-solid fa-user prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address (Login ID) <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required>
                        <i class="fa-solid fa-envelope prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Mobile Number <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="tel" id="phone" name="phone" class="form-control" placeholder="10-digit mobile number" maxlength="10" required>
                        <i class="fa-solid fa-phone prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Firm / Company Name <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="text" id="firm_name" name="firm_name" class="form-control" placeholder="e.g. Sharma Enterprises" required>
                        <i class="fa-solid fa-building prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Create Login Password <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-control" placeholder="Minimum 6 characters" minlength="6" required onkeyup="checkPasswordMatch()">
                        <i class="fa-solid fa-lock prefix-icon"></i>
                        <i class="fa-solid fa-eye" onclick="togglePasswordVisibility('password', this)" style="position: absolute; right: 1rem; cursor: pointer; color: var(--text-muted);" title="Show/Hide Password"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm Password <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter your password" minlength="6" required onkeyup="checkPasswordMatch()">
                        <i class="fa-solid fa-shield-halved prefix-icon"></i>
                        <i class="fa-solid fa-eye" onclick="togglePasswordVisibility('confirm_password', this)" style="position: absolute; right: 1rem; cursor: pointer; color: var(--text-muted);" title="Show/Hide Password"></i>
                    </div>
                    <div id="pwdMatchNotice" style="font-size: 0.775rem; font-weight: 600; margin-top: 3px; display: none;"></div>
                </div>
            </div>

            <!-- SECTION 2: Business Registration Type -->
            <div class="section-title" style="margin-top: 1.5rem;">
                <i class="fa-solid fa-briefcase"></i> 2. Business Registration Type
            </div>

            <div class="radio-cards">
                <label class="radio-card">
                    <input type="radio" name="registration_type" value="registered" checked onchange="toggleRegFields('registered')">
                    <div class="radio-content">
                        <div class="radio-icon">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </div>
                        <div class="radio-text">
                            <h4>Registered Business (GST)</h4>
                            <p><strong>Mandatory:</strong> GSTIN Certificate Details</p>
                        </div>
                    </div>
                </label>

                <label class="radio-card">
                    <input type="radio" name="registration_type" value="unregistered" onchange="toggleRegFields('unregistered')">
                    <div class="radio-content">
                        <div class="radio-icon">
                            <i class="fa-solid fa-store"></i>
                        </div>
                        <div class="radio-text">
                            <h4>Unregistered Business</h4>
                            <p><strong>Mandatory:</strong> Any 1 of PAN, Aadhaar or UDYAM</p>
                        </div>
                    </div>
                </label>
            </div>

            <!-- Dynamic Information Notice -->
            <div id="docNoticeBanner" class="notice-banner">
                <i class="fa-solid fa-circle-info"></i>
                <div id="docNoticeText">
                    <strong>Registered Business Selected:</strong> GSTIN Certificate Details are <strong>Mandatory</strong>. PAN, Aadhaar, and UDYAM documents are Optional.
                </div>
            </div>

            <!-- SECTION 3: Documents Upload -->
            <div class="section-title">
                <i class="fa-solid fa-file-shield"></i> 3. Business &amp; Identity Verification Documents
            </div>

            <!-- 1. GSTIN Certificate Block (MANDATORY IF REGISTERED) -->
            <div id="gstinBlock" class="doc-block" style="border-left: 4px solid var(--primary); background: #f0f7ff;">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-file-invoice" style="color: var(--primary);"></i> GSTIN Registration Certificate Details
                    </div>
                    <span id="gstinBadge" class="badge badge-mandatory"><i class="fa-solid fa-asterisk"></i> Mandatory</span>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>GSTIN Number <span class="req">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="gstin_number" name="gstin_number" class="form-control" placeholder="e.g. 09AAAAA0000A1Z5" maxlength="15" style="text-transform: uppercase;" required>
                            <i class="fa-solid fa-receipt prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload GST Certificate Copy <span class="req">*</span></label>
                        <div class="file-upload-box" onclick="document.getElementById('gstin_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag GST Certificate (JPG, PNG, PDF)</p>
                            <div id="gstin_preview" class="file-preview"></div>
                            <input type="file" id="gstin_doc" name="gstin_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" required onchange="handleFileSelect(this, 'gstin_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. PAN Card Block -->
            <div class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-address-card" style="color: var(--primary);"></i> PAN Card Details
                    </div>
                    <span id="panBadge" class="badge badge-optional">Optional</span>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>PAN Card Number</label>
                        <div class="input-wrapper">
                            <input type="text" id="pan_number" name="pan_number" class="form-control" placeholder="e.g. ABCDE1234F" maxlength="10" style="text-transform: uppercase;">
                            <i class="fa-solid fa-credit-card prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload PAN Card Copy</label>
                        <div class="file-upload-box" onclick="document.getElementById('pan_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag PAN document (JPG, PNG, PDF)</p>
                            <div id="pan_preview" class="file-preview"></div>
                            <input type="file" id="pan_doc" name="pan_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" onchange="handleFileSelect(this, 'pan_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Aadhaar Card Block -->
            <div class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-fingerprint" style="color: var(--primary);"></i> Aadhaar Card Details
                    </div>
                    <span id="aadhaarBadge" class="badge badge-optional">Optional</span>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>12-Digit Aadhaar Number</label>
                        <div class="input-wrapper">
                            <input type="text" id="aadhaar_number" name="aadhaar_number" class="form-control" placeholder="1234 5678 9012" maxlength="12">
                            <i class="fa-solid fa-id-badge prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload Aadhaar Card Copy</label>
                        <div class="file-upload-box" onclick="document.getElementById('aadhaar_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag Aadhaar document (JPG, PNG, PDF)</p>
                            <div id="aadhaar_preview" class="file-preview"></div>
                            <input type="file" id="aadhaar_doc" name="aadhaar_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" onchange="handleFileSelect(this, 'aadhaar_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. UDYAM Registration Certificate Block -->
            <div class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-certificate" style="color: var(--primary);"></i> UDYAM Registration Certificate Details
                    </div>
                    <span id="udyamBadge" class="badge badge-optional">Optional</span>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>UDYAM Registration No.</label>
                        <div class="input-wrapper">
                            <input type="text" id="udyam_number" name="udyam_number" class="form-control" placeholder="e.g. UDYAM-UP-00-0000000" style="text-transform: uppercase;">
                            <i class="fa-solid fa-stamp prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload UDYAM Certificate Copy</label>
                        <div class="file-upload-box" onclick="document.getElementById('udyam_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag UDYAM Certificate (JPG, PNG, PDF)</p>
                            <div id="udyam_preview" class="file-preview"></div>
                            <input type="file" id="udyam_doc" name="udyam_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" onchange="handleFileSelect(this, 'udyam_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 4: Single Line Small Self-Declaration Checkbox -->
            <div class="compact-terms-box">
                <label class="checkbox-container">
                    <input type="checkbox" id="terms_consent" name="terms_consent" required onchange="toggleSubmitBtn(this)">
                    <span class="label-text">
                        I declare all submitted information and uploaded documents are genuine and accurate. I accept the 
                        <a href="javascript:void(0)" onclick="toggleTermsModal()" style="color: var(--primary); font-weight: 600; text-decoration: underline;">Self-Declaration &amp; Terms</a>. <span class="req">*</span>
                    </span>
                </label>
            </div>

            <button type="submit" id="btnSubmit" class="btn-submit" disabled>
                <i class="fa-solid fa-shield-check"></i> Complete Secure KYC Registration
            </button>
        </form>
    </div>

    <!-- Trust indicators footer -->
    <div class="footer-trust">
        <span><i class="fa-solid fa-lock text-primary"></i> 256-Bit SSL Secured</span>
        <span><i class="fa-solid fa-server text-primary"></i> Dedicated Cloud Storage</span>
        <span><i class="fa-solid fa-user-shield text-primary"></i> Data Privacy Compliant</span>
    </div>
</div>

<!-- Compact View Terms Modal (Light Theme) -->
<div id="termsModal" class="modal-backdrop">
    <div class="modal-card">
        <div class="modal-header">
            <h3><i class="fa-solid fa-file-contract" style="color: var(--primary);"></i> Self-Declaration &amp; Consent Terms</h3>
            <button type="button" class="btn-close" onclick="toggleTermsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="dec-point">
                <i class="fa-solid fa-circle-check" style="color: var(--success);"></i>
                <div>
                    <strong>1. Self-Uploaded Genuine Documents &amp; Information</strong>
                    <p>I confirm that all entered business details and document copies (GSTIN, PAN, Aadhaar, UDYAM) uploaded above are genuine, accurate, real, and self-uploaded by me.</p>
                </div>
            </div>
            <div class="dec-point">
                <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i>
                <div>
                    <strong>2. Authorized Verification &amp; Data Privacy Trust</strong>
                    <p>I authorize Friendly AI Solution to verify, process, and retain my submitted identity details and documents for KYC compliance, account provisioning, and legal verification.</p>
                </div>
            </div>
            <div class="dec-point">
                <i class="fa-solid fa-scale-balanced" style="color: var(--warning);"></i>
                <div>
                    <strong>3. Legal Responsibility &amp; Ownership</strong>
                    <p>I accept full legal responsibility for the authenticity of the submitted records and business documentation under applicable Indian business and tax regulations.</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-agree" onclick="toggleTermsModal()">I Understand &amp; Close</button>
        </div>
    </div>
</div>

<!-- Professional Registration Success Modal (Light Theme) -->
<div id="successModal" class="modal-backdrop">
    <div class="modal-card" style="max-width: 500px; text-align: center;">
        <div style="padding: 0.5rem 0.25rem;">
            <!-- Verification Icon -->
            <div style="width: 72px; height: 72px; margin: 0 auto 1.25rem; border-radius: 50%; background: #ecfdf5; border: 2px solid #10b981; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.25);">
                <i class="fa-solid fa-check" style="font-size: 2rem; color: #059669;"></i>
            </div>

            <!-- Title -->
            <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.4rem; font-weight: 700; color: var(--text-heading); margin-bottom: 0.5rem;">
                Registration Completed Successfully!
            </h3>

            <!-- Status Pill -->
            <div style="display: inline-flex; align-items: center; gap: 0.5rem; background: #fef3c7; border: 1px solid #fde68a; color: #92400e; padding: 0.35rem 1rem; border-radius: 50px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1.25rem;">
                <i class="fa-solid fa-clock-rotate-left"></i> Account Under Review
            </div>

            <!-- Description -->
            <p style="color: var(--text-muted); font-size: 0.925rem; line-height: 1.55; margin-bottom: 1.25rem;">
                Your account registration and verification documents have been securely submitted. 
                Your profile is currently <strong style="color: var(--text-heading);">under review</strong> by our administration team.
            </p>

            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.5rem; text-align: left; font-size: 0.85rem; color: var(--text-body);">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem; color: var(--primary); font-weight: 600;">
                    <i class="fa-solid fa-lock"></i> <strong>Account Security Notice:</strong>
                </div>
                You will be able to log in with your email and password as soon as your account is approved and activated by the administrator.
            </div>

            <div style="display: flex; gap: 0.75rem; justify-content: center;">
                <a href="auth/login.php" class="btn-modal-agree" style="flex: 1; text-align: center; text-decoration: none; padding: 0.8rem 1rem; background: var(--primary); display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.95rem;">
                    <i class="fa-solid fa-right-to-bracket"></i> Go to Login
                </a>
                <button type="button" onclick="closeSuccessModal()" style="padding: 0.8rem 1.25rem; background: #f1f5f9; border: 1px solid var(--card-border); border-radius: 8px; color: var(--text-heading); font-weight: 600; cursor: pointer; transition: background 0.2s;">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function togglePasswordVisibility(id, icon) {
        const input = document.getElementById(id);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function checkPasswordMatch() {
        const pwd = document.getElementById('password').value;
        const cpwd = document.getElementById('confirm_password').value;
        const notice = document.getElementById('pwdMatchNotice');

        if (!cpwd) {
            notice.style.display = 'none';
            return;
        }

        notice.style.display = 'block';
        if (pwd === cpwd) {
            notice.style.color = '#059669';
            notice.innerHTML = '<i class="fa-solid fa-check"></i> Passwords match';
        } else {
            notice.style.color = '#dc2626';
            notice.innerHTML = '<i class="fa-solid fa-xmark"></i> Passwords do not match';
        }
    }

    function openSuccessModal() {
        document.getElementById('successModal').classList.add('active');
    }

    function closeSuccessModal() {
        document.getElementById('successModal').classList.remove('active');
    }

    function toggleRegFields(type) {
        const gstinBlock = document.getElementById('gstinBlock');
        const gstinInput = document.getElementById('gstin_number');
        const gstinDoc = document.getElementById('gstin_doc');

        const gstinBadge = document.getElementById('gstinBadge');
        const panBadge = document.getElementById('panBadge');
        const aadhaarBadge = document.getElementById('aadhaarBadge');
        const udyamBadge = document.getElementById('udyamBadge');

        const noticeText = document.getElementById('docNoticeText');

        if (type === 'registered') {
            // Registered: GSTIN is Mandatory, other docs are Optional
            gstinBlock.style.display = 'block';
            gstinInput.required = true;
            gstinDoc.required = true;

            gstinBadge.className = 'badge badge-mandatory';
            gstinBadge.innerHTML = '<i class="fa-solid fa-asterisk"></i> Mandatory';

            panBadge.className = 'badge badge-optional';
            panBadge.innerText = 'Optional';

            aadhaarBadge.className = 'badge badge-optional';
            aadhaarBadge.innerText = 'Optional';

            udyamBadge.className = 'badge badge-optional';
            udyamBadge.innerText = 'Optional';

            noticeText.innerHTML = '<strong>Registered Business Selected:</strong> GSTIN Certificate Details are <strong>Mandatory</strong>. PAN, Aadhaar, and UDYAM documents are Optional.';
        } else {
            // Unregistered: GSTIN is Hidden, at least ONE of PAN, Aadhaar, or UDYAM is Mandatory
            gstinBlock.style.display = 'none';
            gstinInput.required = false;
            gstinDoc.required = false;

            panBadge.className = 'badge badge-either';
            panBadge.innerHTML = '<i class="fa-solid fa-asterisk"></i> At least 1 Required';

            aadhaarBadge.className = 'badge badge-either';
            aadhaarBadge.innerHTML = '<i class="fa-solid fa-asterisk"></i> At least 1 Required';

            udyamBadge.className = 'badge badge-either';
            udyamBadge.innerHTML = '<i class="fa-solid fa-asterisk"></i> At least 1 Required';

            noticeText.innerHTML = '<strong>Unregistered Business Selected:</strong> At least <strong>ONE</strong> complete document (PAN Card, Aadhaar Card, or UDYAM Certificate) with document upload is <strong>Mandatory</strong>.';
        }
    }

    function handleFileSelect(input, previewId) {
        const preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            preview.innerHTML = '<i class="fa-solid fa-file-check"></i> ' + input.files[0].name;
            preview.style.display = 'inline-block';
        } else {
            preview.style.display = 'none';
        }
    }

    function toggleSubmitBtn(checkbox) {
        const btnSubmit = document.getElementById('btnSubmit');
        btnSubmit.disabled = !checkbox.checked;
    }

    function toggleTermsModal() {
        const modal = document.getElementById('termsModal');
        modal.classList.toggle('active');
    }

    function showAlert(msg, type) {
        const box = document.getElementById('alertBox');
        box.className = 'alert-box ' + type;
        box.innerHTML = (type === 'danger' ? '<i class="fa-solid fa-circle-exclamation"></i> ' : '<i class="fa-solid fa-circle-check"></i> ') + msg;
        box.style.display = 'block';
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function handleKycSubmit(e) {
        e.preventDefault();
        const form = document.getElementById('kycForm');
        const btnSubmit = document.getElementById('btnSubmit');
        const consentCheckbox = document.getElementById('terms_consent');

        if (!consentCheckbox.checked) {
            showAlert('Please check the Self-Declaration & Consent checkbox to submit.', 'danger');
            return;
        }

        const pwd = document.getElementById('password').value;
        const cpwd = document.getElementById('confirm_password').value;

        if (pwd.length < 6) {
            showAlert('Password must be at least 6 characters long.', 'danger');
            return;
        }

        if (pwd !== cpwd) {
            showAlert('Password and Confirm Password do not match.', 'danger');
            return;
        }

        // Check Business Type Specific Document Rules
        const regType = document.querySelector('input[name="registration_type"]:checked').value;

        if (regType === 'registered') {
            const gstinNum = document.getElementById('gstin_number').value.trim();
            const gstinDoc = document.getElementById('gstin_doc').files;
            if (!gstinNum) {
                showAlert('Please provide a valid 15-character GSTIN Number.', 'danger');
                document.getElementById('gstin_number').focus();
                return;
            }
            if (gstinDoc.length === 0) {
                showAlert('Please upload a copy of your GST Registration Certificate.', 'danger');
                document.getElementById('gstinBlock').scrollIntoView({ behavior: 'smooth' });
                return;
            }
        } else {
            // Unregistered: At least ONE of PAN, Aadhaar, or UDYAM must be complete (both number + file)
            const panNum = document.getElementById('pan_number').value.trim();
            const panDoc = document.getElementById('pan_doc').files;

            const aadhaarNum = document.getElementById('aadhaar_number').value.trim();
            const aadhaarDoc = document.getElementById('aadhaar_doc').files;

            const udyamNum = document.getElementById('udyam_number').value.trim();
            const udyamDoc = document.getElementById('udyam_doc').files;

            let validDocCount = 0;

            if (panNum && panDoc.length > 0) validDocCount++;
            if (aadhaarNum && aadhaarDoc.length > 0) validDocCount++;
            if (udyamNum && udyamDoc.length > 0) validDocCount++;

            if (validDocCount === 0) {
                showAlert('For Unregistered Business, at least ONE document (PAN Card, Aadhaar Card, or UDYAM Certificate) with its number and document upload is mandatory.', 'danger');
                document.getElementById('docNoticeBanner').scrollIntoView({ behavior: 'smooth' });
                return;
            }

            // Check if any was partially filled
            if (panNum && panDoc.length === 0) {
                showAlert('You entered a PAN Number. Please also upload the PAN Card copy.', 'danger');
                return;
            }
            if (!panNum && panDoc.length > 0) {
                showAlert('You uploaded a PAN Card file. Please also enter the PAN Card Number.', 'danger');
                return;
            }

            if (aadhaarNum && aadhaarDoc.length === 0) {
                showAlert('You entered an Aadhaar Number. Please also upload the Aadhaar Card copy.', 'danger');
                return;
            }
            if (!aadhaarNum && aadhaarDoc.length > 0) {
                showAlert('You uploaded an Aadhaar Card file. Please also enter the 12-digit Aadhaar Number.', 'danger');
                return;
            }

            if (udyamNum && udyamDoc.length === 0) {
                showAlert('You entered a UDYAM Number. Please also upload the UDYAM Certificate copy.', 'danger');
                return;
            }
            if (!udyamNum && udyamDoc.length > 0) {
                showAlert('You uploaded a UDYAM Certificate file. Please also enter the UDYAM Registration Number.', 'danger');
                return;
            }
        }

        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting KYC Registration...';

        const formData = new FormData(form);

        fetch('api/submit_customer_kyc.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                form.reset();
                btnSubmit.disabled = true;
                document.querySelectorAll('.file-preview').forEach(el => el.style.display = 'none');
                const notice = document.getElementById('pwdMatchNotice');
                if (notice) notice.style.display = 'none';
                const alertBox = document.getElementById('alertBox');
                if (alertBox) alertBox.style.display = 'none';
                toggleRegFields('registered'); // Reset to default state
                openSuccessModal();
            } else {
                showAlert(data.error || 'Failed to submit customer details.', 'danger');
                btnSubmit.disabled = false;
            }
        })
        .catch(err => {
            showAlert('An unexpected server error occurred during submission.', 'danger');
            btnSubmit.disabled = false;
        })
        .finally(() => {
            btnSubmit.innerHTML = '<i class="fa-solid fa-shield-check"></i> Complete Secure KYC Registration';
        });
    }

    // Initialize fields state on page load
    document.addEventListener('DOMContentLoaded', () => {
        toggleRegFields('registered');
    });
</script>

</body>
</html>
