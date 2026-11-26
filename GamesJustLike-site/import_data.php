<?php
// Учебный импорт данных GamesJustLike. Повторный запуск не перезаписывает профили.
error_reporting(E_ALL);
ini_set('display_errors', '1');

$pdo = require __DIR__ . '/config/database.php';

/** Приводит запись игры к названиям полей, которые использует импортёр. */
function normalizeGameRecord(array $game): array
{
    $map = [
        'id'=>'Id', 'name'=>'Name', 'imageurl'=>'ImageUrl', 'image'=>'ImageUrl',
        'genre'=>'Genre', 'tags'=>'Tags', 'description'=>'Description',
        'developer'=>'Developer', 'publisher'=>'Publisher', 'releasedate'=>'ReleaseDate',
        'releaseyear'=>'ReleaseDate', 'rating'=>'Rating', 'gametype'=>'GameType',
        'platforms'=>'Platforms', 'agerating'=>'AgeRating', 'languages'=>'Languages',
        'isfree'=>'IsFree', 'steamurl'=>'SteamUrl', 'epicurl'=>'EpicUrl', 'gogurl'=>'GogUrl',
        'steamprice'=>'SteamPrice', 'epicprice'=>'EpicPrice', 'gogprice'=>'GogPrice'
    ];
    $normalized = [];
    foreach ($game as $key => $value) {
        $canonical = $map[strtolower((string)$key)] ?? null;
        if ($canonical === null) continue;
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn($item) => is_scalar($item) ? (string)$item : '', $value));
        } elseif (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        $normalized[$canonical] = $value;
    }
    return $normalized;
}

/** Определяет, является ли массив обычным JSON-массивом с индексами 0..N-1. */
function isJsonList(array $value): bool
{
    if ($value === []) return true;
    return array_keys($value) === range(0, count($value) - 1);
}

// Файлы проверяются по очереди. Если в database лежит пустая копия,
// импортёр попробует games.json из корня проекта или Data старого проекта.
$jsonCandidates = [
    __DIR__ . '/database/games.json',
    __DIR__ . '/games.json',
    __DIR__ . '/Data/games.json',
    dirname(__DIR__) . '/GamesJustLike/Data/games.json'
];
$jsonPath = null;
$jsonContent = null;
$games = [];
$pathDiagnostics = [];

foreach ($jsonCandidates as $candidate) {
    if (!is_file($candidate)) {
        $pathDiagnostics[] = $candidate . ' — файл не найден';
        continue;
    }
    if (!is_readable($candidate)) {
        $pathDiagnostics[] = $candidate . ' — нет прав на чтение';
        continue;
    }

    $raw = file_get_contents($candidate);
    if ($raw === false || trim($raw) === '') {
        $pathDiagnostics[] = $candidate . ' — файл пустой или не читается';
        continue;
    }

    // Удаляем BOM и при необходимости переводим UTF-16 / Windows-1251 в UTF-8.
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    } elseif (strncmp($raw, "\xFF\xFE", 2) === 0) {
        $raw = function_exists('iconv') ? @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, 2)) : false;
    } elseif (strncmp($raw, "\xFE\xFF", 2) === 0) {
        $raw = function_exists('iconv') ? @iconv('UTF-16BE', 'UTF-8//IGNORE', substr($raw, 2)) : false;
    }
    if ($raw === false) {
        $pathDiagnostics[] = $candidate . ' — не удалось преобразовать кодировку';
        continue;
    }
    if (preg_match('//u', $raw) !== 1 && function_exists('iconv')) {
        $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $raw);
        if ($converted !== false) $raw = $converted;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $pathDiagnostics[] = $candidate . ' — ошибка JSON: ' . json_last_error_msg();
        continue;
    }
    if (!is_array($decoded)) {
        $pathDiagnostics[] = $candidate . ' — корневой элемент JSON не является массивом или объектом';
        continue;
    }

    // Поддерживаем [ ... ], а также {"games":[...]}, {"data":[...]} и подобные обёртки.
    if (!isJsonList($decoded)) {
        $wrapper = null;
        foreach ($decoded as $key => $value) {
            if (in_array(strtolower((string)$key), ['games', 'data', 'items', 'products'], true) && is_array($value)) {
                $wrapper = $value;
                break;
            }
        }
        if ($wrapper !== null) {
            $decoded = $wrapper;
        } else {
            $values = array_values($decoded);
            if ($values !== [] && count(array_filter($values, 'is_array')) === count($values)) {
                $decoded = $values; // объект, где ID игр используются как ключи
            }
        }
    }

    if (!is_array($decoded) || $decoded === []) {
        $pathDiagnostics[] = $candidate . ' — JSON прочитан, но список игр пуст';
        continue;
    }

    $validGames = [];
    foreach ($decoded as $record) {
        if (is_object($record)) $record = (array)$record;
        if (!is_array($record)) continue;
        $game = normalizeGameRecord($record);
        if (trim((string)($game['Name'] ?? '')) !== '') {
            $validGames[] = $game;
        }
    }

    if ($validGames === []) {
        $pathDiagnostics[] = $candidate . ' — записи найдены, но не обнаружено поле Name/name';
        continue;
    }

    $jsonPath = $candidate;
    $jsonContent = $raw;
    $games = $validGames;
    break;
}

