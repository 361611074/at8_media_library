# at8_media_library 1.7.2 — 发布检查清单（RELEASE_CHECKLIST）

**实测环境**：Z-BlogPHP **1.7.5.3540** / PHP **8.3.35 (NTS)** / **pdo_sqlite** / Windows
**检查日期**：2026-10-13
**检查方式**：全部为**真实执行**——HTTP 请求走完整后台鉴权与 CSRF 链路，
附件读写调用官方 `PostUpload()` / `DelUpload()` / `Upload::Save()`，
断言脚本可复现。本清单**只登记本次实际执行并通过的测试**；
未搭建的环境一律标注「未实测」，不写成已验证。

> **本版本定位**：1.7.0 完成「自建附件底层 → 媒体库 UI + 官方附件系统适配层」的架构重构，
> 1.7.1 为审核前一致性收尾。**1.7.2 是缺陷修复版**：修复 PDO 驱动族
> （`pdo_sqlite` / `pdo_mysql` / `pdo_pgsql`）下统计与映射查询的**位置索引错位**，
> 附件写操作架构、对外接口、配置键均不变，可覆盖升级。

---

## 1. 元数据与标识（版本号全链路一致）

| 项 | 值 | 结果 |
|---|---|---|
| 插件 ID | `at8_media_library` | ✅ 长期稳定（旧 ID `media_library` 配置自动迁移） |
| 插件名称 | 媒体库 · 相册式附件管理 | ✅ |
| **版本号** | **1.7.2** | ✅ 六处一致：`plugin.xml` `<version>` / `function.php` 常量 `AT8_MEDIA_LIBRARY_VERSION` / `README.md` / `CHANGELOG.md` / 本清单 / ZBA 文件名 |
| 构建日期 | `plugin.xml` `<modified>` = **2026-10-13** | ✅ 与 ZBA 文件名日期一致 |
| 作者 / 官网 | 漫步白月光 / https://www.at8.fun/ | ✅ |
| `<adapted>` | `172900` | ✅ 语义 = 官方 `MAJOR.MINOR.COMMIT` 口径，最低 Z-BlogPHP 1.7.x |
| `<phpver>` | `7.4` | ✅ |
| 前缀 | 函数 `at8_media_library_*`、常量 `AT8_MEDIA_LIBRARY_*`、CSS `.mlx-*` / `.ml-*`、JS `window.AT8ML`、配置 `conf_Name=at8_media_library` | ✅ |

---

## 2. 最低系统与 PHP 要求

| 项 | 值 | 依据 |
|---|---|---|
| 最低 Z-BlogPHP | **1.7.x** | 依赖官方公共函数 `PostUpload()` / `DelUpload()`（`zb_system/function/c_system_event.php`）与 `Upload::Save()`；缺失时 `function_exists()` 守卫给出明确提示，不静默失败 |
| 最低 PHP | **7.4** | 本次用 PHP **8.3.35** 对全部 PHP 文件 `php -l` 通过；无 PHP 8.0+ 专有语法 |
| 数据库 | MySQL / SQLite / PostgreSQL | 不建自定义表，仅用系统 `zbp_upload` / `zbp_config`；**本次实测 `pdo_sqlite`**（正是本次修复所针对的 PDO 驱动族） |
| 第三方依赖 | 无（原生 JS，无 Composer / 无 CDN） | ✅ |

---

## 3. 官方接口审计（本任务第 3 项，逐条核对 1.7.5.3540 源码）

### 3.1 复用的官方接口与契约

| 官方接口 | 位置 | 输入契约 | 插件适配方式 |
|---|---|---|---|
| `PostUpload()` | `c_system_event.php`（**公共函数**，非 cmd.php 私有入口） | 读 `$_FILES` | 临时替换 `$_FILES` → 调用 → `finally` 还原 |
| `DelUpload()` | 同上 | 读 `$_GET['id']` | 临时替换 `$_GET['id']` → 调用 → `finally` 还原 |
| `Upload::Save()` | `lib/upload.php` | 对象属性 | 直接调用，仅写展示字段与官方 `ul_LogID` 关联字段 |

