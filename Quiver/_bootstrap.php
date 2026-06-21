<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

function quiver_bootstrap($json = true) {
    global $CFG, $INFO, $R_HOST, $R_USER, $R_PASS, $W_HOST, $W_USER, $W_PASS, $DB_NAME;
    if ($json) {
        header('Content-Type: application/json');
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '127.0.0.1' && $ip !== '::1') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['TourId'] = $_SESSION['TourId'] ?? -1;
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

function quiver_input() {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function quiver_error($status, $message, $details = null) {
    http_response_code($status);
    $payload = ['error' => $message];
    if ($details !== null) $payload['details'] = $details;
    echo json_encode($payload);
    exit;
}

function quiver_count($sql) {
    $q = safe_r_sql($sql);
    if ($q && $r = safe_fetch($q)) return intval($r->Cnt);
    return 0;
}
