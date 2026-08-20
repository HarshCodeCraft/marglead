<?php
/**
 * Marg ERP 9+ Default Tax Invoice PDF Generator
 * Generates the authentic, official Marg ERP 9+ Default Sale Bill / Tax Invoice PDF
 * featuring Header Box, Party Details, Bill Metadata, Item Table Grid, Bank Details,
 * Ledger Balance, Terms & Conditions, and Authorized Signatory Stamp.
 */

$bill_no   = $_GET['bill'] ?? $_GET['bill_no'] ?? 'INV-A000391';
$amount    = $_GET['amount'] ?? '0.00';
$customer  = $_GET['customer'] ?? 'Valued Customer';
$date      = $_GET['date'] ?? date('d-m-Y');
$firm      = $_GET['firm'] ?? 'Marg Soft Solutions Pvt Ltd';
$balance   = $_GET['balance'] ?? '0.00';
$helpline  = $_GET['helpline'] ?? '';
$bank      = $_GET['bank'] ?? '';
$account   = $_GET['account'] ?? '';
$ifsc      = $_GET['ifsc'] ?? '';
$upi       = $_GET['upi'] ?? '';
$msg_raw   = $_GET['msg'] ?? '';

// Sanitize inputs for PDF text drawing
$firmClean     = preg_replace('/[^\x20-\x7E]/', '', $firm);
$customerClean = preg_replace('/[^\x20-\x7E]/', '', $customer);
$billNoClean   = preg_replace('/[^\x20-\x7E]/', '', $bill_no);
$amountClean   = preg_replace('/[^\x20-\x7E]/', '', $amount);
$balanceClean  = preg_replace('/[^\x20-\x7E]/', '', $balance);
$bankClean     = preg_replace('/[^\x20-\x7E]/', '', $bank);
$accClean      = preg_replace('/[^\x20-\x7E]/', '', $account);
$ifscClean     = preg_replace('/[^\x20-\x7E]/', '', $ifsc);
$upiClean      = preg_replace('/[^\x20-\x7E]/', '', $upi);
$helplineClean = preg_replace('/[^\x20-\x7E]/', '', $helpline);

// Parse item lines if embedded in msg body
$items = [];
if (!empty($msg_raw)) {
    $lines = explode("\n", $msg_raw);
    foreach ($lines as $line) {
        if (preg_match('/^([0-9]+)[\.\)\s]+([A-Za-z0-9\s\-\.]+)\s+([0-9]+)\s+([0-9\.,]+)/', trim($line), $mItem)) {
            $items[] = [
                'name'  => trim($mItem[2]),
                'qty'   => trim($mItem[3]),
                'rate'  => trim($mItem[4]),
                'total' => number_format((float)$mItem[3] * (float)str_replace(',', '', $mItem[4]), 2)
            ];
        }
    }
}

if (empty($items)) {
    $items[] = [
        'name'  => 'Marg ERP 9+ Software Sales & Services',
        'qty'   => '1',
        'rate'  => $amountClean,
        'total' => $amountClean
    ];
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Marg_Default_Invoice_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $billNoClean) . '.pdf"');

// PDF Output Generation Engine (PDF-1.4 Spec)
$pdfLines = [];
$pdfLines[] = "BT";

// Title Header
$pdfLines[] = "/F1 16 Tf";
$pdfLines[] = "40 750 Td";
$pdfLines[] = "(" . addslashes($firmClean) . ") Tj";

$pdfLines[] = "/F1 10 Tf";
$pdfLines[] = "0 -16 Td";
$pdfLines[] = "(TAX INVOICE / SALE BILL - MARG ERP 9+ DEFAULT FORMAT) Tj";

$pdfLines[] = "0 -12 Td";
$pdfLines[] = "(====================================================================================================) Tj";

// Invoice Info Box
$pdfLines[] = "0 -18 Td";
$pdfLines[] = "/F1 10 Tf";
$pdfLines[] = "(Bill No     : " . addslashes($billNoClean) . "                          Date : " . addslashes($date) . ") Tj";

$pdfLines[] = "0 -14 Td";
$pdfLines[] = "(Customer Name : " . addslashes($customerClean) . ") Tj";

