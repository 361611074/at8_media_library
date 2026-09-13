<?php
# 媒体库 · 相册式附件管理 - 公共函数
# 作者：漫步白月光 https://www.at8.fun/

// 禁止直接访问本文件：必须由 Z-Blog 系统（ZBP_PATH 已定义）载入
if (!defined('ZBP_PATH')) {
    exit();
}

if (!defined('MEDIA_LIBRARY_VERSION')) {
    define('MEDIA_LIBRARY_VERSION', '1.1.3');
}

/**
 * 输出 JSON 并结束
 */
function media_library_json($arr)
{
    while (ob_get_length() !== false && @ob_end_clean()) {
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    die();
}

function media_library_error($msg, $code = 1)
{
    media_library_json(array('code' => $code, 'msg' => $msg));
}

function media_library_ok($data = null)
{
    $arr = array('code' => 0, 'msg' => 'ok');
    if ($data !== null) {
        $arr['data'] = $data;
    }
    media_library_json($arr);
}

/**
 * 是否有查看媒体库的权限
 * 与系统自带「附件管理」保持同一门槛（UploadMng），老版本无该权限项时退回后台登录校验
 */
function media_library_can_view()
{
    global $zbp;
    if (!$zbp->CheckRights('admin')) {
        return false; // 未登录后台
    }
    if (isset($GLOBALS['actions']['UploadMng'])) {
        return $zbp->CheckRights('UploadMng') || $zbp->CheckRights('UploadAll') || $zbp->CheckRights('root');
    }
    return true;
}

/**
 * 后台登录校验 + 附件查看权限
 */
function media_library_check_login()
{
    global $zbp;
    if (!$zbp->CheckPlugin('media_library')) {
        media_library_error('插件未启用', 48);
    }
    if (!$zbp->CheckRights('admin')) {
        media_library_error('请先登录后台', 401);
    }
    if (!media_library_can_view()) {
        media_library_error('没有查看附件的权限', 403);
    }
}

/**
 * 附件写操作权限
 */
function media_library_check_upload_rights()
{
    global $zbp;
    if (!$zbp->CheckRights('UploadAll') && !$zbp->CheckRights('root')) {
        media_library_error('没有操作附件的权限', 403);
    }
}

/**
 * CSRF 校验（POST 请求）
 */
function media_library_check_csrf()
{
    global $zbp;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    // 优先自行校验 csrfToken，失败时返回 JSON 而非系统 HTML 错误页
    $ok = false;
    if (method_exists($zbp, 'VerifyCSRFToken')) {
        $ok = $zbp->VerifyCSRFToken(GetVars('csrfToken', 'REQUEST'));
    }
    if (!$ok && function_exists('CheckCSRFTokenValid')) {
        $ok = CheckCSRFTokenValid();
    }
    if ($ok && $zbp->option['ZC_ADDITIONAL_SECURITY'] && function_exists('CheckHTTPRefererValid')) {
        $ok = CheckHTTPRefererValid();
    }
    if (!$ok) {
        media_library_error('安全校验失败，请刷新页面后重试', 403);
    }
}

/**
 * 按扩展名 / MIME 判断文件类别
 */
function media_library_kind($mime, $name)
{
    $ext = strtolower(substr($name, strrpos($name, '.') + 1));
    $mime = strtolower((string) $mime);

    if (strpos($mime, 'image') === 0) {
        return 'image';
    }
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'))) {
        return 'image';
    }

    if (strpos($mime, 'video') === 0) {
        return 'video';
    }
    if (in_array($ext, array('mp4', 'webm', 'flv', 'mov', 'avi', 'mkv'))) {
        return 'video';
    }

    if (strpos($mime, 'audio') === 0) {
        return 'audio';
    }
    if (in_array($ext, array('mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'))) {
        return 'audio';
    }

    if (in_array($ext, array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv')) ||
        strpos($mime, 'text') === 0 || strpos($mime, 'application/pdf') === 0 ||
        strpos($mime, 'application/msword') === 0 || strpos($mime, 'application/vnd') === 0) {
        return 'doc';
    }

    if (in_array($ext, array('zip', 'rar', '7z', 'gz', 'tar', 'bz2')) ||
        strpos($mime, 'application/zip') === 0 || strpos($mime, 'rar') !== false ||
        strpos($mime, '7z') !== false) {
        return 'archive';
    }

    return 'other';
}

function media_library_kind_label($kind)
{
    $map = array(
        'image' => '图片',
        'video' => '视频',
        'audio' => '音频',
        'doc' => '文档',
        'archive' => '压缩包',
        'other' => '其他',
    );
    return isset($map[$kind]) ? $map[$kind] : '其他';
}

/**
 * 按类别生成查询条件（使用系统 SQL 构造器的 where 数组）
 */
function media_library_kind_where($kind)
{
    $img = array('OR',
        array('LIKE', 'ul_MimeType', 'image%'),
        array('LIKE', 'ul_Name', '%.jpg'),
        array('LIKE', 'ul_Name', '%.jpeg'),
        array('LIKE', 'ul_Name', '%.png'),
        array('LIKE', 'ul_Name', '%.gif'),
        array('LIKE', 'ul_Name', '%.webp'),
        array('LIKE', 'ul_Name', '%.bmp'),
        array('LIKE', 'ul_Name', '%.ico'),
    );
    $vid = array('OR',
        array('LIKE', 'ul_MimeType', 'video%'),
        array('LIKE', 'ul_Name', '%.mp4'),
        array('LIKE', 'ul_Name', '%.webm'),
        array('LIKE', 'ul_Name', '%.flv'),
        array('LIKE', 'ul_Name', '%.mov'),
        array('LIKE', 'ul_Name', '%.avi'),
        array('LIKE', 'ul_Name', '%.mkv'),
    );
    $aud = array('OR',
        array('LIKE', 'ul_MimeType', 'audio%'),
        array('LIKE', 'ul_Name', '%.mp3'),
        array('LIKE', 'ul_Name', '%.wav'),
        array('LIKE', 'ul_Name', '%.ogg'),
        array('LIKE', 'ul_Name', '%.m4a'),
        array('LIKE', 'ul_Name', '%.aac'),
        array('LIKE', 'ul_Name', '%.flac'),
    );
    $doc = array('OR',
        array('LIKE', 'ul_MimeType', 'text%'),
        array('LIKE', 'ul_MimeType', 'application/pdf'),
        array('LIKE', 'ul_MimeType', 'application/msword'),
        array('LIKE', 'ul_MimeType', 'application/vnd%'),
        array('LIKE', 'ul_Name', '%.pdf'),
        array('LIKE', 'ul_Name', '%.doc'),
        array('LIKE', 'ul_Name', '%.docx'),
        array('LIKE', 'ul_Name', '%.xls'),
        array('LIKE', 'ul_Name', '%.xlsx'),
        array('LIKE', 'ul_Name', '%.ppt'),
        array('LIKE', 'ul_Name', '%.pptx'),
        array('LIKE', 'ul_Name', '%.txt'),
        array('LIKE', 'ul_Name', '%.md'),
        array('LIKE', 'ul_Name', '%.csv'),
    );
    $arc = array('OR',
        array('LIKE', 'ul_MimeType', 'application/zip%'),
        array('LIKE', 'ul_MimeType', '%rar%'),
        array('LIKE', 'ul_MimeType', '%7z%'),
        array('LIKE', 'ul_Name', '%.zip'),
        array('LIKE', 'ul_Name', '%.rar'),
        array('LIKE', 'ul_Name', '%.7z'),
        array('LIKE', 'ul_Name', '%.gz'),
        array('LIKE', 'ul_Name', '%.tar'),
        array('LIKE', 'ul_Name', '%.bz2'),
    );

    $map = array(
        'image' => $img,
        'video' => $vid,
        'audio' => $aud,
        'doc' => $doc,
        'archive' => $arc,
    );

    if ($kind == 'other') {
        // 以上所有条件均不成立
        $not = array('AND');
        foreach ($map as $group) {
            for ($i = 1; $i < count($group); $i++) {
                $not[] = array('NOT LIKE', $group[$i][1], $group[$i][2]);
            }
        }
        return $not;
    }

    return isset($map[$kind]) ? $map[$kind] : array();
}

/**
 * 取某分类（含子分类）下的文章 ID
 */
function media_library_post_ids_by_cate($cateid)
{
    global $zbp;
    $cateIds = media_library_cate_ids($cateid);
    $ids = array();
    foreach (array_chunk($cateIds, 200) as $chunk) {
        $sql = $zbp->db->sql->Select(
            $zbp->table['Post'],
            array('log_ID'),
            array(array('IN', 'log_CateID', $chunk)),
            null,
            null
        );
        $res = $zbp->db->Query($sql);
        foreach ($res as $r) {
            $v = array_values($r);
            $ids[] = (int) $v[0];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * 分类 ID => 名称 映射（单次查询，请求内缓存）
 */
function media_library_cate_name_map()
{
    global $zbp;
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = array();
    $table = isset($zbp->table['Category']) ? $zbp->table['Category'] : '%pre%category';
    $sql = $zbp->db->sql->Select($table, array('cate_ID', 'cate_Name'), null, null, null);
    $res = $zbp->db->Query($sql);
    foreach ($res as $r) {
        $v = array_values($r);
        $map[(int) $v[0]] = (string) $v[1];
    }
    return $map;
}

/**
 * 取单个分类名称
 */
function media_library_cate_name($cid)
{
    $map = media_library_cate_name_map();
    $cid = (int) $cid;
    return isset($map[$cid]) ? $map[$cid] : '';
}

/**
 * 批量取文章 ID => 分类 ID 映射
 */
function media_library_post_cate_map($logIds)
{
    global $zbp;
    $logIds = array_values(array_unique(array_filter(array_map('intval', $logIds))));
    $map = array();
    if (count($logIds) == 0) {
        return $map;
    }
    foreach (array_chunk($logIds, 500) as $chunk) {
        $sql = $zbp->db->sql->Select(
            $zbp->table['Post'],
            array('log_ID', 'log_CateID'),
            array(array('IN', 'log_ID', $chunk)),
            null,
            null
        );
        $res = $zbp->db->Query($sql);
        foreach ($res as $r) {
            $v = array_values($r);
            $map[(int) $v[0]] = (int) $v[1];
        }
    }
    return $map;
}

/**
 * 批量取文章 ID => 标题 / 分类 映射
 */
function media_library_post_info_map($logIds)
{
    global $zbp;
    $logIds = array_values(array_unique(array_filter(array_map('intval', $logIds))));
    $map = array();
    if (count($logIds) == 0) {
        return $map;
    }
    foreach (array_chunk($logIds, 500) as $chunk) {
        $sql = $zbp->db->sql->Select(
            $zbp->table['Post'],
            array('log_ID', 'log_Title', 'log_CateID'),
            array(array('IN', 'log_ID', $chunk)),
            null,
            null
        );
        $res = $zbp->db->Query($sql);
        foreach ($res as $r) {
            $v = array_values($r);
            $map[(int) $v[0]] = array('title' => (string) $v[1], 'cateid' => (int) $v[2]);
        }
    }
    return $map;
}

/**
 * 批量取用户 ID => 用户名 映射
 */
function media_library_member_name_map($uids)
{
    global $zbp;
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    $map = array();
    if (count($uids) == 0) {
        return $map;
    }
    foreach (array_chunk($uids, 500) as $chunk) {
        $sql = $zbp->db->sql->Select(
            $zbp->table['Member'],
            array('mem_ID', 'mem_Name'),
            array(array('IN', 'mem_ID', $chunk)),
            null,
            null
        );
        $res = $zbp->db->Query($sql);
        foreach ($res as $r) {
            $v = array_values($r);
            $map[(int) $v[0]] = (string) $v[1];
        }
    }
    return $map;
}

/**
 * 格式化附件记录（单条）
 */
function media_library_upload_row($u)
{
    global $zbp;

    $row = array();
    $row['id'] = (int) $u->ID;
    $row['name'] = $u->SourceName;        // 原始文件名
    $row['path'] = $u->Name;              // 相对路径
    $row['url'] = $zbp->host . $u->Name;  // 完整 URL
    $row['size'] = (int) $u->Size;
    $row['size_text'] = media_library_size_text((int) $u->Size);
    $row['mime'] = $u->MimeType;
    $row['kind'] = media_library_kind($u->MimeType, $u->Name);
    $row['kind_label'] = media_library_kind_label($row['kind']);
    $row['time'] = (int) $u->PostTime;
    $row['date_text'] = date('Y-m-d H:i', (int) $u->PostTime);
    $row['month'] = date('Y-m', (int) $u->PostTime);
    $row['authorid'] = (int) $u->AuthorID;
    $row['author'] = '';
    if (isset($zbp->members[(int) $u->AuthorID])) {
        $row['author'] = $zbp->members[(int) $u->AuthorID]->Name;
    }
    $row['logid'] = (int) $u->LogID;
    $row['intro'] = (string) $u->Intro;
    $row['title'] = '';
    $row['alt'] = '';
    $row['meta_ok'] = false;

    // 自定义域（兼容无 Meta 字段的老版本）
    if (@$u->Metas !== null) {
        $row['meta_ok'] = true;
        $row['title'] = (string) @$u->Metas->media_title;
        $row['alt'] = (string) @$u->Metas->media_alt;
    }

    // 文件是否真实存在
    $file = $zbp->path . $u->Name;
    $row['exists'] = file_exists($file) ? 1 : 0;

    // 图片尺寸
    $row['width'] = 0;
    $row['height'] = 0;
    if ($row['kind'] == 'image' && $row['exists']) {
        $info = @getimagesize($file);
        if (is_array($info)) {
            $row['width'] = (int) $info[0];
            $row['height'] = (int) $info[1];
        }
    }

    // 关联文章与分类（由查询附带，未附带时按需补齐）
    if (isset($u->ml_post_title)) {
        $row['post_title'] = (string) $u->ml_post_title;
        $row['cateid'] = (int) $u->ml_cate_id;
        $row['cate_name'] = (string) $u->ml_cate_name;
    } elseif ($row['logid'] > 0) {
        $post = $zbp->GetPostByID($row['logid']);
        if ($post->ID > 0) {
            $row['post_title'] = $post->Title;
            $row['cateid'] = (int) $post->CateID;
            $row['cate_name'] = media_library_cate_name($row['cateid']);
        }
    } else {
        $row['post_title'] = '';
        $row['cateid'] = 0;
        $row['cate_name'] = '';
    }

    return $row;
}

function media_library_size_text($size)
{
    if ($size >= 1048576) {
        return round($size / 1048576, 2) . ' MB';
    }
    if ($size >= 1024) {
        return round($size / 1024, 1) . ' KB';
    }
    return $size . ' B';
}

/**
 * 获取某分类及其全部子分类 ID
 */
function media_library_cate_ids($cateid)
{
    global $zbp;
    $cateid = (int) $cateid;
    $ids = array($cateid);

    // 从分类表读父子关系自行向下递归（不依赖 Category 对象的内部属性，避免版本差异）
    $table = isset($zbp->table['Category']) ? $zbp->table['Category'] : '%pre%category';
    $sql = $zbp->db->sql->Select($table, array('cate_ID', 'cate_ParentID'), null, null, null);
    $res = $zbp->db->Query($sql);
    $children = array();
    foreach ($res as $r) {
        $v = array_values($r);
        $children[(int) $v[1]][] = (int) $v[0];
    }

    $queue = array($cateid);
    while (count($queue) > 0) {
        $cur = array_shift($queue);
        if (!isset($children[$cur])) {
            continue;
        }
        foreach ($children[$cur] as $cid) {
            if ($cid > 0 && !in_array($cid, $ids)) {
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $ids;
}

/**
 * 构造列表查询的 WHERE 片段
 */
function media_library_build_where($p)
{
    $where = array();

    // 关键词（多字段模糊匹配）
    $kw = trim(isset($p['q']) ? $p['q'] : '');
    if ($kw !== '') {
        $where[] = array('SEARCH', 'ul_SourceName', 'ul_Name', 'ul_Intro', $kw);
    }

    // 类别
    $kind = isset($p['kind']) ? $p['kind'] : '';
    $kindWhere = media_library_kind_where($kind);
    if (count($kindWhere) > 0) {
        $where[] = $kindWhere;
    }

    // 文章分类（含子分类）
    $cateid = isset($p['cateid']) ? (int) $p['cateid'] : 0;
    if ($cateid > 0) {
        $ids = media_library_post_ids_by_cate($cateid);
        if (count($ids) == 0) {
            $where[] = array('=', 'ul_ID', 0); // 该分类下没有文章，返回空结果
        } else {
            $where[] = array('IN', 'ul_LogID', $ids);
        }
    }

    // 关联文章
    $logid = isset($p['logid']) ? (int) $p['logid'] : 0;
    if ($logid > 0) {
        $where[] = array('=', 'ul_LogID', $logid);
    }

    // 使用状态：1 = 已被文章关联；0 = 未关联任何文章
    if (isset($p['used']) && $p['used'] == '1') {
        $where[] = array('>', 'ul_LogID', 0);
    }
    if (isset($p['used']) && $p['used'] == '0') {
        $where[] = array('=', 'ul_LogID', 0);
    }

    // 上传者
    $authorid = isset($p['authorid']) ? (int) $p['authorid'] : 0;
    if ($authorid > 0) {
        $where[] = array('=', 'ul_AuthorID', $authorid);
    }

    // 上传月份（YYYY-MM）
    $month = isset($p['month']) ? trim($p['month']) : '';
    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
        $start = strtotime($month . '-01 00:00:00');
        $end = strtotime($month . '-01 00:00:00 +1 month');
        $where[] = array('AND',
            array('>=', 'ul_PostTime', (int) $start),
            array('<', 'ul_PostTime', (int) $end),
        );
    }

    // 自定义时间范围
    $from = isset($p['from']) ? trim($p['from']) : '';
    $to = isset($p['to']) ? trim($p['to']) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = array('>=', 'ul_PostTime', (int) strtotime($from . ' 00:00:00'));
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = array('<=', 'ul_PostTime', (int) strtotime($to . ' 23:59:59'));
    }

    return $where;
}

/**
 * 扫描附件表做统计（只读取必要列，PHP 侧聚合）
 */
function media_library_scan()
{
    global $zbp;
    $sql = $zbp->db->sql->Select(
        $zbp->table['Upload'],
        array('ul_ID', 'ul_LogID', 'ul_AuthorID', 'ul_PostTime', 'ul_Size', 'ul_MimeType', 'ul_Name'),
        null,
        null,
        null
    );
    $res = $zbp->db->Query($sql);

    $data = array(
        'total' => 0,
        'total_size' => 0,
        'images' => 0,
        'unused' => 0,
        'months' => array(),
        'log_counts' => array(),
        'author_counts' => array(),
    );

    foreach ($res as $r) {
        $v = array_values($r);
        $logId = (int) $v[1];
        $authorId = (int) $v[2];
        $postTime = (int) $v[3];
        $size = (int) $v[4];
        $mime = (string) $v[5];
        $name = (string) $v[6];

        $data['total']++;
        $data['total_size'] += $size;
        if (media_library_kind($mime, $name) == 'image') {
            $data['images']++;
        }
        if ($logId <= 0) {
            $data['unused']++;
        } else {
            $data['log_counts'][$logId] = isset($data['log_counts'][$logId]) ? $data['log_counts'][$logId] + 1 : 1;
        }
        if ($authorId > 0) {
            $data['author_counts'][$authorId] = isset($data['author_counts'][$authorId]) ? $data['author_counts'][$authorId] + 1 : 1;
        }
        $m = date('Y-m', $postTime);
        $data['months'][$m] = isset($data['months'][$m]) ? $data['months'][$m] + 1 : 1;
    }

    krsort($data['months']);
    arsort($data['log_counts']);
    arsort($data['author_counts']);

    return $data;
}

/**
 * 分类列表（含附件计数）
 */
function media_library_category_stats($scan)
{
    global $zbp;
    $cateMap = media_library_post_cate_map(array_keys($scan['log_counts']));
    $counts = array();
    foreach ($scan['log_counts'] as $logId => $cnt) {
        $cid = isset($cateMap[$logId]) ? (int) $cateMap[$logId] : 0;
        if ($cid <= 0) {
            continue;
        }
        $counts[$cid] = isset($counts[$cid]) ? $counts[$cid] + $cnt : $cnt;
    }
    arsort($counts);

    $out = array();
    foreach ($counts as $cid => $cnt) {
        $name = media_library_cate_name($cid);
        if ($name === '') {
            continue;
        }
        $out[] = array(
            'id' => (int) $cid,
            'name' => $name,
            'count' => (int) $cnt,
        );
    }
    return $out;
}

/**
 * 全部分类树（下拉筛选用）
 */
function media_library_categories()
{
    global $zbp;
    $list = $zbp->GetCategoryList(null, null, array('cate_Order' => 'ASC'), null, null);
    $out = array();
    foreach ($list as $c) {
        $out[] = array(
            'id' => (int) $c->ID,
            'name' => $c->Name,
            'parentid' => (int) $c->ParentID,
        );
    }
    return $out;
}

/**
 * 全部有附件的作者
 */
function media_library_authors($scan)
{
    $out = array();
    if (count($scan['author_counts']) == 0) {
        return $out;
    }
    $names = media_library_member_name_map(array_keys($scan['author_counts']));
    foreach ($scan['author_counts'] as $aid => $cnt) {
        $out[] = array(
            'id' => (int) $aid,
            'name' => isset($names[$aid]) ? $names[$aid] : '',
            'count' => (int) $cnt,
        );
    }
    return $out;
}

/**
 * 概览统计
 */
function media_library_stats()
{
    global $zbp;
    $t = $zbp->table['Upload'];

    // 先取总数，附件量极大时改用聚合查询，避免整表扫描
    $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), null);
    $res = $zbp->db->Query($sql);
    $total = 0;
    if (count($res) > 0) {
        $vals = array_values($res[0]);
        $total = (int) $vals[0];
    }

    if ($total > 50000) {
        $sql = $zbp->db->sql->Count($t, array('SUM', 'ul_Size'), null);
        $res = $zbp->db->Query($sql);
        $vals = count($res) > 0 ? array_values($res[0]) : array(0);
        $totalsize = (int) $vals[0];

        $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), media_library_kind_where('image'));
        $res = $zbp->db->Query($sql);
        $vals = count($res) > 0 ? array_values($res[0]) : array(0);
        $images = (int) $vals[0];

        $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), array(array('=', 'ul_LogID', 0)));
        $res = $zbp->db->Query($sql);
        $vals = count($res) > 0 ? array_values($res[0]) : array(0);
        $unused = (int) $vals[0];

        return array(
            'total' => $total,
            'images' => $images,
            'total_size' => $totalsize,
            'total_size_text' => media_library_size_text($totalsize),
            'unused' => $unused,
            'months' => array(),
            'category_stats' => array(),
            'authors' => array(),
            'categories' => media_library_categories(),
        );
    }

    $scan = media_library_scan();

    $monthList = array();
    $i = 0;
    foreach ($scan['months'] as $m => $c) {
        if ($i++ >= 24) {
            break;
        }
        $monthList[] = array('month' => $m, 'count' => $c);
    }

    // 附件量较大时跳过分布统计，避免拖慢后台
    $heavy = ($scan['total'] > 30000);

    return array(
        'total' => $scan['total'],
        'images' => $scan['images'],
        'total_size' => $scan['total_size'],
        'total_size_text' => media_library_size_text($scan['total_size']),
        'unused' => $scan['unused'],
        'months' => $monthList,
        'category_stats' => $heavy ? array() : media_library_category_stats($scan),
        'authors' => $heavy ? array() : media_library_authors($scan),
        'categories' => media_library_categories(),
    );
}

