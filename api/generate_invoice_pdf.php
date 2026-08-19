<?php
/**
 * Marg ERP CRM - Dynamic PDF Invoice Generator Endpoint
 * Generates a clean, professional downloadable PDF Invoice file for WhatsApp attachments.
 */

$bill_no = $_GET['bill'] ?? $_GET['bill_no'] ?? 'INV-A000004';
$amount = $_GET['amount'] ?? '0.00';
$customer = $_GET['customer'] ?? 'Valued Customer';
$date = $_GET['date'] ?? date('d-m-Y');
$firm = $_GET['firm'] ?? 'POSHAK PATHAK';
$balance = $_GET['balance'] ?? '0.00';
$helpline = $_GET['helpline'] ?? '';
$bank = $_GET['bank'] ?? '';
$account = $_GET['account'] ?? '';
$ifsc = $_GET['ifsc'] ?? '';
$upi = $_GET['upi'] ?? '';

// Output standard PDF headers
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Invoice_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $bill_no) . '.pdf"');

$pdfLines = [];
$pdfLines[] = "BT";
$pdfLines[] = "/F1 18 Tf";
$pdfLines[] = "50 730 Td";
$pdfLines[] = "(" . addslashes($firm) . ") Tj";
$pdfLines[] = "/F1 14 Tf";
$pdfLines[] = "0 -25 Td";
$pdfLines[] = "(TAX INVOICE / SALE BILL) Tj";
$pdfLines[] = "/F1 10 Tf";
$pdfLines[] = "0 -20 Td";
$pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";
$pdfLines[] = "0 -20 Td";
$pdfLines[] = "(Invoice No : " . addslashes($bill_no) . "                 Date: " . addslashes($date) . ") Tj";
$pdfLines[] = "0 -18 Td";
$pdfLines[] = "(Customer Name : " . addslashes($customer) . ") Tj";
$pdfLines[] = "0 -18 Td";
$pdfLines[] = "(Total Bill Amount : Rs. " . addslashes($amount) . ") Tj";
if (!empty($balance) && $balance !== '0.00') {
    $pdfLines[] = "0 -18 Td";
    $pdfLines[] = "(Ledger Balance : Rs. " . addslashes($balance) . ") Tj";
}
$pdfLines[] = "0 -20 Td";
$pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";

if (!empty($bank) || !empty($account) || !empty($upi)) {
    $pdfLines[] = "0 -20 Td";
    $pdfLines[] = "/F1 11 Tf";
    $pdfLines[] = "(PAYMENT BANK DETAILS:) Tj";
    $pdfLines[] = "/F1 10 Tf";
    if (!empty($upi)) {
        $pdfLines[] = "0 -16 Td";
        $pdfLines[] = "(UPI ID : " . addslashes($upi) . ") Tj";
    }
    if (!empty($bank)) {
        $pdfLines[] = "0 -16 Td";
        $pdfLines[] = "(Bank Name : " . addslashes($bank) . ") Tj";
    }
    if (!empty($account)) {
        $pdfLines[] = "0 -16 Td";
        $pdfLines[] = "(Account No : " . addslashes($account) . ") Tj";
    }
    if (!empty($ifsc)) {
        $pdfLines[] = "0 -16 Td";
        $pdfLines[] = "(IFSC Code : " . addslashes($ifsc) . ") Tj";
    }
    $pdfLines[] = "0 -20 Td";
    $pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";
}

$pdfLines[] = "0 -30 Td";
$pdfLines[] = "/F1 11 Tf";
$pdfLines[] = "(Thank you for your business!" . (!empty($helpline) ? " - Helpline: " . addslashes($helpline) : "") . ") Tj";
$pdfLines[] = "0 -15 Td";
$pdfLines[] = "/F1 9 Tf";
$pdfLines[] = "(This is an official computer-generated invoice copy.) Tj";
$pdfLines[] = "ET";

$streamData = implode("\n", $pdfLines);
$streamLen = strlen($streamData);

$objects = [];
$objects[1] = "<</Type /Catalog /Pages 2 0 R>>";
$objects[2] = "<</Type /Pages /Kids [3 0 R] /Count 1>>";
$objects[3] = "<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R>>";
$objects[4] = "<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>";
$objects[5] = "<</Length " . $streamLen . ">>\nstream\n" . $streamData . "\nendstream";

$output = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $num => $obj) {
    $offsets[$num] = strlen($output);
    $output .= $num . " 0 obj\n" . $obj . "\nendobj\n";
}

$xrefOffset = strlen($output);
$output .= "xref\n0 " . (count($objects) + 1) . "\n";
$output .= "0000000000 65535 f \n";
foreach ($objects as $num => $obj) {
    $output .= sprintf("%010d 00000 n \n", $offsets[$num]);
}
$output .= "trailer\n<</Size " . (count($objects) + 1) . " /Root 1 0 R>>\n";
$output .= "startxref\n" . $xrefOffset . "\n%%EOF";

echo $output;
exit;

