<?php
/* ============================================================
 * 站内极简邮箱（v2）
 * 纯 PHP 直连 IMAP（在配置区填写你账号对应的 mail 服务器）。
 * 账号密码来自开邮箱成功后写入的 session，或本页登录表单。
 * ============================================================ */
session_start();
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// ===== 配置（部署时按自己的信息填写）=====
define('IMAP_HOST', 'mailX.serv00.com'); // IMAP 服务器：把 X 换成你账号所在的服务器编号（与面板地址对应）
define('IMAP_PORT', 993);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: inbox.php');
    exit;
}

$email = $_SESSION['mb_email'] ?? null;
$pass  = $_SESSION['mb_pass'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['mpw'] ?? '';
    if ($email !== '' && $pass !== '') {
        $_SESSION['mb_email'] = $email;
        $_SESSION['mb_pass']  = $pass;
    } else {
        $email = $pass = null;
    }
}

/* ---------------- IMAP 底层 ---------------- */
function smb_esc($s) { return str_replace(['\\', '"'], ['\\\\', '\\"'], $s); }

function smb_cmd($fp, $tag, $cmd) {
    fwrite($fp, $tag . ' ' . $cmd . "\r\n");
    $lines = [];
    while (($line = fgets($fp, 65536)) !== false) {
        $t = rtrim($line, "\r\n");
        if (preg_match('/\{(\d+)\}$/', $t, $m)) {
            // literal：精确读 N 字节拼到行尾
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

function smb_login($email, $pass) {
    $fp = @fsockopen('ssl://' . IMAP_HOST, IMAP_PORT, $errno, $errstr, 20);
    if (!$fp) return [null, '连不上邮件服务器：' . $errstr . "（$errno)"];
    stream_set_timeout($fp, 25);
    $greet = fgets($fp, 512);
    if ($greet === false || stripos($greet, '* OK') !== 0) {
        fclose($fp);
        return [null, '邮件服务器无响应'];
    }
    $r = smb_cmd($fp, 'a001', 'LOGIN "' . smb_esc($email) . '" "' . smb_esc($pass) . '"');
    if (!$r[0]) { fclose($fp); return [null, '邮箱登录失败：账号或密码不对']; }
    return [$fp, null];
}

/* ---------------- 解析 ---------------- */
function parse_headers_block($text) {
    $headers = []; $cur = null;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
        if ($cur !== null && preg_match('/^[ \t]/', $line)) {
            $headers[$cur] .= ' ' . trim($line);
        } elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
            $cur = strtolower(trim($m[1]));
            $headers[$cur] = trim($m[2]);
        }
    }
    return $headers;
}

function mime_dec($s) {
    if (function_exists('mb_decode_mimeheader')) return mb_decode_mimeheader($s);
    return preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
        $t = (strtoupper($m[2]) === 'B') ? base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        return $t === false ? $m[0] : $t;
    }, $s);
}

function to_utf8($body, $charset) {
    $charset = strtolower(trim($charset, '"\' '));
    if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8') return $body;
    if (function_exists('mb_convert_encoding')) {
        $r = @mb_convert_encoding($body, 'UTF-8', $charset);
        if ($r !== false) return $r;
    }
    if (function_exists('iconv')) {
        $r = @iconv($charset, 'UTF-8//IGNORE', $body);
        if ($r !== false) return $r;
    }
    return $body;
}

