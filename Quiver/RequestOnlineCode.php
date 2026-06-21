<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');
$nation = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $input['nation'] ?? ''), 0, 3));
if (!$toId || !$email || !$password || !$nation) quiver_error(400, 'Missing toId, email, password, or nation');

$q = safe_r_sql("SELECT ToCode, ToName FROM Tournament WHERE ToId=$toId");
if (!$q || !($t = safe_fetch($q))) quiver_error(404, 'Tournament not found');

$params = http_build_query([
    'TourCode' => $t->ToCode,
    'TourName' => $t->ToName,
    'Email' => $email,
    'Password' => $password,
    'Nation' => $nation,
    'GoogleMap' => trim($input['googleMap'] ?? ''),
]);
$url = 'https://www.ianseo.net/CodeRequest.php?' . $params;
$response = @file_get_contents($url);
if ($response === false) quiver_error(502, 'Could not reach ianseo.net');
$json = json_decode($response, true);
if (!is_array($json)) quiver_error(502, 'Invalid response from ianseo.net');
$result = $json['result'] ?? $json['error'] ?? '';
if ($result && $result !== 'ErrNoError') quiver_error(502, 'ianseo.net rejected the request: ' . $result);
echo json_encode(['success'=>true,'result'=>$result ?: 'ErrNoError','message'=>'Request sent. ianseo.net will email your credentials to ' . $email . '.']);
