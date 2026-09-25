# at8_media_library 1.7.0 — 发布检查清单（RELEASE_CHECKLIST）

检查基准：《Z-BlogPHP 插件 AI Agent 开发规范》§29
实测环境：Z-BlogPHP **1.7.5.3540** / PHP **8.3.33**（测试站 zblog.xmm.fan 实测）/ MySQL
检查日期：2026-09-25

> 本版本为**市场合规架构重构**：把插件从「自行实现附件上传 / 替换 / 删除底层业务」
> 改造成「媒体库 UI + Z-BlogPHP 官方附件系统适配层」。架构结论与调用链见 §3.1。

## 1. 元数据与标识

| 项 | 值 | 结果 |
|---|---|---|
| 插件 ID | `at8_media_library` | ✅ 由 `media_library` 改名后长期稳定（旧配置自动迁移） |
| 插件名称 | 媒体库 · 相册式附件管理 | ✅ |
| 版本号 | 1.7.0（`plugin.xml` 与 `AT8_MEDIA_LIBRARY_VERSION` 一致） | ✅ 十进制封十进一 |
| 前缀 | 函数 `at8_media_library_*`、PHP 常量 `AT8_MEDIA_LIBRARY_*`、CSS `.mlx-*` / `.ml-*`、JS `window.AT8ML`、配置键 `conf_Name=at8_media_library` | ✅ |
| `plugin.xml` 必填节点 | 含 `<description>`、`<phpver>`；author/source 完整 | ✅ |
| 作者与官网 | 漫步白月光 / https://www.at8.fun/ | ✅ |
| `<adapted>` | `172900`（= 1.7.2 编码，最低 Z-BlogPHP 门槛，本版未变） | ✅ |

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
        ├ Upload::CheckExtName()  （ZC_UPLOAD_FILETYPE 白名单）
        ├ Upload::CheckSize()     （ZC_UPLOAD_FILESIZE）
        ├ Upload::SaveFile() + Filter_Plugin_Upload_SaveFile
        ├ Upload::Save()、$zbp->AddCache()
        ├ CountMemberArray(..., +1)
        └ Filter_Plugin_PostUpload_Succeed

删除  api.php → at8_media_library_official_delete_upload() → 官方 DelUpload()
        ├ $zbp->CheckRights('UploadDel') / 'UploadAll'
        ├ Upload::Del() + Filter_Plugin_Upload_Del
        ├ CountMemberArray(..., -1)
        └ Upload::DelFile() + Filter_Plugin_Upload_DelFile

