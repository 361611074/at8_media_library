<?php
# 媒体库 · 相册式附件管理 - 公共函数
# 作者：漫步白月光 https://www.at8.fun/

// 禁止直接访问本文件：必须由 Z-Blog 系统（ZBP_PATH 已定义）载入
if (!defined('ZBP_PATH')) {
    exit();
}

if (!defined('AT8_MEDIA_LIBRARY_VERSION')) {
    define('AT8_MEDIA_LIBRARY_VERSION', '1.7.1');
}

/**
 * ============================================================================
 * 架构说明（1.7.0 市场合规重构 · 1.7.1 审核收尾）
 * ============================================================================
 * 本插件只负责「媒体库体验层」：查询 / 筛选 / 搜索 / 排序 / 统计 / 预览 /
 * 灯箱 / 复制代码 / 文章关联 UI。
 *
 * 附件的上传、保存、类型校验、体积校验、命名规则、存储路径、云存储 Hook、
 * 附件记录写入、用户附件计数、附件删除，全部由 Z-BlogPHP 官方附件系统完成：
 *
 *   上传 → 官方 PostUpload()   （c_system_event.php）
 *   删除 → 官方 DelUpload()    （c_system_event.php）
 *   保存 → 官方 Upload::Save() （lib/base/upload.php）
 *
 * 插件不再自行实现任何一套附件安全 / 存储体系，也不拦截、不替代、不复制
 * 系统预留接口。详见 at8_media_library_official_upload_one() 的注释。
 * ============================================================================
 */

/**
 * 触发本插件的对外接口（官方机制：DefinePluginFilter 声明 + Add_Filter_Plugin 注册）
 * $arg 按引用传入，挂载函数声明 (&$arg) 即可修改；$context 为只读上下文，挂载函数可不接收
 */
function at8_media_library_hook($name, &$arg, $context = null)
{
    $hook = 'Filter_Plugin_' . $name;
    if (isset($GLOBALS['hooks'][$hook]) && is_array($GLOBALS['hooks'][$hook])) {
        foreach ($GLOBALS['hooks'][$hook] as $fpname => &$fpsignal) {
            if ($context === null) {
                $fpname($arg);
            } else {
                $fpname($arg, $context);
            }
        }
    } elseif (isset($GLOBALS[$hook]) && is_array($GLOBALS[$hook])) {
        // 兼容旧式 $GLOBALS['Filter_Plugin_XXX'] 直挂数组
        foreach ($GLOBALS[$hook] as $fpname => $fv) {
            $fv = is_string($fv) ? $fv : $fpname;
            if (is_callable($fv)) {
                if ($context === null) {
                    $fv($arg);
                } else {
                    $fv($arg, $context);
                }
            }
        }
    }
    return $arg;
}

/**
 * 输出 JSON 并结束
 * 响应结构：success(bool) + code(int, 0=成功) + msg + data
 * success 为通用约定的布尔结果位；code / msg 为既有前端使用的字段，保持向后兼容
 */
