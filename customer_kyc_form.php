<?php
/**
 * Marg Soft Solution - Customer Details & KYC Document Submission Portal
 * Public & CRM Integrated Customer Details Filling Portal
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Details & KYC Submission - Friendly AI Solution</title>
    <meta name="description" content="Submit your customer details, PAN, Aadhaar, UDYAM, and GSTIN documents securely.">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-dark: #07090e;
            --card-bg: rgba(18, 24, 38, 0.75);
            --card-border: rgba(255, 255, 255, 0.08);
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.35);
            --accent: #8b5cf6;
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --input-bg: rgba(10, 14, 23, 0.8);
            --input-border: rgba(255, 255, 255, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(59, 130, 246, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.12) 0%, transparent 40%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .container {
            width: 100%;
            max-width: 900px;
        }

        .header-brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header-brand .logo {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: #fff;
            text-decoration: none;
            margin-bottom: 0.5rem;
        }

        .header-brand .logo i {
            color: var(--primary);
            font-size: 2rem;
        }

        .header-brand p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Glassmorphic Form Card */
        .kyc-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            position: relative;
            overflow: hidden;
        }

        .kyc-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent), var(--success));
        }

        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: #fff;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .kyc-card {
                padding: 1.5rem;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
        }

        .form-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-group label .req {
            color: var(--danger);
            margin-left: 3px;
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
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.3s;
        }

        .form-control {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            padding: 0.85rem 1rem 0.85rem 2.75rem;
            color: #fff;
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 15px var(--primary-glow);
        }

        .form-control:focus + i.prefix-icon {
            color: var(--primary);
        }

        /* Business Type Selector Radio Cards */
        .radio-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
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
            background: var(--input-bg);
            border: 2px solid var(--input-border);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .radio-card input[type="radio"]:checked + .radio-content {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.1);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.2);
        }

        .radio-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: var(--primary);
            transition: transform 0.3s;
        }

        .radio-card input[type="radio"]:checked + .radio-content .radio-icon {
            background: var(--primary);
            color: #fff;
            transform: scale(1.05);
        }

        .radio-text h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
        }

        .radio-text p {
            font-size: 0.775rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Document Component Block */
        .doc-block {
            background: rgba(10, 14, 23, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            transition: all 0.3s ease;
        }

        .doc-block:hover {
            border-color: rgba(255, 255, 255, 0.2);
        }

        .doc-header {
            margin-bottom: 1rem;
        }

        .doc-title {
            font-weight: 700;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #fff;
        }

        /* File Upload Box */
        .file-upload-box {
            border: 2px dashed var(--input-border);
            border-radius: 10px;
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.3s ease;
            position: relative;
        }

        .file-upload-box:hover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
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
            font-size: 1.75rem;
            color: var(--primary);
            margin-bottom: 0.4rem;
        }

        .file-upload-box p {
            font-size: 0.825rem;
            color: var(--text-muted);
        }

        .file-upload-box .file-preview {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--success);
            margin-top: 0.5rem;
            display: none;
        }

        /* Single Line Small Checkbox Container */
        .compact-terms-box {
            margin-top: 1rem;
            margin-bottom: 1.25rem;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            cursor: pointer;
            user-select: none;
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.18);
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            transition: all 0.3s ease;
        }

        .checkbox-container:hover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.1);
        }

        .checkbox-container input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
        }

        .checkbox-container .label-text {
            font-size: 0.8125rem;
            color: var(--text-main);
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        /* Modal Dialog Styling */
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s ease;
            padding: 1rem;
        }

        .modal-backdrop.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal-card {
            background: #0f172a;
            border: 1px solid var(--primary);
            border-radius: 16px;
            max-width: 550px;
            width: 100%;
            padding: 1.5rem;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8);
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal-backdrop.active .modal-card {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 0.85rem;
            margin-bottom: 1rem;
        }

        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            color: #fff;
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
        }

        .btn-close:hover {
            color: #fff;
        }

        .modal-body {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .dec-point {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.85rem;
        }

        .dec-point i {
            font-size: 1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .dec-point strong {
            color: #fff;
            display: block;
            margin-bottom: 2px;
        }

        .dec-point p {
            color: var(--text-muted);
            font-size: 0.8rem;
            line-height: 1.35;
        }

        .modal-footer {
            text-align: right;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 0.85rem;
        }

        .btn-modal-agree {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
        }

        /* Main Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 1rem;
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
            transition: all 0.3s ease;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(16, 185, 129, 0.4);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(0.5);
        }

        .alert-box {
            padding: 1rem;
            border-radius: 10px;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            display: none;
        }

        .alert-box.danger {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-box.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-brand">
        <a href="index.php" class="logo">
            <i class="fa-solid fa-shield-halved"></i> <?php echo defined('APP_NAME') ? APP_NAME : 'Friendly AI Solution'; ?>
        </a>
        <p>Customer Details & Mandatory Document KYC Portal</p>
    </div>

    <div class="kyc-card">
        <div id="alertBox" class="alert-box"></div>

        <form id="kycForm" enctype="multipart/form-data" onsubmit="handleKycSubmit(event)">
            <?php echo renderCsrfInput(); ?>

            <!-- SECTION 1: Personal & Business Info -->
            <div class="section-title">
                <i class="fa-solid fa-id-card"></i> 1. Basic Details
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Full Name <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="text" id="full_name" name="full_name" class="form-control" placeholder="Enter Full Legal Name" required>
                        <i class="fa-solid fa-user prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Email Address <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" class="form-control" placeholder="name@company.com" required>
                        <i class="fa-solid fa-envelope prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Phone Number <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="tel" id="phone" name="phone" class="form-control" placeholder="10-digit mobile number" maxlength="10" required>
                        <i class="fa-solid fa-phone prefix-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Firm / Company Name <span class="req">*</span></label>
                    <div class="input-wrapper">
                        <input type="text" id="firm_name" name="firm_name" class="form-control" placeholder="Enter Firm Name" required>
                        <i class="fa-solid fa-building prefix-icon"></i>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Business Registration Type -->
            <div class="section-title">
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
                            <p>Includes GSTIN Certificate & UDYAM</p>
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
                            <p>Requires UDYAM Certificate</p>
                        </div>
                    </div>
                </label>
            </div>

            <!-- SECTION 3: Documents Upload -->
            <div class="section-title">
                <i class="fa-solid fa-file-shield"></i> 3. Mandatory Documents Upload
            </div>

            <!-- 1. PAN Card Block (MANDATORY) -->
            <div class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-address-card text-primary"></i> PAN Card Details <span class="req">*</span>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>PAN Card Number <span class="req">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="pan_number" name="pan_number" class="form-control" placeholder="ABCDE1234F" maxlength="10" style="text-transform: uppercase;" required>
                            <i class="fa-solid fa-credit-card prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload PAN Card Copy <span class="req">*</span></label>
                        <div class="file-upload-box" onclick="document.getElementById('pan_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag PAN document file (JPG, PNG, PDF)</p>
                            <div id="pan_preview" class="file-preview"></div>
                            <input type="file" id="pan_doc" name="pan_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" required onchange="handleFileSelect(this, 'pan_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Aadhaar Card Block (MANDATORY) -->
            <div class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-fingerprint text-primary"></i> Aadhaar Card Details <span class="req">*</span>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>12-Digit Aadhaar Number <span class="req">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="aadhaar_number" name="aadhaar_number" class="form-control" placeholder="1234 5678 9012" maxlength="12" required>
                            <i class="fa-solid fa-id-badge prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload Aadhaar Card Copy <span class="req">*</span></label>
                        <div class="file-upload-box" onclick="document.getElementById('aadhaar_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag Aadhaar document file (JPG, PNG, PDF)</p>
                            <div id="aadhaar_preview" class="file-preview"></div>
                            <input type="file" id="aadhaar_doc" name="aadhaar_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" required onchange="handleFileSelect(this, 'aadhaar_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. UDYAM Registration Certificate Block (MANDATORY FOR BOTH REGISTERED & UNREGISTERED) -->
            <div class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-certificate text-primary"></i> UDYAM Registration Certificate Details <span class="req">*</span>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>UDYAM Registration No. <span class="req">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="udyam_number" name="udyam_number" class="form-control" placeholder="UDYAM-UP-00-0000000" style="text-transform: uppercase;" required>
                            <i class="fa-solid fa-stamp prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload UDYAM Certificate Copy <span class="req">*</span></label>
                        <div class="file-upload-box" onclick="document.getElementById('udyam_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag UDYAM Certificate file (JPG, PNG, PDF)</p>
                            <div id="udyam_preview" class="file-preview"></div>
                            <input type="file" id="udyam_doc" name="udyam_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" required onchange="handleFileSelect(this, 'udyam_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. GSTIN Certificate Block (CONDITIONAL: MANDATORY IF REGISTERED) -->
            <div id="gstinBlock" class="doc-block">
                <div class="doc-header">
                    <div class="doc-title">
                        <i class="fa-solid fa-file-invoice text-primary"></i> GSTIN Registration Certificate Details <span id="gstReqStar" class="req">*</span>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>GSTIN Number <span id="gstInputReqStar" class="req">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="gstin_number" name="gstin_number" class="form-control" placeholder="09AAAAA0000A1Z5" maxlength="15" style="text-transform: uppercase;" required>
                            <i class="fa-solid fa-receipt prefix-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload GST Certificate Copy <span id="gstDocReqStar" class="req">*</span></label>
                        <div class="file-upload-box" onclick="document.getElementById('gstin_doc').click()">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Click or drag GST Certificate file (JPG, PNG, PDF)</p>
                            <div id="gstin_preview" class="file-preview"></div>
                            <input type="file" id="gstin_doc" name="gstin_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" required onchange="handleFileSelect(this, 'gstin_preview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 4: Single Line Small Self-Declaration Checkbox -->
            <div class="compact-terms-box">
                <label class="checkbox-container">
                    <input type="checkbox" id="terms_consent" name="terms_consent" required onchange="toggleSubmitBtn(this)">
                    <span class="label-text">
                        I declare all uploaded documents are genuine. I agree to the 
                        <a href="javascript:void(0)" onclick="toggleTermsModal()" style="color: var(--primary); text-decoration: underline; font-weight: 600;">Self-Declaration & Terms</a>. <span class="req">*</span>
                    </span>
                </label>
            </div>

            <button type="submit" id="btnSubmit" class="btn-submit" disabled>
                <i class="fa-solid fa-shield-check"></i> Complete Secure KYC Registration
            </button>
        </form>
    </div>
</div>

<!-- Compact View Terms Modal -->
<div id="termsModal" class="modal-backdrop">
    <div class="modal-card">
        <div class="modal-header">
            <h3><i class="fa-solid fa-file-contract text-primary"></i> Self-Declaration & Consent Terms</h3>
            <button type="button" class="btn-close" onclick="toggleTermsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="dec-point">
                <i class="fa-solid fa-circle-check text-success"></i>
                <div>
                    <strong>1. Self-Uploaded Genuine Documents & Information</strong>
                    <p>I confirm that all entered details and document copies (PAN, Aadhaar, UDYAM, GSTIN) uploaded above are genuine, accurate, real, and self-uploaded by me.</p>
                </div>
            </div>
            <div class="dec-point">
                <i class="fa-solid fa-shield-halved text-primary"></i>
                <div>
                    <strong>2. Authorized Verification & Data Privacy Trust</strong>
                    <p>I trust Friendly AI Solution and authorize them to verify, process, and retain my submitted identity details and documents for KYC compliance, account setup, and legal verification.</p>
                </div>
            </div>
            <div class="dec-point">
                <i class="fa-solid fa-scale-balanced text-warning"></i>
                <div>
                    <strong>3. Legal Responsibility & Ownership</strong>
                    <p>I accept full legal responsibility for the authenticity of the submitted records and business documentation under applicable government regulations.</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-agree" onclick="toggleTermsModal()">Close & Return</button>
        </div>
    </div>
</div>

<script>
    function toggleRegFields(type) {
        const gstinBlock = document.getElementById('gstinBlock');
        const gstinInput = document.getElementById('gstin_number');
        const gstinDoc = document.getElementById('gstin_doc');

        if (type === 'registered') {
            gstinBlock.style.display = 'block';
            gstinInput.required = true;
            gstinDoc.required = true;
        } else {
            gstinBlock.style.display = 'none';
            gstinInput.required = false;
            gstinDoc.required = false;
        }
    }

    function handleFileSelect(input, previewId) {
        const preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            preview.innerText = 'Selected File: ' + input.files[0].name;
            preview.style.display = 'block';
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

        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Encrypting & Submitting KYC Data...';

        const formData = new FormData(form);

        fetch('api/submit_customer_kyc.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlert(data.message + ' Customer Lead ID: ' + data.lead_id, 'success');
                form.reset();
                btnSubmit.disabled = true;
                document.querySelectorAll('.file-preview').forEach(el => el.style.display = 'none');
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
</script>

</body>
</html>
