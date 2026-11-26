
<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>GamesJustLike — полная диагностика</h1>";

$dbPath = 'C:/xampp/GamesJustLike-data/gamesjustlike.sqlite';

if (!file_exists($dbPath)) {
    die("<h2 style='color:red'>ОШИБКА: файл базы данных не найден: $dbPath</h2>");
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h2 style='color:green'>Подключение к базе: OK</h2>";

    $tables = $pdo->query("
        SELECT name FROM sqlite_master
        WHERE type='table' AND name NOT LIKE 'sqlite_%'
        ORDER BY name
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "<h2>Таблицы (" . count($tables) . ")</h2>";

    foreach ($tables as $table) {
        echo "<h3>" . htmlspecialchars($table) . "</h3>";

        $columns = $pdo->query(
            "PRAGMA table_info(" . $pdo->quote($table) . ")"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo "<p>Столбцы: ";
        echo htmlspecialchars(implode(', ', array_column($columns, 'name')));
        echo "</p>";

        $count = $pdo->query(
            "SELECT COUNT(*) FROM \"$table\""
        )->fetchColumn();

        echo "<p>Записей: <b>$count</b></p>";
    }

    echo "<h2>Проверка целостности базы</h2>";
    $integrity = $pdo->query("PRAGMA integrity_check")->fetchColumn();
    echo "<p>" . htmlspecialchars($integrity) . "</p>";

    echo "<h2>Проверка внешних ключей</h2>";
    $fk = $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC);

    if (!$fk) {
        echo "<p style='color:green'>Ошибок внешних ключей нет</p>";
    } else {
        echo "<pre>" . htmlspecialchars(print_r($fk, true)) . "</pre>";
    }

    echo "<h2 style='color:green'>Диагностика завершена</h2>";

} catch (Throwable $e) {
    echo "<h2 style='color:red'>Ошибка</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
