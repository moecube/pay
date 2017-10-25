<?php
/**
 * Created by PhpStorm.
 * User: zh99998
 * Date: 2017/7/30
 * Time: 下午5:05
 */

require_once 'vendor/autoload.php';
require_once 'Order.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['sso'], $data['key'])) {
    http_response_code(400);
    die();
}

$user = [];
parse_str(base64_decode($data['sso']), $user);

if (Order::activate($data['key'], $user['email'])) {
    http_response_code(204);
} else {
    http_response_code(403);
}
