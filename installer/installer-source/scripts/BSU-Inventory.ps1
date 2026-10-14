<#
.SYNOPSIS
    The "BSU Inventory" shortcut (started hidden by BSU-Inventory.exe).

.DESCRIPTION
    1. checks that the database and web server services run, and starts them when needed
       (signed-in users may start them; no administrator rights needed)
    2. waits until the application answers
    3. opens the login page in the default browser

    The services normally start with Windows by themselves; this is the fallback when
    someone stopped them. Problems point to the Server Manager.
#>
. "$PSScriptRoot\common.ps1"
Add-Type -AssemblyName System.Windows.Forms

function Show-Problem([string]$Text) {
    $answer = [System.Windows.Forms.MessageBox]::Show(
        "$Text`n`nOpen the BSU Inventory Server Manager to see what is wrong?",
        'BSU Inventory', [System.Windows.Forms.MessageBoxButtons]::YesNo, [System.Windows.Forms.MessageBoxIcon]::Warning)
    if ($answer -eq [System.Windows.Forms.DialogResult]::Yes) {
        Start-Process -FilePath (Join-Path $PSScriptRoot 'BSU-Inventory-Manager.exe')
    }
}

try {
    $paths = Get-BsuPaths
    $settings = Read-InstallSettings $paths
    $httpsPort = 443
    if ($settings) { $httpsPort = [int]$settings.httpsPort }

    $stopped = @(@($script:DbService, $script:WebService) | Where-Object { $s = Get-BsuService $_; -not $s -or $s.Status -ne 'Running' })
    if ($stopped.Count -gt 0) {
        try {
            Start-BsuServices
        } catch {
            Show-Problem "The BSU Inventory server is not running and could not be started:`n$($_.Exception.Message)"
            exit 1
        }
    }

    $problem = Wait-AppHealthy $httpsPort 60
    if ($problem) {
        Show-Problem "The BSU Inventory server is running but does not answer yet:`n$problem"
        exit 1
    }
    Start-Process (Get-AppUrl $httpsPort $settings)
    exit 0
} catch {
    Show-Problem $_.Exception.Message
    exit 1
}
