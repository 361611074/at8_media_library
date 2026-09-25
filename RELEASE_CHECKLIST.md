# at8_media_library 1.7.1 — 发布检查清单（RELEASE_CHECKLIST）

检查基准：《Z-BlogPHP 插件 AI Agent 开发规范》§29
实测环境：Z-BlogPHP **1.7.5.3540** / PHP **8.3.33**（测试站 zblog.xmm.fan 实测）/ MySQL
检查日期：2026-09-25

> **本版本定位**：1.7.0 已完成「把插件从自行实现附件上传 / 替换 / 删除底层业务，改造成
> 媒体库 UI + Z-BlogPHP 官方附件系统适配层」的架构重构；**1.7.1 是官方市场审核前的
> 一致性收尾**——不新增功能、不改动附件处理架构，只做：
> ① `replace` 残留清理；② `$_FILES` / `$_GET` 适配作用域收窄（`try / finally`）；
> ③ API 失败状态码统一；④ 架构 / 代码 / 文档 / 前端 / 发布物五方一致。
>
> 架构结论与官方调用链见 §3.1。

## 1. 元数据与标识

| 项 | 值 | 结果 |
|---|---|---|
| 插件 ID | `at8_media_library` | ✅ 由 `media_library` 改名后长期稳定（旧配置自动迁移） |
| 插件名称 | 媒体库 · 相册式附件管理 | ✅ |
| 版本号 | 1.7.1（`plugin.xml`、`function.php` 常量 `AT8_MEDIA_LIBRARY_VERSION`、README、CHANGELOG、本清单、ZBA 文件名、GitHub Release 七处一致） | ✅ |
| 前缀 | 函数 `at8_media_library_*`、PHP 常量 `AT8_MEDIA_LIBRARY_*`、CSS `.mlx-*` / `.ml-*`、JS `window.AT8ML`、配置键 `conf_Name=at8_media_library` | ✅ |
| `plugin.xml` 必填节点 | 含 `<description>`、`<phpver>`；author / source 完整 | ✅ |
| 作者与官网 | 漫步白月光 / https://www.at8.fun/ | ✅ |
| `<adapted>` | `172900` | ✅ 语义修正：官方口径为 `MAJOR.MINOR.COMMIT`（`c_system_version.php` 的 `$GLOBALS['blogversion']`，如 1.7.5.3540 → `173540`），故 `172900` = 1.7 线 commit 2900，等价于「最低 Z-BlogPHP 1.7.x（1.7.0 及以上）」。**历史发布清单中「= 1.7.2 编码」的写法为误记，本版已修正** |

## 2. 最低系统与 PHP 要求

| 项 | 值 | 依据 |
|---|---|---|
| 最低 Z-BlogPHP | **1.7.x** | 除原有依赖（`Filter_Plugin_Admin_LeftMenu/TopMenu/UploadMng_SubMenu`、`Filter_Plugin_Edit_Response3`、`$zbp->Config()` 属性式、`CheckCSRFTokenValid`）外，1.7.0 起新增依赖官方公共函数 `PostUpload()` / `DelUpload()`（定义于 `zb_system/function/c_system_event.php`，1.7.x 均提供；缺失时给出明确提示而非静默失败） |
| 最低 PHP | **7.4**（`plugin.xml` 显式声明） | ① 全量文件经 PHP **8.3.33** `php -l` 通过；② 无 PHP 8.0+ 专有语法（`?->` / `match` / `str_contains` / 构造器提升 / 联合类型 / `enum` / `readonly` 均 0 命中） |
| 数据库 | MySQL / SQLite / PostgreSQL | **不建自定义表**，仅使用系统 `zbp_upload` 与 `zbp_config` |
| 第三方依赖 | 无（前端原生 JS，无 Composer、无 CDN） | §23 |

## 3. 合规（无遗留项）

### 3.1 官方调用链（本版核心结论）

经 Z-BlogPHP 1.7.5.3540 源码实测确认，插件**存在**可安全复用的官方附件写接口——
`PostUpload()` / `DelUpload()` 是 `c_system_event.php` 中的**公共函数**，不是 `cmd.php`
私有入口，可直接在插件内调用：

