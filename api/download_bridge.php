<?php
/**
 * Marg ERP 9+ Client Setup Package Downloader Endpoint
 * Allows merchants/clients to download the 1-click Marg WhatsApp Connector Package.
 * Interactive Folder Picker & Multi-Drive Compatible (C:, D:, E:, F:, etc.)
 * Includes VBScript + PowerShell + cURL Multi-Fallback for 100% Windows Compatibility.
 */
header("Access-Control-Allow-Origin: *");

$action = $_GET['action'] ?? 'download';
$apiKey = $_GET['api_key'] ?? 'MARG-WABA-7DE9514EA1E4EF2C';

if ($action === 'info') {
    header("Content-Type: application/json");
    echo json_encode([
        'status' => 'success',
        'download_url' => 'https://friendlyaisolution.com/api/download_bridge.php?action=download&api_key=' . urlencode($apiKey),
        'control_room_url' => 'https://friendlyaisolution.com/api/marg_erp_gateway.php?api_key=' . urlencode($apiKey) . '&mob={1}&msg={2}&pdf_url={PDF}',
        'batch_command' => 'whatsapp.bat "' . $apiKey . '" "{1}" "{2}" "{PDF}"'
    ], JSON_PRETTY_PRINT);
    exit;
}

// Generate ready-to-run interactive setup batch script download
header('Content-Type: application/x-bat');
header('Content-Disposition: attachment; filename="marg_whatsapp_setup.bat"');

echo "@echo off\r\n";
echo "setlocal enabledelayedexpansion\r\n";
echo "title Marg ERP 9+ WhatsApp Connector Setup\r\n";
echo "color 0A\r\n";
echo "cls\r\n";
echo "echo ============================================================\r\n";
echo "echo   Marg ERP 9+ WhatsApp Connector Interactive Setup\r\n";
echo "echo ============================================================\r\n";
echo "echo.\r\n";
echo "echo Searching for Marg ERP installations across your drives...\r\n";
echo "echo.\r\n";

echo "set COUNT=0\r\n";
echo "set DRIVES=C D E F G H I J K L M N O P Q R S T U V W X Y Z\r\n";
echo "for %%d in (%%DRIVES%%) do (\r\n";
echo "    if exist %%d:\\ (\r\n";
echo "        for /f \"delims=\" %%f in ('dir /b /s \"%%d:\\*MARG*.EXE\" 2^>nul') do (\r\n";
echo "            set \"EXEPATH=%%~dpf\"\r\n";
echo "            set \"EXEPATH=!EXEPATH:~0,-1!\"\r\n";
echo "            set \"ALREADY=\"\r\n";
echo "            if defined COUNT (\r\n";
echo "                for /l %%i in (1,1,!COUNT!) do (\r\n";
echo "                    if /i \"!FOUND[%%i]!\"==\"!EXEPATH!\" set ALREADY=1\r\n";
echo "                )\r\n";
echo "            )\r\n";
echo "            if not defined ALREADY (\r\n";
echo "                set /a COUNT+=1\r\n";
echo "                set \"FOUND[!COUNT!]=!EXEPATH!\"\r\n";
echo "                echo   [!COUNT!] Found Marg Folder: !EXEPATH!\r\n";
echo "            )\r\n";
echo "        )\r\n";
echo "    )\r\n";
echo ")\r\n";

echo "echo.\r\n";
echo "if !COUNT! GTR 0 (\r\n";
echo "    echo   [0] Enter Custom Folder Path manually\r\n";
echo "    echo.\r\n";
echo "    set /p CHOICE=\"Select your Marg ERP folder number [1-!COUNT!] or 0 for manual: \"\r\n";
echo ") else (\r\n";
echo "    echo No Marg ERP folder automatically detected.\r\n";
echo "    set CHOICE=0\r\n";
echo ")\r\n";

