<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => $isHttps,
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$pdo = db();
$message = (string) ($_GET['message'] ?? '');
$error = '';

function is_staff(): bool {
  return !empty($_SESSION['staff_id']) && in_array($_SESSION['admin_role'] ?? '', ['admin', 'controller'], true);
}

function is_admin_role(): bool {
  return ($_SESSION['admin_role'] ?? '') === 'admin';
}

function require_staff(): void {
  if (!is_staff()) {
    header('Location: /admin/?login=1');
    exit;
  }
}

function require_admin_role(): void {
  require_staff();
  if (!is_admin_role()) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function go_admin(string $message = ''): void {
  header('Location: /admin/' . ($message !== '' ? '?message=' . urlencode($message) : ''));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['action'] ?? '');
  try {
    if ($action === 'login') {
      $username = trim((string) ($_POST['username'] ?? ''));
      $password = (string) ($_POST['password'] ?? '');
      $stmt = $pdo->prepare('SELECT * FROM staff_users WHERE username = ? AND is_active = 1');
      $stmt->execute([$username]);
      $user = $stmt->fetch();
      if ($user && password_verify($password, (string) $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['staff_id'] = (int) $user['id'];
        $_SESSION['admin_role'] = (string) $user['role'];
        $_SESSION['admin_username'] = (string) $user['username'];
        csrf_token();
        go_admin('Вход выполнен.');
      }
      $error = 'Неверный логин или пароль.';
    } elseif ($action === 'logout') {
      check_csrf();
      session_destroy();
      go_admin();
    } else {
      require_staff();
      check_csrf();

      if ($action === 'ticket_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['confirmed', 'checked_in', 'cancelled'], true) ? (string) $_POST['status'] : 'confirmed';
        $checked = $status === 'checked_in' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE tickets SET status = ?, checked_in_at = ? WHERE id = ?')->execute([$status, $checked, $id]);
        go_admin('Билет обновлен.');
      }

      require_admin_role();

      if ($action === 'save_event') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? '')) ?: slugify($title);
        $description = trim((string) ($_POST['description'] ?? ''));
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        $startsTime = trim((string) ($_POST['starts_time'] ?? ''));
        $venue = trim((string) ($_POST['venue'] ?? ''));
        $organizer = trim((string) ($_POST['organizer'] ?? ''));
        $capacity = max(1, min(5000, (int) ($_POST['capacity'] ?? 100)));
        $days = max(1, min(30, (int) ($_POST['days_count'] ?? 1)));
        $isPaid = !empty($_POST['is_paid']) ? 1 : 0;
        $price = max(0, (int) round(((float) str_replace(',', '.', (string) ($_POST['price'] ?? '0'))) * 100));
        $status = in_array($_POST['status'] ?? 'published', ['draft', 'published', 'archived'], true) ? (string) $_POST['status'] : 'published';
        $oldEvent = null;
        if ($id > 0) {
          $oldStmt = $pdo->prepare('SELECT image, gallery FROM events WHERE id = ?');
          $oldStmt->execute([$id]);
          $oldEvent = $oldStmt->fetch() ?: null;
        }
        $image = upload_image('image_upload', trim((string) ($_POST['image'] ?? ($oldEvent['image'] ?? ''))));
        $gallery = gallery_items((string) ($_POST['gallery'] ?? ($oldEvent['gallery'] ?? '[]')));
        $gallery = upload_gallery('gallery_uploads', $gallery);
        if (!empty($_POST['delete_image'])) {
          $image = '';
        }
        $gallery = array_values(array_filter($gallery, static fn($item) => !in_array($item, $_POST['delete_gallery'] ?? [], true)));
        $galleryJson = json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($title === '' || $startsAt === '' || $startsTime === '' || $venue === '' || $organizer === '') {
          throw new RuntimeException('Заполните название, дату, время, место и организатора.');
        }

        if ($id > 0) {
          $stmt = $pdo->prepare('UPDATE events SET slug=?, title=?, description=?, starts_at=?, starts_time=?, venue=?, organizer=?, capacity=?, is_paid=?, price=?, status=?, image=?, gallery=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
          $stmt->execute([$slug, $title, $description, $startsAt, $startsTime, $venue, $organizer, $capacity, $isPaid, $price, $status, $image, $galleryJson, $id]);
        } else {
          $stmt = $pdo->prepare('INSERT INTO events (slug,title,description,starts_at,starts_time,venue,organizer,capacity,is_paid,price,status,image,gallery) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
          $stmt->execute([$slug, $title, $description, $startsAt, $startsTime, $venue, $organizer, $capacity, $isPaid, $price, $status, $image, $galleryJson]);
          $id = (int) $pdo->lastInsertId();
        }
        sync_event_days($pdo, $id, $startsAt, $days);
        go_admin('Мероприятие сохранено.');
      }

      if ($action === 'delete_event') {
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        go_admin('Мероприятие удалено.');
      }

      if ($action === 'save_user') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? 'controller', ['admin', 'controller'], true) ? (string) $_POST['role'] : 'controller';
        $active = !empty($_POST['is_active']) ? 1 : 0;
        if ($username === '') {
          throw new RuntimeException('Укажите логин сотрудника.');
        }
        if ($id > 0) {
          if ($password !== '') {
            $stmt = $pdo->prepare('UPDATE staff_users SET username=?, password_hash=?, role=?, is_active=? WHERE id=?');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $active, $id]);
          } else {
            $stmt = $pdo->prepare('UPDATE staff_users SET username=?, role=?, is_active=? WHERE id=?');
            $stmt->execute([$username, $role, $active, $id]);
          }
        } else {
          if ($password === '') {
            throw new RuntimeException('Для нового сотрудника нужен пароль.');
          }
          $stmt = $pdo->prepare('INSERT INTO staff_users (username,password_hash,role,is_active) VALUES (?,?,?,?)');
          $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
        }
        go_admin('Сотрудник сохранен.');
      }
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editEvent = null;
$editUserId = (int) ($_GET['edit_user'] ?? 0);
$editUser = null;
$events = $tickets = $users = [];
if (is_staff()) {
  $tickets = $pdo->query('SELECT t.*, e.title FROM tickets t JOIN events e ON e.id = t.event_id ORDER BY t.created_at DESC LIMIT 120')->fetchAll();
}
if (is_admin_role()) {
  $events = $pdo->query('SELECT * FROM events ORDER BY starts_at DESC, id DESC')->fetchAll();
  $users = $pdo->query('SELECT id, username, role, is_active, created_at FROM staff_users ORDER BY id')->fetchAll();
  if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$editId]);
    $editEvent = $stmt->fetch() ?: null;
  }
  if ($editUserId > 0) {
    $stmt = $pdo->prepare('SELECT id, username, role, is_active FROM staff_users WHERE id = ?');
    $stmt->execute([$editUserId]);
    $editUser = $stmt->fetch() ?: null;
  }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Админка - события</title>
  <link rel="icon" href="/assets/svg/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="/" aria-label="На сайт">
    <img class="brand__mark" src="/assets/svg/logo.svg" alt="">
    <span class="brand__text">egor_zvada</span>
    <span class="brand__module">admin</span>
  </a>
  <nav class="site-nav">
    <a href="/">Сайт</a>
    <?php if (is_staff()): ?>
      <span><?= h($_SESSION['admin_username'] ?? '') ?> / <?= h($_SESSION['admin_role'] ?? '') ?></span>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="mini-button" type="submit">Выйти</button>
      </form>
    <?php endif; ?>
  </nav>
