# SSH Web Client (PHP)

Login ke server remote via web browser menggunakan **RSA / Ed25519 / ECDSA private key**, lalu langsung diarahkan ke web terminal (xterm.js).

## Cara pasang

1. Upload folder `ssh/` ini ke web server (mis. `https://domain.tld/ssh/`).
2. Jalankan composer untuk install dependency:
   ```bash
   cd ssh
   composer install --no-dev
   ```
   Composer akan men-download `phpseclib/phpseclib` v3 ke folder `vendor/`.

   > Tidak punya composer di server? Install lokal lalu upload folder `vendor/` apa adanya.

3. Pastikan PHP **>= 7.4** dan ekstensi berikut aktif: `openssl`, `mbstring`, `gmp` (opsional, untuk performa).

4. Akses `https://domain.tld/ssh/` — akan tampil form login.

## Cara pakai

1. Isi **Host**, **Port** (default 22), **Username**.
2. Upload file private key, **atau** paste isi key di textarea.
3. Isi **Passphrase** kalau key-nya terenkripsi.
4. Klik **Connect**. Bila autentikasi berhasil, otomatis redirect ke `terminal.php`.
5. Ketik perintah seperti di shell biasa. Tombol panah Atas/Bawah untuk history.
6. Ketik `exit` atau klik **Disconnect** untuk menutup sesi.

## Batasan

- Mode eksekusi adalah **non-interaktif** (per-command exec). Program TUI seperti `vim`, `top`, `nano`, `less` **tidak didukung** karena membutuhkan PTY persisten — itu butuh WebSocket server, di luar lingkup PHP single-request.
- Working directory dilacak per-session (perintah `cd` bekerja antar request).
- Variabel environment yang di-export tidak persist antar request.

## Keamanan

- **WAJIB pakai HTTPS.** Private key dikirim dari browser ke server; tanpa TLS, key bisa disadap.
- Private key disimpan di `$_SESSION` (server-side) dalam bentuk base64. Tidak ditulis ke file.
- File `.htaccess` mem-block akses publik ke `vendor/`, `lib/`, dan `composer.*`.
- Direkomendasikan menambahkan proteksi tambahan di depan halaman ini: HTTP Basic Auth, IP whitelist, atau VPN.
- Pertimbangkan set `session.cookie_secure=1`, `session.cookie_httponly=1`, dan `session.cookie_samesite=Strict` di `php.ini`.
- Setelah selesai dipakai, klik **Disconnect** agar key dihapus dari session.

## Struktur file

```
ssh/
├── composer.json
├── index.php           # form login
├── login.php           # validasi kredensial
├── terminal.php        # halaman web terminal
├── exec.php            # endpoint AJAX exec command
├── logout.php          # bersihkan session
├── lib/SSHSession.php  # helper class SSH
├── .htaccess           # proteksi direktori
└── README.md
```