/**
 * 附件列表
 */
function media_library_list($p)
{
    global $zbp;

    $page = max(1, isset($p['page']) ? (int) $p['page'] : 1);
    $perpage = max(1, min(200, isset($p['perpage']) ? (int) $p['perpage'] : 48));

    // 排序白名单
    $orderby = isset($p['orderby']) ? $p['orderby'] : 'time';
    $dir = (isset($p['orderdir']) && strtolower($p['orderdir']) == 'asc') ? 'ASC' : 'DESC';
    $orderMap = array(
        'time' => array('ul_PostTime' => $dir),
        'name' => array('ul_SourceName' => $dir),
        'size' => array('ul_Size' => $dir),
        'id' => array('ul_ID' => $dir),
    );
    $order = isset($orderMap[$orderby]) ? $orderMap[$orderby] : array('ul_PostTime' => 'DESC');

    $where = media_library_build_where($p);

    // 总数
    $sql = $zbp->db->sql->Count($zbp->table['Upload'], array('COUNT', '*'), $where);
    $res = $zbp->db->Query($sql);
    $total = 0;
    if (count($res) > 0) {
        $vals = array_values($res[0]);
        $total = (int) $vals[0];
    }

    // 当前页
    $offset = ($page - 1) * $perpage;
    $sql = $zbp->db->sql->Select($zbp->table['Upload'], '*', $where, $order, array($offset, $perpage));
    $res = $zbp->db->Query($sql);

    $uploads = array();
    $logIds = array();
    foreach ($res as $r) {
        $u = new Upload();
        $u->LoadInfoByAssoc($r);
        if ((int) $u->LogID > 0) {
            $logIds[] = (int) $u->LogID;
        }
        $uploads[] = $u;
    }

    // 批量补齐关联文章与分类信息（避免逐条查询）
    $postMap = media_library_post_info_map($logIds);
    $rows = array();
    foreach ($uploads as $u) {
        $lid = (int) $u->LogID;
        if ($lid > 0 && isset($postMap[$lid])) {
            $u->ml_post_title = $postMap[$lid]['title'];
            $u->ml_cate_id = $postMap[$lid]['cateid'];
            $cid = (int) $postMap[$lid]['cateid'];
            $u->ml_cate_name = media_library_cate_name($cid);
        }
        $rows[] = media_library_upload_row($u);
    }

    return array(
        'list' => $rows,
        'total' => $total,
        'page' => $page,
        'perpage' => $perpage,
        'pages' => max(1, (int) ceil($total / $perpage)),
    );
}