if ($jsonPath === null || $games === []) {
    http_response_code(400);
    echo '<h1>Не удалось импортировать каталог игр</h1>';
    echo '<p>Я проверил несколько возможных расположений games.json. Создайте или скопируйте актуальный файл со 100 играми в <code>database/games.json</code>.</p><pre>';
    echo htmlspecialchars(implode("\n", $pathDiagnostics));
    echo '</pre>';
    exit;
}

function lowerText(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN " . $column . " " . $definition);
    }
}

// Дополнительные поля каталога из исходного JSON предыдущего проекта.
$extraColumns = [
    'genre_text' => 'TEXT',
    'tags' => 'TEXT',
    'game_type' => 'TEXT',
    'platforms' => 'TEXT',
    'rating' => 'REAL NOT NULL DEFAULT 0',
    'publisher' => 'TEXT',
    'languages' => 'TEXT',
    'is_free' => 'INTEGER NOT NULL DEFAULT 0',
    'steam_url' => 'TEXT',
    'epic_url' => 'TEXT',
    'gog_url' => 'TEXT',
    'steam_price' => 'REAL',
    'epic_price' => 'REAL',
    'gog_price' => 'REAL',
    'currency' => "TEXT NOT NULL DEFAULT 'USD'"
];
foreach ($extraColumns as $column => $definition) {
    ensureColumn($pdo, 'products', $column, $definition);
}

$categoryData = [
    'Экшен' => ['Шутеры', 'Экшен-приключения', 'Файтинги и платформеры'],
    'RPG' => ['Action-RPG', 'Тактические RPG', 'JRPG и классические RPG'],
    'Приключения' => ['Открытый мир', 'Квесты', 'Сюжетные приключения'],
    'Хоррор' => ['Survival Horror', 'Психологический хоррор', 'Кооперативный хоррор'],
    'Стратегии' => ['RTS', 'Пошаговые стратегии', '4X и тактика'],
    'Выживание и песочницы' => ['Выживание', 'Крафтинг и строительство', 'Песочницы'],
    'Гонки и спорт' => ['Гонки', 'Автосимуляторы', 'Спортивные игры'],
    'Симуляторы и инди' => ['Симуляторы жизни', 'Фермы и строительство', 'Инди-проекты']
];

