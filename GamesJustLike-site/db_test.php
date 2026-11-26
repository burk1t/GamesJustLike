
<?php
try {
    $pdo = require __DIR__ . '/config/database.php';

    $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();

    echo '<h1>GamesJustLike</h1>';
    echo '<p>SQLite подключена успешно!</p>';
    echo '<p>Версия SQLite: ' . htmlspecialchars($version) . '</p>';
    echo '<p>PDO_SQLITE работает.</p>';

} catch (Throwable $e) {
    echo 'Ошибка: ' . htmlspecialchars($e->getMessage());
}
