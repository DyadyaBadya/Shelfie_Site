<?php
session_start(); // Начинаем сессию для авторизации - для корректной работы в хосте выключить
require 'db.php'; // подключаем базу - для корректной работы в хосте выключить

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Конфигурация
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('BOOKS_DIR', __DIR__ . '/books');
define('DATA_DIR', __DIR__ . '/data');
define('PHOTO_DIR', __DIR__ . '/photo');
define('FONTS_DIR', __DIR__ . '/fonts');
define('ADMIN_PASS', '12345'); // пароль администратора (оставлен для совместимости)

function safe($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Создаём папки, если их нет
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(BOOKS_DIR)) mkdir(BOOKS_DIR, 0755, true);
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0755, true);
if (!is_dir(FONTS_DIR)) mkdir(FONTS_DIR, 0755, true);

// Файл с событиями
$events_file = DATA_DIR . '/events.json';
if (!file_exists($events_file)) {
    $sample = [
        ['id' => 1, 'title' => 'День открытых дверей', 'date' => date('Y-m-d', strtotime('+14 days')), 'desc' => '...'],
        ['id' => 2, 'title' => 'Выставка студенческих работ', 'date' => date('Y-m-d', strtotime('+30 days')), 'desc' => '...']
    ];
    file_put_contents($events_file, json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Инициализация переменных для форм
$reg_error = '';
$reg_ok = '';
$login_error = '';
$login_ok = '';
$error = $ok = '';

// === Обработка выхода ===
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Location: ?page=home');
    exit;
}

// === Обработка формы регистрации ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($login === '' || $password === '' || $password_confirm === '') {
        $reg_error = 'Все поля обязательны.';
    } elseif ($password !== $password_confirm) {
        $reg_error = 'Пароли не совпадают.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
            $stmt->execute([$login]);
            if ($stmt->fetch()) {
                $reg_error = 'Логин уже занят.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (login, password, role) VALUES (?, ?, 'user')");
                $stmt->execute([$login, $hashed_password]);
                $reg_ok = 'Регистрация успешна. Войдите в аккаунт.';
            }
        } catch (PDOException $e) {
            $reg_error = 'Ошибка базы данных: ' . safe($e->getMessage());
        }
    }
}

// === Обработка формы входа ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $login_error = 'Логин и пароль обязательны.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, login, password, role FROM users WHERE login = ?");
            $stmt->execute([$login]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['login'] = $user['login'];
                $_SESSION['role'] = $user['role'];
                $login_ok = 'Вход успешен.';
                header('Location: ?page=home');
                exit;
            } else {
                $login_error = 'Неверный логин или пароль.';
            }
        } catch (PDOException $e) {
            $login_error = 'Ошибка базы данных: ' . safe($e->getMessage());
        }
    }
}

// === Обработка формы контакта ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $message === '') {
        $error = 'Имя и сообщение обязательны.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (name, email, message) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $message]);
            $ok = 'Сообщение сохранено.';
        } catch (PDOException $e) {
            $error = 'Ошибка базы данных: ' . safe($e->getMessage());
        }
    }
}

// === Обработка удаления сообщений (админ) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_message' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $message_id = $_POST['message_id'] ?? 0;
    try {
        $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ?");
        $stmt->execute([$message_id]);
        $ok = 'Сообщение удалено.';
    } catch (PDOException $e) {
        $error = 'Ошибка удаления: ' . safe($e->getMessage());
    }
}

// === Обработка редактирования сообщений (админ) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_message' && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $message_id = $_POST['message_id'] ?? 0;
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name === '' || $message === '') {
        $error = 'Имя и сообщение обязательны.';
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE messages SET name = ?, email = ?, message = ? WHERE id = ?");
            $stmt->execute([$name, $email, $message, $message_id]);
            $ok = 'Сообщение обновлено.';
        } catch (PDOException $e) {
            $error = 'Ошибка редактирования: ' . safe($e->getMessage());
        }
    }
}

// === Обработка загрузки изображений ===
$upload_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = 'Ошибка загрузки файла.';
    } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        $upload_error = 'Файл слишком большой.';
    } else {
        $name = basename($_FILES['image']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            $upload_error = 'Недопустимый тип файла.';
        } else {
            $tmp = $_FILES['image']['tmp_name'];
            $newname = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = UPLOAD_DIR . '/' . $newname;
            if (!move_uploaded_file($tmp, $dest)) {
                $upload_error = 'Не удалось сохранить файл.';
            } else {
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
        }
    }
}

