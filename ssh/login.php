<?php
/**
 * login.php — Validasi kredensial & buat session SSH.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/SSHSession.php';

function flash_back(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_back('error', 'Metode tidak diizinkan.');
}

// CSRF.
$csrf = $_POST['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    flash_back('error', 'CSRF token tidak valid. Refresh halaman lalu coba lagi.');
}

$host       = trim((string) ($_POST['host'] ?? ''));
$port       = (int) ($_POST['port'] ?? 22);
$username   = trim((string) ($_POST['username'] ?? ''));
$passphrase = (string) ($_POST['passphrase'] ?? '');

// Ambil private key: prioritas dari file upload, fallback ke text.
$keyText = '';
if (!empty($_FILES['key_file']['tmp_name']) && is_uploaded_file($_FILES['key_file']['tmp_name'])) {
    $keyText = (string) file_get_contents($_FILES['key_file']['tmp_name']);
}
if (trim($keyText) === '') {
    $keyText = (string) ($_POST['key_text'] ?? '');
}
$keyText = trim($keyText);

if ($host === '' || $username === '' || $keyText === '') {
    flash_back('error', 'Host, username, dan private key wajib diisi.');
}

try {
    $info = SSHSession::authenticate($host, $port, $username, $keyText, $passphrase);
    SSHSession::store(
        $host, $port, $username, $keyText, $passphrase,
        $info['cwd'], $info['hostname']
    );
} catch (\Throwable $e) {
    flash_back('error', $e->getMessage());
}

// Sukses — redirect ke terminal.
header('Location: terminal.php');
exit;
