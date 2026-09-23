<?php
# 媒体库 · AJAX 接口
# 作者：漫步白月光 https://www.at8.fun/

require dirname(__FILE__) . '/../../../zb_system/function/c_system_base.php';
require dirname(__FILE__) . '/../../../zb_system/function/c_system_admin.php';
require_once dirname(__FILE__) . '/function.php';

$zbp->Load();
at8_media_library_check_login();

// 动作取值：显式只读 POST / GET，不用 $_REQUEST（$_REQUEST 含 COOKIE，可能被外部注入 act）
$act = '';
if (isset($_POST['act']) && is_string($_POST['act'])) {
    $act = $_POST['act'];
} elseif (isset($_GET['act']) && is_string($_GET['act'])) {
    $act = $_GET['act'];
}

// 允许的动作白名单（未列出一律拒绝，避免任何隐式分支）
$readActs = array('list', 'stats', 'categories', 'posts', 'quotecheck');
$writeActs = array('upload', 'replace', 'update', 'bulk', 'delete');
if (!in_array($act, $readActs, true) && !in_array($act, $writeActs, true)) {
    // 不回显原始输入，避免反射型内容进入响应与日志
    at8_media_library_error('未知操作', 400);
}
// 写操作强制 POST：避免用 GET 触发状态变更，也避免 CSRF 校验被请求方法绕过
if (in_array($act, $writeActs, true) && (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST')) {
    at8_media_library_error('该操作必须使用 POST 请求', 405);
}

// 列表
if ($act == 'list') {
    at8_media_library_ok(at8_media_library_list($_GET + $_POST));
}

// 概览统计
if ($act == 'stats') {
    at8_media_library_ok(at8_media_library_stats());
}

// 分类列表
if ($act == 'categories') {
    at8_media_library_ok(at8_media_library_categories());
}

// 文章搜索（关联下拉用，复用官方 GetPostList；注意官方签名第一参数是 $select）
if ($act == 'posts') {
    $kw = trim(GetVars('q', 'GET'));
    $where = array();
    // 非 root 仅可见「已发布 + 自己的」文章/页面，防止他人草稿、审核、私人文章标题被枚举
    if (!$zbp->CheckRights('root')) {
        $where[] = array('OR',
            array('=', 'log_Status', ZC_POST_STATUS_PUBLIC),
            array('=', 'log_AuthorID', (int) $zbp->user->ID),
        );
    }
    if ($kw != '') {
        $where[] = array('LIKE', 'log_Title', '%' . $kw . '%');
    }
    $list = $zbp->GetPostList(null, $where, array('log_PostTime' => 'DESC'), array(20));
    $out = array();
    foreach ($list as $p) {
        $out[] = array(
            'id' => (int) $p->ID,
            'title' => $p->Title,
            'type' => (int) $p->Type,
        );
    }
    at8_media_library_ok($out);
}

// 引用检测：关联文章正文是否实际引用该附件（详情抽屉用，只读）
if ($act == 'quotecheck') {
    $id = (int) GetVars('id', 'REQUEST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        at8_media_library_error('附件不存在');
    }
    // 数据范围：无 UploadAll 仅能检测自己的附件
    at8_media_library_check_owner($u);
    at8_media_library_ok(at8_media_library_quote_state($u));
}

// 上传（支持多文件、拖拽）
if ($act == 'upload') {
    at8_media_library_check_csrf();
    at8_media_library_require_right('UploadPst'); // 官方权限项：上传
    $logid = at8_media_library_validate_logid((int) GetVars('logid', 'POST'));
    $files = at8_media_library_normalize_files('files');
    if (count($files) == 0) {
        $files = at8_media_library_normalize_files('file');
    }
    if (count($files) == 0) {
        $max = at8_media_library_max_upload_size();
        if ($max > 0 && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $max) {
            at8_media_library_error('文件超过服务器上限 ' . at8_media_library_size_text($max) . '，请调大 php.ini 的 post_max_size 与 upload_max_filesize');
        }
        at8_media_library_error('没有收到文件');
    }
    if (count($files) > 30) {
        at8_media_library_error('单次最多上传 30 个文件，请分批处理');
    }
    $rows = array();
    foreach ($files as $f) {
        $u = at8_media_library_save_one($f, $logid);
        // 对外接口：上传成功事件（其他插件可做缩略图生成、同步推送等后续处理）
        at8_media_library_hook('at8_media_library_UploadSucceed', $u);
        $rows[] = at8_media_library_upload_row($u);
    }
    at8_media_library_stats_flush();
    at8_media_library_ok($rows);
}

// 替换文件（保留附件记录与 URL 路径不变）
if ($act == 'replace') {
    at8_media_library_check_csrf();
    at8_media_library_require_right('UploadPst'); // 官方权限项：上传（替换属写操作）
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        at8_media_library_error('附件不存在');
    }
    // 所有权：无 UploadAll 仅能替换自己的附件
    at8_media_library_check_owner($u);
    $files = at8_media_library_normalize_files('file');
    if (count($files) != 1) {
        at8_media_library_error('请选择一个替换文件');
    }
    $f = $files[0];
    if ($f['error'] !== 0 || $f['tmp_name'] == '' || !is_uploaded_file($f['tmp_name'])) {
        at8_media_library_error('替换文件无效');
    }
    $max = at8_media_library_max_upload_size();
    if ($max > 0 && (int) $f['size'] > $max) {
        at8_media_library_error('文件超过服务器上限 ' . at8_media_library_size_text($max));
    }
    $orig = at8_media_library_display_name($f['name']);
    $disk = at8_media_library_safe_filename($f['name']);
    $dot = strrpos($disk, '.');
    $ext = ($dot !== false) ? strtolower(substr($disk, $dot + 1)) : '';
    $allow = at8_media_library_allow_exts();
    if ($ext == '' || !in_array($ext, $allow)) {
        at8_media_library_error('不允许上传的类型：.' . $ext . '（可在后台「网站设置 → 允许上传的文件类型」中调整）');
    }
    if (at8_media_library_is_image_ext($ext)) {
        $info = @getimagesize($f['tmp_name']);
        $mime = strtolower((string) at8_media_library_detect_mime($f['tmp_name'], $ext));
        if (!is_array($info) && strpos($mime, 'image/') !== 0) {
            at8_media_library_error('文件内容不是有效图片：' . $orig);
        }
    }
    // 替换不改变附件地址，因此类型必须与原附件一致（防止 .jpg 记录被换成其他类型内容）
    $origExt = strtolower(pathinfo($u->Name, PATHINFO_EXTENSION));
    if ($origExt !== '' && $ext !== $origExt) {
        at8_media_library_error('替换文件类型（.' . $ext . '）需与原附件（.' . $origExt . '）一致；如需其他类型请删除后重新上传');
    }
    // 注意：不更新 PostTime —— Dir/Url 均由 PostTime 推导，更新会导致附件 URL 变化
    // 且 FullFile 指向新目录而文件仍在旧目录（跨月替换必现「文件缺失」）
    if (at8_media_library_is_standard_name($u->Name)) {
        // 系统标准记录：走官方存储流程（DelFile 触发云存储删除 hook，SaveFile 触发云存储上传 hook），
        // 确保对象存储同步更新，云端与本站内容一致
        $target = at8_media_library_disk_path($u);
        if ($target !== '' && !is_writable(dirname($target))) {
            at8_media_library_error('替换失败，请检查目录写入权限');
        }
        // 原文件备份：DelFile 与 SaveFile 之间任何一步失败时恢复，避免「记录在、文件丢」
        $backup = '';
        if ($target !== '' && is_file($target)) {
            $tmpBak = @tempnam(sys_get_temp_dir(), 'at8ml_bak_');
            if (is_string($tmpBak) && $tmpBak !== '' && @copy($target, $tmpBak)) {
                $backup = $tmpBak;
            }
        }
        $restore = function () use (&$backup, $u) {
            if ($backup === '') {
                return;
            }
            $p = at8_media_library_disk_path($u);
            if ($p !== '' && !is_file($p)) {
                @copy($backup, $p);
            }
            @unlink($backup);
            $backup = '';
        };
        $delRet = $u->DelFile();
        if ($delRet === false) {
            $restore();
            at8_media_library_error('原文件删除失败（存储插件报告），已中止替换');
        }
        if (!$u->SaveFile($f['tmp_name'])) {
            $restore();
            at8_media_library_error('替换失败：站点「允许上传的文件类型」设置与该扩展名冲突');
        }
        $storageHooked = !empty($GLOBALS['hooks']['Filter_Plugin_Upload_SaveFile']);
        $newPath = at8_media_library_disk_path($u);
        if ($newPath === '' && !$storageHooked) {
            $restore();
            at8_media_library_error('替换失败，请检查目录写入权限');
        }
        if ($backup !== '') {
            @unlink($backup);
        }
        $statFile = ($newPath !== '') ? $newPath : $f['tmp_name'];
        $u->SourceName = $orig;
        $u->Size = (int) @filesize($statFile);
        $u->MimeType = at8_media_library_detect_mime($statFile, $ext);
        $u->Save();
    } else {
        // 旧格式记录（历史前缀存储）：不经官方存储流程、云插件不会接管，本地直替
        $target = at8_media_library_disk_path($u);
        if ($target === '') {
            at8_media_library_error('未找到原附件文件，无法替换（可删除后重新上传）');
        }
        if (!@move_uploaded_file($f['tmp_name'], $target)) {
            at8_media_library_error('替换失败，请检查目录写入权限');
        }
        @chmod($target, 0644);
        $u->SourceName = $orig;
        $u->Size = (int) @filesize($target);
        $u->MimeType = at8_media_library_detect_mime($target, $ext);
        $u->Save();
    }
    at8_media_library_stats_flush();
    at8_media_library_audit('替换附件 #' . $u->ID . ' ' . $u->Name . ' -> ' . $orig);
    at8_media_library_ok(at8_media_library_upload_row($u));
}