// === Обработка загрузки FB2 ===
$fb2_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_fb2') {
    if (!isset($_FILES['fb2']) || $_FILES['fb2']['error'] !== UPLOAD_ERR_OK) {
        $fb2_error = 'Ошибка загрузки файла.';
    } elseif ($_FILES['fb2']['size'] > 5 * 1024 * 1024) {
        $fb2_error = 'Файл слишком большой.';
    } else {
        $name = basename($_FILES['fb2']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'fb2') {
            $fb2_error = 'Требуется файл формата .fb2.';
        } else {
            $tmp = $_FILES['fb2']['tmp_name'];
            $newname = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = BOOKS_DIR . '/' . $newname;
            if (!move_uploaded_file($tmp, $dest)) {
                $fb2_error = 'Не удалось сохранить файл.';
            } else {
                header('Location: ?page=reader&file=' . urlencode($newname));
                exit;
            }
        }
    }
}

// Читаем события
$events = json_decode(file_get_contents($events_file), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $events = [];
    error_log('Ошибка чтения events.json: ' . json_last_error_msg());
}

// Поиск и фильтрация событий
$q = trim($_GET['q'] ?? '');
$filtered = [];
foreach ($events as $ev) {
    if ($q === '' || mb_stripos($ev['title'] . ' ' . $ev['desc'], $q) !== false) {
        $filtered[] = $ev;
    }
}

// Файлы в папке uploads (для галереи)
$files = [];
foreach (scandir(UPLOAD_DIR) as $f) {
    if ($f === '.' || $f === '..') continue;
    if (is_file(UPLOAD_DIR . '/' . $f)) $files[] = $f;
}

// Файлы в папке books (для читалки)
$books = [];
foreach (scandir(BOOKS_DIR) as $f) {
    if ($f === '.' || $f === '..') continue;
    if (is_file(BOOKS_DIR . '/' . $f) && pathinfo($f, PATHINFO_EXTENSION) === 'fb2') $books[] = $f;
}

// Определяем текущую страницу
$page = $_GET['page'] ?? 'home';

// Защита страниц
if ($page === 'admin' && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin')) {
    $page = 'access_denied';
} elseif ($page === 'reader' && !isset($_SESSION['user_id'])) {
    header('Location: ?page=login');
    exit;
}
?>

