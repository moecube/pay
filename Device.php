<?php

require_once 'vendor/autoload.php';

/**
 * Created by PhpStorm.
 * User: zh99998
 * Date: 2017/2/23
 * Time: 下午6:43
 */
class Device
{
    /** @var $db PDO */
    public static $db;

    public static function expire(): void
    {
        self::$db->exec("WITH expired AS (UPDATE orders SET status = 'expired' WHERE status = 'new' AND created_at + INTERVAL '1 day' < NOW() RETURNING id) UPDATE keys set order_id = NULL FROM expired WHERE order_id = expired.id");
    }

    public static function price(string $app_id, string $currency): float
    {
        // PHP是世界上最好的语言 参数模板不能用
        $query = self::$db->prepare("SELECT price_$currency::NUMERIC FROM apps WHERE id = :app_id");
        $query->execute(['app_id' => $app_id]);
        return $query->fetchColumn();
    }

    public static function create($id, $app_id, $user_id, $payment, $currency, $total)
    {
        self::$db->beginTransaction();
        $query = self::$db->prepare("INSERT INTO orders (id, user_id, status, payment, currency, total) VALUES (:id, :user_id, 'new', :payment, :currency, :total) RETURNING *");
        $query->execute(['id' => $id, 'user_id' => $user_id, 'payment' => $payment, 'currency' => $currency, 'total' => $total]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        $query = self::$db->prepare("UPDATE keys k1 SET order_id = :id FROM (SELECT id FROM keys WHERE app_id = :app_id AND user_id IS NULL AND order_id IS NULL LIMIT 1 FOR UPDATE SKIP LOCKED) k2 WHERE k1.id = k2.id");
        $query->execute(['id' => $id, 'app_id' => $app_id]);
        if ($query->rowCount()) {
            self::$db->commit();
            return $order;
        } else {
            self::$db->rollBack();
            return NULL;
        }
    }
}

Order::$db = new PDO(getenv("DATABASE"));
Order::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
