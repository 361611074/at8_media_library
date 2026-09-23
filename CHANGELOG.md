# 更新日志 · at8_media_library

版本号规则：十进制封十进一（每段 0~9，满 10 进位），不用 1.2.10 这类写法。

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
