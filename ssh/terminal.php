<?php
/**
 * terminal.php — Web terminal pakai xterm.js.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/SSHSession.php';

if (!SSHSession::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$creds = SSHSession::getCreds();
$promptUser = htmlspecialchars($creds['username'] . '@' . ($creds['hostname'] ?: $creds['host']));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SSH Terminal — <?= $promptUser ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
    <style>
        html, body { height: 100%; margin: 0; background: #000; color: #e2e8f0;
                     font-family: system-ui, sans-serif; }
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 14px; background: #0f172a; border-bottom: 1px solid #1e293b;
            font-size: 13px;
        }
        .topbar .info { color: #94a3b8; }
        .topbar .info b { color: #38bdf8; }
        .topbar a {
            color: #fca5a5; text-decoration: none; padding: 4px 10px;
            border: 1px solid #7f1d1d; border-radius: 6px; font-size: 12px;
        }
        .topbar a:hover { background: #7f1d1d; color: white; }
        #term { height: calc(100vh - 38px); padding: 6px; }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="info">SSH: <b><?= $promptUser ?></b> &middot; port <?= (int) $creds['port'] ?></div>
        <a href="logout.php">Disconnect</a>
    </div>
    <div id="term"></div>

    <script>
    (function () {
        const term = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
            theme: { background: '#000000', foreground: '#e5e7eb', cursor: '#38bdf8' },
            convertEol: true,
        });
        const fit = new FitAddon.FitAddon();
        term.loadAddon(fit);
        term.open(document.getElementById('term'));
        fit.fit();
        window.addEventListener('resize', () => fit.fit());

        const user = <?= json_encode($creds['username'], JSON_UNESCAPED_SLASHES) ?>;
        const host = <?= json_encode($creds['hostname'] ?: $creds['host'], JSON_UNESCAPED_SLASHES) ?>;
        let cwd  = <?= json_encode($creds['cwd'] ?: '/', JSON_UNESCAPED_SLASHES) ?>;

        function prompt() {
            term.write(`\r\n\x1b[1;32m${user}@${host}\x1b[0m:\x1b[1;34m${cwd}\x1b[0m$ `);
        }

        // Welcome banner.
        term.writeln('\x1b[36mConnected to ' + host + '\x1b[0m');
        term.writeln('Tipe perintah dan tekan Enter. Ketik "exit" untuk disconnect.');
        term.writeln('Catatan: ini mode exec (non-interaktif). Program TUI seperti vim/top tidak didukung.');
        prompt();

        let buffer = '';
        let history = [];
        let histIdx = -1;
        let busy = false;

        async function runCommand(cmd) {
            busy = true;
            try {
                const res = await fetch('exec.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'cmd=' + encodeURIComponent(cmd),
                });
                if (res.status === 401) {
                    term.writeln('\r\n\x1b[31mSession habis. Mengarahkan ke login...\x1b[0m');
                    setTimeout(() => location.href = 'index.php', 1200);
                    return;
                }
                const data = await res.json();
                if (data.error) {
                    term.writeln('\r\n\x1b[31m' + data.error + '\x1b[0m');
                } else {
                    if (data.output) {
                        // Tulis output apa adanya (sudah berisi newline).
                        term.write(data.output.replace(/\n/g, '\r\n'));
                    }
                    if (data.cwd) cwd = data.cwd;
                }
            } catch (e) {
                term.writeln('\r\n\x1b[31mError: ' + e.message + '\x1b[0m');
            } finally {
                busy = false;
                prompt();
            }
        }

        term.onData(e => {
            if (busy) return;
            for (const ch of e) {
                const code = ch.charCodeAt(0);
                if (ch === '\r') { // Enter
                    term.write('\r\n');
                    const cmd = buffer.trim();
                    buffer = '';
                    if (cmd === '') { prompt(); return; }
                    if (cmd === 'clear') { term.clear(); prompt(); return; }
                    if (cmd === 'exit' || cmd === 'logout') {
                        term.writeln('Disconnecting...');
                        setTimeout(() => location.href = 'logout.php', 500);
                        return;
                    }
                    history.push(cmd); histIdx = history.length;
                    runCommand(cmd);
                } else if (code === 127) { // Backspace
                    if (buffer.length > 0) {
                        buffer = buffer.slice(0, -1);
                        term.write('\b \b');
                    }
                } else if (ch === '\x1b[A') { // Up
                    if (histIdx > 0) {
                        histIdx--;
                        // hapus baris saat ini
                        term.write('\r\x1b[K');
                        prompt();
                        buffer = history[histIdx];
                        term.write(buffer);
                    }
                } else if (ch === '\x1b[B') { // Down
                    if (histIdx < history.length - 1) {
                        histIdx++;
                        term.write('\r\x1b[K'); prompt();
                        buffer = history[histIdx];
                        term.write(buffer);
                    } else {
                        histIdx = history.length;
                        term.write('\r\x1b[K'); prompt();
                        buffer = '';
                    }
                } else if (code >= 32) { // printable
                    buffer += ch;
                    term.write(ch);
                }
            }
        });
    })();
    </script>
</body>
</html>
