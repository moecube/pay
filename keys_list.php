<?php
/**
 * Created by PhpStorm.
 * User: zh99998
 * Date: 2017/2/21
 * Time: 下午5:47
 */

require_once 'Order.php';

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    die();
}

$user_id = $_GET['user_id'];

header('Content-Type: application/json');
echo json_encode(Order::keys($user_id));
