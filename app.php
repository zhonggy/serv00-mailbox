<?php
/* ============================================================
 * Serv00 自助开邮箱 + 站内收件箱入口（v2）
 * 表单 -> 后端用 DevilWEB 面板登录态调用开邮箱接口
 * 成功后把账号密码放进 session，"去邮箱"直达站内收件箱 inbox.php
 * ============================================================ */
session_start();
date_default_timezone_set('Asia/Shanghai'); // 显示时间统一用北京时间

// ===== 配置（部署时按自己的信息填写）=====
define('PANEL_BASE',  'https://panelX.serv00.com'); // 面板地址
define('PANEL_USER',  'your_username');                       // 面板用户名
// 开邮箱用的域名（多个）：表单里以下拉框选择，要加域名改这里
define('MAIL_DOMAINS', ['example.com']);
define('WEBMAIL_URL', 'https://mail.serv00.com/'); // 官方 webmail（备用）
// 管理员邮箱：用这个邮箱登录进开邮箱页，其他邮箱登录进收件箱。
// 密码不写在这里，登录时由邮件服务器当场验证。
define('ADMIN_EMAIL', 'admin@example.com');
// ===== 配置结束 =====

// ---- 面板密码：config.php -> 从旧版 mailbox.php 自动迁移 -> 空 ----
function panel_pass() {
    static $v = null;
    if ($v !== null) return $v;
    $cfg = __DIR__ . '/config.php';
    if (file_exists($cfg)) {
        include $cfg;
        if (defined('PANEL_PASS') && PANEL_PASS !== '') return $v = PANEL_PASS;
    }
    // 从同目录旧版 mailbox.php 迁移（用户之前在里面填过密码）
    $legacy = __DIR__ . '/mailbox.php';
    if (file_exists($legacy)) {
        $src = file_get_contents($legacy);
        if (preg_match("/define\s*\(\s*'PANEL_PASS'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $src, $m) && $m[1] !== '') {
            $pw = stripcslashes($m[1]);
            file_put_contents($cfg, "<?php define('PANEL_PASS', '" . addcslashes($pw, "'\\") . "');\n");
            @chmod($cfg, 0600);
            // 从旧文件里擦掉密码
            $src2 = preg_replace("/define\s*\(\s*'PANEL_PASS'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", "define('PANEL_PASS', '')", $src, 1);
            if ($src2 !== null && $src2 !== $src) @file_put_contents($legacy, $src2);
            return $v = $pw;
        }
    }
    return $v = '';
}

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- 面板会话（cookie jar 落在临时目录，0600）----
function jar_path() {
    $p = rtrim(sys_get_temp_dir(), '/') . '/srv00_panel_cookies.txt';
    if (!file_exists($p)) { touch($p); chmod($p, 0600); }
    return $p;
}

// $referer：Django 的 CSRF 保护要求 HTTPS POST 必须带合法 Referer
function http($url, $post = null, $referer = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => jar_path(),
        CURLOPT_COOKIEFILE     => jar_path(),
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_REFERER, $referer ?: $url);
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$body === false ? '' : $body, $err, $code];
}

function abs_url($base, $action) {
    if (!$action) return $base;
    if (preg_match('#^https?://#i', $action)) return $action;
    return rtrim(PANEL_BASE, '/') . '/' . ltrim($action, '/');
}

// 登录态是否有效
function session_ok() {
    list($mail, , ) = http(PANEL_BASE . '/mail/');
    if (!$mail) return false;
    if (stripos($mail, 'add_mailbox') !== false) return true;
    if (preg_match('#log\s?out#i', $mail)) return true;
    if (stripos($mail, 'Logged in as') !== false) return true;
    if (stripos($mail, 'type="password"') !== false) return false;
    return stripos($mail, PANEL_USER) !== false;
}