官方链路内部已含：权限校验（`CheckRights`）、类型白名单（`Upload::CheckExtName()` ← `ZC_UPLOAD_FILETYPE`，硬拒 `php/phtml/phar/.htaccess/web.config`）、体积上限（`Upload::CheckSize()` ← `ZC_UPLOAD_FILESIZE`）、同月重名（错误码 28）、文件落盘与命名、`Filter_Plugin_Upload_SaveFile` / `Filter_Plugin_Upload_DelFile` 云存储 Hook、记录写入、用户附件计数 `CountMemberArray()`。
**插件不重复实现以上任何一步。**

### 3.2 `try / finally` 还原完整性（已逐处核对）

| 适配点 | 保存 | 替换 | 调用 | 还原 |
|---|---|---|---|---|
| `at8_media_library_official_upload_one()` | `$_FILES` | `$_FILES = array('...' => $one)` | `PostUpload()` | `finally { $_FILES = $saved; }` |
| `at8_media_library_official_delete_upload()` | `$_GET` | `$_GET['id'] = (int)$id` | `DelUpload()` | `finally { $_GET = $saved; }`（整体还原） |

正常返回 / `ZbpErrorException` / 任意 `Throwable` 均在 `finally` 还原，不向同请求的其他插件泄漏临时结构。

### 3.3 异常、权限、CSRF

- **异常**：官方 `ShowError()` 实为 `throw new ZbpErrorException()`（**非 `die`**），插件 `try/catch` 后转 JSON，不产生死页面。官方错误码语义：`5`=非法访问(CSRF/Referer)、`6`=权限、`26`=类型不允许、`27`=超站点大小、`28`=同月重名、`29`=LINKED(被文章引用)。
- **权限**：入口 `check_login()`（插件启用 + 后台登录 + `UploadMng` 查看权）；写操作细分——上传/编辑/关联 = `UploadPst`，删除 = `UploadDel`，`UploadAll` 仅用于操作**他人**附件。
- **数据范围**：`scope_where()` 服务端强制注入 `ul_AuthorID = 当前用户`（无 `UploadAll` 时），不信任前端参数。
- **CSRF**：写操作 `CheckCSRFTokenValid('csrfToken', array('post'))`，失败拒绝；写动作强制 POST（非 POST 返回 405）。
- **存储 Hook**：上传经官方 `Filter_Plugin_Upload_SaveFile`、删除经 `Filter_Plugin_Upload_DelFile`；插件不假设附件在本地磁盘，不自行删本地/云端文件。

### 3.4 静态合规扫描（全仓库）

| 检查项 | 结果 |
|---|---|
| 未自行实现附件上传 / 删除 / 路径管理 | ✅ `move_uploaded_file` / `is_uploaded_file` / `copy` / `finfo_*` / `mime_content_type` 均 **0 处** |
| 未自行实现「替换引擎」 | ✅ 官方附件系统不提供可复用替换能力（核心 `replace` 检索仅命中 `str_replace()` / JS `.replace()`），本插件**不提供**该功能，也未自建 |
| 未修改 Z-BlogPHP 核心文件 | ✅ 改动仅限 `zb_users/plugin/at8_media_library/` |
| 不对附件文件执行 `unlink` | ✅ 仅对插件自身 `cache/` 目录（带 `is_file()` 守卫）；附件删除一律交官方 `Upload::DelFile()` |
| 无伪造 HTTP 自请求 | ✅ `curl_*` 0 处 |
| 无 `!important` / 无调试残留 / 无硬编码密钥 | ✅ 均 0 处 |
| UTF-8 无 BOM | ✅ |

---

## 4. 生命周期实测（**35 项 / 35 PASS / 0 FAIL**）

真实后台 HTTP 操作（`cmd.php?act=PluginDis` / `PluginEnb`、AppCentre `app_del.php`），
每步前后以「数据库计数 + 记录指纹 + 磁盘文件指纹 + 配置键值」四维比对：

