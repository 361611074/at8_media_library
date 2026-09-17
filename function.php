<?php
# 媒体库 · 相册式附件管理 - 公共函数
# 作者：漫步白月光 https://www.at8.fun/

// 禁止直接访问本文件：必须由 Z-Blog 系统（ZBP_PATH 已定义）载入
if (!defined('ZBP_PATH')) {
    exit();
}

if (!defined('MEDIA_LIBRARY_VERSION')) {
    define('MEDIA_LIBRARY_VERSION', '1.2.7');
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
    if (!$zbp->CheckPlugin('at8_media_library')) {
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
        array('LIKE', 'ul_Name', '%.svg'),
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
    $row['name'] = $u->SourceName;                    // 原始文件名
    $row['path'] = $u->Name;                          // 相对路径（原始存储值）
    $row['url'] = media_library_upload_url($u);       // 完整 URL
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

    // 文件是否真实存在（路径由 Upload 对象推导，兼容系统上传的存储格式）
    $file = media_library_disk_path($u);
    $row['exists'] = ($file !== '') ? 1 : 0;

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

/**
 * 引用匹配：判断文章正文内容中是否出现了该附件的文件名
 * 同时匹配原始文件名与 rawurlencode 后的形式（兼容中文文件名的正文 URL）
 */
function media_library_quote_match($name, $content)
{
    $base = basename(str_replace('\\', '/', (string) $name));
    $content = (string) $content;
    if ($base === '' || $content === '') {
        return false;
    }
    if (strpos($content, $base) !== false) {
        return true;
    }
    $enc = rawurlencode($base);
    return ($enc !== $base && strpos($content, $enc) !== false);
}

/**
 * 引用检测：关联文章的正文中是否实际引用了该附件
 * 返回 null 表示未关联（无需检测）；state: quoted=已引用 linked=仅关联未引用 missing=关联文章不存在
 */
function media_library_quote_state($u)
{
    global $zbp;
    if ((int) $u->LogID <= 0) {
        return null;
    }
    $post = $zbp->GetPostByID((int) $u->LogID);
    if ($post->ID == 0 || (int) $post->ID !== (int) $u->LogID) {
        return array('checked' => 1, 'quoted' => 0, 'state' => 'missing');
    }
    $quoted = media_library_quote_match($u->Name, (string) $post->Content);
    return array('checked' => 1, 'quoted' => $quoted ? 1 : 0, 'state' => $quoted ? 'quoted' : 'linked');
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

    // 文章分类（含子分类）；cateid=none 为「未关联附件」虚拟分类（不经文章反查，直接匹配 ul_LogID=0）
    $cateid = isset($p['cateid']) ? trim((string) $p['cateid']) : '';
    if ($cateid === 'none') {
        $where[] = array('=', 'ul_LogID', 0);
    } elseif ((int) $cateid > 0) {
        $ids = media_library_post_ids_by_cate((int) $cateid);
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
 * 汇总数据缓存（写入/替换/删除/关联操作后由 media_library_stats_flush() 失效）
 */

/**
 * ---------- 轻量运行时缓存 ----------
 * 自动探测可用组件，按优先级启用：
 *   1. Redis（ext/redis，默认 127.0.0.1:6379，可用插件配置 redis_host/redis_port/redis_auth 覆盖）
 *   2. APCu（ext/apcu 且已启用）
 *   3. Opcache 文件缓存（PHP 数组文件 + include，opcache 开启时读取走共享内存）
 * 任一层不可用自动降级，不影响功能；值一律 JSON/PHP 数组存储，不使用 unserialize。
 */
function media_library_cache_redis()
{
    global $zbp;
    static $redis = null, $dead = false;
    if ($redis !== null) {
        return $redis;
    }
    if ($dead) {
        return false;
    }
    if (!extension_loaded('redis') || !class_exists('Redis')) {
        $dead = true;
        return false;
    }
    $host = '127.0.0.1';
    $port = 6379;
    $auth = '';
    if (isset($zbp) && is_object($zbp)) {
        $cfg = $zbp->Config('at8_media_library');
        if ((string) $cfg->redis_host !== '') {
            $host = (string) $cfg->redis_host;
        }
        if ((int) $cfg->redis_port > 0) {
            $port = (int) $cfg->redis_port;
        }
        $auth = (string) $cfg->redis_auth;
    }
    try {
        $r = new Redis();
        $r->connect($host, $port, 0.5, null, 0, 0.5);
        if ($auth !== '') {
            $r->auth($auth);
        }
        $r->select(0);
        $redis = $r;
    } catch (Exception $e) {
        $dead = true; // 连接失败本次运行不再重试，直接走下层
        return false;
    } catch (Throwable $e) {
        $dead = true;
        return false;
    }
    return $redis;
}

function media_library_cache_apcu()
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('apcu_enabled') && apcu_enabled();
    }
    return $ok;
}

function media_library_cache_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = dirname(__FILE__) . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = '';
        return $dir;
    }
    // 目录防护：禁止直接访问（Apache / 通用兜底）
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    $idx = $dir . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '');
    }
    return $dir;
}

