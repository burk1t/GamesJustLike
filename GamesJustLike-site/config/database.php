
<?php

$dbPath = 'C:/xampp/GamesJustLike-data/gamesjustlike.sqlite';

if (!extension_loaded('pdo_sqlite')) {
    exit('Ошибка: расширение PDO_SQLITE не подключено.');
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;

} catch (PDOException $e) {
    http_response_code(500);
    exit('Ошибка подключения к базе данных: ' . $e->getMessage());
}
