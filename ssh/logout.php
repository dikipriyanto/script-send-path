<?php
/**
 * logout.php — Hapus kredensial SSH dari session.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/SSHSession.php';

SSHSession::clear();
session_regenerate_id(true);

header('Location: index.php');
exit;