function media_library_cache_get($key)
{
    $key = 'at8ml:' . $key;

    // 1) Redis
    $r = media_library_cache_redis();
    if ($r) {
        try {
            $v = $r->get($key);
            if ($v !== false && $v !== null) {
                $d = json_decode((string) $v, true);
                if (is_array($d) && array_key_exists('d', $d)) {
                    if ($d['_e'] > 0 && $d['_e'] < time()) {
                        return null; // 已过期（Redis TTL 兜底，理论到不了）
                    }
                    return $d['d'];
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
    }

    // 2) APCu（TTL 原生支持，过期自动 miss）
    if (media_library_cache_apcu()) {
        $ok = false;
        $v = apcu_fetch($key, $ok);
        if ($ok && is_array($v) && array_key_exists('d', $v)) {
            return $v['d'];
        }
    }

    // 3) Opcache 文件缓存（opcache 开启时 include 命中共享内存；未开启则普通文件读）
    $dir = media_library_cache_dir();
    if ($dir !== '') {
        $file = $dir . '/' . md5($key) . '.php';
        if (is_file($file)) {
            $d = @include $file;
            if (is_array($d) && array_key_exists('d', $d)) {
                if ($d['_e'] > 0 && $d['_e'] < time()) {
                    @unlink($file);
                    return null;
                }
                return $d['d'];
            }
        }
    }

    return null;
}

function media_library_cache_set($key, $data, $ttl = 0)
{
    $key = 'at8ml:' . $key;
    $payload = array('_e' => ($ttl > 0 ? time() + (int) $ttl : 0), 'd' => $data);

    // 1) Redis
    $r = media_library_cache_redis();
    if ($r) {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($ttl > 0) {
                $r->setex($key, (int) $ttl, $json);
            } else {
                $r->set($key, $json);
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
    }

    // 2) APCu
    if (media_library_cache_apcu()) {
        @apcu_store($key, $payload, (int) $ttl);
    }

    // 3) Opcache 文件缓存（原子写入：临时文件 + rename）
    $dir = media_library_cache_dir();
    if ($dir !== '') {
        $export = var_export($payload, true);
        $export = str_replace('?>', "?\x3E", $export); // 防止提前结束 PHP 标签
        $code = "<?php\n// at8_media_library runtime cache\nreturn " . $export . ";\n";
        $file = $dir . '/' . md5($key) . '.php';
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $code, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function media_library_cache_del($key)
{
    $key = 'at8ml:' . $key;

    $r = media_library_cache_redis();
    if ($r) {
        try {
            $r->del($key);
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
    }

    if (media_library_cache_apcu()) {
        @apcu_delete($key);
    }

    $dir = media_library_cache_dir();
    if ($dir !== '') {
        @unlink($dir . '/' . md5($key) . '.php');
    }
}

function media_library_stats_ttl()
{
    return 300; // 缓存 5 分钟
}

function media_library_stats_flush()
{
    global $zbp;
    media_library_cache_del('stats');
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $zbp->cache->media_library_stats_time = 0;
        if (method_exists($zbp, 'SaveCache')) {
            $zbp->SaveCache();
        }
    }
}

function media_library_stats()
{
    global $zbp;

    // 读缓存（优先 Redis / APCu / Opcache 文件缓存）
    $cached = media_library_cache_get('stats');
    if (is_array($cached) && isset($cached['total'])) {
        return $cached;
    }

    // 兼容旧缓存：系统 cache 存储的 5 分钟缓存
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $ts = (int) $zbp->cache->media_library_stats_time;
        $raw = (string) $zbp->cache->media_library_stats;
        if ($raw !== '' && $ts > 0 && (time() - $ts) < media_library_stats_ttl()) {
            $legacy = @json_decode($raw, true); // JSON 存储，避免 unserialize 的对象注入面
            if (is_array($legacy) && isset($legacy['total'])) {
                return $legacy;
            }
        }
    }

    $data = media_library_stats_compute();

    // 写缓存（新缓存层 + 旧系统 cache 双写，保证任意环境下都有缓存生效）
    media_library_cache_set('stats', $data, media_library_stats_ttl());
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $zbp->cache->media_library_stats = (string) json_encode($data);
        $zbp->cache->media_library_stats_time = time();
        if (method_exists($zbp, 'SaveCache')) {
            $zbp->SaveCache();
        }
    }

    return $data;
}

function media_library_stats_compute()
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

    // 按文章过滤时附带引用检测结果（正文是否实际引用该附件；正文只取一次，逐行匹配文件名）
    $logidFilter = isset($p['logid']) ? (int) $p['logid'] : 0;
    if ($logidFilter > 0) {
        $post = $zbp->GetPostByID($logidFilter);
        $content = ($post->ID > 0 && (int) $post->ID === $logidFilter) ? (string) $post->Content : '';
        foreach ($rows as $k => $row) {
            $rows[$k]['quoted'] = media_library_quote_match($row['path'], $content) ? 1 : 0;
        }
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
 * 校验并返回落在附件目录（zb_users/upload/）内的真实路径，越界返回 ''
 */
function media_library_realpath_in_upload($path, $uploadRoot)
{
    $real = @realpath($path);
    if ($real === false || $real === '' || $uploadRoot === false || $uploadRoot === '') {
        return '';
    }
    if (strpos($real, $uploadRoot) !== 0 || !@is_file($real)) {
        return '';
    }
    return $real;
}

/**
 * 判断是否为系统标准 ul_Name 存储格式（仅文件名，无路径前缀）
 * 标准记录的 FullFile/Url/DelFile 均可直接使用系统属性
 */
function media_library_is_standard_name($name)
{
    $name = str_replace('\\', '/', trim((string) $name));
    if ($name === '' || preg_match('#^https?://#i', $name)) {
        return false;
    }
    return strpos($name, '/') === false;
}

/**
 * 解析附件在磁盘上的真实路径（接收 Upload 对象）
 * 标准记录（ul_Name 仅文件名）：直接使用对象 FullFile（Dir 按上传时间推导，兼容云存储接管与系统目录设置）
 * 历史记录（本插件旧版把 zb_users/upload/... 或 upload/... 整段存入 ul_Name）：按前缀直达，不再逐层猜测
 * 找不到返回 ''；结果必须落在 zb_users/upload/ 内（realpath 包含校验，防穿越）
 */
function media_library_disk_path($u)
{
    global $zbp;
    $uploadRoot = @realpath($zbp->usersdir . 'upload');
    if ($uploadRoot === false || $uploadRoot === '') {
        return '';
    }

    // 1) 系统标准：FullFile = usersdir + Dir + Name
    $file = media_library_realpath_in_upload($u->FullFile, $uploadRoot);
    if ($file !== '') {
        return $file;
    }

    // 2) 历史格式兼容
    $name = str_replace('\\', '/', trim((string) $u->Name));
    $name = ltrim($name, '/');
    if ($name === '' || strpos($name, '..') !== false || strpos($name, "\0") !== false) {
        return '';
    }
    if (strpos($name, 'zb_users/upload/') === 0) {
        return media_library_realpath_in_upload($zbp->path . $name, $uploadRoot);
    }
    if (strpos($name, 'upload/') === 0) {
        return media_library_realpath_in_upload($zbp->usersdir . $name, $uploadRoot);
    }
    return '';
}

/**
 * 附件的访问 URL
 * 标准记录：直接使用系统 $u->Url（内置 rawurlencode 与云存储接管 hook）
 * 历史记录：按存储前缀归一为站点根相对路径后逐段 rawurlencode（修复中文/空格文件名坏链）
 */
function media_library_raw_path_url($siteRelPath)
{
    global $zbp;
    $parts = explode('/', str_replace('\\', '/', ltrim((string) $siteRelPath, '/')));
    $enc = array_map('rawurlencode', $parts);
    return $zbp->host . implode('/', $enc);
}

function media_library_upload_url($u)
{
    global $zbp;
    $name = str_replace('\\', '/', trim((string) $u->Name));
    $name = ltrim($name, '/');
    if (preg_match('#^https?://#i', $name)) {
        return $name; // 个别流程直接存完整 URL
    }
    if (stripos($name, 'zb_users/') === 0) {
        return media_library_raw_path_url($name); // 历史格式：站点根相对
    }
    if (stripos($name, 'upload/') === 0) {
        return media_library_raw_path_url('zb_users/' . $name); // 历史格式：zb_users 相对
    }
    return $u->Url; // 标准格式：系统属性
}

/**
 * 允许上传的扩展名（跟随站点后台「允许上传的文件类型」设置）
 * - 站点设置了白名单 → 以站点为准（zba / apk 等站点允许的类型均可上传）
 * - 站点未设置 → 使用插件内置类型
 * - 服务端可执行脚本（php/exe/js/html 等）无论何时都拒绝
 * - svg / svgz / xml / xsl / swf 等可内嵌脚本的格式默认排除，站点明确允许时才放行
 */
function media_library_allow_exts()
{
    global $zbp;

    // 硬拒绝：可执行 / 服务端脚本，任何情况下不允许
    $deny = array(
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'vbs', 'ps1',
        'html', 'htm', 'shtml', 'xhtml', 'js', 'mjs', 'htaccess',
    );

    // 硬排除的可内嵌脚本格式：无论站点是否允许都不经本插件上传（防同源 XSS），
    // 系统自带附件管理不受影响，如需 svg 可走系统上传
    $risky = array('svg', 'svgz', 'xml', 'xsl', 'xslt', 'swf');

    $builtin = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'heic', 'avif', 'tif', 'tiff',
        'mp4', 'webm', 'flv', 'mov', 'avi', 'mkv', 'wmv', 'm4v',
        'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'epub',
        'zip', 'rar', '7z', 'gz', 'tar', 'bz2', 'zba', 'apk', 'iso',
    );

    $site = isset($zbp->option['ZC_UPLOAD_FILETYPE']) ? (string) $zbp->option['ZC_UPLOAD_FILETYPE'] : '';
    $siteArr = preg_split('/[|,\s]+/', strtolower(trim($site)), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($siteArr) || count($siteArr) === 0) {
        $siteArr = $builtin; // 站点未设置白名单时使用内置类型
    }

    $out = array();
    foreach ($siteArr as $ext) {
        if (in_array($ext, $deny) || in_array($ext, $risky)) {
            continue;
        }
        $out[] = $ext;
    }
    return $out;
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

    // 附件对象：存储格式对齐系统标准（ul_Name 仅存文件名，目录由对象 Dir 推导）
    $u = new Upload();
    $u->PostTime = time();
    $u->Name = $base . '.' . $ext;
    $dirAbs = $zbp->usersdir . $u->Dir; // Dir 由对象按上传时间推导（含 ZC_UPLOAD_DIR_* 与云存储接管 hook）
    if (!is_dir($dirAbs)) {
        @mkdir($dirAbs, 0755, true);
    }
    if (!is_dir($dirAbs)) {
        media_library_error('上传目录创建失败：' . $u->Dir);
    }

    // Windows 主机按系统字符集转码落盘（与系统 SaveFile 行为一致，DB 中仍存 UTF-8 名）
    $toDiskName = function ($name) use ($zbp) {
        if (defined('PHP_SYSTEM') && PHP_SYSTEM === SYSTEM_WINDOWS && !empty($zbp->lang['windows_character_set'])) {
            $conv = @iconv('UTF-8', $zbp->lang['windows_character_set'] . '//IGNORE', $name);
            if (is_string($conv) && $conv !== '') {
                return $conv;
            }
        }
        return $name;
    };

    // 同名冲突处理
    if (file_exists($dirAbs . $toDiskName($u->Name))) {
        $u->Name = $base . '_' . date('dHis') . '_' . mt_rand(100, 999) . '.' . $ext;
    }

    if (!@move_uploaded_file($fileInfo['tmp_name'], $dirAbs . $toDiskName($u->Name))) {
        media_library_error('文件保存失败，请检查目录写入权限');
    }
    @chmod($dirAbs . $toDiskName($u->Name), 0644);

    $mime = media_library_detect_mime($dirAbs . $toDiskName($u->Name), $ext);
    $u->SourceName = $show;
    $u->Size = (int) @filesize($dirAbs . $toDiskName($u->Name));
    $u->MimeType = $mime;
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

/**
 * 文章/页面编辑页右栏「文章配图」面板（Filter_Plugin_Edit_Response3）
 * 弹窗内列出关联到本文的图片并支持一键插入编辑器正文
 * （经 editor_api.editor.content.insert 官方接口，兼容 UEditor 等全部编辑器）
 */
function media_library_edit_panel()
{
    global $zbp;
    if (!media_library_can_view()) {
        return;
    }
    $api = $zbp->host . 'zb_users/plugin/at8_media_library/api.php';
    $css = $zbp->host . 'zb_users/plugin/at8_media_library/css/style.css?v=' . MEDIA_LIBRARY_VERSION;
    $token = method_exists($zbp, 'GetCSRFToken') ? $zbp->GetCSRFToken() : '';
    $safe = array(JSON_UNESCAPED_UNICODE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $apiJs = json_encode($api, $safe[0] | $safe[1]);
    $tokenJs = json_encode($token, $safe[0] | $safe[1]);
    $cssHtml = htmlspecialchars($css);

    echo '<link rel="stylesheet" href="' . $cssHtml . '">' . "\n";
    echo '<div id="ml-edit-panel" class="editmod"><label class="editinputname">文章附件</label>';
    echo '<div class="ml-panel-btns">'
        . '<button type="button" class="ml-panel-btn ml-panel-btn-primary" id="ml-edit-open">'
        . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'
        . '<span>管理 / 插入附件</span></button>'
        . '</div></div>' . "\n";

    // 弹窗样式独立作用域（ID 选择器 + 显式四边定位 + 高 z-index），不依赖后台环境样式
    echo '<style id="ml-edit-style">' . "\n"
        . '#ml-edit-panel .ml-panel-btns{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;margin-top:6px}' . "\n"
        . '#ml-edit-panel .ml-panel-btn{display:inline-flex;align-items:center;gap:6px;box-sizing:border-box;font-family:inherit;font-size:13px;line-height:1.4;padding:7px 14px;border-radius:8px;border:1px solid #d7deeb;background:#fff;color:#3b4a5f;cursor:pointer;text-decoration:none;transition:all .15s;vertical-align:middle}' . "\n"
        . '#ml-edit-panel .ml-panel-btn:hover{border-color:#2a5cf5;color:#2a5cf5;text-decoration:none}' . "\n"
        . '#ml-edit-panel .ml-panel-btn-primary{background:#2a5cf5;border-color:#2a5cf5;color:#fff;box-shadow:0 2px 8px rgba(42,92,245,.30)}' . "\n"
        . '#ml-edit-panel .ml-panel-btn-primary:hover{background:#1f4ae0;color:#fff;box-shadow:0 4px 12px rgba(42,92,245,.38)}' . "\n"
        . '#ml-edit-panel .ml-panel-btn svg{flex:0 0 auto}' . "\n"
        . '#ml-edit-mask{position:fixed;top:0;right:0;bottom:0;left:0;width:100vw;height:100vh;background:rgba(9,14,25,.55);z-index:2147483000;display:none;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}' . "\n"
        . '#ml-edit-mask.mlx-open{display:flex}' . "\n"
        . 'body.ml-edit-modal-open [class*="toolbarbox"]{visibility:hidden}' . "\n"
        . '#ml-edit-mask .mlx-modal{display:flex;flex-direction:column;background:#fff;border-radius:14px;width:780px;max-width:96vw;max-height:86vh;box-shadow:0 24px 70px rgba(9,14,25,.35);overflow:hidden;font-family:inherit}' . "\n"
        . '#ml-edit-mask .mlx-modal-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 18px;background:#f7f9fd;border-bottom:1px solid #eef1f7;font-size:15px;font-weight:700;color:#1f2d3d}' . "\n"
        . '#ml-edit-mask .mlx-modal-title{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}' . "\n"
        . '#ml-edit-mask .mlx-modal-close{flex:0 0 auto;display:inline-flex;align-items:center;gap:4px;font-family:inherit;font-size:12px;line-height:1;padding:6px 10px;border-radius:6px;border:1px solid #d7deeb;background:#fff;color:#5b6b80;cursor:pointer;transition:all .15s}' . "\n"
        . '#ml-edit-mask .mlx-modal-close:hover{border-color:#e5484d;color:#e5484d;background:#fef2f2}' . "\n"
        . '#ml-edit-mask .mlx-modal-body{flex:1 1 auto;padding:14px 18px;font-size:13px;color:#3b4a5f;overflow:auto}' . "\n"
        . '#ml-edit-mask .mlx-btn{display:inline-block;font-family:inherit;font-size:12px;line-height:1.4;padding:5px 12px;border-radius:6px;border:1px solid #d7deeb;background:#fff;color:#3b4a5f;cursor:pointer;transition:all .15s}' . "\n"
        . '#ml-edit-mask .mlx-btn:hover{border-color:#2a5cf5;color:#2a5cf5}' . "\n"
        . '#ml-edit-mask .mlx-btn-primary{background:#2a5cf5;border-color:#2a5cf5;color:#fff}' . "\n"
        . '#ml-edit-mask .mlx-btn-primary:hover{background:#1f4ae0;color:#fff}' . "\n"
        . '</style>' . "\n";

    echo '<div id="ml-edit-mask">'
        . '<div class="mlx-modal">'
        . '<div class="mlx-modal-head">'
        . '<span class="mlx-modal-title" id="ml-edit-modal-title">文章附件</span>'
        . '<button type="button" class="mlx-modal-close" id="ml-edit-close"><span>✕</span><span>关闭</span></button>'
        . '</div>'
        . '<div class="mlx-modal-body" id="ml-edit-body">加载中…</div>'
        . '</div></div>' . "\n";
    ?>
<script>
(function () {
	var API = <?php echo $apiJs; ?>;
	var TOKEN = <?php echo $tokenJs; ?>;
	function $(id) { return document.getElementById(id); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function postId() {
		var el = $('edtID');
		var v = el ? parseInt(el.value, 10) : 0;
		return isNaN(v) ? 0 : v;
	}
	function postTitle() {
		var el = $('edtTitle');
		return el ? el.value : '';
	}
	function api(data, cb) {
		data.csrfToken = TOKEN;
		var fd = new FormData();
		for (var k in data) if (data.hasOwnProperty(k)) fd.append(k, data[k]);
		var x = new XMLHttpRequest();
		x.open('POST', API, true);
		x.onreadystatechange = function () {
			if (x.readyState !== 4) return;
			var d = null;
			try { d = JSON.parse(x.responseText); } catch (e) { }
			if (d && d.code === 0) {
				cb(d.data);
			} else {
				$('ml-edit-body').innerHTML = '<div style="padding:14px;color:#c0392b">' +
					esc((d && d.msg) || ('请求失败（HTTP ' + x.status + '）')) + '</div>';
			}
		};
		x.send(fd);
	}
	function insertHtml(html) {
		try {
			if (window.editor_api && editor_api.editor && editor_api.editor.content && editor_api.editor.content.insert) {
				editor_api.editor.content.insert(html);
			} else if (window.UE && UE.getEditor) {
				UE.getEditor('editor_content').execCommand('inserthtml', html);
			} else {
				alert('未找到编辑器插入接口，请通过「复制 URL」手动插入');
			}
		} catch (e) {
			alert('插入失败：' + e.message);
		}
	}
	function render(items) {
		var iconMap = { video: '🎬', audio: '🎵', doc: '📄', archive: '🗜️', other: '📦' };
		function iconOf(kind) { return iconMap[kind] || '📄'; }
		var imgs = [], files = [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'image') imgs.push(items[i]); else files.push(items[i]);
		}
		if (!imgs.length && !files.length) {
			$('ml-edit-body').innerHTML = '<div style="padding:18px;text-align:center;color:#93a1b5">本文还没有关联附件。可在「媒体库」中关联，或经编辑器上传（自动关联本文）。</div>';
			return;
		}
		function card(it, isImg) {
			var badge = (typeof it.quoted === 'undefined') ? '' :
				(it.quoted ? '<span style="color:#1a9e55">✅ 已引用</span>' : '<span style="color:#c07f00">⚠️ 未引用</span>');
			var inner;
			if (isImg) {
				inner = '<div style="height:84px;overflow:hidden;border-radius:6px;background:#f3f6fb;text-align:center">'
					+ '<img src="' + esc(it.url) + '" style="max-width:100%;max-height:84px" alt=""></div>';
			} else {
				inner = '<div style="height:84px;overflow:hidden;border-radius:6px;background:#f3f6fb;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px">'
					+ '<span style="font-size:22px;line-height:1">' + esc(iconOf(it.kind)) + '</span>'
					+ '<span style="font-size:11px;color:#5b6b80">' + esc(it.kind_label) + (it.size_text ? ' · ' + esc(it.size_text) : '') + '</span></div>';
			}
			var btn = isImg
				? '<button type="button" class="mlx-btn mlx-btn-primary" style="margin-top:5px;width:100%" data-mode="img" data-url="' + esc(it.url) + '" data-alt="' + esc(it.alt || it.title || it.name) + '">插入正文</button>'
				: '<button type="button" class="mlx-btn mlx-btn-primary" style="margin-top:5px;width:100%" data-mode="link" data-url="' + esc(it.url) + '" data-alt="' + esc(it.name) + '">插入链接</button>';
			return '<div style="width:150px;border:1px solid #e3e9f2;border-radius:8px;padding:6px;box-sizing:border-box">'
				+ inner
				+ '<div style="font-size:12px;margin:5px 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
				+ '<div style="font-size:11px;color:#93a1b5;display:flex;justify-content:space-between"><span>' + (isImg ? esc(it.size_text) : esc(it.date_text || '')) + '</span>' + badge + '</div>'
				+ btn
				+ '</div>';
		}
		var h = '';
		if (imgs.length) {
			h += '<div style="padding:2px 6px 6px;font-size:12px;font-weight:700;color:#5b6b80">图片（点击插入正文）</div>';
			h += '<div style="display:flex;flex-wrap:wrap;gap:10px;padding:4px 0">';
			for (var j = 0; j < imgs.length; j++) h += card(imgs[j], true);
			h += '</div>';
		}
		if (files.length) {
			h += '<div style="padding:8px 6px 6px;font-size:12px;font-weight:700;color:#5b6b80">其他附件（点击插入下载链接）</div>';
			h += '<div style="display:flex;flex-wrap:wrap;gap:10px;padding:4px 0">';
			for (var m = 0; m < files.length; m++) h += card(files[m], false);
			h += '</div>';
		}
		$('ml-edit-body').innerHTML = h;
		var btns = $('ml-edit-body').querySelectorAll('button[data-url]');
		for (var k = 0; k < btns.length; k++) {
			btns[k].addEventListener('click', function () {
				var url = this.getAttribute('data-url');
				var alt = this.getAttribute('data-alt');
				if (this.getAttribute('data-mode') === 'img') {
					insertHtml('<p><img src="' + url + '" alt="' + alt + '"></p>');
				} else {
					insertHtml('<p><a href="' + url + '" target="_blank">' + alt + '</a></p>');
				}
			});
		}
	}
	function load() {
		var pid = postId();
		if (!pid) {
			$('ml-edit-modal-title').textContent = '文章附件';
			$('ml-edit-body').innerHTML = '<div style="padding:18px;text-align:center;color:#93a1b5">请先保存文章，再管理附件。</div>';
			return;
		}
		$('ml-edit-modal-title').textContent = '文章附件 #' + pid + ' ' + postTitle();
		$('ml-edit-body').innerHTML = '<div style="padding:18px;text-align:center;color:#93a1b5">加载中…</div>';
		api({ act: 'list', logid: pid, perpage: 200, orderby: 'time' }, function (d) {
			render(d.list || []);
		});
	}
	function openMask() {
		var mask = $('ml-edit-mask');
		// 挂到 body 顶层：脱离右栏可能的层叠上下文，保证遮罩永远盖住全页
		if (mask.parentElement !== document.body) document.body.appendChild(mask);
		document.body.classList.add('ml-edit-modal-open');
		mask.classList.add('mlx-open');
		load();
	}
	function closeMask() {
		$('ml-edit-mask').classList.remove('mlx-open');
		document.body.classList.remove('ml-edit-modal-open');
	}
	$('ml-edit-open').addEventListener('click', function (e) {
		e.preventDefault();
		openMask();
	});
	$('ml-edit-close').addEventListener('click', closeMask);
	$('ml-edit-mask').addEventListener('click', function (e) {
		if (e.target === this) closeMask();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && $('ml-edit-mask').classList.contains('mlx-open')) closeMask();
	});
})();
</script>
	<?php
}

