<?php
# 媒体库 · AJAX 接口
# 作者：漫步白月光 https://www.at8.fun/

require dirname(__FILE__) . '/../../../zb_system/function/c_system_base.php';
require dirname(__FILE__) . '/../../../zb_system/function/c_system_admin.php';
require_once dirname(__FILE__) . '/function.php';

$zbp->Load();
media_library_check_login();

$act = GetVars('act', 'REQUEST');

// 列表
if ($act == 'list') {
    media_library_ok(media_library_list($_GET + $_POST));
}

// 概览统计
if ($act == 'stats') {
    media_library_ok(media_library_stats());
}

// 分类列表
if ($act == 'categories') {
    media_library_ok(media_library_categories());
}

// 文章搜索（关联下拉用，复用官方 GetPostList；注意官方签名第一参数是 $select）
if ($act == 'posts') {
    $kw = trim(GetVars('q', 'GET'));
    $where = array();
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
    media_library_ok($out);
}

// 引用检测：关联文章正文是否实际引用该附件（详情抽屉用，只读）
if ($act == 'quotecheck') {
    $id = (int) GetVars('id', 'REQUEST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        media_library_error('附件不存在');
    }
    media_library_ok(media_library_quote_state($u));
}

// 上传（支持多文件、拖拽）
if ($act == 'upload') {
    media_library_check_csrf();
    media_library_check_upload_rights();
    $logid = (int) GetVars('logid', 'POST');
    $files = media_library_normalize_files('files');
    if (count($files) == 0) {
        $files = media_library_normalize_files('file');
    }
    if (count($files) == 0) {
        $max = media_library_max_upload_size();
        if ($max > 0 && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $max) {
            media_library_error('文件超过服务器上限 ' . media_library_size_text($max) . '，请调大 php.ini 的 post_max_size 与 upload_max_filesize');
        }
        media_library_error('没有收到文件');
    }
    $rows = array();
    foreach ($files as $f) {
        $u = media_library_save_one($f, $logid);
        $rows[] = media_library_upload_row($u);
    }
    media_library_stats_flush();
    media_library_ok($rows);
}

// 替换文件（保留附件记录与 URL 路径不变）
if ($act == 'replace') {
    media_library_check_csrf();
    media_library_check_upload_rights();
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        media_library_error('附件不存在');
    }
    $files = media_library_normalize_files('file');
    if (count($files) != 1) {
        media_library_error('请选择一个替换文件');
    }
    $f = $files[0];
    if ($f['error'] !== 0 || $f['tmp_name'] == '' || !is_uploaded_file($f['tmp_name'])) {
        media_library_error('替换文件无效');
    }
    $max = media_library_max_upload_size();
    if ($max > 0 && (int) $f['size'] > $max) {
        media_library_error('文件超过服务器上限 ' . media_library_size_text($max));
    }
    $orig = media_library_display_name($f['name']);
    $disk = media_library_safe_filename($f['name']);
    $dot = strrpos($disk, '.');
    $ext = ($dot !== false) ? strtolower(substr($disk, $dot + 1)) : '';
    $allow = media_library_allow_exts();
    if ($ext == '' || !in_array($ext, $allow)) {
        media_library_error('不允许上传的类型：.' . $ext . '（可在后台「网站设置 → 允许上传的文件类型」中调整）');
    }
    if (media_library_is_image_ext($ext)) {
        $info = @getimagesize($f['tmp_name']);
        $mime = strtolower((string) media_library_detect_mime($f['tmp_name'], $ext));
        if (!is_array($info) && strpos($mime, 'image/') !== 0) {
            media_library_error('文件内容不是有效图片：' . $orig);
        }
    }
    // 替换不改变附件地址，因此类型必须与原附件一致（防止 .jpg 记录被换成其他类型内容）
    $origExt = strtolower(pathinfo($u->Name, PATHINFO_EXTENSION));
    if ($origExt !== '' && $ext !== $origExt) {
        media_library_error('替换文件类型（.' . $ext . '）需与原附件（.' . $origExt . '）一致；如需其他类型请删除后重新上传');
    }
    // 目标文件必须真实存在且落在附件目录内（防止记录被篡改后越权覆盖文件）
    $target = media_library_disk_path($u);
    if ($target === '') {
        media_library_error('未找到原附件文件，无法替换（可删除后重新上传）');
    }
    if (!@move_uploaded_file($f['tmp_name'], $target)) {
        media_library_error('替换失败，请检查目录写入权限');
    }
    @chmod($target, 0644);
    // 注意：不更新 PostTime —— Dir/Url 均由 PostTime 推导，更新会导致附件 URL 变化
    // 且 FullFile 指向新目录而文件仍在旧目录（跨月替换必现「文件缺失」）
    $u->SourceName = $orig;
    $u->Size = (int) @filesize($target);
    $u->MimeType = media_library_detect_mime($target, $ext);
    $u->Save();
    media_library_stats_flush();
    media_library_audit('替换附件 #' . $u->ID . ' ' . $u->Name . ' -> ' . $orig);
    media_library_ok(media_library_upload_row($u));
}

