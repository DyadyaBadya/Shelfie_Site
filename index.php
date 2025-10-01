<?php
//require 'db.php'; // подключаем базу

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Конфигурация
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('BOOKS_DIR', __DIR__ . '/books');
define('DATA_DIR', __DIR__ . '/data');
define('ADMIN_PASS', '12345'); // пароль администратора (оставлен для совместимости)

function safe($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Создаём папки, если их нет
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(BOOKS_DIR)) mkdir(BOOKS_DIR, 0755, true);
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

// Файл с событиями
$events_file = DATA_DIR . '/events.json';
if (!file_exists($events_file)) {
    $sample = [
        ['id' => 1, 'title' => 'День открытых дверей', 'date' => date('Y-m-d', strtotime('+14 days')), 'desc' => '...'],
        ['id' => 2, 'title' => 'Выставка студенческих работ', 'date' => date('Y-m-d', strtotime('+30 days')), 'desc' => '...']
    ];
    file_put_contents($events_file, json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// === Обработка формы контакта ===
$error = $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $message === '') {
        $error = 'Имя и сообщение обязательны.';
    } else {
        $msgFile = DATA_DIR . '/messages.json';
        if (!file_exists($msgFile)) {
            file_put_contents($msgFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $all = json_decode(file_get_contents($msgFile), true) ?: [];
        $all[] = [
            'time' => date('c'),
            'name' => $name,
            'email' => $email,
            'message' => $message
        ];
        file_put_contents($msgFile, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $ok = 'Сообщение сохранено.';
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

// === Обработка регистрации ===
$reg_error = $reg_ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $login = trim($_POST['login'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $pass_confirm = trim($_POST['password_confirm'] ?? '');

    if ($login === '' || $pass === '') {
        $reg_error = 'Логин и пароль обязательны.';
    } elseif (strlen($login) < 3 || strlen($login) > 50 || !preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        $reg_error = 'Логин должен быть от 3 до 50 символов и содержать только буквы, цифры или подчеркивания.';
    } elseif (strlen($pass) < 6) {
        $reg_error = 'Пароль должен содержать минимум 6 символов.';
    } elseif ($pass !== $pass_confirm) {
        $reg_error = 'Пароли не совпадают.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
            $stmt->execute([$login]);
            if ($stmt->fetch()) {
                $reg_error = 'Такой логин уже существует.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (login, password) VALUES (?, ?)");
                $stmt->execute([$login, $hash]);
                $reg_ok = 'Регистрация прошла успешно!';
                header('Location: ?page=login');
                exit;
            }
        } catch (PDOException $e) {
            $reg_error = 'Ошибка базы данных: ' . safe($e->getMessage());
        }
    }
}

// === Обработка входа ===
$login_error = $login_ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $login = trim($_POST['login'] ?? '');
    $pass = trim($_POST['password'] ?? '');

    if ($login === '' || $pass === '') {
        $login_error = 'Логин и пароль обязательны.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE login = ?");
            $stmt->execute([$login]);
            $user = $stmt->fetch();
            if ($user && password_verify($pass, $user['password'])) {
                $login_ok = 'Вход выполнен успешно!';
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
            <a href="?page=contact"><?php echo file_exists("photo/contact.svg") ? '<img src="photo/'.rawurlencode('contact.svg').'" alt="Контакты" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Контакты</a>
            <a href="?page=admin"><?php echo file_exists("photo/admin.svg") ? '<img src="photo/'.rawurlencode('admin.svg').'" alt="Админка" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Админка</a>
            <a href="?page=register"><?php echo file_exists("photo/register_icon.svg") ? '<img src="photo/'.rawurlencode('register_icon.svg').'" alt="Регистрация" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Регистрация</a>
            <a href="?page=login"><?php echo file_exists("photo/login.svg") ? '<img src="photo/'.rawurlencode('login.svg').'" alt="Вход" loading="lazy" style="width: 20px; height: 20px; vertical-align: middle;">' : '[Иконка] '; ?> Вход</a>
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
                    <?php if (file_exists("photo/shelfie.png")): ?>
                        <img src="photo/<?php echo rawurlencode('shelfie.png'); ?>" alt="Книжная полка" class="shelfie-img" loading="lazy">
                    <?php else: ?>
                        <p>[Изображение полки не найдено]</p>
                    <?php endif; ?>
                    <a href="?page=reader" class="shelfie-btn">Перейти к книгам</a>
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
                <h1>События колледжа</h1>
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
                <h1>Контакты</h1>
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
                    <button type="submit">Отправить</button>
                </form>

            <?php elseif ($page === 'admin'): ?>
                <h1>Сообщения</h1>
                <?php
                $msgFile = DATA_DIR . '/messages.json';
                if (file_exists($msgFile)) {
                    $msgs = json_decode(file_get_contents($msgFile), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        echo "Ошибка чтения сообщений.";
                    } elseif (empty($msgs)) {
                        echo "Сообщений нет.";
                    } else {
                        foreach ($msgs as $m) {
                            echo "<div><strong>" . safe($m['name']) . "</strong> (" . safe($m['email']) . ")<br>";
                            echo safe($m['message']) . "<br><small>" . safe($m['time']) . "</small></div><hr>";
                        }
                    }
                } else {
                    echo "Сообщений нет.";
                }
                ?>

            <?php elseif ($page === 'reader'): ?>
                <h1>Читалка</h1>
                <h3>Загрузка FB2</h3>
                <?php if ($fb2_error): ?>
                    <div class="error"><?php echo safe($fb2_error); ?></div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_fb2">
                    <input type="file" name="fb2" accept=".fb2" required>
                    <button type="submit">Загрузить</button>
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
                if (isset($_GET['file']) && in_array($_GET['file'], $books)) {
                    $file = BOOKS_DIR . '/' . $_GET['file'];
                    try {
                        $xml = simplexml_load_file($file);
                        if ($xml === false) {
                            echo '<div class="error">Ошибка чтения файла FB2.</div>';
                        } else {
                            echo '<div class="reader">';
                            foreach ($xml->body as $body) {
                                foreach ($body->section as $section) {
                                    if (isset($section->title)) {
                                        echo '<h3 class="reader-title">' . safe((string)$section->title->p) . '</h3>';
                                    }
                                    echo '<div class="reader-section">';
                                    foreach ($section->p as $paragraph) {
                                        echo '<p>' . safe((string)$paragraph) . '</p>';
                                    }
                                    echo '</div>';
                                }
                            }
                            echo '</div>';
                        }
                    } catch (Exception $e) {
                        echo '<div class="error">Ошибка парсинга: ' . safe($e->getMessage()) . '</div>';
                    }
                }
                ?>

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
                    <button>Искать</button>
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