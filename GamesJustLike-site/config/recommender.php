
<?php
// config/recommender.php
// Модуль расчёта персональных рекомендаций.

function computeRecommendations(PDO $pdo, ?int $userId, bool $includeUnavailable = false): array
{
    // 1. Предпочтения пользователя и история событий
    $userPrefs = [];
    $userGroupId = null;
    $userEvents = [];

    if ($userId !== null) {
        $stmt = $pdo->prepare(
            'SELECT preference_id, value
             FROM user_preferences
             WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $userPrefs[(int)$r['preference_id']] = (float)$r['value'];
        }

        $stmt = $pdo->prepare(
            'SELECT group_id FROM users WHERE id = :u'
        );
        $stmt->execute([':u' => $userId]);
        $userGroupId = $stmt->fetchColumn() ?: null;

        // События с весами: покупка > корзина > просмотр
        $stmt = $pdo->prepare("
            SELECT product_id, event_type
            FROM user_events
            WHERE user_id = :u
              AND product_id IS NOT NULL
              AND created_at >= datetime('now', '-30 days')
        ");
        $stmt->execute([':u' => $userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['product_id'];

            $weight = match ($r['event_type']) {
                'view' => 0.2,
                'cart_add' => 0.6,
                'purchase' => 1.0,
                default => 0.0
            };

            if ($weight > ($userEvents[$id] ?? 0)) {
                $userEvents[$id] = $weight;
            }
        }
    }

    // 2. Групповые предпочтения и категории
    $groupPrefs = [];
    $groupCategories = [];

    if ($userGroupId) {
        $stmt = $pdo->prepare(
            'SELECT preference_id, coefficient
             FROM group_preferences WHERE group_id = :g'
        );
        $stmt->execute([':g' => $userGroupId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $groupPrefs[(int)$r['preference_id']] = (float)$r['coefficient'];
        }

        $stmt = $pdo->prepare(
            'SELECT category_id, coefficient
             FROM group_categories WHERE group_id = :g'
        );
        $stmt->execute([':g' => $userGroupId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $groupCategories[(int)$r['category_id']] = (float)$r['coefficient'];
        }
    }

    // 3. Товары
    $where = $includeUnavailable ? '' : 'WHERE p.stock > 0';

    $sql = "
        SELECT p.id, p.name, p.price, p.stock, p.rating, p.image_url,
               p.genre_text, p.game_type, p.platforms, p.age_rating,
               s.category_id
        FROM products p
        LEFT JOIN subcategories s ON s.id = p.subcategory_id
        $where
        ORDER BY p.id
    ";

    $products = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // 4. Предпочтения товаров
    $productPrefs = [];

    foreach ($pdo->query(
        'SELECT product_id, preference_id, coefficient
         FROM product_preferences'
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $productPrefs[(int)$r['product_id']][(int)$r['preference_id']]
            = (float)$r['coefficient'];
    }

    // 5. Связи товаров
    $relations = [];

    foreach ($pdo->query(
        'SELECT source_product_id, target_product_id, coefficient
         FROM product_relations'
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $relations[(int)$r['source_product_id']][] = [
            'target' => (int)$r['target_product_id'],
            'coeff' => (float)$r['coefficient']
        ];
    }

    $result = [];

    foreach ($products as $p) {
        $pid = (int)$p['id'];
        $prefs = $productPrefs[$pid] ?? [];

        // Personal
        $personal = 0.0;

        if ($userPrefs && $prefs) {
            $sum = 0.0;
            $sumWeight = 0.0;

            foreach ($prefs as $prefId => $coef) {
                $sum += ($userPrefs[$prefId] ?? 0.0) * $coef;
                $sumWeight += $coef;
            }

            if ($sumWeight > 0) {
                $personal = $sum / $sumWeight;
            }
        }

        // Group
        $groupScore = 0.0;

        if ($userGroupId) {
            $gpSum = 0.0;
            $gpWeight = 0.0;

            foreach ($prefs as $prefId => $coef) {
                $gpSum += ($groupPrefs[$prefId] ?? 0.0) * $coef;
                $gpWeight += $coef;
            }

            $gpMatch = $gpWeight > 0 ? $gpSum / $gpWeight : 0.0;
            $gc = $groupCategories[(int)($p['category_id'] ?? 0)] ?? 0.0;

            $groupScore = 0.6 * $gpMatch + 0.4 * $gc;
        }

        // Relations
        $relScore = 0.0;

        if (!empty($relations[$pid]) && $userEvents) {
            foreach ($relations[$pid] as $rel) {
                if (isset($userEvents[$rel['target']])) {
                    $relScore += $rel['coeff'] * $userEvents[$rel['target']];
                }
            }

            $relScore = min(1.0, $relScore);
        }

        // Baseline
        $baseline = ((float)$p['rating']) / 5.0;

        // Events: сходство предпочтений товара с товарами,
        // с которыми пользователь взаимодействовал
        $eventsScore = 0.0;
        $eventWeightSum = 0.0;
        $eventSimilaritySum = 0.0;

        foreach ($userEvents as $eventProductId => $eventWeight) {
            $eventPrefs = $productPrefs[$eventProductId] ?? [];

            if (!$eventPrefs || !$prefs) {
                continue;
            }

            $dot = 0.0;
            $normA = 0.0;
            $normB = 0.0;

            foreach ($prefs as $prefId => $coef) {
                $a = (float)$coef;
                $b = (float)($eventPrefs[$prefId] ?? 0.0);

                $dot += $a * $b;
                $normA += $a * $a;
                $normB += $b * $b;
            }

            if ($normA > 0 && $normB > 0) {
                $similarity = $dot / (sqrt($normA) * sqrt($normB));
                $eventSimilaritySum += $similarity * $eventWeight;
                $eventWeightSum += $eventWeight;
            }
        }

        if ($eventWeightSum > 0) {
            $eventsScore = $eventSimilaritySum / $eventWeightSum;
        }

        $eventsScore = max(0.0, min(1.0, $eventsScore));

        // Итоговая оценка
        $total = 0.40 * $personal
               + 0.25 * $groupScore
               + 0.15 * $relScore
               + 0.15 * $baseline
               + 0.05 * $eventsScore;

        $result[] = [
            'id' => $pid,
            'name' => $p['name'],
            'price' => $p['price'],
            'stock' => $p['stock'],
            'rating' => $p['rating'],
            'image_url' => $p['image_url'],
            'genre_text' => $p['genre_text'],
            'game_type' => $p['game_type'],
            'platforms' => $p['platforms'],
            'age_rating' => $p['age_rating'],
            'category_id' => (int)($p['category_id'] ?? 0),
            'personal' => round($personal, 4),
            'group' => round($groupScore, 4),
            'relations' => round($relScore, 4),
            'baseline' => round($baseline, 4),
            'events' => round($eventsScore, 4),
            'total' => round($total, 4)
        ];
    }

    // Сортировка по итоговой оценке
    usort($result, function ($a, $b) {
        if (abs($b['total'] - $a['total']) > 0.00001) {
            return $b['total'] <=> $a['total'];
        }

        return $a['id'] <=> $b['id'];
    });

    return $result;
}
