/**
 * 媒体库 · 相册式附件管理
 * 作者：漫步白月光 https://www.at8.fun/
 * 原生 JS，无外部依赖
 */
(function () {
	'use strict';

	let state = {
		view: 'grid',
		page: 1,
		perpage: AT8ML.perpage || 48,
		q: '',
		kind: '',
		cateid: '',
		month: '',
		authorid: '',
		used: '',
		orderby: 'time',
		orderdir: 'desc',
		total: 0,
		pages: 1,
		list: [],
		selected: {},      // id -> item
		current: null,     // 抽屉当前附件
		lightboxIndex: -1,
		lightboxImgs: [],
		bindTarget: null,  // 批量关联目标文章
		statsLoaded: false
	};

	// ---------- 工具 ----------
	function $(id) { return document.getElementById(id); }

	function el(tag, cls, text) {
		let e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = text;
		return e;
	}

	function esc(s) {
		return String(s === null || s === undefined ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function debounce(fn, ms) {
		let t = null;
		return function () {
			let args = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, args); }, ms);
		};
	}

	function copyText(text, okMsg) {
		function fallback() {
			let ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); toast(okMsg || '已复制'); }
			catch (e) { toast('复制失败，请手动复制'); }
			document.body.removeChild(ta);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () { toast(okMsg || '已复制'); }, fallback);
		} else {
			fallback();
		}
	}

	let toastTimer = null;
	function toast(msg, isErr) {
		let t = $('ml-toast');
		if (!t) {
			t = el('div');
			t.id = 'ml-toast';
			t.style.cssText = 'position:fixed;left:50%;top:24px;transform:translateX(-50%);z-index:9999;' +
				'background:#1f2d3d;color:#fff;font-size:13px;padding:9px 18px;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.25);';
			document.body.appendChild(t);
		}
		t.textContent = msg;
		t.style.background = isErr ? '#e5484d' : '#1f2d3d';
		t.style.display = 'block';
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { t.style.display = 'none'; }, 2200);
	}

	// ---------- API ----------
	function apiGet(params, cb) {
		let qs = [];
		for (let k in params) {
			if (params.hasOwnProperty(k) && params[k] !== '' && params[k] !== null && params[k] !== undefined) {
				qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
			}
		}
		let xhr = new XMLHttpRequest();
		xhr.open('GET', AT8ML.api + '?' + qs.join('&'), true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;
			handleJson(xhr, cb);
		};
		xhr.send();
	}

	function apiPost(formData, cb, onFail) {
		formData.append('csrfToken', AT8ML.csrfToken || '');
		let xhr = new XMLHttpRequest();
		xhr.open('POST', AT8ML.api, true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;
			handleJson(xhr, cb, onFail);
		};
		xhr.send(formData);
	}

	function handleJson(xhr, cb, onFail) {
		let data;
		try {
			data = JSON.parse(xhr.responseText);
		} catch (e) {
			let msg = serverErrorText(xhr.responseText);
			// 仅凭 HTTP 401 判定登录失效；超时/500 等服务端错误直接展示可读原因
			if (xhr.status === 401) {
				toast('登录已失效，请刷新页面重新登录', true);
			} else if (msg) {
				toast('服务端错误：' + msg, true);
				if (window.console && console.error) console.error('[媒体库] HTTP ' + xhr.status, msg, String(xhr.responseText).slice(0, 2000));
			} else {
				toast('请求失败（HTTP ' + xhr.status + '）', true);
			}
			if (onFail) onFail();
			return;
		}
		if (data.code !== 0) {
			toast(data.msg || '操作失败', true);
			if (onFail) onFail();
			return;
		}
		if (cb) cb(data.data);
	}

	// 从系统错误页里提取可读的报错文本（服务端 500 时用）
	function serverErrorText(html) {
		if (!html) return '';
		let t = String(html)
			.replace(/<script[\s\S]*?<\/script>/gi, ' ')
			.replace(/<style[\s\S]*?<\/style>/gi, ' ');
		let m = t.match(/<title>([\s\S]*?)<\/title>/i);
		let title = m ? m[1] : '';
		t = t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
		let cut = t.search(/可能的错误原因|如果您是访客/);
		if (cut > 0) t = t.slice(0, cut).trim();
		if (!t) t = title.replace(/\s+/g, ' ').trim();
		return t.slice(0, 160);
	}

	// ---------- 数据加载 ----------
	function buildQuery() {
		return {
			act: 'list',
			page: state.page,
			perpage: state.perpage,
			q: state.q,
			kind: state.kind,
			cateid: state.cateid,
			month: state.month,
			authorid: state.authorid,
			used: state.used,
			orderby: state.orderby,
			orderdir: state.orderdir
		};
	}

	function loadList() {
		$('ml-loading').style.display = 'block';
		$('ml-empty').style.display = 'none';
		apiGet(buildQuery(), function (d) {
			$('ml-loading').style.display = 'none';
			state.list = d.list || [];
			state.total = d.total;
			state.pages = d.pages;
			renderGrid();
			renderPager();
			if (!state.list.length) $('ml-empty').style.display = 'block';
		});
	}

	function loadStats() {
		apiGet({ act: 'stats' }, function (d) {
			state.statsLoaded = true;
			$('ml-st-total').textContent = d.total;
			$('ml-st-img').textContent = d.images;
			$('ml-st-size').textContent = d.total_size_text;
			$('ml-st-unused').textContent = d.unused;

			// 分类下拉
			let cate = $('ml-cate');
			cate.innerHTML = '<option value="">全部分类</option>';
			let oNone = document.createElement('option');
			oNone.value = 'none';
			oNone.textContent = '未关联附件';
			cate.appendChild(oNone);
			(d.categories || []).forEach(function (c) {
				let pad = c.parentid > 0 ? '　' : '';
				let o = document.createElement('option');
				o.value = c.id;
				o.textContent = pad + c.name;
				cate.appendChild(o);
			});

			// 月份下拉
			let month = $('ml-month');
			month.innerHTML = '<option value="">全部月份</option>';
			(d.months || []).forEach(function (m) {
				let o = document.createElement('option');
				o.value = m.month;
				o.textContent = m.month + '（' + m.count + '）';
				month.appendChild(o);
			});

			// 上传者下拉
			let author = $('ml-author');
			author.innerHTML = '<option value="">全部上传者</option>';
			(d.authors || []).forEach(function (a) {
				let o = document.createElement('option');
				o.value = a.id;
				o.textContent = a.name + '（' + a.count + '）';
				author.appendChild(o);
			});

			// 重建后回填当前筛选值：DOM 归零但 state 仍保留，若不回填会出现「界面显示全部、实际仍在过滤」的错觉
			// 选项已不存在时（如分类被删）同步重置 state，保持两者一致
			if (cate.value !== String(state.cateid == null ? '' : state.cateid)) { state.cateid = ''; }
			cate.value = String(state.cateid);
			if (month.value !== String(state.month == null ? '' : state.month)) { state.month = ''; }
			month.value = String(state.month);
			if (author.value !== String(state.authorid == null ? '' : state.authorid)) { state.authorid = ''; }
			author.value = String(state.authorid);
		});
	}

	// ---------- 渲染 ----------
	function kindIconText(kind, name) {
		let dot = name.lastIndexOf('.');
		let ext = dot >= 0 ? name.slice(dot + 1) : '?';
		if (ext.length > 5) ext = ext.slice(0, 5);
		return ext;
	}

	function thumbHtml(item) {
		let badge = '';
		if (!item.exists) badge = '<span class="mlx-badge mlx-badge-missing">文件缺失</span>';
		else if (item.local_missing) badge = '<span class="mlx-badge">本地无文件 · 可能已转存云端</span>';
		else if (item.kind === 'image') badge = '<span class="mlx-badge">' + esc(item.kind_label) + (item.width ? ' ' + item.width + '×' + item.height : '') + '</span>';
		else badge = '<span class="mlx-badge">' + esc(item.kind_label) + '</span>';

		let inner;
		if (item.kind === 'image' && item.exists) {
			inner = '<img src="' + esc(item.url) + '" alt="' + esc(item.alt || item.name) + '" loading="lazy">';
		} else if (item.kind === 'video' && item.exists) {
			inner = '<div class="mlx-fileicon"><div class="mlx-icon">▶</div>' + esc(kindIconText(item.kind, item.name)) + '</div>';
		} else {
			inner = '<div class="mlx-fileicon"><div class="mlx-icon">' + esc(kindIconText(item.kind, item.name)) + '</div></div>';
		}

		let sub = item.size_text + ' · ' + item.date_text.split(' ')[0];
		let cate = item.cate_name ? '<span class="mlx-meta-cate" title="关联文章：' + esc(item.post_title) + '">' + esc(item.cate_name) + '</span>' : '';

		return '<div class="mlx-thumb">' + inner + badge +
			'<span class="mlx-check" title="选择"></span></div>' +
			'<div class="mlx-meta"><div class="mlx-meta-name" title="' + esc(item.name) + '">' + esc(item.name) + '</div>' +
			'<div class="mlx-meta-sub"><span>' + sub + '</span></div>' + cate + '</div>';
	}

	function renderGrid() {
		let box = $('ml-grid');
		box.innerHTML = '';
		state.list.forEach(function (item) {
			let card = el('div', 'mlx-card');
			if (state.selected[item.id]) card.className += ' selected';
			card.setAttribute('data-id', item.id);
			card.innerHTML = thumbHtml(item);
			box.appendChild(card);

			card.addEventListener('click', function (e) {
				if (e.target.className.indexOf('mlx-check') >= 0) return;
				openDrawer(item.id);
			});
			let check = card.querySelector('.mlx-check');
			check.addEventListener('click', function (e) {
				e.stopPropagation();
				toggleSelect(item.id);
			});
		});
		updateBulkBar();
	}

	function toggleSelect(id) {
		let item = findItem(id);
		if (state.selected[id]) {
			delete state.selected[id];
		} else if (item) {
			state.selected[id] = item;
		}
		let cards = $('ml-grid').children;
		for (let i = 0; i < cards.length; i++) {
			let cid = cards[i].getAttribute('data-id');
			if (state.selected[cid]) cards[i].className = 'mlx-card selected';
			else cards[i].className = 'mlx-card';
		}
		updateBulkBar();
	}

	function findItem(id) {
		id = String(id);
		for (let i = 0; i < state.list.length; i++) {
			if (String(state.list[i].id) === id) return state.list[i];
		}
		return null;
	}

	function updateBulkBar() {
		let n = 0;
		for (let k in state.selected) if (state.selected.hasOwnProperty(k)) n++;
		$('ml-bulkbar').style.display = n > 0 ? 'flex' : 'none';
		$('ml-sel-count').textContent = n;
	}

	function renderPager() {
		let box = $('ml-pager');
		box.innerHTML = '';
		if (state.pages <= 1) {
			box.innerHTML = '<span style="color:#93a1b5;font-size:12px">共 ' + state.total + ' 个附件</span>';
			return;
		}
		function addBtn(label, page, opts) {
			opts = opts || {};
			let b = el('button');
			b.textContent = label;
			if (opts.active) b.className = 'active';
			if (opts.disabled) b.disabled = true;
			b.addEventListener('click', function () {
				if (state.page === page) return;
				state.page = page;
				loadList();
				window.scrollTo(0, 0);
			});
			box.appendChild(b);
		}
		let start = Math.max(1, state.page - 2);
		let end = Math.min(state.pages, state.page + 2);
		addBtn('«', 1, { disabled: state.page === 1 });
		addBtn('‹', state.page - 1, { disabled: state.page === 1 });
		if (start > 1) {
			addBtn('1', 1);
			if (start > 2) box.appendChild(el('span', '', '…'));
		}
		for (let i = start; i <= end; i++) addBtn(String(i), i, { active: i === state.page });
		if (end < state.pages) {
			if (end < state.pages - 1) box.appendChild(el('span', '', '…'));
			addBtn(String(state.pages), state.pages);
		}
		addBtn('›', state.page + 1, { disabled: state.page === state.pages });
		addBtn('»', state.pages, { disabled: state.page === state.pages });
	}

	// ---------- 详情抽屉 ----------
	function openDrawer(id) {
		let item = findItem(id);
		if (!item) return;
		state.current = item;
		let dw = $('ml-drawer');

		let preview;
		if (item.kind === 'image' && item.exists) {
			preview = '<div class="mlx-dw-preview"><img id="ml-dw-img" src="' + esc(item.url) + '" alt=""></div>';
		} else if (item.kind === 'video' && item.exists) {
			preview = '<div class="mlx-dw-preview"><video src="' + esc(item.url) + '" controls style="max-width:100%;max-height:300px"></video></div>';
		} else if (item.kind === 'audio' && item.exists) {
			preview = '<div class="mlx-dw-preview"><audio src="' + esc(item.url) + '" controls style="width:92%"></audio></div>';
		} else {
			preview = '<div class="mlx-dw-preview"><div class="mlx-fileicon"><div class="mlx-icon">' + esc(kindIconText(item.kind, item.name)) + '</div>' + esc(item.kind_label) + '</div></div>';
		}

		let canEdit = AT8ML.canUpload;
		let infoRows =
			'<tr><td>URL</td><td>' + esc(item.url) + '</td></tr>' +
			'<tr><td>类型</td><td>' + esc(item.kind_label) + ' / ' + esc(item.mime || '-') + '</td></tr>' +
			'<tr><td>大小</td><td>' + esc(item.size_text) + (item.width ? ' · ' + item.width + '×' + item.height + ' px' : '') + '</td></tr>' +
			'<tr><td>上传时间</td><td>' + esc(item.date_text) + '</td></tr>' +
			'<tr><td>上传者</td><td>' + esc(item.author) + '</td></tr>' +
			'<tr><td>文件状态</td><td>' + (item.exists ? (item.local_missing ? '正常 · 本地无文件（可能已转存云端）' : '正常') : '<span class="mlx-missing">文件缺失（记录存在但文件已被删除）</span>') + '</td></tr>' +
			'<tr><td>关联文章</td><td>' + (item.logid > 0 ? '#' + item.logid + ' ' + esc(item.post_title || '') : '未关联') + '</td></tr>' +
			'<tr><td>引用状态</td><td id="ml-dw-quote">' + (item.logid > 0 ? '检测中…' : '<span style="color:#b8860b">⚠️ 未引用（未关联文章）</span>') + '</td></tr>';

		dw.innerHTML =
			'<div class="mlx-dw-head"><b title="' + esc(item.name) + '">' + esc(item.name) + '</b>' +
			'<button type="button" class="mlx-btn mlx-btn-sm" id="ml-dw-close">关闭</button></div>' +
			preview +
			'<div class="mlx-dw-body">' +
			'<div class="mlx-dw-info"><table>' + infoRows + '</table></div>' +
			'<div class="mlx-dw-actions">' +
			'<button type="button" class="mlx-btn mlx-btn-sm" id="ml-dw-copyurl">复制 URL</button>' +
			'<button type="button" class="mlx-btn mlx-btn-sm" id="ml-dw-copyhtml">复制 HTML</button>' +
			'<button type="button" class="mlx-btn mlx-btn-sm" id="ml-dw-copymd">复制 Markdown</button>' +
			'<a class="mlx-btn mlx-btn-sm" href="' + esc(item.url) + '" target="_blank">新窗口打开</a>' +
			(AT8ML.canDelete ? '<button type="button" class="mlx-btn mlx-btn-sm mlx-btn-danger" id="ml-dw-del">删除</button>' : '') +
			'</div>' +
			(canEdit ?
			'<div class="mlx-dw-field"><label>标题</label><input type="text" class="mlx-input" id="ml-dw-title" value="' + esc(item.title) + '"></div>' +
			'<div class="mlx-dw-field"><label>图片 Alt</label><input type="text" class="mlx-input" id="ml-dw-alt" value="' + esc(item.alt) + '"></div>' +
			'<div class="mlx-dw-field"><label>说明</label><textarea class="mlx-input" id="ml-dw-intro">' + esc(item.intro) + '</textarea></div>' +
			'<div class="mlx-dw-field"><label>关联文章 / 页面</label>' +
			'<input type="text" class="mlx-input" id="ml-dw-post-q" placeholder="输入标题搜索…">' +
			'<div class="mlx-ac-list" id="ml-dw-ac" style="display:none"></div>' +
			'<input type="hidden" id="ml-dw-logid" value="' + item.logid + '">' +
			'<div style="font-size:12px;color:#93a1b5;margin-top:5px" id="ml-dw-post-cur">当前：' +
			(item.logid > 0 ? '#' + item.logid + ' ' + esc(item.post_title || '') : '未关联') + '</div>' +
			(item.logid > 0 ? '<button type="button" class="mlx-btn mlx-btn-sm" id="ml-dw-unlink" style="margin-top:6px">取消关联</button>' : '') +
			'</div></div>' +
			'<button type="button" class="mlx-btn mlx-btn-primary mlx-dw-save" id="ml-dw-save">保存修改</button>'
			: '') +
			'</div>';

		dw.className = 'mlx-drawer open';
		$('ml-drawer-mask').className = 'mlx-drawer-mask open';

		$('ml-dw-close').addEventListener('click', closeDrawer);

		let img = $('ml-dw-img');
		if (img) img.addEventListener('click', function () { openLightbox(id); });

		// 引用检测：关联文章的正文中是否实际引用了该附件
		if (item.logid > 0) {
			let qfd = new FormData();
			qfd.append('act', 'quotecheck');
			qfd.append('id', item.id);
			apiPost(qfd, function (q) {
				let cell = $('ml-dw-quote');
				if (!cell) return; // 抽屉已关闭
				if (q && q.state === 'missing') {
					cell.innerHTML = '<span class="mlx-missing">关联的文章不存在（可能已删除）</span>';
				} else if (q && q.quoted) {
					cell.innerHTML = '✅ 已在正文引用';
				} else {
					cell.innerHTML = '<span class="mlx-quote-warn">⚠️ 仅关联未引用</span>';
				}
			}, function () {
				let cell = $('ml-dw-quote');
				if (!cell) return;
				cell.innerHTML = '<span class="mlx-quote-err">检测失败，请重试</span>';
			});
		}

		$('ml-dw-copyurl').addEventListener('click', function () { copyText(item.url, 'URL 已复制'); });
		$('ml-dw-copyhtml').addEventListener('click', function () {
			let alt = item.alt || item.title || item.name;
			let html = item.kind === 'image'
				? '<img src="' + item.url + '" alt="' + alt + '">'
				: '<a href="' + item.url + '">' + esc(item.name) + '</a>';
			copyText(html, 'HTML 代码已复制');
		});
		$('ml-dw-copymd').addEventListener('click', function () {
			let alt = item.alt || item.title || item.name;
			let md = item.kind === 'image'
				? '![' + alt + '](' + item.url + ')'
				: '[' + item.name + '](' + item.url + ')';
			copyText(md, 'Markdown 已复制');
		});

		if (!canEdit) return;

		$('ml-dw-del').addEventListener('click', function () {
			if (!window.confirm('确定删除「' + item.name + '」？此操作会同时删除文件与记录，不可恢复。')) return;
			let fd = new FormData();
			fd.append('act', 'delete');
			fd.append('id', item.id);
			apiPost(fd, function () {
				toast('已删除');
				delete state.selected[item.id];
				closeDrawer();
				loadList();
				loadStats();
			});
		});

		// 取消关联：清空隐藏域，保存时以 logid=0 提交解除关联
		let unlinkBtn = $('ml-dw-unlink');
		if (unlinkBtn) {
			unlinkBtn.addEventListener('click', function () {
				$('ml-dw-logid').value = '';
				$('ml-dw-post-cur').textContent = '当前：未关联';
				unlinkBtn.disabled = true;
			});
		}

		$('ml-dw-save').addEventListener('click', function () {
			let fd = new FormData();
			fd.append('act', 'update');
			fd.append('id', item.id);
			fd.append('title', $('ml-dw-title').value);
			fd.append('alt', $('ml-dw-alt').value);
			fd.append('intro', $('ml-dw-intro').value);
			fd.append('logid', $('ml-dw-logid').value);
			apiPost(fd, function (d) {
				toast('已保存');
				state.current = d;
				// 关联状态变化影响「使用状态 / 未关联附件」筛选结果：处于这些筛选时重新拉列表，否则就地更新
				if (state.used !== '' || state.cateid === 'none') {
					loadList();
				} else {
					refreshItem(d);
				}
				loadStats();
			});
		});

		// 关联文章搜索
		let acBox = $('ml-dw-ac');
		let doSearch = debounce(function () {
			let q = $('ml-dw-post-q').value.trim();
			apiGet({ act: 'posts', q: q }, function (list) {
				acBox.innerHTML = '';
				acBox.style.display = 'block';
				if (!list.length) {
					acBox.innerHTML = '<div class="mlx-ac-item">没有匹配的文章</div>';
					return;
				}
				list.forEach(function (p) {
					let it = el('div', 'mlx-ac-item');
					it.innerHTML = '<b>#' + p.id + '</b> ' + esc(p.title) + ' <span>' + (p.type === 1 ? '页面' : '文章') + '</span>';
					if (p.id === state.current.logid) it.className += ' mlx-ac-active';
					it.addEventListener('click', function () {
						$('ml-dw-logid').value = p.id;
						$('ml-dw-post-cur').textContent = '当前：#' + p.id + ' ' + p.title;
						acBox.style.display = 'none';
					});
					acBox.appendChild(it);
				});
			});
		}, 250);
		$('ml-dw-post-q').addEventListener('input', doSearch);
		$('ml-dw-post-q').addEventListener('focus', doSearch);
	}

	function refreshItem(d) {
		for (let i = 0; i < state.list.length; i++) {
			if (state.list[i].id === d.id) { state.list[i] = d; break; }
		}
		renderGrid();
	}

	function closeDrawer() {
		$('ml-drawer').className = 'mlx-drawer';
		$('ml-drawer-mask').className = 'mlx-drawer-mask';
		state.current = null;
	}

	// ---------- 上传 ----------
	function openUploadPanel() {
		$('ml-upload-panel').className = 'mlx-upload-panel open';
	}

	function closeUploadPanel() {
		$('ml-upload-panel').className = 'mlx-upload-panel';
		$('ml-up-list').innerHTML = '';
	}

	function addUploadRow(name) {
		let row = el('div', 'mlx-up-item');
		row.innerHTML = '<span class="mlx-up-name">' + esc(name) + '</span>' +
			'<span class="mlx-up-bar"><i></i></span>' +
			'<span class="mlx-up-status">…</span>';
		$('ml-up-list').appendChild(row);
		return row;
	}

	function uploadFiles(files) {
		if (!AT8ML.canUpload) { toast('没有上传权限', true); return; }
		if (!files || !files.length) return;
		let logid = $('ml-upload-logid').value || '0';

		let arr = [];
		for (let i = 0; i < files.length; i++) arr.push(files[i]);

		// 单文件逐个上传，便于展示各自进度
		function next(idx) {
			if (idx >= arr.length) {
				toast('上传完成');
				loadList();
				loadStats();
				return;
			}
			let f = arr[idx];
			let row = addUploadRow(f.name);
			let fd = new FormData();
			fd.append('act', 'upload');
			fd.append('csrfToken', AT8ML.csrfToken || '');
			fd.append('logid', logid);
			fd.append('files', f, f.name);

			let xhr = new XMLHttpRequest();
			xhr.open('POST', AT8ML.api, true);
			xhr.upload.onprogress = function (e) {
				if (e.lengthComputable) {
					let pct = Math.round(e.loaded / e.total * 100);
					row.querySelector('.mlx-up-bar i').style.width = pct + '%';
					row.querySelector('.mlx-up-status').textContent = pct + '%';
				}
			};
			xhr.onreadystatechange = function () {
				if (xhr.readyState !== 4) return;
				let st = row.querySelector('.mlx-up-status');
				let data = null;
				try { data = JSON.parse(xhr.responseText); } catch (e) {}
				if (data && data.code === 0) {
					st.textContent = '完成';
					st.className = 'mlx-up-status ok';
					row.querySelector('.mlx-up-bar i').style.width = '100%';
					next(idx + 1);
				} else {
					st.textContent = '失败';
					st.className = 'mlx-up-status err';
					st.title = (data && data.msg) || '上传失败';
					toast(f.name + '：' + ((data && data.msg) || '上传失败'), true);
					next(idx + 1);
				}
			};
			xhr.send(fd);
		}
		next(0);
	}

	// ---------- 灯箱 ----------
	function openLightbox(id) {
		let imgs = [];
		state.list.forEach(function (it, idx) {
			if (it.kind === 'image' && it.exists) imgs.push(it);
		});
		if (!imgs.length) return;
		let idx = 0;
		for (let i = 0; i < imgs.length; i++) {
			if (imgs[i].id === id) { idx = i; break; }
		}
		state.lightboxImgs = imgs;
		showLightbox(idx);
	}

	function showLightbox(idx) {
		let imgs = state.lightboxImgs;
		if (!imgs.length) return;
		if (idx < 0) idx = imgs.length - 1;
		if (idx >= imgs.length) idx = 0;
		state.lightboxIndex = idx;
		let it = imgs[idx];
		$('ml-lb-img').src = it.url;
		$('ml-lb-caption').textContent = it.name + (it.width ? '（' + it.width + '×' + it.height + '）' : '') + ' · ' + (idx + 1) + '/' + imgs.length;
		$('ml-lightbox').className = 'mlx-lightbox open';
	}

	function closeLightbox() {
		$('ml-lightbox').className = 'mlx-lightbox';
	}

	// ---------- 弹窗（批量关联） ----------
	function showBindModal() {
		if ($('ml-modal')) return; // 防止快速双击叠出重复弹窗
		let mask = el('div', 'mlx-modal-mask');
		mask.id = 'ml-modal';
		mask.innerHTML =
			'<div class="mlx-modal">' +
			'<div class="mlx-modal-head">批量关联到文章</div>' +
			'<div class="mlx-modal-body">' +
			'<input type="text" class="mlx-input" id="ml-bind-q" placeholder="输入文章标题搜索…">' +
			'<div class="mlx-ac-list" id="ml-bind-ac"></div>' +
			'<div style="font-size:12px;color:#93a1b5;margin-top:8px">所选附件将被关联到同一篇文章（用于按文章 / 分类筛选）</div>' +
			'</div>' +
			'<div class="mlx-modal-foot">' +
			'<button type="button" class="mlx-btn" id="ml-bind-cancel">取消</button>' +
			'<button type="button" class="mlx-btn mlx-btn-primary" id="ml-bind-ok" disabled>关联</button>' +
			'</div></div>';
		document.body.appendChild(mask);

		let target = null;
		mask.addEventListener('click', function (e) { if (e.target === mask) mask.parentNode.removeChild(mask); });
		$('ml-bind-cancel').addEventListener('click', function () { mask.parentNode.removeChild(mask); });

		function search() {
			let q = $('ml-bind-q').value.trim();
			apiGet({ act: 'posts', q: q }, function (list) {
				let box = $('ml-bind-ac');
				box.innerHTML = '';
				if (!list.length) {
					box.innerHTML = '<div class="mlx-ac-item">没有匹配的文章</div>';
					return;
				}
				list.forEach(function (p) {
					let it = el('div', 'mlx-ac-item');
					it.innerHTML = '<b>#' + p.id + '</b> ' + esc(p.title) + ' <span>' + (p.type === 1 ? '页面' : '文章') + '</span>';
					it.addEventListener('click', function () {
						let items = box.querySelectorAll('.mlx-ac-item');
						for (let i = 0; i < items.length; i++) items[i].className = 'mlx-ac-item';
						it.className += ' mlx-ac-active';
						target = p;
						$('ml-bind-ok').disabled = false;
					});
					box.appendChild(it);
				});
			});
		}
		$('ml-bind-q').addEventListener('input', debounce(search, 250));
		search();

		$('ml-bind-ok').addEventListener('click', function () {
			if (!target) return;
			let ids = [];
			for (let k in state.selected) if (state.selected.hasOwnProperty(k)) ids.push(k);
			let fd = new FormData();
			fd.append('act', 'bulk');
			fd.append('op', 'bind');
			fd.append('ids', ids.join(','));
			fd.append('logid', target.id);
			apiPost(fd, function (d) {
				toast('已关联 ' + d.done + ' 个附件到 #' + target.id + ' ' + target.title);
				mask.parentNode.removeChild(mask);
				state.selected = {};
				loadList();
				loadStats();
			});
		});
	}

	// ---------- 事件绑定 ----------
	function bindEvents() {
		// 筛选
		$('ml-q').addEventListener('input', debounce(function () {
			state.q = $('ml-q').value.trim();
			state.page = 1;
			loadList();
		}, 350));

		[['ml-kind', 'kind'], ['ml-cate', 'cateid'], ['ml-month', 'month'],
		 ['ml-author', 'authorid'], ['ml-used', 'used']].forEach(function (pair) {
			$(pair[0]).addEventListener('change', function () {
				state[pair[1]] = this.value;
				state.page = 1;
				loadList();
			});
		});

		$('ml-orderby').addEventListener('change', function () {
			state.orderby = this.value;
			state.page = 1;
			loadList();
		});
		$('ml-orderdir').addEventListener('change', function () {
			state.orderdir = this.value;
			state.page = 1;
			loadList();
		});
		$('ml-perpage').addEventListener('change', function () {
			state.perpage = parseInt(this.value, 10) || 48;
			state.page = 1;
			loadList();
		});

		// 视图切换
		let vsBtns = document.querySelectorAll('.mlx-vs-btn');
		for (let i = 0; i < vsBtns.length; i++) {
			vsBtns[i].addEventListener('click', function () {
				for (let j = 0; j < vsBtns.length; j++) vsBtns[j].className = 'mlx-vs-btn';
				this.className = 'mlx-vs-btn active';
				state.view = this.getAttribute('data-view');
				let root = document.querySelector('.mlx-root');
				if (state.view === 'list') root.className = 'mlx-root mlx-listview';
				else root.className = 'mlx-root';
			});
		}

		// 批量
		$('ml-btn-selall').addEventListener('click', function () {
			state.list.forEach(function (it) { state.selected[it.id] = it; });
			renderGrid();
		});
		$('ml-btn-clearsel').addEventListener('click', function () {
			state.selected = {};
			renderGrid();
		});
		$('ml-btn-bulkbind').addEventListener('click', showBindModal);
		if (!AT8ML.canDelete) $('ml-btn-bulkdel').style.display = 'none';
		$('ml-btn-bulkdel').addEventListener('click', function () {
			let n = 0;
			for (let k in state.selected) if (state.selected.hasOwnProperty(k)) n++;
			if (!n) return;
			if (!window.confirm('确定删除选中的 ' + n + ' 个附件？文件与记录将一并删除，不可恢复。')) return;
			let ids = [];
			for (let key in state.selected) if (state.selected.hasOwnProperty(key)) ids.push(key);
			// 分批请求：单次删除过多会触发 PHP 执行超时，服务端返回非 JSON 会被误判
			let CHUNK = 20, done = 0, skipped = 0, failed = 0, pos = 0;
			let next = function () {
				let part = ids.slice(pos, pos + CHUNK);
				if (!part.length) {
					toast('已删除 ' + done + ' 个附件' + (skipped ? '，' + skipped + ' 个已跳过' : '') + (failed ? '，' + failed + ' 个删除失败，可重试' : ''), !!failed);
					state.selected = {};
					loadList();
					loadStats();
					return;
				}
				pos += CHUNK;
				let fd = new FormData();
				fd.append('act', 'bulk');
				fd.append('op', 'delete');
				fd.append('ids', part.join(','));
				apiPost(fd, function (d) {
					done += (d && d.done) || 0;
					skipped += (d && d.skipped) || 0;
					failed += (d && d.failed) || 0;
					next();
				}, function () {
					// 单批失败不中断后续批次，最后汇总提示
					failed += part.length;
					next();
				});
			};
			next();
		});

		// 上传
		$('ml-btn-upload').addEventListener('click', openUploadPanel);
		$('ml-up-close').addEventListener('click', closeUploadPanel);
		$('ml-up-drop').addEventListener('click', function () { $('ml-up-input').click(); });
		$('ml-up-input').addEventListener('change', function () {
			uploadFiles(this.files);
			this.value = '';
		});

		// 整页拖拽
		let dragDepth = 0;
		document.addEventListener('dragenter', function (e) {
			e.preventDefault();
			dragDepth++;
			$('ml-dropzone-tip').className = 'mlx-dropzone-tip show';
		});
		document.addEventListener('dragleave', function (e) {
			e.preventDefault();
			dragDepth--;
			if (dragDepth <= 0) {
				dragDepth = 0;
				$('ml-dropzone-tip').className = 'mlx-dropzone-tip';
			}
		});
		document.addEventListener('dragover', function (e) { e.preventDefault(); });
		document.addEventListener('drop', function (e) {
			e.preventDefault();
			dragDepth = 0;
			$('ml-dropzone-tip').className = 'mlx-dropzone-tip';
			if (e.dataTransfer && e.dataTransfer.files) {
				openUploadPanel();
				uploadFiles(e.dataTransfer.files);
			}
		});

		// 抽屉 / 灯箱
		$('ml-drawer-mask').addEventListener('click', closeDrawer);
		$('ml-lb-close').addEventListener('click', closeLightbox);
		$('ml-lb-prev').addEventListener('click', function () { showLightbox(state.lightboxIndex - 1); });
		$('ml-lb-next').addEventListener('click', function () { showLightbox(state.lightboxIndex + 1); });
		$('ml-lightbox').addEventListener('click', function (e) {
			if (e.target === this || e.target.className === 'mlx-lb-inner') closeLightbox();
		});

		// 键盘
		document.addEventListener('keydown', function (e) {
			let lbOpen = $('ml-lightbox').className.indexOf('open') >= 0;
			if (e.key === 'Escape' || e.keyCode === 27) {
				if (lbOpen) closeLightbox();
				else closeDrawer();
			}
			if (lbOpen && (e.key === 'ArrowLeft' || e.keyCode === 37)) showLightbox(state.lightboxIndex - 1);
			if (lbOpen && (e.key === 'ArrowRight' || e.keyCode === 39)) showLightbox(state.lightboxIndex + 1);
		});
	}

	// ---------- 初始化 ----------
	function init() {
		// 页内动态元素
		if (!$('ml-dropzone-tip')) {
			let tip = el('div', 'mlx-dropzone-tip', '松开鼠标，上传到媒体库');
			tip.id = 'ml-dropzone-tip';
			document.body.appendChild(tip);
		}
		// 文件选择框过滤：类型列表来自站点「允许上传的文件类型」配置（仅 UI 提示，
		// 最终是否放行仍由服务端官方附件流程裁决，前端限制不作为安全边界）
		if (AT8ML.uploadAccept) $('ml-up-input').setAttribute('accept', AT8ML.uploadAccept);
		// 上传面板可选文章（最近文章）
		apiGet({ act: 'posts' }, function (list) {
			let sel = $('ml-upload-logid');
			list.slice(0, 10).forEach(function (p) {
				let o = document.createElement('option');
				o.value = p.id;
				o.textContent = '关联：#' + p.id + ' ' + p.title.slice(0, 14);
				sel.appendChild(o);
			});
		});

		bindEvents();
		loadStats();
		loadList();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
