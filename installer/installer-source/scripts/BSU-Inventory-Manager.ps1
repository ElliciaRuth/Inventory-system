<#
.SYNOPSIS
    BSU Inventory Server Manager: status, start/stop, backups, logs and repair in one window.
    Started (as administrator) by BSU-Inventory-Manager.exe.

.DESCRIPTION
    Laragon's own window cannot manage the system's servers (they run as Windows services so
    they start with Windows), so this small tool covers what an administrator needs without
    a command prompt. -Report prints the status checks to the console instead.
#>
param([switch]$Report)

. "$PSScriptRoot\common.ps1"
$ErrorActionPreference = 'Continue'
$paths = Get-BsuPaths

if ($Report) {
    $st = Get-ServerStatus $paths
    Write-Host 'BSU Inventory System Status'
    Write-Host ''
    foreach ($r in $st.Rows) { Write-Host ('{0,-26} {1}  {2}' -f $r.Name, $(if ($r.Ok) { 'OK ' } else { 'X  ' }), $r.Detail) }
    Write-Host ''
    foreach ($a in $st.Addresses) { Write-Host "LAN URL: https://$($a.IP)$(Get-HttpsSuffix $st.HttpsPort)/  ($($a.Interface)$(if ($a.Dhcp) { ', DHCP' }))" }
    exit 0
}

$id = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $id.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process -FilePath 'powershell.exe' -Verb RunAs -WindowStyle Hidden -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    exit 0
}

Add-Type -AssemblyName System.Windows.Forms, System.Drawing, Microsoft.VisualBasic
[System.Windows.Forms.Application]::EnableVisualStyles()

$form = New-Object System.Windows.Forms.Form
$form.Text = 'BSU Inventory Server Manager'
$form.Size = New-Object System.Drawing.Size(860, 640)
$form.MinimumSize = New-Object System.Drawing.Size(760, 560)
$form.StartPosition = 'CenterScreen'
$form.Font = New-Object System.Drawing.Font('Segoe UI', 9)
$icon = Join-Path $PSScriptRoot 'bsu-inventory.ico'
if (Test-Path $icon) { $form.Icon = New-Object System.Drawing.Icon $icon }

$header = New-Object System.Windows.Forms.Label
$header.Font = New-Object System.Drawing.Font('Segoe UI', 14, [System.Drawing.FontStyle]::Bold)
$header.AutoSize = $true
$header.Location = New-Object System.Drawing.Point(16, 12)
$form.Controls.Add($header)

$list = New-Object System.Windows.Forms.ListView
$list.View = 'Details'
$list.FullRowSelect = $true
$list.HeaderStyle = 'Nonclickable'
$list.Location = New-Object System.Drawing.Point(16, 50)
$list.Size = New-Object System.Drawing.Size(812, 210)
$list.Anchor = 'Top, Left, Right'
[void]$list.Columns.Add('Check', 190)
[void]$list.Columns.Add('', 40)
[void]$list.Columns.Add('Details', 560)
$form.Controls.Add($list)

$addressBox = New-Object System.Windows.Forms.TextBox
$addressBox.Multiline = $true
$addressBox.ReadOnly = $true
$addressBox.ScrollBars = 'Vertical'
$addressBox.Location = New-Object System.Drawing.Point(16, 270)
$addressBox.Size = New-Object System.Drawing.Size(812, 96)
$addressBox.Anchor = 'Top, Left, Right'
$addressBox.Font = New-Object System.Drawing.Font('Consolas', 9.5)
$form.Controls.Add($addressBox)

$busy = New-Object System.Windows.Forms.Label
$busy.AutoSize = $true
$busy.ForeColor = [System.Drawing.Color]::DimGray
$busy.Location = New-Object System.Drawing.Point(16, 372)
$form.Controls.Add($busy)

$panel = New-Object System.Windows.Forms.FlowLayoutPanel
$panel.Location = New-Object System.Drawing.Point(12, 396)
$panel.Size = New-Object System.Drawing.Size(820, 190)
$panel.Anchor = 'Top, Bottom, Left, Right'
$form.Controls.Add($panel)

$script:status = $null