编辑  api.php → 官方 Upload::Save()（仅写自有展示字段与 ul_LogID 关联字段）
关联  api.php → 官方 Upload::Save()（写官方 ul_LogID 字段）
```

适配方式：官方 `PostUpload()` 以 `$_FILES` 为输入、`DelUpload()` 以 `$_GET['id']` 为输入，
插件采用「临时替换该输入 → 调用 → 立即还原」的方式复用官方能力，
**不伪造 HTTP 请求、不 `include cmd.php`、不模拟浏览器调用系统入口、不绕过任何校验**。
官方 `ShowError()` 实为 `throw new ZbpErrorException()`（非 `die`），故插件可 `try/catch`
后转成 JSON 响应，不产生死页面。

### 3.2 合规检查项

- [x] **不重复实现附件底层**：上传类型校验、体积校验、文件保存、命名、存储路径、
      云存储 Hook、记录写入、用户附件计数、附件删除、核心写入 / 删除机制，全部交官方
- [x] **不拦截 / 替代系统预留接口**（官方客服明确要求）
- [x] **不新增附件数据表**，仅使用系统 `zbp_upload`
- [x] **不对附件文件执行 `unlink`**：全仓库 `unlink` 仅 4 处，全部指向插件自身 `cache/` 目录
- [x] **不自行计算附件磁盘路径用于写 / 删**：`disk_path()` 等仅用于列表只读展示，已加注释声明
- [x] **不提供可绕过官方上传白名单的入口**：已移除 `Filter_Plugin_at8_media_library_AllowExts`
- [x] **不伪造 HTTP 请求**：无 `curl_*` / `file_get_contents` 自请求（各 0 处）
- [x] **无测试密钥、无硬编码 Token / 密码**（`redis_auth` 为后台可配项，不写死）
- [x] **无 Debug 残留**（`var_dump` / `print_r` 0 处；`error_log` 0 处）
- [x] **未修改 Z-BlogPHP 核心文件**（改动仅限 `zb_users/plugin/at8_media_library/`）
- [x] **无虚构 Hook**：4 个官方 Hook 均经源码确认且实际被调用；自有的 5 个扩展接口均在
      `include.php` 用官方 `DefinePluginFilter` 声明**且实际被调用**
- [x] **无 `!important`**（全仓库 0 处）
- [x] **无加密 / 混淆 PHP；UTF-8 无 BOM**

## 4. 生命周期（测试站实测）

| 阶段 | 检查内容 | 结果 |
|---|---|---|
| 安装 | 幂等：`HasConfig` 判定；含 `media_library → at8_media_library` 旧配置迁移 | ✅ |
| 启用 | 注册 4 个官方后台 Hook + 声明 5 个对外接口 | ✅ |
| 正常运行 | 相册列表 / 统计 / 分类 / 文章搜索 / **上传（官方 PostUpload）** / 编辑 / 批量 / **删除（官方 DelUpload）** | ✅ 81 项断言 81 PASS / 0 FAIL |
| 停用 | **不丢配置、不丢附件记录**（仅清理可再生缓存目录） | ✅ |
| 重新启用 | 主页与 API 立即正常 | ✅ |
| 升级 | `UpdatePlugin_at8_media_library()` 按 `ConfigVer` 逐级迁移，不重建数据 | ✅ |
| 卸载 | **只清理可再生缓存目录**；配置、附件记录、实体文件、自定义信息**全部保留** | ✅ |

> **关键机制说明（1.7.5 实测）**：官方 `DisablePlugin()` 内部调用 `UninstallPlugin_<id>()`，
> 即「停用」也会进入卸载钩子；真卸载由 `AppCentre/app_del.php` 直接删目录、不触发该钩子。
> 因此卸载钩子内**不得**删除任何用户数据。

## 5. 安全

| 项 | 检查 | 结果 |
|---|---|---|
| 权限 | API 入口 `check_login()`（插件启用 + 后台登录 + `UploadMng` 查看权限）；写操作按官方权限项细分：上传 / 编辑 / 关联 = `UploadPst`，删除 = `UploadDel`；`UploadAll` 仅用于操作他人附件 | ✅ 四维度全实测：无 `UploadMng` / 无 `UploadPst` / 无 `UploadDel` / 无 `UploadAll` 均正确拦截 |
| 数据范围 | `scope_where()` 服务端强制注入 `ul_AuthorID = 当前用户`（无 `UploadAll`），不信任前端参数 | ✅ |
| 所有权 | `check_owner()` 逐条校验（单个操作报错 / 批量操作跳过计数） | ✅ 跨用户用例实测 403 / skipped |
| 关联越权 | `validate_logid()` 仅允许关联「自己的文章」或拥有 `UploadAll`，防枚举他人草稿 / 私密文章标题 | ✅ 实测 403 |
| CSRF | 写操作 `CheckCSRFTokenValid('csrfToken', array('post'))`（+ 增强模式下 `CheckHTTPRefererValid`），失败拒绝 | ✅ 篡改 / 缺失 token 均 403 |
| 请求方法 | `upload/update/bulk/delete` 强制 POST，非 POST 返回 405 | ✅ GET 写操作实测被拒 |
| 动作白名单 | `act` 不读 `$_REQUEST`（含 COOKIE），按只读 / 写两张白名单分发，未列出返回 400，且不回显原始输入 | ✅ `act=evil<script>` 被拒且不反射；`act=replace` 返回 400（功能已移除） |
| HTTP 状态码 | `error()` 的 `code` 落在 400~599 时同步设置 HTTP 状态码（`headers_sent()` 守卫）；**本版统一「附件不存在」为 404**（原先 quotecheck / update 分支漏传） | ✅ 405 / 403 / 404 / 400 与响应体 `code` 一致 |
| 上传安全 | **全部交官方**：类型由 `Upload::CheckExtName()`（`ZC_UPLOAD_FILETYPE`）裁决、体积由 `Upload::CheckSize()`（`ZC_UPLOAD_FILESIZE`）裁决、同月重名由官方拒绝（错误码 28）、落盘命名与路径由官方规则决定 | ✅ `.php` / `.xyz` 实测被官方拒（错误码 26）且未落盘；同月同名被拒（错误码 28） |
| 文件系统 | 插件不再对附件做 `unlink` / 路径计算 / `chmod`；仅对自身 `cache/` 目录做原子写入（临时文件 + `rename`）与清理，带 `.htaccess` / `index.html` 防直接访问 | ✅ |
| 第三方存储 | 上传经官方 `Filter_Plugin_Upload_SaveFile`、删除经官方 `Filter_Plugin_Upload_DelFile`；插件不假设「附件一定在本地磁盘」，不自行删除本地 / 云端文件 | ✅ 探针实测两个 Hook 均被触发（s12） |
| XSS | JSON 输出不做 HTML 转义但前端统一 `esc()` 转义（含属性上下文）；未知动作不回显输入 | ✅ |
| SQL 注入 | 全部经官方 `$zbp->db->sql->Select/Count` 构造 + 强类型转换；排序字段 / 方向走白名单映射；`IN` 子句用整型数组 | ✅ |
| 外部 HTTP / SSRF | 无用户可控的外部请求；Redis 连接为管理员配置项 | ✅ |
| 日志 | 仅记录附件 ID / 文件名 / 操作类型，无敏感信息 | ✅ |

### 5.1 敏感 API 静态扫描（全仓库，§44）

| 目标 | 命中 | 分类 |
|---|---|---|
| `unlink(` | 4 | **媒体库自身逻辑**：function.php 缓存过期清理 / 缓存原子写临时文件 / `cache_del`，include.php 停用钩子清理 `cache/`。**均不涉及附件文件** |
| `rename(` | 1 | 媒体库自身逻辑：缓存原子写入（tmp → file） |
| `file_put_contents(` | 3 | 媒体库自身逻辑：`cache/` 的 `.htaccess` / `index.html` 防护文件 + 缓存写入 |
| `getimagesize(` | 1 | 媒体库自身逻辑：列表行**只读**展示图片尺寸，已加 `local_missing == 0` 守卫，**不是上传校验** |
| `move_uploaded_file(` / `is_uploaded_file(` / `copy(` / `finfo_file(` / `finfo_open(` | 0 | **已删除**（原属自建上传安全体系） |
| `SaveFile` / `DelFile` / `CountMemberArray` / `GetUploadList` | 0（实际调用） | **已删除**：仅存在于 function.php 的**说明性注释**中，插件不再直接调用官方底层方法 |
| `PostUpload` / `DelUpload` | 各 1 处实际调用 | **官方流程调用**：function.php `official_upload_one()` / `official_delete_upload()`，其余为注释 |
| `GetUploadByID` | 3 | 官方只读查询（api.php quotecheck / update / delete 取对象） |
| `->Save()` | 3 | **官方流程调用**：官方 `Upload::Save()`（上传后写 LogID、编辑信息、批量关联） |

## 6. 性能

- [x] 列表一次 `IN` 查询取回批量对象 + 批量补齐文章与分类映射（消除 N+1）
- [x] 统计多级缓存（Redis → APCu → 文件）、按数据范围分键、写操作后 flush
- [x] **缓存 Key 补站点环境前缀**：Redis / APCu 按服务器进程共享，同一服务器多站点原先会
      互相命中 `at8ml:stats_all` 等键，现统一加站点根目录指纹
- [x] 大表（>5 万）走聚合查询，>3 万跳过分布统计
- [x] 每页上限 200，批量操作上限 500，单次上传上限 30
- [x] 文件缓存原子写入（临时文件 + `rename`），带 `.htaccess` / `index.html` 防直接访问

## 7. 测试与验收记录

- 本地：`php -l` 全通过（4 个 PHP，PHP 8.3.33 校验），`node --check` 通过（app.js / edit.js），
  `plugin.xml` 可解析，`!important` = 0，无 BOM
- 测试站 **s8 部署回归 81 项 81 PASS / 0 FAIL**，覆盖
  ① 包内清单（7 项）② 官方 AppCentre `app_upload.php` 真实安装 + 逐文件 MD5（8 项）
  ③ 只读接口（5 项）④ 官方 `PostUpload()` 上传链路（16 项，含中文名 / 同月同名 / `.php` / `.xyz`）
  ⑤ 编辑与关联（3 项）⑥ 官方 `DelUpload()` 删除链路（7 项，含计数 ±1）
  ⑦ 安全测试（12 项：越权 / CSRF / 方法 / 白名单）⑧ 跨用户越权（17 项）
  ⑨ debug 模式全站扫错 + 错误日志（7 项，0 新增）
- 测试站 **s11 补充安全测试 42 PASS**（44 项中 2 项因测试手段失效，已由 s12 覆盖），覆盖
  伪造 Upload ID / 伪造 LogID / 伪造文件名与路径穿越 / 伪造 MIME / 伪造扩展名 /
  超大文件 / 多文件 / 跨用户上传（`authorid` 伪造）
- 测试站 **s12 探针验证 28 项 28 PASS / 0 FAIL**：
  ① 官方 `Filter_Plugin_Upload_SaveFile` / `Filter_Plugin_Upload_DelFile` **确实被触发**
     → 证明第三方云存储 / 对象存储插件可正常接管，插件不假设附件在本地磁盘
  ② 无 `UploadPst` → 403；无 `UploadDel` → 403；无 `UploadMng`（Level 4）→ 403
     （探针插件测试后已彻底删除，站点配置已还原，未改动任何核心文件）
- 分发包：包内 **12 个文件**与插件目录最终版**逐文件 MD5 一致**、无多余项、
  无 `.git` / `.backups` / `screenshots` / `cache` / `CHANGELOG.md` / `RELEASE_CHECKLIST.md` / `zbignore.txt`
- 测试站实装：经官方「应用中心 → 上传应用」链路安装本包通过，落地目录无 `.git`，
  版本常量与 `plugin.xml` 一致
- 站点最终状态：附件记录 3 条（原始），无测试残留，无探针插件残留

## 8. 发布物

- `at8_media_library_1.7.0_20260925.zba`（12 文件，89278 bytes）
- GitHub：https://github.com/361611074/at8_media_library
