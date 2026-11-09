# Startet den Sprach-Tutor im Browser, ohne Docker.
# Mit LiveKit Cloud (LIVEKIT_URL = wss://...) läuft lokal nur der Web-Server, der Agent läuft in der Cloud
# (vorher einmal deploy-cloud.ps1). Sonst: lokaler LiveKit-Server, Sprach-Agent und Web-Server.
# Aufruf im Ordner Voice-AI-Tutor:  powershell -ExecutionPolicy Bypass -File .\start-voice.ps1
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

if (-not (Test-Path .env)) {
    Write-Host ".env fehlt. Zuerst start-textmode.ps1 ausführen und Schlüssel eintragen." -ForegroundColor Yellow
    exit 1
}
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)$' -and $Matches[2] -ne '') {
        [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2].Trim('"'), "Process")
    }
}
foreach ($name in 'ANTHROPIC_API_KEY', 'DEEPGRAM_API_KEY', 'CARTESIA_API_KEY') {
    if (-not [Environment]::GetEnvironmentVariable($name)) {
        Write-Host "$name ist in .env noch leer. Bitte eintragen und neu starten." -ForegroundColor Yellow
        exit 1
    }
}
if (-not $env:CARTESIA_VOICE_ID) {
    # Stimme von Lukas ("Lukas - Professional", Deutsch); eigene Wahl in .env eintragen
    $env:CARTESIA_VOICE_ID = "e00dd3df-19e7-4cd4-827a-7ff6687b6954"
}

# Python-Umgebung (einmalig)
if (-not (Test-Path .venv)) {
    py -3 -m venv .venv
    .\.venv\Scripts\python -m pip install -e "backend[dev]"
}
$python = (Resolve-Path .\.venv\Scripts\python.exe).Path

if ($env:LIVEKIT_URL -like 'wss://*') {
    Start-Process $python -ArgumentList '-m', 'uvicorn', 'tutor.server:app', '--port', '8000' -WorkingDirectory $PSScriptRoot
    Start-Sleep 5
    Start-Process "http://localhost:8000"
    Write-Host "Läuft mit LiveKit Cloud. Zum Beenden das Fenster des Web-Servers schließen." -ForegroundColor Green
    exit 0
}

# LiveKit-Server für Windows (einmalig herunterladen, gleiche Version wie docker-compose.yml)
$lkVersion = "1.13.7"
$lkExe = Join-Path $PSScriptRoot "tools\livekit-server.exe"
if (-not (Test-Path $lkExe)) {
    New-Item -ItemType Directory -Force tools | Out-Null
    $zip = Join-Path $PSScriptRoot "tools\livekit.zip"
    Invoke-WebRequest "https://github.com/livekit/livekit/releases/download/v$lkVersion/livekit_${lkVersion}_windows_amd64.zip" -OutFile $zip
    Expand-Archive $zip -DestinationPath tools -Force
    Remove-Item $zip
}

# VAD- und Turn-Detector-Modelle (einmalig)
if (-not (Test-Path tools\.models-ok)) {
    & $python -m tutor.agent download-files
    New-Item tools\.models-ok -ItemType File | Out-Null
}

# Die Fenster erben die Umgebungsvariablen dieses Prozesses
Start-Process $lkExe -ArgumentList '--dev' -WorkingDirectory $PSScriptRoot
Start-Sleep 2
Start-Process $python -ArgumentList '-m', 'tutor.agent', 'dev' -WorkingDirectory $PSScriptRoot
Start-Process $python -ArgumentList '-m', 'uvicorn', 'tutor.server:app', '--port', '8000' -WorkingDirectory $PSScriptRoot
Start-Sleep 5
Start-Process "http://localhost:8000"
Write-Host "Läuft. Zum Beenden die drei Fenster (LiveKit, Agent, Web-Server) schließen." -ForegroundColor Green