```text
上传  api.php → at8_media_library_official_upload_one() → 官方 PostUpload()
        ├ $zbp->CheckRights('UploadPst')
        ├ 同月重名检查（$zbp->GetUploadList）
        ├ Upload::CheckExtName()  （ZC_UPLOAD_FILETYPE 白名单 + php/phtml/phar/.htaccess/web.config 硬拒绝）
        ├ Upload::CheckSize()     （ZC_UPLOAD_FILESIZE）
        ├ Upload::SaveFile() + Filter_Plugin_Upload_SaveFile
        ├ Upload::Save()、$zbp->AddCache()
        ├ CountMemberArray(..., +1)
        └ Filter_Plugin_PostUpload_Succeed

删除  api.php → at8_media_library_official_delete_upload() → 官方 DelUpload()
        ├ $zbp->CheckRights('UploadDel') / 'UploadAll'
        ├ $u->Del() + Filter_Plugin_Upload_Del
        ├ CountMemberArray(..., -1)
        └ $u->DelFile() + Filter_Plugin_Upload_DelFile

编辑  api.php → 官方 Upload::Save()（仅写自有展示字段与 ul_LogID 关联字段）
关联  api.php → 官方 Upload::Save()（写官方 ul_LogID 字段）

替换  —— 官方附件系统**不提供**任何可复用的附件替换能力（1.7.5.3540 全量核心源码
        `replace` 检索全部命中 str_replace() / JS .replace()，无附件替换函数 / 方法 / Hook）
        → 结论 UNCONFIRMED → **按规范降级：不提供该功能，也不自行实现替换引擎**
```

适配方式：官方 `PostUpload()` 以 `$_FILES` 为输入、`DelUpload()` 以 `$_GET['id']` 为输入，
插件采用「临时替换该输入 → 调用 → 立即还原」的方式复用官方能力，
**不伪造 HTTP 请求、不 `include cmd.php`、不模拟浏览器调用系统入口、不绕过任何校验**。
官方 `ShowError()` 实为 `throw new ZbpErrorException()`（非 `die`），故插件可 `try/catch`
后转成 JSON 响应，不产生死页面。

**【1.7.1 作用域收窄】** 临时替换与还原一律包在 `try / finally` 中：

| 适配点 | 保存 | 替换 | 调用 | 还原（`finally`） |
|---|---|---|---|---|
| `at8_media_library_official_upload_one()` | `$savedFiles = $_FILES` | `$_FILES = array('at8_media_library_upload' => $one)` | `PostUpload()` | `$_FILES = $savedFiles` |
| `at8_media_library_official_delete_upload()` | `$savedGet = $_GET` | `$_GET['id'] = (int) $id` | `DelUpload()` | `$_GET = $savedGet`（整体还原，含本就不存在的 `id` 键） |

正常返回、抛 `ZbpErrorException`、或任何 `Throwable` 都会在 `finally` 中还原，
不把临时结构残留给同请求中的其他插件。

### 3.2 合规检查项

- [x] **不重复实现附件底层**：上传类型校验、体积校验、文件保存、命名、存储路径、
      云存储 Hook、记录写入、用户附件计数、附件删除、核心写入 / 删除机制，全部交官方
- [x] **不拦截 / 替代系统预留接口**（官方客服明确要求）
- [x] **不新增附件数据表**，仅使用系统 `zbp_upload`
- [x] **不对附件文件执行 `unlink`**：全仓库 `unlink` 仅 **4 处**（`function.php` 3 处 +
      `include.php` 1 处），全部指向插件自身 `cache/` 目录，且均带 `is_file()` 守卫
- [x] **不自行计算附件磁盘路径用于写 / 删**：`disk_path()` 等仅用于列表只读展示，已加注释声明
- [x] **不提供可绕过官方上传白名单的入口**：已移除 `Filter_Plugin_at8_media_library_AllowExts`
- [x] **不伪造 HTTP 请求**：无 `curl_*` 自请求（0 处）
- [x] **无测试密钥、无硬编码 Token / 密码**
- [x] **无 Debug 残留**（`var_dump` / `print_r` 0 处；`error_log` 0 处）
- [x] **未修改 Z-BlogPHP 核心文件**（改动仅限 `zb_users/plugin/at8_media_library/`）
- [x] **无虚构 Hook**：4 个官方 Hook 均经源码确认且实际被调用；自有的 **5 个**扩展接口均在
      `include.php` 用官方 `DefinePluginFilter` 声明**且实际被调用**（§24：文档 / 代码 /
      声明三方一致，均为 5 个）