/**
 * 生成落盘用的安全文件名
 * 只保留中文、字母、数字、点、下划线、连字符，其余一律替换为下划线。
 * 这样文件名不含 %、空格、引号、括号等，保证「磁盘名 == URL 路径」，避免链接 404 与特殊字符引发的问题。
 */
function media_library_safe_filename($name)
{
    $name = str_replace("\0", '', (string) $name);
    $name = basename($name);

    // 拆出扩展名并强制为纯字母数字
    $dot = strrpos($name, '.');
    $ext = '';
    if ($dot !== false) {
        $ext = strtolower(preg_replace('/[^A-Za-z0-9]/', '', substr($name, $dot + 1)));
        $name = substr($name, 0, $dot);
    }

    // 主名部分：仅保留中文 / 字母 / 数字 / . _ -
    $base = preg_replace('/[^\x{4e00}-\x{9fa5}A-Za-z0-9._-]+/u', '_', $name);
    if ($base === null) { // 非法 UTF-8 时退回纯 ASCII 处理
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    }
    $base = preg_replace('/_{2,}/', '_', $base);
    $base = trim($base, '._-');

    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($base, 'UTF-8') > 60) {
        $base = mb_substr($base, 0, 60, 'UTF-8');
    }

    if ($base === '') {
        $base = 'file';
    }

    return ($ext === '') ? $base : ($base . '.' . $ext);
}

