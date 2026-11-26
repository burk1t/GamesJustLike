
PRAGMA foreign_keys = ON;

-- =========================================
-- 1. ГРУППЫ ПОКУПАТЕЛЕЙ
-- =========================================

CREATE TABLE IF NOT EXISTS customer_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
);

-- =========================================
-- 2. ПОЛЬЗОВАТЕЛИ
-- =========================================

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    login TEXT NOT NULL UNIQUE,
    email TEXT UNIQUE,
    password_hash TEXT NOT NULL,
    group_id INTEGER NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0
        CHECK (is_admin IN (0, 1)),
    is_active INTEGER NOT NULL DEFAULT 1
        CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (group_id)
        REFERENCES customer_groups(id)
        ON DELETE RESTRICT
);

-- =========================================
-- 3. КАТЕГОРИИ
-- =========================================

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT
);

-- =========================================
-- 4. ПОДКАТЕГОРИИ
-- =========================================

CREATE TABLE IF NOT EXISTS subcategories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL,
    name TEXT NOT NULL,

    FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE CASCADE,

    UNIQUE(category_id, name)
);

-- =========================================
-- 5. ТОВАРЫ
-- =========================================

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    subcategory_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    developer TEXT,
    release_year INTEGER,
    price REAL NOT NULL DEFAULT 0
        CHECK (price >= 0),
    stock INTEGER NOT NULL DEFAULT 0
        CHECK (stock >= 0),
    age_rating TEXT,
    image_url TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (subcategory_id)
        REFERENCES subcategories(id)
        ON DELETE RESTRICT
);

-- =========================================
-- 6. СПРАВОЧНИК ПРЕДПОЧТЕНИЙ
-- =========================================

CREATE TABLE IF NOT EXISTS preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT
);

-- =========================================
-- 7. МАТРИЦА: ПОЛЬЗОВАТЕЛЬ × ПРЕДПОЧТЕНИЕ
-- =========================================

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INTEGER NOT NULL,
    preference_id INTEGER NOT NULL,

    value REAL NOT NULL
        CHECK (value >= -1 AND value <= 1),

    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, preference_id),

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (preference_id)
        REFERENCES preferences(id)
        ON DELETE CASCADE
);

-- =========================================
-- 8. МАТРИЦА: ГРУППА × ПРЕДПОЧТЕНИЕ
-- =========================================

CREATE TABLE IF NOT EXISTS group_preferences (
    group_id INTEGER NOT NULL,
    preference_id INTEGER NOT NULL,

    coefficient REAL NOT NULL
        CHECK (coefficient >= -1 AND coefficient <= 1),

    PRIMARY KEY (group_id, preference_id),

    FOREIGN KEY (group_id)
        REFERENCES customer_groups(id)
        ON DELETE CASCADE,

    FOREIGN KEY (preference_id)
        REFERENCES preferences(id)
        ON DELETE CASCADE
);

-- =========================================
-- 9. МАТРИЦА: ГРУППА × КАТЕГОРИЯ
-- =========================================

CREATE TABLE IF NOT EXISTS group_categories (
    group_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,

    coefficient REAL NOT NULL
        CHECK (coefficient >= -1 AND coefficient <= 1),

    PRIMARY KEY (group_id, category_id),

    FOREIGN KEY (group_id)
        REFERENCES customer_groups(id)
        ON DELETE CASCADE,

    FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE CASCADE
);

-- =========================================
-- 10. МАТРИЦА: ТОВАР × ПРЕДПОЧТЕНИЕ
-- =========================================

CREATE TABLE IF NOT EXISTS product_preferences (
    product_id INTEGER NOT NULL,
    preference_id INTEGER NOT NULL,

    coefficient REAL NOT NULL
        CHECK (coefficient >= -1 AND coefficient <= 1),

    PRIMARY KEY (product_id, preference_id),

    FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE,

    FOREIGN KEY (preference_id)
        REFERENCES preferences(id)
        ON DELETE CASCADE
);

-- =========================================
-- 11. МАТРИЦА: ТОВАР × ТОВАР
-- =========================================

CREATE TABLE IF NOT EXISTS product_relations (
    source_product_id INTEGER NOT NULL,
    target_product_id INTEGER NOT NULL,

    relation_type TEXT NOT NULL,

    coefficient REAL NOT NULL
        CHECK (coefficient >= -1 AND coefficient <= 1),

    PRIMARY KEY (
        source_product_id,
        target_product_id,
        relation_type
    ),

    CHECK (source_product_id <> target_product_id),

    FOREIGN KEY (source_product_id)
        REFERENCES products(id)
        ON DELETE CASCADE,

    FOREIGN KEY (target_product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
);

-- =========================================
-- 12. ИСТОРИЯ ДЕЙСТВИЙ
-- =========================================

CREATE TABLE IF NOT EXISTS user_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER,
    product_id INTEGER,

    event_type TEXT NOT NULL CHECK (
        event_type IN (
            'view',
            'cart_add',
            'purchase',
            'cart_remove',
            'login'
        )
    ),

    event_key TEXT UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
);

-- =========================================
-- 13. КОРЗИНА ПОКУПАТЕЛЯ
-- =========================================

CREATE TABLE IF NOT EXISTS cart_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,

    user_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,

    quantity INTEGER NOT NULL DEFAULT 1
        CHECK (quantity > 0),

    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(user_id, product_id),

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
);

-- =========================================
-- 14. ИНДЕКСЫ ДЛЯ УСКОРЕНИЯ ЗАПРОСОВ
-- =========================================

CREATE INDEX IF NOT EXISTS idx_products_subcategory
ON products(subcategory_id);

CREATE INDEX IF NOT EXISTS idx_products_stock
ON products(stock);

CREATE INDEX IF NOT EXISTS idx_user_events_user
ON user_events(user_id);

CREATE INDEX IF NOT EXISTS idx_user_events_product
ON user_events(product_id);

CREATE INDEX IF NOT EXISTS idx_user_preferences
ON user_preferences(user_id);

CREATE INDEX IF NOT EXISTS idx_product_preferences
ON product_preferences(product_id);

CREATE INDEX IF NOT EXISTS idx_product_relations_target
ON product_relations(target_product_id);

PRAGMA user_version = 1;
