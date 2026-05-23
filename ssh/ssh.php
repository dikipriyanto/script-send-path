<?php
/**
 * ssh.php — Web SSH client (single-file).
 * ----------------------------------------------------------------
 * Flow:
 *   1) GET  ssh.php           -> form login (host/port/user auto-filled,
 *                                user tinggal paste/upload private key).
 *   2) POST ssh.php?a=login   -> validasi SSH, simpan session, redirect.
 *   3) GET  ssh.php?a=term    -> halaman web terminal (xterm.js).
 *   4) POST ssh.php?a=exec    -> AJAX endpoint eksekusi command.
 *   5) GET  ssh.php?a=logout  -> tutup session.
 *
 * Dependency: phpseclib/phpseclib v3 (jalankan `composer install` dulu).
 * ----------------------------------------------------------------
 */
declare(strict_types=1);

session_start();

// =================== KONFIGURASI DEFAULT ===================
// Nilai-nilai ini akan otomatis terisi di form login.
// Ubah sesuai server tujuan Anda.
const DEFAULT_HOST     = 'example.com';
const DEFAULT_PORT     = 22;
const DEFAULT_USERNAME = 'root';
// ===========================================================

require_once __DIR__ . '/vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

const SESSION_KEY = 'ssh_creds';

/* ----------------------- Helper SSH ----------------------- */

function ssh_authenticate(string $host, int $port, string $user, string $pem, string $pass = ''): array
{
    if ($host === '' || $user === '' || $pem === '') {
        throw new InvalidArgumentException('Host, username, dan private key wajib diisi.');
    }
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Port tidak valid.');
    }
    try {
        $key = $pass !== '' ? PublicKeyLoader::load($pem, $pass) : PublicKeyLoader::load($pem);
    } catch (\Throwable $e) {
        throw new RuntimeException('Private key tidak valid / passphrase salah: ' . $e->getMessage());
    }
    $ssh = new SSH2($host, $port, 10);
    if (!$ssh->login($user, $key)) {
        throw new RuntimeException('Login SSH gagal. Periksa host/user/key.');
    }
    $hostname = trim((string) $ssh->exec('hostname'));
    $cwd      = trim((string) $ssh->exec('pwd')) ?: '/';
    $ssh->disconnect();
    return ['hostname' => $hostname, 'cwd' => $cwd];
}

function ssh_exec_command(string $command): array
{
    $c = $_SESSION[SESSION_KEY] ?? null;
    if (!$c) {
        throw new RuntimeException('Belum login.');
    }
    $pem = base64_decode($c['key_b64'], true);
    if ($pem === false) {
        throw new RuntimeException('Private key di session rusak.');
    }
    $key = $c['passphrase'] !== ''
        ? PublicKeyLoader::load($pem, $c['passphrase'])
        : PublicKeyLoader::load($pem);

    $ssh = new SSH2($c['host'], (int) $c['port'], 10);
    if (!$ssh->login($c['username'], $key)) {
        throw new RuntimeException('Reconnect SSH gagal.');
    }
    $ssh->setTimeout(30);

    // Bungkus command supaya cwd tetap dilacak antar request.
    $marker  = '___KIRO_PWD___';
    $cwd     = $c['cwd'] ?? '/';
    $wrapped = sprintf(
        'cd %s 2>/dev/null; %s; __ec=$?; printf "\n%s:$__ec:\n"; pwd',
        escapeshellarg($cwd),
        $command,
        $marker
    );
    $raw = (string) $ssh->exec($wrapped);
    $ssh->disconnect();

    $output = $raw;
    $newCwd = $cwd;
    $exit   = null;
    $pos = strrpos($raw, $marker);
    if ($pos !== false) {
        $output = substr($raw, 0, $pos);
        $tail   = substr($raw, $pos + strlen($marker));
        if (preg_match('/^:(\d+):\s*\R(.*)$/s', $tail, $m)) {
            $exit = (int) $m[1];
            $cand = trim($m[2]);
            if ($cand !== '') $newCwd = $cand;
        }
    }
    $_SESSION[SESSION_KEY]['cwd'] = $newCwd;
    return ['output' => rtrim($output, "\r\n") . "\n", 'cwd' => $newCwd, 'exit' => $exit];
}

