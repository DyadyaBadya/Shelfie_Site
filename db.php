<?php
// db.php — подключение к MySQL
define('DB_HOST','127.0.0.1');   // или localhost
define('DB_NAME','firstbd');         // ваша база
define('DB_USER','root');         // ваш пользователь MySQL
define('DB_PASS','');             // пароль, если есть

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к базе: " . $e->getMessage());
}


?>