/**
 * 生成用于展示的原始文件名（仅去控制字符，保留用户可读的原始名称）
 */
function media_library_display_name($name)
{
    $name = str_replace(array("\0", "\r", "\n", "\t"), ' ', (string) $name);
    $name = basename($name);
    $name = trim($name);
    if ($name === '') {
        $name = 'file';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($name, 'UTF-8') > 180) {
        $name = mb_substr($name, 0, 180, 'UTF-8');
    }
    return $name;
}

/**
 * 校验并规范化附件在站点内的相对路径
 * 只允许 zb_users/ 下的附件目录，拒绝绝对路径、上级跳转与代码目录，防止越权删改文件
 */
function media_library_safe_relpath($rel)
{
    $rel = str_replace('\\', '/', (string) $rel);
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
        return '';
    }
    if ($rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
        return '';
    }
    if (strpos($rel, 'zb_users/') !== 0) {
        return '';
    }
    // 禁止操作程序/缓存/日志等目录
    if (preg_match('#^zb_users/(plugin|theme|cache|logs|data|avatar)/#i', $rel)) {
        return '';
    }
    return $rel;
}

/**
 * 允许上传的扩展名 = 插件内置类型 ∩ 站点后台「允许上传的文件类型」
 * 默认不含 svg / svgz / xml / xsl / html 等可内嵌脚本的格式（避免同源 XSS）
 */