// ---- 登录面板（通用 Django 表单解析）----
function panel_login() {
    $ppass = panel_pass();
    if ($ppass === '') return '还没配置面板密码';
    $loginPage = PANEL_BASE . '/';
    list($html, $err, ) = http($loginPage);
    if ($err || !$html) return '打不开面板首页：' . h($err);
    if (session_ok()) return true;

    $form = null;
    preg_match_all('#<form\b[^>]*>.*?</form>#is', $html, $forms);
    foreach ($forms[0] as $f) {
        if (preg_match('#type=["\']password["\']#i', $f)) { $form = $f; break; }
    }
    if (!$form) return '没找到登录表单，面板页面结构可能变了';

    preg_match('#action=["\']([^"\']*)["\']#i', $form, $am);
    $action = abs_url($loginPage, $am[1] ?? '');

    preg_match('#name=["\']csrfmiddlewaretoken["\']\s+value=["\']([^"\']+)["\']#i', $form, $cm);
    if (!$cm) preg_match('#value=["\']([^"\']+)["\']\s+name=["\']csrfmiddlewaretoken["\']#i', $form, $cm);
    $token = $cm[1] ?? '';

    $post = [];
    preg_match_all('#<input\b[^>]*>#i', $form, $inputs);
    $userField = null; $passField = null;
    foreach ($inputs[0] as $inp) {
        preg_match('#name=["\']([^"\']+)["\']#i', $inp, $nm);
        preg_match('#type=["\']([^"\']+)["\']#i', $inp, $tp);
        $name = $nm[1] ?? null; $type = strtolower($tp[1] ?? 'text');
        if (!$name) continue;
        if ($type === 'hidden') {
            preg_match('#value=["\']([^"\']*)["\']#i', $inp, $vm);
            $post[$name] = $vm[1] ?? '';
        } elseif ($type === 'password') {
            $passField = $name;
        } elseif ($type === 'text' && $userField === null && stripos($name, 'token') === false) {
            $userField = $name;
        }
    }
    if ($token) $post['csrfmiddlewaretoken'] = $token;
    if ($userField) $post[$userField] = PANEL_USER; else $post['username'] = PANEL_USER;
    if ($passField) $post[$passField] = $ppass; else $post['password'] = $ppass;

    list($res, $err2, $code2) = http($action, $post, $loginPage);
    if ($err2) return '登录请求失败：' . h($err2);
    if ($code2 === 403 && stripos($res, 'CSRF') !== false) {
        return '被面板 CSRF 拦截（403），请检查服务器出站 IP 是否被面板屏蔽';
    }
    if (session_ok()) return true;
    if (preg_match('#type=["\']password["\']#i', $res)) {
        return '面板用户名或密码不对（仍停留在登录页），请检查面板密码配置';
    }
    return '登录状态无法确认，请检查配置';
}

function panel_ensure_login() {
    if (session_ok()) return true;
    @unlink(jar_path());
    return panel_login();
}

// ---- 开邮箱 ----
function create_mailbox($prefix, $pw, $domain) {
    $login = panel_ensure_login();
    if ($login !== true) return [false, $login];

    $url = PANEL_BASE . '/mail/details/add_mailbox/' . $domain;
    list($html, $err, ) = http($url);
    if ($err || !$html) return [false, '打不开开邮箱页面：' . h($err)];
    preg_match('#name="csrfmiddlewaretoken" value="([^"]+)"#', $html, $cm);
    if (!$cm) return [false, '没取到 CSRF token，面板页面可能改版了'];

    $full = $prefix . '@' . $domain;
    list($res, $err2, $code2) = http($url, [
        'csrfmiddlewaretoken'    => $cm[1],
        'email'                  => $full,
        'id_email-placeholder-1' => $prefix,
        'password1'              => $pw,
        'password2'              => $pw,
    ], $url);
    if ($err2) return [false, '提交失败：' . h($err2)];
    if ($code2 === 403) return [false, '提交被面板拒绝（403），可能是 CSRF 或权限问题'];

    if (preg_match_all('#<ul class="errorlist[^"]*">(.*?)</ul>#is', $res, $em)) {
        $msgs = [];
        foreach ($em[1] as $e) $msgs[] = trim(strip_tags($e));
        return [false, '面板拒绝了：' . h(implode('；', array_unique($msgs)))];
    }
    if (stripos($res, $full) !== false) return [true, $full];
    if (stripos($res, 'success') !== false) return [true, $full];
    return [false, '结果不明确（没看到成功/失败标记），请去面板确认 ' . h($full) . ' 是否已建好'];
}

