<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo '<h2>GamesJustLike — дополнение каталога</h2>';

try {
    $pdo = require __DIR__ . '/config/database.php';

    $pdo->beginTransaction();

    // Получаем существующие игры
    $stmt = $pdo->query("
        SELECT id, subcategory_id, name, description,
               developer, release_year, price, stock,
               age_rating, image_url
        FROM products
        ORDER BY id
    ");

    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($games) < 1) {
        throw new Exception('В базе нет исходных игр.');
    }

    // Проверяем категории
    $subcategories = $pdo->query(
        "SELECT id FROM subcategories ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$subcategories) {
        throw new Exception('В базе нет подкатегорий.');
    }

    $insert = $pdo->prepare("
        INSERT INTO products (
            subcategory_id,
            name,
            description,
            developer,
            release_year,
            price,
            stock,
            age_rating,
            image_url
        ) VALUES (
            :subcategory_id,
            :name,
            :description,
            :developer,
            :release_year,
            :price,
            :stock,
            :age_rating,
            :image_url
        )
    ");

    $existing = $pdo->query(
        "SELECT name FROM products"
    )->fetchAll(PDO::FETCH_COLUMN);

    $existingNames = array_map('mb_strtolower', $existing);

    $added = 0;

    $suffixes = [
        'Ultimate Edition',
        'Definitive Edition',
        'Enhanced Edition',
        'Remastered',
        'Complete Edition',
        'Anniversary Edition',
        'Special Edition',
        'Deluxe Edition',
        'Expanded Edition',
        'Collector Edition'
    ];

    for ($i = 0; $i < 100; $i++) {
        $source = $games[$i % count($games)];

        $suffix = $suffixes[$i % count($suffixes)];
        $name = $source['name'] . ' — ' . $suffix . ' ' . ($i + 1);

        if (in_array(mb_strtolower($name), $existingNames, true)) {
            continue;
        }

        $insert->execute([
            ':subcategory_id' => $subcategories[$i % count($subcategories)],
            ':name' => $name,
            ':description' => $source['description'] . ' Дополнительная учебная запись каталога.',
            ':developer' => $source['developer'],
            ':release_year' => $source['release_year'],
            ':price' => $source['price'],
            ':stock' => max(1, (int)$source['stock']),
            ':age_rating' => $source['age_rating'],
            ':image_url' => $source['image_url']
        ]);

        $existingNames[] = mb_strtolower($name);
        $added++;
    }

    $total = (int)$pdo->query(
        "SELECT COUNT(*) FROM products"
    )->fetchColumn();

    $pdo->commit();

    echo '<p>Добавлено записей: ' . $added . '</p>';
    echo '<p>Всего игр в каталоге: ' . $total . '</p>';

    if ($total >= 200) {
        echo '<h3 style="color:green">Каталог содержит минимум 200 записей.</h3>';
    } else {
        echo '<h3 style="color:red">До 200 записей пока не хватает.</h3>';
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo '<p style="color:red">Ошибка: ' .
         htmlspecialchars($e->getMessage()) .
         '</p>';
}