function media_library_allow_exts()
{
    global $zbp;

    $builtin = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
        'mp4', 'webm', 'flv', 'mov',
        'mp3', 'wav', 'ogg', 'm4a', 'aac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv',
        'zip', 'rar', '7z', 'gz', 'tar', 'bz2',
    );

    $site = isset($zbp->option['ZC_UPLOAD_FILETYPE']) ? (string) $zbp->option['ZC_UPLOAD_FILETYPE'] : '';
    if (trim($site) === '') {
        return $builtin;
    }

    $siteArr = preg_split('/[|,\s]+/', strtolower($site), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($siteArr)) {
        return $builtin;
    }

    $out = array();
    foreach ($builtin as $ext) {
        if (in_array($ext, $siteArr)) {
            $out[] = $ext;
        }
    }
    return $out; // 站点白名单里一个都不含时，结果为空（即拒绝上传），遵从前台设置
}

/**
 * 是否图片类扩展名（图片需做内容校验）
 */
function media_library_is_image_ext($ext)
{
    return in_array(strtolower($ext), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'));
}

/**
 * 服务器允许的单文件上限（取 upload_max_filesize 与 post_max_size 的较小值）
 */
function media_library_max_upload_size()
{
    $to = function ($v) {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }
        $unit = strtolower(substr($v, -1));
        $num = (float) $v;
        if ($unit === 'g') {
            $num *= 1024 * 1024 * 1024;
        } elseif ($unit === 'm') {
            $num *= 1024 * 1024;
        } elseif ($unit === 'k') {
            $num *= 1024;
        }
        return (int) $num;
    };
    $a = $to(ini_get('upload_max_filesize'));
    $b = $to(ini_get('post_max_size'));
    $max = 0;
    if ($a > 0 && $b > 0) {
        $max = min($a, $b);
    } elseif ($a > 0) {
        $max = $a;
    } elseif ($b > 0) {
        $max = $b;
    }
    return $max;
}