// 从原始邮件里抠出可读正文（优先 text/plain，其次 text/html 去标签）
function extract_text($raw) {
    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
    $htext = ltrim($parts[0]); $body = $parts[1] ?? '';
    $hd = parse_headers_block($htext);
    $ct = strtolower($hd['content-type'] ?? 'text/plain');
    if (strpos($ct, 'multipart/') === 0 && preg_match('/boundary="?([^";\s]+)"?/i', $ct, $m)) {
        $b = $m[1];
        $chunks = explode('--' . $b, $body);
        foreach (['text/plain', 'text/html'] as $want) {
            foreach ($chunks as $ch) {
                if (stripos($ch, $want) !== false) {
                    $t = extract_text($ch);
                    if (trim($t) !== '') return $t;
                }
            }
        }
        return '';
    }
    $cte = strtolower($hd['content-transfer-encoding'] ?? '');
    if (strpos($cte, 'base64') !== false) { $d = base64_decode($body); if ($d !== false) $body = $d; }
    elseif (strpos($cte, 'quoted-printable') !== false) { $body = quoted_printable_decode($body); }
    $charset = 'utf-8';
    if (preg_match('/charset="?([^";\s]+)"?/i', $ct, $m)) $charset = $m[1];
    if (strpos($ct, 'text/html') !== false) {
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $body = to_utf8($body, $charset);
    return trim($body);
}

// 解码单个 MIME part 的 body（base64 / quoted-printable + 转 UTF-8）
function decode_part_body($body, $hd) {
    $cte = strtolower($hd['content-transfer-encoding'] ?? '');
    if (strpos($cte, 'base64') !== false) { $d = base64_decode($body); if ($d !== false) $body = $d; }
    elseif (strpos($cte, 'quoted-printable') !== false) { $body = quoted_printable_decode($body); }
    $ct = strtolower($hd['content-type'] ?? '');
    $charset = 'utf-8';
    if (preg_match('/charset="?([^";\s]+)"?/i', $ct, $m)) $charset = $m[1];
    return to_utf8($body, $charset);
}

// 递归在 multipart 里找第一个 Content-Type 含 $want 的 part，返回解码后正文；找不到返回 null
function find_part($raw, $want) {
    $parts = preg_split("/\r?\n\r?\n/", ltrim($raw), 2);
    $htext = $parts[0]; $body = $parts[1] ?? '';
    $hd = parse_headers_block($htext);
    $ct = strtolower($hd['content-type'] ?? 'text/plain');
    if (strpos($ct, 'multipart/') === 0 && preg_match('/boundary="?([^";\s]+)"?/i', $ct, $m)) {
        foreach (explode('--' . $m[1], $body) as $ch) {
            if (trim($ch, " \r\n\t-") === '') continue;
            $r = find_part($ch, $want);
            if ($r !== null && trim($r) !== '') return $r;
        }
        return null;
    }
    if (stripos($ct, $want) !== false) return decode_part_body($body, $hd);
    return null;
}

// 取 HTML 版正文（没有则返回空字符串）
function extract_html($raw) {
    $h = find_part($raw, 'text/html');
    return $h === null ? '' : $h;
}

// 列表用发件人短名：优先显示名，没有则显示地址
function short_from($raw) {
    $v = trim(mime_dec($raw));
    if (preg_match('/"([^"]+)"\s*<[^>]+>/', $v, $m)) return $m[1];
    if (preg_match('/^([^<]+)<[^>]+>\s*$/', $v, $m)) {
        $t = trim($m[1], ' "');
        if ($t !== '') return $t;
    }
    if (preg_match('/<([^>]+)>/', $v, $m)) return $m[1];
    return $v !== '' ? $v : '(未知)';
}

// 完整发件人（正文页用）
function nice_from($raw) {
    $v = mime_dec($raw);
    if (preg_match('/"([^"]+)"\s*<([^>]+)>/', $v, $m)) return $m[1] . ' <' . $m[2] . '>';
    if (preg_match('/^(.*)<([^>]+)>\s*$/', $v, $m)) return trim($m[1], ' "') . ' <' . $m[2] . '>';
    return $v;
}

// 列表时间：7 天内显示 星期X HH:MM，否则显示日期
function fmt_time($ts) {
    if (!$ts) return '';
    $week = ['日', '一', '二', '三', '四', '五', '六'];
    if (time() - $ts < 7 * 86400) {
        return '星期' . $week[(int)date('w', $ts)] . ' ' . date('H:i', $ts);
    }
    if (date('Y', $ts) === date('Y')) return date('n月j日', $ts);
    return date('Y年n月j日', $ts);
}

// 从 FETCH 响应块里抠出 BODYSTRUCTURE（括号配平，跳过引号内括号）
function extract_bodystructure($blob) {
    $p = stripos($blob, 'BODYSTRUCTURE');
    if ($p === false) return '';
    $p = strpos($blob, '(', $p);
    if ($p === false) return '';
    $depth = 0; $inQ = false; $esc = false; $out = '';
    $len = strlen($blob);
    for ($i = $p; $i < $len; $i++) {
        $c = $blob[$i];
        $out .= $c;
        if ($inQ) {
            if ($esc) $esc = false;
            elseif ($c === '\\') $esc = true;
            elseif ($c === '"') $inQ = false;
        } else {
            if ($c === '"') $inQ = true;
            elseif ($c === '(') $depth++;
            elseif ($c === ')') { $depth--; if ($depth === 0) break; }
        }
    }
    return $out;
}

/* ---------------- 业务 ---------------- */
function smb_list($fp, $n = 20) {
    $r = smb_cmd($fp, 'b001', 'SELECT INBOX');
    if (!$r[0]) return [null, '打不开收件箱'];
    $exists = 0;
    foreach ($r[1] as $l) if (preg_match('/^\* (\d+) EXISTS/', $l, $m)) $exists = (int)$m[1];
    if ($exists === 0) return [[], null];
    $a = max(1, $exists - $n + 1);
    $r = smb_cmd($fp, 'b002', "FETCH $a:$exists (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)] BODYSTRUCTURE)");
    if (!$r[0]) return [null, '读取邮件列表失败'];
    $chunks = []; $cur = null;
    foreach ($r[1] as $l) {
        if (preg_match('/^\* (\d+) FETCH/', $l)) {
            if ($cur) $chunks[] = $cur;
            $cur = [$l];
        } elseif ($cur !== null) {
            $cur[] = $l;
        }
    }
    if ($cur) $chunks[] = $cur;
    $out = [];
    foreach ($chunks as $lines) {
        $first = $lines[0];
        $pre = $first;
        if (($p = strpos($first, '{')) !== false) $pre = substr($first, 0, $p);
        $uid = 0;
        if (preg_match('/UID (\d+)/i', $pre, $u)) $uid = (int)$u[1];
        $unread = true;
        if (preg_match('/FLAGS \(([^)]*)\)/i', $pre, $f)) $unread = stripos($f[1], '\\seen') === false;
        $hdr = '';
        if (preg_match('/\{\d+\}(.*)$/s', $first, $m2)) $hdr = $m2[1];
        $hd = parse_headers_block($hdr);
        $struct = extract_bodystructure(implode("\n", $lines));
        $hasAttach = $struct !== '' && preg_match('/"(attachment|inline)"/i', $struct) && stripos($struct, 'filename') !== false;
        $ts = strtotime($hd['date'] ?? '') ?: 0;
        $out[] = [
            'uid'     => $uid,
            'from'    => short_from($hd['from'] ?? ''),
            'subject' => ($s = trim(mime_dec($hd['subject'] ?? ''))) !== '' ? $s : '(无主题)',
            'date'    => $hd['date'] ?? '',
            'ts'      => $ts,
            'unread'  => $unread,
            'attach'  => $hasAttach,
        ];
    }
    return [array_reverse($out), null];
}

function smb_view($fp, $uid) {
    $s = smb_cmd($fp, 's001', 'SELECT INBOX');
    if (!$s[0]) return [null, '打不开收件箱'];
    smb_cmd($fp, 's002', "UID STORE $uid +FLAGS.SILENT \\Seen");
    $r = smb_cmd($fp, 'c001', "UID FETCH $uid (BODY.PEEK[])");
    if (!$r[0]) return [null, '读取邮件正文失败'];
    $raw = '';
    foreach ($r[1] as $l) {
        if (preg_match('/^\* \d+ FETCH/', $l) && preg_match('/\{\d+\}(.*)$/s', $l, $m2)) { $raw = $m2[1]; break; }
    }
    if ($raw === '') return [null, '邮件内容为空'];
    $parts = preg_split("/\r?\n\r?\n/", ltrim($raw), 2);
    $hd = parse_headers_block($parts[0]);
    return [[
        'from'    => nice_from($hd['from'] ?? ''),
        'to'      => $hd['to'] ?? '',
        'subject' => ($s = trim(mime_dec($hd['subject'] ?? ''))) !== '' ? $s : '(无主题)',
        'date'    => $hd['date'] ?? '',
        'body'    => extract_text($raw),
        'html'    => extract_html($raw),
    ], null];
}

/* ---------------- 主流程 ---------------- */
$err = null; $list = null; $view = null; $showLogin = false;
if ($email && $pass) {
    list($fp, $err) = smb_login($email, $pass);
    if ($fp) {
        if (isset($_GET['uid'])) $view = smb_view($fp, (int)$_GET['uid']);
        else $list = smb_list($fp);
        if ($view) { $err = $view[1]; $view = $view[0]; }
        if ($list) { $err = $list[1]; $list = $list[0]; }
        smb_cmd($fp, 'z999', 'LOGOUT');
        fclose($fp);
        if ($err && stripos($err, '登录失败') !== false) {
            unset($_SESSION['mb_email'], $_SESSION['mb_pass']);
            $showLogin = true;
        }
    } else {
        $showLogin = true;
    }
} else {
    $showLogin = true;
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $view ? '邮件正文' : '收件箱' ?> · <?= h($email ?: '邮箱') ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f2f4f8;color:#333;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:680px;margin:0 auto;min-height:100vh;background:#fff;box-shadow:0 0 12px rgba(0,0,0,.06)}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee}
.topbar h2{margin:0;font-size:18px}
.topbar .who{font-size:12px;color:#888;margin-top:2px}
.topbar .acts{white-space:nowrap}
.topbar .acts a{display:inline-block;padding:9px 16px;font-size:15px;background:#eef2ff;color:#1a73e8;border-radius:8px;text-decoration:none;margin-left:8px}
.topbar .acts a:hover{background:#dbe4ff}
.row{display:block;padding:12px 18px;border-bottom:1px solid #f0f0f0;text-decoration:none;color:inherit}
.row:hover{background:#f8faff}
.r1{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
.sender{font-weight:600;font-size:15px;color:#222;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rt{font-size:12px;color:#999;white-space:nowrap;flex:none}
.r2{margin-top:4px;font-size:14px;color:#777;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row.unread .subj{color:#111;font-weight:500}
.dot{color:#1a73e8;font-size:9px;margin-right:7px;vertical-align:2px}
.att{font-size:12px;margin-left:4px}
.empty{color:#999;text-align:center;padding:60px 0;font-size:14px}
.err{background:#fdecea;color:#b3261e;border-radius:8px;padding:12px;margin:14px 18px 0;font-size:14px}
.meta{background:#f6f8fb;border-radius:8px;padding:12px 14px;font-size:13px;color:#555;margin:14px 18px}
.meta b{color:#222;font-weight:600}
.mbody{margin:0 18px}
.mbody iframe{width:100%;height:65vh;border:1px solid #ddd;border-radius:8px;background:#fff}
.txtbody{font-size:14px;line-height:1.7;white-space:pre-wrap;word-break:break-word;color:#333}
.note{font-size:12px;color:#888;margin:14px 18px 20px;text-align:center}
.note a{color:#1a73e8}
label{display:block;font-size:14px;margin:12px 0 6px;color:#555}
input{width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ddd;border-radius:8px;background:#fff;color:#333}
button{width:100%;margin-top:18px;padding:12px;font-size:16px;background:#1a73e8;color:#fff;border:0;border-radius:8px;cursor:pointer}
button:hover{background:#1663c7}
.login{padding:40px 24px;max-width:420px;margin:0 auto}
.login h2{margin:0 0 4px}
.login .sub{color:#888;font-size:13px;margin-bottom:8px}
.back{display:block;text-align:center;margin-top:14px;font-size:13px;color:#1a73e8}
</style>
</head>
<body>
<div class="wrap">
<?php if ($showLogin): ?>
  <div class="login">
    <h2>站内邮箱登录</h2>
    <div class="sub">直连邮件服务器，无需打开官方 webmail</div>
    <?php if ($err): ?><div class="err" style="margin:14px 0 0">❌ <?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <label>邮箱地址</label>
      <input name="email" required placeholder="例如 zhangsan@example.com" value="<?= h($email ?: '') ?>">
      <label>密码</label>
      <input name="mpw" type="password" required>
      <button type="submit">进入收件箱</button>
    </form>
    <a class="back" href="./">← 去开新邮箱</a>
  </div>
<?php else: ?>
  <?php $uidv = (int)($_GET['uid'] ?? 0); ?>
  <div class="topbar">
    <div><h2><?= $view ? '邮件正文' : '收件箱' ?></h2><div class="who"><?= h($email) ?></div></div>
    <div class="acts"><?php if ($view): ?><a href="inbox.php">← 返回收件箱</a><?php endif; ?><a href="<?= $view ? '?uid=' . $uidv : 'inbox.php' ?>" title="刷新">🔄 刷新</a><a href="?logout=1">退出</a></div>
  </div>
  <?php if ($err): ?><div class="err">❌ <?= h($err) ?></div><?php endif; ?>
  <?php if ($view): $m = $view; ?>
    <div class="meta">
      <div><b>发件人：</b><?= h($m['from']) ?></div>
      <div><b>主题：</b><?= h($m['subject']) ?></div>
      <div><b>时间：</b><?= h($m['date']) ?></div>
    </div>
    <div class="mbody">
    <?php if ($m['html'] !== '' && !isset($_GET['text'])): ?>
      <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?= h($m['html']) ?>"></iframe>
      <div class="note"><a href="?uid=<?= $uidv ?>&text=1">查看纯文本版</a></div>
    <?php else: ?>
      <div class="txtbody"><?= h($m['body'] !== '' ? $m['body'] : '(正文解析为空，可能是纯附件邮件)') ?></div>
    <?php endif; ?>
    </div>
  <?php elseif ($list !== null): ?>
    <?php if (count($list) === 0): ?>
      <div class="empty">📭 收件箱是空的<br><span style="font-size:12px">新开的邮箱还没有收到邮件</span></div>
    <?php else: ?>
      <?php foreach ($list as $m): ?>
      <a class="row<?= $m['unread'] ? ' unread' : '' ?>" href="?uid=<?= (int)$m['uid'] ?>">
        <div class="r1"><span class="sender"><?= h($m['from']) ?></span><span class="rt"><?= h(fmt_time($m['ts'])) ?><?= $m['attach'] ? '<span class="att">📎</span>' : '' ?></span></div>
        <div class="r2"><?php if ($m['unread']): ?><span class="dot">●</span><?php endif; ?><span class="subj"><?= h($m['subject']) ?></span></div>
      </a>
      <?php endforeach; ?>
      <div class="note">最近 <?= count($list) ?> 封</div>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
