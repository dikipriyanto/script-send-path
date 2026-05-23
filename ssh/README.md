# SSH Web Client (PHP, single-file)

Login ke server remote via browser pakai **RSA / Ed25519 / ECDSA private key**, lalu otomatis diarahkan ke web terminal (xterm.js).

Hanya **satu file PHP** (`ssh.php`) — host/port/username sudah otomatis terisi dari konstanta di atas file. User tinggal paste/upload private key.

## Pasang

1. Edit konstanta default di bagian atas `ssh.php`:
   ```php
   const DEFAULT_HOST     = 'example.com';
   const DEFAULT_PORT     = 22;
   const DEFAULT_USERNAME = 'root';
   ```
2. Install dependency phpseclib v3:
   ```bash
   cd ssh
   composer install --no-dev
   ```
   (Tidak ada composer di server? Jalankan composer install di komputer lokal lalu upload folder `vendor/` apa adanya.)

3. Akses `https://domain.tld/ssh/` (atau `ssh.php`).

## Pakai

1. Form sudah pre-filled host/port/username.
2. Paste isi private key, atau pilih file key (.pem / .key).
3. Isi passphrase jika key terenkripsi.
4. Klik **Connect** → langsung diarahkan ke terminal.
5. Ketik perintah seperti shell biasa. `exit` / Disconnect untuk keluar.

## Batasan

- Mode eksekusi **non-interaktif** (per-command exec). `vim`, `top`, `nano`, `less` tidak didukung — itu butuh PTY persisten via WebSocket.
- `cd` antar perintah dipertahankan (cwd disimpan di session). `export VAR=...` tidak persist.

## Keamanan

- **WAJIB pakai HTTPS.** Tanpa TLS, private key bisa disadap.
- Private key disimpan di `$_SESSION` (server memory) — tidak ditulis ke disk.
- File `.htaccess` mem-block akses ke `vendor/`, `composer.json`, `README.md`.
- Disarankan tambahkan Basic Auth / IP whitelist di depan endpoint ini.
- Setelah selesai, klik **Disconnect** untuk bersihkan session.

## Struktur

```
ssh/
├── ssh.php          ← satu-satunya file aplikasi
├── composer.json    ← dependency phpseclib v3
├── .htaccess        ← proteksi & DirectoryIndex
└── README.md
```