/**
 * 上传错误码 -> 人话
 */
function media_library_upload_error_text($code)
{
    $map = array(
        1 => '文件超过服务器限制（upload_max_filesize = ' . ini_get('upload_max_filesize') . '）',
        2 => '文件超过表单限制（MAX_FILE_SIZE）',
        3 => '文件只上传了一部分，请重试',
        4 => '没有选择文件',
        6 => '服务器缺少临时目录',
        7 => '服务器写入临时文件失败',
        8 => '上传被服务器扩展中断',
    );
    $code = (int) $code;
    return isset($map[$code]) ? $map[$code] : ('上传错误（代码 ' . $code . '）');
}

/**
 * 探测文件 MIME
 */
function media_library_detect_mime($file, $ext)
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = @finfo_file($fi, $file);
            @finfo_close($fi);
        }
    }
    if ($mime == '' && function_exists('mime_content_type')) {
        $mime = @mime_content_type($file);
    }
    if ($mime == '' || $mime == 'application/octet-stream') {
        $map = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'ico' => 'image/x-icon', 'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'flv' => 'video/x-flv',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf', 'txt' => 'text/plain',
            'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $ext = strtolower($ext);
        if (isset($map[$ext])) {
            $mime = $map[$ext];
        }
    }
    return $mime;
}

