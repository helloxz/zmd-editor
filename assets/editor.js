(function () {
	'use strict';

	var cfg = window.zmdEditor;
	if (!cfg || !window.OverType) {
		return;
	}

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function copyButton(button, title) {
		if (!button) {
			return null;
		}
		return Object.assign({}, button, { title: title });
	}

	ready(function () {
		var textarea = document.getElementById('content');
		if (!textarea || document.getElementById('zmd-editor-host')) {
			return;
		}

		var postdivrich = document.getElementById('postdivrich');
		var host = document.createElement('div');
		host.id = 'zmd-editor-host';
		host.dataset.theme = cfg.theme || 'solar';
		host.style.setProperty('--zmd-height', cfg.height || '70vh');

		if (postdivrich && postdivrich.parentNode) {
			postdivrich.parentNode.insertBefore(host, postdivrich);
			postdivrich.classList.add('zmd-offscreen');
			textarea.tabIndex = -1;
		} else {
			var wrap = textarea.closest('.wp-editor-wrap');
			if (wrap && wrap.parentNode) {
				wrap.parentNode.insertBefore(host, wrap);
				wrap.classList.add('zmd-offscreen');
			} else {
				textarea.parentNode.insertBefore(host, textarea.nextSibling);
				textarea.classList.add('zmd-offscreen-field');
			}
			textarea.tabIndex = -1;
		}

		var toolbarButtons = window.toolbarButtons;
		var buttons = null;
		if (toolbarButtons) {
			buttons = [
				copyButton(toolbarButtons.bold, '加粗'),
				copyButton(toolbarButtons.italic, '斜体'),
				copyButton(toolbarButtons.code, '行内代码'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.link, '插入链接'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.h1, '一级标题'),
				copyButton(toolbarButtons.h2, '二级标题'),
				copyButton(toolbarButtons.h3, '三级标题'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.bulletList, '无序列表'),
				copyButton(toolbarButtons.orderedList, '有序列表'),
				copyButton(toolbarButtons.taskList, '任务列表'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.outdent, '减少缩进'),
				copyButton(toolbarButtons.indent, '增加缩进'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.quote, '引用'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.upload, '上传图片'),
				toolbarButtons.separator,
				copyButton(toolbarButtons.viewMode, '视图模式')
			].filter(Boolean);
		}

		var editor = null;
		var syncing = false;
		var pending = 0;

		var options = {
			value: textarea.value,
			theme: cfg.theme || 'solar',
			fontSize: '15px',
			lineHeight: 1.7,
			padding: '16px',
			fontFamily: '"Sarasa Mono SC", "Noto Sans Mono CJK SC", "Microsoft YaHei Mono", "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace',
			placeholder: cfg.i18n.placeholder || '开始写 Markdown…',
			autofocus: false,
			toolbar: true,
			toolbarButtons: buttons || undefined,
			autoResize: false,
			showStats: !!cfg.showStats,
			spellcheck: false,
			textareaProps: {
				'aria-label': 'Markdown 内容'
			},
			fileUpload: {
				enabled: true,
				maxSize: 1024 * 1024 * 1024,
				mimeTypes: [],
				batch: false,
				onInsertFile: uploadFile
			},
			onChange: function (value) {
				syncing = true;
				textarea.value = value;
				textarea.dispatchEvent(new Event('input', { bubbles: true }));
				textarea.dispatchEvent(new Event('change', { bubbles: true }));
				syncing = false;
			}
		};

		try {
			var created = new window.OverType(host, options);
			editor = Array.isArray(created) ? created[0] : created;
			if (!editor) {
				throw new Error('OverType returned no instance');
			}
		} catch (err) {
			// Leave the original textarea usable if OverType fails to initialize.
			if (postdivrich) {
				postdivrich.classList.remove('zmd-offscreen');
			}
			var failedWrap = textarea.closest('.wp-editor-wrap');
			if (failedWrap) {
				failedWrap.classList.remove('zmd-offscreen');
			}
			textarea.classList.remove('zmd-offscreen-field');
			textarea.tabIndex = 0;
			host.remove();
			if (window.console) {
				console.error('ZMD Editor: OverType init failed', err);
			}
			return;
		}

		function externalSync() {
			if (syncing) {
				return;
			}
			if (textarea.value !== editor.getValue()) {
				editor.setValue(textarea.value);
			}
		}

		textarea.addEventListener('input', externalSync);
		textarea.addEventListener('change', externalSync);

		// Safety net for WordPress autosave restore, which writes into #content
		// with document.execCommand(). Only sync while OverType is not focused.
		window.setInterval(function () {
			if (document.activeElement !== editor.textarea && textarea.value !== editor.getValue()) {
				editor.setValue(textarea.value);
			}
		}, 1200);

		var form = textarea.form || textarea.closest('form');
		if (form) {
			form.addEventListener('submit', function (event) {
				textarea.value = editor.getValue();
				if (pending > 0) {
					event.preventDefault();
					window.alert(cfg.i18n.uploadPending || '图片还在上传，请稍候再保存。');
				}
			}, true);
		}

		function altText(name) {
			return String(name || 'image')
				.replace(/\.[^.]+$/, '')
				.replace(/[\[\]]/g, '')
				.replace(/\s+/g, ' ')
				.trim() || 'image';
		}

		function formatSize(bytes) {
			if (bytes >= 1024 * 1024) {
				return Math.round(bytes / 1024 / 1024) + ' MB';
			}
			if (bytes >= 1024) {
				return Math.round(bytes / 1024) + ' KB';
			}
			return bytes + ' B';
		}

		var toastTimer = null;
		function toast(message, type) {
			var el = document.getElementById('zmd-toast');
			if (!el) {
				el = document.createElement('div');
				el.id = 'zmd-toast';
				document.body.appendChild(el);
			}
			el.textContent = message;
			el.className = 'zmd-toast zmd-toast-' + (type || 'info') + ' is-visible';
			window.clearTimeout(toastTimer);
			toastTimer = window.setTimeout(function () {
				el.classList.remove('is-visible');
			}, 4500);
		}

		function uploadFile(file) {
			if (!cfg.hasToken) {
				toast(cfg.i18n.noToken || '请先配置 ImgURL Token。', 'error');
				return Promise.reject(new Error('no token'));
			}

			if (cfg.maxSize > 0 && file.size > cfg.maxSize) {
				toast((cfg.i18n.tooLarge || '图片超过大小限制：') + (cfg.maxSizeLabel || formatSize(cfg.maxSize)), 'error');
				return Promise.reject(new Error('too large'));
			}

			pending++;
			var data = new FormData();
			data.append('file', file, file.name);

			var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
			var timer = null;
			if (controller) {
				timer = window.setTimeout(function () {
					controller.abort();
				}, 120000);
			}

			return fetch(cfg.restUrl + 'upload', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': cfg.nonce
				},
				body: data,
				signal: controller ? controller.signal : undefined
			}).then(function (response) {
				return response.json().catch(function () {
					return {};
				}).then(function (json) {
					if (!response.ok || !json.url) {
						var message = json && json.message ? json.message : (cfg.i18n.badResponse || '服务器返回格式错误。');
						if (!json || !json.message) {
							message += ' HTTP ' + response.status;
						}
						throw new Error(message);
					}
					toast((cfg.i18n.uploaded || '图片已上传并插入。') + ' ' + file.name, 'success');
					return '![' + altText(file.name) + '](' + json.url + ')';
				});
			}).catch(function (err) {
				toast(file.name + '：' + (err && err.message ? err.message : (cfg.i18n.uploadFailed || '图片上传失败。')), 'error');
				throw err;
			}).finally(function () {
				if (timer) {
					window.clearTimeout(timer);
				}
				pending = Math.max(0, pending - 1);
			});
		}
	});
})();
