<?php
/**
 * index.php — Form login SSH.
 */
declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/SSHSession.php';

// Sudah login? Langsung ke terminal.
if (SSHSession::isLoggedIn()) {
    header('Location: terminal.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// CSRF token sederhana.
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SSH Web Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #e2e8f0;
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .card {
            width: 100%; max-width: 520px;
            background: #111827; border: 1px solid #334155;
            border-radius: 12px; padding: 28px 32px;
            box-shadow: 0 20px 50px rgba(0,0,0,.4);
        }
        h1 { margin: 0 0 4px; color: #38bdf8; font-size: 22px; }
        .sub { color: #94a3b8; font-size: 13px; margin-bottom: 22px; }
        label { display: block; font-size: 13px; margin: 12px 0 6px; color: #cbd5e1; }
        input[type=text], input[type=password], input[type=number], textarea {
            width: 100%; background: #0b1220; color: #e2e8f0;
            border: 1px solid #334155; border-radius: 8px;
            padding: 10px 12px; font-size: 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        textarea { resize: vertical; min-height: 140px; }
        .row { display: flex; gap: 12px; }
        .row > div { flex: 1; }
        .row > div.port { flex: 0 0 110px; }
        button {
            margin-top: 18px; width: 100%; padding: 12px;
            background: #0284c7; color: white; border: 0; border-radius: 8px;
            font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #0369a1; }
        .file-hint { font-size: 12px; color: #64748b; margin-top: 4px; }
        .alert {
            margin-bottom: 16px; padding: 10px 12px;
            border-radius: 8px; font-size: 13px;
        }
        .alert.error { background: #7f1d1d; color: #fee2e2; border: 1px solid #b91c1c; }
        .alert.info  { background: #1e3a8a; color: #dbeafe; border: 1px solid #2563eb; }
        .footer { margin-top: 16px; font-size: 12px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <form class="card" action="login.php" method="post" enctype="multipart/form-data" autocomplete="off">
        <h1>SSH Web Login</h1>
        <div class="sub">Login ke server remote menggunakan RSA / Ed25519 / ECDSA private key.</div>

        <?php if ($flash): ?>
            <div class="alert <?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="row">
            <div>
                <label for="host">Host / IP</label>
                <input id="host" type="text" name="host" required placeholder="example.com" value="<?= htmlspecialchars($_POST['host'] ?? '') ?>">
            </div>
            <div class="port">
                <label for="port">Port</label>
                <input id="port" type="number" name="port" min="1" max="65535" value="<?= htmlspecialchars($_POST['port'] ?? '22') ?>">
            </div>
        </div>

        <label for="username">Username</label>
        <input id="username" type="text" name="username" required placeholder="root" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

        <label for="key_file">Private Key (file)</label>
        <input id="key_file" type="file" name="key_file" accept=".pem,.key,.txt,*">
        <div class="file-hint">Atau paste isi private key di bawah ini.</div>

        <label for="key_text">Private Key (paste)</label>
        <textarea id="key_text" name="key_text" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"></textarea>

        <label for="passphrase">Passphrase (opsional)</label>
        <input id="passphrase" type="password" name="passphrase" placeholder="Kosongkan jika key tidak terenkripsi">

        <button type="submit">Connect</button>

        <div class="footer">
            Pastikan halaman ini diakses lewat HTTPS. Private key disimpan di server-side session selama sesi aktif saja.
        </div>
    </form>
</body>
</html>