| 阶段 | 操作 | 断言结果 |
|---|---|---|
| 基线 | — | 附件 27 / 成员 3 / 文章 5 / 分类 4；磁盘 28（27 附件 + upload 占位 `index.html`）；配置 `perpage=48`、`ConfigVer=1` |
| **停用** | `act=PluginDis`（302） | ✅ `plugin_enabled=0`；**配置、附件记录、磁盘文件、用户数据全部保留**（指纹逐一不变）；插件目录仍在 |
| **重新启用** | `act=PluginEnb`（302） | ✅ `plugin_enabled=1`；业务数据无任何变化 |
| **卸载** | 先停用 → AppCentre `app_del.php`（302） | ✅ 插件目录已删除（`plugin_dir_exists=0`、`plugin_main_exists=0`）；**配置、附件记录、磁盘文件、用户数据全部保留**（指纹逐一不变） |
| **重装** | 官方 `App::UnPack` 1.7.2 ZBA + 启用 | ✅ 版本恢复 1.7.2；数据指纹与基线完全一致 |

> **机制核实（1.7.5 实测）**：官方 `DisablePlugin()` 内部会调用 `UninstallPlugin_<id>()`，
> 即「停用」也进入卸载钩子；真卸载由 AppCentre `app_del.php` → `App::Del()` 直接
> `rrmdir()` 删目录、**不触发**该钩子。故卸载钩子内只清理可再生的 `cache/` 目录，
> **不删任何用户数据**。`App::CanDel()` 要求应用未启用，故卸载前必须先停用。

---

## 5. 覆盖升级实测（**10 项 / 10 PASS / 0 FAIL**）

严格走官方升级链路：降级 → 造旧配置 → 官方端点上传 ZBA → 官方钩子：

| 步骤 | 操作 | 断言结果 |
|---|---|---|
| 1 | 官方 `App::UnPack` 还原 1.7.1 | ✅ 版本 = 1.7.1 |
| 2 | 造旧配置 `ConfigVer=0` | ✅ 已置 0 |
| 3 | **官方 `AppCentre/app_upload.php`** POST 上传 1.7.2 ZBA（root token） | ✅ HTTP 302（成功解包并跳转） |
| 4 | 校验解包结果 | ✅ 12 文件 **list + MD5 与仓库全部一致**；版本 = 1.7.2；`cache->success_updated_app = 'at8_media_library'`（已登记待更新） |
| 5 | GET 官方 `cmd.php?act=misc&type=updatedapp` | ✅ HTTP 200（消费待更新标记并调用 `UpdatePlugin_at8_media_library()`） |
| 6 | **钩子执行的数据级证据** | ✅ `ConfigVer` **0 → 1**（钩子体逻辑确实执行）；`success_updated_app` 被消费清空 |
| 7 | 后台插件管理页 | ✅ 显示 1.7.2 |

> 说明：`Include_Admin_UpdateAppAfter()` 输出的 `<script src="cmd.php?act=misc&type=updatedapp">`
> 由浏览器执行，curl 不跑 JS；但该 endpoint 本身是服务端 PHP 函数，直接 GET 即触发，
> 故上表用直接 GET + `ConfigVer` 变化作为「钩子真实执行」的客观证据。

---

## 6. 功能回归（**69 项 / 69 PASS / 0 FAIL**）

两套回归脚本，均对**真实测试站**发 HTTP 请求：

**套件 1（37 项）**：未登录 401 / 未知 act 400 且不反射 / 写动作 GET 405 / CSRF 缺失与篡改 403 /
上传唯一夹具 200 / `.php` 与 `.xyz` 被官方拒（错误码 26）/ 3MB 超限（27）/ 同月同名（28）/
无文件 400 / 关联不存在文章 400 / 只读接口 200 / `quotecheck` 存在 200 与不存在 404 /
`update` 元信息 200 与不存在 404 / `delete` 不存在 404 / `bulk` 未选择与超限与未知 op 均 400 /
删除前磁盘文件存在、删除后记录消失 / 多文件上传返回 2 行。

**套件 2（32 项）**：列表与统计与分类与文章接口 200 / 夹具上传 200 /
`update` 元信息落库且**核心字段（Name/MimeType/Size/AuthorID）未被改写** /
删除前文件存在、删除后记录与文件均消失、用户附件计数 -1 / `bulk` 各分支 /
**权限矩阵 J0–J9**：普通用户（Level 3）可上传（作者为自己）、删他人附件 403、改他人附件 403、
批量删他人计为 skipped、关联他人文章 403；Level 5 无 `UploadMng` 时列表 403。