$preferenceData = [
    'action'      => ['Экшен', 'Интерес к динамичным боевым играм'],
    'rpg'         => ['RPG', 'Интерес к ролевому развитию персонажа'],
    'adventure'   => ['Приключения', 'Интерес к исследованию и сюжетным заданиям'],
    'horror'      => ['Хоррор', 'Интерес к атмосферным и пугающим играм'],
    'strategy'    => ['Стратегии', 'Интерес к тактике и планированию'],
    'survival'    => ['Выживание', 'Интерес к ресурсам и выживанию'],
    'racing'      => ['Гонки и спорт', 'Интерес к гонкам и спортивным играм'],
    'simulation'  => ['Симуляторы', 'Интерес к моделированию и управлению'],
    'indie'       => ['Инди', 'Интерес к независимым проектам'],
    'coop'        => ['Кооператив', 'Предпочтение совместной игры'],
    'multiplayer' => ['Мультиплеер', 'Предпочтение сетевой игры'],
    'open_world'  => ['Открытый мир', 'Предпочтение свободного исследования мира']
];

// Базовые профили четырёх учебных групп. Шкала всех коэффициентов: от -1 до +1.
$groupProfiles = [
    'story' => [
        'name' => 'Сюжетные исследователи',
        'values' => ['action'=>0.35,'rpg'=>0.90,'adventure'=>0.95,'horror'=>-0.10,'strategy'=>0.25,'survival'=>0.30,'racing'=>-0.35,'simulation'=>0.20,'indie'=>0.65,'coop'=>0.20,'multiplayer'=>-0.20,'open_world'=>0.90]
    ],
    'competitive' => [
        'name' => 'Соревновательные игроки',
        'values' => ['action'=>0.85,'rpg'=>0.10,'adventure'=>-0.20,'horror'=>-0.30,'strategy'=>0.65,'survival'=>0.10,'racing'=>0.80,'simulation'=>0.15,'indie'=>0.10,'coop'=>0.35,'multiplayer'=>0.95,'open_world'=>0.10]
    ],
    'survival' => [
        'name' => 'Любители выживания',
        'values' => ['action'=>0.55,'rpg'=>0.45,'adventure'=>0.50,'horror'=>0.80,'strategy'=>0.35,'survival'=>1.00,'racing'=>-0.30,'simulation'=>0.15,'indie'=>0.40,'coop'=>0.75,'multiplayer'=>0.55,'open_world'=>0.80]
    ],
    'casual' => [
        'name' => 'Казуальные игроки',
        'values' => ['action'=>0.10,'rpg'=>0.25,'adventure'=>0.40,'horror'=>-0.85,'strategy'=>0.15,'survival'=>-0.10,'racing'=>0.20,'simulation'=>0.90,'indie'=>0.80,'coop'=>0.35,'multiplayer'=>0.10,'open_world'=>0.35]
    ]
];

$groupCategoryValues = [
    'story' => ['Экшен'=>0.30,'RPG'=>0.90,'Приключения'=>1.00,'Хоррор'=>-0.10,'Стратегии'=>0.25,'Выживание и песочницы'=>0.40,'Гонки и спорт'=>-0.35,'Симуляторы и инди'=>0.55],
    'competitive' => ['Экшен'=>0.80,'RPG'=>0.15,'Приключения'=>-0.15,'Хоррор'=>-0.25,'Стратегии'=>0.65,'Выживание и песочницы'=>0.20,'Гонки и спорт'=>0.90,'Симуляторы и инди'=>0.10],
    'survival' => ['Экшен'=>0.50,'RPG'=>0.45,'Приключения'=>0.45,'Хоррор'=>0.85,'Стратегии'=>0.35,'Выживание и песочницы'=>1.00,'Гонки и спорт'=>-0.35,'Симуляторы и инди'=>0.20],
    'casual' => ['Экшен'=>0.10,'RPG'=>0.25,'Приключения'=>0.40,'Хоррор'=>-0.85,'Стратегии'=>0.20,'Выживание и песочницы'=>-0.10,'Гонки и спорт'=>0.25,'Симуляторы и инди'=>0.90]
];