function at8_media_library_json($arr)
{
    if (!array_key_exists('success', $arr)) {
        $arr['success'] = (isset($arr['code']) && (int) $arr['code'] === 0);
    }
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

/**
 * 把 php.ini 的容量写法（如 "50M" / "2G" / "512K"）换算为字节数
 * 仅用于「请求体是否已达 post_max_size」的可诊断提示，不参与任何安全判定
 */
function at8_media_library_ini_bytes($val)
{
    $val = trim((string) $val);
    if ($val === '') {
        return 0;
    }
    $num = (int) $val;
    $unit = strtolower(substr($val, -1));
    if ($unit === 'g') {
        $num *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $num *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $num *= 1024;
    }
    return $num;
}

/**
 * 统一失败响应
 * $code 默认 400（请求参数错误），调用方按语义显式传入标准状态码：
 * 401 未登录 / 403 无权限 / 404 资源不存在 / 405 方法不允许 / 500 服务端能力缺失；
 * 需要插件自有业务码时显式传入（如「插件未启用」48，<400 不同步 HTTP 状态码）。
 */
function at8_media_library_error($msg, $code = 400)
{
    // 业务 code 落在标准 HTTP 状态区间（400~599）时，同步设置为 HTTP 状态码，
    // 使 WAF / 访问日志 / 监控能按标准语义识别失败请求；响应体仍保留 code / success 供前端判断。
    // headers_sent() 守卫：已知头信息已发出时静默跳过，不影响响应体
    if (!headers_sent() && is_int($code) && $code >= 400 && $code < 600) {
        http_response_code($code);
    }
    at8_media_library_json(array('success' => false, 'code' => $code, 'msg' => $msg));
}

function at8_media_library_ok($data = null)
{
    $arr = array('success' => true, 'code' => 0, 'msg' => 'ok');
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
 * 是否可管理全部附件（官方口径：UploadAll；root 兜底）
 * UploadAll 仅用于「操作/查看其他人的附件」，普通上传/删除走 UploadPst / UploadDel
 */
function at8_media_library_can_all()
{
    global $zbp;
    return $zbp->CheckRights('UploadAll') || $zbp->CheckRights('root');
}

/**
 * 通用权限校验（对齐官方 PostUpload/DelUpload 的权限项）
 * 老版本无对应权限项时退回 UploadAll 口径
 */
function at8_media_library_require_right($right)
{
    global $zbp;
    $ok = isset($GLOBALS['actions'][$right]) ? $zbp->CheckRights($right) : at8_media_library_can_all();
    if (!$ok) {
        at8_media_library_error('没有该操作的权限（' . $right . '）', 403);
    }
}

/**
 * 附件读操作范围（对齐官方 Admin_UploadMng：无 UploadAll 仅见自己的附件）
 * 服务端强制注入查询条件，不信任前端传入的任何作者参数
 */
function at8_media_library_scope_where()
{
    global $zbp;
    if (at8_media_library_can_all()) {
        return array();
    }
    return array(array('=', 'ul_AuthorID', (int) $zbp->user->ID));
}

/**
 * CSRF 校验（写操作专用，复用官方 CheckCSRFTokenValid）
 * 入口 api.php 已按动作强制 POST，此处再做一次纵深防御：非 POST 一律拒绝，
 * 避免将来新增调用点忘记方法约束时「静默跳过校验」。
 */
function at8_media_library_check_csrf()
{
    global $zbp;
    if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        at8_media_library_error('该操作必须使用 POST 请求', 405);
    }
    // 官方校验函数失败时返回 JSON 而非系统 HTML 错误页
    $ok = function_exists('CheckCSRFTokenValid') && CheckCSRFTokenValid('csrfToken', array('post'));
    // ZC_ADDITIONAL_SECURITY 在部分站点/历史版本可能不存在，需 isset 守卫避免 debug 下报 Undefined array key
    $addSec = isset($zbp->option['ZC_ADDITIONAL_SECURITY']) ? $zbp->option['ZC_ADDITIONAL_SECURITY'] : false;
    if ($ok && $addSec && function_exists('CheckHTTPRefererValid')) {
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
    // 对外接口：其他插件（云存储 / 缩略图 / CDN 类）可接管缩略图地址
    $url = at8_media_library_upload_url($u);
    $url = at8_media_library_hook('at8_media_library_Thumb', $url, $u);
    $row['url'] = $url;                               // 完整 URL
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

    // 文件存在性【只读展示，不参与任何写决策】
    // 云存储兼容口径：不能以「本地文件是否存在」判断官方附件是否丢失。
    // - 系统标准记录：URL 由系统 / 云存储 Hook 提供，一律视为可访问（exists=1）；
    //   本地文件缺失时仅标记 local_missing=1，前端显示中性提示（可能已转存云端）
    // - 早期版本的历史记录（ul_Name 里存了路径前缀，非官方标准格式）：
    //   这类记录不经官方存储流程、云插件不会接管，本地缺失即为真缺失
    // 注意：删除 / 上传 / 替换等写操作已全部交给官方附件体系，本处结果只用于界面展示。
    $file = at8_media_library_disk_path($u);
    if (at8_media_library_is_standard_name($u->Name)) {
        $row['exists'] = 1;
        $row['local_missing'] = ($file === '') ? 1 : 0;
    } else {
        $row['exists'] = ($file !== '') ? 1 : 0;
        $row['local_missing'] = 0;
    }

    // 图片尺寸【只读展示】：仅在本地确实存在该文件时读取，读取失败即 0（不报错、不影响任何流程）
    // 这不是上传校验——上传阶段不做任何图片内容检测，由官方流程裁决。
    // 请求内静态缓存，key 含 mtime 防替换后读到旧尺寸；避免同请求内重复读盘。
    $row['width'] = 0;
    $row['height'] = 0;
    if ($row['kind'] == 'image' && $file !== '' && $row['local_missing'] == 0) {
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

    // 对外接口：行数据输出前，其他插件可追加自定义字段 / 修改展示数据
    $row = at8_media_library_hook('at8_media_library_Row', $row, $u);
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
 * $scoped=true 时仅统计当前用户自己的附件（无 UploadAll 权限的数据范围）
 */
function at8_media_library_scan($scoped = false)
{
    global $zbp;
    $where = $scoped ? at8_media_library_scope_where() : array();
    $sql = $zbp->db->sql->Select(
        $zbp->table['Upload'],
        array('ul_ID', 'ul_LogID', 'ul_AuthorID', 'ul_PostTime', 'ul_Size', 'ul_MimeType', 'ul_Name'),
        $where,
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

/**
 * 缓存 Key 的站点环境前缀
 *
 * Redis / APCu 是按服务器进程共享的：同一台服务器上若并存多个 Z-BlogPHP 站点，
 * 不带站点标识的 Key（如 at8ml:stats_all）会互相命中，把 A 站的统计串给 B 站。
 * 因此所有缓存 Key 统一加站点根目录指纹；文件缓存本身在站点目录内，加了也无害。
 * （口径依据：缓存 Key 必须包含用户范围 / 权限范围 / 筛选参数 / 站点环境）
 */
function at8_media_library_cache_scope()
{
    global $zbp;
    static $scope = null;
    if ($scope !== null) {
        return $scope;
    }
    $env = '';
    if (isset($zbp) && is_object($zbp)) {
        if (isset($zbp->path) && (string) $zbp->path !== '') {
            $env = (string) $zbp->path;
        } elseif (isset($zbp->option['ZC_BLOG_HOST'])) {
            $env = (string) $zbp->option['ZC_BLOG_HOST'];
        }
    }
    $scope = substr(md5($env), 0, 12) . ':';
    return $scope;
}

function at8_media_library_cache_get($key)
{
    $key = 'at8ml:' . at8_media_library_cache_scope() . $key;

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
    $key = 'at8ml:' . at8_media_library_cache_scope() . $key;
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
    $key = 'at8ml:' . at8_media_library_cache_scope() . $key;

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
        // 必须先判存在再删：Z-Blog 的错误处理器不理会 @ 抑制，缓存文件本就不存在时
        // 直接 unlink 会在 debug 模式下每次落一条 E_WARNING（No such file or directory）。
        // stats_flush() 每次操作都会删 3 个键，缓存未生成时即触发（与 cache_get 中的写法保持一致）。
        $file = $dir . '/' . md5($key) . '.php';
        if (is_file($file)) {
            @unlink($file);
        }
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
    at8_media_library_cache_del('stats_all');
    if (isset($zbp->user) && is_object($zbp->user) && (int) $zbp->user->ID > 0) {
        at8_media_library_cache_del('stats_u' . (int) $zbp->user->ID);
    }
    if (isset($zbp->cache) && is_object($zbp->cache)) {
        $zbp->cache->at8_media_library_stats_time = 0;
        if (method_exists($zbp, 'SaveCache')) {
            $zbp->SaveCache();
        }
    }
}

/**
 * 汇总统计（按数据范围分键缓存：UploadAll 用户看全站，普通用户仅看自己的附件）
 */
function at8_media_library_stats()
{
    global $zbp;

    $scoped = !at8_media_library_can_all();
    $key = $scoped ? 'stats_u' . (int) $zbp->user->ID : 'stats_all';

    // 读缓存（优先 Redis / APCu / Opcache 文件缓存）
    $cached = at8_media_library_cache_get($key);
    if (is_array($cached) && isset($cached['total'])) {
        return at8_media_library_hook('at8_media_library_Stats', $cached);
    }

    // 兼容旧缓存：系统 cache 存储的 5 分钟缓存（仅全站口径可用）
    $legacyOk = true;
    if ($scoped) {
        $legacyOk = false; // 旧缓存是全站数据，不能泄露给无 UploadAll 用户
    }
    if ($legacyOk && isset($zbp->cache) && is_object($zbp->cache)) {
        $ts = (int) $zbp->cache->at8_media_library_stats_time;
        $raw = (string) $zbp->cache->at8_media_library_stats;
        if ($raw !== '' && $ts > 0 && (time() - $ts) < at8_media_library_stats_ttl()) {
            $legacy = @json_decode($raw, true); // JSON 存储，避免 unserialize 的对象注入面
            if (is_array($legacy) && isset($legacy['total'])) {
                return at8_media_library_hook('at8_media_library_Stats', $legacy);
            }
        }
    }

    $data = at8_media_library_stats_compute($scoped);

    // 写缓存（新缓存层 + 旧系统 cache 双写，保证任意环境下都有缓存生效）
    at8_media_library_cache_set($key, $data, at8_media_library_stats_ttl());
    if (!$scoped && isset($zbp->cache) && is_object($zbp->cache)) {
        $zbp->cache->at8_media_library_stats = (string) json_encode($data);
        $zbp->cache->at8_media_library_stats_time = time();
        if (method_exists($zbp, 'SaveCache')) {
            $zbp->SaveCache();
        }
    }

    return at8_media_library_hook('at8_media_library_Stats', $data);
}

function at8_media_library_stats_compute($scoped = false)
{
    global $zbp;
    $t = $zbp->table['Upload'];
    $scope = $scoped ? at8_media_library_scope_where() : array();

    // 先取总数，附件量极大时改用聚合查询，避免整表扫描
    $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), $scope);
    $res = $zbp->db->Query($sql);
    $total = 0;
    if (count($res) > 0) {
        $vals = array_values($res[0]);
        $total = (int) $vals[0];
    }

    if ($total > 50000) {
        $sql = $zbp->db->sql->Count($t, array('SUM', 'ul_Size'), $scope);
        $res = $zbp->db->Query($sql);
        $vals = count($res) > 0 ? array_values($res[0]) : array(0);
        $totalsize = (int) $vals[0];

        $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), array_merge($scope, at8_media_library_kind_where('image')));
        $res = $zbp->db->Query($sql);
        $vals = count($res) > 0 ? array_values($res[0]) : array(0);
        $images = (int) $vals[0];

        $sql = $zbp->db->sql->Count($t, array('COUNT', '*'), array_merge($scope, array(array('=', 'ul_LogID', 0))));
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

    $scan = at8_media_library_scan($scoped);

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
    // 服务端强制数据范围：无 UploadAll 仅见自己的附件（对齐官方 Admin_UploadMng，不信任前端参数）
    $scope = at8_media_library_scope_where();
    if (count($scope) > 0) {
        $where = array_merge($scope, $where);
    }
    // 对外接口：其他插件可追加 / 修改查询条件（如自定义筛选维度）
    $where = at8_media_library_hook('at8_media_library_ListWhere', $where, $p);

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
 * 校验并返回落在附件目录（zb_users/upload/）内的真实路径，越界返回 ''
 *
 * 【只读展示用途】仅用于列表行展示「文件状态 / 图片尺寸」，绝不参与上传、替换、删除
 * 等任何写决策——写操作全部交给官方附件体系。历史遗留记录（早期版本把
 * zb_users/upload/... 整段存入 ul_Name）需要按前缀还原 URL，故保留本函数。
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

    // 请求内静态缓存：列表每行都会调用，避免同一请求内对同一附件重复 realpath/is_file 系统调用
    static $mem = array();
    $uid = (int) $u->ID;
    $key = ($uid > 0 ? ('id' . $uid) : 'n') . '|' . (string) $u->FullFile . '|' . (string) $u->Name;
    if (array_key_exists($key, $mem)) {
        return $mem[$key];
    }

    $result = at8_media_library_disk_path_calc($u);
    $mem[$key] = $result;
    return $result;
}

/**
 * 计算附件磁盘绝对路径（供 disk_path 调用，带请求内缓存）
 */
function at8_media_library_disk_path_calc($u)
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
 * 站点「允许上传的文件类型」——仅用于前端 <input accept> 提示
 *
 * 【重要】这不是插件的安全白名单，也不是第二个判断入口。
 * 真正的上传类型安全判定只发生在官方 Upload::CheckExtName() 内部
 * （读取 ZC_UPLOAD_FILETYPE，并硬拒绝 php / phtml / phar / .htaccess / web.config）。
 * 本函数只是把官方配置原样读出来交给浏览器做「文件选择框过滤」，让用户在选文件时
 * 就能看到哪些类型可传，减少一次无谓的服务端往返；即使有人绕过前端 accept，
 * 也仍然由官方流程裁决，不存在「官方禁止 → 插件放行」的通路。
 */
function at8_media_library_site_upload_filetypes()
{
    global $zbp;
    $site = isset($zbp->option['ZC_UPLOAD_FILETYPE']) ? (string) $zbp->option['ZC_UPLOAD_FILETYPE'] : '';
    $arr = preg_split('/[|,\s]+/', strtolower(trim($site)), -1, PREG_SPLIT_NO_EMPTY);
    return is_array($arr) ? $arr : array();
}

/**
 * 站点允许的单文件上限（MB，官方 ZC_UPLOAD_FILESIZE）——仅用于前端提示文案
 * 真正的体积判定只发生在官方 Upload::CheckSize() 内部
 */
function at8_media_library_site_upload_filesize_mb()
{
    global $zbp;
    return isset($zbp->option['ZC_UPLOAD_FILESIZE']) ? (int) $zbp->option['ZC_UPLOAD_FILESIZE'] : 0;
}

/**
 * PHP 自身的上传错误码 -> 人话
 *
 * 这是「参数与 UX 层」的转述，不是插件的安全判定：文件超过 php.ini 的
 * upload_max_filesize / post_max_size 时，PHP 在进入任何 PHP 代码之前就已拒绝，
 * $_FILES['error'] 只会是 1/2，插件只是把官方/PHP 的结果翻译成人能看懂的话。
 * 真正的站点级体积上限由官方 Upload::CheckSize()（ZC_UPLOAD_FILESIZE）裁决。
 */
function at8_media_library_upload_error_text($code)
{
    $map = array(
        1 => '文件超过服务器上限（php.ini 的 upload_max_filesize = ' . ini_get('upload_max_filesize') . '）',
        2 => '文件超过表单上限（MAX_FILE_SIZE）',
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
 * 把单个上传文件交给 Z-BlogPHP 官方附件上传能力处理，返回官方生成的 Upload 对象
 *
 * ---------------------------------------------------------------------------
 * 本函数不含任何附件安全 / 存储 / 命名 / 类型判断逻辑。以下全部由官方
 * PostUpload()（zb_system/function/c_system_event.php）在其内部完成：
 *
 *   $zbp->CheckRights('UploadPst')        官方权限复核
 *   new Upload()                          官方附件对象
 *   同月重名检查（$zbp->GetUploadList）    官方命名规则
 *   $upload->CheckExtName()               官方类型安全（ZC_UPLOAD_FILETYPE +
 *                                         硬拒绝 php / phtml / phar / .htaccess / web.config）
 *   $upload->CheckSize()                  官方体积安全（ZC_UPLOAD_FILESIZE）
 *   $upload->SaveFile($tmp)               官方落盘 + Filter_Plugin_Upload_SaveFile
 *                                         （云存储 / 对象存储插件经此 Hook 接管）
 *   $upload->Save()                       官方附件记录写入
 *   $zbp->AddCache($upload)               官方对象缓存
 *   CountMemberArray(..., +1)             官方用户附件计数
 *   Filter_Plugin_PostUpload_Succeed      官方上传成功 Hook
 *
 * 唯一的适配动作：官方 PostUpload() 以 $_FILES 为输入、并以「最后一个成功项」
 * 作为返回值。媒体库需要逐文件返回结果与进度，因此这里把当前这一个文件按官方
 * 期望的单文件结构交给它，调用后立即还原 $_FILES。
 * 这不是「伪造 POST 请求」，没有 HTTP 往返、没有 include cmd.php、没有模拟浏览器，
 * 也没有绕过官方任何一道校验——所有校验仍由官方代码执行。
 *
 * 【1.7.1】作用域收窄：临时替换与还原包在 try / finally 中，正常返回、抛出
 * ZbpErrorException、或任何 Throwable 都会在 finally 里还原 $_FILES，
 * 不把临时结构残留给同请求中的其他插件；也不修改 $_POST / $_SERVER 等其它超全局。
 *
 * 官方校验失败时 ShowError() 抛出 ZbpErrorException（非 die），此处捕获后转成
 * 插件统一的 JSON 错误，不向前端回显原始异常文本 / 路径 / SQL / 堆栈。
 * ---------------------------------------------------------------------------
 *
 * @param array $fileInfo 单个 $_FILES 条目（name/type/tmp_name/error/size）
 *
 * @return Upload 官方附件对象（已写入数据库）
 */
function at8_media_library_official_upload_one($fileInfo)
{
    if (!function_exists('PostUpload')) {
        at8_media_library_error('当前 Z-BlogPHP 版本缺少官方附件上传接口，请先升级系统', 500);
    }

    $one = array(
        'name' => isset($fileInfo['name']) ? (string) $fileInfo['name'] : '',
        'type' => isset($fileInfo['type']) ? (string) $fileInfo['type'] : '',
        'tmp_name' => isset($fileInfo['tmp_name']) ? (string) $fileInfo['tmp_name'] : '',
        'error' => isset($fileInfo['error']) ? (int) $fileInfo['error'] : 4,
        'size' => isset($fileInfo['size']) ? (int) $fileInfo['size'] : 0,
    );

    $savedFiles = $_FILES;
    $_FILES = array('at8_media_library_upload' => $one);

    $caught = null;
    $u = false;
    try {
        $u = PostUpload();
    } catch (Exception $e) {
        $caught = $e;
    } catch (Throwable $e) {
        $caught = $e;
    } finally {
        // 无论正常返回、抛异常还是被官方流程中断，都在此还原全局 $_FILES
        $_FILES = $savedFiles;
    }

    if ($caught !== null) {
        at8_media_library_error(at8_media_library_official_error_text($caught), 400);
    }
    if (!is_object($u) || !($u instanceof Upload) || (int) $u->ID <= 0) {
        at8_media_library_error('上传失败：官方附件流程未生成附件记录', 400);
    }

    return $u;
}

/**
 * 把附件删除交给 Z-BlogPHP 官方附件删除能力处理
 *
 * ---------------------------------------------------------------------------
 * 官方 DelUpload()（zb_system/function/c_system_event.php）内部完成：
 *
 *   $zbp->CheckRights('UploadDel')                官方权限复核
 *   $zbp->CheckRights('UploadAll') 或 本人附件      官方所有权判定
 *   $u->Del()                                     官方附件记录删除
 *                                                 + Filter_Plugin_Upload_Del
 *   CountMemberArray(..., -1)                     官方用户附件计数
 *   $u->DelFile()                                 官方文件删除
 *                                                 + Filter_Plugin_Upload_DelFile
 *                                                 （云存储 / 对象存储插件经此 Hook 接管）
 *
 * 本函数不计算磁盘路径、不 unlink、不自行计数、不做「先删文件再删记录」之类的
 * 自定义编排，也不为失败做回滚补偿——附件生命周期完全由官方保证。
 *
 * 适配动作：官方 DelUpload() 从 $_GET['id'] 取目标附件 ID，故此处临时写入该值，
 * 调用后立即还原。这同样不是伪造 HTTP 请求，权限与所有权仍由官方重新判定。
 *
 * 【1.7.1】作用域收窄：临时写入与还原包在 try / finally 中，正常返回、抛异常或
 * 任何 Throwable 都会在 finally 里整体还原 $_GET（用整体赋值而非只删 id 键，
 * 保证还原前 $_GET 的原貌，包括本就不存在的 id 键），不污染同请求中的其他插件。
 * ---------------------------------------------------------------------------
 *
 * @param int $id 附件 ID
 *
 * @return bool true=官方删除流程完成；false=官方拒绝（无权限 / 非本人附件 / 版本不支持）
 */
function at8_media_library_official_delete_upload($id)
{
    if (!function_exists('DelUpload')) {
        return false;
    }

    $savedGet = $_GET;
    $_GET['id'] = (int) $id;

    $caught = null;
    $ok = false;
    try {
        $ok = (bool) DelUpload();
    } catch (Exception $e) {
        $caught = $e;
    } catch (Throwable $e) {
        $caught = $e;
    } finally {
        // 无论正常返回、抛异常还是被官方流程中断，都在此整体还原全局 $_GET
        $_GET = $savedGet;
    }

    if ($caught !== null) {
        // 官方 ShowError(6)（无 UploadDel 权限）等：不外抛异常文本，只记审计
        at8_media_library_audit('官方删除流程拒绝 #' . (int) $id . '（错误码 ' . (int) $caught->getCode() . '）');
        return false;
    }

    if ($ok) {
        at8_media_library_audit('经官方流程删除附件 #' . (int) $id);
    }

    return $ok;
}

/**
 * 官方附件流程的错误码 -> 插件对外文案
 *
 * 只翻译已知的官方错误码，未知错误一律给通用文案 + 错误码，
 * 避免把原始异常文本（可能含服务器路径 / SQL / 堆栈）回显给前端。
 */
function at8_media_library_official_error_text($e)
{
    $code = (int) $e->getCode();
    $map = array(
        5 => '安全校验失败，请刷新页面后重试',
        6 => '没有执行该附件操作的权限',
        26 => '不允许上传该类型文件（可在后台「网站设置 → 允许上传的文件类型」中调整）',
        27 => '文件超过站点允许的上传大小（可在后台「网站设置 → 允许上传的大小」中调整）',
        28 => '同名文件在本月内已存在，请重命名后再上传',
    );
    if (isset($map[$code])) {
        return $map[$code];
    }
    return '官方附件流程拒绝了本次操作（错误码 ' . $code . '）';
}

/**
 * 附件所有权校验：无 UploadAll 权限只能操作自己的附件
 * $strict=true 时无权限直接返回 JSON 错误；false 时返回 bool（批量操作跳过用）
 */
function at8_media_library_check_owner($u, $strict = true)
{
    if (at8_media_library_can_all() || (int) $u->AuthorID === (int) $GLOBALS['zbp']->user->ID) {
        return true;
    }
    if ($strict) {
        at8_media_library_error('只能操作自己的附件', 403);
    }
    return false;
}

/**
 * 关联文章校验：文章必须存在，且（属于自己 或 拥有 UploadAll）
 * 防止通过「关联附件 + 引用检测 / 标题回显」探测他人草稿、审核中文章的标题与正文特征
 * 返回合法的 logid（0 = 不关联）
 */
function at8_media_library_validate_logid($logid)
{
    global $zbp;
    $logid = max(0, (int) $logid);
    if ($logid == 0) {
        return 0;
    }
    $post = $zbp->GetPostByID($logid);
    if ($post->ID == 0 || (int) $post->ID !== $logid) {
        at8_media_library_error('关联的文章不存在', 400);
    }
    if (!at8_media_library_can_all() && (int) $post->AuthorID !== (int) $zbp->user->ID) {
        at8_media_library_error('只能关联到自己的文章', 403);
    }
    return $logid;
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
        Logs('[at8_media_library] ' . $text);
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

    ob_start();
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
    $html = ob_get_clean();
    // 对外接口：其他插件可修改编辑页面板输出（追加按钮、注入自定义区块等）
    $html = at8_media_library_hook('at8_media_library_EditPanel', $html);
    echo $html;
}


