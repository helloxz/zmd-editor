(function () {
	'use strict';

	var cfg = window.zmdAdmin;
	if (!cfg) {
		return;
	}

	var testButton = document.getElementById('zmd-test-connection');
	var refreshButton = document.getElementById('zmd-refresh-albums');
	var result = document.getElementById('zmd-test-result');
	var albumSelect = document.getElementById('zmd-album-id');
	var buttons = [testButton, refreshButton].filter(Boolean);

	if (!result) {
		return;
	}

	function setResult(message, type) {
		result.textContent = message;
		result.className = 'zmd-status zmd-status-' + (type || 'info');
	}

	function setBusy(busy) {
		buttons.forEach(function (button) {
			button.disabled = busy;
		});
	}

	function populateAlbums(items) {
		if (!albumSelect) {
			return;
		}

		var current = albumSelect.value || '0';
		var known = {};
		while (albumSelect.options.length > 0) {
			albumSelect.remove(0);
		}

		var defaultOption = document.createElement('option');
		defaultOption.value = '0';
		defaultOption.textContent = '默认相册（0）';
		albumSelect.appendChild(defaultOption);

		items.forEach(function (item) {
			var id = String(item.album_id || 0);
			if (id === '0') {
				return;
			}
			known[id] = true;
			var option = document.createElement('option');
			option.value = id;
			option.textContent = (item.name || '未命名相册') + ' (#' + id + ')';
			albumSelect.appendChild(option);
		});

		if (current !== '0' && !known[current]) {
			var saved = document.createElement('option');
			saved.value = current;
			saved.textContent = '已保存相册 (#' + current + ')';
			albumSelect.appendChild(saved);
		}

		albumSelect.value = current;
		if (!albumSelect.value) {
			albumSelect.value = '0';
		}
	}

	function fetchAlbums() {
		if (!cfg.hasToken) {
			setResult(cfg.i18n.noToken || '请先填写并保存 ImgURL Token。', 'error');
			return;
		}

		setBusy(true);
		setResult(cfg.i18n.testing || '正在连接…', 'info');

		fetch(cfg.restUrl + 'albums', {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce
			}
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (json) {
				if (!response.ok) {
					var message = json && json.message ? json.message : (cfg.i18n.badJson || '服务器返回格式错误。') + ' HTTP ' + response.status;
					throw new Error(message);
				}
				return json;
			});
		}).then(function (json) {
			var items = Array.isArray(json.items) ? json.items : [];
			populateAlbums(items);
			if (items.length === 0) {
				setResult(cfg.i18n.emptyList || '连接成功，但没有返回相册。', 'success');
			} else {
				setResult((cfg.i18n.success || '连接成功，共 %d 个相册。').replace('%d', String(items.length)), 'success');
			}
		}).catch(function (err) {
			setResult((cfg.i18n.failed || '连接失败：') + (err && err.message ? err.message : ''), 'error');
		}).finally(function () {
			setBusy(false);
		});
	}

	if (testButton) {
		testButton.addEventListener('click', fetchAlbums);
	}
	if (refreshButton) {
		refreshButton.addEventListener('click', fetchAlbums);
	}
})();
