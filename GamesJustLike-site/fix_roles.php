
<?php
$pdo = require __DIR__ . '/config/database.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        )
    ");

    $roles = ['admin', 'manager', 'user'];

    $stmt = $pdo->prepare("
        INSERT OR IGNORE INTO roles (name)
        VALUES (:name)
    ");

    foreach ($roles as $role) {
        $stmt->execute([':name' => $role]);
    }

    echo "Таблица roles создана или уже существовала.<br>";

    $rows = $pdo->query("SELECT id, name FROM roles")->fetchAll();

    echo "<pre>";
    print_r($rows);
    echo "</pre>";

} catch (Throwable $e) {
    echo "Ошибка: " . htmlspecialchars($e->getMessage());
}
