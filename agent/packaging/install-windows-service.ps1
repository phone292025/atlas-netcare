# Install atlas-agent as a Windows service, from an administrator PowerShell:
#
#   .\install-windows-service.ps1 -Binary .\atlas-agent-windows-amd64.exe -Config .\atlas-agent.yaml
#
# Copies the program and its config to C:\ProgramData\AtlasAgent, registers
# a service that starts with Windows and restarts after a crash, and starts
# it. atlas-agent answers the Service Control Manager itself
# (cmd/atlas-agent/service_windows.go), so the service runs the program
# directly. It runs as its own virtual account, NT SERVICE\AtlasAgent, which
# can send ping without administrator rights and can read and write only its
# own folder, rather than as LocalSystem.
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

# Only administrators and SYSTEM may read the folder: it holds the token. The
# service's own account is added once the service, and so the account, exists.
icacls $home_ /inheritance:r /grant:r 'Administrators:(OI)(CI)F' 'SYSTEM:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls could not lock down $home_" }

# A service gets no console, so the log goes to a file.
$command = "`"$home_\atlas-agent.exe`" -config `"$home_\atlas-agent.yaml`" -log `"$home_\agent.log`""

if (Get-Service AtlasAgent -ErrorAction SilentlyContinue) {
    Stop-Service AtlasAgent
    sc.exe delete AtlasAgent | Out-Null
}

New-Service -Name AtlasAgent -DisplayName 'Atlas NetCare probe agent' -BinaryPathName $command -StartupType Automatic | Out-Null
# Its own virtual account instead of LocalSystem, allowed to change files in
# its folder (the buffer, the log) and nothing more.
sc.exe config AtlasAgent obj= 'NT SERVICE\AtlasAgent' | Out-Null
icacls $home_ /grant:r 'NT SERVICE\AtlasAgent:(OI)(CI)M' | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls could not give NT SERVICE\AtlasAgent its folder" }
# Restart 5 seconds after any crash, and keep doing so.
sc.exe failure AtlasAgent reset= 0 actions= restart/5000/restart/5000/restart/5000 | Out-Null
# Also when the agent stops itself with an error (exit code 1), not only when
# the process crashes: without this flag Windows treats that as a clean stop.
sc.exe failureflag AtlasAgent 1 | Out-Null
Start-Service AtlasAgent

Get-Service AtlasAgent
