<?php
# 媒体库 · 相册式附件管理（at8_media_library）
# 作者：漫步白月光 https://www.at8.fun/

require_once dirname(__FILE__) . '/function.php';

# 注册插件
RegisterPlugin("at8_media_library", "ActivePlugin_at8_media_library");

function ActivePlugin_at8_media_library()
{
    // 后台左侧菜单
    Add_Filter_Plugin('Filter_Plugin_Admin_LeftMenu', 'media_library_AddLeftMenu');
    // 后台顶部菜单
    Add_Filter_Plugin('Filter_Plugin_Admin_TopMenu', 'media_library_AddTopMenu');
    // 系统自带「附件管理」页面右上角加入口
    Add_Filter_Plugin('Filter_Plugin_Admin_UploadMng_SubMenu', 'media_library_UploadMngSubMenu');
}

function media_library_AddLeftMenu(&$m)
{
    global $zbp;
    if (!media_library_can_view()) {
        return; // 无查看附件权限的账号不显示入口
    }
    $m[] = MakeLeftMenu("root", "媒体库", $zbp->host . "zb_users/plugin/at8_media_library/main.php", "nav_at8_media_library", "aMediaLibrary", "");
}

function media_library_AddTopMenu(&$m)
{
    global $zbp;
    if (!media_library_can_view()) {
        return;
    }
    $m[] = MakeTopMenu("root", "媒体库", $zbp->host . "zb_users/plugin/at8_media_library/main.php", "_self", "topmenu_at8_media_library");
}

function media_library_UploadMngSubMenu(&$m = null)
{
    global $zbp;
    if (!media_library_can_view()) {
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
        $zbp->Config('at8_media_library')->version = MEDIA_LIBRARY_VERSION;
        $zbp->SaveConfig('at8_media_library');
    }
}

function UninstallPlugin_at8_media_library()
{
    // 保留配置与附件数据，卸载不删文件
}
