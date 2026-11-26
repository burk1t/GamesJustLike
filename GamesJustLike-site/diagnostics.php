<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = require __DIR__ . '/config/database.php';
require __DIR__ . '/config/recommender.php';

$userId = (int)$_SESSION['user_id'];

// Данные пользователя
$stmt = $pdo->prepare('SELECT id, login, email, group_id FROM users WHERE id = :u');
$stmt->execute([':u' => $userId]);
$user = $stmt->fetch();

// Все 12 предпочтений пользователя с названиями
$stmt = $pdo->prepare(
    "SELECT p.id, p.code, p.name, up.value, up.updated_at
     FROM preferences p
     LEFT JOIN user_preferences up ON up.preference_id = p.id AND up.user_id = :u
     ORDER BY p.id"
);
$stmt->execute([':u' => $userId]);
$prefs = $stmt->fetchAll();

// Группа
$groupName = '';
if ($user['group_id']) {
    $g = $pdo->prepare('SELECT name FROM customer_groups WHERE id = :g');
    $g->execute([':g' => (int)$user['group_id']]);
    $groupName = $g->fetchColumn() ?: '';
}

// Последние 20 событий
$stmt = $pdo->prepare(
    "SELECT e.id, e.event_type, e.created_at, e.product_id, p.name AS product_name
     FROM user_events e
     LEFT JOIN products p ON p.id = e.product_id
     WHERE e.user_id = :u
     ORDER BY e.created_at DESC, e.id DESC
     LIMIT 20"
);
$stmt->execute([':u' => $userId]);
$events = $stmt->fetchAll();

// Корзина
$stmt = $pdo->prepare(
    "SELECT c.quantity, c.updated_at, p.id, p.name, p.price
     FROM cart_items c
     INNER JOIN products p ON p.id = c.product_id
     WHERE c.user_id = :u
     ORDER BY c.updated_at DESC"
);
$stmt->execute([':u' => $userId]);
$cart = $stmt->fetchAll();

// Рекомендации (топ-10 для наглядности)
$top = array_slice(computeRecommendations($pdo, $userId), 0, 10);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Диагностика — GameJustLike</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .diag-wrap { max-width: 1100px; margin: 30px auto; padding: 0 20px 60px; }
        .diag-wrap h1 { color: #fff; }
        .diag-card {
            background: #11171f; border: 1px solid #252e39; border-radius: 10px;
            padding: 18px 20px; margin-bottom: 20px;
        }
        .diag-card h2 { color: #fff; margin: 0 0 14px; font-size: 18px; }
        .diag-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .diag-table th, .diag-table td {
            padding: 7px 9px; border-bottom: 1px solid #252e39; text-align: left;
        }
        .diag-table th { background: #1a222d; color: #cdd5de; }
        .diag-table td { color: #aeb8c3; }
        .pref-bar {
            display: inline-block; height: 10px; background: #3f6df6;
            border-radius: 4px; vertical-align: middle; margin-right: 8px;
        }
        .num { text-align: right; font-family: Consolas, monospace; color: #7fa5ff; }
        .event-view { color: #8faeff; }
        .event-cart_add { color: #ffd166; }
        .event-purchase { color: #4caf50; }
        .back-link { display: inline-block; margin-bottom: 18px; color: #8faeff; }
    </style>
</head>
<body>
    <div class="diag-wrap">
        <a href="index.php" class="back-link">← Вернуться в каталог</a>
        <h1>Диагностика рекомендаций</h1>

        <div class="diag-card">
            <h2>Пользователь</h2>
            <p style="color:#cdd5de;">
                ID: <strong><?= (int)$user['id'] ?></strong> ·
                Логин: <strong><?= htmlspecialchars($user['login']) ?></strong> ·
                Группа: <strong><?= (int)$user['group_id'] ?> (<?= htmlspecialchars($groupName) ?>)</strong>
            </p>
        </div>

        <div class="diag-card">
            <h2>Предпочтения (user_preferences)</h2>
            <table class="diag-table">
                <thead>
                    <tr><th>ID</th><th>Код</th><th>Название</th><th>Значение</th><th></th><th>Обновлено</th></tr>
                </thead>
                <tbody>
                <?php foreach ($prefs as $p): ?>
                    <?php $v = $p['value'] !== null ? (float)$p['value'] : null; ?>
                    <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= htmlspecialchars($p['code']) ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td class="num"><?= $v !== null ? number_format($v, 3, '.', '') : '—' ?></td>
                        <td>
                            <?php if ($v !== null): ?>
                                <span class="pref-bar" style="width:<?= (int)round($v * 160) ?>px;"></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['updated_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="diag-card">
            <h2>Последние 20 событий (user_events)</h2>
            <?php if (!$events): ?>
                <p style="color:#7f8b98;">Событий пока нет.</p>
            <?php else: ?>
                <table class="diag-table">
                    <thead>
                        <tr><th>ID</th><th>Тип</th><th>Товар</th><th>Дата</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $e): ?>
                        <tr>
                            <td><?= (int)$e['id'] ?></td>
                            <td class="event-<?= htmlspecialchars($e['event_type']) ?>">
                                <?= htmlspecialchars($e['event_type']) ?>
                            </td>
                            <td>
                                <?= (int)$e['product_id'] ?> —
                                <?= htmlspecialchars($e['product_name'] ?? '') ?>
                            </td>
                            <td><?= htmlspecialchars($e['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="diag-card">
            <h2>Корзина (cart_items)</h2>
            <?php if (!$cart): ?>
                <p style="color:#7f8b98;">Корзина пуста.</p>
            <?php else: ?>
                <table class="diag-table">
                    <thead>
                        <tr><th>ID</th><th>Название</th><th>Количество</th><th>Цена</th><th>Обновлено</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cart as $c): ?>
                        <tr>
                            <td><?= (int)$c['id'] ?></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= (int)$c['quantity'] ?></td>
                            <td class="num"><?= number_format((float)$c['price'], 2, '.', '') ?> USD</td>
                            <td><?= htmlspecialchars($c['updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="diag-card">
            <h2>Топ-10 рекомендаций (по total)</h2>
            <table class="diag-table">
                <thead>
                    <tr><th>#</th><th>ID</th><th>Название</th>
                        <th class="num">Personal</th><th class="num">Group</th>
                        <th class="num">Rel.</th><th class="num">Base</th>
                        <th class="num">Events</th><th class="num">Total</th></tr>
                </thead>
                <tbody>
                <?php foreach ($top as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= (int)$t['id'] ?></td>
                        <td><?= htmlspecialchars($t['name']) ?></td>
                        <td class="num"><?= $t['personal'] ?></td>
                        <td class="num"><?= $t['group'] ?></td>
                        <td class="num"><?= $t['relations'] ?></td>
                        <td class="num"><?= $t['baseline'] ?></td>
                        <td class="num"><?= $t['events'] ?></td>
                        <td class="num"><?= $t['total'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>