// 更新附件信息（标题 / Alt / 说明 / 关联文章）
if ($act == 'update') {
    media_library_check_csrf();
    media_library_check_upload_rights();
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        media_library_error('附件不存在');
    }
    if (isset($_POST['logid'])) {
        $u->LogID = max(0, (int) $_POST['logid']);
    }
    media_library_update_meta($u, $_POST);
    $u->Save();
    // 关联状态变化影响「未关联文章」统计，缓存必须同步失效
    media_library_stats_flush();
    media_library_ok(media_library_upload_row($u));
}

// 批量操作
if ($act == 'bulk') {
    media_library_check_csrf();
    media_library_check_upload_rights();
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
        media_library_error('未选择任何附件');
    }
    $idArr = array_values(array_unique($idArr));
    if (count($idArr) > 500) {
        media_library_error('单次最多操作 500 个附件，请分批处理');
    }

    if ($op == 'delete') {
        @set_time_limit(0); // 批量删文件可能较慢，避免执行超时中断
        $done = 0;
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
                continue;
            }
            // 标准记录走系统 DelFile()（兼容云存储接管插件）；历史前缀记录 FullFile 指向不正确，按兼容路径删
            if (media_library_is_standard_name($u->Name)) {
                $u->DelFile();
            } else {
                $disk = media_library_disk_path($u);
                if ($disk !== '') {
                    @unlink($disk);
                }
            }
            media_library_audit('删除附件 #' . $u->ID . ' ' . $u->Name);
            $u->Del();
            $done++;
        }
        $skip = count($idArr) - $done;
        media_library_stats_flush();
        media_library_ok(array('done' => $done, 'skipped' => $skip));
    }

    if ($op == 'bind') {
        $logid = (int) GetVars('logid', 'POST');
        $done = 0;
        foreach ($idArr as $id) {
            $u = $zbp->GetUploadByID($id);
            if ($u->ID > 0) {
                $u->LogID = max(0, $logid);
                $u->Save();
                $done++;
            }
        }
        media_library_stats_flush();
        media_library_ok(array('done' => $done));
    }

    media_library_error('未知操作');
}

// 删除单个
if ($act == 'delete') {
    media_library_check_csrf();
    media_library_check_upload_rights();
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        media_library_error('附件不存在');
    }
    // 标准记录走系统 DelFile()（兼容云存储接管插件）；历史前缀记录 FullFile 指向不正确，按兼容路径删
    if (media_library_is_standard_name($u->Name)) {
        $u->DelFile();
    } else {
        $disk = media_library_disk_path($u);
        if ($disk !== '') {
            @unlink($disk);
        }
    }
    media_library_audit('删除附件 #' . $u->ID . ' ' . $u->Name);
    $u->Del();
    media_library_stats_flush();
    media_library_ok(array('id' => $id));
}

media_library_error('未知操作：' . htmlspecialchars($act));
