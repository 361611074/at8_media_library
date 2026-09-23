# at8_media_library 1.6.3 — 发布检查清单（RELEASE_CHECKLIST）

检查基准：《Z-BlogPHP 插件 AI Agent 开发规范》§29
实测环境：Z-BlogPHP **1.7.5** / PHP 7.3.4（本地 `php -l`）+ PHP 8.2（测试站 zblog.xmm.fan）/ MySQL
检查日期：2026-09-23

## 1. 元数据与标识

| 项 | 值 | 结果 |
|---|---|---|
| 插件 ID | `at8_media_library` | ✅ 由 `media_library` 改名后长期稳定（旧配置自动迁移） |
| 插件名称 | 媒体库 · 相册式附件管理 | ✅ |
| 版本号 | 1.6.3（`plugin.xml` 与 `AT8_MEDIA_LIBRARY_VERSION` 一致） | ✅ 十进制封十进一 |
| 前缀 | 函数 `at8_media_library_*`、PHP 常量 `AT8_MEDIA_LIBRARY_*`、CSS `.mlx-*` / `.ml-*`、JS `window.AT8ML`、配置键 `conf_Name=at8_media_library` | ✅ 本次将 `window.ML` 改名 `window.AT8ML` 以避免命名冲突 |
| `plugin.xml` 必填节点 | 含 `<description>`、`<phpver>`；author/source 完整 | ✅ |
| 作者与官网 | 漫步白月光 / https://www.at8.fun/ | ✅ |

## 2. 最低系统与 PHP 要求

| 项 | 值 | 依据 |
|---|---|---|
| 最低 Z-BlogPHP | 1.7.x | 依赖 `Filter_Plugin_Admin_LeftMenu/TopMenu/UploadMng_SubMenu`、`Filter_Plugin_Edit_Response3`、`$zbp->Config()` 属性式、`CheckCSRFTokenValid` |
| 最低 PHP | **7.0**（`plugin.xml` 显式声明；打包脚本读取，不再写死 5.2） | 缓存层使用 `Throwable`（PHP 7.0+）；实测 7.3.4 与 8.2 通过 |
| 数据库 | MySQL / SQLite / PostgreSQL（另经 PDO 兼容 Redis 可选缓存） | 不建自定义表，仅使用系统 `zbp_upload` 与 `zbp_config` |
| 第三方依赖 | 无（前端原生 JS，无 Composer、无 CDN） | §23 |

## 3. 合规（无遗留项）

- [x] 无测试密钥、无硬编码 Token / 密码（`redis_auth` 为后台可配项，不写死）
- [x] 无 Debug 残留（`var_dump` / `print_r` 0 处；`error_log` 0 处）
- [x] 无 localhost 硬编码（仅 Redis 默认回环地址，属缓存连接默认值，可由后台覆盖）
- [x] 未修改 Z-BlogPHP 核心文件
- [x] 无虚构 Hook：4 个官方 Hook 均经源码确认（含触发处 `edit.php:341 HookFilterPlugin('Filter_Plugin_Edit_Response3')`，回调为无参、返回值忽略，与实现一致）；自有的 8 个扩展接口均在 `include.php` 用官方 `DefinePluginFilter` 声明**且实际被调用**
- [x] 无 `!important`
- [x] 无加密 / 混淆 PHP；UTF-8 无 BOM

## 4. 生命周期（测试站实测）

| 阶段 | 检查内容 | 结果 |
|---|---|---|
| 安装 | 幂等：`HasConfig` 判定；含 `media_library → at8_media_library` 旧配置迁移 | ✅ |
| 启用 | 注册 4 个官方后台 Hook + 声明 8 个对外接口 | ✅ |
| 正常运行 | 相册列表 / 统计 / 分类 / 文章搜索 / 上传 / 替换 / 编辑 / 批量 / 删除 | ✅ 40 项断言全过（含真实 PNG 中文名上传 + 删除） |
| 停用 | **不丢配置、不丢附件记录**（仅清理可再生缓存目录） | ✅ 实测配置 2 行不变、附件 3 条不变 |
| 重新启用 | 主页与 API 立即正常 | ✅ |
| 升级 | `UpdatePlugin_at8_media_library()` 按 `ConfigVer` 逐级迁移，不重建数据 | ✅ |
| 卸载 | **只清理可再生缓存目录**；配置、附件记录、实体文件、自定义信息**全部保留**（因 1.7.5「停用」也会进该钩子，钩子内不得删用户数据） | ✅ |

> **关键机制说明（1.7.5 实测）**：官方 `DisablePlugin()` 内部调用 `UninstallPlugin_<id>()`，即「停用」也会进入卸载钩子；真卸载由 `AppCentre/app_del.php` 直接删目录、不触发该钩子。因此卸载钩子内**不得**删除任何用户数据。原实现（只清缓存）本就符合该约束，本次在该位置补上说明注释并加固，避免后续误改成「停用即清配置」。

