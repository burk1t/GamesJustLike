
<?php
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Диагностика GamesJustLike</h1>";

echo "<h2>Проверка PHP</h2>";
echo "PHP работает: " . PHP_VERSION . "<br>";

echo "<h2>Проверка SQLite</h2>";

if (extension_loaded('pdo_sqlite')) {
    echo "PDO SQLite: подключен<br>";
} else {
    echo "PDO SQLite: НЕ подключен<br>";
}

if (extension_loaded('sqlite3')) {
    echo "SQLite3: подключен<br>";
} else {
    echo "SQLite3: НЕ подключен<br>";
}

echo "<h2>Проверка PDO</h2>";

if (class_exists('PDO')) {
    echo "PDO доступен<br>";
} else {
    echo "PDO недоступен<br>";
}
?>