- [x] **无 `!important`**（全仓库 0 处）
- [x] **无加密 / 混淆 PHP；UTF-8 无 BOM**

## 4. 生命周期（测试站实测）

| 阶段 | 检查内容 | 结果 |
|---|---|---|
| 安装 | 幂等：`HasConfig` 判定；含 `media_library → at8_media_library` 旧配置迁移 | ✅ |
| 启用 | 注册 4 个官方后台 Hook + 声明 5 个对外接口 | ✅ |
| 正常运行 | 相册列表 / 统计 / 分类 / 文章搜索 / **上传（官方 PostUpload）** / 编辑 / 批量 / **删除（官方 DelUpload）** | ✅ s13 综合套件 **83 项 83 PASS / 0 FAIL** |
| 停用 | **不丢配置、不丢附件记录**（仅清理可再生缓存目录） | ✅ |
| 重新启用 | 主页与 API 立即正常 | ✅ |
| 升级 | `UpdatePlugin_at8_media_library()` 按 `ConfigVer` 逐级迁移，不重建数据 | ✅ 1.6.6 → 1.7.0 → 1.7.1 实测 **12 项 12 PASS / 0 FAIL**（§7） |
| 卸载 | **只清理可再生缓存目录**；配置、附件记录、实体文件、自定义信息**全部保留** | ✅ |

> **关键机制说明（1.7.5 实测）**：官方 `DisablePlugin()` 内部调用 `UninstallPlugin_<id>()`，
> 即「停用」也会进入卸载钩子；真卸载由 `AppCentre/app_del.php` 直接删目录、不触发该钩子。
> 因此卸载钩子内**不得**删除任何用户数据。

## 5. 安全

| 项 | 检查 | 结果 |
|---|---|---|
| 权限 | API 入口 `check_login()`（插件启用 + 后台登录 + `UploadMng` 查看权限）；写操作按官方权限项细分：上传 / 编辑 / 关联 = `UploadPst`，删除 = `UploadDel`；`UploadAll` 仅用于操作他人附件 | ✅ 四维度全实测（站点 actions：`admin=5` 登录权 / `UploadMng=3` / `UploadPst=3` / `UploadDel=3` / `UploadAll=2`）：未登录 401、无 `UploadMng`（Level 5）403、Level 3 正常、管理员正常 |
| 数据范围 | `scope_where()` 服务端强制注入 `ul_AuthorID = 当前用户`（无 `UploadAll`），不信任前端参数 | ✅ Level 3 用户列表仅见自己附件（authors=[24]） |
| 所有权 | `check_owner()` 逐条校验（单个操作报错 / 批量操作跳过计数） | ✅ 跨用户用例实测 403 / skipped |
| 关联越权 | `validate_logid()` 仅允许关联「自己的文章」或拥有 `UploadAll`，防枚举他人草稿 / 私密文章标题 | ✅ 实测 403（单个 update 与批量 bind 两路均拦截） |
| CSRF | 写操作 `CheckCSRFTokenValid('csrfToken', array('post'))`（+ 增强模式下 `CheckHTTPRefererValid`），失败拒绝 | ✅ 篡改 / 缺失 / 空 token 均 403；Referer 指向外部域名 403（注：官方 `CheckHTTPRefererValid()` 对**空 Referer 直接放行**，与插件行为一致） |
| 请求方法 | `upload/update/bulk/delete` 强制 POST，非 POST 返回 405 | ✅ 4 个写动作 GET 实测均 405 |
| 动作白名单 | `act` 不读 `$_REQUEST`（含 COOKIE），按只读 / 写两张白名单分发，未列出返回 400，且不回显原始输入 | ✅ `act=evil<script>` 被拒且不反射；`act=replace` 返回 400（功能已移除） |
| HTTP 状态码 | `error()` 的 `code` 落在 400~599 时同步设置 HTTP 状态码（`headers_sent()` 守卫）；**1.7.1 起默认 `$code = 400`**，参数校验失败不再返回 `200 OK` | ✅ 401 / 403 / 404 / 405 / 400 与响应体 `code` 一致；前端用 `XMLHttpRequest` 读 `responseText` 且只判断 `code !== 0`，故状态码变更**前端零改动** |
| 上传安全 | **全部交官方**：类型由 `Upload::CheckExtName()`（`ZC_UPLOAD_FILETYPE`）裁决、体积由 `Upload::CheckSize()`（`ZC_UPLOAD_FILESIZE`）裁决、同月重名由官方拒绝（错误码 28）、落盘命名与路径由官方规则决定 | ✅ `.php` / `.xyz` 实测被官方拒（错误码 26）且未落盘；3MB 超限被拒（错误码 27）；同月同名被拒（错误码 28） |
| 文件系统 | 插件不再对附件做 `unlink` / 路径计算 / `chmod`；仅对自身 `cache/` 目录做原子写入（临时文件 + `rename`）与清理，带 `.htaccess` / `index.html` 防直接访问 | ✅ |
| 第三方存储 | 上传经官方 `Filter_Plugin_Upload_SaveFile`、删除经官方 `Filter_Plugin_Upload_DelFile`；插件不假设「附件一定在本地磁盘」，不自行删除本地 / 云端文件 | ✅ 探针实测两个 Hook 均被触发（s12）；**真实第三方存储环境未搭建，标记「未实测」**（§35） |
| XSS | JSON 输出不做 HTML 转义但前端统一 `esc()` 转义（含属性上下文）；未知动作不回显输入 | ✅ |
| SQL 注入 | 全部经官方 `$zbp->db->sql->Select/Count` 构造 + 强类型转换；排序字段 / 方向走白名单映射；`IN` 子句用整型数组 | ✅ 伪造 `UploadID`（`-1` / `abc` / `0` / `99999999`）均被拒 |
| 外部 HTTP / SSRF | 无用户可控的外部请求 | ✅ `curl_*` 0 处 |
| 日志 | 仅记录附件 ID / 文件名 / 操作类型，无敏感信息 | ✅ |

