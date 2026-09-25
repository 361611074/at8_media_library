# 更新日志 · at8_media_library

版本号规则：十进制封十进一（每段 0~9，满 10 进位），不用 1.2.10 这类写法。

## 1.7.0（2026-09-25）

**市场合规架构重构**：把本插件从「自行实现附件上传 / 替换 / 删除底层业务」改造成
「媒体库 UI + Z-BlogPHP 官方附件系统适配层」。

### 架构结论

重构后，附件的上传、保存、删除全部由 Z-BlogPHP 官方附件系统执行，插件只负责
查询 / 筛选 / 搜索 / 预览 / 统计 / UI。官方调用链（Z-BlogPHP 1.7.5.3540 源码实测确认）：

```text
上传：媒体库 api.php
      → at8_media_library_official_upload_one()
      → 官方 PostUpload()                        [c_system_event.php]
          → $zbp->CheckRights('UploadPst')
          → Upload::CheckExtName()               [lib/base/upload.php]
          → Upload::CheckSize()                  [lib/base/upload.php]
          → Upload::SaveFile()  + Filter_Plugin_Upload_SaveFile
          → Upload::Save()
          → $zbp->AddCache()
          → CountMemberArray(..., +1)
          → Filter_Plugin_PostUpload_Succeed

删除：媒体库 api.php
      → at8_media_library_official_delete_upload()
      → 官方 DelUpload()                         [c_system_event.php]
          → $zbp->CheckRights('UploadDel') / 'UploadAll'
          → Upload::Del()       + Filter_Plugin_Upload_Del
          → CountMemberArray(..., -1)
          → Upload::DelFile()   + Filter_Plugin_Upload_DelFile

编辑：媒体库 api.php → 官方 Upload::Save()（只写自有展示字段与 LogID 关联）

关联：媒体库 api.php → 官方 Upload::Save()（写官方 ul_LogID 字段）
```

### 移除的自实现附件底层逻辑

- **上传安全体系**：删除插件自建的扩展名白名单（`at8_media_library_allow_exts()`，含
  `deny` / `risky` / `builtin` 三张表）、删除防双扩展名自检、删除图片内容
  `getimagesize()` + `finfo` 双重校验、删除 `is_uploaded_file()` 自检、删除自读
  `php.ini` 的 `upload_max_filesize` / `post_max_size` 体积判定
  （`at8_media_library_max_upload_size()`）。以上改由官方
  `Upload::CheckExtName()` / `CheckSize()` 裁决。
- **文件命名核心规则**：删除 `at8_media_library_safe_filename()`（自建字符白名单重写文件名）
  与同月重名自动改名逻辑，改由官方「同月重名即拒绝」规则处理。
- **MIME 探测**：删除 `at8_media_library_detect_mime()`，MIME 直接采用官方
  `PostUpload()` 写入的 `$_FILES['type']`。
- **文件保存体系**：删除 `at8_media_library_save_one()` 中自建的 `new Upload()` 装配、
  `SaveFile()` 后本地落盘校验、Windows 字符集换算、`chmod`、`filesize` / MIME 回填、
  `CountMemberArray(+1)`、`Filter_Plugin_PostUpload_Succeed` 手动触发——整段逻辑由
  官方 `PostUpload()` 一次完成。
- **文件删除体系**：删除 `at8_media_library_delete_upload()` 中自建的
  `DelFile()` → 本地路径计算 → `@unlink()` 兜底 → `Del()` → `CountMemberArray(-1)` 编排。
  插件不再对附件文件执行任何 `unlink`，也不再自行计算附件磁盘路径用于删除。
- **替换引擎**：删除 `act=replace` 全部逻辑（原文件备份 / 临时文件 `copy` 恢复 /
  `DelFile` + `SaveFile` 串联 / 本地直替分支 / 扩展名一致性校验 / 伪原子回滚）。
  Z-BlogPHP 官方附件系统未提供可复用的附件替换能力，本版本**移除「替换文件」功能**，
  不再自行维护一套替换引擎。
- **重复 Hook**：移除 `Filter_Plugin_at8_media_library_AllowExts`（会构成绕过官方上传白名单的
  第二判断入口）、`UploadSucceed`（与官方 `Filter_Plugin_PostUpload_Succeed` 重复）、
  `DeleteSucceed`（官方无对应删除成功 Hook，插件不再自造涉及附件敏感业务的接口）。

### 保留与加固