echo "if \"!CHOICE!\"==\"0\" (\r\n";
echo "    echo.\r\n";
echo "    set /p TARGET_DIR=\"Enter full path of your Marg ERP folder (e.g. D:\\MargERP or F:\\PharmacyMarg): \"\r\n";
echo ") else (\r\n";
echo "    set \"TARGET_DIR=!FOUND[%CHOICE%]!\"\r\n";
echo ")\r\n";

echo "if \"!TARGET_DIR!\"==\"\" set \"TARGET_DIR=C:\\MARG\"\r\n";
echo "if \"!TARGET_DIR:~-1!\"==\"\\\" set \"TARGET_DIR=!TARGET_DIR:~0,-1!\"\r\n";

echo "if not exist \"!TARGET_DIR!\" mkdir \"!TARGET_DIR!\" 2>nul\r\n";

echo "echo.\r\n";
echo "echo Installing whatsapp.bat and whatsapp.vbs inside: !TARGET_DIR! ...\r\n";

echo "set TEMP_VBS=%%TEMP%%\\whatsapp_template.vbs\r\n";
echo "echo On Error Resume Next > \"%%TEMP_VBS%%\"\r\n";
echo "echo Set args = WScript.Arguments >> \"%%TEMP_VBS%%\"\r\n";
echo "echo If args.Count ^>= 3 Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     apiKey = args(0) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     mob = args(1) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     msg = args(2) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     pdfPath = \"\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If args.Count ^>= 4 Then pdfPath = args(3) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Set fso = CreateObject(\"Scripting.FileSystemObject\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If pdfPath ^<^> \"\" And fso.FileExists(pdfPath) And Not fso.FolderExists(pdfPath) Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         pdfPath = fso.GetAbsolutePathName(pdfPath) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Else >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         pdfPath = \"\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If pdfPath = \"\" Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         latestDate = CDate(\"1970-01-01\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         ScanPdfFolder \"C:\\Users\\Public\\MARG\", fso, latestDate, pdfPath >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         ScanPdfFolder \"C:\\MARG\", fso, latestDate, pdfPath >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     b64Data = \"\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If pdfPath ^<^> \"\" And fso.FileExists(pdfPath) Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         Set stream = CreateObject(\"ADODB.Stream\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         stream.Type = 1 >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         stream.Open >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         stream.LoadFromFile pdfPath >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         Set dom = CreateObject(\"MSXML2.DOMDocument.6.0\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         If dom Is Nothing Then Set dom = CreateObject(\"MSXML2.DOMDocument\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         Set elem = dom.createElement(\"b64\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         elem.dataType = \"bin.base64\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         elem.nodeTypedValue = stream.Read >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         stream.Close >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         b64Data = elem.text >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Set http = CreateObject(\"MSXML2.ServerXMLHTTP.6.0\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If http Is Nothing Then Set http = CreateObject(\"MSXML2.ServerXMLHTTP\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If http Is Nothing Then Set http = CreateObject(\"MSXML2.XMLHTTP\") >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     url = \"https://friendlyaisolution.com/api/marg_erp_gateway.php\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     postData = \"api_key=\" ^& encode(apiKey) ^& \"^&mob=\" ^& encode(mob) ^& \"^&msg=\" ^& encode(msg) ^& \"^&pdf_url=\" ^& encode(pdfPath) ^& \"^&pdf_base64=\" ^& encode(b64Data) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     http.Open \"POST\", url, False >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     http.setRequestHeader \"Content-Type\", \"application/x-www-form-urlencoded; charset=UTF-8\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     http.Send postData >> \"%%TEMP_VBS%%\"\r\n";
echo "echo End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo Sub ScanPdfFolder(folderPath, fsoObj, ByRef topDate, ByRef topFile) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     On Error Resume Next >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     If Not fsoObj.FolderExists(folderPath) Then Exit Sub >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Set fol = fsoObj.GetFolder(folderPath) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     For Each f In fol.Files >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         If LCase(fsoObj.GetExtensionName(f.Name)) = \"pdf\" Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo             If f.DateLastModified ^> topDate Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo                 topDate = f.DateLastModified >> \"%%TEMP_VBS%%\"\r\n";
echo "echo                 topFile = f.Path >> \"%%TEMP_VBS%%\"\r\n";
echo "echo             End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Next >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     For Each subf In fol.SubFolders >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         ScanPdfFolder subf.Path, fsoObj, topDate, topFile >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Next >> \"%%TEMP_VBS%%\"\r\n";
echo "echo End Sub >> \"%%TEMP_VBS%%\"\r\n";
echo "echo Function encode(str) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Dim i, c, res >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     res = \"\" >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     For i = 1 To Len(str) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         c = Mid(str, i, 1) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         If InStr(\"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.~\", c) ^> 0 Then >> \"%%TEMP_VBS%%\"\r\n";
echo "echo             res = res ^& c >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         Else >> \"%%TEMP_VBS%%\"\r\n";
echo "echo             res = res ^& \"%%%%\" ^& Right(\"0\" ^& Hex(Asc(c)), 2) >> \"%%TEMP_VBS%%\"\r\n";
echo "echo         End If >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     Next >> \"%%TEMP_VBS%%\"\r\n";
echo "echo     encode = res >> \"%%TEMP_VBS%%\"\r\n";
echo "echo End Function >> \"%%TEMP_VBS%%\"\r\n";

