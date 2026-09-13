<?php
# 媒体库 · 后台管理页
# 作者：漫步白月光 https://www.at8.fun/

require dirname(__FILE__) . '/../../../zb_system/function/c_system_base.php';
require dirname(__FILE__) . '/../../../zb_system/function/c_system_admin.php';
require_once dirname(__FILE__) . '/function.php';

$zbp->Load();

if (!$zbp->CheckPlugin('media_library')) {$zbp->ShowError(48);die();}
if (!$zbp->CheckRights('admin')) {$zbp->ShowError(6);die();}
if (!media_library_can_view()) {$zbp->ShowError(6);die();}

$blogtitle = '媒体库 · 相册式附件管理';

// 需要传递给前端的配置
$ml_config = array(
    'api' => $zbp->host . 'zb_users/plugin/media_library/api.php',
    'host' => $zbp->host,
    'csrfToken' => method_exists($zbp, 'GetCSRFToken') ? $zbp->GetCSRFToken() : (function_exists('csrfToken') ? csrfToken() : ''),
    'canUpload' => ($zbp->CheckRights('UploadAll') || $zbp->CheckRights('root')) ? 1 : 0,
    'perpage' => 48,
    'user' => $zbp->user->Name,
);
$ml_config_json = json_encode($ml_config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require $blogpath . 'zb_system/admin/admin_header.php';
require $blogpath . 'zb_system/admin/admin_top.php';
?>
<link rel="stylesheet" href="<?php echo $zbp->host; ?>zb_users/plugin/media_library/css/style.css?v=<?php echo MEDIA_LIBRARY_VERSION; ?>">
<div id="divMain" class="mlx-root">
	<div class="mlx-wrap">
		<div class="mlx-header">
			<h1 class="mlx-title">媒体库</h1>
			<div class="mlx-stats" id="ml-stats">
				<span class="mlx-stat"><b id="ml-st-total">-</b> 全部附件</span>
				<span class="mlx-stat"><b id="ml-st-img">-</b> 图片</span>
				<span class="mlx-stat"><b id="ml-st-size">-</b> 占用空间</span>
				<span class="mlx-stat"><b id="ml-st-unused">-</b> 未关联文章</span>
			</div>
			<div class="mlx-header-actions">
				<?php if ($zbp->CheckRights('UploadAll') || $zbp->CheckRights('root')) { ?>
				<button type="button" class="mlx-btn mlx-btn-primary" id="ml-btn-upload">上传文件</button>
				<?php } ?>
				<a class="mlx-btn" href="<?php echo $zbp->host; ?>zb_system/admin/index.php?act=UploadMng">传统列表视图</a>
			</div>
		</div>

		<div class="mlx-toolbar">
			<input type="text" class="mlx-input mlx-search" id="ml-q" placeholder="搜索文件名 / 说明…">
			<select class="mlx-input" id="ml-kind">
				<option value="">全部类型</option>
				<option value="image">图片</option>
				<option value="video">视频</option>
				<option value="audio">音频</option>
				<option value="doc">文档</option>
				<option value="archive">压缩包</option>
				<option value="other">其他</option>
			</select>
			<select class="mlx-input" id="ml-cate"><option value="">全部分类</option></select>
			<select class="mlx-input" id="ml-month"><option value="">全部月份</option></select>
			<select class="mlx-input" id="ml-author"><option value="">全部上传者</option></select>
			<select class="mlx-input" id="ml-used">
				<option value="">使用状态</option>
				<option value="1">已关联文章</option>
				<option value="0">未关联文章</option>
			</select>
			<select class="mlx-input" id="ml-orderby">
				<option value="time">按时间</option>
				<option value="name">按名称</option>
				<option value="size">按大小</option>
			</select>
			<select class="mlx-input mlx-mini" id="ml-orderdir">
				<option value="desc">降序</option>
				<option value="asc">升序</option>
			</select>
			<span class="mlx-spacer"></span>
			<span class="mlx-view-switch">
				<button type="button" class="mlx-vs-btn active" data-view="grid" title="网格视图">▦</button>
				<button type="button" class="mlx-vs-btn" data-view="list" title="列表视图">☰</button>
			</span>
			<select class="mlx-input mlx-mini" id="ml-perpage">
				<option value="24">24 / 页</option>
				<option value="48" selected>48 / 页</option>
				<option value="96">96 / 页</option>
				<option value="200">200 / 页</option>
			</select>
		</div>

		<div class="mlx-bulkbar" id="ml-bulkbar" style="display:none">
			<span>已选 <b id="ml-sel-count">0</b> 项</span>
			<button type="button" class="mlx-btn mlx-btn-sm" id="ml-btn-selall">全选本页</button>
			<button type="button" class="mlx-btn mlx-btn-sm" id="ml-btn-clearsel">取消选择</button>
			<span class="mlx-spacer"></span>
			<button type="button" class="mlx-btn mlx-btn-sm" id="ml-btn-bulkbind">批量关联文章</button>
			<button type="button" class="mlx-btn mlx-btn-sm mlx-btn-danger" id="ml-btn-bulkdel">批量删除</button>
		</div>

		<div class="mlx-grid" id="ml-grid"></div>
		<div class="mlx-empty" id="ml-empty" style="display:none">
			<p class="mlx-empty-icon">🗂</p>
			<p>没有找到符合条件的附件</p>
			<p class="mlx-empty-sub">试试调整筛选条件，或拖拽文件到此页面上传</p>
		</div>
		<div class="mlx-loading" id="ml-loading" style="display:none">加载中…</div>

		<div class="mlx-pager" id="ml-pager"></div>
		<div class="mlx-sentinel" id="ml-sentinel"></div>
	</div>
</div>

<!-- 详情侧栏 -->
<div class="mlx-drawer-mask" id="ml-drawer-mask"></div>
<aside class="mlx-drawer" id="ml-drawer"></aside>

<!-- 上传面板 -->
<div class="mlx-upload-panel" id="ml-upload-panel">
	<div class="mlx-up-head">
		<b>上传文件</b>
		<select class="mlx-input mlx-mini" id="ml-upload-logid">
			<option value="0">不关联文章</option>
		</select>
		<span class="mlx-spacer"></span>
		<button type="button" class="mlx-btn mlx-btn-sm" id="ml-up-close">关闭</button>
	</div>
	<div class="mlx-up-drop" id="ml-up-drop">
		<p>点击选择文件，或拖拽到此处</p>
		<p class="mlx-up-sub">支持图片、视频、音频、文档、压缩包，可多选</p>
		<input type="file" id="ml-up-input" multiple style="display:none">
	</div>
	<div class="mlx-up-list" id="ml-up-list"></div>
</div>

<!-- 灯箱 -->
<div class="mlx-lightbox" id="ml-lightbox">
	<div class="mlx-lb-inner">
		<img id="ml-lb-img" src="" alt="">
		<div class="mlx-lb-caption" id="ml-lb-caption"></div>
	</div>
	<button type="button" class="mlx-lb-btn mlx-lb-prev" id="ml-lb-prev">‹</button>
	<button type="button" class="mlx-lb-btn mlx-lb-next" id="ml-lb-next">›</button>
	<button type="button" class="mlx-lb-btn mlx-lb-close" id="ml-lb-close">×</button>
</div>

<script>window.ML = <?php echo $ml_config_json; ?>;</script>
<script src="<?php echo $zbp->host; ?>zb_users/plugin/media_library/script/app.js?v=<?php echo MEDIA_LIBRARY_VERSION; ?>"></script>
<script>if (typeof ActiveLeftMenu == "function") { ActiveLeftMenu("nav_media_library"); }</script>
<?php
require $blogpath . 'zb_system/admin/admin_footer.php';
RunTime();
