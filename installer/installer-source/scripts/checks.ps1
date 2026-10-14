<#
    Run by BSU-Inventory-Setup.exe while the wizard is open (nothing is installed yet).
    Prints one fact per line for the installer to show and check:

      PORT <n> FREE | PORT <n> OURS | PORT <n> USED <process> <path>
      LARAGON <folder>                another Laragon on this PC (never changed)
      IP <address> <DHCP|STATIC> <adapter>
      PUBLICNET <adapter>             a network set to Public (other PCs are blocked)
      VCREDIST <version>              installed Visual C++ 2015+ x64 runtime, or NONE
      DISKFREE <drive> <GB>           free space where it will be installed
      FIXEDDRIVE <drive> <GB>         every local hard disk (to suggest a backup disk)
#>
param([string]$InstallDir = 'C:\BSU-Inventory', [string]$Ports = '80,443,3306')

$ErrorActionPreference = 'SilentlyContinue'
$own = (Join-Path $InstallDir 'laragon').ToLowerInvariant()

foreach ($port in ($Ports -split ',' | Where-Object { $_ -match '^\d+$' })) {
    $ids = @(Get-NetTCPConnection -State Listen -LocalPort ([int]$port) | Select-Object -ExpandProperty OwningProcess -Unique)
    if ($ids.Count -eq 0) { "PORT $port FREE"; continue }
    $p = Get-Process -Id $ids[0]
    $path = ''
    try { $path = $p.Path } catch { }
    if ($path -and $path.ToLowerInvariant().StartsWith($own)) { "PORT $port OURS" }
    else {
        $name = 'unknown'
        if ($p) { $name = $p.ProcessName }
        if (-not $path -and $ids[0] -eq 4) { $name = 'System (IIS or another Windows web service)' }
        "PORT $port USED $name $path"
    }
}

foreach ($d in @('C:\laragon', 'D:\laragon', 'E:\laragon')) {
    if ((Test-Path "$d\laragon.exe") -and ($d.ToLowerInvariant() -ne $own)) { "LARAGON $d" }
}

# Adapters with a default route are the real LAN (not VMware/VirtualBox host-only adapters)
$routed = @(Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | Select-Object -ExpandProperty InterfaceIndex -Unique)
$ips = @(Get-NetIPAddress -AddressFamily IPv4 | Where-Object {
    $o = $_.IPAddress.Split('.') | ForEach-Object { [int]$_ }
    ($o[0] -eq 10) -or ($o[0] -eq 172 -and $o[1] -ge 16 -and $o[1] -le 31) -or ($o[0] -eq 192 -and $o[1] -eq 168)
})
if (@($ips | Where-Object { $routed -contains $_.InterfaceIndex }).Count -gt 0) { $ips = @($ips | Where-Object { $routed -contains $_.InterfaceIndex }) }
$ips | ForEach-Object {
    $kind = 'STATIC'
    if ($_.PrefixOrigin -eq 'Dhcp') { $kind = 'DHCP' }
    "IP $($_.IPAddress) $kind $($_.InterfaceAlias)"
}

Get-NetConnectionProfile | Where-Object { $_.NetworkCategory -eq 'Public' } | ForEach-Object { "PUBLICNET $($_.InterfaceAlias)" }

$vc = Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
if ($vc -and $vc.Installed -eq 1) { "VCREDIST $($vc.Version)" } else { 'VCREDIST NONE' }

$drive = Split-Path -Qualifier $InstallDir
$disk = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='$drive'"
if ($disk) { 'DISKFREE {0} {1}' -f $drive, [math]::Floor($disk.FreeSpace / 1GB) }

Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object { 'FIXEDDRIVE {0} {1}' -f $_.DeviceID, [math]::Floor($_.FreeSpace / 1GB) }
