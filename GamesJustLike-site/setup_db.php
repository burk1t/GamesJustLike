
<?php

try {
    $pdo = require __DIR__ . '/config/database.php';

    $schemaPath = __DIR__ . '/database/schema.sql';

    if (!file_exists($schemaPath)) {
        throw new RuntimeException('Файл schema.sql не найден.');
    }

    $sql = file_get_contents($schemaPath);

    if ($sql === false) {
        throw new RuntimeException('Не удалось прочитать SQL-файл.');
    }

    $pdo->exec($sql);

    $tables = $pdo->query("
        SELECT name
        FROM sqlite_master
        WHERE type = 'table'
          AND name NOT LIKE 'sqlite_%'
        ORDER BY name
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo '<h1>GamesJustLike — создание базы</h1>';
    echo '<p>Структура базы данных успешно создана!</p>';
    echo '<p>Количество таблиц: ' . count($tables) . '</p>';

    echo '<h3>Созданные таблицы:</h3><ol>';

    foreach ($tables as $table) {
        echo '<li>' . htmlspecialchars($table) . '</li>';
    }

    echo '</ol>';

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ошибка: ' . htmlspecialchars($e->getMessage());
}
