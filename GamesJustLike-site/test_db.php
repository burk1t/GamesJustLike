
<?php
require_once __DIR__ . '/db.php';

echo 'Подключение к SQLite успешно!';

$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
$tables = $stmt->fetchAll();

echo '<h3>Таблицы базы данных:</h3>';

foreach ($tables as $table) {
    echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8') . '<br>';
}
