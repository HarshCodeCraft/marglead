<?php
// PHP Script to generate a minimal valid .xlsx template file programmatically
$xlsx_path = __DIR__ . '/sample_leads_template.xlsx';

$zip = new ZipArchive();
if ($zip->open($xlsx_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    // 1. [Content_Types].xml
    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $content_types);

    // 2. _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // 3. xl/_rels/workbook.xml.rels
    $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);

    // 4. xl/workbook.xml
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Leads" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    // 5. xl/sharedStrings.xml
    $strings = ['Name', 'Phone', 'Email', 'Company', 'Assigned To', 
                'Amit Sharma', '919454883552', 'amit.sharma@apexpharma.com', 'Apex Pharma Solutions', 'AJAY RATHOUR',
                'Dr. Satish Verma', '919998877766', 'drverma@diagnostic.in', 'Dr. Verma Diagnostic Clinic', 'HARSH SAINI',
                'Rajesh Gupta', '919123456789', 'rgupta@metrochem.org', 'Metro Chemicals & Co.', 'MOIN KHAN'];
    
    $shared_strings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $str) {
        $shared_strings .= '<si><t>' . htmlspecialchars($str) . '</t></si>';
    }
    $shared_strings .= '</sst>';
    $zip->addFromString('xl/sharedStrings.xml', $shared_strings);

    // 6. xl/worksheets/sheet1.xml
    $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="s"><v>0</v></c>
      <c r="B1" t="s"><v>1</v></c>
      <c r="C1" t="s"><v>2</v></c>
      <c r="D1" t="s"><v>3</v></c>
      <c r="E1" t="s"><v>4</v></c>
    </row>
    <row r="2">
      <c r="A2" t="s"><v>5</v></c>
      <c r="B2" t="s"><v>6</v></c>
      <c r="C2" t="s"><v>7</v></c>
      <c r="D2" t="s"><v>8</v></c>
      <c r="E2" t="s"><v>9</v></c>
    </row>
    <row r="3">
      <c r="A3" t="s"><v>10</v></c>
      <c r="B3" t="s"><v>11</v></c>
      <c r="C3" t="s"><v>12</v></c>
      <c r="D3" t="s"><v>13</v></c>
      <c r="E3" t="s"><v>14</v></c>
    </row>
    <row r="4">
      <c r="A4" t="s"><v>15</v></c>
      <c r="B4" t="s"><v>16</v></c>
      <c r="C4" t="s"><v>17</v></c>
      <c r="D4" t="s"><v>18</v></c>
      <c r="E4" t="s"><v>19</v></c>
    </row>
  </sheetData>
</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);

    $zip->close();
    echo "Excel template successfully compiled at: " . $xlsx_path . "\n";
} else {
    echo "Failed to create ZipArchive archive for XLSX.\n";
}
?>
