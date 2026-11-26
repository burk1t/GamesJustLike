<?php
session_start();
$pdo = require __DIR__ . '/config/database.php';
require __DIR__ . '/config/recommender.php';

$userId = isset($_GET['user']) ? (int)$_GET['user'] : null;

$userInfo = null;
if ($userId) {
    $stmt = $pdo->prepare('SELECT id, login, group_id FROM users WHERE id = :u');
    $stmt->execute([':u' => $userId]);
    $userInfo = $stmt->fetch();
}

$items = computeRecommendations($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Проверка модуля рекомендаций</title>
    <style>
        body { background:#0b0f14; color:#e8edf2; font-family:Arial,sans-serif; padding:20px; }
        h1 { color:#fff; }
        .info { background:#11171f; padding:12px 16px; border-radius:8px; margin-bottom:16px; border:1px solid #252e39; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { padding:6px 8px; border-bottom:1px solid #252e39; text-align:left; }
        th { background:#1a222d; color:#fff; position:sticky; top:0; }
        tr:hover { background:#11171f; }
        .total { color:#7fa5ff; font-weight:bold; }
        .num { text-align:right; font-family:Consolas,monospace; }
    </style>
</head>
<body>
    <h1>Проверка модуля рекомендаций</h1>
    <div class="info">
        <?php if ($userInfo): ?>
            Пользователь: <strong>#<?= (int)$userInfo['id'] ?> <?= htmlspecialchars($userInfo['login']) ?></strong>,
            группа: <?= (int)$userInfo['group_id'] ?>.
        <?php else: ?>
            Гость (без авторизации) — резервный алгоритм по рейтингу.
        <?php endif; ?>
        Всего товаров: <strong><?= count($items) ?></strong>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th><th>ID</th><th>Название</th><th>Кат.</th>
                <th class="num">Personal</th><th class="num">Group</th>
                <th class="num">Rel.</th><th class="num">Base</th>
                <th class="num">Events</th><th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $it['id'] ?></td>
                <td><?= htmlspecialchars($it['name']) ?></td>
                <td><?= $it['category_id'] ?></td>
                <td class="num"><?= $it['personal'] ?></td>
                <td class="num"><?= $it['group'] ?></td>
                <td class="num"><?= $it['relations'] ?></td>
                <td class="num"><?= $it['baseline'] ?></td>
                <td class="num"><?= $it['events'] ?></td>
                <td class="num total"><?= $it['total'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>