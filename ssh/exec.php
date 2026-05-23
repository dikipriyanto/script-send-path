<?php
/**
 * exec.php — Endpoint AJAX untuk eksekusi command SSH.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/SSHSession.php';

header('Content-Type: application/json; charset=UTF-8');

if (!SSHSession::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Belum login.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metode tidak diizinkan.']);
    exit;
}

$cmd = (string) ($_POST['cmd'] ?? '');
if (trim($cmd) === '') {
    echo json_encode(['output' => '', 'cwd' => SSHSession::getCreds()['cwd'] ?? '/']);
    exit;
}

try {
    $res = SSHSession::execute($cmd);
    echo json_encode([
        'output' => $res['output'],
        'cwd'    => $res['cwd'],
        'exit'   => $res['exit'],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
