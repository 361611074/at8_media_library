<?php
# 媒体库 · AJAX 接口
# 作者：漫步白月光 https://www.at8.fun/
#
# ============================================================================
# 职责边界（1.7.0 市场合规重构 · 1.7.1 审核收尾）
# ============================================================================
# 本文件只做：认证 → 权限 → CSRF → 参数校验 → 调用官方附件能力 → 输出 JSON。
#
# 附件的上传 / 保存 / 类型校验 / 体积校验 / 命名 / 存储路径 / 云存储 Hook /
# 记录写入 / 用户附件计数 / 附件删除，全部由 Z-BlogPHP 官方附件系统完成：
#
#   upload → 官方 PostUpload()   （function.php: at8_media_library_official_upload_one）
#   delete → 官方 DelUpload()    （function.php: at8_media_library_official_delete_upload）
#   update → 官方 Upload::Save() （官方对象保存方法）
#
# 本文件不含任何「文件是否安全」「图片是否有效」「文件该存到哪」的判断，
# 不 unlink、不 move_uploaded_file、不自行计算附件磁盘路径，
# 也不 include cmd.php / 伪造 HTTP 请求 / 模拟浏览器调用系统入口。
# ============================================================================

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
// 只读：list / stats / categories / posts / quotecheck
// 写：upload / update / delete（bulk 是批量 UI 调度器，底层逐个走官方附件流程）
$readActs = array('list', 'stats', 'categories', 'posts', 'quotecheck');
$writeActs = array('upload', 'update', 'bulk', 'delete');
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
        at8_media_library_error('附件不存在', 404);
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

    // 参数整理（只整理 $_FILES 结构，不做任何安全判断）
    $files = at8_media_library_normalize_files('files');
    if (count($files) == 0) {
        $files = at8_media_library_normalize_files('file');
    }
    if (count($files) == 0) {
        // 请求体达到 php.ini 的 post_max_size 时，PHP 会静默清空 $_POST / $_FILES，此处给出可诊断提示
        $cl = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $pm = at8_media_library_ini_bytes(ini_get('post_max_size'));
        if ($cl > 0 && $pm > 0 && $cl >= $pm) {
            at8_media_library_error('没有收到文件：请求体已达服务器 post_max_size（' . ini_get('post_max_size') . '）上限，请调大 php.ini 后重试', 400);
        }
        at8_media_library_error('没有收到文件', 400);
    }
    if (count($files) > 30) {
        at8_media_library_error('单次最多上传 30 个文件，请分批处理', 400);
    }

    $rows = array();
    foreach ($files as $f) {
        // PHP 自身在上传阶段就已拒绝的文件（超出 php.ini 上限等），给出可读原因。
        // 这不是插件的安全判定，站点级类型 / 体积规则仍由官方流程裁决。
        $err = isset($f['error']) ? (int) $f['error'] : 4;
        if ($err !== 0) {
            at8_media_library_error('上传失败：' . at8_media_library_upload_error_text($err), 400);
        }

        // 交给官方附件上传能力（官方 PostUpload：权限复核 + 类型 / 体积 / 重名校验 +
        // 落盘 + 云存储 Hook + 记录写入 + 用户计数 + 官方上传成功 Hook）
        $u = at8_media_library_official_upload_one($f);

        // 媒体库业务：上传时即关联到指定文章（写官方 LogID 字段 + 官方保存方法）
        if ($logid > 0) {
            $u->LogID = $logid;
            $u->Save();
        }

        $rows[] = at8_media_library_upload_row($u);
    }
    at8_media_library_stats_flush();
    at8_media_library_ok($rows);
}

// 更新附件信息（标题 / Alt / 说明 / 关联文章）
//
// 只写「媒体库自有展示字段（存于官方 Metas 扩展机制）」与官方 LogID 关联字段，
// 不改动 Name / SourceName / MimeType / Size / AuthorID / PostTime 等官方附件核心字段，
// 保存走官方对象方法 $u->Save()。
if ($act == 'update') {
    at8_media_library_check_csrf();
    at8_media_library_require_right('UploadPst'); // 官方权限项：上传（信息编辑属写操作）
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        at8_media_library_error('附件不存在', 404);
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

// 批量操作（批量 UI 调度器：底层每个附件仍逐个走官方附件流程）
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
        at8_media_library_error('未选择任何附件', 400);
    }
    $idArr = array_values(array_unique($idArr));
    if (count($idArr) > 500) {
        at8_media_library_error('单次最多操作 500 个附件，请分批处理', 400);
    }

    if ($op == 'delete') {
        @set_time_limit(0); // 批量删除可能较慢，避免执行超时中断
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
            // 逐个交给官方附件删除能力（官方 DelUpload 内部再次复核权限与所有权）
            if (at8_media_library_official_delete_upload((int) $u->ID)) {
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

    at8_media_library_error('未知操作', 400);
}

// 删除单个
if ($act == 'delete') {
    at8_media_library_check_csrf();
    at8_media_library_require_right('UploadDel'); // 官方权限项：删除
    $id = (int) GetVars('id', 'POST');
    $u = $zbp->GetUploadByID($id);
    if ($u->ID == 0) {
        at8_media_library_error('附件不存在', 404);
    }
    // 插件侧数据范围预检：无 UploadAll 仅能删除自己的附件（官方 DelUpload 内部会再判一次）
    at8_media_library_check_owner($u);
    // 交给官方附件删除能力（官方 DelUpload：权限复核 + 所有权判定 + 记录删除 +
    // 用户附件计数 + 文件删除 + 云存储删除 Hook）
    if (!function_exists('DelUpload')) {
        at8_media_library_error('当前 Z-BlogPHP 版本缺少官方附件删除接口，请先升级系统', 500);
    }
    if (!at8_media_library_official_delete_upload((int) $u->ID)) {
        at8_media_library_error('删除失败：官方附件流程未完成（无权限或附件不存在）', 403);
    }
    at8_media_library_stats_flush();
    at8_media_library_ok(array('id' => $id));
}

// 所有动作已在文件头经白名单分发，此处为防御性兜底（正常不可达）
at8_media_library_error('未知操作', 400);
