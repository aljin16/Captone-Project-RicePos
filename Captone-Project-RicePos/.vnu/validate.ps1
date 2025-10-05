$ErrorActionPreference = 'Stop'

function Get-PhpExePath {
	$default = 'C:\xampp\php\php.exe'
	if (Test-Path $default) { return $default }
	$cmd = Get-Command php -ErrorAction SilentlyContinue
	if ($cmd) {
		try { return ($cmd | Select-Object -First 1 -ExpandProperty Source) } catch { return $cmd.Path }
	}
	throw 'PHP executable not found. Install PHP or ensure C:\xampp\php\php.exe exists.'
}

Set-Location "$PSScriptRoot\.."

$phpExe = Get-PhpExePath
$bindHost = '127.0.0.1'
$bindPort = 8000
$baseUrl = "http://${bindHost}:${bindPort}/"
$docroot = (Resolve-Path 'public').Path

Write-Host "Starting PHP built-in server at $baseUrl (docroot=public)" -ForegroundColor Cyan
$server = Start-Process -FilePath $phpExe -ArgumentList '-S',"${bindHost}:${bindPort}",'-t','public' -PassThru -WindowStyle Hidden
Start-Sleep -Seconds 2

try {
	$files = Get-ChildItem -Path 'public' -Recurse -Include *.php,*.html -File | Where-Object { $_.Name -ne 'logs_stream.php' }
	if (-not $files -or $files.Count -eq 0) {
		[pscustomobject]@{ Summary = @{ TotalFiles = 0; TotalErrors = 0; TotalWarnings = 0 }; Files = @() } | ConvertTo-Json -Depth 5
		return
	}

	$results = @()
	foreach ($f in $files) {
		$rel = $f.FullName.Substring((Get-Location).Path.Length + 1)
		$pathAtRoot = $f.FullName.Substring($docroot.Length + 1) -replace '\\','/'
		$url = $baseUrl + $pathAtRoot
		$html = ''
		try {
			$resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 25
			$html = $resp.Content
		} catch {
			try {
				$html = Get-Content -Raw -Path $f.FullName
			} catch {
				$html = ''
			}
		}

		# Skip validation for non-HTML endpoints (e.g., JSON APIs)
		$ctype = $resp.Headers['Content-Type']
		$looksJson = ($html -match '^\s*[\{\[]')
		$notHtml = ($ctype -and ($ctype -match 'application/json' -or $ctype -match 'text/json')) -or $looksJson
		$errs = -1; $warns = -1
		try {
			if ($notHtml) {
				$errs = 0; $warns = 0; $json = @{ messages = @() }
			} else {
				Start-Sleep -Milliseconds 200
				$validate = Invoke-WebRequest -Method Post -UseBasicParsing -Uri "https://validator.w3.org/nu/?out=json&level=all" -ContentType 'text/html; charset=utf-8' -Body $html -TimeoutSec 90
				$json = $validate.Content | ConvertFrom-Json
				$errs = 0; $warns = 0
				if ($json -and $json.messages) {
					foreach ($m in $json.messages) {
						if ($m.type -eq 'error') { $errs++ }
						elseif ($m.type -eq 'warning') { $warns++ }
						elseif ($m.type -eq 'info' -and $m.subtype -eq 'warning') { $warns++ }
					}
				}
			}
		} catch {
			$errs = -1; $warns = -1
		}

		# Keep raw messages for fixing specifics
		$messages = @()
		if ($json -and $json.messages) {
			$messages = $json.messages | ForEach-Object { [pscustomobject]@{ type = $_.type; subtype = $_.subtype; message = $_.message; extract = $_.extract; lastLine = $_.lastLine; firstLine = $_.firstLine } }
		}
		$results += [pscustomobject]@{ File = $rel; Url = $url; Errors = $errs; Warnings = $warns; Messages = $messages }
	}

	# Sort results by error count desc, then warnings desc, then file name
	$sorted = $results | Sort-Object -Property @{ Expression = 'Errors'; Descending = $true }, @{ Expression = 'Warnings'; Descending = $true }, 'File'

	$totalErrors = ($results | Where-Object { $_.Errors -ge 0 } | Measure-Object -Property Errors -Sum).Sum
	$totalWarnings = ($results | Where-Object { $_.Warnings -ge 0 } | Measure-Object -Property Warnings -Sum).Sum
	if (-not $totalErrors) { $totalErrors = 0 }
	if (-not $totalWarnings) { $totalWarnings = 0 }

	[pscustomobject]@{
		Summary = [pscustomobject]@{ TotalFiles = $results.Count; TotalErrors = $totalErrors; TotalWarnings = $totalWarnings }
		Files = $sorted
	} | ConvertTo-Json -Depth 5
}
finally {
	if ($server -and !$server.HasExited) {
		try { Stop-Process -Id $server.Id -Force } catch {}
	}
}

