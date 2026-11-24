<#
  OMPS device collector 1.0 (design document section 14)

  Collects only the hardware items listed in the server's "Collection scope": computer name,
  operating system, CPU, memory, disks, motherboard, monitors, network adapters.
  Never collects keystrokes, screen contents, files, browsing history or login accounts.
  Works with the PowerShell 2.0 built into Windows and later; nothing else is installed.

  Usage:
    Register (once, at install):      omps-collector.ps1 -Register -Server http://server/omps -Code CODE
    Report (run by scheduled tasks):  omps-collector.ps1 -Event boot | daily
    Show what would be reported:      omps-collector.ps1 -Show
#>
param(
    [switch]$Register,
    [string]$Server = '',
    [string]$Code = '',
    [string]$Event = 'manual',
    [switch]$Show,
    [string]$ConfigPath = ''
)

$Version = '1.0'
$AllItems = @('computer_name', 'os', 'cpu', 'memory', 'disk', 'board', 'monitor', 'nic')
if ($ConfigPath -eq '') { $ConfigPath = Join-Path $env:ProgramData 'OMPS\collector.ini' }

function Read-Config {
    $c = @{}
    if (Test-Path $ConfigPath) {
        foreach ($line in [System.IO.File]::ReadAllLines($ConfigPath)) {
            $i = $line.IndexOf('=')
            if ($i -gt 0) { $c[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim() }
        }
    }
    return $c
}

function Write-Config($c) {
    $dir = Split-Path $ConfigPath -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $lines = @()
    foreach ($k in $c.Keys) { $lines += ($k + '=' + $c[$k]) }
    [System.IO.File]::WriteAllLines($ConfigPath, $lines)
}

# ---------- JSON (PowerShell 2.0 has no ConvertTo-Json) ----------
function J-Str($s) {
    if ($s -eq $null) { return 'null' }
    $s = ([string]$s).Trim()
    if ($s -eq '') { return 'null' }
    $sb = New-Object System.Text.StringBuilder
    foreach ($ch in $s.ToCharArray()) {
        $n = [int]$ch
        if ($ch -eq '"') { [void]$sb.Append('\"') }
        elseif ($ch -eq '\') { [void]$sb.Append('\\') }
        elseif ($n -lt 32) { [void]$sb.Append(('\u{0:x4}' -f $n)) }
        else { [void]$sb.Append($ch) }
    }
    return '"' + $sb.ToString() + '"'
}
function J-Item($model, $serial, $spec) {
    return '{"model":' + (J-Str $model) + ',"serial_no":' + (J-Str $serial) + ',"spec":' + (J-Str $spec) + '}'
}
function J-List($items) { return '[' + ($items -join ',') + ']' }

function Gb($bytes) {
    if ($bytes -eq $null -or $bytes -eq '') { return $null }
    return ([math]::Round([double]$bytes / 1073741824)).ToString() + ' GB'
}

function Wmi($class, $ns) {
    try {
        # the leading comma keeps a one-item array an array (PowerShell unwraps returned arrays)
        if ($ns) { return ,@(Get-WmiObject -Namespace $ns -Class $class -ErrorAction Stop) }
        return ,@(Get-WmiObject -Class $class -ErrorAction Stop)
    } catch { return ,@() }
}

function Text-FromCodes($codes) {
    if ($codes -eq $null) { return $null }
    $s = ''
    foreach ($c in $codes) { if ($c -ne 0) { $s += [char]$c } }
    return $s.Trim()
}

# ---------- collection: only the items in scope ----------
function Collect($scope) {
    $parts = @()
    if ($scope -contains 'computer_name') { $parts += '"computer_name":' + (J-Str $env:COMPUTERNAME) }
    if ($scope -contains 'os') {
        $os = Wmi 'Win32_OperatingSystem'
        if ($os.Count) { $parts += '"os":' + (J-Str ($os[0].Caption + ' ' + $os[0].Version)) }
    }
    if ($scope -contains 'cpu') {
        $l = @(); foreach ($x in (Wmi 'Win32_Processor')) { $l += J-Item $x.Name $x.ProcessorId ([string]$x.NumberOfCores + ' cores') }
        $parts += '"cpu":' + (J-List $l)
    }
    if ($scope -contains 'memory') {
        $l = @(); foreach ($x in (Wmi 'Win32_PhysicalMemory')) { $l += J-Item (([string]$x.Manufacturer + ' ' + [string]$x.PartNumber).Trim()) $x.SerialNumber (Gb $x.Capacity) }
        $parts += '"memory":' + (J-List $l)
    }
    if ($scope -contains 'disk') {
        $l = @(); foreach ($x in (Wmi 'Win32_DiskDrive')) { if ($x.InterfaceType -ne 'USB') { $l += J-Item $x.Model $x.SerialNumber (Gb $x.Size) } }
        $parts += '"disk":' + (J-List $l)
    }
    if ($scope -contains 'board') {
        $l = @(); foreach ($x in (Wmi 'Win32_BaseBoard')) { $l += J-Item (([string]$x.Manufacturer + ' ' + [string]$x.Product).Trim()) $x.SerialNumber $null }
        $parts += '"board":' + (J-List $l)
    }
    if ($scope -contains 'monitor') {
        $l = @(); foreach ($x in (Wmi 'WmiMonitorID' 'root\wmi')) { $l += J-Item (Text-FromCodes $x.UserFriendlyName) (Text-FromCodes $x.SerialNumberID) $null }
        $parts += '"monitor":' + (J-List $l)
    }
    if ($scope -contains 'nic') {
        $l = @()
        foreach ($x in (Wmi 'Win32_NetworkAdapter')) {
            if ($x.PhysicalAdapter -and $x.MACAddress -and ($x.PNPDeviceID -notlike 'ROOT\*') -and ($x.PNPDeviceID -notlike 'USB\*')) { $l += J-Item $x.Name $x.MACAddress $null }
        }
        $parts += '"nic":' + (J-List $l)
    }
    return '{' + ($parts -join ',') + '}'
}

function Post($url, $body, $token) {
    $wc = New-Object System.Net.WebClient
    $wc.Encoding = [System.Text.Encoding]::UTF8
    $wc.Headers.Add('Content-Type', 'application/json; charset=utf-8')
    $wc.Headers.Add('Accept', 'application/json')
    if ($token) { $wc.Headers.Add('X-Collector-Token', $token) }
    return $wc.UploadString($url, 'POST', $body)
}

function Api($server, $path) { return $server.TrimEnd('/') + '/index.php?r=' + $path }

function Scope-From($json) {
    $m = [regex]::Match($json, '"scope":\[([^\]]*)\]')
    if (-not $m.Success) { return $AllItems }
    $out = @()
    foreach ($x in [regex]::Matches($m.Groups[1].Value, '"([a-z_]+)"')) { $out += $x.Groups[1].Value }
    return $out
}

function Fail($msg, $code) { Write-Host $msg; exit $code }

$cfg = Read-Config

if ($Register) {
    if ($Server -eq '' -or $Code -eq '') { Fail 'Registration needs -Server and -Code.' 1 }
    $os = Wmi 'Win32_OperatingSystem'
    $osText = ''; if ($os.Count) { $osText = $os[0].Caption + ' ' + $os[0].Version }
    $body = '{"code":' + (J-Str $Code) + ',"computer_name":' + (J-Str $env:COMPUTERNAME) + ',"os":' + (J-Str $osText) + ',"version":' + (J-Str $Version) + '}'
    try { $resp = Post (Api $Server '/api/v1/collector/register') $body $null }
    catch { Fail ('Registration failed: ' + $_.Exception.Message + ' (if the code is wrong, used or expired, ask the device administrator for a new one)') 1 }
    $t = [regex]::Match($resp, '"token":"([a-f0-9]+)"')
    if (-not $t.Success) { Fail 'Registration failed: the server returned no token.' 1 }
    $scope = Scope-From $resp
    $cfg = @{ 'server' = $Server.TrimEnd('/'); 'token' = $t.Groups[1].Value; 'scope' = ($scope -join ',') }
    Write-Config $cfg
    Write-Host ('Registered. This computer will report: ' + ($scope -join ', '))
    $Event = 'boot'
}

$scope = $AllItems
if ($cfg['scope'] -ne $null) { $scope = @($cfg['scope'].Split(',') | Where-Object { $_ -ne '' }) }
$data = Collect $scope

if ($Show) {
    Write-Host ('Collection scope: ' + ($scope -join ', '))
    Write-Host $data
    exit 0
}

if (-not $cfg['token']) { Fail ('Not registered yet: ' + $ConfigPath) 1 }
if (@('boot', 'daily', 'manual') -notcontains $Event) { $Event = 'manual' }
$body = '{"event":' + (J-Str $Event) + ',"version":' + (J-Str $Version) + ',"data":' + $data + '}'
try { $resp = Post (Api $cfg['server'] '/api/v1/collector/report') $body $cfg['token'] }
catch {
    $msg = $_.Exception.Message
    if ($msg -match '401') { Fail 'Report refused: the device administrator revoked this computer. Register it again.' 2 }
    Fail ('Report failed (will retry at next start-up or tomorrow): ' + $msg) 3
}
# the server may have changed the scope: the next report follows it
$cfg['scope'] = ((Scope-From $resp) -join ',')
Write-Config $cfg
Write-Host 'Report sent.'
exit 0
