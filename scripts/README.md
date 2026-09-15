# CubeShop 开发服务管理脚本

本目录提供「一键启动 / 停止 / 列出」前后端开发服务的脚本，覆盖**本地直连开发**模式（不依赖 Docker）：

| 平台 | 脚本 | 运行环境 |
| --- | --- | --- |
| Linux / macOS | `dev.sh` | Bash |
| Windows | `dev.ps1` | PowerShell 5.1+ |

脚本会为每个服务起一个**独立的后台进程**，并把 PID 与日志写入 `scripts/.runtime/`，停止时按进程组 / 进程树整体回收（npm 拉起的 `node`(Vite) 子进程会一并退出，不会残留）。

---

## 1. 前置依赖

在第一次启动前，请确保本机已安装：

- **Node.js 20+** 与 **npm**（前端）
- **PHP 8.4+**（含 `pdo_pgsql` 扩展）与 **Composer**（后端）
- **PostgreSQL 14+**（本地数据库；无 PG 时可用 SQLite 临时验证，见仓库根目录 README）

> 脚本会在首次启动时**自动检测并安装依赖**：若某服务目录下没有 `node_modules` / `vendor`，会自动执行 `npm install` / `composer install`。
> 如不想自动安装，可设置环境变量 `CS_NO_INSTALL=1`（见下文）。

---

## 2. 快速开始

### Linux / macOS

```bash
cd CubeShop/scripts

# 列出所有服务状态
./dev.sh list

# 启动全部服务（前端 + 后端）
./dev.sh start

# 只启动前端 / 后端
./dev.sh start frontend
./dev.sh start backend

# 只启动单个服务
./dev.sh start web

# 停止
./dev.sh stop                # 全部
./dev.sh stop backend        # 后端组
./dev.sh stop web            # 单个

# 重启
./dev.sh restart web

# 追踪某服务日志
./dev.sh logs api
```

> 若 `./dev.sh` 提示无执行权限，先 `chmod +x dev.sh`。

### Windows（PowerShell）

```powershell
cd CubeShop\scripts

# 列出所有服务状态
.\dev.ps1 list

# 启动全部 / 分组 / 单个
.\dev.ps1 start
.\dev.ps1 start frontend
.\dev.ps1 start backend
.\dev.ps1 start web

# 停止
.\dev.ps1 stop
.\dev.ps1 stop backend
.\dev.ps1 stop web

# 重启
.\dev.ps1 restart web

# 追踪日志
.\dev.ps1 logs api
```

> **执行策略报错？**（如 `无法加载文件 ... 因为在此系统上禁止运行脚本`）
> 用下面方式临时绕过执行策略运行：
> ```powershell
> powershell -ExecutionPolicy Bypass -File .\dev.ps1 list
> ```
> 或为本用户放宽策略（仅一次）：`Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`

---

## 3. 命令一览

| 动作 | 参数（目标） | 说明 |
| --- | --- | --- |
| `list` / `status` | — | 列出所有服务及运行状态、访问地址 |
| `start` | `all`(默认) / `frontend` / `backend` / `<name>` | 启动服务 |
| `stop` | 同上 | 停止服务 |
| `restart` | 同上 | 先停后启 |
| `logs` | `<name>`（必填） | 持续输出某服务日志（`tail -f` / `Get-Content -Wait`） |
| `help` | — | 显示帮助 |

目标取值：
- `all`：全部服务（默认）
- `frontend`：前端组 = `web` + `admin`
- `backend`：后端组 = `api` + `queue` + `scheduler`
- `<name>`：单个服务名（见下表）

---

## 4. 服务清单

| 名称 | 分组 | 目录 | 端口 | 访问地址 | 启动命令 |
| --- | --- | --- | --- | --- | --- |
| `web` | frontend | `web/` | 3000 | http://localhost:3000 | `npm run dev` |
| `admin` | frontend | `admin/` | 5173 | http://localhost:5173 | `npm run dev` |
| `api` | backend | `backend/` | 8000 | http://127.0.0.1:8000/api/health | `php artisan serve --host=127.0.0.1 --port=8000` |
| `queue` | backend | `backend/` | — | — | `php artisan queue:work --tries=3 --sleep=1` |
| `scheduler` | backend | `backend/` | — | — | `php artisan schedule:run`（每 60s 循环） |

前端 `web` / `admin` 的 Vite 已配置 `/api` 代理到 `127.0.0.1:8000`，因此启动 `api` 后前端即可联调。

---

## 5. 进程与日志

- 运行时文件位于 `scripts/.runtime/`（已被 `.gitignore` 忽略）：
  - `<name>.pid`：进程组 / 根进程 PID
  - `<name>.log`：该服务标准输出与错误
- 查看日志也可直接打开对应文件，或用 `logs` 子命令实时追踪。
- 停止时脚本会按 **进程组（Linux）/ 进程树（Windows）** 整体结束，避免 Vite / PHP 子进程残留。

---

## 6. 环境变量

| 变量 | 取值 | 作用 |
| --- | --- | --- |
| `CS_NO_INSTALL` | `1` | 跳过依赖自动安装（`node_modules` / `vendor`） |

示例（Linux）：
```bash
CS_NO_INSTALL=1 ./dev.sh start
```
示例（Windows PowerShell）：
```powershell
$env:CS_NO_INSTALL='1'; .\dev.ps1 start
```

---

## 7. 常见问题

**Q：端口被占用（如 8000 / 3000 / 5173 已占用）？**
先 `stop` 对应服务，或释放占用端口后再启动。如需改端口，编辑本 README 第 4 节对应的启动命令，并同步修改 `dev.sh` / `dev.ps1` 中的服务注册表。

**Q：启动后前端页面打不开 / 接口 404？**
确认 `api` 服务已启动且 PostgreSQL 可连接；`web` / `admin` 的 `/api` 代理目标为 `127.0.0.1:8000`。

**Q：为什么没有 `postgres` / `redis` / `nginx`？**
这些属于基础设施，按仓库根目录 README 的「方式一」用 `docker compose up -d` 启动即可。本脚本只管理**应用层**的前后端开发进程，二者可配合使用（Docker 提供 DB/缓存，脚本提供前后端 + Laravel serve）。

**Q：Windows 上 `npm run dev` 启动了但停止不干净？**
脚本通过 WMI 递归收集进程树（cmd → npm → node）一并结束。若仍有残留，可在任务管理器结束 `node` / `vite` 进程，或重启终端。

---

## 8. 与 Docker 的关系

- **只用本脚本**：本地装好 Node/PHP/PostgreSQL，跑前后端 + Laravel serve，适合纯前端 / 后端开发调试。
- **只用 Docker**：`docker compose up -d` 提供完整 postgres + redis + app + nginx 环境，无需本地装 PHP/Composer。
- **混合**：`docker compose up -d postgres redis` 提供依赖，再用本脚本启动前后端与 `api`（将 `.env` 的 `DB_HOST` 指向 `localhost` 的映射端口）。

两种方式按需选择，互不冲突。