/* ----------------------- Router ----------------------- */

$action = $_GET['a'] ?? 'form';
$loggedIn = isset($_SESSION[SESSION_KEY]['key_b64']);

switch ($action) {
    case 'login':   handle_login();   break;
    case 'term':    handle_terminal(); break;
    case 'exec':    handle_exec();    break;
    case 'logout':  handle_logout();  break;
    default:        handle_form();    break;
}

/* ----------------------- Handlers ----------------------- */

function handle_form(): void
{
    global $loggedIn;
    if ($loggedIn) {
        header('Location: ssh.php?a=term');
        exit;
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    $csrf  = $_SESSION['csrf'];
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    render_form($csrf, $flash);
}

function handle_login(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect_form('error', 'Metode tidak diizinkan.'); }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        redirect_form('error', 'CSRF token tidak valid. Refresh halaman.');
    }

    $host = trim((string) ($_POST['host'] ?? ''));
    $port = (int) ($_POST['port'] ?? 22);
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['passphrase'] ?? '');

    $pem = '';
    if (!empty($_FILES['key_file']['tmp_name']) && is_uploaded_file($_FILES['key_file']['tmp_name'])) {
        $pem = (string) file_get_contents($_FILES['key_file']['tmp_name']);
    }
    if (trim($pem) === '') $pem = (string) ($_POST['key_text'] ?? '');
    $pem = trim($pem);

    if ($host === '' || $user === '' || $pem === '') {
        redirect_form('error', 'Host, username, dan private key wajib diisi.');
    }

    try {
        $info = ssh_authenticate($host, $port, $user, $pem, $pass);
    } catch (\Throwable $e) {
        redirect_form('error', $e->getMessage());
    }

    $_SESSION[SESSION_KEY] = [
        'host'       => $host,
        'port'       => $port,
        'username'   => $user,
        'key_b64'    => base64_encode($pem),
        'passphrase' => $pass,
        'hostname'   => $info['hostname'],
        'cwd'        => $info['cwd'],
        'created_at' => time(),
    ];
    header('Location: ssh.php?a=term');
    exit;
}

function handle_terminal(): void
{
    global $loggedIn;
    if (!$loggedIn) { header('Location: ssh.php'); exit; }
    render_terminal($_SESSION[SESSION_KEY]);
}

function handle_exec(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    global $loggedIn;
    if (!$loggedIn) { http_response_code(401); echo json_encode(['error' => 'Belum login.']); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['error' => 'Metode tidak diizinkan.']); exit;
    }
    $cmd = (string) ($_POST['cmd'] ?? '');
    if (trim($cmd) === '') {
        echo json_encode(['output' => '', 'cwd' => $_SESSION[SESSION_KEY]['cwd'] ?? '/']);
        exit;
    }
    try {
        echo json_encode(ssh_exec_command($cmd));
    } catch (\Throwable $e) {
        http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
    }
}

function handle_logout(): void
{
    unset($_SESSION[SESSION_KEY]);
    session_regenerate_id(true);
    header('Location: ssh.php');
    exit;
}

function redirect_form(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ssh.php');
    exit;
}

/* ----------------------- Views ----------------------- */

