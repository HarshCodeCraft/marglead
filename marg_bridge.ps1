# Marg ERP 9+ WhatsApp Local-to-Live Bridge Server (Zero Dependency PowerShell Version)
# Listens on http://localhost:8080/ for Marg ERP requests, scans local drives for fresh PDF,
# and forwards PDF + Bill details to Hostinger Live Server.

$port = 8080
$liveServerUrl = "https://friendlyaisolution.com/api/marg_erp_gateway.php"

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://localhost:$port/")

try {
    $listener.Start()
    Write-Host "============================================================" -ForegroundColor Green
    Write-Host "  Marg ERP 9+ WhatsApp Local Bridge Server (Active)" -ForegroundColor Green
    Write-Host "  Running on: http://localhost:$port/" -ForegroundColor Yellow
    Write-Host "  Keep this window minimized while using Marg ERP." -ForegroundColor Cyan
    Write-Host "============================================================" -ForegroundColor Green
    Write-Host ""
} catch {
    Write-Host "Error starting HTTP listener on port $port: $_" -ForegroundColor Red
    exit 1
}

while ($listener.IsListening) {
    try {
        $context = $listener.GetContext()
        $request = $context.Request
        $response = $context.Response
        $qs = $request.QueryString

        $apiKey = $qs["api_key"]
        $mob = $qs["mob"]
        $msg = $qs["msg"]
        $pdfUrl = $qs["pdf_url"]

        Write-Host "[$(Get-Date -Format 'HH:mm:ss')] Request received for $mob" -ForegroundColor Cyan

        # Search local Windows drives for Marg generated PDF
        $matchedFile = $null
        if ($pdfUrl -and $pdfUrl -ne '{PDF}' -and (Test-Path $pdfUrl)) {
            $matchedFile = $pdfUrl
        }

        if (-not $matchedFile) {
            $recentPdfs = @()
            $baseScanLocations = @(
                "C:\Users\Public\MARG",
                "C:\Users\Public\Documents\MARG",
                "C:\MARG",
                "C:\MARGWIN",
                "C:\MargERP"
            )

            # Discover all subdirectories (including dynamic company folders like 31041)
            $allDirsToScan = @()
            foreach ($baseLoc in $baseScanLocations) {
                if (Test-Path $baseLoc) {
                    $allDirsToScan += $baseLoc
                    Get-ChildItem -Path $baseLoc -Directory -Recurse -Depth 3 -ErrorAction SilentlyContinue | ForEach-Object {
                        $allDirsToScan += $_.FullName
                    }
                }
            }

            # Scan drive roots as well for MARG directories
            $drives = Get-PSDrive -PSProvider FileSystem
            foreach ($d in $drives) {
                $driveRoot = $d.Root
                if (Test-Path $driveRoot) {
                    Get-ChildItem -Path $driveRoot -Filter "*MARG*" -Directory -ErrorAction SilentlyContinue | ForEach-Object {
                        $allDirsToScan += $_.FullName
                    }
                }
            }
            $allDirsToScan += $env:TEMP

            $allDirsToScan = $allDirsToScan | Select-Object -Unique

            foreach ($dirPath in $allDirsToScan) {
                if (Test-Path $dirPath) {
                    $files = Get-ChildItem -Path $dirPath -Filter "*.pdf" -File -ErrorAction SilentlyContinue |
                             Where-Object { ((Get-Date) - $_.LastWriteTime).TotalMinutes -lt 1440 }
                    $recentPdfs += $files
                }
            }

            if ($recentPdfs.Count -gt 0) {
                $matchedFile = ($recentPdfs | Sort-Object LastWriteTime -Descending)[0].FullName
                Write-Host "  -> Matched fresh Marg PDF: $matchedFile" -ForegroundColor Green
            }
        }

        # Send cURL / RestMethod POST to Live Hostinger Server
        $postParams = @{
            api_key    = $apiKey
            mob        = $mob
            msg        = $msg
            BillHeader = $qs["BillHeader"]
            BillItem   = $qs["BillItem"]
        }

        if ($matchedFile -and (Test-Path $matchedFile)) {
            Write-Host "  -> Attaching Local PDF: $matchedFile" -ForegroundColor Green
            $postParams['pdf_url'] = $matchedFile
            $postParams['pdf_base64'] = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($matchedFile))
        }

        $res = Invoke-RestMethod -Uri $liveServerUrl -Method Post -Body $postParams -ErrorAction SilentlyContinue
        Write-Host "  -> Forwarded to Live Gateway successfully!" -ForegroundColor Green

        $resBuffer = [System.Text.Encoding]::UTF8.GetBytes('{"status":"success","message":"Bridge Processed"}')
        $response.ContentType = "application/json"
        $response.ContentLength64 = $resBuffer.Length
        $response.OutputStream.Write($resBuffer, 0, $resBuffer.Length)
        $response.Close()
    } catch {
        Write-Host "Bridge processing error: $_" -ForegroundColor Red
    }
}
