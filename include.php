<?php
# 媒体库 · 相册式附件管理（at8_media_library）
# 作者：漫步白月光 https://www.at8.fun/

require_once dirname(__FILE__) . '/function.php';

# 注册插件
RegisterPlugin("at8_media_library", "ActivePlugin_at8_media_library");

# 对外声明本插件的扩展接口（官方 DefinePluginFilter 机制：先声明，其他插件的
# ActivePlugin 中 Add_Filter_Plugin 才能挂载成功；include 在所有 ActivePlugin 调用前完成）
#
# 【1.7.0 市场合规调整】本插件只声明「展示 / 查询」类扩展接口。
# 原 AllowExts / UploadSucceed / DeleteSucceed 三个接口直接涉及附件敏感业务，已移除：
#   · 上传类型是否放行 → 只由官方 Upload::CheckExtName()（ZC_UPLOAD_FILETYPE）裁决，
#     插件不再提供任何可绕过官方上传白名单的入口；
#   · 上传成功事件 → 请使用官方 Filter_Plugin_PostUpload_Succeed（由官方 PostUpload() 触发）；
#   · 删除成功事件 → 官方附件系统未提供删除成功 Hook，插件也不再自造一个。
if (function_exists('DefinePluginFilter')) {
    DefinePluginFilter('Filter_Plugin_at8_media_library_ListWhere');      // (&$where, $p) 列表查询条件
    DefinePluginFilter('Filter_Plugin_at8_media_library_Thumb');          // (&$url, $upload) 缩略图地址
    DefinePluginFilter('Filter_Plugin_at8_media_library_Row');            // (&$row, $upload) 行数据
    DefinePluginFilter('Filter_Plugin_at8_media_library_Stats');          // (&$stats) 统计口径
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

/**
 * 停用 / 卸载钩子：只清理运行时文件缓存目录（cache/），不删除任何配置与业务数据
 *
 * 【1.7.5 实机核实，勿改】官方 DisablePlugin() 内部会调用 UninstallPlugin_xxx()，
 * 即「停用插件」也会进入本函数；真正的「删除应用」由 AppCentre/app_del.php 直接删目录，
 * 不触发本函数。故本函数不得删除配置或附件数据，否则停用即造成用户数据丢失。
 *
 * 保留：插件配置（config）、全部附件记录（zbp_upload）、附件实体文件、
 *      附件自定义信息（Metas / Intro）——重新启用后照常可用
 */
function UninstallPlugin_at8_media_library()
{
    // 运行时文件缓存目录（插件目录下 cache/），可再生，停用时清理不影响任何用户数据
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
