<?php
// ============================================================
//  PizzaVote – Konfiguration
//  Datei: config.php
// ============================================================

// ── Admin-Passwort fürs Backend ──────────────────────────────
define('ADMIN_PASSWORD', 'admin123');      // bitte ändern!

// ── Sprache der Oberfläche ───────────────────────────────────
define('APP_LANG', 'de');   // 'de' oder 'en' — passende lang/<APP_LANG>.json muss existieren
require_once __DIR__ . '/i18n.php';

// ── Zeitzone ─────────────────────────────────────────────────
date_default_timezone_set('Europe/Vienna');

// ── SQLite-Datenbankpfad ─────────────────────────────────────
//    Ordner "data/" wird automatisch angelegt.
//    Webserver braucht Schreibrecht im Projektordner.
define('DB_PATH', __DIR__ . '/data/bestellung.db');

// ── PDO-Verbindung + Auto-Setup ───────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');   // bessere Concurrency
        $pdo->exec('PRAGMA foreign_keys = ON');
        setupSchema($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Datenbankfehler', 'detail' => $e->getMessage()]));
    }
    return $pdo;
}

// ── Schema automatisch anlegen (nur beim ersten Aufruf) ───────
function setupSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            ip         TEXT    NOT NULL UNIQUE,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS products (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            price       REAL    NOT NULL DEFAULT 0.0,
            image_url   TEXT    NOT NULL DEFAULT '',
            active      INTEGER NOT NULL DEFAULT 1,
            sort_order  INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS orders (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            title      TEXT    NOT NULL DEFAULT 'Bestellung',
            status     TEXT    NOT NULL DEFAULT 'active'
                               CHECK(status IN ('active','closed','cancelled')),
            deadline   TEXT             DEFAULT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            closed_at  TEXT             DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS order_items (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id   INTEGER NOT NULL REFERENCES orders(id)   ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
            product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
            comment    TEXT    NOT NULL DEFAULT '',
            created_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(order_id, user_id)
        );
    ");

    // Beispielprodukte nur beim allerersten Start
    $count = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ((int)$count === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO products (name, description, price, image_url, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([
            ['Margherita',       'Tomato, mozzarella, basil',                              13.90, 'img/margherita.jpg', 1],
            ['Salami',           'Tomato, mozzarella, salami',                             16.00, 'img/salami.jpg',     2],
            ['Quattro Formaggi', 'Four cheeses, oregano',                                  17.50, 'img/quattro.jpg',    3],
            ['Prosciutto',       'Tomato, mozzarella, ham',                                16.00, 'img/prosciutto.jpg', 4],
            ['Diavola',          'Spicy salami, chili, mozzarella',                        17.90, 'img/diavola.jpg',    5],
            ['Vegetariana',      'Seasonal vegetables, mozzarella',                        16.90, 'img/vegetariana.jpg',6],
            ['Tonno',            'Tomato, mozzarella, tuna, onions',                       16.90, 'img/tonno.jpg',      7],
            ['Spinaci',          'Tomato, mozzarella, spinach, sheep\'s cheese',           16.90, 'img/spinaci.jpg',    8],
            ['Rusticana',        'Tomato, mozzarella, ham, mushrooms, olives, egg, artichokes', 17.90, 'img/rustica.jpg', 9],
        ] as $r) $stmt->execute($r);
    }
}
