<#
.SYNOPSIS
  CubeShop 开发服务管理器（Windows / PowerShell）

.DESCRIPTION
  一行命令启动 / 停止 / 列出 前端或后端服务，或单个服务。
  前端：web(storefront)、admin(管理端)
  后端：api(Laravel serve)、queue(队列 worker)、scheduler(定时调度)

  每个服务以独立进程树方式后台运行，日志与 PID 存于 scripts/.runtime/。
  停止时按进程树递归 kill，确保 npm 拉起的 node(vite) 子进程一并退出。
  仅管理「本地直连开发」进程；数据库/缓存/反代等基础设施请另用 docker compose。

.EXAMPLE
  .\dev.ps1 list
  .\dev.ps1 start
  .\dev.ps1 start frontend
  .\dev.ps1 start backend
  .\dev.ps1 start web
  .\dev.ps1 stop backend
  .\dev.ps1 restart web
  .\dev.ps1 logs api

  若遇到“无法加载文件”的执行策略错误，用以下方式运行：
  powershell -ExecutionPolicy Bypass -File .\dev.ps1 list
#>

[CmdletBinding()]
param(
  [Parameter(Position = 0)] [string] $Action = 'help',
  [Parameter(Position = 1)] [string] $Target = ''
)

$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Definition
$ProjectRoot = Split-Path -Parent $ScriptDir
$RuntimeDir  = Join-Path $ScriptDir '.runtime'
if (-not (Test-Path $RuntimeDir)) { New-Item -ItemType Directory -Force -Path $RuntimeDir | Out-Null }

# ---------- 服务注册表 ----------
$Services = @(
  [PSCustomObject]@{ Name = 'web';       Group = 'frontend'; Dir = 'web';       Port = 3000; Url = 'http://localhost:3000';             Command = 'npm run dev' }
  [PSCustomObject]@{ Name = 'admin';     Group = 'frontend'; Dir = 'admin';     Port = 5173; Url = 'http://localhost:5173';             Command = 'npm run dev' }
  [PSCustomObject]@{ Name = 'api';       Group = 'backend';  Dir = 'backend';   Port = 8000; Url = 'http://127.0.0.1:8000/api/health';  Command = 'php artisan serve --host=127.0.0.1 --port=8000' }
  [PSCustomObject]@{ Name = 'queue';     Group = 'backend';  Dir = 'backend';   Port = $null; Url = $null;                              Command = 'php artisan queue:work --tries=3 --sleep=1' }
  [PSCustomObject]@{ Name = 'scheduler'; Group = 'backend';  Dir = 'backend';   Port = $null; Url = $null;                              Command = 'php artisan schedule:run --no-ansi' }
)

# ---------- 辅助函数 ----------
function Get-PidFile($name) { return Join-Path $RuntimeDir "$name.pid" }
function Get-LogFile($name) { return Join-Path $RuntimeDir "$name.log" }

function Test-Running($name) {
  $pf = Get-PidFile $name
  if (-not (Test-Path $pf)) { return $false }
  $pidStr = (Get-Content $pf -Raw).Trim()
  if (-not $pidStr) { return $false }
  try { return ($null -ne (Get-Process -Id $pidStr -ErrorAction SilentlyContinue)) } catch { return $false }
}

function Stop-ProcessTree([int]$rootPid) {
  # 通过 WMI 递归收集整棵进程树（cmd/npm -> node 等），一次性强制结束
  $toKill = [System.Collections.Generic.HashSet[int]]::new()
  $null = $toKill.Add($rootPid)
  $procs = Get-CimInstance Win32_Process
  $changed = $true
  while ($changed) {
    $changed = $false
    foreach ($p in $procs) {
      if ($p.ParentProcessId -in $toKill -and -not $toKill.Contains($p.ProcessId)) {
        $null = $toKill.Add($p.ProcessId)
        $changed = $true
      }
    }
  }
  foreach ($pid in $toKill) {
    try { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue } catch { }
  }
}