### 5.1 敏感 API 静态扫描（全仓库，§27 / §28 / §29 / §30）

扫描口径：按 ZBA 实际发布文件集（10 个文件）扫描，已剥离 PHP / JS 的 `//` `#` `/* */`
注释；Markdown 文档中的提及单独归入「文档提及」，不计入可执行代码。

| 目标 | 分类 | 代码命中 | 说明 |
|---|---|---|---|
| `move_uploaded_file(` / `is_uploaded_file(` / `copy(` / `finfo_file(` / `finfo_open(` / `mime_content_type(` | C | **0** | 旧自建上传安全体系，已全部删除 |
| `cmd.php` / `AllowExts` / `UploadSucceed` / `DeleteSucceed` / `replace`（动作）/ `->Del(` / `curl_` | C | **0** | 旧自实现附件底层 / 替换功能 / 伪造 HTTP，已全部删除 |
| `unlink(` | B | 3（`function.php`） | 全部指向插件自身 `cache/`：缓存过期清理、缓存原子写临时文件、`cache_del`；另有 `include.php` 停用钩子清理 `cache/`（共 4 处），**均不涉及附件文件**，且均带 `is_file()` 守卫 |
| `rename(` | B | 1（`function.php`） | 缓存原子写入（tmp → file） |
| `file_put_contents(` | B | 3（`function.php`） | `cache/` 的 `.htaccess` / `index.html` 防护文件 + 缓存写入 |
| `getimagesize(` | B | 1（`function.php`） | 列表行**只读**展示图片尺寸，带 `local_missing == 0` 守卫，**不是上传校验** |
| `PostUpload` | A | 2（`function.php`） | `function_exists('PostUpload')` 守卫 + `$u = PostUpload()` 实际调用 |
| `DelUpload` | A | 3（`api.php` 1 / `function.php` 2） | `function_exists()` 守卫 + `DelUpload()` 实际调用 |
| `GetUploadByID` | A | 3（`api.php`） | 官方只读查询（quotecheck / update / delete 取对象） |
| `->Save()` | A | 3（`api.php`） | 官方 `Upload::Save()`（上传后写 LogID、编辑信息、批量关联） |
| `SaveFile` / `DelFile` / `CountMemberArray` / `GetUploadList` | A | **0（代码）** | 仅存在于说明性注释 / 文档中，插件不直接调用官方底层方法 |

**C 类（旧自实现附件底层）代码命中总数 = 0 ✅**

## 6. 性能

- [x] 列表一次 `IN` 查询取回批量对象 + 批量补齐文章与分类映射（消除 N+1）
- [x] 统计多级缓存（Redis → APCu → 文件）、按数据范围分键、写操作后 flush
- [x] 缓存 Key 补站点环境前缀：同一服务器多站点共用 Redis / APCu 时不再互相命中
- [x] 大表（>5 万）走聚合查询，>3 万跳过分布统计
- [x] 每页上限 200，批量操作上限 500，单次上传上限 30
- [x] 文件缓存原子写入（临时文件 + `rename`），带 `.htaccess` / `index.html` 防直接访问