- 保留媒体库核心能力：网格 / 列表视图、分类 / 类型 / 月份 / 上传者 / 使用状态筛选、
  关键词搜索、排序分页、详情侧栏、灯箱、URL / HTML / Markdown 复制、统计概览、文章关联。
- 保留权限模型：`UploadMng`（查看）/ `UploadPst`（上传、编辑、关联）/ `UploadDel`（删除）/
  `UploadAll`（操作他人附件），并对齐官方 `Admin_UploadMng` 的数据范围。
- 保留只读展示辅助：`at8_media_library_disk_path()` / `is_standard_name()` /
  `upload_url()` 仅用于列表行展示「文件状态 / 图片尺寸」，已加注释明确**不参与任何写决策**；
  仍兼容早期版本把路径写进 `ul_Name` 的历史记录。
- **缓存 Key 补站点环境前缀**：Redis / APCu 按服务器进程共享，同一服务器多站点原先会
  互相命中 `at8ml:stats_all` 等键，现统一加站点根目录指纹。
- 上传面板的 `accept` 提示与提示文案改读官方站点配置（`ZC_UPLOAD_FILETYPE` /
  `ZC_UPLOAD_FILESIZE`），仅作 UI 提示，不构成服务端安全判断。
- 官方错误（`ZbpErrorException`，错误码 5 / 6 / 26 / 27 / 28）统一转成插件 JSON 文案，
  不回显原始异常文本、服务器路径或堆栈。

### 行为变化（升级须知）

- **「替换文件」功能已移除**：如需更换附件内容，请删除后重新上传。原功能会保持附件 ID 与
  URL 不变，新流程下会生成新的附件记录与 URL。
- **同名文件规则变化**：原先插件会自动改名（`名字_日期时间_随机数.ext`）后落盘，
  现在与系统自带附件管理一致——同一月份内同名文件会被官方直接拒绝。
- **上传文件名的落盘形式变化**：不再对文件名做字符白名单重写，直接采用官方
  `Upload::SaveFile()` 的落盘规则（文件名保持用户上传的原名；URL 由官方 `Upload::Url`
  做 `rawurlencode`）。
- **上传体积上限口径变化**：由「php.ini 的 `upload_max_filesize` / `post_max_size`」
  改为官方「网站设置 → 允许上传的大小」（`ZC_UPLOAD_FILESIZE`，单位 MB）。
- **删除失败语义变化**：官方 `DelUpload()` 为先删记录、后删文件且不检查文件删除结果，
  插件不再为失败做回滚补偿，也不再出现「文件删不掉就保留记录」的自定义行为。

### 兼容性

- 1.6.6 → 1.7.0 可直接覆盖升级：插件配置、已有附件记录、附件实体文件、自定义展示字段
  （`Metas` 内的 `media_title` / `media_alt`）全部保留；升级后写操作即进入新架构，
  不会回落到旧上传引擎。
- 最低 Z-BlogPHP 1.7.0（`<adapted>172900</adapted>` 不变）；官方 `PostUpload()` /
  `DelUpload()` 缺失时给出明确提示而非静默失败。
- 最低 PHP 7.4（不变）。
- 兼容本地存储与各类云存储 / 对象存储插件：上传经官方 `Filter_Plugin_Upload_SaveFile`、
  删除经官方 `Filter_Plugin_Upload_DelFile`，插件不假设「附件一定在本地磁盘」。

## 1.6.6（2026-09-24）

修复 debug 模式下的一条 PHP 警告（无功能变更）：

- **修复 `at8_media_library_cache_del()` 未判存在即删除导致的 E_WARNING**：
  该函数原先直接 `@unlink($dir . '/' . md5($key) . '.php')`，没有 `is_file()` 前置判断。
  Z-BlogPHP 的错误处理器不理会 `@` 抑制，因此在缓存文件本就不存在时（`stats_flush()` 每次
  写入/替换/删除/关联操作都会删 `stats` / `stats_all` / `stats_u<ID>` 三个键，而缓存可能尚未生成），
  会向 `zb_users/logs/*-error*.txt` 每次落一条
  `unlink(...): No such file or directory`（E_WARNING）。
  现改为先 `is_file()` 再删，与 `at8_media_library_cache_get()` 中的既有写法一致。
- 触发场景：debug 模式（或站点开启错误日志）下打开媒体库、上传 / 改名 / 删除 / 关联附件。
  此前在测试站历史日志中已累计出现上百条同类记录（非 1.6.5 引入，属长期遗留）。
- 同步更新 `function.php` 版本常量、`plugin.xml`、本日志、`README.md`、`RELEASE_CHECKLIST.md`。

