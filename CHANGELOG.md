# 更新日志 · at8_media_library

版本号规则：十进制封十进一（每段 0~9，满 10 进位），不用 1.2.10 这类写法。

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
