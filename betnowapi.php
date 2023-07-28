<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// session_start();
header('Access-Control-Allow-Origin: *');

header('Access-Control-Allow-Methods: GET, POST');

header("Access-Control-Allow-Headers: X-Requested-With");
header('Content-Type: application/json');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require __DIR__. '/../autoloader/loader.php';

$gamefuntions = new gamefunctions();
$bet  = new betController();
// getting data from frontend  
$data = file_get_contents('php://input');
$header = apache_request_headers();
$token = trim(str_replace('Bearer', '', $header['Authorization']));
$result = jwtauth::decodeToken($token, key, algorithm);
if ($result) {
    if (jwtauth::isTokenexpired($result)) {
        echo json_encode(array('message' => 'Token expired', 'type' => 'error'));
    } else {
        $uid = $result->uid;

        // echo json_encode($data);

        echo json_encode($gamefuntions->sendbetdata(intval($uid), $data));
    }
} else {
    echo json_encode(array('message' => 'Invalid token', 'type' => 'error'));
}
// echo json_encode($gamefuntions->sendbetdata($uid, $data));
// echo json_encode($bet->sendbetdata(4, $data));
// echo json_encode($gamefuntions->sendbetdata(4, $data));

