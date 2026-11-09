<?php
/* ============================================================
 * 站内极简邮箱（v2）
 * 纯 PHP 直连 IMAP（mailX.serv00.com:993）。
 * 账号密码来自开邮箱成功后写入的 session，或本页登录表单。
 * ============================================================ */
session_start();
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai'); // 显示时间统一用北京时间
mb_internal_encoding('UTF-8');

define('IMAP_HOST', 'mailX.serv00.com');
define('IMAP_PORT', 993);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (isset($_GET['logout'])) {
    // 只退当前邮箱，管理员登录状态保留：开邮箱页的历史「去邮箱」不用重新登录
    unset($_SESSION['mb_email'], $_SESSION['mb_pass']);
    header('Location: ./');
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

// 文件名解码：=?UTF-8?B?...?= / RFC2231 (utf-8''%E4%B8%AD) / 普通
function decode_filename($s) {
    $s = trim($s, " \t\"'");
    if ($s === '') return '';
    if (stripos($s, '=?') !== false) return trim(mime_dec($s));
    if (preg_match("/^[^']*'[^']*'(.*)$/s", $s, $m)) return urldecode($m[1]);
    return $s;
}

// RFC2231 分段参数：filename*0*=...; filename*1*=... → 按序号拼起来解码
function rfc2231_join($hv, $param) {
    if (!preg_match_all('/' . preg_quote($param, '/') . '\*(\d+)\*?\s*=\s*"?([^";\r\n]+)"?/i', $hv, $mm, PREG_SET_ORDER)) return '';
    usort($mm, function ($a, $b) { return (int)$a[1] - (int)$b[1]; });
    $vals = array_column($mm, 2);
    $vals[0] = preg_replace("/^[^']*'[^']*'/", '', $vals[0]); // 去掉首段的 charset'lang' 前缀
    $s = trim(implode('', $vals), " \t\"'");
    return $s === '' ? '' : urldecode($s);
}

// 常见 MIME 类型 → 扩展名（文件名实在拿不到时的兜底）
function ext_for_type($type) {
    static $map = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'text/plain' => 'txt', 'text/csv' => 'csv',
        'application/zip' => 'zip', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'];
    return $map[strtolower(trim($type))] ?? 'bin';
}
function fallback_attach_name($n, $type) { return 'attachment-' . $n . '.' . ext_for_type($type); }

// 从 part 头信息取文件名：优先 Content-Disposition 的 filename（含分段），其次 Content-Type 的 name
function part_filename($hd) {
    $disp = $hd['content-disposition'] ?? '';
    $f = rfc2231_join($disp, 'filename');
    if ($f !== '') return $f;
    if (preg_match('/filename\*?\s*=\s*"?([^";\r\n]+)"?/i', $disp, $m)) {
        $f = decode_filename($m[1]);
        if ($f !== '') return $f;
    }
    $ct = $hd['content-type'] ?? '';
    $f = rfc2231_join($ct, 'name');
    if ($f !== '') return $f;
    if (preg_match('/\bname\*?\s*=\s*"?([^";\r\n]+)"?/i', $ct, $m)) {
        $f = decode_filename($m[1]);
        if ($f !== '') return $f;
    }
    return '';
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
    $ct_raw = $hd['content-type'] ?? 'text/plain';
    $ct = strtolower($ct_raw);
    // boundary 区分大小写：必须从原始（未转小写）的 Content-Type 里提取
    if (strpos($ct, 'multipart/') === 0 && preg_match('/boundary="?([^";\s]+)"?/', $ct_raw, $m)) {
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
    $ct_raw = $hd['content-type'] ?? 'text/plain';
    $ct = strtolower($ct_raw);
    // boundary 区分大小写：必须从原始（未转小写）的 Content-Type 里提取
    if (strpos($ct, 'multipart/') === 0 && preg_match('/boundary="?([^";\s]+)"?/', $ct_raw, $m)) {
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

// 递归收集附件 part，返回 [['n'=>'2','name'=>'a.pdf','type'=>'application/pdf','size'=>1234],...]
// $prefix 为 IMAP part 编号（1, 2, 1.1…），顶层单 part 邮件记为 1
function find_attachments($raw, $prefix = '', $withData = false) {
    $out = [];
    $parts = preg_split("/\r?\n\r?\n/", ltrim($raw), 2);
    $htext = $parts[0]; $body = $parts[1] ?? '';
    $hd = parse_headers_block($htext);
    $ct_raw = $hd['content-type'] ?? 'text/plain';
    $ct = strtolower($ct_raw);
    if (strpos($ct, 'multipart/') === 0 && preg_match('/boundary="?([^";\s]+)"?/', $ct_raw, $m)) {
        $i = 0;
        foreach (explode('--' . $m[1], $body) as $ch) {
            if (trim($ch, " \r\n\t-") === '') continue;
            $i++;
            $n = $prefix === '' ? (string)$i : $prefix . '.' . $i;
            foreach (find_attachments($ch, $n, $withData) as $a) $out[] = $a;
        }
        return $out;
    }
    $n = $prefix === '' ? '1' : $prefix;
    $disp = strtolower($hd['content-disposition'] ?? '');
    $fname = part_filename($hd);
    $isText = stripos($ct, 'text/plain') === 0 || stripos($ct, 'text/html') === 0;
    $isAttach = strpos($disp, 'attachment') !== false
        || (strpos($disp, 'inline') !== false && $fname !== '')
        || ($fname !== '' && !$isText);
    if (!$isAttach) return $out;
    $type = strtolower(trim(explode(';', $ct_raw)[0]));
    if ($type === '') $type = 'application/octet-stream';
    $data = $body;
    $cte = strtolower($hd['content-transfer-encoding'] ?? '');
    if (strpos($cte, 'base64') !== false) { $d = base64_decode($data); if ($d !== false) $data = $d; }
    elseif (strpos($cte, 'quoted-printable') !== false) { $data = quoted_printable_decode($data); }
    if ($fname === '') $fname = fallback_attach_name($n, $type);
    $entry = ['n' => $n, 'name' => $fname, 'type' => $type, 'size' => strlen($data)];
    if ($withData) $entry['data'] = $data;
    $out[] = $entry;
    return $out;
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

// 文件大小 human-readable
function fmt_size($n) {
    if ($n < 1024) return $n . ' B';
    if ($n < 1048576) return round($n / 1024, 1) . ' KB';
    return round($n / 1048576, 1) . ' MB';
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
// 列出所有邮箱文件夹（LIST），返回服务端原始名；失败时退回 INBOX
function smb_folders($fp) {
    $r = smb_cmd($fp, 'f001', 'LIST "" "*"');
    if (!$r[0]) return ['INBOX'];
    $out = [];
    foreach ($r[1] as $l) {
        if (preg_match('/^\* LIST \([^)]*\) "[^"]*" (.+)$/', $l, $m)) {
            $name = trim($m[1]);
            if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
                $name = stripcslashes(substr($name, 1, -1));
            }
            if ($name !== '' && !in_array($name, $out, true)) $out[] = $name;
        }
    }
    if (!in_array('INBOX', $out, true)) array_unshift($out, 'INBOX');
    return $out;
}

// 文件夹显示名：常见英文名转中文，其他尝试解 modified UTF-7
function folder_label($folder) {
    if ($folder === 'INBOX') return '收件箱';
    static $map = ['junk' => '垃圾邮件', 'spam' => '垃圾邮件', 'sent' => '已发送',
        'sent items' => '已发送', 'drafts' => '草稿', 'trash' => '已删除'];
    $k = strtolower($folder);
    if (isset($map[$k])) return $map[$k];
    if (strpos($folder, '&') !== false) {
        $d = @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
        if ($d !== false && $d !== '') return $d;
    }
    return $folder;
}

// 合并显示：不参与合并的系统文件夹（已发送/草稿/已删除，属用户自己动作，非收到的邮件）
function folder_skip_merge($folder) {
    static $skip = ['trash', 'deleted items', 'deleted messages', 'sent', 'sent items',
        'sent messages', 'drafts'];
    return in_array(strtolower($folder), $skip, true);
}

function smb_list($fp, $n = 20) {
    $all = [];
    foreach (smb_folders($fp) as $folder) {
        if (folder_skip_merge($folder)) continue;
        $r = smb_cmd($fp, 'b001', 'SELECT "' . smb_esc($folder) . '"');
        if (!$r[0]) continue;
        $exists = 0;
        foreach ($r[1] as $l) if (preg_match('/^\* (\d+) EXISTS/', $l, $m)) $exists = (int)$m[1];
        if ($exists === 0) continue;
        $a = max(1, $exists - $n + 1);
        $r = smb_cmd($fp, 'b002', "FETCH $a:$exists (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)] BODYSTRUCTURE)");
        if (!$r[0]) continue;
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
            $all[] = [
                'uid'     => $uid,
                'folder'  => $folder,
                'from'    => short_from($hd['from'] ?? ''),
                'subject' => ($s = trim(mime_dec($hd['subject'] ?? ''))) !== '' ? $s : '(无主题)',
                'date'    => $hd['date'] ?? '',
                'ts'      => $ts,
                'unread'  => $unread,
                'attach'  => $hasAttach,
            ];
        }
    }
    // 各文件夹合并后按时间倒序，只取最近 n 封
    usort($all, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return [array_slice($all, 0, $n), null];
}

function smb_view($fp, $uid, $folder = 'INBOX') {
    $s = smb_cmd($fp, 's001', 'SELECT "' . smb_esc($folder) . '"');
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
        'attachments' => find_attachments($raw),
    ], null];
}

/* 下载附件：?uid=X&dl=N → 取 BODY.PEEK[N]，解码后直接输出下载（成功时函数内 exit） */
function smb_download($fp, $uid, $part, $folder = 'INBOX') {
    $r = smb_cmd($fp, 's001', 'SELECT "' . smb_esc($folder) . '"');
    if (!$r[0]) return '打不开收件箱';
    // 注意：该 IMAP 服务端对 BODY[n] 只返回内容不带 part 头信息，所以直接取整封邮件再解析
    $r = smb_cmd($fp, 'd001', "UID FETCH $uid (BODY.PEEK[])");
    if (!$r[0]) return '读取附件失败';
    $raw = '';
    foreach ($r[1] as $l) {
        if (preg_match('/^\* \d+ FETCH/', $l) && preg_match('/\{\d+\}(.*)$/s', $l, $m2)) { $raw = $m2[1]; break; }
    }
    if ($raw === '') return '附件内容为空';
    $found = null;
    foreach (find_attachments($raw, '', true) as $a) {
        if ($a['n'] === $part) { $found = $a; break; }
    }
    if (!$found) return '附件不存在';
    $body = $found['data'];
    $fname = $found['name'];
    $type = $found['type'] !== '' ? $found['type'] : 'application/octet-stream';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fname) . '"; filename*=utf-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private');
    echo $body;
    exit;
}

/* ---------------- 主流程 ---------------- */
$err = null; $list = null; $view = null; $showLogin = false;
if ($email && $pass) {
    list($fp, $err) = smb_login($email, $pass);
    if ($fp) {
        $folder = $_GET['f'] ?? 'INBOX';
        if (isset($_GET['uid'], $_GET['dl']) && preg_match('/^\d+(\.\d+)*$/', $_GET['dl'])) {
            $derr = smb_download($fp, (int)$_GET['uid'], $_GET['dl'], $folder); // 成功时函数内已 exit
            smb_cmd($fp, 'z999', 'LOGOUT'); fclose($fp);
            http_response_code(404);
            echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>下载失败</title></head><body style="font-family:sans-serif;padding:40px">❌ ' . h($derr) . '<br><br><a href="inbox.php">← 返回收件箱</a></body></html>';
            exit;
        }
        // 左右分栏：看信时也要取列表（先取正文再取列表，列表里的已读状态才是最新的）
        if (isset($_GET['uid'])) $view = smb_view($fp, (int)$_GET['uid'], $folder);
        $list = smb_list($fp);
        if ($view) { $err = $view[1]; $view = $view[0]; }
        if ($list) { if ($err === null) $err = $list[1]; $list = $list[0]; }
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
<title><?= $view ? h($view['subject']) : '收件箱' ?> · <?= h($email ?: '邮箱') ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef3f8 url('bg-lowpoly-light.webp') no-repeat center top fixed;background-size:cover;color:#333;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:1080px;margin:0 auto;min-height:100vh;background:rgba(255,255,255,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);box-shadow:0 0 12px rgba(0,0,0,.06)}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee;background:linear-gradient(135deg,rgba(224,242,254,.55),rgba(237,233,254,.55))}
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
.ftag{font-size:11px;color:#7c5cf0;background:#f1efff;border-radius:4px;padding:1px 6px;margin-left:6px;white-space:nowrap;flex:none}
.empty{color:#999;text-align:center;padding:60px 0;font-size:14px}
.err{background:#fdecea;color:#b3261e;border-radius:8px;padding:12px;margin:14px 18px 0;font-size:14px}
.meta{background:#f6f8fb;border-radius:8px;padding:12px 14px;font-size:13px;color:#555;margin:14px 18px}
.meta b{color:#222;font-weight:600}
.mbody{margin:0 18px}
.mbody iframe{width:100%;height:65vh;border:1px solid #ddd;border-radius:8px;background:#fff}
.txtbody{font-size:14px;line-height:1.7;white-space:pre-wrap;word-break:break-word;color:#333}
.attachs{margin:0 18px 14px;padding:10px 14px;background:#f6f9ff;border:1px solid #e3ecff;border-radius:10px}
.atth{font-weight:600;font-size:14px;margin-bottom:6px}
.attrow{display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid #e9f0ff;font-size:14px}
.attname{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.attsize{color:#999;font-size:12px;white-space:nowrap;flex:none}
.attdl{flex:none;padding:6px 14px;background:#1a73e8;color:#fff;border-radius:7px;text-decoration:none;font-size:13px}
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
.layout{display:flex;align-items:flex-start}
.pane-list{flex:0 0 360px;max-width:360px;border-right:1px solid #eee;position:sticky;top:0;max-height:100vh;overflow-y:auto}
.pane-view{flex:1;min-width:0;padding-bottom:24px}
.row.sel{background:#e8f0fe}
.empty-view{color:#999;text-align:center;padding:90px 20px;font-size:14px;line-height:1.8}
@media(max-width:720px){
.pane-list{flex:1 1 auto;max-width:none;border-right:0;position:static;max-height:none;overflow:visible}
.layout.has-view .pane-list{display:none}
.layout:not(.has-view) .pane-view{display:none}
}
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
  <?php $uidv = (int)($_GET['uid'] ?? 0); $folderv = $_GET['f'] ?? 'INBOX'; ?>
  <div class="topbar">
    <div><h2>收件箱</h2><div class="who"><?= h($email) ?></div></div>
    <div class="acts"><?php if ($view): ?><a href="inbox.php">← 返回收件箱</a><?php endif; ?><a href="<?= $view ? '?uid=' . $uidv . '&f=' . urlencode($folderv) : 'inbox.php' ?>" title="刷新">🔄 刷新</a><a href="?logout=1">退出</a></div>
  </div>
  <?php if ($err): ?><div class="err">❌ <?= h($err) ?></div><?php endif; ?>
  <div class="layout<?= $view ? ' has-view' : '' ?>">
    <div class="pane-list">
    <?php if ($list !== null): ?>
      <?php if (count($list) === 0): ?>
      <div class="empty">📭 收件箱是空的<br><span style="font-size:12px">新开的邮箱还没有收到邮件</span></div>
      <?php else: ?>
      <?php foreach ($list as $m): ?>
      <?php $sel = $view && (int)$m['uid'] === $uidv && $m['folder'] === $folderv; ?>
      <a class="row<?= $m['unread'] ? ' unread' : '' ?><?= $sel ? ' sel' : '' ?>"<?= $sel ? ' id="sel"' : '' ?> href="?uid=<?= (int)$m['uid'] ?>&f=<?= urlencode($m['folder']) ?>">
        <div class="r1"><span class="sender"><?= h($m['from']) ?><?php if ($m['folder'] !== 'INBOX'): ?><span class="ftag"><?= h(folder_label($m['folder'])) ?></span><?php endif; ?></span><span class="rt"><?= h(fmt_time($m['ts'])) ?><?= $m['attach'] ? '<span class="att">📎</span>' : '' ?></span></div>
        <div class="r2"><?php if ($m['unread']): ?><span class="dot">●</span><?php endif; ?><span class="subj"><?= h($m['subject']) ?></span></div>
      </a>
      <?php endforeach; ?>
      <div class="note">最近 <?= count($list) ?> 封</div>
      <?php endif; ?>
    <?php endif; ?>
    </div>
    <div class="pane-view">
    <?php if ($view): $m = $view; ?>
    <div class="meta">
      <div><b>发件人：</b><?= h($m['from']) ?></div>
      <div><b>主题：</b><?= h($m['subject']) ?></div>
      <div><b>时间：</b><?= h($m['date']) ?></div>
    </div>
    <?php if (!empty($m['attachments'])): ?>
    <div class="attachs">
      <div class="atth">📎 附件（<?= count($m['attachments']) ?>）</div>
      <?php foreach ($m['attachments'] as $a): ?>
      <div class="attrow">
        <span class="attname" title="<?= h($a['name']) ?>"><?= h($a['name']) ?></span>
        <span class="attsize"><?= h(fmt_size($a['size'])) ?></span>
        <a class="attdl" href="?uid=<?= $uidv ?>&f=<?= urlencode($folderv) ?>&dl=<?= h($a['n']) ?>">下载</a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="mbody">
    <?php if ($m['html'] !== '' && !isset($_GET['text'])): ?>
      <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="<?= h($m['html']) ?>"></iframe>
      <div class="note"><a href="?uid=<?= $uidv ?>&f=<?= urlencode($folderv) ?>&text=1">查看纯文本版</a></div>
    <?php else: ?>
      <div class="txtbody"><?= h($m['body'] !== '' ? $m['body'] : '(正文解析为空，可能是纯附件邮件)') ?></div>
    <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="empty-view">👈 点击左侧的邮件<br><span style="font-size:12px">内容会在这里显示</span></div>
    <?php endif; ?>
    </div>
  </div>
  <script>var s=document.getElementById('sel');if(s){s.scrollIntoView({block:'nearest'});}</script>
<?php endif; ?>
</div>
</body>
</html>