function Refresh-Status {
    $busy.Text = 'Checking...'
    $form.Cursor = 'WaitCursor'
    [System.Windows.Forms.Application]::DoEvents()
    $script:status = Get-ServerStatus $paths
    $list.Items.Clear()
    $allOk = $true
    foreach ($r in $script:status.Rows) {
        $item = New-Object System.Windows.Forms.ListViewItem($r.Name)
        [void]$item.SubItems.Add($(if ($r.Ok) { [string][char]0x2714 } else { [string][char]0x2716 }))
        [void]$item.SubItems.Add([string]$r.Detail)
        $item.ForeColor = $(if ($r.Ok) { [System.Drawing.Color]::DarkGreen } else { [System.Drawing.Color]::Firebrick })
        [void]$list.Items.Add($item)
        if (-not $r.Ok) { $allOk = $false }
    }
    $web = Get-BsuService $script:WebService
    $running = $web -and $web.Status -eq 'Running'
    $dot = [string][char]0x25CF
    if ($allOk) { $header.Text = "$dot  Server status: Running"; $header.ForeColor = [System.Drawing.Color]::ForestGreen }
    elseif ($running) { $header.Text = "$dot  Server status: Running, with problems"; $header.ForeColor = [System.Drawing.Color]::DarkOrange }
    else { $header.Text = "$dot  Server status: Stopped"; $header.ForeColor = [System.Drawing.Color]::Firebrick }

    $suffix = Get-HttpsSuffix $script:status.HttpsPort
    $lines = @("This server:  https://localhost$suffix/")
    $lan = $true
    if ($script:status.Settings) { $lan = [bool]$script:status.Settings.lanAccess }
    if ($lan) {
        foreach ($a in $script:status.Addresses) {
            $lines += "LAN URL:      https://$($a.IP)$suffix/   ($($a.Interface)$(if ($a.Dhcp) { ', address from DHCP' }))"
        }
        if ($script:status.Addresses.Count -eq 0) { $lines += 'No LAN address: connect this PC to the office network, then press Repair.' }
        if (@($script:status.Addresses | Where-Object { $_.Dhcp }).Count -gt 0) {
            $lines += 'DHCP addresses can change: ask for a DHCP reservation for this PC. After a change, press Repair.'
        }
    } else { $lines += 'LAN access is off (this PC only).' }
    $addressBox.Text = $lines -join "`r`n"
    $busy.Text = "Last checked $(Get-Date -Format 'HH:mm:ss')"
    $form.Cursor = 'Default'
}

# Runs one of the server scripts in its own hidden PowerShell and waits for it
function Invoke-ServerScript([string]$Script, [string[]]$Arguments, [string]$What) {
    $busy.Text = "$What... (this can take a few minutes)"
    $form.Cursor = 'WaitCursor'
    [System.Windows.Forms.Application]::DoEvents()
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$(Join-Path $PSScriptRoot $Script)`"") + $Arguments
    $p = Start-Process -FilePath 'powershell.exe' -ArgumentList $argList -WindowStyle Hidden -Wait -PassThru
    $form.Cursor = 'Default'
    return $p.ExitCode
}

function Show-Info([string]$Text, [string]$Icon = 'Information') {
    [void][System.Windows.Forms.MessageBox]::Show($Text, 'BSU Inventory Server Manager', 'OK', $Icon)
}

function Confirm-Action([string]$Text) {
    return [System.Windows.Forms.MessageBox]::Show($Text, 'BSU Inventory Server Manager', 'YesNo', 'Warning') -eq 'Yes'
}

function Add-Button([string]$Text, [scriptblock]$Action) {
    $b = New-Object System.Windows.Forms.Button
    $b.Text = $Text
    $b.Size = New-Object System.Drawing.Size(196, 36)
    $b.Margin = New-Object System.Windows.Forms.Padding(4)
    $b.Tag = $Action
    $b.Add_Click({
        try { & $this.Tag } catch { Show-Info $_.Exception.Message 'Error' }
        Refresh-Status
    })
    $panel.Controls.Add($b)
}

$url = { "https://localhost$(Get-HttpsSuffix $script:status.HttpsPort)/" }

Add-Button 'Open BSU Inventory' { Start-Process (& $url) }
Add-Button 'Copy LAN address' {
    $a = @($script:status.Addresses)
    if ($a.Count -eq 0) { Show-Info 'No LAN address found.' 'Warning'; return }
    $text = "https://$($a[0].IP)$(Get-HttpsSuffix $script:status.HttpsPort)/"
    [System.Windows.Forms.Clipboard]::SetText($text)
    Show-Info "Copied: $text"
}
Add-Button 'How other PCs connect' {
    $info = Join-Path $paths.Config 'connection-info.txt'
    if (Test-Path $info) { Start-Process notepad.exe -ArgumentList "`"$info`"" } else { Show-Info 'Run Repair first.' 'Warning' }
}
Add-Button 'Refresh status' { }

Add-Button 'Start services' { Start-BsuServices }
Add-Button 'Stop services' {
    if (Confirm-Action 'Stop the BSU Inventory server? Nobody on the network can use the system until it is started again.') { Stop-BsuServices }
}
Add-Button 'Restart services' { Stop-BsuServices; Start-BsuServices }
Add-Button 'Repair configuration' {
    if (-not (Confirm-Action "Repair checks and rewrites the server configuration, renews the HTTPS certificate for this PC's current addresses and restarts the services (about a minute). Data is not changed. Continue?")) { return }
    $code = Invoke-ServerScript 'configure.ps1' @('-Mode', 'Repair') 'Repairing'
    if ($code -eq 0) { Show-Info 'Repair finished. The system is running.' }
    else { Show-Info "Repair failed. See the newest configure-repair log in:`n$($paths.Logs)" 'Error' }
}

