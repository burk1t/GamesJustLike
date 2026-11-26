
<?php
$pdo = require __DIR__ . '/config/database.php';

try {
    $columns = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $names = array_column($columns, 'name');

    if (!in_array('role_id', $names, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role_id INTEGER DEFAULT 3");
        echo "Столбец role_id добавлен.<br>";
    } else {
        echo "Столбец role_id уже существует.<br>";
    }

    // Назначаем администратора
    $stmt = $pdo->prepare("
        UPDATE users
        SET role_id = 1
        WHERE login = 'admin01'
    ");
    $stmt->execute();

    // Остальным назначаем роль user
    $pdo->exec("
        UPDATE users
        SET role_id = 3
        WHERE login != 'admin01' OR login IS NULL
    ");

    echo "Роли пользователям назначены.<br><br>";

    print_r($pdo->query("
        SELECT id, login, role_id
        FROM users
    ")->fetchAll());

} catch (Throwable $e) {
    echo "Ошибка: " . htmlspecialchars($e->getMessage());
}
