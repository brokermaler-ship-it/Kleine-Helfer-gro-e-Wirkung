# Startet den Tutor im Textmodus (nur Anthropic-Schlüssel nötig).
# Aufruf im Ordner Voice-AI-Tutor:  powershell -ExecutionPolicy Bypass -File .\start-textmode.ps1
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

# Beim ersten Start .env aus der Vorlage anlegen und zum Eintragen öffnen
if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    notepad .env
    Write-Host ".env angelegt. Schlüssel eintragen, speichern und das Skript erneut starten." -ForegroundColor Yellow
    exit 0
}

# Schlüssel aus .env laden
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)$' -and $Matches[2] -ne '') {
        [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2].Trim('"'), "Process")
    }
}
if (-not $env:ANTHROPIC_API_KEY) {
    Write-Host "ANTHROPIC_API_KEY ist in .env noch leer. Bitte eintragen und neu starten." -ForegroundColor Yellow
    exit 1
}

# Beim ersten Start: virtuelle Umgebung anlegen und Pakete installieren
if (-not (Test-Path .venv)) {
    py -3 -m venv .venv
    .\.venv\Scripts\python -m pip install -e "backend[dev]"
}

.\.venv\Scripts\python -m tutor.textmode phishing-melden
