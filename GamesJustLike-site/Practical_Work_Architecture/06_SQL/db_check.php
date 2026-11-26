<?php
$pdo = require __DIR__ . '/config/database.php';

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "<pre style='font-family:Consolas,monospace;font-size:13px;line-height:1.4;'>";
echo "=== TABLES (" . count($tables) . ") ===\n";
foreach ($tables as $t) {
    echo "  - $t\n";
}

echo "\n\n=== COLUMNS & ROW COUNT ===\n";
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
        $cols = $pdo->query("PRAGMA table_info(\"$t\")")->fetchAll();
        echo "\n--- $t ($count rows) ---\n";
        foreach ($cols as $c) {
            $extra = [];
            if ($c['pk']) $extra[] = 'PK';
            if ($c['notnull']) $extra[] = 'NOT NULL';
            echo "  {$c['name']} {$c['type']}" . ($extra ? ' [' . implode(',', $extra) . ']' : '') . "\n";
        }
    } catch (Throwable $e) {
        echo "\n--- $t: ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n\n=== SAMPLE DATA (first 2 rows per key table) ===\n";
$keyTables = ['users','roles','groups','buyer_groups','preferences','preference_features',
              'categories','subcategories','products',
              'user_preferences','user_preference','group_preferences','group_preference',
              'group_categories','group_category',
              'product_preferences','product_relations',
              'user_events','events','actions','history'];
foreach ($keyTables as $t) {
    if (in_array($t, $tables)) {
        try {
            $rows = $pdo->query("SELECT * FROM \"$t\" LIMIT 2")->fetchAll();
            echo "\n--- $t ---\n";
            foreach ($rows as $r) {
                echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
        } catch (Throwable $e) {
            echo "\n--- $t: ERROR: " . $e->getMessage() . "\n";
        }
    }
}
echo "</pre>";