// ---- 统一登录：管理员邮箱进开邮箱页，普通邮箱进收件箱 ----
// 管理员只认邮箱地址，密码由邮件服务器当场验证，网站上不存管理员密码。
// 登录成功一次后 session 内免登录；退出点开邮箱页右上角的「退出」。
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_ok']);
    header('Location: ./');
    exit;
}
function imap_esc($s) { return str_replace(['\\', '"'], ['\\\\', '\\"'], $s); }
function imap_cmd($fp, $tag, $cmd) {
    fwrite($fp, $tag . ' ' . $cmd . "\r\n");
    $lines = [];
    while (($line = fgets($fp, 65536)) !== false) {
        $t = rtrim($line, "\r\n");
        if (preg_match('/\{(\d+)\}$/', $t, $m)) {
            $need = (int)$m[1]; $data = '';
            while ($need > 0 && !feof($fp)) {
                $chunk = fread($fp, $need);
                if ($chunk === false || $chunk === '') break;
                $data .= $chunk; $need -= strlen($chunk);
            }
            $lines[] = $t . $data;
            continue;
        }
        $lines[] = $t;
        if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $t, $m2)) {
            return [strtoupper($m2[1]) === 'OK', $lines];
        }
    }
    return [false, $lines];
}
// 只验证账号密码是否正确，不读任何邮件
function imap_check_login($email, $pass) {
    $fp = @fsockopen('ssl://mailX.serv00.com', 993, $errno, $errstr, 20);
    if (!$fp) return [false, '连不上邮件服务器：' . $errstr . "（$errno)"];
    stream_set_timeout($fp, 25);
    $greet = fgets($fp, 512);
    if ($greet === false || stripos($greet, '* OK') !== 0) { fclose($fp); return [false, '邮件服务器无响应']; }
    $r = imap_cmd($fp, 'a001', 'LOGIN "' . imap_esc($email) . '" "' . imap_esc($pass) . '"');
    imap_cmd($fp, 'z999', 'LOGOUT');
    fclose($fp);
    if (!$r[0]) return [false, '邮箱登录失败：账号或密码不对'];
    return [true, null];
}
if (empty($_SESSION['admin_ok'])) {
    $login_err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['email'])) {
        $lem = strtolower(trim($_POST['email'] ?? ''));
        $lpw = $_POST['mpw'] ?? '';
        if ($lem !== '' && $lpw !== '') {
            list($lok, $lerr) = imap_check_login($lem, $lpw);
            if ($lok) {
                if ($lem === strtolower(ADMIN_EMAIL)) {
                    $_SESSION['admin_ok'] = true;
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
                }
                $_SESSION['mb_email'] = $lem;
                $_SESSION['mb_pass'] = $lpw;
                header('Location: inbox.php'); exit;
            }
            $login_err = $lerr;
            sleep(1); // 失败拖慢 1 秒，拖慢爆破
        } else {
            $login_err = '请输入邮箱和密码。';
        }
    }
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>邮箱登录</title></head>'
       . '<body style="font-family:-apple-system,sans-serif;background:#f4f6f8;margin:0;padding:40px">'
       . '<div style="max-width:360px;margin:60px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08)">'
       . '<h3 style="margin:0 0 20px;text-align:center;font-size:36px">邮箱登录</h3>'
       . ($login_err !== '' ? '<p style="color:#d93025;margin:0 0 12px">' . h($login_err) . '</p>' : '')
       . '<form method="post"><label style="display:block;font-size:14px;margin:12px 0 6px;color:#555">邮箱地址</label>'
       . '<input name="email" type="email" required autofocus placeholder="例如 zhangsan@example.com" value="' . h($_POST['email'] ?? '') . '" style="width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ddd;border-radius:8px">'
       . '<label style="display:block;font-size:14px;margin:12px 0 6px;color:#555">密码</label>'
       . '<input name="mpw" type="password" required style="width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ddd;border-radius:8px">'
       . '<button style="width:100%;margin-top:18px;padding:12px;font-size:16px;background:#1a73e8;color:#fff;border:0;border-radius:8px;cursor:pointer">登录</button></form></div></body></html>';
    exit;
}

$result = null;
$shown_pw = null;