> 回归用例均为**可重入**设计（唯一夹具名 + 相对基线断言），执行后测试站数据经清理
> **逐字节回到基线**（附件 27、记录与磁盘指纹一致）。

---

## 7. ZBA 打包与解包核验

- **打包**：用官方 `App::Pack()` + `gzencode(9)` 生成
  `at8_media_library_1.7.2_20261013.zba`，**92272 bytes**
  （sha256 `169088f250b2c0fbd6b67c5f670e41053021d1a2b17a249b74e7cd94669ec35e`）。
- **排除项**（`zbignore.txt`）：`CHANGELOG.md`、`RELEASE_CHECKLIST.md`、`screenshots/`、
  `zbignore.txt`、`cache/`、`.git/`。
- **包内清单（12 文件）**：`LICENSE`、`README.md`、`api.php`、`css/edit.css`、`css/style.css`、
  `function.php`、`include.php`、`logo.png`、`main.php`、`plugin.xml`、`script/app.js`、`script/edit.js`。
- **校验**：ZBA manifest 与仓库有效集 **list + MD5 全部一致**（无缺失、无多余）；
  官方 `App::UnPack` 解包到插件目录后，与仓库**逐文件 MD5 一致**。
- **元数据**：`version=1.7.2` / `adapted=172900` / `phpver=7.4` / `modified=2026-10-13`。

---

## 8. 截图（发布物）

4 张后台截图已用**全新演示数据集**（27 附件 / 26 图片 / 1.45 MB / 11 未关联）重制，
尺寸与原图一致：

| 文件 | 尺寸 | 内容 |
|---|---|---|
| `screenshots/01-相册网格总览.png` | 1600×1000 | 相册网格 27 卡片 + 统计栏 |
| `screenshots/02-按文章分类筛选.png` | 1600×1000 | 分类筛选（卡片带分类标签） |
| `screenshots/03-附件详情与关联文章.png` | 1920×879 | 详情抽屉（**已无「替换文件」按钮**，仅复制 URL / HTML / Markdown、新窗口打开、删除 + 元信息编辑） |
| `screenshots/04-文章关联附件管理.png` | 1920×620 | 文章编辑页「文章附件」面板（插入正文） |

---

## 9. 未实测 / 未解决事项（如实标注）

| 项 | 状态 | 说明 |
|---|---|---|
| **真实第三方对象存储**（S3 / OSS / COS 等） | ⚠️ **未实测** | 仅通过探针确认官方 `Filter_Plugin_Upload_SaveFile` / `Filter_Plugin_Upload_DelFile` **确实被触发**，理论上第三方存储插件可接管；但**未搭建真实对象存储环境端到端验证**，不得视为已验证 |
| `pdo_mysql` / `pdo_pgsql` / `mysqli` | ⚠️ **本次未实测** | 本次实测为 `pdo_sqlite`。1.7.2 修复点（改用按列名读取）对 `mysqli`（本就返回命名键）行为不变；PDO 家族其余驱动按同一修复逻辑处理，但未在本次逐一实测 |
| 后台独立管理页的浏览器端交互（拖拽、无限滚动） | ⚠️ 仅截图目视 | 已通过截图目视确认渲染正确，未做浏览器自动化交互断言 |

---

## 10. 发布物

- `at8_media_library_1.7.2_20261013.zba`（12 文件，92272 bytes）
- GitHub：https://github.com/361611074/at8_media_library

---

## 附：本次测试脚本（非发布物，存放于 `tools/`）

| 脚本 | 用途 | 结果 |
|---|---|---|
| `regress1.sh` | 鉴权 / 白名单 / 方法 / CSRF / 上传矩阵 / 删除 / 多文件 | 37/37 PASS |
| `regress2.sh` | 元信息 / 计数 / 批量 / 权限矩阵 J0–J9 | 32/32 PASS |
| `lifecycle.sh` | 停用 / 重新启用 / 卸载 / 重装 全链路数据一致性 | 35/35 PASS |
| `upgrade.sh` | 降级 → 官方端点上传 → 官方升级钩子 | 10/10 PASS |
| `verify_zba2.php` | ZBA manifest vs 仓库 | 12/12 匹配 |
| `verify_unpacked2.php` | 解包目录 vs 仓库 | 12/12 匹配 |
