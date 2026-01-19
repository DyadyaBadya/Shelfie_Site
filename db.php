<?php
// db.php — подключение к MySQL
define('DB_HOST','sql311.infinityfree.com');   // или localhost
define('DB_NAME','if0_40064476_firstbd');         // ваша база
define('DB_USER','if0_40064476');         // ваш пользователь MySQL
define('DB_PASS','R89698969r');             // пароль, если есть

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