function render_form(string $csrf, ?array $flash): void
{
    $h    = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $host = $_POST['host']     ?? DEFAULT_HOST;
    $port = $_POST['port']     ?? (string) DEFAULT_PORT;
    $user = $_POST['username'] ?? DEFAULT_USERNAME;
    ?><!doctype html>
<html lang="id"><head>
<meta charset="UTF-8"><title>SSH Web Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root { color-scheme: dark; } * { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
       background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #e2e8f0;
       display: flex; align-items: center; justify-content: center; padding: 20px; }
.card { width: 100%; max-width: 520px; background: #111827; border: 1px solid #334155;
        border-radius: 12px; padding: 28px 32px; box-shadow: 0 20px 50px rgba(0,0,0,.4); }
h1 { margin: 0 0 4px; color: #38bdf8; font-size: 22px; }
.sub { color: #94a3b8; font-size: 13px; margin-bottom: 22px; }
label { display: block; font-size: 13px; margin: 12px 0 6px; color: #cbd5e1; }
input[type=text], input[type=password], input[type=number], textarea {
    width: 100%; background: #0b1220; color: #e2e8f0; border: 1px solid #334155;
    border-radius: 8px; padding: 10px 12px; font-size: 14px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
textarea { resize: vertical; min-height: 160px; }
.row { display: flex; gap: 12px; }
.row > div { flex: 1; } .row > div.port { flex: 0 0 110px; }
button { margin-top: 18px; width: 100%; padding: 12px; background: #0284c7; color: white;
         border: 0; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
button:hover { background: #0369a1; }
.file-hint { font-size: 12px; color: #64748b; margin-top: 4px; }
.alert { margin-bottom: 16px; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
.alert.error { background: #7f1d1d; color: #fee2e2; border: 1px solid #b91c1c; }
.footer { margin-top: 16px; font-size: 12px; color: #64748b; text-align: center; }
</style></head><body>
<form class="card" action="ssh.php?a=login" method="post" enctype="multipart/form-data" autocomplete="off">
    <h1>SSH Web Login</h1>
    <div class="sub">Paste / upload private key Anda untuk masuk ke server.</div>

    <?php if ($flash): ?>
        <div class="alert <?= $h($flash['type']) ?>"><?= $h($flash['msg']) ?></div>
    <?php endif; ?>

    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">

    <div class="row">
        <div>
            <label for="host">Host</label>
            <input id="host" type="text" name="host" required value="<?= $h($host) ?>">
        </div>
        <div class="port">
            <label for="port">Port</label>
            <input id="port" type="number" name="port" min="1" max="65535" value="<?= $h($port) ?>">
        </div>
    </div>

    <label for="username">Username</label>
    <input id="username" type="text" name="username" required value="<?= $h($user) ?>">

    <label for="key_file">Private Key (file)</label>
    <input id="key_file" type="file" name="key_file" accept=".pem,.key,.txt,*">
    <div class="file-hint">Atau paste isi private key di kolom bawah.</div>

    <label for="key_text">Private Key (paste)</label>
    <textarea id="key_text" name="key_text" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"></textarea>

    <label for="passphrase">Passphrase (opsional)</label>
    <input id="passphrase" type="password" name="passphrase" placeholder="Kosongkan jika key tidak terenkripsi">

    <button type="submit">Connect</button>
    <div class="footer">Pastikan diakses lewat HTTPS. Private key disimpan di session selama sesi aktif saja.</div>
</form></body></html><?php
}

function render_terminal(array $c): void
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $promptUser = $h($c['username'] . '@' . ($c['hostname'] ?: $c['host']));
    ?><!doctype html>
<html lang="id"><head>
<meta charset="UTF-8"><title>SSH Terminal — <?= $promptUser ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
<style>
html, body { height: 100%; margin: 0; background: #000; color: #e2e8f0; font-family: system-ui, sans-serif; }
.topbar { display: flex; align-items: center; justify-content: space-between; padding: 8px 14px;
          background: #0f172a; border-bottom: 1px solid #1e293b; font-size: 13px; }
.topbar .info { color: #94a3b8; } .topbar .info b { color: #38bdf8; }
.topbar a { color: #fca5a5; text-decoration: none; padding: 4px 10px;
            border: 1px solid #7f1d1d; border-radius: 6px; font-size: 12px; }
.topbar a:hover { background: #7f1d1d; color: white; }
#term { height: calc(100vh - 38px); padding: 6px; }
</style></head><body>
<div class="topbar">
    <div class="info">SSH: <b><?= $promptUser ?></b> &middot; port <?= (int) $c['port'] ?></div>
    <a href="ssh.php?a=logout">Disconnect</a>
</div>
<div id="term"></div>
<script>
(function () {
    const term = new Terminal({
        cursorBlink: true, fontSize: 14,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        theme: { background: '#000000', foreground: '#e5e7eb', cursor: '#38bdf8' },
        convertEol: true,
    });
    const fit = new FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(document.getElementById('term'));
    fit.fit();
    window.addEventListener('resize', () => fit.fit());

    const user = <?= json_encode($c['username'], JSON_UNESCAPED_SLASHES) ?>;
    const host = <?= json_encode($c['hostname'] ?: $c['host'], JSON_UNESCAPED_SLASHES) ?>;
    let cwd  = <?= json_encode($c['cwd'] ?: '/', JSON_UNESCAPED_SLASHES) ?>;

    function prompt() {
        term.write(`\r\n\x1b[1;32m${user}@${host}\x1b[0m:\x1b[1;34m${cwd}\x1b[0m$ `);
    }
    term.writeln('\x1b[36mConnected to ' + host + '\x1b[0m');
    term.writeln('Ketik perintah lalu Enter. "exit" untuk disconnect, "clear" untuk bersihkan layar.');
    term.writeln('Catatan: mode exec (non-interaktif). vim/top/nano TIDAK didukung.');
    prompt();

    let buffer = '', history = [], histIdx = -1, busy = false;

    async function runCommand(cmd) {
        busy = true;
        try {
            const res = await fetch('ssh.php?a=exec', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'cmd=' + encodeURIComponent(cmd),
            });
            if (res.status === 401) {
                term.writeln('\r\n\x1b[31mSession habis. Kembali ke login...\x1b[0m');
                setTimeout(() => location.href = 'ssh.php', 1200); return;
            }
            const data = await res.json();
            if (data.error) {
                term.writeln('\r\n\x1b[31m' + data.error + '\x1b[0m');
            } else {
                if (data.output) term.write(data.output.replace(/\n/g, '\r\n'));
                if (data.cwd) cwd = data.cwd;
            }
        } catch (e) {
            term.writeln('\r\n\x1b[31mError: ' + e.message + '\x1b[0m');
        } finally { busy = false; prompt(); }
    }

    term.onData(e => {
        if (busy) return;
        for (const ch of e) {
            const code = ch.charCodeAt(0);
            if (ch === '\r') {
                term.write('\r\n');
                const cmd = buffer.trim(); buffer = '';
                if (cmd === '') { prompt(); return; }
                if (cmd === 'clear') { term.clear(); prompt(); return; }
                if (cmd === 'exit' || cmd === 'logout') {
                    term.writeln('Disconnecting...');
                    setTimeout(() => location.href = 'ssh.php?a=logout', 400); return;
                }
                history.push(cmd); histIdx = history.length;
                runCommand(cmd);
            } else if (code === 127) {
                if (buffer.length > 0) { buffer = buffer.slice(0, -1); term.write('\b \b'); }
            } else if (ch === '\x1b[A') {
                if (histIdx > 0) { histIdx--; term.write('\r\x1b[K'); prompt();
                    buffer = history[histIdx]; term.write(buffer); }
            } else if (ch === '\x1b[B') {
                if (histIdx < history.length - 1) { histIdx++; term.write('\r\x1b[K'); prompt();
                    buffer = history[histIdx]; term.write(buffer);
                } else { histIdx = history.length; term.write('\r\x1b[K'); prompt(); buffer = ''; }
            } else if (code >= 32) { buffer += ch; term.write(ch); }
        }
    });
})();
</script></body></html><?php
}
