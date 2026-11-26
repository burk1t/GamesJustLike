<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    respond(['success' => false, 'message' => 'Не авторизован'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['success' => false, 'message' => 'Некорректный JSON'], 400);
}

$productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
$eventType = (string)($input['event_type'] ?? 'view');
if ($eventType === 'cart') $eventType = 'cart_add';
$allowed   = ['view', 'cart_add', 'purchase'];

if (!$productId || $productId < 1 || !in_array($eventType, $allowed, true)) {
    respond(['success' => false, 'message' => 'Некорректное событие'], 400);
}

$userId = (int)$_SESSION['user_id'];
$pdo = require __DIR__ . '/../config/database.php';

// Проверить, что товар существует и доступен
$stmt = $pdo->prepare('SELECT id, stock FROM products WHERE id = :id');
$stmt->execute([':id' => $productId]);
$product = $stmt->fetch();
if (!$product) {
    respond(['success' => false, 'message' => 'Товар не найден'], 404);
}

// Защита от дублирования view: не чаще 1 раза в 5 минут
if ($eventType === 'view') {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM user_events
         WHERE user_id = :u AND product_id = :p AND event_type = 'view'
           AND created_at >= datetime('now', '-5 minutes')"
    );
    $check->execute([':u' => $userId, ':p' => $productId]);
    if ((int)$check->fetchColumn() > 0) {
        respond(['success' => true, 'skipped' => 'recent_view']);
    }
}

$weights = ['view' => 0.05, 'cart_add' => 0.15, 'purchase' => 0.30];
$w = $weights[$eventType];

$pdo->beginTransaction();
try {
    // 1. Записать событие
    $ins = $pdo->prepare(
        "INSERT INTO user_events (user_id, product_id, event_type, event_key, created_at)
         VALUES (:u, :p, :e, NULL, CURRENT_TIMESTAMP)"
    );
    $ins->execute([':u' => $userId, ':p' => $productId, ':e' => $eventType]);

    // 2. Получить признаки товара
    $prefs = $pdo->prepare('SELECT preference_id, coefficient FROM product_preferences WHERE product_id = :p');
    $prefs->execute([':p' => $productId]);
    $productPrefs = $prefs->fetchAll();

    // 3. Обновить индивидуальные предпочтения
    if ($productPrefs) {
        $upd = $pdo->prepare(
            "UPDATE user_preferences
             SET value = MIN(1.0, MAX(0.0, value + :delta)),
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :u AND preference_id = :pref"
        );
        foreach ($productPrefs as $pp) {
            $delta = (float)$pp['coefficient'] * $w;
            $upd->execute([
                ':delta' => $delta,
                ':u'     => $userId,
                ':pref'  => (int)$pp['preference_id']
            ]);
        }
    }

    // 4. Корзина
    if ($eventType === 'cart_add') {
        $existing = $pdo->prepare('SELECT quantity FROM cart_items WHERE user_id = :u AND product_id = :p');
        $qty = $existing->fetchColumn();
        if ($qty === false) {
            $add = $pdo->prepare(
                'INSERT INTO cart_items (user_id, product_id, quantity, updated_at)
                 VALUES (:u, :p, 1, CURRENT_TIMESTAMP)'
            );
            $add->execute([':u' => $userId, ':p' => $productId]);
        } else {
            $add = $pdo->prepare(
                'UPDATE cart_items SET quantity = quantity + 1, updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :u AND product_id = :p'
            );
            $add->execute([':u' => $userId, ':p' => $productId]);
        }
    }

    // 5. Покупка — убираем из корзины
    if ($eventType === 'purchase') {
        $del = $pdo->prepare('DELETE FROM cart_items WHERE user_id = :u AND product_id = :p');
        $del->execute([':u' => $userId, ':p' => $productId]);
    }

    $pdo->commit();

    respond([
        'success'    => true,
        'event_type' => $eventType,
        'product_id' => $productId,
        'weight'     => $w,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    respond(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()], 500);
}