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
$tab = (string) ($_GET['tab'] ?? 'events');
$allowedTabs = ['events', 'tickets', 'staff', 'settings'];
if (!in_array($tab, $allowedTabs, true)) {
  $tab = 'events';
}

function role_label(string $role): string {
  return [
    'owner' => 'Главный админ',
    'admin' => 'Админ мероприятий',
    'controller' => 'Контролер',
  ][$role] ?? $role;
}

function can_manage_events(): bool {
  return in_array($_SESSION['admin_role'] ?? '', ['owner', 'admin'], true);
}

function can_manage_staff(): bool {
  return ($_SESSION['admin_role'] ?? '') === 'owner';
}

function can_check_tickets(): bool {
  return in_array($_SESSION['admin_role'] ?? '', ['owner', 'controller'], true);
}

function is_staff(): bool {
  return !empty($_SESSION['staff_id']) && in_array($_SESSION['admin_role'] ?? '', ['owner', 'admin', 'controller'], true);
}

function require_staff(): void {
  if (!is_staff()) {
    header('Location: /admin/?login=1');
    exit;
  }
}

function require_events_role(): void {
  require_staff();
  if (!can_manage_events()) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function require_owner(): void {
  require_staff();
  if (!can_manage_staff()) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function go_admin(string $message = '', string $tab = ''): void {
  $params = [];
  if ($tab !== '') {
    $params['tab'] = $tab;
  }
  if ($message !== '') {
    $params['message'] = $message;
  }
  header('Location: /admin/' . ($params ? '?' . http_build_query($params) : ''));
  exit;
}

function save_smtp_settings(PDO $pdo): void {
  $fields = [
    'smtp_host',
    'smtp_port',
    'smtp_username',
    'smtp_encryption',
    'smtp_from_email',
    'smtp_from_name',
  ];
  foreach ($fields as $field) {
    meta_set($pdo, $field, trim((string) ($_POST[$field] ?? '')));
  }
  $password = (string) ($_POST['smtp_password'] ?? '');
  if ($password !== '') {
    meta_set($pdo, 'smtp_password', $password);
  }
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
        go_admin('Вход выполнен.', can_manage_events() ? 'events' : 'tickets');
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
        if (!can_check_tickets()) {
          http_response_code(403);
          exit('Forbidden');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['confirmed', 'checked_in', 'cancelled'], true) ? (string) $_POST['status'] : 'confirmed';
        $checked = $status === 'checked_in' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE tickets SET status = ?, checked_in_at = ? WHERE id = ?')->execute([$status, $checked, $id]);
        go_admin('Билет обновлен.', 'tickets');
      }

      if ($action === 'save_event') {
        require_events_role();
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
        $image = upload_image('image_upload', (string) ($oldEvent['image'] ?? ''));
        $gallery = gallery_items($oldEvent['gallery'] ?? '[]');
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
        go_admin('Мероприятие сохранено.', 'events');
      }

      if ($action === 'delete_event') {
        require_events_role();
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        go_admin('Мероприятие удалено.', 'events');
      }

      if ($action === 'save_user') {
        require_owner();
        $id = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = in_array($_POST['role'] ?? 'controller', ['owner', 'admin', 'controller'], true) ? (string) $_POST['role'] : 'controller';
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
        go_admin('Сотрудник сохранен.', 'staff');
      }

      if ($action === 'save_profile') {
        $password = (string) ($_POST['new_password'] ?? '');
        if ($password === '') {
          throw new RuntimeException('Введите новый пароль.');
        }
        $pdo->prepare('UPDATE staff_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $_SESSION['staff_id']]);
        go_admin('Пароль обновлен.', 'settings');
      }

      if ($action === 'save_smtp') {
        require_owner();
        save_smtp_settings($pdo);
        go_admin('Настройки почты сохранены.', 'settings');
      }
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

if (is_staff() && !can_manage_events() && $tab === 'events') {
  $tab = 'tickets';
}
if (is_staff() && !can_check_tickets() && $tab === 'tickets') {
  $tab = can_manage_events() ? 'events' : 'settings';
}
if (is_staff() && !can_manage_staff() && $tab === 'staff') {
  $tab = 'settings';
}

$editId = (int) ($_GET['edit'] ?? 0);
$editEvent = null;
$editUserId = (int) ($_GET['edit_user'] ?? 0);
$editUser = null;
$events = $tickets = $users = [];
if (is_staff()) {
  $tickets = $pdo->query('SELECT t.*, e.title FROM tickets t JOIN events e ON e.id = t.event_id ORDER BY t.created_at DESC LIMIT 120')->fetchAll();
}
if (can_manage_events()) {
  $events = $pdo->query('SELECT * FROM events ORDER BY starts_at DESC, id DESC')->fetchAll();
  if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$editId]);
    $editEvent = $stmt->fetch() ?: null;
    $tab = 'events';
  }
}
if (can_manage_staff()) {
  $users = $pdo->query('SELECT id, username, role, is_active, created_at FROM staff_users ORDER BY id')->fetchAll();
  if ($editUserId > 0) {
    $stmt = $pdo->prepare('SELECT id, username, role, is_active FROM staff_users WHERE id = ?');
    $stmt->execute([$editUserId]);
    $editUser = $stmt->fetch() ?: null;
    $tab = 'staff';
  }
}
$mail = mail_config($pdo);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <meta name="color-scheme" content="light">
  <title>Админка - события</title>
  <link rel="icon" href="/assets/svg/school-logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="site-header admin-header">
  <a class="brand" href="/" aria-label="На сайт">
    <img class="brand__mark brand__mark--school" src="/assets/svg/school-logo.svg" alt="">
    <span class="brand__copy">
      <span class="brand__text">СШ ВВЕ</span>
      <span class="brand__module">Админка</span>
    </span>
  </a>
  <nav class="site-nav">
    <a href="/">Сайт</a>
    <?php if (is_staff()): ?>
      <span class="admin-header__meta"><?= h($_SESSION['admin_username'] ?? '') ?> / <?= h(role_label((string) ($_SESSION['admin_role'] ?? ''))) ?></span>
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
    <section class="empty-state admin-login">
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
    <div class="admin-shell">
      <aside class="admin-sidebar">
        <p class="kicker">control room</p>
        <h1>Панель</h1>
        <nav class="admin-menu" aria-label="Разделы админки">
          <?php if (can_manage_events()): ?><a class="<?= $tab === 'events' ? 'is-active' : '' ?>" href="/admin/?tab=events">Мероприятия</a><?php endif; ?>
          <?php if (can_check_tickets()): ?><a class="<?= $tab === 'tickets' ? 'is-active' : '' ?>" href="/admin/?tab=tickets">Проверка билетов</a><?php endif; ?>
          <?php if (can_manage_staff()): ?><a class="<?= $tab === 'staff' ? 'is-active' : '' ?>" href="/admin/?tab=staff">Сотрудники</a><?php endif; ?>
          <a class="<?= $tab === 'settings' ? 'is-active' : '' ?>" href="/admin/?tab=settings">Настройки</a>
        </nav>
      </aside>

      <section class="admin-content">
        <?php if ($message !== ''): ?><div class="notice"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice notice--error"><?= h($error) ?></div><?php endif; ?>

        <?php if ($tab === 'events' && can_manage_events()): ?>
          <div class="admin-section-head">
            <div>
              <p class="kicker">events</p>
              <h2>Мероприятия</h2>
            </div>
            <a class="button" href="/admin/?tab=events#event-form">Создать мероприятие</a>
          </div>
          <div class="admin-grid">
            <section class="admin-panel">
              <div class="admin-list">
                <?php if (!$events): ?><div class="admin-empty">Событий пока нет. Создайте первое мероприятие справа.</div><?php endif; ?>
                <?php foreach ($events as $event): ?>
                  <?php $days = event_days($pdo, (int) $event['id']); ?>
                  <div class="admin-row">
                    <div>
                      <strong><?= h($event['title']) ?></strong>
                      <span><?= h(format_date_ru($event['starts_at'])) ?> · <?= h($event['starts_time']) ?> · <?= h($event['venue']) ?> · <?= count($days) ?> дн. · <?= h($event['status']) ?></span>
                    </div>
                    <div class="admin-actions">
                      <a class="mini-button" href="/admin/?tab=events&edit=<?= h($event['id']) ?>">Править</a>
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

            <section class="admin-panel" id="event-form">
              <h3><?= $editEvent ? 'Редактировать мероприятие' : 'Создать мероприятие' ?></h3>
              <?php $editDays = $editEvent ? count(event_days($pdo, (int) $editEvent['id'])) : 1; ?>
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_event">
                <input type="hidden" name="id" value="<?= h($editEvent['id'] ?? 0) ?>">
                <div class="form-grid">
                  <label>Название <input name="title" value="<?= h($editEvent['title'] ?? '') ?>" required></label>
                  <label>Slug <input name="slug" value="<?= h($editEvent['slug'] ?? '') ?>" placeholder="создастся автоматически"></label>
                  <label>Описание <textarea name="description"><?= h($editEvent['description'] ?? '') ?></textarea></label>
                  <?php if (!empty($editEvent['image'])): ?>
                    <div class="image-preview"><img src="<?= h($editEvent['image']) ?>" alt=""><label class="check"><input name="delete_image" type="checkbox" value="1"> Убрать обложку</label></div>
                  <?php endif; ?>
                  <label>Обложка <input name="image_upload" type="file" accept="image/*,.svg"></label>
                  <?php $gallery = gallery_items($editEvent['gallery'] ?? '[]'); ?>
                  <?php if ($gallery): ?>
                    <div class="gallery-manager">
                      <?php foreach ($gallery as $image): ?>
                        <label class="gallery-thumb">
                          <img src="<?= h($image) ?>" alt="">
                          <span><input name="delete_gallery[]" type="checkbox" value="<?= h($image) ?>"> убрать</span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <label>Галерея <input name="gallery_uploads[]" type="file" accept="image/*,.svg" multiple></label>
                  <div class="form-pair">
                    <label>Дата старта <input name="starts_at" type="date" value="<?= h($editEvent['starts_at'] ?? date('Y-m-d')) ?>" required></label>
                    <label>Время <input name="starts_time" type="time" value="<?= h($editEvent['starts_time'] ?? '18:00') ?>" required></label>
                  </div>
                  <div class="form-pair">
                    <label>Сколько дней <input name="days_count" type="number" min="1" max="30" value="<?= h($editDays) ?>" required></label>
                    <label>Количество мест <input name="capacity" type="number" min="1" max="5000" value="<?= h($editEvent['capacity'] ?? 100) ?>" required></label>
                  </div>
                  <label>Место проведения <input name="venue" value="<?= h($editEvent['venue'] ?? '') ?>" required></label>
                  <label>Организатор <input name="organizer" value="<?= h($editEvent['organizer'] ?? 'СШ ВВЕ') ?>" required></label>
                  <div class="form-pair">
                    <label class="check"><input name="is_paid" type="checkbox" value="1" <?= !empty($editEvent['is_paid']) ? 'checked' : '' ?>> Платное</label>
                    <label>Цена за день, ₽ <input name="price" type="number" min="0" step="1" value="<?= h($editEvent ? ((int) $editEvent['price'] / 100) : 0) ?>"></label>
                  </div>
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
        <?php endif; ?>

        <?php if ($tab === 'tickets'): ?>
          <div class="admin-section-head">
            <div>
              <p class="kicker">access control</p>
              <h2>Проверка билетов</h2>
            </div>
          </div>
          <section class="admin-panel">
            <form class="ticket-check-form" method="get" action="/">
              <label>Код билета <input name="ticket" placeholder="ABC123" autocomplete="off"></label>
              <button class="button" type="submit">Открыть билет</button>
            </form>
          </section>
          <section class="admin-panel">
            <div class="admin-list">
              <?php if (!$tickets): ?><div class="admin-empty">Оформленных билетов пока нет.</div><?php endif; ?>
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

        <?php if ($tab === 'staff' && can_manage_staff()): ?>
          <div class="admin-section-head">
            <div>
              <p class="kicker">team</p>
              <h2>Сотрудники</h2>
            </div>
          </div>
          <div class="admin-grid">
            <section class="admin-panel">
              <div class="admin-list">
                <?php foreach ($users as $user): ?>
                  <div class="admin-row">
                    <div>
                      <strong><?= h($user['username']) ?></strong>
                      <span><?= h(role_label((string) $user['role'])) ?> · <?= ((int) $user['is_active'] === 1) ? 'активен' : 'выключен' ?></span>
                    </div>
                    <a class="mini-button" href="/admin/?tab=staff&edit_user=<?= h($user['id']) ?>">Править</a>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
            <section class="admin-panel">
              <h3><?= $editUser ? 'Редактировать сотрудника' : 'Создать сотрудника' ?></h3>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="id" value="<?= h($editUser['id'] ?? 0) ?>">
                <div class="form-grid">
                  <label>Логин <input name="username" value="<?= h($editUser['username'] ?? '') ?>" required></label>
                  <label>Пароль <input name="password" type="password" <?= $editUser ? '' : 'required' ?>></label>
                  <label>Роль
                    <select name="role">
                      <?php foreach (['controller', 'admin', 'owner'] as $role): ?>
                        <option value="<?= h($role) ?>" <?= (($editUser['role'] ?? '') === $role) ? 'selected' : '' ?>><?= h(role_label($role)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label class="check"><input name="is_active" type="checkbox" value="1" <?= (($editUser['is_active'] ?? 1) ? 'checked' : '') ?>> Активен</label>
                </div>
                <button class="button button--wide" type="submit">Сохранить сотрудника</button>
              </form>
            </section>
          </div>
        <?php endif; ?>

        <?php if ($tab === 'settings'): ?>
          <div class="admin-section-head">
            <div>
              <p class="kicker">settings</p>
              <h2>Настройки</h2>
            </div>
          </div>
          <div class="admin-grid">
            <section class="admin-panel">
              <h3>Мой пароль</h3>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_profile">
                <label>Новый пароль <input name="new_password" type="password" required></label>
                <button class="button button--wide" type="submit">Обновить пароль</button>
              </form>
            </section>

            <?php if (can_manage_staff()): ?>
              <section class="admin-panel">
                <h3>Почта SMTP</h3>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="save_smtp">
                  <div class="form-grid">
                    <label>SMTP сервер <input name="smtp_host" value="<?= h($mail['host'] ?? '') ?>" placeholder="smtp.example.ru"></label>
                    <div class="form-pair">
                      <label>Порт <input name="smtp_port" type="number" value="<?= h($mail['port'] ?? 587) ?>"></label>
                      <label>Шифрование
                        <select name="smtp_encryption">
                          <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'Без шифрования'] as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= (($mail['encryption'] ?? 'tls') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </div>
                    <label>Логин <input name="smtp_username" value="<?= h($mail['username'] ?? '') ?>"></label>
                    <label>Новый пароль SMTP <input name="smtp_password" type="password" placeholder="<?= !empty($mail['password']) ? 'пароль сохранен' : '' ?>"></label>
                    <label>От кого, email <input name="smtp_from_email" value="<?= h($mail['from_email'] ?? '') ?>" placeholder="tickets@example.ru"></label>
                    <label>От кого, имя <input name="smtp_from_name" value="<?= h($mail['from_name'] ?? APP_NAME) ?>"></label>
                  </div>
                  <button class="button button--wide" type="submit">Сохранить почту</button>
                </form>
              </section>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</main>
<script src="/assets/js/app.js"></script>
</body>
</html>
