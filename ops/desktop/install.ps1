# Puts "ReadLog Control" on the desktop, and a cloudflared.exe where the control
# looks for one when the cloudflared Docker image cannot be pulled.
#
#     powershell -ExecutionPolicy Bypass -File ops\desktop\install.ps1
#
# Safe to run again: it overwrites the shortcut and skips a cloudflared that is
# already there.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$bat = Join-Path $PSScriptRoot 'ReadLog Control.bat'

$desktop = [Environment]::GetFolderPath('Desktop')
$lnk = Join-Path $desktop 'ReadLog Control.lnk'
$shell = New-Object -ComObject WScript.Shell
$s = $shell.CreateShortcut($lnk)
$s.TargetPath = $bat
$s.WorkingDirectory = $repo
$s.IconLocation = "$env:SystemRoot\System32\cmd.exe,0"
$s.Description = 'ReadLog: on, off, status, public URL'
$s.Save()
Write-Host "shortcut: $lnk"

$exe = Join-Path $env:LOCALAPPDATA 'Programs\cloudflared\cloudflared.exe'
$imagePresent = $false
try { docker image inspect cloudflare/cloudflared:latest *> $null; $imagePresent = ($LASTEXITCODE -eq 0) } catch {}
if (Get-Command cloudflared -ErrorAction SilentlyContinue) {
    Write-Host "cloudflared: already on PATH"
} elseif (Test-Path $exe) {
    Write-Host "cloudflared: $exe"
} elseif ($imagePresent) {
    Write-Host "cloudflared: the Docker image is present; the control uses the compose profile, no download needed"
} else {
    New-Item -ItemType Directory -Force (Split-Path $exe) | Out-Null
    $url = 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'
    Write-Host "cloudflared: downloading $url"
    Invoke-WebRequest -Uri $url -OutFile $exe -UseBasicParsing
    Write-Host "cloudflared: $exe"
}
Write-Host "done. Double-click 'ReadLog Control' on the desktop."
