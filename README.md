# Serv00 自助开邮箱

在 serv00 上搭一个网页，不用登录 DevilWEB 面板就能自助创建邮箱账号，并附带一个免密直达的站内收件箱。

## 功能

- **自助开邮箱**：填邮箱前缀 + 密码（可手动输入，也可一键随机生成），多域名下拉选择，一键创建
- **站内收件箱**：开好邮箱后点"去邮箱"直接进入收件箱，不用再输密码；也支持账号密码单独登录
- **邮件列表**：两行式布局（发件人 + 时间 / 主题），未读圆点高亮，点开自动标为已读，附件显示 📎
- **HTML 邮件渲染**：HTML 正文在沙盒 iframe 里渲染（禁用脚本），可切换纯文本版
- **创建历史**：开过的邮箱自动记到服务器，支持复制、删除、一键进收件箱、跳官方 webmail
- **访问口令**：给整站加一道口令门，输对一次后会话内免输

## 部署（serv00）

1. 在 DevilWEB 面板里建一个 PHP 类型的网站（WWW Websites），拿到网站根目录（`public_html`）
2. 把本仓库的 `app.php`、`inbox.php`、`index.php` 上传到 `public_html`
3. 在 `public_html` 下新建 `config.php`，只写一行（把密码换成你自己的面板密码）：
   ```php
   <?php define('PANEL_PASS', '你的面板密码');
   ```
4. 打开 `app.php` 顶部的配置区，按自己的信息填写：
   - `PANEL_BASE`：面板地址，把 `panelX` 的 `X` 换成你账号所在的服务器编号
   - `PANEL_USER`：面板用户名
   - `MAIL_DOMAINS`：已在面板里开通了邮件功能的域名列表
   - `ACCESS_CODE`：访问口令，强烈建议设置
5. 打开 `inbox.php` 顶部的配置区，填写 `IMAP_HOST`（`mailX.serv00.com`，`X` 与面板地址对应）
6. 访问你的网站域名，输入访问口令即可使用

> 每个域名需要在面板里至少创建一个邮箱账号（如 `postmaster@你的域名`），该域名的邮件功能才会被激活，之后本页面就能为它开邮箱了。

## 文件说明

| 文件 | 说明 |
|---|---|
| `app.php` | 开邮箱主页 + 创建历史接口 |
| `inbox.php` | 站内收件箱（IMAP 直连） |
| `index.php` | 入口，引入 `app.php` |
| `config.php` | **不要提交到 Git**：存放面板密码，部署时手动创建 |
| `hist_data.php` | **不要提交到 Git**：创建历史数据，运行时自动生成，首行有 PHP 守卫，直接访问返回 403 |

## 安全提醒

- `config.php` 里是你的面板密码，权限建议设为 `0600`，绝不要提交到公开仓库
- 务必设置 `ACCESS_CODE`，否则任何人打开页面都能开邮箱、看到创建历史
- 创建历史（含邮箱密码）存在服务器的 `hist_data.php`，直接访问会被 403 拦截，但请做好服务器本身的安全

## 原理

后端用 PHP cURL 带着面板登录态（cookie）调用 DevilWEB 的开邮箱接口（`POST /mail/details/add_mailbox/{domain}`），Django 的 CSRF 要求 HTTPS POST 带合法 `Referer`；收件箱用原生 socket 直连 IMAP（SSL 993），纯 PHP 实现无第三方依赖。