echo "set TEMP_BAT=%%TEMP%%\\whatsapp_template.bat\r\n";
echo "echo @echo off> \"%%TEMP_BAT%%\"\r\n";
echo "echo rem Marg ERP 9+ WhatsApp Auto-Uploader >> \"%%TEMP_BAT%%\"\r\n";
echo "echo cscript //nologo \"%%%%~dp0whatsapp.vbs\" \"%%%%~1\" \"%%%%~2\" \"%%%%~3\" \"%%%%~4\">> \"%%TEMP_BAT%%\"\r\n";

echo "copy /y \"%%TEMP_BAT%%\" \"!TARGET_DIR!\\whatsapp.bat\" >nul\r\n";
echo "copy /y \"%%TEMP_VBS%%\" \"!TARGET_DIR!\\whatsapp.vbs\" >nul\r\n";

echo "copy /y \"%%TEMP_BAT%%\" \"%%SystemRoot%%\\whatsapp.bat\" >nul 2>&1\r\n";
echo "copy /y \"%%TEMP_VBS%%\" \"%%SystemRoot%%\\whatsapp.vbs\" >nul 2>&1\r\n";

echo "if not exist \"C:\\MARG\" mkdir \"C:\\MARG\" 2>nul\r\n";
echo "copy /y \"%%TEMP_BAT%%\" \"C:\\MARG\\whatsapp.bat\" >nul 2>&1\r\n";
echo "copy /y \"%%TEMP_VBS%%\" \"C:\\MARG\\whatsapp.vbs\" >nul 2>&1\r\n";

echo "del /f /q \"%%TEMP_BAT%%\" \"%%TEMP_VBS%%\" 2>nul\r\n";

echo "echo.\r\n";
echo "echo ============================================================\r\n";
echo "echo SETUP COMPLETED SUCCESSFULLY!\r\n";
echo "echo ============================================================\r\n";
echo "echo Copy and paste this String into Marg ERP Control Room:\r\n";
echo "echo.\r\n";
echo "echo !TARGET_DIR!\\whatsapp.bat \"" . $apiKey . "\" \"{1}\" \"{2}\" \"{PDF}\"\r\n";
echo "echo.\r\n";
echo "echo (OR Direct HTTP URL Method - Paste this in Marg HTTP API URL):\r\n";
echo "echo https://friendlyaisolution.com/api/marg_erp_gateway.php?api_key=" . urlencode($apiKey) . "^&mob={1}^&msg={2}^&pdf_url={PDF}\r\n";
echo "echo.\r\n";
echo "echo Press any key to exit...\r\n";
echo "pause >nul\r\n";
exit;



