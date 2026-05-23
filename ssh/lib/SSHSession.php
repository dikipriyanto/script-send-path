<?php
/**
 * SSHSession.php
 * --------------------------------------------------------------
 * Helper untuk membuat koneksi SSH menggunakan phpseclib3,
 * dengan kredensial yang tersimpan di $_SESSION.
 *
 * Karena PHP web request bersifat stateless, koneksi SSH
 * dibuka ulang per-request dari kredensial di session, lalu
 * sebuah command dieksekusi. Working directory + env variable
 * tetap dilacak di session agar perilakunya menyerupai shell.
 * --------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

final class SSHSession
{
    public const SESSION_KEY = 'ssh_creds';

    /**
     * Validasi kredensial SSH dengan mencoba login sekali.
     * Mengembalikan array berisi info server bila sukses,
     * atau melempar Exception bila gagal.
     */
    public static function authenticate(
        string $host,
        int $port,
        string $username,
        string $privateKeyPem,
        string $passphrase = ''
    ): array {
        if ($host === '' || $username === '' || $privateKeyPem === '') {
            throw new InvalidArgumentException('Host, username, dan private key wajib diisi.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port tidak valid.');
        }

        // Load private key (RSA / Ed25519 / ECDSA — phpseclib auto-detect).
        try {
            $key = $passphrase !== ''
                ? PublicKeyLoader::load($privateKeyPem, $passphrase)
                : PublicKeyLoader::load($privateKeyPem);
        } catch (\Throwable $e) {
            throw new RuntimeException('Private key tidak valid atau passphrase salah: ' . $e->getMessage());
        }

        $ssh = new SSH2($host, $port, 10); // timeout 10 detik
        if (!$ssh->login($username, $key)) {
            throw new RuntimeException('Login SSH gagal. Periksa host/user/key.');
        }

        // Ambil informasi awal (hostname + cwd + uname).
        $hostname = trim((string) $ssh->exec('hostname'));
        $cwd      = trim((string) $ssh->exec('pwd')) ?: '/';
        $uname    = trim((string) $ssh->exec('uname -a'));

        $ssh->disconnect();

        return [
            'hostname' => $hostname,
            'cwd'      => $cwd,
            'uname'    => $uname,
        ];
    }

    /**
     * Simpan kredensial ke $_SESSION.
     * Private key di-encode base64 supaya aman saat di-serialize.
     */
    public static function store(
        string $host,
        int $port,
        string $username,
        string $privateKeyPem,
        string $passphrase,
        string $cwd,
        string $hostname
    ): void {
        $_SESSION[self::SESSION_KEY] = [
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'key_b64'     => base64_encode($privateKeyPem),
            'passphrase'  => $passphrase, // opsional
            'cwd'         => $cwd ?: '/',
            'hostname'    => $hostname,
            'created_at'  => time(),
        ];
    }

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]['key_b64']);
    }

    public static function getCreds(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Buka koneksi SSH baru menggunakan kredensial di session.
     */
    public static function connect(): SSH2
    {
        $c = self::getCreds();
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

        $ssh = new SSH2($c['host'], $c['port'], 10);
        if (!$ssh->login($c['username'], $key)) {
            throw new RuntimeException('Login SSH gagal saat reconnect.');
        }
        return $ssh;
    }

    /**
     * Eksekusi command pada server remote, mempertahankan
     * working directory yang tersimpan di session.
     *
     * Mengembalikan: ['output' => string, 'cwd' => string]
     */
    public static function execute(string $command): array
    {
        $c   = self::getCreds();
        $cwd = $c['cwd'] ?? '/';

        // Bungkus command supaya cwd dilacak: jalankan di $cwd lalu print pwd di akhir.
        // Pemisah unik supaya kita bisa pisahkan output user dengan path baru.
        $marker = '___KIRO_PWD_MARKER___';
        $wrapped = sprintf(
            'cd %s 2>/dev/null; %s; __ec=$?; printf "\n%s%s%s"; pwd',
            escapeshellarg($cwd),
            $command,
            $marker,
            ':$__ec:',
            ''
        );

        $ssh = self::connect();
        $ssh->setTimeout(30);
        $raw = (string) $ssh->exec($wrapped);
        $ssh->disconnect();

        // Pisahkan output user dari marker.
        $output  = $raw;
        $newCwd  = $cwd;
        $exit    = null;
        $pos = strrpos($raw, $marker);
        if ($pos !== false) {
            $output = substr($raw, 0, $pos);
            $tail   = substr($raw, $pos + strlen($marker));
            // Format tail: ":<exit>:\n<newcwd>\n"
            if (preg_match('/^:(\d+):\s*\R(.*)$/s', $tail, $m)) {
                $exit   = (int) $m[1];
                $candidate = trim($m[2]);
                if ($candidate !== '') {
                    $newCwd = $candidate;
                }
            }
        }

        // Update cwd di session.
        $_SESSION[self::SESSION_KEY]['cwd'] = $newCwd;

        return [
            'output' => rtrim($output, "\r\n") . "\n",
            'cwd'    => $newCwd,
            'exit'   => $exit,
        ];
    }
}