## 1.6.5（2026-09-24）

规范符合性微调（无功能变更）：

- **`plugin.xml` 元数据补齐**：
  - `<source>` 节点补 `<email>361611074@qq.com</email>`（与 `<author>` 一致，便于应用中心审核员核对作者信息）。
  - `<modified>` 日期同步至 2026-09-24。
- `zbignore.txt` 调整：`README.md` 不再排除（上架审核对使用说明有要求，README 进 zba 包便于审核员查阅）；`CHANGELOG.md` / `RELEASE_CHECKLIST.md` / `screenshots` / `cache` / `.git` 仍按原状排除。
- 同步更新 `function.php` 版本常量、`plugin.xml`、本日志、`RELEASE_CHECKLIST.md`。

## 1.6.4（2026-09-23）

- **最低 PHP 版本提高至 7.4**（`plugin.xml` 的 `<phpver>`，应用中心据此拒绝安装）。
  依据（均可复现）：① 全量 PHP 文件在 **PHP 7.3.4** 通过 `php -l`（严于 7.4）；
  ② 运行时在 **PHP 8.2** 测试站实测零报错（debug 模式 8 个页面）；
  ③ 源码未使用任何 PHP 8.0+ 专有语法（全量扫描无 `?->`、`match` 表达式、`str_contains`、
  `#[Attribute]`、构造器提升、联合类型、`enum`、`readonly`）。
  原先声明 7.0 的依据是「缓存层用到 `Throwable`」，现统一为做过兼容承诺的 7.4。
- 同步更新 README「兼容性」中的 PHP 版本表述。

## 1.6.3（2026-09-23）

规范符合性修复（延续 1.6.2 审计）：

- **HTTP 状态码与 JSON 语义一致**：`at8_media_library_error()` 传入的 `code` 落在标准状态区间（400~599）时，同步以 `http_response_code()` 设置 HTTP 状态码，参数校验失败 / 未授权 / 方法不允许等失败请求不再一律返回 `200 OK`；响应体结构不变（`{success, code, msg}`），前端零改动
- 未列出动作的默认分支补显式 `400`（原先使用默认 `code = 1`）

## 1.6.2（2026-09-23）

规范符合性修复：

- **写操作强制 POST**：API 入口对 `upload` / `replace` / `update` / `bulk` / `delete` 强制 POST，非 POST 返回 `405`；
- **动作白名单**：`act` 不再读 `$_REQUEST`（含 COOKIE），改为显式「POST 优先 → GET 兜底」，并按只读 / 写操作两张白名单分发，未列出一律 `400`；
  顺带去掉未知动作的原始输入回显（避免反射内容进入响应与日志）；
- **CSRF 纵深防御**：`at8_media_library_check_csrf()` 非 POST 不再静默 `return`，改为直接拒绝（防将来新增调用点漏校验）；
- **JSON 响应结构**：新增 `success` 布尔字段（`{success, code, msg, data}`），`code` / `msg` 保持不变，前端零改动；
- **JS 命名空间**：`window.ML` → `window.AT8ML`（对齐插件唯一前缀，避免与主题 / 其他插件命名冲突）；
- **停用 / 卸载数据安全**：核实 1.7.5 `DisablePlugin()` 内部会调用 `UninstallPlugin_xxx()`（停用与卸载共用同一钩子，
  真卸载走 AppCentre 删目录反而不触发），故 `UninstallPlugin_at8_media_library()` 仅清理可再生的缓存目录（`cache/`），
  **不删除配置、附件记录、附件实体文件、附件自定义信息**；
- 新增 `LICENSE` / `CHANGELOG.md` / `RELEASE_CHECKLIST.md`；`plugin.xml` 显式声明 `<phpver>7.0</phpver>`。

## 1.6.1（2026-09-23）

- 修复 `at8_media_library_check_owner()` 越权校验（非管理员仅可操作自己的附件）；
- 与 `function.php` / `api.php` 同版本发布，修复「半新半旧文件混搭」导致的未定义函数报错。

## 1.6.0

- 引入附件所有权校验（`check_owner`），非 `UploadAll` 权限账号仅能操作 / 查看自己的附件。

## 1.5.x

- 对外扩展接口（`DefinePluginFilter`）：白名单、查询条件、缩略图、行数据、统计、上传 / 删除事件、编辑页面板；
- 多级缓存（Redis → APCu → 文件），统计口径按数据范围分键。

更早版本参见仓库提交历史：https://github.com/361611074/at8_media_library