function Ensure-Deps($svc, $dir) {
  if ($env:CS_NO_INSTALL -eq '1') { return }
  if ((Test-Path (Join-Path $dir 'package.json')) -and -not (Test-Path (Join-Path $dir 'node_modules'))) {
    Write-Host "  ! 缺少 node_modules，自动执行 npm install ($dir) ..." -ForegroundColor Yellow
    Push-Location $dir; & npm install; Pop-Location
  }
  if ((Test-Path (Join-Path $dir 'artisan')) -and -not (Test-Path (Join-Path $dir 'vendor'))) {
    Write-Host "  ! 缺少 vendor，自动执行 composer install ($dir) ..." -ForegroundColor Yellow
    Push-Location $dir; & composer install; Pop-Location
  }
}

function Start-Service($svc) {
  if (Test-Running $svc.Name) {
    Write-Host "  • $($svc.Name) 已在运行 (pid $((Get-Content (Get-PidFile $svc.Name) -Raw).Trim())" -ForegroundColor Blue
    return
  }
  $dir = Join-Path $ProjectRoot $svc.Dir
  if (-not (Test-Path $dir)) { Write-Host "  X $($svc.Name): 目录不存在 $dir" -ForegroundColor Red; return }
  Ensure-Deps $svc $dir
  $log  = Get-LogFile $svc.Name
  $pidf = Get-PidFile $svc.Name
  Write-Host "  • 启动 $($svc.Name) ..." -ForegroundColor Blue

  if ($svc.Name -eq 'scheduler') {
    # 调度器需循环执行；用 powershell 起一个常驻循环进程
    $loop = "while (`$true) { Set-Location '$dir'; php artisan schedule:run --no-ansi 2>&1; Start-Sleep 60 }"
    $proc = Start-Process -FilePath powershell.exe -ArgumentList '-NoProfile', '-Command', $loop `
      -WorkingDirectory $dir -RedirectStandardOutput $log -NoNewWindow -PassThru
  } else {
    # 用 -WorkingDirectory 指定目录，避免路径含空格时的引号转义问题
    # 用命令内 2>&1 把 stderr 合并进 stdout，再只重定向 stdout 到日志文件
    # （Start-Process 不允许 RedirectStandardOutput 与 RedirectStandardError 指向同一文件）
    $proc = Start-Process -FilePath cmd.exe -ArgumentList "/c $($svc.Command) 2>&1" `
      -WorkingDirectory $dir -RedirectStandardOutput $log -NoNewWindow -PassThru
  }

  if ($null -eq $proc) {
    Write-Host "  X $($svc.Name) 启动失败" -ForegroundColor Red
    return
  }
  $proc.Id | Out-File -FilePath $pidf -Encoding ascii
  Start-Sleep -Seconds 1

  if (Test-Running $svc.Name) {
    Write-Host "  ✓ $($svc.Name) 已启动 (pid $($proc.Id))" -ForegroundColor Green
    if ($svc.Url) { Write-Host "    访问地址: $($svc.Url)" -ForegroundColor Blue }
  } else {
    Write-Host "  X $($svc.Name) 启动失败，请查看日志: $log" -ForegroundColor Red
    Remove-Item $pidf -Force -ErrorAction SilentlyContinue
  }
}

function Stop-Service($name) {
  $pf = Get-PidFile $name
  if (-not (Test-Path $pf)) { Write-Host "  • $name 未运行" -ForegroundColor Blue; return }
  $pidStr = (Get-Content $pf -Raw).Trim()
  if ($pidStr -match '^\d+$') {
    try { Stop-ProcessTree ([int]$pidStr) } catch { }
  }
  Remove-Item $pf -Force -ErrorAction SilentlyContinue
  Write-Host "  ✓ $name 已停止" -ForegroundColor Green
}

