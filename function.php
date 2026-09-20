<?php
# 媒体库 · 相册式附件管理 - 公共函数
# 作者：漫步白月光 https://www.at8.fun/

// 禁止直接访问本文件：必须由 Z-Blog 系统（ZBP_PATH 已定义）载入
if (!defined('ZBP_PATH')) {
    exit();
}

if (!defined('AT8_MEDIA_LIBRARY_VERSION')) {
    define('AT8_MEDIA_LIBRARY_VERSION', '1.4.1');
}

/**
 * 输出 JSON 并结束
 */
function at8_media_library_json($arr)
{
    // 对齐官方 ApiResponse() 的 @ob_clean() 思路：只清空缓冲内容、不删除任何缓冲层，
    // 保留 debug 插件等基于输出捕获的工具层；输出 JSON 后将内容逐层推送给客户端，
    // 收尾时缓冲层仍在但已空——debug 插件收尾 ob_end_clean() 有缓冲可删不会报 E_WARNING，
    // JSON 也已送达客户端，不会被其收尾清理连带丢弃
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    // ob_flush 不减少缓冲层数，按层数逐层把内容推送给客户端（缓冲层保留、内容清空）
    $n = ob_get_level();
    for (; $n > 0; $n--) {
        @ob_flush();
    }
    flush();
    die();
}

function at8_media_library_error($msg, $code = 1)
{
    at8_media_library_json(array('code' => $code, 'msg' => $msg));
}

function at8_media_library_ok($data = null)
{
    $arr = array('code' => 0, 'msg' => 'ok');
    if ($data !== null) {
        $arr['data'] = $data;
    }
    at8_media_library_json($arr);
}

/**
 * 是否有查看媒体库的权限
 * 与系统自带「附件管理」保持同一门槛（UploadMng），老版本无该权限项时退回后台登录校验
 */
function at8_media_library_can_view()
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
function at8_media_library_check_login()
{
    global $zbp;
    if (!$zbp->CheckPlugin('at8_media_library')) {
        at8_media_library_error('插件未启用', 48);
    }
    if (!$zbp->CheckRights('admin')) {
        at8_media_library_error('请先登录后台', 401);
    }
    if (!at8_media_library_can_view()) {
        at8_media_library_error('没有查看附件的权限', 403);
    }
}

/**
 * 附件写操作权限
 */
function at8_media_library_check_upload_rights()
{
    global $zbp;
    if (!$zbp->CheckRights('UploadAll') && !$zbp->CheckRights('root')) {
        at8_media_library_error('没有操作附件的权限', 403);
    }
}

/**
 * CSRF 校验（POST 请求，复用官方 CheckCSRFTokenValid）
 */
function at8_media_library_check_csrf()
{
    global $zbp;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    // 官方校验函数失败时返回 JSON 而非系统 HTML 错误页
    $ok = function_exists('CheckCSRFTokenValid') && CheckCSRFTokenValid('csrfToken', array('post'));
    if ($ok && $zbp->option['ZC_ADDITIONAL_SECURITY'] && function_exists('CheckHTTPRefererValid')) {
        $ok = CheckHTTPRefererValid();
    }
    if (!$ok) {
        at8_media_library_error('安全校验失败，请刷新页面后重试', 403);
    }
}

/**
 * 按扩展名 / MIME 判断文件类别
 */
function at8_media_library_kind($mime, $name)
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

