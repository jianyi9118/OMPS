<#
  OMPS 设备采集客户端 1.0（设计文档第 14 节）

  只采集服务器“采集范围”中列出的硬件信息：计算机名、操作系统、CPU、内存、硬盘、主板、显示器、网卡。
  不采集键盘输入、屏幕、文件、浏览记录、登录账号。
  兼容 Windows 自带的 PowerShell 2.0 及以上，不需要安装任何软件。

  用法：
    注册（安装时运行一次）：  omps-collector.ps1 -Register -Server http://服务器/omps -Code 注册码
    上报（计划任务调用）：    omps-collector.ps1 -Event boot | daily
    查看将要上报的内容：      omps-collector.ps1 -Show
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
    if ($Server -eq '' -or $Code -eq '') { Fail '注册需要 -Server 和 -Code。' 1 }
    $os = Wmi 'Win32_OperatingSystem'
    $osText = ''; if ($os.Count) { $osText = $os[0].Caption + ' ' + $os[0].Version }
    $body = '{"code":' + (J-Str $Code) + ',"computer_name":' + (J-Str $env:COMPUTERNAME) + ',"os":' + (J-Str $osText) + ',"version":' + (J-Str $Version) + '}'
    try { $resp = Post (Api $Server '/api/v1/collector/register') $body $null }
    catch { Fail ('注册失败：' + $_.Exception.Message + '（注册码错误、已用过或已过期时，请设备管理员重新生成）') 1 }
    $t = [regex]::Match($resp, '"token":"([a-f0-9]+)"')
    if (-not $t.Success) { Fail '注册失败：服务器没有返回令牌。' 1 }
    $scope = Scope-From $resp
    $cfg = @{ 'server' = $Server.TrimEnd('/'); 'token' = $t.Groups[1].Value; 'scope' = ($scope -join ',') }
    Write-Config $cfg
    Write-Host ('注册成功。本机将上报：' + ($scope -join ', '))
    $Event = 'boot'
}

$scope = $AllItems
if ($cfg['scope'] -ne $null) { $scope = @($cfg['scope'].Split(',') | Where-Object { $_ -ne '' }) }
$data = Collect $scope

if ($Show) {
    Write-Host ('采集范围：' + ($scope -join ', '))
    Write-Host $data
    exit 0
}

if (-not $cfg['token']) { Fail ('还没有注册：' + $ConfigPath) 1 }
if (@('boot', 'daily', 'manual') -notcontains $Event) { $Event = 'manual' }
$body = '{"event":' + (J-Str $Event) + ',"version":' + (J-Str $Version) + ',"data":' + $data + '}'
try { $resp = Post (Api $cfg['server'] '/api/v1/collector/report') $body $cfg['token'] }
catch {
    $msg = $_.Exception.Message
    if ($msg -match '401') { Fail '上报被拒绝：本机令牌已被设备管理员撤销，需要重新注册。' 2 }
    Fail ('上报失败（下次开机或明天再试）：' + $msg) 3
}
# the server may have changed the scope: the next report follows it
$cfg['scope'] = ((Scope-From $resp) -join ',')
Write-Config $cfg
Write-Host '上报完成。'
exit 0
