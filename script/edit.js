/* 文章编辑页「文章附件」面板脚本
 * 由 at8_media_library_edit_panel() 经 <script src> 外置引用；
 * API 地址与 CSRF 令牌经 #ml-edit-mask 的 data-api / data-token 属性注入 */
(function () {
	const maskEl = document.getElementById('ml-edit-mask');
	const API = maskEl ? maskEl.getAttribute('data-api') : '';
	const TOKEN = maskEl ? maskEl.getAttribute('data-token') : '';
	function $(id) { return document.getElementById(id); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function postId() {
		const el = $('edtID');
		const v = el ? parseInt(el.value, 10) : 0;
		return isNaN(v) ? 0 : v;
	}
	function postTitle() {
		const el = $('edtTitle');
		return el ? el.value : '';
	}
	function api(data, cb) {
		data.csrfToken = TOKEN;
		const fd = new FormData();
		for (const k in data) if (data.hasOwnProperty(k)) fd.append(k, data[k]);
		const x = new XMLHttpRequest();
		x.open('POST', API, true);
		x.onreadystatechange = function () {
			if (x.readyState !== 4) return;
			let d = null;
			try { d = JSON.parse(x.responseText); } catch (e) { }
			if (d && d.code === 0) {
				cb(d.data);
			} else {
				$('ml-edit-body').innerHTML = '<div class="mlx-err">' +
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
		const iconMap = { video: '🎬', audio: '🎵', doc: '📄', archive: '🗜️', other: '📦' };
		function iconOf(kind) { return iconMap[kind] || '📄'; }
		const imgs = [];
		const files = [];
		for (let i = 0; i < items.length; i++) {
			const it = items[i];
			if (it.kind === 'image') imgs.push(it); else files.push(it);
		}
		if (!imgs.length && !files.length) {
			$('ml-edit-body').innerHTML = '<div class="mlx-empty">本文还没有关联附件。可在「媒体库」中关联，或经编辑器上传（自动关联本文）。</div>';
			return;
		}
		function card(it, isImg) {
			const badge = (typeof it.quoted === 'undefined') ? '' :
				(it.quoted ? '<span class="mlx-quoted">✅ 已引用</span>' : '<span class="mlx-unquoted">⚠️ 未引用</span>');
			let inner;
			if (isImg) {
				inner = '<div class="mlx-card-thumb"><img src="' + esc(it.url) + '" alt=""></div>';
			} else {
				inner = '<div class="mlx-card-thumb is-file"><span class="mlx-card-icon">' + esc(iconOf(it.kind)) + '</span>'
					+ '<span class="mlx-card-type">' + esc(it.kind_label) + (it.size_text ? ' · ' + esc(it.size_text) : '') + '</span></div>';
			}
			const btn = isImg
				? '<button type="button" class="mlx-btn mlx-btn-primary mlx-card-btn" data-mode="img" data-url="' + esc(it.url) + '" data-alt="' + esc(it.alt || it.title || it.name) + '">插入正文</button>'
				: '<button type="button" class="mlx-btn mlx-btn-primary mlx-card-btn" data-mode="link" data-url="' + esc(it.url) + '" data-alt="' + esc(it.name) + '">插入链接</button>';
			return '<div class="mlx-card">'
				+ inner
				+ '<div class="mlx-card-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
				+ '<div class="mlx-card-meta"><span>' + (isImg ? esc(it.size_text) : esc(it.date_text || '')) + '</span>' + badge + '</div>'
				+ btn
				+ '</div>';
		}
		let h = '';
		if (imgs.length) {
			h += '<div class="mlx-group-title">图片（点击插入正文）</div>';
			h += '<div class="mlx-row">';
			for (let j = 0; j < imgs.length; j++) h += card(imgs[j], true);
			h += '</div>';
		}
		if (files.length) {
			h += '<div class="mlx-group-title">其他附件（点击插入下载链接）</div>';
			h += '<div class="mlx-row">';
			for (let m = 0; m < files.length; m++) h += card(files[m], false);
			h += '</div>';
		}
		$('ml-edit-body').innerHTML = h;
		const btns = $('ml-edit-body').querySelectorAll('button[data-url]');
		for (let k = 0; k < btns.length; k++) {
			btns[k].addEventListener('click', function () {
				const url = this.getAttribute('data-url');
				const alt = this.getAttribute('data-alt');
				// 二次转义：getAttribute 返回解码后的原始值，历史附件文件名可能含引号等字符，插入前必须重新转义
				if (this.getAttribute('data-mode') === 'img') {
					insertHtml('<p><img src="' + esc(url) + '" alt="' + esc(alt) + '"></p>');
				} else {
					insertHtml('<p><a href="' + esc(url) + '" target="_blank">' + esc(alt) + '</a></p>');
				}
			});
		}
	}
	function load() {
		const pid = postId();
		if (!pid) {
			$('ml-edit-modal-title').textContent = '文章附件';
			$('ml-edit-body').innerHTML = '<div class="mlx-empty">请先保存文章，再管理附件。</div>';
			return;
		}
		$('ml-edit-modal-title').textContent = '文章附件 #' + pid + ' ' + postTitle();
		$('ml-edit-body').innerHTML = '<div class="mlx-empty">加载中…</div>';
		api({ act: 'list', logid: pid, perpage: 200, orderby: 'time' }, function (d) {
			render(d.list || []);
		});
	}
	function openMask() {
		// 挂到 body 顶层：脱离右栏可能的层叠上下文，保证遮罩永远盖住全页
		if (maskEl.parentElement !== document.body) document.body.appendChild(maskEl);
		document.body.classList.add('ml-edit-modal-open');
		maskEl.classList.add('mlx-open');
		load();
	}
	function closeMask() {
		maskEl.classList.remove('mlx-open');
		document.body.classList.remove('ml-edit-modal-open');
	}
	$('ml-edit-open').addEventListener('click', function (e) {
		e.preventDefault();
		openMask();
	});
	$('ml-edit-close').addEventListener('click', closeMask);
	maskEl.addEventListener('click', function (e) {
		if (e.target === this) closeMask();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && maskEl.classList.contains('mlx-open')) closeMask();
	});
})();