$preferenceRules = [
    'action' => ['action','экшен','shooter','шутер','combat','fighting','файтинг'],
    'rpg' => ['rpg','ролев','role-playing','jrpg'],
    'adventure' => ['adventure','приключ','quest','квест'],
    'horror' => ['horror','хоррор','ужас','scary'],
    'strategy' => ['strategy','стратег','tactics','тактик','4x'],
    'survival' => ['survival','выживан','крафтинг','crafting','sandbox','песочниц'],
    'racing' => ['racing','гонк','sport','спорт','driving','автосим'],
    'simulation' => ['simulation','симулятор','simulator','farming','ферм'],
    'indie' => ['indie','инди'],
    'coop' => ['co-op','coop','кооператив'],
    'multiplayer' => ['multiplayer','мультиплеер','pvp','pve','online','онлайн'],
    'open_world' => ['open world','открытый мир','exploration','исследован']
];

function chooseCategory(string $text): string
{
    $t = lowerText($text);
    $rules = [
        'Хоррор' => ['horror','хоррор','ужас'],
        'Стратегии' => ['strategy','стратег','tactics','тактик','4x'],
        'Гонки и спорт' => ['racing','гонк','sport','спорт'],
        'Выживание и песочницы' => ['survival','выживан','sandbox','песочниц','crafting','крафтинг'],
        'RPG' => ['rpg','ролев','jrpg'],
        'Приключения' => ['adventure','приключ','quest','квест'],
        'Экшен' => ['action','экшен','shooter','шутер'],
        'Симуляторы и инди' => ['simulation','симулятор','indie','инди','farming','ферм']
    ];
    foreach ($rules as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($t, $keyword)) return $category;
        }
    }
    return 'Симуляторы и инди';
}

function chooseSubcategory(string $category, string $text): string
{
    $t = lowerText($text);
    if ($category === 'Экшен') {
        if (str_contains($t,'shooter') || str_contains($t,'шутер')) return 'Шутеры';
        if (str_contains($t,'fight') || str_contains($t,'файт')) return 'Файтинги и платформеры';
        return 'Экшен-приключения';
    }
    if ($category === 'RPG') {
        if (str_contains($t,'tactic') || str_contains($t,'тактик')) return 'Тактические RPG';
        if (str_contains($t,'jrpg')) return 'JRPG и классические RPG';
        return 'Action-RPG';
    }
    if ($category === 'Приключения') {
        if (str_contains($t,'open world') || str_contains($t,'открытый мир')) return 'Открытый мир';
        if (str_contains($t,'quest') || str_contains($t,'квест')) return 'Квесты';
        return 'Сюжетные приключения';
    }
    if ($category === 'Хоррор') {
        if (str_contains($t,'co-op') || str_contains($t,'кооператив')) return 'Кооперативный хоррор';
        if (str_contains($t,'psychological') || str_contains($t,'психолог')) return 'Психологический хоррор';
        return 'Survival Horror';
    }
    if ($category === 'Стратегии') {
        if (str_contains($t,'turn-based') || str_contains($t,'пошаг')) return 'Пошаговые стратегии';
        if (str_contains($t,'4x')) return '4X и тактика';
        return 'RTS';
    }
    if ($category === 'Выживание и песочницы') {
        if (str_contains($t,'sandbox') || str_contains($t,'песочниц')) return 'Песочницы';
        if (str_contains($t,'craft') || str_contains($t,'крафт') || str_contains($t,'building') || str_contains($t,'строитель')) return 'Крафтинг и строительство';
        return 'Выживание';
    }
    if ($category === 'Гонки и спорт') {
        if (str_contains($t,'simulator') || str_contains($t,'симулятор')) return 'Автосимуляторы';
        if (str_contains($t,'sport') || str_contains($t,'спорт')) return 'Спортивные игры';
        return 'Гонки';
    }
    if (str_contains($t,'farm') || str_contains($t,'ферм') || str_contains($t,'building') || str_contains($t,'строитель')) return 'Фермы и строительство';
    if (str_contains($t,'indie') || str_contains($t,'инди')) return 'Инди-проекты';
    return 'Симуляторы жизни';
}

