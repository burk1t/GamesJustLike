
<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $pdo = require __DIR__ . '/config/database.php';

        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.login,
                u.email,
                u.role_id,
                u.password_hash,
                r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.login = :login
            LIMIT 1
        ");

        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['login'] = $user['login'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];

            header('Location: /GamesJustLike-site/index.php');
            exit;
        }

        $error = 'Неверный логин или пароль.';

    } catch (Throwable $e) {
        $error = 'Техническая ошибка: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация — GameJustLike</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="login-container">
        <h1>Авторизация</h1>

        <?php if ($error): ?>
            <p style="color:#ff6464;">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="text" name="login" placeholder="Логин" required>
            <input type="password" name="password" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
    </main>
</body>
</html>