function at8_media_library_kind_label($kind)
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
function at8_media_library_kind_where($kind)
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
function at8_media_library_post_ids_by_cate($cateid)
{
    global $zbp;
    $cateIds = at8_media_library_cate_ids($cateid);
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
function at8_media_library_cate_name_map()
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
function at8_media_library_cate_name($cid)
{
    $map = at8_media_library_cate_name_map();
    $cid = (int) $cid;
    return isset($map[$cid]) ? $map[$cid] : '';
}

/**
 * 批量取文章 ID => 分类 ID 映射
 */
function at8_media_library_post_cate_map($logIds)
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
function at8_media_library_post_info_map($logIds)
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
function at8_media_library_member_name_map($uids)
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
function at8_media_library_upload_row($u)
{
    global $zbp;

    $row = array();
    $row['id'] = (int) $u->ID;
    $row['name'] = ($u->SourceName !== '') ? $u->SourceName : basename($u->Name); // 原始文件名（老记录 SourceName 可能为空，回退磁盘名）
    $row['path'] = $u->Name;                          // 相对路径（原始存储值）
    $row['url'] = at8_media_library_upload_url($u);       // 完整 URL
    $row['size'] = (int) $u->Size;
    $row['size_text'] = at8_media_library_size_text((int) $u->Size);
    $row['mime'] = $u->MimeType;
    $row['kind'] = at8_media_library_kind($u->MimeType, $u->Name);
    $row['kind_label'] = at8_media_library_kind_label($row['kind']);
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
    $file = at8_media_library_disk_path($u);
    $row['exists'] = ($file !== '') ? 1 : 0;

    // 图片尺寸（请求内静态缓存，key 含 mtime 防替换后读到旧尺寸；避免同请求内重复读盘）
    $row['width'] = 0;
    $row['height'] = 0;
    if ($row['kind'] == 'image' && $row['exists']) {
        static $dimCache = array();
        $ck = $file . '|' . (string) @filemtime($file);
        if (!isset($dimCache[$ck])) {
            $info = @getimagesize($file);
            $dimCache[$ck] = is_array($info) ? array((int) $info[0], (int) $info[1]) : array(0, 0);
        }
        $row['width'] = $dimCache[$ck][0];
        $row['height'] = $dimCache[$ck][1];
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
            $row['cate_name'] = at8_media_library_cate_name($row['cateid']);
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
function at8_media_library_quote_match($name, $content)
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
function at8_media_library_quote_state($u)
{
    global $zbp;
    if ((int) $u->LogID <= 0) {
        return null;
    }
    $post = $zbp->GetPostByID((int) $u->LogID);
    if ($post->ID == 0 || (int) $post->ID !== (int) $u->LogID) {
        return array('checked' => 1, 'quoted' => 0, 'state' => 'missing');
    }
    $quoted = at8_media_library_quote_match($u->Name, (string) $post->Content);
    return array('checked' => 1, 'quoted' => $quoted ? 1 : 0, 'state' => $quoted ? 'quoted' : 'linked');
}

function at8_media_library_size_text($size)
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
 * 取某分类及其全部子分类 ID（复用系统常驻的 $zbp->categories）
 */
function at8_media_library_cate_ids($cateid)
{
    global $zbp;
    $cateid = (int) $cateid;
    $ids = array($cateid);

    // 由系统分类对象构建父子关系向下递归
    $children = array();
    foreach ($zbp->categories as $c) {
        $children[(int) $c->ParentID][] = (int) $c->ID;
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
function at8_media_library_build_where($p)
{
    $where = array();

    // 关键词（多字段模糊匹配）
    $kw = trim(isset($p['q']) ? $p['q'] : '');
    if ($kw !== '') {
        $where[] = array('SEARCH', 'ul_SourceName', 'ul_Name', 'ul_Intro', $kw);
    }

    // 类别
    $kind = isset($p['kind']) ? $p['kind'] : '';
    $kindWhere = at8_media_library_kind_where($kind);
    if (count($kindWhere) > 0) {
        $where[] = $kindWhere;
    }

    // 文章分类（含子分类）；cateid=none 为「未关联附件」虚拟分类（不经文章反查，直接匹配 ul_LogID=0）
    $cateid = isset($p['cateid']) ? trim((string) $p['cateid']) : '';
    if ($cateid === 'none') {
        $where[] = array('=', 'ul_LogID', 0);
    } elseif ((int) $cateid > 0) {
        $ids = at8_media_library_post_ids_by_cate((int) $cateid);
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
function at8_media_library_scan()
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
        if (at8_media_library_kind($mime, $name) == 'image') {
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
function at8_media_library_category_stats($scan)
{
    global $zbp;
    $cateMap = at8_media_library_post_cate_map(array_keys($scan['log_counts']));
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
        $name = at8_media_library_cate_name($cid);
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
 * 全部分类树（下拉筛选用，复用系统常驻的 $zbp->categories，已按 cate_Order 排序）
 */
function at8_media_library_categories()
{
    global $zbp;
    $out = array();
    foreach ($zbp->categories as $c) {
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
function at8_media_library_authors($scan)
{
    $out = array();
    if (count($scan['author_counts']) == 0) {
        return $out;
    }
    $names = at8_media_library_member_name_map(array_keys($scan['author_counts']));
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
 * 汇总数据缓存（写入/替换/删除/关联操作后由 at8_media_library_stats_flush() 失效）
 */

/**
 * ---------- 轻量运行时缓存 ----------
 * 自动探测可用组件，按优先级启用：
 *   1. Redis（ext/redis，默认 127.0.0.1:6379，可用插件配置 redis_host/redis_port/redis_auth 覆盖）
 *   2. APCu（ext/apcu 且已启用）
 *   3. Opcache 文件缓存（PHP 数组文件 + include，opcache 开启时读取走共享内存）
 * 任一层不可用自动降级，不影响功能；值一律 JSON/PHP 数组存储，不使用 unserialize。
 */
function at8_media_library_cache_redis()
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

function at8_media_library_cache_apcu()
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('apcu_enabled') && apcu_enabled();
    }
    return $ok;
}

function at8_media_library_cache_dir()
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

function at8_media_library_cache_get($key)
{
    $key = 'at8ml:' . $key;

    // 1) Redis
    $r = at8_media_library_cache_redis();
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
    if (at8_media_library_cache_apcu()) {
        $ok = false;
        $v = apcu_fetch($key, $ok);
        if ($ok && is_array($v) && array_key_exists('d', $v)) {
            return $v['d'];
        }
    }

    // 3) Opcache 文件缓存（opcache 开启时 include 命中共享内存；未开启则普通文件读）
    $dir = at8_media_library_cache_dir();
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

function at8_media_library_cache_set($key, $data, $ttl = 0)
{
    $key = 'at8ml:' . $key;
    $payload = array('_e' => ($ttl > 0 ? time() + (int) $ttl : 0), 'd' => $data);

    // 1) Redis
    $r = at8_media_library_cache_redis();
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
    if (at8_media_library_cache_apcu()) {
        @apcu_store($key, $payload, (int) $ttl);
    }

    // 3) Opcache 文件缓存（原子写入：临时文件 + rename）
    $dir = at8_media_library_cache_dir();
    if ($dir !== '') {
        // base64(JSON) 载荷：base64 字符集不含 '>'，天然免疫 PHP 结束标记提前截断；
        // 也不依赖 var_export 的引号转义（var_export 对特殊字符的转义不可靠）
        // 注意：本注释及本函数任何位置严禁出现字面 PHP 结束标记（连 // 注释里都会截断 PHP 模式）
        $code = "<?php\n// at8_media_library runtime cache\nreturn json_decode(base64_decode('"
            . base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)) . "'), true);\n";
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

function at8_media_library_cache_del($key)
{
    $key = 'at8ml:' . $key;

    $r = at8_media_library_cache_redis();
    if ($r) {
        try {
            $r->del($key);
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
    }

    if (at8_media_library_cache_apcu()) {
        @apcu_delete($key);
    }

    $dir = at8_media_library_cache_dir();
    if ($dir !== '') {
        @unlink($dir . '/' . md5($key) . '.php');
    }
}

function at8_media_library_stats_ttl()
{
    return 300; // 缓存 5 分钟
}

function at8_media_library_stats_flush()
{
    global $zbp;
    at8_media_library_cache_del('stats');
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $zbp->cache->at8_media_library_stats_time = 0;
        if (method_exists($zbp, 'SaveCache')) {
            $zbp->SaveCache();
        }
    }
}

function at8_media_library_stats()
{
    global $zbp;

    // 读缓存（优先 Redis / APCu / Opcache 文件缓存）
    $cached = at8_media_library_cache_get('stats');
    if (is_array($cached) && isset($cached['total'])) {
        return $cached;
    }

    // 兼容旧缓存：系统 cache 存储的 5 分钟缓存
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $ts = (int) $zbp->cache->at8_media_library_stats_time;
        $raw = (string) $zbp->cache->at8_media_library_stats;
        if ($raw !== '' && $ts > 0 && (time() - $ts) < at8_media_library_stats_ttl()) {
            $legacy = @json_decode($raw, true); // JSON 存储，避免 unserialize 的对象注入面
            if (is_array($legacy) && isset($legacy['total'])) {
                return $legacy;
            }
        }
    }

    $data = at8_media_library_stats_compute();

    // 写缓存（新缓存层 + 旧系统 cache 双写，保证任意环境下都有缓存生效）
    at8_media_library_cache_set('stats', $data, at8_media_library_stats_ttl());
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $zbp->cache->at8_media_library_stats = (string) json_encode($data);
        $zbp->cache->at8_media_library_stats_time = time();
        if (method_exists($zbp, 'SaveCache')) {
            $zbp->SaveCache();
        }
    }

    return $data;
}

function at8_media_library_stats_compute()
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

        $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), at8_media_library_kind_where('image'));
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
            'total_size_text' => at8_media_library_size_text($totalsize),
            'unused' => $unused,
            'months' => array(),
            'category_stats' => array(),
            'authors' => array(),
            'categories' => at8_media_library_categories(),
        );
    }

    $scan = at8_media_library_scan();

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
        'total_size_text' => at8_media_library_size_text($scan['total_size']),
        'unused' => $scan['unused'],
        'months' => $monthList,
        'category_stats' => $heavy ? array() : at8_media_library_category_stats($scan),
        'authors' => $heavy ? array() : at8_media_library_authors($scan),
        'categories' => at8_media_library_categories(),
    );
}

/**
 * 附件列表
 */
function at8_media_library_list($p)
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

    $where = at8_media_library_build_where($p);

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
    $postMap = at8_media_library_post_info_map($logIds);
    $rows = array();
    foreach ($uploads as $u) {
        $lid = (int) $u->LogID;
        if ($lid > 0 && isset($postMap[$lid])) {
            $u->ml_post_title = $postMap[$lid]['title'];
            $u->ml_cate_id = $postMap[$lid]['cateid'];
            $cid = (int) $postMap[$lid]['cateid'];
            $u->ml_cate_name = at8_media_library_cate_name($cid);
        }
        $rows[] = at8_media_library_upload_row($u);
    }

    // 按文章过滤时附带引用检测结果（正文是否实际引用该附件；正文只取一次，逐行匹配文件名）
    $logidFilter = isset($p['logid']) ? (int) $p['logid'] : 0;
    if ($logidFilter > 0) {
        $post = $zbp->GetPostByID($logidFilter);
        $content = ($post->ID > 0 && (int) $post->ID === $logidFilter) ? (string) $post->Content : '';
        foreach ($rows as $k => $row) {
            $rows[$k]['quoted'] = at8_media_library_quote_match($row['path'], $content) ? 1 : 0;
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
function at8_media_library_safe_filename($name)
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
function at8_media_library_display_name($name)
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
function at8_media_library_realpath_in_upload($path, $uploadRoot)
{
    $real = @realpath($path);
    if ($real === false || $real === '' || $uploadRoot === false || $uploadRoot === '') {
        return '';
    }
    // 前缀判断补目录分隔符，防 /upload2 之类相邻目录被误判为在 /upload 内
    $real .= DIRECTORY_SEPARATOR;
    $uploadRoot .= DIRECTORY_SEPARATOR;
    if (strpos($real, $uploadRoot) !== 0 || !@is_file(rtrim($real, DIRECTORY_SEPARATOR))) {
        return '';
    }
    return rtrim($real, DIRECTORY_SEPARATOR);
}

/**
 * 判断是否为系统标准 ul_Name 存储格式（仅文件名，无路径前缀）
 * 标准记录的 FullFile/Url/DelFile 均可直接使用系统属性
 */
function at8_media_library_is_standard_name($name)
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
function at8_media_library_disk_path($u)
{
    global $zbp;
    $uploadRoot = @realpath($zbp->usersdir . 'upload');
    if ($uploadRoot === false || $uploadRoot === '') {
        return '';
    }

    // 1) 系统标准：FullFile = usersdir + Dir + Name
    $file = at8_media_library_realpath_in_upload($u->FullFile, $uploadRoot);
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
        return at8_media_library_realpath_in_upload($zbp->path . $name, $uploadRoot);
    }
    if (strpos($name, 'upload/') === 0) {
        return at8_media_library_realpath_in_upload($zbp->usersdir . $name, $uploadRoot);
    }
    return '';
}

/**
 * 附件的访问 URL
 * 标准记录：直接使用系统 $u->Url（内置 rawurlencode 与云存储接管 hook）
 * 历史记录：按存储前缀归一为站点根相对路径后逐段 rawurlencode（修复中文/空格文件名坏链）
 */
function at8_media_library_raw_path_url($siteRelPath)
{
    global $zbp;
    $parts = explode('/', str_replace('\\', '/', ltrim((string) $siteRelPath, '/')));
    $enc = array_map('rawurlencode', $parts);
    return $zbp->host . implode('/', $enc);
}

function at8_media_library_upload_url($u)
{
    global $zbp;
    $name = str_replace('\\', '/', trim((string) $u->Name));
    $name = ltrim($name, '/');
    if (preg_match('#^https?://#i', $name)) {
        return $name; // 个别流程直接存完整 URL
    }
    if (stripos($name, 'zb_users/') === 0) {
        return at8_media_library_raw_path_url($name); // 历史格式：站点根相对
    }
    if (stripos($name, 'upload/') === 0) {
        return at8_media_library_raw_path_url('zb_users/' . $name); // 历史格式：zb_users 相对
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
function at8_media_library_allow_exts()
{
    global $zbp;

    // 硬拒绝：可执行 / 服务端脚本，任何情况下不允许
    $deny = array(
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'sh', 'bash',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'vbs', 'ps1',
        'html', 'htm', 'shtml', 'xhtml', 'js', 'mjs', 'htaccess',
        'ini', 'env',
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
function at8_media_library_is_image_ext($ext)
{
    return in_array(strtolower($ext), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'));
}

/**
 * 服务器允许的单文件上限（取 upload_max_filesize 与 post_max_size 的较小值）
 */
function at8_media_library_max_upload_size()
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
function at8_media_library_upload_error_text($code)
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
function at8_media_library_detect_mime($file, $ext)
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
function at8_media_library_save_one($fileInfo, $logid)
{
    global $zbp;
    at8_media_library_check_upload_rights();

    $err = isset($fileInfo['error']) ? (int) $fileInfo['error'] : 4;
    if ($err !== 0) {
        at8_media_library_error('上传失败：' . at8_media_library_upload_error_text($err));
    }
    if (!isset($fileInfo['tmp_name']) || !is_string($fileInfo['tmp_name']) || $fileInfo['tmp_name'] === '' || !is_uploaded_file($fileInfo['tmp_name'])) {
        at8_media_library_error('上传失败：请检查文件是否有效（' . at8_media_library_display_name(isset($fileInfo['name']) ? $fileInfo['name'] : '') . '）');
    }

    // 体积上限（跟随服务器 upload_max_filesize / post_max_size）
    $max = at8_media_library_max_upload_size();
    $size = (int) (isset($fileInfo['size']) ? $fileInfo['size'] : 0);
    if ($max > 0 && $size > $max) {
        at8_media_library_error('文件超过服务器上限 ' . at8_media_library_size_text($max));
    }

    $disk = at8_media_library_safe_filename($fileInfo['name']);   // 落盘名（安全字符集）
    $show = at8_media_library_display_name($fileInfo['name']);    // 展示名（保留原始名称）
    $dot = strrpos($disk, '.');
    $ext = ($dot !== false) ? strtolower(substr($disk, $dot + 1)) : '';
    $base = ($dot !== false) ? substr($disk, 0, $dot) : $disk;
    $allow = at8_media_library_allow_exts();
    if ($ext == '' || !in_array($ext, $allow)) {
        at8_media_library_error('不允许上传的类型：.' . $ext . '（可在后台「网站设置 → 允许上传的文件类型」中调整）');
    }

    // 防双扩展名：主名任一段为可执行脚本扩展名时拒绝（如 shell.php.jpg）
    foreach (explode('.', $base) as $seg) {
        if (preg_match('/^(php\d*|phtml|phar|pht)$/i', $seg)) {
            at8_media_library_error('文件名包含可疑的可执行扩展名段（如 .php.），请重命名后再上传');
        }
    }

    // 图片必须真的是图片（防止把脚本/可执行文件改名成图片上传）
    if (at8_media_library_is_image_ext($ext)) {
        $info = @getimagesize($fileInfo['tmp_name']);
        $mime = strtolower((string) at8_media_library_detect_mime($fileInfo['tmp_name'], $ext));
        if (!is_array($info) && strpos($mime, 'image/') !== 0) {
            at8_media_library_error('文件内容不是有效图片：' . $show);
        }
    }

    // 附件对象：存储格式对齐系统标准（ul_Name 仅存文件名，目录由对象 Dir 推导）
    $u = new Upload();
    $u->PostTime = time();
    $u->Name = $base . '.' . $ext;

    // 同名冲突处理（官方 SaveFile 不处理冲突，与系统上传一致：先改名再落盘）
    if (is_file($u->FullFile)) {
        $u->Name = $base . '_' . date('dHis') . '_' . mt_rand(100, 999) . '.' . $ext;
    }

    // 官方方法落盘：内置建目录、Windows 字符集转码，并触发 Filter_Plugin_Upload_SaveFile（云存储接管插件经此 hook 生效）
    $u->SaveFile($fileInfo['tmp_name']);

    // SaveFile 无论成败均返回 true（系统行为），落盘结果必须实际验证。
    // Windows 下磁盘名为本地字符集转码结果，需按同一规则换算后检查。
    $diskName = $u->Name;
    if (defined('PHP_SYSTEM') && PHP_SYSTEM === SYSTEM_WINDOWS && !empty($zbp->lang['windows_character_set'])) {
        $conv = @iconv('UTF-8', $zbp->lang['windows_character_set'] . '//IGNORE', $u->Name);
        if (is_string($conv) && $conv !== '') {
            $diskName = $conv;
        }
    }
    $savedPath = $zbp->usersdir . $u->Dir . $diskName;
    if (!is_file($savedPath)) {
        at8_media_library_error('文件保存失败：目录不可写，或站点「允许上传的文件类型」设置与该扩展名冲突');
    }
    @chmod($savedPath, 0644);

    $mime = at8_media_library_detect_mime($savedPath, $ext);
    $u->SourceName = $show;
    $u->Size = (int) @filesize($savedPath);
    $u->MimeType = $mime;
    $u->AuthorID = (int) $zbp->user->ID;
    $u->LogID = max(0, (int) $logid);
    $u->Save();

    return $u;
}

/**
 * 规范化 $_FILES 多文件结构
 */
function at8_media_library_normalize_files($key)
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
function at8_media_library_update_meta($u, $p)
{
    $title = (isset($p['title']) && is_string($p['title'])) ? trim($p['title']) : null;
    $alt = (isset($p['alt']) && is_string($p['alt'])) ? trim($p['alt']) : null;
    $intro = (isset($p['intro']) && is_string($p['intro'])) ? trim($p['intro']) : null;

    if ($title !== null) {
        $u->Metas->media_title = at8_media_library_clip($title, 200);
    }
    if ($alt !== null) {
        $u->Metas->media_alt = at8_media_library_clip($alt, 200);
    }
    if ($intro !== null) {
        $u->Intro = at8_media_library_clip($intro, 500);
    }
}

/**
 * 字符串截断（避免超长内容写库）
 */
function at8_media_library_clip($s, $len)
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
function at8_media_library_audit($text)
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
function at8_media_library_edit_panel()
{
    global $zbp;
    if (!at8_media_library_can_view()) {
        return;
    }
    $v = AT8_MEDIA_LIBRARY_VERSION;
    $api = $zbp->host . 'zb_users/plugin/at8_media_library/api.php';
    $css = $zbp->host . 'zb_users/plugin/at8_media_library/css/edit.css?v=' . $v;
    $js = $zbp->host . 'zb_users/plugin/at8_media_library/script/edit.js?v=' . $v;
    $token = method_exists($zbp, 'GetCSRFToken') ? $zbp->GetCSRFToken() : '';

    echo '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">' . "\n";

    echo '<div id="ml-edit-panel" class="editmod"><label class="editinputname">文章附件</label>';
    echo '<div class="ml-panel-btns">'
        . '<button type="button" class="ml-panel-btn ml-panel-btn-primary" id="ml-edit-open">'
        . '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'
        . '<span>管理 / 插入附件</span></button>'
        . '</div></div>' . "\n";


    // CSRF 令牌经 data 属性注入，由外置 script/edit.js 读取
    echo '<div id="ml-edit-mask" data-api="' . htmlspecialchars($api) . '" data-token="' . htmlspecialchars($token) . '">'
        . '<div class="mlx-modal">'
        . '<div class="mlx-modal-head">'
        . '<span class="mlx-modal-title" id="ml-edit-modal-title">文章附件</span>'
        . '<button type="button" class="mlx-modal-close" id="ml-edit-close"><span>✕</span><span>关闭</span></button>'
        . '</div>'
        . '<div class="mlx-modal-body" id="ml-edit-body">加载中…</div>'
        . '</div></div>' . "\n";
    echo '<script src="' . htmlspecialchars($js) . '"></script>' . "\n";
}


