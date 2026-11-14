# ZMD Editor

适用于 WordPress 的 Markdown 编辑器插件，支持将图片上传到 [ImgURL图床](https://www.imgurl.org/) 。文章内容保存为原始 Markdown ，推荐搭配支持 Markdown 渲染的主题 [ztheme](https://github.com/helloxz/ztheme) 一起使用。

![image.webp](https://p.uuu.ovh/2026/11/14/tIs0YNg9.webp)

![image.webp](https://p.uuu.ovh/2026/11/14/UJN2F6DD.webp)

> 备注：若 WordPress 主题不支持渲染 Markdown 内容，格式将无法正常显示！

## 功能与特点

- 经典编辑器屏幕 + OverType，Markdown 标记可见，不做富文本渲染
- `post_content` 保存原始 Markdown；发布、自动保存、修订、草稿都走 WordPress 原生流程
- 保存时保护原始 Markdown，避免实体/标签改写
- ImgURL 图片上传（服务端代理，Token 不进页面）
- 工具栏上传、粘贴上传、拖拽上传，自动插入 `![alt](url)`
- 视图模式（普通编辑 / 纯文本 / Preview）
- 可配置启用的文章类型（默认文章 + 页面）、ImgURL 地址、Token、相册、去重/水印/压缩、大小限制、响应 URL 路径
- 对启用的文章类型不加载 Gutenberg；未勾选的类型保持原样

## 安装

1. 把 `zmd-editor` 目录放到 `wp-content/plugins/`。
2. 在 WordPress 后台“插件”里启用 **ZMD Editor**。
3. 打开后台左侧 **ZMD Editor**，填写 ImgURL Token（`sk-` 开头），保存后点“测试连接”。
4. 新建或编辑文章，内容区会变成 OverType。

详细配置、实现说明和边界见 [AGENTS.md](AGENTS.md)。

## 联系作者

- 博客：[https://blog.xiaoz.org](https://blog.xiaoz.org)
- X：[https://x.com/xiaozblog](https://x.com/xiaozblog)

## License

AGPL-3.0-or-later。见仓库根目录 `LICENSE`。
