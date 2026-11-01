# Install atlas-agent as a Windows service, from an administrator PowerShell:
#
#   .\install-windows-service.ps1 -Binary .\atlas-agent-windows-amd64.exe -Config .\atlas-agent.yaml
#
# Copies the program and its config to C:\ProgramData\AtlasAgent, registers
# a service that starts with Windows and restarts after a crash, and starts
# it. atlas-agent answers the Service Control Manager itself
# (cmd/atlas-agent/service_windows.go), so the service runs the program
# directly. It runs as LocalSystem, which raw ICMP ping needs on Windows.
# In atlas-agent.yaml give token_file, ca_file and buffer as full paths
# under C:\ProgramData\AtlasAgent. Logs go to C:\ProgramData\AtlasAgent\agent.log.
#
# Uninstall: Stop-Service AtlasAgent; sc.exe delete AtlasAgent

param(
    [Parameter(Mandatory)] [string] $Binary,
    [Parameter(Mandatory)] [string] $Config
)

$ErrorActionPreference = 'Stop'
$home_ = 'C:\ProgramData\AtlasAgent'

New-Item -ItemType Directory -Force $home_ | Out-Null
Copy-Item $Binary "$home_\atlas-agent.exe" -Force
Copy-Item $Config "$home_\atlas-agent.yaml" -Force

# Only administrators and SYSTEM may read the folder: it holds the token.
icacls $home_ /inheritance:r /grant:r 'Administrators:(OI)(CI)F' 'SYSTEM:(OI)(CI)F' | Out-Null

# A service gets no console, so the log goes to a file.
$command = "`"$home_\atlas-agent.exe`" -config `"$home_\atlas-agent.yaml`" -log `"$home_\agent.log`""

if (Get-Service AtlasAgent -ErrorAction SilentlyContinue) {
    Stop-Service AtlasAgent
    sc.exe delete AtlasAgent | Out-Null
}

New-Service -Name AtlasAgent -DisplayName 'Atlas NetCare probe agent' -BinaryPathName $command -StartupType Automatic | Out-Null
# Restart 5 seconds after any crash, and keep doing so.
sc.exe failure AtlasAgent reset= 0 actions= restart/5000/restart/5000/restart/5000 | Out-Null
Start-Service AtlasAgent

Get-Service AtlasAgent
