[CmdletBinding()]
param(
    [ValidatePattern('^[A-Za-z0-9._-]+$')]
    [string]$SshTarget = 'gmktec'
)

$ErrorActionPreference = 'Stop'
$forward = '127.0.0.1:11440:127.0.0.1:11440'

Write-Host 'Starting MBFD external-coding gateway tunnel...' -ForegroundColor Cyan
Write-Host "Forwarding $forward through $SshTarget" -ForegroundColor Gray

& ssh.exe -N `
    -L $forward `
    -o ExitOnForwardFailure=yes `
    -o ServerAliveInterval=30 `
    -o ServerAliveCountMax=3 `
    $SshTarget

if ($LASTEXITCODE -ne 0) {
    throw "MBFD external-coding gateway tunnel exited with code $LASTEXITCODE."
}