Add-Button 'Back up now' {
    $code = Invoke-ServerScript 'backup.ps1' @('-Reason', 'manual') 'Backing up'
    $dir = Join-Path $script:status.Settings.backupDir 'full'
    if ($code -eq 0) { Show-Info "Backup finished:`n$dir" } else { Show-Info "The backup failed. See $($paths.Logs)\backup.log." 'Error' }
}
Add-Button 'Restore backup...' {
    $dlg = New-Object System.Windows.Forms.OpenFileDialog
    $dlg.Title = 'Choose a BSU Inventory server backup'
    $dlg.Filter = 'BSU Inventory backups (BSU-Inventory-Backup-*.zip)|BSU-Inventory-Backup-*.zip|Zip files (*.zip)|*.zip'
    $dlg.InitialDirectory = Join-Path $script:status.Settings.backupDir 'full'
    if ($dlg.ShowDialog() -ne 'OK') { return }
    if (-not (Confirm-Action ("Replace ALL current inventory data with this backup?`n`n$($dlg.FileName)`n`n" +
        "A safety backup of the current data is made first. Users cannot work during the restore."))) { return }
    $certs = [System.Windows.Forms.MessageBox]::Show(
        "Also restore the backup's certificate authority?`n`nChoose Yes when this is a NEW server replacing the old one, so phones and PCs that trusted the old server keep working. Choose No otherwise.",
        'BSU Inventory Server Manager', 'YesNo', 'Question')
    $extra = @('-BackupFile', "`"$($dlg.FileName)`"")
    if ($certs -eq 'Yes') { $extra += '-RestoreCertificates' }
    $code = Invoke-ServerScript 'restore.ps1' $extra 'Restoring'
    if ($code -eq 0) { Show-Info 'Restore finished. The system is running.' }
    else { Show-Info "The restore failed. See the newest restore log in:`n$($paths.Logs)" 'Error' }
}
Add-Button 'Open backups folder' { Start-Process explorer.exe -ArgumentList "`"$($script:status.Settings.backupDir)`"" }
Add-Button 'Open logs folder' { Start-Process explorer.exe -ArgumentList "`"$($paths.Logs)`"" }

Add-Button 'Laragon (database tools)' {
    Show-Info ("Laragon opens for its tools (HeidiSQL database browser). Do not press 'Start All': the BSU Inventory servers already run as Windows services.`n`n" +
               "Database: 127.0.0.1, port $($script:status.Settings.dbPort). The root login is in:`n$($paths.RootCnf)")
    Start-Process (Join-Path $paths.Laragon 'laragon.exe')
}
Add-Button 'Create administrator...' {
    $fields = [ordered]@{ first_name = 'First name'; last_name = 'Last name'; username = 'Username'; email = 'Email'; password = 'Password' }
    $values = @{}
    foreach ($k in $fields.Keys) {
        $v = [Microsoft.VisualBasic.Interaction]::InputBox("New Technical Staff (administrator) account`n`n$($fields[$k]):", 'Create administrator', '')
        if ($v -eq '') { return }
        $values[$k] = $v
    }
    $confirm = [Microsoft.VisualBasic.Interaction]::InputBox('Type the password again:', 'Create administrator', '')
    if ($confirm -ne $values.password) { Show-Info 'The passwords do not match.' 'Warning'; return }
    $file = Join-Path $paths.SecretsDir ("admin-{0}.json" -f ([guid]::NewGuid().ToString('N')))
    Write-Text $file ($values | ConvertTo-Json)
    $r = Invoke-Spark $paths @('user:create-admin', '--from-file', $file) ''
    Remove-Item $file -Force -ErrorAction SilentlyContinue
    $text = [regex]::Replace($r.Output, '\x1b\[[0-9;]*m', '')
    if ($r.Code -eq 0) { Show-Info $text } else { Show-Info $text 'Warning' }
}
Add-Button 'Reset a password...' {
    $user = [Microsoft.VisualBasic.Interaction]::InputBox("Username of the account (the user must choose a new password at the next login):", 'Reset a password', '')
    if (-not $user) { return }
    $r = Invoke-Tool -Exe $paths.PhpExe -Arguments @('spark', 'user:reset-password', $user) -WorkingDirectory $paths.Backend
    # Strip the console colour codes from spark's output
    Show-Info ([regex]::Replace($r.Output, '\x1b\[[0-9;]*m', ''))
}

$form.Add_Shown({ Refresh-Status })
[void]$form.ShowDialog()
