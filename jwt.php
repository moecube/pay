<?php
/**
 * Created by PhpStorm.
 * User: zh99998
 * Date: 2017/7/30
 * Time: 下午5:05
 */

require_once 'vendor/autoload.php';
require_once 'Order.php';

use http\QueryString;
use http\Url;
use Jose\Factory\JWKFactory;
use Jose\Factory\JWSFactory;

if (!isset($_GET['sso'], $_GET['app_id'], $_GET['callback'], $_GET['device_id'])) {
    http_response_code(400);
    die();
}

$user = [];
parse_str(base64_decode($_GET['sso']), $user);

$key = Order::keys_has($user['email'], $_GET['app_id']);

if ($key) {

    if (Order::bind($key, $_GET['device_id'])) {
        $key = JWKFactory::createFromKeyFile('jwt/private.key');

        $jws = JWSFactory::createJWSToCompactJSON(
            [
                'iss' => 'mycard-apps',
                'aud' => $_GET['app_id'],
                'sub' => $user['external_id'],
                'exp' => time() + 60 * 60 * 24 * 30,
                'iat' => time(),
                'nbf' => time() - 3600,
            ],
            $key,                         // The key used to sign
            [                             // Protected headers. Muse contains at least the algorithm
                'crit' => ['exp', 'aud'],
                'alg' => 'RS256',
            ]
        );

        $url = new Url($_GET['callback']);
        $url->query = new QueryString(["sso" => $_GET['sso'], "jwt" => $jws]);
        header('Location: ' . $url->toString());
    } else {
        $url = new Url('https://mycard.moe/key/device');
        $url->query = new QueryString([
            'callback' => $_GET['callback'],
            'device_id' => $_GET['device_id'],
            'app_id' => $_GET['app_id'],
            'sso' => $_GET['sso'],
            'key' => $key
        ]);
        header('Location: ' . $url->toString());
    }

} else {
    $url = new Url('https://mycard.moe/key/activate');
    $url->query = new QueryString([
        'callback' => $_GET['callback'],
        'device_id' => $_GET['device_id'],
        'app_id' => $_GET['app_id'],
        'sso' => $_GET['sso']
    ]);
    header('Location: ' . $url->toString());
}