## 5. 安全

| 项 | 检查 | 结果 |
|---|---|---|
| 权限 | API 入口 `check_login()`（插件启用 + 后台登录 + `UploadMng` 查看权限）；写操作按官方权限项细分：上传/替换/编辑/关联 = `UploadPst`，删除 = `UploadDel`；`UploadAll` 仅用于操作他人附件 | ✅ |
| 数据范围 | `scope_where()` 服务端强制注入 `ul_AuthorID = 当前用户`（无 `UploadAll`），不信任前端参数 | ✅ |
| 所有权 | `check_owner()` 逐条校验（单个操作报错 / 批量操作跳过计数） | ✅ |
| 关联越权 | `validate_logid()` 仅允许关联「自己的文章」或拥有 `UploadAll`，防枚举他人草稿标题 | ✅ |
| CSRF | 写操作 `CheckCSRFTokenValid('csrfToken', array('post'))`（+ 增强模式下 `CheckHTTPRefererValid`），失败拒绝；**新增：非 POST 一律拒绝（纵深防御）** | ✅ 篡改 token 被拒 403 |
| 请求方法 | **新增**：`upload/replace/update/bulk/delete` 强制 POST，非 POST 返回 405 | ✅ GET 写操作实测被拒 |
| 动作白名单 | **新增**：`act` 不再读 `$_REQUEST`（含 COOKIE），按只读/写两张白名单分发，未列出返回 400，且不回显原始输入 | ✅ `act=evil<script>` 被拒且不反射 |
| HTTP 状态码 | **新增（1.6.3）**：`error()` 的 `code` 落在 400~599 时同步设置 HTTP 状态码（`headers_sent()` 守卫），失败请求不再一律 `200 OK` | ✅ 实测 405 / 400 与响应体 `code` 一致 |
| XSS | JSON 输出不做 HTML 转义但前端统一 `esc()` 转义（含属性上下文）；未知动作不再回显输入 | ✅ 前端 10 处 `AT8ML` 引用无 XSS 面 |
| SQL 注入 | 全部经官方 `$zbp->db->sql->Select/Count` 构造 + 强类型转换；排序字段/方向走白名单映射；`IN` 子句用整型数组 | ✅ |
| 上传安全 | 扩展名白名单（跟随站点设置）+ 硬拒可执行/脚本扩展名（含 `ini/env`）+ 硬排除可内嵌脚本格式（svg/xml/swf 等）+ 防双扩展名 + 图片内容 `getimagesize`/`finfo` 双重校验 + 体积跟随 php.ini + 单次 30 个上限 | ✅ |
| 文件系统 | 落盘名白名单化（中文/字母/数字/`. _ -`）；读写删全部经 `realpath` 前缀校验限定在 `zb_users/upload/` 内；拒绝 `..` 与 NUL | ✅ |
| 外部 HTTP / SSRF | 无用户可控的外部请求；Redis 连接为管理员配置项 | ✅ |
| 日志 | 仅记录附件 ID / 文件名 / 操作类型，无敏感信息 | ✅ |

## 6. 性能

- [x] 列表一次 `IN` 查询取回批量对象 + 批量补齐文章与分类映射（消除 N+1）
- [x] 统计多级缓存（Redis → APCu → 文件）、按数据范围分键、写操作后 flush
- [x] 大表（>5 万）走聚合查询，>3 万跳过分布统计
- [x] 每页上限 200，批量操作上限 500
- [x] 文件缓存原子写入（临时文件 + rename），带 `.htaccess` / `index.html` 防直接访问

## 7. 测试与验收记录

- 本地：`php -l` 全通过（6 个 PHP），`node --check` 通过（app.js / edit.js），`plugin.xml` 可解析，`!important` = 0
- 测试站：**40 项断言 40 PASS / 0 FAIL**，含 debug 模式 8 个页面零报错
- 分发包：包内 11 个文件与插件目录最终版**逐文件 MD5 一致**、无多余项、无 `.git` / `.backups` / `.log` 残留
- 测试站实装：经官方「应用中心 → 上传应用」链路安装本包通过，落地目录无 `.git`，版本常量与 `plugin.xml` 一致
- 已知环境问题（与本插件无关）：测试站文章编辑页返回 500，错误为 ZB 侧 `Value of type null is not callable`；**停用本插件后同样复现**，属测试站预存环境问题

## 8. 发布物

- `at8_media_library_1.6.3_20260923.zba`（70.3 KB，11 文件；已剔除 screenshots / README / CHANGELOG / RELEASE_CHECKLIST / cache / `.git`）
- GitHub：https://github.com/361611074/at8_media_library