// ---- 创建历史：存服务器 ----
// serv00 的 PHP 有 open_basedir 限制，只能读写 public_html 里面；
// 所以历史文件放在 public_html 内，但用 .php 后缀 + 首行守卫：浏览器直接访问只会得到 403。
define('HISTORY_FILE', __DIR__ . '/hist_data.php');
define('HISTORY_GUARD', "<?php http_response_code(403); exit('forbidden'); ?>\n");
function hist_load() {
    if (!@is_file(HISTORY_FILE)) return [];
    $c = @file_get_contents(HISTORY_FILE);
    if ($c === false) return [];
    if (($p = strpos($c, '?>')) !== false) $c = substr($c, $p + 2);
    $d = @json_decode(trim($c), true);
    return is_array($d) ? $d : [];
}
function hist_save($h) {
    $h = array_values(array_slice($h, 0, 200));
    $data = HISTORY_GUARD . json_encode($h, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return @file_put_contents(HISTORY_FILE, $data, LOCK_EX) !== false;
}
function hist_json($extra = []) {
    while (ob_get_level() > 0) ob_end_clean(); // 丢弃之前可能混入的 Warning，保证纯 JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true, 'list' => hist_load()], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
// 历史 API：POST hist_action=add/del/clear/import；GET ?hist=1 拉列表
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hist_action'])) {
    $a = $_POST['hist_action'];
    $h = hist_load();
    if ($a === 'add') {
        $em = trim($_POST['email'] ?? ''); $pw = (string)($_POST['pass'] ?? '');
        if ($em !== '' && $pw !== '') {
            // 同一邮箱重建时保留旧标签
            $oldTag = '';
            foreach ($h as $it) { if (($it['email'] ?? '') === $em) { $oldTag = (string)($it['tag'] ?? ''); break; } }
            $h = array_values(array_filter($h, function ($it) use ($em) { return ($it['email'] ?? '') !== $em; }));
            array_unshift($h, ['email' => $em, 'pass' => $pw, 'time' => date('Y-m-d H:i:s'), 'tag' => $oldTag]);
        }
    } elseif ($a === 'import') {
        $imp = @json_decode($_POST['data'] ?? '[]', true);
        if (is_array($imp)) {
            $have = [];
            foreach ($h as $it) $have[$it['email'] ?? ''] = true;
            foreach (array_reverse($imp) as $it) {
                $em = trim($it['email'] ?? ''); $pw = (string)($it['pass'] ?? '');
                if ($em !== '' && $pw !== '' && !isset($have[$em])) {
                    array_unshift($h, ['email' => $em, 'pass' => $pw, 'time' => (string)($it['time'] ?? date('Y-m-d H:i:s')), 'tag' => (string)($it['tag'] ?? '')]);
                    $have[$em] = true;
                }
            }
        }
    } elseif ($a === 'del') {
        $i = (int)($_POST['idx'] ?? -1);
        if (isset($h[$i])) array_splice($h, $i, 1);
    } elseif ($a === 'tag') {
        $i = (int)($_POST['idx'] ?? -1);
        $tag = trim((string)($_POST['tag'] ?? ''));
        if (isset($h[$i])) $h[$i]['tag'] = mb_substr($tag, 0, 30);
    } elseif ($a === 'clear') {
        $h = [];
    }
    $saved = hist_save($h);
    hist_json(['saved' => $saved]);
}
if (isset($_GET['hist'])) hist_json();
// 历史条目一键进收件箱：把该邮箱账号密码写入 session 后跳转 inbox.php
if (isset($_GET['goinbox'])) {
    $em = (string)$_GET['goinbox'];
    foreach (hist_load() as $it) {
        if (($it['email'] ?? '') === $em && ($it['pass'] ?? '') !== '') {
            $_SESSION['mb_email'] = $it['email'];
            $_SESSION['mb_pass'] = $it['pass'];
            header('Location: inbox.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prefix'])) {
    if (panel_pass() === '') {
        $result = [false, '还没配置面板密码，无法调用开邮箱接口。'];
    } else {
        $prefix = strtolower(trim($_POST['prefix'] ?? ''));
        $pw1 = $_POST['pw1'] ?? '';
        $domain = $_POST['domain'] ?? '';
        if (!in_array($domain, MAIL_DOMAINS, true)) {
            $result = [false, '所选域名不在允许列表中。'];
        } elseif (!preg_match('/^[a-z0-9][a-z0-9._-]{0,30}[a-z0-9]$/', $prefix)) {
            $result = [false, '邮箱前缀不合法：1-32 位，字母数字及 . _ -，首尾不能是符号。'];
        } elseif (strlen($pw1) < 8) {
            $result = [false, '密码至少 8 位。'];
        } else {
            $result = create_mailbox($prefix, $pw1, $domain);
            if ($result[0]) {
                // 成功：把账号密码放进 session，直达站内收件箱
                $_SESSION['mb_email'] = $result[1];
                $_SESSION['mb_pass']  = $pw1;
                $shown_pw = $pw1;
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>开邮箱</title>
<style>
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#eef3f8 url('bg-lowpoly-light.webp') no-repeat center top fixed;background-size:cover;margin:0;padding:24px}
.wrap{max-width:980px;margin:24px auto;display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
.tile{background:rgba(255,255,255,.84);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.95);border-radius:18px;padding:24px;box-shadow:0 6px 24px rgba(110,140,180,.10)}
.hero{grid-column:1/-1;display:flex;align-items:center;gap:18px;background:linear-gradient(135deg,rgba(224,242,254,.94),rgba(237,233,254,.94))}
.hero .icon{font-size:46px;line-height:1}
.hero h2{margin:0;font-size:24px;color:#1e3a5f}
.hero .sub{color:#5b7a99;font-size:13px;margin-top:6px}
.tile-form{grid-column:span 4}
.tile-form h3{margin:0 0 4px;font-size:17px;color:#1e3a5f}
.tile-side{grid-column:span 2;display:flex;flex-direction:column;gap:14px}
.mini{background:#f2f7fd;border:1px dashed #cfe0f2;border-radius:14px;padding:16px;font-size:13px;color:#4a6a8a;line-height:1.8}
.mini b{color:#2b5a8c}
.tile-hist{grid-column:1/-1}
.tile-hist.hist{margin-top:0;border-top:0;padding-top:24px}
@media(max-width:720px){.tile-form,.tile-side{grid-column:1/-1}.tile-hist{order:3}.tile-side{order:4}.hero .icon{font-size:36px}}
h2{margin:0 0 6px;font-size:20px}
.sub{color:#888;font-size:13px;margin-bottom:18px}
label{display:block;font-size:14px;margin:12px 0 6px;color:#333}
input{width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ddd;border-radius:8px}
.addr{display:flex;align-items:center;gap:6px}
.addr input{flex:1}
.addr span{color:#666;font-size:14px;white-space:nowrap}
button{width:100%;margin-top:18px;padding:12px;font-size:16px;background:#1a73e8;color:#fff;border:0;border-radius:8px;cursor:pointer}
button:hover{background:#1663c7}
.ok{background:#e6f4ea;color:#137333;border-radius:8px;padding:12px;margin-top:16px;font-size:14px}
.err{background:#fce8e6;color:#a50e0e;border-radius:8px;padding:12px;margin-top:16px;font-size:14px}
.go{display:block;text-align:center;margin-top:12px;padding:12px;background:#188038;color:#fff;border-radius:8px;text-decoration:none;font-size:16px}
.small{display:block;text-align:center;margin-top:10px;font-size:13px;color:#1a73e8}
.note{font-size:12px;color:#999;margin-top:14px}
.row{display:flex;gap:8px;align-items:center}
.row .addr{flex:1}
.row input{flex:1}
.dice{width:46px;flex:none;margin:0;padding:9px 0;font-size:18px;background:#f1f3f4;color:#333;border:1px solid #ddd;border-radius:8px;cursor:pointer}
.dice:hover{background:#e2e6ea}
.cred{display:flex;justify-content:space-between;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;margin-top:10px;font-size:14px}
.cred span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cpbtn{width:auto;flex:none;margin:0;padding:7px 16px;font-size:13px}
.dsel{flex:none;width:auto;padding:10px 6px;font-size:14px;border:1px solid #ddd;border-radius:8px;background:#fff;color:#333}
.hist{margin-top:20px;border-top:1px solid #eee;padding-top:14px}
.hist-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.hist-head b{font-size:15px}
.htag{padding:6px 8px;font-size:13px;border:1px solid #ddd;border-radius:6px;width:120px;background:#fff}
.clearbtn{width:auto;flex:none;margin:0;padding:6px 14px;font-size:13px;background:#f1f3f4;color:#666;border:1px solid #ddd;border-radius:6px;cursor:pointer}
.hitem{border:1px solid #e8e8e8;border-radius:8px;padding:10px 12px;margin-bottom:8px;background:#fbfcfd}
.hrow1{display:flex;justify-content:space-between;align-items:center;gap:8px}
.hemail{font-weight:600;font-size:14px;word-break:break-all}
.htime{font-size:12px;color:#999;white-space:nowrap;flex:none}
.hpass{font-family:Consolas,monospace;font-size:13px;color:#555;margin-top:4px;word-break:break-all}
.hacts{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.hdel{width:auto;flex:none;margin:0;padding:7px 12px;font-size:13px;background:#fff;color:#a50e0e;border:1px solid #f0c0c0;border-radius:6px;cursor:pointer}
.hgo{display:inline-block;width:auto;flex:none;margin:0;padding:7px 14px;font-size:13px;background:#188038;color:#fff;border:0;border-radius:6px;cursor:pointer;text-decoration:none}
.hweb{display:inline-block;width:auto;flex:none;margin:0;padding:7px 14px;font-size:13px;background:#fff;color:#1a73e8;border:1px solid #c6dafc;border-radius:6px;cursor:pointer;text-decoration:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="tile hero">
    <div class="icon">✉️</div>
    <div>
      <h2>开邮箱</h2>
      <div class="sub">支持域名：<?= h(implode('、', MAIL_DOMAINS)) ?> · 不用登录面板 · v9</div>
    </div>
    <a href="?logout=1" style="margin-left:auto;align-self:center;font-size:13px;color:#1a73e8;white-space:nowrap;text-decoration:none">退出</a>
  </div>
  <div class="tile tile-form">
    <h3>新建邮箱</h3>
  <?php if ($result !== null): ?>
    <?php if ($result[0]): ?>
      <div class="ok">✅ 开好了</div>
      <div class="cred"><span>邮箱：<b id="cp_email"><?= h($result[1]) ?></b></span><button type="button" class="cpbtn" onclick="copyText('cp_email',this)">复制</button></div>
      <div class="cred"><span>密码：<b id="cp_pass"><?= h($shown_pw) ?></b></span><button type="button" class="cpbtn" onclick="copyText('cp_pass',this)">复制</button></div>
      <a class="go" href="inbox.php" target="_blank" rel="noopener">去邮箱 →</a>
      <div class="note">直接进入站内收件箱（新标签页），无需再输密码。</div>
      <a class="small" target="_blank" rel="noopener" href="<?= h(WEBMAIL_URL . '?_user=' . urlencode($result[1])) ?>">官方 webmail（备用）</a>
    <?php else: ?>
      <div class="err">❌ <?= $result[1] ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post">
    <label>邮箱前缀</label>
    <div class="row">
      <button type="button" class="dice" onclick="randPrefix()" title="随机生成">🎲</button>
      <div class="addr"><input id="prefix" name="prefix" required maxlength="32" pattern="[a-zA-Z0-9._-]{1,32}"
        placeholder="可手动输入，或点🎲随机" value="<?= h($_POST['prefix'] ?? '') ?>"><select name="domain" class="dsel"><?php foreach (MAIL_DOMAINS as $d): ?><option value="<?= h($d) ?>"<?= (($_POST['domain'] ?? '') === $d) ? ' selected' : '' ?>>@<?= h($d) ?></option><?php endforeach; ?></select></div>
    </div>
    <label>密码（至少 8 位，随机生成含大小写+数字）</label>
    <div class="row">
      <button type="button" class="dice" onclick="randPass()" title="随机生成">🎲</button>
      <input id="pw" name="pw1" type="text" required minlength="8" placeholder="可手动输入，或点🎲随机">
    </div>
    <button type="submit">开邮箱</button>
  </form>
  </div>
  <div class="tile-side">
    <div class="mini"><b>📌 小贴士</b><br>密码至少 8 位；点 🎲 可随机生成前缀和密码。</div>
    <div class="mini"><b>🔒 安全</b><br>邮箱密码只在 session 里流转，不落地；创建历史保存在服务器上（防直接访问）。</div>
    <div class="mini"><b>📥 收件</b><br>开好后点「去邮箱」直达站内收件箱，也可用官方 webmail 备用。</div>
  </div>
  <div class="tile tile-hist hist" id="hist" style="display:none">
    <div class="hist-head"><b>📋 创建历史</b><button type="button" class="clearbtn" id="histClear">清空</button></div>
    <div id="histList"></div>
  </div>
</div>
<script>
function randPrefix(){
  var c='abcdefghijklmnopqrstuvwxyz0123456789', s='';
  for(var i=0;i<10;i++) s+=c[Math.floor(Math.random()*c.length)];
  document.getElementById('prefix').value=s;
}
function randPass(){
  var up='ABCDEFGHJKLMNPQRSTUVWXYZ', lo='abcdefghijkmnpqrstuvwxyz', dg='23456789';
  var all=up+lo+dg, s='';
  s+=up[Math.floor(Math.random()*up.length)];
  s+=lo[Math.floor(Math.random()*lo.length)];
  s+=dg[Math.floor(Math.random()*dg.length)];
  for(var i=3;i<12;i++) s+=all[Math.floor(Math.random()*all.length)];
  s=s.split('').sort(function(){return Math.random()-0.5;}).join('');
  document.getElementById('pw').value=s;
}
function copyText(id,btn){
  var t=document.getElementById(id).textContent;
  var done=function(){btn.textContent='已复制';setTimeout(function(){btn.textContent='复制';},1500);};
  var fb=function(){
    var ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();
    try{document.execCommand('copy');done();}catch(e){}
    document.body.removeChild(ta);
  };
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(fb);}
  else fb();
}
// 创建历史：存服务器（hist_data.php，带防直接访问守卫）
var WEBMAIL_URL=<?= json_encode(WEBMAIL_URL) ?>;
function escH(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function renderHistList(h){
  var box=document.getElementById('hist'),list=document.getElementById('histList');
  if(!h||!h.length){box.style.display='none';return;}
  box.style.display='block';
  list.innerHTML=h.map(function(it,i){
    return '<div class="hitem"><div class="hrow1"><span class="hemail">'+escH(it.email)+'</span><span class="htime">'+escH(it.time||'')+'</span></div>'+
    '<div class="hpass">'+escH(it.pass)+'</div>'+
    '<div class="hacts"><button type="button" class="cpbtn" onclick="copyHistEmail('+i+',this)">复制邮箱</button>'+
    '<button type="button" class="cpbtn" onclick="copyHistPass('+i+',this)">复制密码</button>'+
    '<a class="hgo" target="_blank" rel="noopener" href="?goinbox='+encodeURIComponent(it.email)+'">去邮箱 →</a>'+
    '<a class="hweb" target="_blank" rel="noopener" href="'+WEBMAIL_URL+'?_user='+encodeURIComponent(it.email)+'">官方备用</a>'+
    '<button type="button" class="hdel" onclick="delHist('+i+')">删除</button>'+
    '<input class="htag" type="text" placeholder="标签" maxlength="30" value="'+escH(it.tag||'')+'" onchange="saveTag('+i+',this.value)"></div></div>';
  }).join('');
}
var _histCache=[];
function histApi(action,data){
  var fd=new FormData();fd.append('hist_action',action);
  for(var k in data)fd.append(k,data[k]);
  return fetch('',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(j&&j.ok){_histCache=j.list||[];renderHistList(_histCache);}return j;
  }).catch(function(){return null;});
}
function loadHistServer(){
  fetch('?hist=1',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
    if(j&&j.ok){_histCache=j.list||[];renderHistList(_histCache);}
  }).catch(function(){});
}
function addHist(email,pass){histApi('add',{email:email,pass:pass});}
function delHist(i){histApi('del',{idx:i});}
function saveTag(i,v){histApi('tag',{idx:i,tag:v});}
function copyStr(t,btn){
  var done=function(){var o=btn.textContent;btn.textContent='已复制';setTimeout(function(){btn.textContent=o;},1500);};
  var fb=function(){var ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}document.body.removeChild(ta);};
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(fb);}else fb();
}
function copyHistEmail(i,btn){if(_histCache[i])copyStr(_histCache[i].email,btn);}
function copyHistPass(i,btn){if(_histCache[i])copyStr(_histCache[i].pass,btn);}
document.getElementById('histClear').onclick=function(){if(confirm('确定清空全部创建历史吗？'))histApi('clear',{});};
// 把旧版 localStorage 里的记录一次性迁到服务器
(function(){
  var old=null;try{old=JSON.parse(localStorage.getItem('smb_history_v1'))||[];}catch(e){}
  if(old&&old.length){
    histApi('import',{data:JSON.stringify(old)}).then(function(){try{localStorage.removeItem('smb_history_v1');}catch(e){}});
  }else{loadHistServer();}
})();
</script>
<?php if ($result !== null && $result[0]): ?>
<script>addHist(<?= json_encode($result[1], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($shown_pw, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>);</script>
<?php endif; ?>
</body>
</html>