<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shelfie - Читалка</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- Навигация -->
    <nav class="navbar">
        <div class="navbar-brand">Shelfie</div>
        <div class="navbar-menu" id="navbarMenu">
            <a href="?page=home"><?php echo file_exists("photo/home.svg") ? '<img src="photo/'.rawurlencode('home.svg').'" alt="Главная" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Главная</a>
            <a href="?page=gallery"><?php echo file_exists("photo/gallery.svg") ? '<img src="photo/'.rawurlencode('gallery.svg').'" alt="Галерея" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Галерея</a>
            <a href="?page=events"><?php echo file_exists("photo/event.svg") ? '<img src="photo/'.rawurlencode('event.svg').'" alt="События" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> События</a>
            <a href="?page=contact"><?php echo file_exists("photo/contact.svg") ? '<img src="photo/'.rawurlencode('contact.svg').'" alt="Контакты" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Отправить сообщение</a>
            <a href="?page=messages"><?php echo file_exists("photo/messages.svg") ? '<img src="photo/'.rawurlencode('messages.svg').'" alt="Сообщения" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Сообщения</a>
            <a href="?page=admin"><?php echo file_exists("photo/admin.svg") ? '<img src="photo/'.rawurlencode('admin.svg').'" alt="Админка" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Админка</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <span>| <?php echo safe($_SESSION['login']); ?> - <?php echo safe($_SESSION['role']); ?>  |</span>
                <a href="?action=logout"><?php echo file_exists("photo/logout.svg") ? '<img src="photo/'.rawurlencode('logout.svg').'" alt="Выход" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Выход</a>
            <?php else: ?>
                <a href="?page=register"><?php echo file_exists("photo/register_icon.svg") ? '<img src="photo/'.rawurlencode('register_icon.svg').'" alt="Регистрация" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Регистрация</a>
                <a href="?page=login"><?php echo file_exists("photo/login.svg") ? '<img src="photo/'.rawurlencode('login.svg').'" alt="Вход" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Вход</a>
            <?php endif; ?>
            <a href="?page=reader"><?php echo file_exists("photo/reader.svg") ? '<img src="photo/'.rawurlencode('reader.svg').'" alt="Читалка" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Читалка</a>
        </div>
        <div class="navbar-toggle" onclick="toggleMenu()">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </nav>

    <!-- Контент страниц -->
    <div class="main-container">
        <main>
            <?php if ($page === 'home'): ?>
                <div class="shelfie-home">
                    <h1>Shelfie</h1>
                    <p>Ваша личная библиотека для чтения и вдохновения</p>
                    <?php if (file_exists("photo/shelfie1.png")): ?>
                        <img src="photo/<?php echo rawurlencode('shelfie1.png'); ?>" alt="Книжная полка" class="shelfie-img" loading="lazy">
                    <?php else: ?>
                        <p>[Изображение полки не найдено]</p>
                    <?php endif; ?>
                    <br><a href="?page=reader" class="shelfie-btn">Перейти к книгам</a>
                </div>

            <?php elseif ($page === 'register'): ?>
                <h1>Регистрация</h1>
                <?php if ($reg_error): ?>
                    <div class="error"><?php echo safe($reg_error); ?></div>
                <?php endif; ?>
                <?php if ($reg_ok): ?>
                    <div class="ok"><?php echo safe($reg_ok); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="register">
                    <input name="login" placeholder="Логин" value="<?php echo safe($login ?? ''); ?>" required><br>
                    <input type="password" name="password" placeholder="Пароль" required><br>
                    <input type="password" name="password_confirm" placeholder="Подтвердите пароль" required><br>
                    <button type="submit">Зарегистрироваться</button>
                </form>

            <?php elseif ($page === 'login'): ?>
                <h1>Вход</h1>
                <?php if ($login_error): ?>
                    <div class="error"><?php echo safe($login_error); ?></div>
                <?php endif; ?>
                <?php if ($login_ok): ?>
                    <div class="ok"><?php echo safe($login_ok); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <input name="login" placeholder="Логин" value="<?php echo safe($login ?? ''); ?>" required><br>
                    <input type="password" name="password" placeholder="Пароль" required><br>
                    <button type="submit">Войти</button>
                </form>

            <?php elseif ($page === 'gallery'): ?>
                <h1>Галерея</h1>
                <h3>Галерея изображений</h3>
                <?php if (empty($files)): ?>
                    <p>Нет файлов</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($files as $file): ?>
                            <div class="card">
                                <img src="uploads/<?php echo rawurlencode($file); ?>" 
                                     alt="<?php echo safe($file); ?>" 
                                     loading="lazy">
                                <div class="filename"><?php echo safe($file); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <h3>Загрузка изображений</h3>
                <?php if ($upload_error): ?>
                    <div class="error"><?php echo safe($upload_error); ?></div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="image" accept="image/*" required>
                    <button type="submit">Загрузить</button>
                </form>

            <?php elseif ($page === 'events'): ?>
                <h1>События сайта</h1>
                <form method="get" action="?page=events">
                    <input type="hidden" name="page" value="events">
                    <input name="q" placeholder="Поиск событий" value="<?php echo safe($q ?? ''); ?>">
                    <button>Искать</button>
                </form>
                <?php if (empty($filtered)): ?>
                    <p>События не найдены</p>
                <?php else: ?>
                    <?php foreach ($filtered as $event): ?>
                        <div class="event-card">
                            <div class="event-date"><?php echo safe(date('d M Y', strtotime($event['date']))); ?></div>
                            <div class="event-body">
                                <h4><?php echo safe($event['title']); ?></h4>
                                <p><?php echo safe($event['desc']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif ($page === 'contact'): ?>
                <h1>Отправка сообщений</h1>
                <?php if ($error): ?>
                    <div class="error"><?php echo safe($error); ?></div>
                <?php endif; ?>
                <?php if ($ok): ?>
                    <div class="ok"><?php echo safe($ok); ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="contact">
                    <input name="name" placeholder="Ваше имя" value="<?php echo safe($name ?? ''); ?>" required>
                    <input name="email" placeholder="Email" value="<?php echo safe($email ?? ''); ?>">
                    <textarea name="message" placeholder="Сообщение" required><?php echo safe($message ?? ''); ?></textarea>
                    <button type="submit" class="submit-btn">Отправить</button>
                </form>

            <?php elseif ($page === 'messages'): ?>
                <h1>Сообщения</h1>
                <?php
                $stmt = $pdo->query("SELECT id, name, email, message, created_at FROM messages ORDER BY created_at DESC");
                $msgs = $stmt->fetchAll();
                if (empty($msgs)) {
                    echo "<p>Сообщений нет.</p>";
                } else {
                    foreach ($msgs as $m) {
                        echo "<div class='message-card'>";
                        echo "<strong>" . safe($m['name']) . "</strong> (" . safe($m['email']) . ")<br>";
                        echo safe($m['message']) . "<br><small>" . safe($m['created_at']) . "</small>";
                        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                            echo "<form method='post' style='display:inline;'>";
                            echo "<input type='hidden' name='action' value='delete_message'>";
                            echo "<input type='hidden' name='message_id' value='" . $m['id'] . "'>";
                            echo "<button type='submit' class='delete-btn'>Удалить</button>";
                            echo "</form>";
                            echo "<form method='post' style='display:inline;'>";
                            echo "<input type='hidden' name='action' value='edit_message'>";
                            echo "<input type='hidden' name='message_id' value='" . $m['id'] . "'>";
                            echo "<input name='name' value='" . safe($m['name']) . "' required>";
                            echo "<input name='email' value='" . safe($m['email']) . "'>";
                            echo "<textarea name='message' required>" . safe($m['message']) . "</textarea>";
                            echo "<button type='submit' class='edit-btn'>Изменить</button>";
                            echo "</form>";
                        }
                        echo "</div>";
                    }
                }
                ?>

            <?php elseif ($page === 'admin'): ?>
                <h1>Админ-панель</h1>
                <p>Добро пожаловать, администратор! Здесь вы можете управлять контентом.</p>

            <?php elseif ($page === 'reader'): ?>
                <h1>Читалка</h1>
                <h3>Загрузка FB2</h3>
                <?php if ($fb2_error): ?>
                    <div class="error"><?php echo safe($fb2_error); ?></div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_fb2">
                    <input type="file" name="fb2" accept=".fb2" required>
                    <button type="submit" class="submit-btn">Загрузить</button>
                </form>
                <h3>Список книг</h3>
                <?php if (empty($books)): ?>
                    <p>Нет книг</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($books as $book): ?>
                            <li><a href="?page=reader&file=<?php echo urlencode($book); ?>"><?php echo safe($book); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php
                $genreTranslations = [
                    'sf_action' => 'Научная фантастика: Экшн',
                    'sf_litrpg' => 'ЛитRPG',
                    'network_literature' => 'Сетевая литература',
                    'sf_heroic' => 'Героическая фантастика',
                    'other' => 'Другое',
                    'sf_epic' => 'Эпическая фантастика',
                    // Добавьте больше жанров по необходимости
                    'default' => 'Неизвестный жанр'
                ];

                    if (isset($_GET['file']) && in_array($_GET['file'], $books)) {
                        $file = BOOKS_DIR . '/' . $_GET['file'];
                        try {
                            $xml = simplexml_load_file($file);
                            if ($xml === false) {
                                echo '<div class="error">Ошибка чтения файла FB2.</div>';
                            } else {
                                // === Извлечение обложки (замена старого блока) ===
                                $cover_image = '';
                                $cover_type = '';

                                /* Регистрируем пространство имён FB2 (default namespace) под префиксом fb,
                                чтобы xpath мог находить элементы типа fb:binary */
                                $namespaces = $xml->getDocNamespaces(true);
                                $fb_ns = $namespaces[''] ?? 'http://www.gribuser.ru/xml/fictionbook/2.0';
                                $xml->registerXPathNamespace('fb', $fb_ns);

                                // Ищем тег <image> внутри coverpage (учитывая namespace)
                                $imageNodes = $xml->xpath('//fb:coverpage/fb:image | //fb:title-info/fb:coverpage/fb:image | //fb:description/fb:title-info/fb:coverpage/fb:image');

                                if (!empty($imageNodes)) {
                                    $imageNode = $imageNodes[0];

                                    // Попытка получить l:href / xlink:href или обычный href
                                    $cover_id = '';
                                    $xlinkAttrs = $imageNode->attributes('http://www.w3.org/1999/xlink');
                                    if (isset($xlinkAttrs['href'])) {
                                        $cover_id = ltrim((string)$xlinkAttrs['href'], '#');
                                    } else {
                                        $plainAttrs = $imageNode->attributes();
                                        if (isset($plainAttrs['href'])) {
                                            $cover_id = ltrim((string)$plainAttrs['href'], '#');
                                        } elseif (isset($plainAttrs['l:href'])) {
                                            $cover_id = ltrim((string)$plainAttrs['l:href'], '#');
                                        }
                                    }

                                    if ($cover_id !== '') {
                                        // Ищем binary с учётом namespace (fb:binary)
                                        $binaryNodes = $xml->xpath("//fb:binary[@id='$cover_id']");
                                        if ($binaryNodes && isset($binaryNodes[0])) {
                                            $binaryNode = $binaryNodes[0];
                                            $cover_type = (string)$binaryNode['content-type'] ?: 'image/jpeg';

                                            // Убираем пробелы/переносы и проверяем base64
                                            $b64 = preg_replace('/\s+/', '', (string)$binaryNode);
                                            if ($b64 !== '' && base64_decode($b64, true) !== false) {
                                                // Используем data:URI — браузер отобразит картинку напрямую
                                                $cover_image = 'data:' . $cover_type . ';base64,' . $b64;
                                            } else {
                                                echo '<div class="error">Ошибка: неверные base64-данные для обложки (id="' . safe($cover_id) . '").</div>';
                                            }
                                        } else {
                                            // Попробуем внешний файл (например, если в ссылке указано cover.jpg)
                                            $cover_filename = basename($cover_id);
                                            if (file_exists(PHOTO_DIR . '/' . $cover_filename)) {
                                                $cover_image = 'photo/' . $cover_filename;
                                                $cover_type = mime_content_type(__DIR__ . '/' . $cover_image);
                                            } elseif (file_exists(UPLOAD_DIR . '/' . $cover_filename)) {
                                                $cover_image = 'uploads/' . $cover_filename;
                                                $cover_type = mime_content_type(__DIR__ . '/' . $cover_image);
                                            } else {
                                                echo '<div class="error">Ошибка: Binary с id="' . safe($cover_id) . '" не найден и внешний файл также отсутствует.</div>';
                                            }
                                        }
                                    } else {
                                        echo '<div class="error">Ошибка: не найден атрибут href в теге &lt;image&gt;.</div>';
                                    }
                                } else {
                                    echo '<div class="error">Ошибка: Тег coverpage/image отсутствует.</div>';
                                }

                                // Показ обложки (единожды)
                                echo '<div class="reader-container">';
                                if ($cover_image) {
                                    echo '<img src="' . $cover_image . '" alt="Обложка книги" class="reader-cover">';
                                } else {
                                    echo '<div class="reader-cover-placeholder">Обложка отсутствует</div>';
                                }
                                echo '</div>';
                                // === Конец блока обложки ===

                                function renderNode($node, $ns) {
                                $html = '';

                                switch ($node->getName()) {
                                    case 'section':
                                        // Рекурсивный вывод секции
                                        $html .= '<div class="fb2-section">';
                                        foreach ($node->children($ns) as $child) {
                                            $html .= renderNode($child, $ns);
                                        }
                                        $html .= '</div>';
                                        break;

                                    case 'title':
                                        $titleText = '';
                                        foreach ($node->children($ns) as $child) {
                                            if ($child->getName() === 'p') {
                                                $titleText .= (string)$child . ' ';
                                            }
                                        }
                                        $html .= '<h2 class="fb2-title">' . htmlspecialchars(trim($titleText)) . '</h2>';
                                        break;

                                    case 'subtitle':
                                        $html .= '<h3 class="fb2-subtitle">' . htmlspecialchars((string)$node) . '</h3>';
                                        break;

                                    case 'p':
                                        $html .= '<p class="fb2-p">' . htmlspecialchars((string)$node) . '</p>';
                                        break;

                                    case 'empty-line':
                                        $html .= '<br>';
                                        break;

                                    case 'epigraph':
                                        $html .= '<blockquote class="fb2-epigraph">';
                                        foreach ($node->children($ns) as $child) {
                                            $html .= renderNode($child, $ns);
                                        }
                                        $html .= '</blockquote>';
                                        break;

                                    case 'poem':
                                        $html .= '<div class="fb2-poem">';
                                        foreach ($node->children($ns) as $child) {
                                            $html .= renderNode($child, $ns);
                                        }
                                        $html .= '</div>';
                                        break;

                                    case 'stanza':
                                        $html .= '<div class="fb2-stanza">';
                                        foreach ($node->children($ns) as $child) {
                                            $html .= renderNode($child, $ns);
                                        }
                                        $html .= '</div>';
                                        break;

                                    case 'v': // строка стихотворения
                                        $html .= '<div class="fb2-verse">' . htmlspecialchars((string)$node) . '</div>';
                                        break;

                                    default:
                                        // Если это текстовый узел
                                        if (trim((string)$node) !== '') {
                                            $html .= htmlspecialchars((string)$node);
                                        }
                                }

                                return $html;
                            }

                            // === Вывод содержимого книги ===
                            $fb_ns = 'http://www.gribuser.ru/xml/fictionbook/2.0';

                            // Основное тело (без @name или с @name="main")
                            $mainBody = $xml->xpath('//fb:body[not(@name) or @name="main"]');
                            if ($mainBody) {
                                foreach ($mainBody as $body) {
                                    echo '<div class="fb2-body">';
                                    foreach ($body->children($fb_ns) as $child) {
                                        echo renderNode($child, $fb_ns);
                                    }
                                    echo '</div>';
                                }
                            }

                            // Примечания (body name="notes")
                            $notesBody = $xml->xpath('//fb:body[@name="notes"]');
                            if ($notesBody) {
                                echo '<div class="fb2-notes"><h3>Примечания</h3>';
                                foreach ($notesBody[0]->children($fb_ns) as $section) {
                                    if ($section->getName() === 'section') {
                                        $id = (string)$section['id'];
                                        echo '<div id="note-' . safe($id) . '" class="fb2-note">';
                                        foreach ($section->children($fb_ns) as $c) {
                                            echo renderNode($c, $fb_ns);
                                        }
                                        echo '</div>';
                                    }
                                }
                                echo '</div>';
                            }
                            }
                        } catch (Exception $e) {
                            echo '<div class="error">Ошибка парсинга: ' . safe($e->getMessage()) . '</div>';
                        }
                    }
                ?>

            <?php elseif ($page === 'access_denied'): ?>
                <h1>Доступ запрещен</h1>
                <p>Эта страница доступна только для администраторов.</p>

            <?php else: ?>
                <h1>Страница не найдена</h1>
            <?php endif; ?>
        </main>
        <!-- Sidebar с виджетами -->
        <aside class="sidebar">
            <div class="widget">
                <h3>Поиск по сайту</h3>
                <form method="get" action="?page=events">
                    <input name="q" placeholder="Поиск..." value="<?php echo safe($q ?? ''); ?>">
                    <button class="submit-btn">Искать</button>
                </form>
            </div>
            <div class="widget">
                <h3>Последние события</h3>
                <ul>
                    <?php foreach (array_slice($events, 0, 3) as $event): ?>
                        <li><?php echo safe($event['title']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="widget">
                <h3>Социальные сети</h3>
                <p class="dev-notice">В разработке</p>
                <a href="https://vk.com" class="social-link">VK</a>
                <a href="https://telegram.org" class="social-link">Telegram</a>
                <a href="https://instagram.com" class="social-link">Instagram</a>
            </div>
        </aside>
    </div>

    <!-- Lightbox -->
    <div id="lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);align-items:center;justify-content:center;z-index:9999">
        <img id="lightbox-img" src="" style="max-width:95%;max-height:95%;border-radius:8px;">
    </div>
    <script>
    // JS для мобильного меню
    function toggleMenu() {
        var menu = document.getElementById("navbarMenu");
        menu.classList.toggle("active");
    }

    document.addEventListener('click', e => {
        const t = e.target;
        if (t.matches('.card img')) {
            const lb = document.getElementById('lightbox');
            document.getElementById('lightbox-img').src = t.src;
            lb.style.display = 'flex';
        } else if (e.target.id === 'lightbox' || e.target.id === 'lightbox-img') {
            document.getElementById('lightbox').style.display = 'none';
        }
    });
    </script>
</body>
</html>