/**
 * 保存上传的单个文件，返回 Upload 对象
 */
function media_library_save_one($fileInfo, $logid)
{
    global $zbp;
    media_library_check_upload_rights();

    $err = isset($fileInfo['error']) ? (int) $fileInfo['error'] : 4;
    if ($err !== 0) {
        media_library_error('上传失败：' . media_library_upload_error_text($err));
    }
    if (!isset($fileInfo['tmp_name']) || !is_string($fileInfo['tmp_name']) || $fileInfo['tmp_name'] === '' || !is_uploaded_file($fileInfo['tmp_name'])) {
        media_library_error('上传失败：请检查文件是否有效（' . media_library_display_name(isset($fileInfo['name']) ? $fileInfo['name'] : '') . '）');
    }

    // 体积上限（跟随服务器 upload_max_filesize / post_max_size）
    $max = media_library_max_upload_size();
    $size = (int) (isset($fileInfo['size']) ? $fileInfo['size'] : 0);
    if ($max > 0 && $size > $max) {
        media_library_error('文件超过服务器上限 ' . media_library_size_text($max));
    }

    $disk = media_library_safe_filename($fileInfo['name']);   // 落盘名（安全字符集）
    $show = media_library_display_name($fileInfo['name']);    // 展示名（保留原始名称）
    $dot = strrpos($disk, '.');
    $ext = ($dot !== false) ? strtolower(substr($disk, $dot + 1)) : '';
    $base = ($dot !== false) ? substr($disk, 0, $dot) : $disk;
    $allow = media_library_allow_exts();
    if ($ext == '' || !in_array($ext, $allow)) {
        media_library_error('不允许上传的类型：.' . $ext . '（可在后台「网站设置 → 允许上传的文件类型」中调整）');
    }

    // 图片必须真的是图片（防止把脚本/可执行文件改名成图片上传）
    if (media_library_is_image_ext($ext)) {
        $info = @getimagesize($fileInfo['tmp_name']);
        $mime = strtolower((string) media_library_detect_mime($fileInfo['tmp_name'], $ext));
        if (!is_array($info) && strpos($mime, 'image/') !== 0) {
            media_library_error('文件内容不是有效图片：' . $show);
        }
    }

    // 上传目录：zb_users/upload/Y/m/
    $subdir = 'zb_users/upload/' . date('Y') . '/' . date('m') . '/';
    $dir = $zbp->path . $subdir;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
        media_library_error('上传目录创建失败：' . $subdir);
    }

    // 冲突处理
    $target = $base . '.' . $ext;
    if (file_exists($dir . $target)) {
        $target = $base . '_' . date('dHis') . '_' . mt_rand(100, 999) . '.' . $ext;
    }

    if (!@move_uploaded_file($fileInfo['tmp_name'], $dir . $target)) {
        media_library_error('文件保存失败，请检查目录写入权限');
    }
    @chmod($dir . $target, 0644);

    $mime = media_library_detect_mime($dir . $target, $ext);

    $u = new Upload();
    $u->Name = $subdir . $target;
    $u->SourceName = $show;
    $u->Size = (int) @filesize($dir . $target);
    $u->MimeType = $mime;
    $u->PostTime = time();
    $u->AuthorID = (int) $zbp->user->ID;
    $u->LogID = max(0, (int) $logid);
    $u->Save();

    return $u;
}

