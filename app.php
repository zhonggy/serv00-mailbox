<?php
/* ============================================================
 * Serv00 自助开邮箱 + 站内收件箱入口（v2）
 * 表单 -> 后端用 DevilWEB 面板登录态调用开邮箱接口
 * 成功后把账号密码放进 session，"去邮箱"直达站内收件箱 inbox.php
 * ============================================================ */
session_start();

// ===== 配置（部署时按自己的信息填写）=====
define('PANEL_BASE',  'https://panelX.serv00.com'); // 面板地址：把 X 换成你账号所在的服务器编号
define('PANEL_USER',  'your_username');             // 面板用户名
// 开邮箱用的域名（多个）：表单里以下拉框选择。填你已在面板里开通了邮件功能的域名
define('MAIL_DOMAINS', ['example.com']);
define('WEBMAIL_URL', 'https://mail.serv00.com/'); // 官方 webmail（备用）
// 给页面加个访问口令（防陌生人乱开邮箱）。设为 '' 表示不设，强烈建议设置
define('ACCESS_CODE', '');
// ===== 配置结束 =====

// ---- 面板密码：放在 config.php 里（不要提交到 Git），格式：<?php define('PANEL_PASS', '你的面板密码');
function panel_pass() {
    static $v = null;
    if ($v !== null) return $v;
    $cfg = __DIR__ . '/config.php';
    if (file_exists($cfg)) {
        include $cfg;
        if (defined('PANEL_PASS') && PANEL_PASS !== '') return $v = PANEL_PASS;
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

// ---- 口令门（可选）：ACCESS_CODE 非空时启用，输对一次后 session 内免输 ----
if (ACCESS_CODE !== '') {
    $code = $_REQUEST['code'] ?? '';
    if ($code !== '' && $code === ACCESS_CODE) $_SESSION['code_ok'] = true;
    if (empty($_SESSION['code_ok'])) {
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>访问口令</title></head>'
           . '<body style="font-family:-apple-system,sans-serif;background:#f4f6f8;margin:0;padding:40px">'
           . '<div style="max-width:360px;margin:60px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08)">'
           . '<h3 style="margin:0 0 16px">请输入访问口令</h3>'
           . '<form method="post"><input name="code" type="password" required autofocus style="width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ddd;border-radius:8px">'
           . '<button style="width:100%;margin-top:14px;padding:12px;font-size:16px;background:#1a73e8;color:#fff;border:0;border-radius:8px;cursor:pointer">进入</button></form></div></body></html>';
        exit;
    }
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
            $h = array_values(array_filter($h, function ($it) use ($em) { return ($it['email'] ?? '') !== $em; }));
            array_unshift($h, ['email' => $em, 'pass' => $pw, 'time' => date('Y-m-d H:i:s')]);
        }
    } elseif ($a === 'import') {
        $imp = @json_decode($_POST['data'] ?? '[]', true);
        if (is_array($imp)) {
            $have = [];
            foreach ($h as $it) $have[$it['email'] ?? ''] = true;
            foreach (array_reverse($imp) as $it) {
                $em = trim($it['email'] ?? ''); $pw = (string)($it['pass'] ?? '');
                if ($em !== '' && $pw !== '' && !isset($have[$em])) {
                    array_unshift($h, ['email' => $em, 'pass' => $pw, 'time' => (string)($it['time'] ?? date('Y-m-d H:i:s'))]);
                    $have[$em] = true;
                }
            }
        }
    } elseif ($a === 'del') {
        $i = (int)($_POST['idx'] ?? -1);
        if (isset($h[$i])) array_splice($h, $i, 1);
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
body{font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f4f6f8;margin:0;padding:24px}
.card{max-width:480px;margin:40px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
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
<div class="card">
  <h2>开邮箱</h2>
  <div class="sub">支持域名：<?= h(implode('、', MAIL_DOMAINS)) ?> · 不用登录面板 · v7</div>
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
    <?php if (ACCESS_CODE !== ''): ?><input type="hidden" name="code" value="<?= h($_REQUEST['code'] ?? '') ?>"><?php endif; ?>
    <button type="submit">开邮箱</button>
  </form>
  <div class="hist" id="hist" style="display:none">
    <div class="hist-head"><b>📋 创建历史</b><button type="button" class="clearbtn" id="histClear">清空</button></div>
    <div id="histList"></div>
  </div>
  <div class="note">后端用面板登录态调用开邮箱接口；邮箱密码只在 session 里流转，不落地。<br>创建历史保存在服务器上（防直接访问）；记得给本页加上访问口令。</div>
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
    '<button type="button" class="hdel" onclick="delHist('+i+')">删除</button></div></div>';
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
