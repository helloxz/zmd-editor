# ZMD Editor — 技术说明

本文件面向开发、维护和自动化代理，记录实现细节、配置、边界和验证方式。用户向简介见 [README.md](README.md)。

## 环境

- WordPress 6.0+
- PHP 7.4+
- 服务器能出站访问 ImgURL（默认 `https://www.imgurl.org`）

## 工作原理

- **编辑器宿主**：对启用的文章类型，`use_block_editor_for_post_type` 返回 `false`，使用经典编辑器屏幕；Gutenberg 保留安装，未勾选的类型不受影响。
- **经典编辑器接管**：`wp_editor_settings` 对 `post.php` / `post-new.php` 的 `content` 编辑器关闭 TinyMCE、quicktags 和媒体按钮。
- **OverType 挂载**：`assets/editor.js` 把 `#postdivrich` 移出可视区（保留原生 `#content` 可聚焦），在原位置插入 OverType。
- **双向同步**：OverType `onChange` 写回 `#content` 并派发 `input`；`#content` 的外部变化（如 WordPress autosave 恢复）反向写回 OverType；提交前再同步一次。
- **保存完整性**：`content_save_pre` 优先级 999，对启用类型且拥有 `unfiltered_html` 的用户返回原始提交内容（slashed），避免 `convert_invalid_entities`、`balanceTags`、KSES 改写；Quick Draft 排除。
- **前台**：插件不注册 `the_content` / `the_excerpt` / feed 过滤器，也不引入 PHP Markdown 解析器；`post_content` 由主题负责渲染。
- **图片上传**：`POST /wp-json/zmd/v1/upload` 代理到 `{ImgURL 地址}/api/v3/upload`；`GET /wp-json/zmd/v1/albums` 代理到 `{ImgURL 地址}/api/v3/album_list`。
- **权限**：上传接口要求 `upload_files`；相册/测试接口要求 `manage_options`。
- **Token**：优先读取 `ZMD_IMGURL_TOKEN` 常量；否则读取 `zmd_imgurl_token` 选项（`autoload=no`）。
- **资源缓存**：`ZMD_EDITOR_VERSION` 用于脚本/样式版本号；修改 JS/CSS 后应升版本。

```text
OverType onChange ──→ #content.value ──→ WP autosave / submit / revisions
#content input    ←── WP autosave restore / external updates
Paste/drop/toolbar → POST /zmd/v1/upload → ImgURL → ![](url)
Settings test      → GET  /zmd/v1/albums → ImgURL album_list
```

## 使用细节

- **工具栏上传**：点击工具栏的上传按钮选择图片。
- **粘贴上传**：截图或图片文件直接粘贴到编辑区。
- **拖拽上传**：把图片文件拖进编辑区。
- **视图模式**：工具栏最右侧的眼睛是 OverType 内置的 `viewMode` 下拉（普通编辑 / 纯文本 / Preview）。
- **Preview 的边界**：Preview 是 OverType 的阅读视图，会隐藏 Markdown 标记，但**不显示图片**，也不使用主题 CSS；表格等也不渲染。真正的前台效果请用 WordPress 的“预览”。
- 上传成功后插入 `![文件名](https://...)`。OverType 不内联显示图片，这是它的设计限制。

## 设置页

- 启用的文章类型（默认 `post` + `page`）
- 编辑器高度、主题、统计栏
- ImgURL 地址（默认 `https://www.imgurl.org`）
- Token
- 默认相册
- 去重（dedup）、文字水印、压缩
- 最大文件大小（实际生效值受服务器限制，取较小值）
- 响应 URL 路径（默认 `data.url`）
- 连接测试（按钮在“保存设置”旁边；调用相册接口验证地址和 Token）
- 刷新相册（在“默认相册”字段旁）
- 系统信息（插件/OverType/WordPress/PHP 版本、实际接口地址、上传上限、Token 来源）

## ImgURL 配置

设置页只需要填 **ImgURL 地址**，默认：

