# Lädt den Sprach-Agenten zu LiveKit Cloud hoch (erster Aufruf: anlegen, danach: neue Version).
# Voraussetzung: LIVEKIT_URL (wss://...), LIVEKIT_API_KEY, LIVEKIT_API_SECRET aus LiveKit Cloud in .env.
# Aufruf im Repository-Ordner:  powershell -ExecutionPolicy Bypass -File .\deploy-cloud.ps1
#
# Gebaut wird in build\livekit-cloud mit deploy\livekit-cloud\Dockerfile, damit das Root-Dockerfile
# (Web-Server und Agent für docker compose) unberührt bleibt.
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot
$region = "eu-central"

$vars = @{}
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)$' -and $Matches[2] -ne '') { $vars[$Matches[1]] = $Matches[2].Trim('"') }
}
if (-not ($vars['LIVEKIT_URL'] -like 'wss://*')) {
    Write-Host "LIVEKIT_URL in .env zeigt noch nicht auf LiveKit Cloud (wss://...). Bitte eintragen." -ForegroundColor Yellow
    exit 1
}

# LiveKit-CLI (einmalig)
if (-not (Get-Command lk -ErrorAction SilentlyContinue)) {
    winget install --id LiveKit.LiveKitCLI -e --accept-source-agreements --accept-package-agreements
}
$env:Path += ";$env:LOCALAPPDATA\Microsoft\WinGet\Links"

# Projekt mit den Werten aus .env verknüpfen (einmalig)
$ErrorActionPreference = "Continue"
$known = (lk project list 2>&1 | Out-String)
if ($known -notmatch [regex]::Escape($vars['LIVEKIT_URL'])) {
    lk project add voice-tutor --url $vars['LIVEKIT_URL'] --api-key $vars['LIVEKIT_API_KEY'] --api-secret $vars['LIVEKIT_API_SECRET'] --default
}

# Build-Verzeichnis: Agent-Code, Lernmodule und die Cloud-Dateien
$stage = Join-Path $PSScriptRoot "build\livekit-cloud"
$toml = Join-Path $PSScriptRoot "deploy\livekit-cloud\livekit.toml"
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force "$stage\backend" | Out-Null
Copy-Item deploy\livekit-cloud\Dockerfile, deploy\livekit-cloud\.dockerignore, deploy\livekit-cloud\requirements.txt $stage
Copy-Item backend\pyproject.toml "$stage\backend"
Copy-Item backend\tutor "$stage\backend\tutor" -Recurse -Exclude __pycache__
Copy-Item content "$stage\content" -Recurse
if (Test-Path $toml) { Copy-Item $toml $stage }

# Geheimnisse für den Agenten: alles außer LIVEKIT_*, das setzt LiveKit Cloud selbst.
$secrets = Join-Path $env:TEMP "voice-tutor-secrets.txt"
$vars.GetEnumerator() | Where-Object { $_.Key -notlike 'LIVEKIT_*' } | ForEach-Object { "$($_.Key)=$($_.Value)" } | Set-Content $secrets
Push-Location $stage
try {
    if (Test-Path livekit.toml) {
        lk agent update-secrets --secrets-file $secrets --overwrite
        lk agent deploy
    } else {
        lk agent create --yes --region $region --secrets-file $secrets
    }
    $ok = ($LASTEXITCODE -eq 0)
    if (Test-Path livekit.toml) { Copy-Item livekit.toml $toml -Force }
} finally {
    Pop-Location
    Remove-Item $secrets -ErrorAction SilentlyContinue
}
if (-not $ok) { Write-Host "Hochladen fehlgeschlagen (siehe oben)." -ForegroundColor Red; exit 1 }
Write-Host "Fertig. Danach start-voice.ps1 ausführen." -ForegroundColor Green