</header>

<main class="admin-layout">
  <?php if (!is_staff()): ?>
    <section class="empty-state">
      <p class="kicker">secure session</p>
      <h1>Админка</h1>
      <?php if ($error !== ''): ?><div class="notice notice--error"><?= h($error) ?></div><?php endif; ?>
      <form class="booking-panel" method="post">
        <input type="hidden" name="action" value="login">
        <div class="form-grid">
          <label>Логин <input name="username" autocomplete="username" required></label>
          <label>Пароль <input name="password" type="password" autocomplete="current-password" required></label>
        </div>
        <button class="button button--wide" type="submit">Войти</button>
      </form>
    </section>
  <?php else: ?>
    <div class="admin-top">
      <div>
        <p class="kicker">control room</p>
        <h1><?= is_admin_role() ? 'Управление' : 'Контроль' ?></h1>
      </div>
      <div class="admin-tabs">
        <?php if (is_admin_role()): ?><a href="#events" class="is-active">Мероприятия</a><a href="#users">Сотрудники</a><?php endif; ?>
        <a href="#tickets">Билеты</a>
      </div>
    </div>
    <?php if ($message !== ''): ?><div class="notice"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice--error"><?= h($error) ?></div><?php endif; ?>

    <?php if (is_admin_role()): ?>
      <div class="admin-grid" id="events">
        <section class="admin-panel">
          <h2>Мероприятия</h2>
          <div class="admin-list">
            <?php foreach ($events as $event): ?>
              <?php $days = event_days($pdo, (int) $event['id']); ?>
              <div class="admin-row">
                <div>
                  <strong><?= h($event['title']) ?></strong>
                  <span><?= h(format_date_ru($event['starts_at'])) ?> · <?= h($event['starts_time']) ?> · <?= h($event['venue']) ?> · <?= count($days) ?> дн. · <?= h($event['status']) ?></span>
                </div>
                <div class="admin-actions">
                  <a class="mini-button" href="/admin/?edit=<?= h($event['id']) ?>">Править</a>
                  <form method="post" onsubmit="return confirm('Удалить мероприятие и все билеты?')">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="id" value="<?= h($event['id']) ?>">
                    <button class="danger-button" type="submit">Удалить</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="admin-panel">
          <h2><?= $editEvent ? 'Редактировать событие' : 'Создать событие' ?></h2>
          <?php $editDays = $editEvent ? count(event_days($pdo, (int) $editEvent['id'])) : 1; ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_event">
            <input type="hidden" name="id" value="<?= h($editEvent['id'] ?? 0) ?>">
            <div class="form-grid">
              <label>Название <input name="title" value="<?= h($editEvent['title'] ?? '') ?>" required></label>
              <label>Slug <input name="slug" value="<?= h($editEvent['slug'] ?? '') ?>"></label>
              <label>Описание <textarea name="description"><?= h($editEvent['description'] ?? '') ?></textarea></label>
              <label>Обложка, путь <input name="image" value="<?= h($editEvent['image'] ?? '') ?>" placeholder="/assets/img/uploads/event.webp"></label>
              <?php if (!empty($editEvent['image'])): ?>
                <label class="check"><input name="delete_image" type="checkbox" value="1"> Убрать обложку</label>
              <?php endif; ?>
              <label>Загрузить обложку <input name="image_upload" type="file" accept="image/*,.svg"></label>
              <label>Галерея, пути <textarea name="gallery"><?= h(implode("\n", gallery_items($editEvent['gallery'] ?? '[]'))) ?></textarea></label>
              <?php if (!empty($editEvent['gallery'])): ?>
                <div class="file-list">
                  <?php foreach (gallery_items($editEvent['gallery']) as $image): ?>
                    <label class="file-list__item">
                      <img src="<?= h($image) ?>" alt="">
                      <span><?= h($image) ?></span>
                      <span class="check"><input name="delete_gallery[]" type="checkbox" value="<?= h($image) ?>"> Убрать</span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <label>Добавить изображения в галерею <input name="gallery_uploads[]" type="file" accept="image/*,.svg" multiple></label>
              <label>Дата старта <input name="starts_at" type="date" value="<?= h($editEvent['starts_at'] ?? date('Y-m-d')) ?>" required></label>
              <label>Время <input name="starts_time" type="time" value="<?= h($editEvent['starts_time'] ?? '18:00') ?>" required></label>
              <label>Сколько дней <input name="days_count" type="number" min="1" max="30" value="<?= h($editDays) ?>" required></label>
              <label>Место проведения <input name="venue" value="<?= h($editEvent['venue'] ?? '') ?>" required></label>
              <label>Организатор <input name="organizer" value="<?= h($editEvent['organizer'] ?? 'egor_zvada') ?>" required></label>
              <label>Количество мест <input name="capacity" type="number" min="1" max="5000" value="<?= h($editEvent['capacity'] ?? 100) ?>" required></label>
              <label class="check"><input name="is_paid" type="checkbox" value="1" <?= !empty($editEvent['is_paid']) ? 'checked' : '' ?>> Платное мероприятие</label>
              <label>Цена за день, ₽ <input name="price" type="number" min="0" step="1" value="<?= h($editEvent ? ((int) $editEvent['price'] / 100) : 0) ?>"></label>
              <label>Статус
                <select name="status">
                  <?php foreach (['draft' => 'Черновик', 'published' => 'Опубликовано', 'archived' => 'Архив'] as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= (($editEvent['status'] ?? 'published') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <button class="button button--wide" type="submit">Сохранить мероприятие</button>
          </form>
        </section>
      </div>

      <div class="admin-grid" id="users">
        <section class="admin-panel">
          <h2>Сотрудники</h2>
          <div class="admin-list">
            <?php foreach ($users as $user): ?>
              <div class="admin-row">
                <div>
                  <strong><?= h($user['username']) ?></strong>
                  <span><?= h($user['role']) ?> · <?= ((int) $user['is_active'] === 1) ? 'активен' : 'выключен' ?></span>
                </div>
                <a class="mini-button" href="/admin/?edit_user=<?= h($user['id']) ?>#users">Править</a>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
        <section class="admin-panel">
          <h2><?= $editUser ? 'Редактировать сотрудника' : 'Создать сотрудника' ?></h2>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="id" value="<?= h($editUser['id'] ?? 0) ?>">
            <div class="form-grid">
              <label>Логин <input name="username" value="<?= h($editUser['username'] ?? '') ?>" required></label>
              <label>Пароль <input name="password" type="password" <?= $editUser ? '' : 'required' ?>></label>
              <label>Роль
                <select name="role">
                  <option value="controller" <?= (($editUser['role'] ?? '') === 'controller') ? 'selected' : '' ?>>Контролер</option>
                  <option value="admin" <?= (($editUser['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Администратор</option>
                </select>
              </label>
              <label class="check"><input name="is_active" type="checkbox" value="1" <?= (($editUser['is_active'] ?? 1) ? 'checked' : '') ?>> Активен</label>
            </div>
            <button class="button button--wide" type="submit">Сохранить сотрудника</button>
          </form>
        </section>
      </div>
    <?php endif; ?>

    <section class="admin-panel" id="tickets">
      <h2>Проверка билетов</h2>
      <form class="form-grid" method="get" action="/">
        <label>Код билета <input name="ticket" placeholder="ABC123" autocomplete="off"></label>
        <button class="button" type="submit">Открыть билет</button>
      </form>
      <div class="admin-list" style="margin-top:18px">
        <?php foreach ($tickets as $ticket): ?>
          <div class="admin-row">
            <div>
              <strong><?= h($ticket['code']) ?> · <?= h($ticket['buyer_name']) ?></strong>
              <span><?= h($ticket['title']) ?> · место <?= h($ticket['seat_number']) ?> · <?= h($ticket['buyer_email']) ?> · письмо <?= ((int) $ticket['email_sent'] === 1) ? 'ушло' : 'не ушло' ?> · <?= h($ticket['status']) ?></span>
            </div>
            <div class="admin-actions">
              <a class="mini-button" href="/?ticket=<?= urlencode((string) $ticket['code']) ?>">QR</a>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="ticket_status">
                <input type="hidden" name="id" value="<?= h($ticket['id']) ?>">
                <select name="status">
                  <?php foreach (['confirmed' => 'Активен', 'checked_in' => 'Погашен', 'cancelled' => 'Отменен'] as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $ticket['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="mini-button" type="submit">OK</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