```text
https://www.imgurl.org
```

插件自动拼接：

- 上传：`POST {地址}/api/v3/upload`
- 相册：`GET {地址}/api/v3/album_list`
- 鉴权：`Authorization: Bearer sk-...`
- 上传字段：multipart 的 `file` + `params`
- `params`：`album_id`、`dedup`、`watermark`、`compress`
- 响应 URL 路径：默认 `data.url`，可配置

兼容 ImgURL v3 标准路径的服务只需改地址和 Token；如果服务使用不同的接口路径，需要改代码/单独适配。

## Token 保存方式

- 默认保存在数据库选项 `zmd_imgurl_token`（`autoload=no`），不会输出到页面。
- 也可以在 `wp-config.php` 中定义常量覆盖（更安全）：

```php
define( 'ZMD_IMGURL_TOKEN', 'sk-xxxxxxxx' );
```

常量存在时，设置页的 Token 输入框会被禁用。

## 重要边界

- **只接管后台经典编辑器**。Quick Draft、XML-RPC、REST API、自定义前端表单创建/保存的内容不保证是 Markdown。
- **停用插件后 Gutenberg 会恢复**。不要用 Gutenberg 打开并保存这些纯 Markdown 文章，否则区块编辑器会改写内容。
- **一个文章类型只能有一种内容格式**。启用某类型后，该类型的新文章都走 Markdown 编辑器；如果该类型里已有 HTML 旧文章，会以源码形式打开。本插件不转换旧内容。
- **不负责前台渲染**。主题需要自己把 `post_content` 当 Markdown 渲染；如果主题的渲染器和 `wpautop` / `wptexturize` 顺序冲突，需要在主题侧调整。
- **非管理员作者**可能受 KSES 影响（`&`、代码块里的 HTML 标签可能被改写）。建议给作者 `unfiltered_html`，或只用管理员写作。
- **图片走 ImgURL，不进 WordPress 媒体库**；特色图片仍用 WordPress 媒体库。
- 有 `upload_files` 权限的用户都可以使用配置的 ImgURL Token 上传；多作者站点请自行评估。
- 服务器上传上限会影响代理：默认 10 MB，实际生效值受 `upload_max_filesize` / `post_max_size` 限制，设置页会显示。
- 服务器需要能出站访问 ImgURL；如果上传失败，先检查服务器网络和接口地址。
- **视图模式 Preview** 不渲染图片/表格，也不等于主题前台效果。

## 目录结构

```text
zmd-editor/
├── zmd-editor.php
├── uninstall.php
├── README.md
├── AGENTS.md
├── includes/
│   ├── class-plugin.php
│   ├── class-settings.php
│   ├── class-editor.php
│   ├── class-upload.php
│   └── class-imgurl.php
└── assets/
    ├── overtype/
    │   ├── overtype.min.js
    │   └── LICENSE
    ├── editor.js
    ├── editor.css
    ├── admin.js
    └── admin.css
```

## 验证

- PHP 语法：在 WordPress 容器内用 PHP 8.2 执行 `php -l`。
- JS 语法：`node --check assets/editor.js`、`node --check assets/admin.js`。
- REST 路由：`/zmd/v1/upload`、`/zmd/v1/albums` 已注册。
- 编辑器设置过滤：TinyMCE / quicktags / 媒体按钮关闭，`textarea_rows=20`。
- 保存完整性：普通保存和 heartbeat autosave 的 `&`、代码块 `<div>`、反斜杠、引号原样往返。
- ImgURL：服务器出站、multipart、错误映射（用假 Token 验证过 `invalid.token`）。
- 后台 UI：设置页渲染、按钮对齐、OverType 挂载、视图模式按钮。

## 第三方

- [OverType](https://github.com/panphora/overtype) 2.8.0，MIT License。许可证见 `assets/overtype/LICENSE`。

## License

AGPL-3.0-or-later。见仓库根目录 `LICENSE`。