$pdfLines[] = "0 -12 Td";
$pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";

// Table Header
$pdfLines[] = "0 -16 Td";
$pdfLines[] = "/F1 9 Tf";
$pdfLines[] = "(S.No   Description / Particulars                        Qty        Rate (Rs)        Total (Rs)) Tj";

$pdfLines[] = "0 -10 Td";
$pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";

// Table Item Rows
$sno = 1;
foreach ($items as $item) {
    $pName = str_pad(substr($item['name'], 0, 42), 42, ' ');
    $pQty  = str_pad($item['qty'], 8, ' ', STR_PAD_LEFT);
    $pRate = str_pad($item['rate'], 14, ' ', STR_PAD_LEFT);
    $pTot  = str_pad($item['total'], 14, ' ', STR_PAD_LEFT);
    
    $pdfLines[] = "0 -14 Td";
    $pdfLines[] = "(" . sprintf("%-6d", $sno++) . " " . addslashes($pName) . " " . $pQty . "   " . $pRate . "   " . $pTot . ") Tj";
}

$pdfLines[] = "0 -12 Td";
$pdfLines[] = "(====================================================================================================) Tj";

// Amounts & Totals Summary
$pdfLines[] = "0 -16 Td";
$pdfLines[] = "/F1 10 Tf";
$pdfLines[] = "(TOTAL BILL AMOUNT                             : Rs. " . addslashes($amountClean) . ") Tj";

if (!empty($balanceClean) && $balanceClean !== '0.00') {
    $pdfLines[] = "0 -14 Td";
    $pdfLines[] = "(OUTSTANDING LEDGER BALANCE                     : Rs. " . addslashes($balanceClean) . ") Tj";
}

// Payment Bank Details Section
if (!empty($bankClean) || !empty($accClean) || !empty($upiClean)) {
    $pdfLines[] = "0 -16 Td";
    $pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";
    $pdfLines[] = "0 -14 Td";
    $pdfLines[] = "/F1 9 Tf";
    $pdfLines[] = "(BANK & PAYMENT DETAILS:) Tj";
    
    if (!empty($upiClean)) {
        $pdfLines[] = "0 -12 Td";
        $pdfLines[] = "(UPI ID        : " . addslashes($upiClean) . ") Tj";
    }
    if (!empty($bankClean)) {
        $pdfLines[] = "0 -12 Td";
        $pdfLines[] = "(Bank Name     : " . addslashes($bankClean) . ") Tj";
    }
    if (!empty($accClean)) {
        $pdfLines[] = "0 -12 Td";
        $pdfLines[] = "(Account No    : " . addslashes($accClean) . ") Tj";
    }
    if (!empty($ifscClean)) {
        $pdfLines[] = "0 -12 Td";
        $pdfLines[] = "(IFSC Code     : " . addslashes($ifscClean) . ") Tj";
    }
}

// Footer & Terms
$pdfLines[] = "0 -18 Td";
$pdfLines[] = "(----------------------------------------------------------------------------------------------------) Tj";
$pdfLines[] = "0 -14 Td";
$pdfLines[] = "/F1 8 Tf";
$pdfLines[] = "(Terms & Conditions: Goods once sold will not be taken back. Subject to local jurisdiction.) Tj";

if (!empty($helplineClean)) {
    $pdfLines[] = "0 -12 Td";
    $pdfLines[] = "(Helpline / Support: " . addslashes($helplineClean) . ") Tj";
}

$pdfLines[] = "0 -22 Td";
$pdfLines[] = "/F1 9 Tf";
$pdfLines[] = "(For " . addslashes($firmClean) . "                                    [ Authorized Signatory ]) Tj";

$pdfLines[] = "ET";

$streamData = implode("\n", $pdfLines);
$streamLen = strlen($streamData);

$objects = [];
$objects[1] = "<</Type /Catalog /Pages 2 0 R>>";
$objects[2] = "<</Type /Pages /Kids [3 0 R] /Count 1>>";
$objects[3] = "<</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R>>";
$objects[4] = "<</Type /Font /Subtype /Type1 /BaseFont /Courier>>";
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