function Resolve-Targets($target) {
  switch ($target) {
    { $_ -in '', 'all' }      { return $Services }
    'frontend'                { return $Services | Where-Object { $_.Group -eq 'frontend' } }
    'backend'                 { return $Services | Where-Object { $_.Group -eq 'backend' } }
    default {
      $s = $Services | Where-Object { $_.Name -eq $target }
      if (-not $s) { Write-Host "  X 未知服务或分组: $target" -ForegroundColor Red; exit 1 }
      return $s
    }
  }
}

function Show-Status {
  Write-Host ""
  Write-Host "CubeShop 服务状态" -ForegroundColor Blue
  Write-Host ("  {0,-11}{1,-10}{2,-10}{3}" -f 'NAME', 'GROUP', 'STATUS', 'URL')
  Write-Host ("  {0,-11}{1,-10}{2,-10}{3}" -f '----', '-----', '------', '---')
  foreach ($s in $Services) {
    $st = if (Test-Running $s.Name) { 'running' } else { 'stopped' }
    $url = if ($s.Url) { $s.Url } else { '—' }
    $color = if ($st -eq 'running') { 'Green' } else { 'Yellow' }
    Write-Host ("  {0,-11}{1,-10}" -f $s.Name, $s.Group) -NoNewline
    Write-Host ("{0,-10}" -f $st) -ForegroundColor $color -NoNewline
    Write-Host "$url"
  }
  Write-Host ""
}

function Show-Logs($name) {
  if (-not $name) { Write-Host "  X 请指定服务名，例如: .\dev.ps1 logs web" -ForegroundColor Red; return }
  $s = $Services | Where-Object { $_.Name -eq $name }
  if (-not $s) { Write-Host "  X 未知服务: $name" -ForegroundColor Red; return }
  $log = Get-LogFile $name
  if (-not (Test-Path $log)) { Write-Host "  X 暂无日志: $log" -ForegroundColor Red; return }
  Write-Host "  • 追踪 $name 日志 (Ctrl+C 退出):" -ForegroundColor Blue
  Get-Content $log -Wait
}

function Show-Usage {
  $lines = @(
    ''
    'CubeShop 开发服务管理器 (Windows)'
    '用法: .\dev.ps1 <动作> [目标]'
    ''
    '动作:'
    '  list                       列出所有服务及状态'
    '  start [all|frontend|backend|<name>]   启动服务（默认 all）'
    '  stop  [all|frontend|backend|<name>]   停止服务（默认 all）'
    '  restart [all|frontend|backend|<name>] 重启服务（默认 all）'
    '  logs <name>               查看某服务日志（持续输出）'
    '  help                      显示本帮助'
    ''
    '目标:'
    '  all       全部服务（默认）'
    '  frontend  web + admin'
    '  backend   api + queue + scheduler'
    '  <name>    单个服务：web | admin | api | queue | scheduler'
    ''
    '环境变量:'
    '  $env:CS_NO_INSTALL = 1   跳过依赖自动安装（node_modules / vendor）'
    ''
    '示例:'
    '  .\dev.ps1 start backend     # 仅启动后端'
    '  .\dev.ps1 start web         # 仅启动前端 storefront'
    '  .\dev.ps1 stop frontend     # 停止全部前端'
    '  .\dev.ps1 list              # 查看状态'
    ''
  )
  Write-Host ($lines -join "`n")
}

# ---------- 主分发 ----------
switch ($Action) {
  { $_ -in 'list', 'status' } { Show-Status }
  { $_ -in 'start', 'stop', 'restart' } {
    $targets = Resolve-Targets $Target
    foreach ($s in $targets) {
      switch ($Action) {
        'start'   { Start-Service $s }
        'stop'    { Stop-Service $s.Name }
        'restart' { Stop-Service $s.Name; Start-Service $s }
      }
    }
    if ($Action -ne 'restart') { Show-Status }
  }
  'logs' { Show-Logs $Target }
  { $_ -in 'help', '-h', '--help' } { Show-Usage }
  default { Write-Host "  X 未知动作: $Action" -ForegroundColor Red; Show-Usage; exit 1 }
}