/**
 * 规范化 $_FILES 多文件结构
 */
function media_library_normalize_files($key)
{
    $out = array();
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key]) || !isset($_FILES[$key]['name'])) {
        return $out;
    }
    $f = $_FILES[$key];

    if (is_array($f['name'])) {
        $count = count($f['name']);
        for ($i = 0; $i < $count; $i++) {
            if (!isset($f['name'][$i]) || !is_string($f['name'][$i])) {
                continue; // 跳过畸形结构（如 files[name][]=x），避免后续报错
            }
            $out[] = array(
                'name' => $f['name'][$i],
                'type' => isset($f['type'][$i]) ? (string) $f['type'][$i] : '',
                'tmp_name' => isset($f['tmp_name'][$i]) ? (string) $f['tmp_name'][$i] : '',
                'error' => isset($f['error'][$i]) ? (int) $f['error'][$i] : 4,
                'size' => isset($f['size'][$i]) ? (int) $f['size'][$i] : 0,
            );
        }
    } elseif (is_string($f['name'])) {
        $out[] = array(
            'name' => $f['name'],
            'type' => isset($f['type']) ? (string) $f['type'] : '',
            'tmp_name' => isset($f['tmp_name']) ? (string) $f['tmp_name'] : '',
            'error' => isset($f['error']) ? (int) $f['error'] : 4,
            'size' => isset($f['size']) ? (int) $f['size'] : 0,
        );
    }
    return $out;
}

/**
 * 保存附件自定义信息
 */
function media_library_update_meta($u, $p)
{
    $title = (isset($p['title']) && is_string($p['title'])) ? trim($p['title']) : null;
    $alt = (isset($p['alt']) && is_string($p['alt'])) ? trim($p['alt']) : null;
    $intro = (isset($p['intro']) && is_string($p['intro'])) ? trim($p['intro']) : null;

    if ($title !== null) {
        $u->Metas->media_title = media_library_clip($title, 200);
    }
    if ($alt !== null) {
        $u->Metas->media_alt = media_library_clip($alt, 200);
    }
    if ($intro !== null) {
        $u->Intro = media_library_clip($intro, 500);
    }
}

/**
 * 字符串截断（避免超长内容写库）
 */
function media_library_clip($s, $len)
{
    $s = (string) $s;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') > $len) {
            return mb_substr($s, 0, $len, 'UTF-8');
        }
        return $s;
    }
    return strlen($s) > $len ? substr($s, 0, $len) : $s;
}

/**
 * 记录敏感操作到系统日志（便于追溯）
 */
function media_library_audit($text)
{
    if (function_exists('Logs')) {
        Logs('[media_library] ' . $text);
    }
}
