<?php
/**
 * Marg ERP CRM - PHPMailer Helper & Mail Utility
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    
    /**
     * Wrap email contents in a premium, high-converting HTML advertisement template
     */
    public static function wrapHTMLTemplate($title, $header_title, $subtitle, $content_body, $cta_text = '', $cta_url = '') {
        $cta_button = '';
        if (!empty($cta_text) && !empty($cta_url)) {
            $cta_button = "
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . htmlspecialchars($cta_url) . "' style='background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 15px; display: inline-block; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.4); letter-spacing: 0.5px;'>
                    " . htmlspecialchars($cta_text) . "
                </a>
            </div>";
        }
        
        $html = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>" . htmlspecialchars($title) . "</title>
</head>
<body style='margin: 0; padding: 0; background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; -webkit-font-smoothing: antialiased;'>
    <table border='0' cellpadding='0' cellspacing='0' width='100%' style='background-color: #f8fafc; padding: 40px 0;'>
        <tr>
            <td align='center'>
                <table border='0' cellpadding='0' cellspacing='0' width='600' style='background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);'>
                    <!-- Premium Gradient Header -->
                    <tr>
                        <td style='background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); padding: 35px 40px; text-align: center; border-bottom: 4px solid #f97316;'>
                            <div style='font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: 1px; margin-bottom: 8px;'>
                                <span style='color: #f97316;'>MARG</span> SOFT SOLUTIONS
                            </div>
                            <div style='font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 2px; font-weight: 600;'>Advanced Lead & Enterprise ERP CRM</div>
                        </td>
                    </tr>
                    
                    <!-- Visual Feature Image Banner -->
                    <tr>
                        <td style='background-color: #eff6ff; padding: 30px 40px; text-align: center; border-bottom: 1px solid #e2e8f0;'>
                            <h1 style='font-size: 22px; font-weight: 800; color: #1e293b; margin: 0 0 10px 0; line-height: 1.3;'>" . htmlspecialchars($header_title) . "</h1>
                            <p style='font-size: 14px; color: #64748b; margin: 0; font-weight: 500;'>" . htmlspecialchars($subtitle) . "</p>
                        </td>
                    </tr>
                    
                    <!-- Main Body Card Container -->
                    <tr>
                        <td style='padding: 40px; background-color: #ffffff;'>
                            <div style='font-size: 15px; color: #334155; line-height: 1.7;'>
                                " . $content_body . "
                            </div>
                            
                            " . $cta_button . "
                        </td>
                    </tr>
                    
                    <!-- Corporate Footer Branding -->
                    <tr>
                        <td style='background-color: #f1f5f9; padding: 30px 40px; text-align: center; border-top: 1px solid #e2e8f0;'>
                            <p style='font-size: 13px; font-weight: 600; color: #475569; margin: 0 0 8px 0;'>MARG SOFT SOLUTIONS INC.</p>
                            <p style='font-size: 11px; color: #64748b; margin: 0 0 15px 0;'>Opp. Okhla Metro Station, Phase III, New Delhi - 110020</p>
                            <div style='margin-bottom: 20px;'>
                                <a href='https://margsoft.com' style='color: #3b82f6; text-decoration: none; font-size: 12px; font-weight: 600; margin: 0 10px;'>Website</a> • 
                                <a href='mailto:support@margsoft.com' style='color: #3b82f6; text-decoration: none; font-size: 12px; font-weight: 600; margin: 0 10px;'>Support Center</a> • 
                                <a href='#' style='color: #3b82f6; text-decoration: none; font-size: 12px; font-weight: 600; margin: 0 10px;'>Privacy Policy</a>
                            </div>
                            <p style='font-size: 10px; color: #94a3b8; margin: 0;'>This is an automated operational transmission from Marg Soft Solution. If you did not request this communication, please ignore.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
        return $html;
    }
    
    /**
     * Send email via Gmail SMTP using PHPMailer and log in database
     */
    public static function send($to, $subject, $body) {
        global $db_connected, $pdo;
        
        $status = 'Sent';
        $mail = new PHPMailer(true);
        
        try {
            // SMTP Server configurations
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'harshuharshu609@gmail.com';
            $mail->Password   = 'ijmyilcxvyicaevb'; // App password without spaces
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Sender and Recipient settings
            $mail->setFrom('harshuharshu609@gmail.com', 'MARG SOFT SOLUTIONS');
            $mail->addAddress($to);
            
            // Email message details
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            $mail->send();
        } catch (Exception $e) {
            $status = 'Failed: ' . $mail->ErrorInfo;
            
            // Local PHP mail() fallback
            try {
                $headers = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: MARG SOFT SOLUTIONS <noreply@marglead.com>\r\n";
                $success = @mail($to, $subject, $body, $headers);
                if ($success) {
                    $status = 'Sent (Fallback)';
                }
            } catch (Exception $fallbackEx) {
                // Ignore fallback exception
            }
        }
        
        // Save to database sent_emails log
        if ($db_connected && $pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO sent_emails (recipient, subject, body, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$to, $subject, $body, $status]);
            } catch (PDOException $e) {
                error_log("Failed to log sent email to DB: " . $e->getMessage());
            }
        }
        
        return $status === 'Sent' || strpos($status, 'Sent') !== false;
    }
    
    /**
     * Send User Approval/Decline Notifications using dynamic layout wrapper
     */
    public static function sendUserApproval($email, $name, $status) {
        $subject = "Marg Soft Solution Account Status: " . ($status === 'Active' ? 'Approved' : 'Suspended/Declined');
        
        $title = "Account Status Notification";
        $header_title = $status === 'Active' ? "Welcome to the Team, " . $name . "!" : "Account Registration Notice";
        $subtitle = $status === 'Active' ? "Your Marg Soft Solution profile has been activated." : "Update on your Marg Soft Solution access request.";
        
        $body = "<p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>";
        if ($status === 'Active') {
            $body .= "<p>We are excited to let you know that your account registration request has been reviewed and successfully <strong>authorized</strong> by the site administrator.</p>";
            $body .= "<p>Your customized operational permissions matrix is active. You can now log in, schedule demo sessions, generate quotations, and manage workflows on your profile panel.</p>";
            
            $cta_text = "Launch CRM Dashboard";
            $cta_url = "http://localhost/marglead/auth/login.php";
        } else {
            $body .= "<p>We regret to inform you that your request to access the Marg Soft Solution database has been <strong>declined</strong> or <strong>suspended</strong> at this time.</p>";
            $body .= "<p>If you believe this is an error or require manual configuration, please get in touch with the management team.</p>";
            
            $cta_text = "Contact Manager";
            $cta_url = "mailto:admin@marglead.com";
        }
        
        $compiledBody = self::wrapHTMLTemplate($title, $header_title, $subtitle, $body, $cta_text, $cta_url);
        return self::send($email, $subject, $compiledBody);
    }
    
    /**
     * Send new registration pending approval notification
     */
    public static function sendUserRegistrationNotification($email, $name) {
        // 1. Email to Admin
        $adminSubject = "Pending Registration Review - Operator: " . $name;
        $adminTitle = "Pending Approval Review";
        $adminHeader = "New Operator Sign-up";
        $adminSubtitle = "Configure privileges and approve user registration request.";
        
        $adminBody = "<p>Hello Admin,</p>";
        $adminBody .= "<p>A new operator profile has created a registration request on the CRM portal. Details are listed below:</p>";
        $adminBody .= "<div style='background-color: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin: 20px 0;'>";
        $adminBody .= "<p style='margin: 5px 0;'><strong>Full Name:</strong> " . htmlspecialchars($name) . "</p>";
        $adminBody .= "<p style='margin: 5px 0;'><strong>Email Address:</strong> " . htmlspecialchars($email) . "</p>";
        $adminBody .= "</div>";
        $adminBody .= "<p>Please sign in to the administrative Users Matrix panel to review their credentials, configure their role powers (Sales Executive, Team Leader, etc.), and approve their login access.</p>";
        
        $adminCtaText = "Manage User Matrix";
        $adminCtaUrl = "http://localhost/marglead/index.php?page=admin_users";
        $adminCompiled = self::wrapHTMLTemplate($adminTitle, $adminHeader, $adminSubtitle, $adminBody, $adminCtaText, $adminCtaUrl);
        self::send('admin@marglead.com', $adminSubject, $adminCompiled);
        
        // 2. Email to User
        $userSubject = "Marg Soft Solution Account Request Received";
        $userTitle = "Account Registration Received";
        $userHeader = "Hi " . $name . ", we are reviewing your request!";
        $userSubtitle = "Your Marg Soft Solution profile is currently pending activation.";
        
        $userBody = "<p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>";
        $userBody .= "<p>Thank you for submitting your account registration request with <strong>Marg Soft Solution</strong>.</p>";
        $userBody .= "<p>Our administrative team is currently configuring your default security credentials and workspace modules permissions matrix. You will receive an automated email notification once your profile has been authorized and activated.</p>";
        $userBody .= "<p>Thank you for your patience.</p>";
        
        $userCompiled = self::wrapHTMLTemplate($userTitle, $userHeader, $userSubtitle, $userBody, "Visit Support Center", "https://margsoft.com");
        return self::send($email, $userSubject, $userCompiled);
    }

    /**
     * Send official Quotation & Proposal email with itemized pricing table to Client
     */
    public static function sendQuotation($quoteId, $recipientEmail, $clientName, $companyName, $grandTotal, $itemsJson = null) {
        if (empty($recipientEmail)) {
            return false;
        }
        
        $subject = "Official Quotation & Proposal - " . $quoteId . " | Marg Soft Solutions";
        $title = "Official Quotation & Proposal";
        $header_title = "Quotation Proposal for " . ($companyName ?: $clientName);
        $subtitle = "Proposal ID: " . $quoteId . " | Date: " . date('d M, Y');
        
        // Process items HTML
        $items = [];
        if (!empty($itemsJson)) {
            $items = json_decode($itemsJson, true);
        }
        
        $itemsTable = "<table border='0' cellpadding='8' cellspacing='0' width='100%' style='border-collapse: collapse; margin-top: 15px; font-size: 14px;'>
            <thead>
                <tr style='background-color: #f1f5f9; border-bottom: 2px solid #cbd5e1; text-align: left;'>
                    <th style='padding: 8px; color: #0f172a;'>Product / Service Description</th>
                    <th style='padding: 8px; text-align: center; color: #0f172a;'>Qty</th>
                    <th style='padding: 8px; text-align: right; color: #0f172a;'>Price (INR)</th>
                    <th style='padding: 8px; text-align: right; color: #0f172a;'>Total</th>
                </tr>
            </thead>
            <tbody>";
            
        if (!empty($items) && is_array($items)) {
            foreach ($items as $item) {
                $itemsTable .= "<tr style='border-bottom: 1px solid #e2e8f0;'>
                    <td style='padding: 8px; color: #334155;'><strong>" . htmlspecialchars($item['product'] ?? 'Marg ERP License') . "</strong></td>
                    <td style='padding: 8px; text-align: center; color: #334155;'>" . intval($item['qty'] ?? 1) . "</td>
                    <td style='padding: 8px; text-align: right; color: #334155;'>₹" . number_format(floatval($item['price'] ?? 0), 2) . "</td>
                    <td style='padding: 8px; text-align: right; font-weight: bold; color: #0f172a;'>₹" . number_format(floatval($item['total'] ?? 0), 2) . "</td>
                </tr>";
            }
        } else {
            $itemsTable .= "<tr style='border-bottom: 1px solid #e2e8f0;'>
                <td style='padding: 8px; color: #334155;'><strong>Marg ERP Software Solution & Implementation</strong></td>
                <td style='padding: 8px; text-align: center; color: #334155;'>1</td>
                <td style='padding: 8px; text-align: right; color: #334155;'>₹" . number_format(floatval($grandTotal), 2) . "</td>
                <td style='padding: 8px; text-align: right; font-weight: bold; color: #0f172a;'>₹" . number_format(floatval($grandTotal), 2) . "</td>
            </tr>";
        }
        
        $itemsTable .= "</tbody></table>";
        
        $body = "<p>Dear <strong>" . htmlspecialchars($clientName ?: 'Valued Customer') . "</strong>,</p>";
        $body .= "<p>Thank you for expressing interest in <strong>Marg Soft Solutions</strong>. We are pleased to send you the official software proposal and price quote <strong>" . htmlspecialchars($quoteId) . "</strong> for <strong>" . htmlspecialchars($companyName ?: 'your organization') . "</strong>.</p>";
        
        $body .= "<div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 20px 0;'>
            <div style='font-size: 16px; font-weight: bold; color: #0f172a; margin-bottom: 10px;'>Quotation Summary:</div>
            " . $itemsTable . "
            <div style='margin-top: 15px; text-align: right; font-size: 16px; font-weight: bold; color: #10b981;'>
                Grand Total Amount: ₹" . number_format(floatval($grandTotal), 2) . "
            </div>
        </div>";
        
        $body .= "<p>You can view and review your complete proposal sheet online by clicking the link below:</p>";
        
        $cta_text = "View Proposal Online";
        $cta_url = "http://localhost/marglead/index.php?page=quotation_view&id=" . urlencode($quoteId);
        
        $compiledBody = self::wrapHTMLTemplate($title, $header_title, $subtitle, $body, $cta_text, $cta_url);
        return self::send($recipientEmail, $subject, $compiledBody);
    }
}