## 7. 测试与验收记录

- **本地静态检查**：`php -l` 全通过（4 个 PHP，PHP 8.3.33 校验），`node --check` 通过
  （app.js / edit.js），`plugin.xml` 可解析，`!important` = 0，无 BOM
- **s13 综合测试套件 83 项 83 PASS / 0 FAIL**（测试站实测，覆盖 §33 矩阵 / §34 越权 / §35 存储）：
  - **S0 身份矩阵（4）**：未登录 → 401；无 `UploadMng`（Level 5）→ 403；Level 3 → 200；管理员 → 200
  - **S1 只读动作（8）**：`list` / `stats` / `categories` / `posts` / `quotecheck` 200；
    不存在附件 404；未知 `act` 400 且不反射；`act=replace` 400
  - **S2 写操作强制 POST（4）**：`upload` / `update` / `bulk` / `delete` 用 GET → 405
  - **S3 CSRF（5）**：缺 token / 伪造 token（update、delete）/ 空 token / Referer 外部域名 → 均 403
  - **S4 上传矩阵（26）**：jpg / png / gif / webp / svg / txt 成功且**保持原名**、返回真实 URL；
    `.php` / `.xyz` 被官方拒（错误码 26）；3MB 超限被拒（错误码 27）；中文文件名保持原名；
    同月同名被拒（错误码 28）；多文件一次 3 条；伪造 `authorid` 被忽略；关联不存在文章 400；无文件 400
  - **S5 删除 + 计数（7）**：官方 `DelUpload` 200、`CountMemberArray` ±1、磁盘文件同步删除；
    不存在 / 负数 / 非数字 ID → 404
  - **S6 编辑 / 关联（7）**：标题 / Alt / 说明写入；**核心字段（Name/MimeType/Size/AuthorID）未被改写**；
    关联置空 200；关联不存在文章 400；不存在附件 404
  - **S7 越权与伪造（13）**：普通用户读取 / 修改 / 删除他人附件 → 403；批量删他人附件 → skipped；
    关联他人文章 → 403（单个 + 批量）；伪造 `UploadID` 4 种 → 404；伪造 `LogID=-5` → 规范化
  - **S8 批量（5）**：未选择 400；超 500 上限 400；未知 op 400；关联不存在文章 400；批量删除 done 正确
  - **收尾（3）**：测试上传的附件全部清理；附件总数与管理员附件计数回到基线
- **升级链路测试 12 项 12 PASS / 0 FAIL**（§36，严格贴合官方 `misc_updatedapp` 调用序列）：
  - 用 1.6.6 的 **.zba 实包**覆盖安装 → 造出旧配置（`perpage=36` + 自定义标记键，移除 `ConfigVer`）
  - 覆盖为 1.7.0 的 .zba → 调用官方 `UpdatePlugin_at8_media_library()` → 版本号正确、旧配置保留、`ConfigVer` 迁移为 1
  - 覆盖为 1.7.1 → 再次调用官方更新钩子 → 旧配置保留、附件记录与磁盘文件**逐字节一致**（MD5 比对）
  - 升级后 `list` 接口正常，`total` 与基线一致
- **s12 探针验证**：官方 `Filter_Plugin_Upload_SaveFile` / `Filter_Plugin_Upload_DelFile` **确实被触发**
  → 第三方云存储 / 对象存储插件可正常接管（探针插件测试后已彻底删除，未改动任何核心文件）
- **ZBA 实包解包核验（§37）**：包内 **12 个文件**与插件目录最终版**逐文件 MD5 一致**、无多余项、
  无 `.git` / `.backups` / `screenshots` / `cache` / `CHANGELOG.md` / `RELEASE_CHECKLIST.md` / `zbignore.txt`；
  元数据 `version=1.7.1` / `adapted=172900` / `phpver=7.4`
- **测试站最终状态**：附件记录 2 条（原始），无测试残留，无探针插件残留，插件配置已还原

## 8. 发布物

- `at8_media_library_1.7.1_20260925.zba`（12 文件，91635 bytes）
- GitHub：https://github.com/361611074/at8_media_library