// 更新附件信息（标题 / Alt / 说明 / 关联文章）
if ($act == 'update') {
    at8_media_library_check_csrf();
    at8_media_library_require_right('UploadPst'); // 官方权限项：上传（信息编辑属写操作）
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        at8_media_library_error('附件不存在');
    }
    // 所有权：无 UploadAll 仅能编辑自己的附件
    at8_media_library_check_owner($u);
    if (isset($_POST['logid'])) {
        $u->LogID = at8_media_library_validate_logid((int) $_POST['logid']);
    }
    at8_media_library_update_meta($u, $_POST);
    $u->Save();
    // 关联状态变化影响「未关联文章」统计，缓存必须同步失效
    at8_media_library_stats_flush();
    at8_media_library_ok(at8_media_library_upload_row($u));
}

// 批量操作
if ($act == 'bulk') {
    at8_media_library_check_csrf();
    // 具体权限项在 op 分支内细分（delete=UploadDel / bind=UploadPst）
    $op = GetVars('op', 'POST');
    $ids = isset($_POST['ids']) ? $_POST['ids'] : '';
    $idArr = array();
    foreach (explode(',', (string) $ids) as $v) {
        $v = (int) trim($v);
        if ($v > 0) {
            $idArr[] = $v;
        }
    }
    if (count($idArr) == 0) {
        at8_media_library_error('未选择任何附件');
    }
    $idArr = array_values(array_unique($idArr));
    if (count($idArr) > 500) {
        at8_media_library_error('单次最多操作 500 个附件，请分批处理');
    }

    if ($op == 'delete') {
        @set_time_limit(0); // 批量删文件可能较慢，避免执行超时中断
        at8_media_library_require_right('UploadDel'); // 官方权限项：删除
        $done = 0;
        $failed = 0;
        $skipped = 0;
        // 一次 IN 查询取回全部附件对象，避免逐条 N+1 查询
        $sql = $zbp->db->sql->Select(
            $zbp->table['Upload'],
            '*',
            array(array('IN', 'ul_ID', $idArr)),
            null,
            null
        );
        $res = $zbp->db->Query($sql);
        foreach ($res as $r) {
            $u = new Upload();
            $u->LoadInfoByAssoc($r);
            if ((int) $u->ID <= 0) {
                $skipped++;
                continue;
            }
            // 无 UploadAll 只能删自己的附件；他人附件计为跳过
            if (!at8_media_library_check_owner($u, false)) {
                $skipped++;
                continue;
            }
            // 官方流程删除：文件（含云存储 hook）删除验证成功后才删记录、同步用户附件数
            if (at8_media_library_delete_upload($u)) {
                $done++;
            } else {
                $failed++;
            }
        }
        $skipped += count($idArr) - $done - $failed - $skipped;
        if ($skipped < 0) {
            $skipped = 0;
        }
        at8_media_library_stats_flush();
        at8_media_library_ok(array('done' => $done, 'failed' => $failed, 'skipped' => $skipped));
    }

    if ($op == 'bind') {
        at8_media_library_require_right('UploadPst'); // 官方权限项：上传（关联属写操作）
        $logid = at8_media_library_validate_logid((int) GetVars('logid', 'POST'));
        $done = 0;
        $skipped = 0;
        // 一次 IN 查询取回全部附件对象，避免逐条 N+1 查询
        $sql = $zbp->db->sql->Select(
            $zbp->table['Upload'],
            '*',
            array(array('IN', 'ul_ID', $idArr)),
            null,
            null
        );
        $res = $zbp->db->Query($sql);
        foreach ($res as $r) {
            $u = new Upload();
            $u->LoadInfoByAssoc($r);
            if ((int) $u->ID <= 0) {
                $skipped++;
                continue;
            }
            // 无 UploadAll 只能关联自己的附件；他人附件计为跳过
            if (!at8_media_library_check_owner($u, false)) {
                $skipped++;
                continue;
            }
            $u->LogID = $logid;
            $u->Save();
            $done++;
        }
        at8_media_library_stats_flush();
        at8_media_library_audit('批量关联 ' . $done . ' 个附件 -> 文章 #' . $logid . '，跳过 ' . $skipped);
        at8_media_library_ok(array('done' => $done, 'skipped' => $skipped));
    }

    at8_media_library_error('未知操作');
}

// 删除单个
if ($act == 'delete') {
    at8_media_library_check_csrf();
    at8_media_library_require_right('UploadDel'); // 官方权限项：删除
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        at8_media_library_error('附件不存在');
    }
    // 所有权：无 UploadAll 仅能删除自己的附件
    at8_media_library_check_owner($u);
    // 官方流程删除：文件（含云存储 hook）删除验证成功后才删记录、同步用户附件数
    if (!at8_media_library_delete_upload($u)) {
        at8_media_library_error('删除失败：文件无法删除（目录权限或存储插件报告失败），记录已保留');
    }
    at8_media_library_stats_flush();
    at8_media_library_ok(array('id' => $id));
}

// 所有动作已在文件头经白名单分发，此处为防御性兜底（正常不可达）
at8_media_library_error('未知操作', 400);
