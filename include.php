<?php
# 媒体库 · 相册式附件管理（at8_media_library）
# 作者：漫步白月光 https://www.at8.fun/

require_once dirname(__FILE__) . '/function.php';

# 注册插件
RegisterPlugin("at8_media_library", "ActivePlugin_at8_media_library");

# 对外声明本插件的扩展接口（官方 DefinePluginFilter 机制：先声明，其他插件的
# ActivePlugin 中 Add_Filter_Plugin 才能挂载成功；include 在所有 ActivePlugin 调用前完成）
if (function_exists('DefinePluginFilter')) {
    DefinePluginFilter('Filter_Plugin_at8_media_library_AllowExts');      // (&$allow) 上传白名单
    DefinePluginFilter('Filter_Plugin_at8_media_library_ListWhere');      // (&$where, $p) 列表查询条件
    DefinePluginFilter('Filter_Plugin_at8_media_library_Thumb');          // (&$url, $upload) 缩略图地址
    DefinePluginFilter('Filter_Plugin_at8_media_library_Row');            // (&$row, $upload) 行数据
    DefinePluginFilter('Filter_Plugin_at8_media_library_Stats');          // (&$stats) 统计口径
    DefinePluginFilter('Filter_Plugin_at8_media_library_UploadSucceed');  // ($upload) 上传成功事件
    DefinePluginFilter('Filter_Plugin_at8_media_library_DeleteSucceed');  // ($upload) 删除成功事件
    DefinePluginFilter('Filter_Plugin_at8_media_library_EditPanel');      // (&$html) 编辑页面板 HTML
}

function ActivePlugin_at8_media_library()
{
    // 后台左侧菜单
    Add_Filter_Plugin('Filter_Plugin_Admin_LeftMenu', 'at8_media_library_AddLeftMenu');
    // 后台顶部菜单
    Add_Filter_Plugin('Filter_Plugin_Admin_TopMenu', 'at8_media_library_AddTopMenu');
    // 系统自带「附件管理」页面右上角加入口
    Add_Filter_Plugin('Filter_Plugin_Admin_UploadMng_SubMenu', 'at8_media_library_UploadMngSubMenu');
    // 文章/页面编辑页右栏「文章配图」面板
    Add_Filter_Plugin('Filter_Plugin_Edit_Response3', 'at8_media_library_edit_panel');
}

function at8_media_library_AddLeftMenu(&$m)
{
    global $zbp;
    if (!at8_media_library_can_view()) {
        return; // 无查看附件权限的账号不显示入口
    }
    $m[] = MakeLeftMenu("UploadMng", "媒体库", $zbp->host . "zb_users/plugin/at8_media_library/main.php", "nav_at8_media_library", "aMediaLibrary", "");
}

function at8_media_library_AddTopMenu(&$m)
{
    global $zbp;
    if (!at8_media_library_can_view()) {
        return;
    }
    $m[] = MakeTopMenu("UploadMng", "媒体库", $zbp->host . "zb_users/plugin/at8_media_library/main.php", "_self", "topmenu_at8_media_library");
}

function at8_media_library_UploadMngSubMenu(&$m = null)
{
    global $zbp;
    if (!at8_media_library_can_view()) {
        return;
    }
    // 兼容两种调用约定：传入数组则追加；无参调用（老版本）则直接输出
    $html = MakeSubMenu("相册视图", $zbp->host . "zb_users/plugin/at8_media_library/main.php", "m-left", "_self", "aMediaLibrary", "以相册形式管理附件");
    if (is_array($m)) {
        $m[] = $html;
    } else {
        echo $html;
    }
}

function InstallPlugin_at8_media_library()
{
    global $zbp;
    if (!$zbp->HasConfig('at8_media_library')) {
        // v1.1.x 及更早版本插件 ID 为 media_library，迁移旧配置后清理
        if ($zbp->HasConfig('media_library')) {
            $oldPerpage = (int) $zbp->Config('media_library')->perpage;
            $zbp->Config('at8_media_library')->perpage = ($oldPerpage > 0) ? $oldPerpage : 48;
            $zbp->DelConfig('media_library');
        } else {
            $zbp->Config('at8_media_library')->perpage = 48;
        }
        $zbp->SaveConfig('at8_media_library');
    }
}

/**
 * 插件更新时调用（含旧版兼容别名 at8_media_library_Updated）
 * 后续版本若新增/变更配置键，在此按 ConfigVer 逐级迁移
 */
function UpdatePlugin_at8_media_library()
{
    global $zbp;
    $conf = $zbp->Config('at8_media_library');
    $ver = (int) $conf->ConfigVer;
    if ($ver < 1) {
        // v1：确保 perpage 存在且合法（覆盖 v1.5.0 前的旧配置）
        $perpage = (int) $conf->perpage;
        if ($perpage <= 0) {
            $conf->perpage = 48;
        }
        $conf->ConfigVer = 1;
        $zbp->SaveConfig('at8_media_library');
    }
}
// 旧版兼容（1.7 前的更新钩子命名）
function at8_media_library_Updated()
{
    UpdatePlugin_at8_media_library();
}

function UninstallPlugin_at8_media_library()
{
    // 清理运行时文件缓存目录（插件目录下 cache/），保留配置与附件数据，卸载不删附件文件
    $dir = dirname(__FILE__) . '/cache';
    if (is_dir($dir)) {
        // 两次 glob：* 不匹配 dotfile（如 .htaccess），.* 需剔除 . / ..
        $files = array_merge((array) glob($dir . '/*'), (array) glob($dir . '/.*'));
        if (is_array($files)) {
            foreach ($files as $f) {
                $base = basename($f);
                if ($base === '.' || $base === '..') {
                    continue;
                }
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        @rmdir($dir);
    }
}