$pdo->beginTransaction();
try {
    // Группы, категории, подкатегории и справочник изначально добавляются только один раз.
    $groupIds = [];
    $insertGroup = $pdo->prepare('INSERT OR IGNORE INTO customer_groups(name, description) VALUES(?, ?)');
    $findGroup = $pdo->prepare('SELECT id FROM customer_groups WHERE name = ?');
    foreach ($groupProfiles as $key => $profile) {
        $insertGroup->execute([$profile['name'], 'Учебная группа покупателей: ' . $profile['name']]);
        $findGroup->execute([$profile['name']]);
        $groupIds[$key] = (int)$findGroup->fetchColumn();
    }

    $categoryIds = [];
    $subcategoryIds = [];
    $insertCategory = $pdo->prepare('INSERT OR IGNORE INTO categories(name, description) VALUES(?, ?)');
    $findCategory = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
    $insertSubcategory = $pdo->prepare('INSERT OR IGNORE INTO subcategories(category_id, name) VALUES(?, ?)');
    $findSubcategory = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = ? AND name = ?');
    foreach ($categoryData as $categoryName => $subcategories) {
        $insertCategory->execute([$categoryName, 'Категория игрового каталога']);
        $findCategory->execute([$categoryName]);
        $categoryId = (int)$findCategory->fetchColumn();
        $categoryIds[$categoryName] = $categoryId;
        foreach ($subcategories as $subcategoryName) {
            $insertSubcategory->execute([$categoryId, $subcategoryName]);
            $findSubcategory->execute([$categoryId, $subcategoryName]);
            $subcategoryIds[$categoryName][$subcategoryName] = (int)$findSubcategory->fetchColumn();
        }
    }

    $insertPreference = $pdo->prepare('INSERT OR IGNORE INTO preferences(code, name, description) VALUES(?, ?, ?)');
    $findPreference = $pdo->prepare('SELECT id FROM preferences WHERE code = ?');
    $preferenceIds = [];
    foreach ($preferenceData as $code => $item) {
        $insertPreference->execute([$code, $item[0], $item[1]]);
        $findPreference->execute([$code]);
        $preferenceIds[$code] = (int)$findPreference->fetchColumn();
    }

    $insertGroupPreference = $pdo->prepare('INSERT OR IGNORE INTO group_preferences(group_id, preference_id, coefficient) VALUES(?, ?, ?)');
    foreach ($groupProfiles as $groupKey => $profile) {
        foreach ($profile['values'] as $code => $value) {
            $insertGroupPreference->execute([$groupIds[$groupKey], $preferenceIds[$code], $value]);
        }
    }

    $insertGroupCategory = $pdo->prepare('INSERT OR IGNORE INTO group_categories(group_id, category_id, coefficient) VALUES(?, ?, ?)');
    foreach ($groupCategoryValues as $groupKey => $values) {
        foreach ($values as $categoryName => $value) {
            $insertGroupCategory->execute([$groupIds[$groupKey], $categoryIds[$categoryName], $value]);
        }
    }

    // Десять демонстрационных аккаунтов A-J. Пароль одинаковый и учебный.
    $demoUsers = [
        ['demo_a','story'], ['demo_b','competitive'], ['demo_c','survival'],
        ['demo_d','casual'], ['demo_e','story'], ['demo_f','competitive'],
        ['demo_g','survival'], ['demo_h','casual'], ['demo_i','story'], ['demo_j','competitive']
    ];
    $insertUser = $pdo->prepare('INSERT OR IGNORE INTO users(login, email, password_hash, group_id) VALUES(?, ?, ?, ?)');
    $findUser = $pdo->prepare('SELECT id FROM users WHERE login = ?');
    $insertUserPreference = $pdo->prepare('INSERT OR IGNORE INTO user_preferences(user_id, preference_id, value) VALUES(?, ?, ?)');
    $demoPasswordHash = password_hash('Demo2026!', PASSWORD_DEFAULT);
    foreach ($demoUsers as $index => [$login, $groupKey]) {
        $insertUser->execute([$login, $login . '@example.test', $demoPasswordHash, $groupIds[$groupKey]]);
        $findUser->execute([$login]);
        $userId = (int)$findUser->fetchColumn();
        foreach ($groupProfiles[$groupKey]['values'] as $code => $baseValue) {
            // Небольшое отличие пользователей одной группы помогает проверить персонализацию.
            $offset = (($index % 3) - 1) * 0.04;
            $value = max(-1.0, min(1.0, $baseValue + $offset));
            $insertUserPreference->execute([$userId, $preferenceIds[$code], $value]);
        }
    }

    // Импорт игр из JSON предыдущего проекта. Исходные строки не перезаписываются при повторном запуске.
    $insertProduct = $pdo->prepare('INSERT OR IGNORE INTO products(
        id, subcategory_id, name, description, developer, release_year, price, stock, age_rating, image_url,
        genre_text, tags, game_type, platforms, rating, publisher, languages, is_free,
        steam_url, epic_url, gog_url, steam_price, epic_price, gog_price, currency
    ) VALUES(
        :id, :subcategory_id, :name, :description, :developer, :release_year, :price, :stock, :age_rating, :image_url,
        :genre_text, :tags, :game_type, :platforms, :rating, :publisher, :languages, :is_free,
        :steam_url, :epic_url, :gog_url, :steam_price, :epic_price, :gog_price, :currency
    )');
    $insertProductPreference = $pdo->prepare('INSERT OR IGNORE INTO product_preferences(product_id, preference_id, coefficient) VALUES(?, ?, ?)');
    $insertRelation = $pdo->prepare('INSERT OR IGNORE INTO product_relations(source_product_id, target_product_id, relation_type, coefficient) VALUES(?, ?, ?, ?)');
    $productMeta = [];

    foreach ($games as $game) {
        if (!is_array($game) || trim((string)($game['Name'] ?? '')) === '') continue;
        $genre = (string)($game['Genre'] ?? '');
        $tags = (string)($game['Tags'] ?? '');
        $combined = $genre . ' ' . $tags . ' ' . (string)($game['GameType'] ?? '');
        $categoryName = chooseCategory($combined);
        $subcategoryName = chooseSubcategory($categoryName, $combined);
        $subcategoryId = $subcategoryIds[$categoryName][$subcategoryName];

        $prices = [];
        foreach (['SteamPrice','EpicPrice','GogPrice'] as $priceField) {
            if (isset($game[$priceField]) && is_numeric($game[$priceField]) && (float)$game[$priceField] > 0) {
                $prices[] = (float)$game[$priceField];
            }
        }
        $isFree = !empty($game['IsFree']) ? 1 : 0;
        $price = $prices ? min($prices) : 0.0;
        $releaseYear = null;
        if (!empty($game['ReleaseDate'])) {
            $year = substr((string)$game['ReleaseDate'], 0, 4);
            if (ctype_digit($year)) $releaseYear = (int)$year;
        }
        $sourceId = isset($game['Id']) && is_numeric($game['Id']) ? (int)$game['Id'] : null;
        $insertProduct->execute([
            ':id'=>$sourceId, ':subcategory_id'=>$subcategoryId, ':name'=>(string)$game['Name'],
            ':description'=>(string)($game['Description'] ?? ''), ':developer'=>(string)($game['Developer'] ?? ''),
            ':release_year'=>$releaseYear, ':price'=>$price, ':stock'=>5 + (($sourceId ?? 1) * 7 % 16),
            ':age_rating'=>(string)($game['AgeRating'] ?? ''), ':image_url'=>(string)($game['ImageUrl'] ?? ''),
            ':genre_text'=>$genre, ':tags'=>$tags, ':game_type'=>(string)($game['GameType'] ?? ''),
            ':platforms'=>(string)($game['Platforms'] ?? ''), ':rating'=>(float)($game['Rating'] ?? 0),
            ':publisher'=>(string)($game['Publisher'] ?? ''), ':languages'=>(string)($game['Languages'] ?? ''),
            ':is_free'=>$isFree, ':steam_url'=>(string)($game['SteamUrl'] ?? ''),
            ':epic_url'=>(string)($game['EpicUrl'] ?? ''), ':gog_url'=>(string)($game['GogUrl'] ?? ''),
            ':steam_price'=>isset($game['SteamPrice']) ? (float)$game['SteamPrice'] : null,
            ':epic_price'=>isset($game['EpicPrice']) ? (float)$game['EpicPrice'] : null,
            ':gog_price'=>isset($game['GogPrice']) ? (float)$game['GogPrice'] : null,
            ':currency'=>'USD'
        ]);

        if ($sourceId !== null) {
            $productId = $sourceId;
        } else {
            $productId = (int)$pdo->lastInsertId();
        }
        $productMeta[] = ['id'=>$productId, 'subcategory_id'=>$subcategoryId];

        $textForRules = lowerText($combined);
        foreach ($preferenceRules as $code => $keywords) {
            $coefficient = 0.0;
            foreach ($keywords as $keyword) {
                if (str_contains($textForRules, $keyword)) {
                    $coefficient = str_contains(lowerText($genre), $keyword) ? 0.85 : 0.60;
                    break;
                }
            }
            $insertProductPreference->execute([$productId, $preferenceIds[$code], $coefficient]);
        }
    }

    // Направленные связи похожих игр: до трёх товаров из той же подкатегории в каждом направлении.
    $bySubcategory = [];
    foreach ($productMeta as $item) $bySubcategory[$item['subcategory_id']][] = $item['id'];
    foreach ($bySubcategory as $ids) {
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            $added = 0;
            for ($j = $i + 1; $j < $count && $added < 3; $j++) {
                if ($ids[$i] === $ids[$j]) continue;
                $insertRelation->execute([$ids[$i], $ids[$j], 'similar', 0.55]);
                $insertRelation->execute([$ids[$j], $ids[$i], 'similar', 0.55]);
                $added++;
            }
        }
    }

    $pdo->commit();

    $counts = [];
    foreach (['customer_groups','users','categories','subcategories','preferences','products','user_preferences','group_preferences','group_categories','product_preferences','product_relations'] as $table) {
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    echo '<h1>GamesJustLike — импорт учебных данных выполнен</h1>';
    echo '<p>Повторный импорт не перезаписывает существующие предпочтения пользователей.</p>';
    echo '<table border="1" cellpadding="7" cellspacing="0">';
    foreach ($counts as $table => $count) {
        echo '<tr><td>' . htmlspecialchars($table) . '</td><td>' . $count . '</td></tr>';
    }
    echo '</table>';
    echo '<p>Файл-источник: <code>' . htmlspecialchars($jsonPath) . '</code></p>';
    echo '<p>Корректно распознано игр в JSON: ' . count($games) . '.</p>';
    echo '<p>Размер JSON: ' . number_format(strlen($jsonContent), 0, '.', ' ') . ' байт.</p>';
    if ($counts['products'] < 200) {
        echo '<p style="color:#b45309"><strong>Внимание:</strong> в базе пока меньше 200 товаров. Импортировано то количество, которое есть в database/games.json. Нужно дополнить собственный каталог до 200 записей.</p>';
    } else {
        echo '<p style="color:green"><strong>Требование по количеству товаров выполнено:</strong> не менее 200.</p>';
    }
    echo '<p>Тестовые аккаунты: demo_a ... demo_j. Учебный пароль: Demo2026!</p>';
    echo '<p>После проверки удалите import_data.php из публичной папки сайта.</p>';

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo '<h1>Ошибка импорта</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
