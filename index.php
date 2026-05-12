<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$pdo = db();
$error = '';
$successTicket = null;
$emailSent = null;
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$viewerRole = (string) ($_SESSION['admin_role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
  check_csrf();
  $slug = trim((string) ($_POST['slug'] ?? ''));
  $event = event_by_slug($pdo, $slug);
  if (!$event) {
    http_response_code(404);
    exit('Event not found');
  }

  try {
    $dayIds = array_values(array_unique(array_map('intval', $_POST['days'] ?? [])));
    $availableDayIds = array_map('intval', array_column(event_days($pdo, (int) $event['id']), 'id'));
    $dayIds = array_values(array_intersect($dayIds, $availableDayIds));
    $seat = max(1, min((int) $event['capacity'], (int) ($_POST['seat'] ?? 0)));
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if (!$dayIds) {
      throw new RuntimeException('Выберите хотя бы один день.');
    }
    if ($seat < 1) {
      throw new RuntimeException('Выберите место.');
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Укажите имя и корректную почту.');
    }

    $successTicket = create_ticket($pdo, $event, $dayIds, $seat, $name, $email, $phone);
    $emailSent = send_ticket_email($pdo, $successTicket);
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$ticketCode = isset($_GET['ticket']) ? strtoupper(trim((string) $_GET['ticket'])) : '';
$ticket = $ticketCode !== '' ? ticket_by_code($pdo, $ticketCode) : null;
$slug = isset($_GET['event']) ? trim((string) $_GET['event']) : '';
$event = $slug !== '' ? event_by_slug($pdo, $slug) : null;
$events = published_events($pdo);

$pageTitle = $ticket ? 'Билет ' . $ticket['code'] : ($event ? $event['title'] : 'Мероприятия');
$pageDescription = 'Билеты на мероприятия и соревнования.';
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<?php include __DIR__ . '/partials/header.php'; ?>

<main>
  <?php if ($ticketCode !== ''): ?>
    <section class="ticket-screen">
      <?php if ($ticket): ?>
        <?php $verifyUrl = app_url('?ticket=' . urlencode((string) $ticket['code'])); ?>
        <div class="ticket-hero">
          <p class="kicker">valid ticket / <?= h($ticket['status']) ?></p>
          <h1><?= h($ticket['title']) ?></h1>
          <div class="ticket-meta">
            <span><?= h(implode(' + ', array_map(static fn($day) => format_date_ru($day['event_date']), $ticket['days']))) ?></span>
            <span><?= h($ticket['starts_time']) ?></span>
            <span>место <?= h($ticket['seat_number']) ?></span>
          </div>
        </div>
        <div class="ticket-layout">
          <article class="ticket-card">
            <div>
              <span class="ticket-code"><?= h($ticket['code']) ?></span>
              <h2><?= h($ticket['buyer_name']) ?></h2>
              <p><?= h($ticket['venue']) ?></p>
            </div>
            <img class="ticket-qr" src="/qr.php?text=<?= urlencode($verifyUrl) ?>" alt="QR-код билета">
          </article>
          <aside class="status-panel">
            <span class="status-dot"></span>
            <strong>Билет найден в базе</strong>
            <p>Статус билета: <?= h($ticket['status']) ?>. Покажите этот экран или QR-код на входе.</p>
            <?php if (in_array($viewerRole, ['owner', 'controller'], true)): ?>
              <form method="post" action="/admin/">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="ticket_status">
                <input type="hidden" name="id" value="<?= h($ticket['id']) ?>">
                <input type="hidden" name="status" value="checked_in">
                <button class="button button--wide" type="submit">Подтвердить вход</button>
              </form>
            <?php endif; ?>
          </aside>
        </div>
      <?php else: ?>
        <section class="empty-state">
          <p class="kicker">ticket lookup</p>
          <h1>Билет не найден</h1>
          <a class="button" href="/">К мероприятиям</a>
        </section>
      <?php endif; ?>
    </section>
  <?php elseif ($event): ?>
    <?php
      $days = event_days($pdo, (int) $event['id']);
      $selectedDayIds = array_map('intval', array_column($days, 'id'));
      $occupied = occupied_seats($pdo, (int) $event['id'], $selectedDayIds);
      $occupiedMap = occupied_seat_days($pdo, (int) $event['id']);
    ?>
    <section class="event-detail">
      <a class="back-link" href="/">← все мероприятия</a>
      <?php if ($successTicket): ?>
        <div class="notice">
          <strong>Билет оформлен: <?= h($successTicket['code']) ?></strong>
          <?php if ($emailSent): ?>
            <span>Письмо с QR-кодом отправлено на почту.</span>
          <?php else: ?>
            <span>Почта пока не настроена или SMTP не ответил. Откройте билет сейчас и сделайте скриншот QR-кода.</span>
          <?php endif; ?>
          <a href="/?ticket=<?= urlencode((string) $successTicket['code']) ?>">Открыть билет</a>
        </div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="notice notice--error"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="event-grid">
        <section class="event-copy">
          <?php if (!empty($event['image'])): ?>
            <img class="event-cover" src="<?= h($event['image']) ?>" alt="">
          <?php endif; ?>
          <p class="kicker">Оформление билета</p>
          <h1><?= h($event['title']) ?></h1>
          <p class="lead"><?= h($event['description']) ?></p>
          <dl class="facts">
            <div><dt>Дата</dt><dd><?= h(format_date_ru($event['starts_at'])) ?><?= count($days) > 1 ? ' / ' . count($days) . ' дня' : '' ?></dd></div>
            <div><dt>Время</dt><dd><?= h($event['starts_time']) ?></dd></div>
            <div><dt>Место</dt><dd><?= h($event['venue']) ?></dd></div>
            <div><dt>Билеты</dt><dd>бесплатно</dd></div>
          </dl>
          <?php $gallery = gallery_items($event['gallery'] ?? '[]'); ?>
          <?php if ($gallery): ?>
            <div class="event-gallery">
              <?php foreach ($gallery as $image): ?>
                <img src="<?= h($image) ?>" alt="">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <form class="booking-panel" method="post" action="/?event=<?= urlencode((string) $event['slug']) ?>">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="book">
          <input type="hidden" name="slug" value="<?= h($event['slug']) ?>">
          <input type="hidden" name="seat" value="" data-seat-input>

          <div class="panel-head">
            <span>01</span>
            <h2>Дни посещения</h2>
          </div>
          <div class="day-picker" data-day-picker>
            <?php foreach ($days as $day): ?>
              <label>
                <input type="checkbox" name="days[]" value="<?= h($day['id']) ?>" checked>
                <span><?= h($day['label']) ?><small><?= h(format_date_ru($day['event_date'])) ?></small></span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="panel-head">
            <span>02</span>
            <h2>Место</h2>
          </div>
          <div class="seat-map" style="--capacity: <?= (int) $event['capacity'] ?>" data-seat-map>
            <?php for ($seat = 1; $seat <= (int) $event['capacity']; $seat++): ?>
              <?php $busyDays = $occupiedMap[$seat] ?? []; ?>
              <button class="seat <?= in_array($seat, $occupied, true) ? 'is-busy' : '' ?>" type="button" data-seat="<?= $seat ?>" data-busy-days="<?= h(implode(',', $busyDays)) ?>" <?= in_array($seat, $occupied, true) ? 'disabled' : '' ?>><?= $seat ?></button>
            <?php endfor; ?>
          </div>

          <div class="panel-head">
            <span>03</span>
            <h2>Контакты</h2>
          </div>
          <div class="form-grid">
            <label>Имя <input name="name" required autocomplete="name"></label>
            <label>Email <input name="email" type="email" required autocomplete="email"></label>
            <label>Телефон <input name="phone" autocomplete="tel"></label>
          </div>
          <button class="button button--wide" type="submit">Получить билет</button>
        </form>
      </div>
    </section>
  <?php else: ?>
    <section class="hero">
      <div class="hero__copy">
        <p class="kicker">Билеты на мероприятия</p>
        <h1>Мероприятия</h1>
        <p>Выберите мероприятие, забронируйте место и получите QR-билет сразу на экране.</p>
      </div>
      <div class="hero__panel">
        <span><?= count($events) ?></span>
        <p>активных мероприятий</p>
      </div>
    </section>

    <section class="events-toolbar" aria-label="Поиск мероприятий">
      <div>
        <h2>Доступные мероприятия</h2>
      </div>
      <label class="search-field">
        <span class="search-field__icon" aria-hidden="true">⌕</span>
        <input type="search" placeholder="Название или место" data-event-search>
      </label>
    </section>

    <section class="event-list" aria-label="Список мероприятий">
      <?php if (!$events): ?>
        <article class="empty-list">
          <h2>Мероприятий пока нет</h2>
          <p>Когда администратор опубликует событие, оно появится здесь.</p>
        </article>
      <?php endif; ?>
      <?php foreach ($events as $index => $item): ?>
        <?php $days = event_days($pdo, (int) $item['id']); ?>
        <article class="event-card" data-event-card data-event-title="<?= h(mb_strtolower(($item['title'] ?? '') . ' ' . ($item['venue'] ?? ''), 'UTF-8')) ?>">
          <div class="event-card__media">
            <img src="<?= h(!empty($item['image']) ? $item['image'] : '/assets/img/school-logo.png') ?>" alt="">
          </div>
          <div class="event-card__body">
            <h2><?= h($item['title']) ?></h2>
            <p><?= h($item['description']) ?></p>
            <div class="event-card__meta">
              <span><?= h(format_date_ru($item['starts_at'])) ?></span>
              <span><?= h($item['starts_time']) ?></span>
              <span><?= h($item['venue']) ?></span>
              <span><?= count($days) ?> <?= count($days) === 1 ? 'день' : 'дня' ?></span>
            </div>
          </div>
          <div class="event-card__action">
            <strong>бесплатно</strong>
            <a class="button" href="/?event=<?= urlencode((string) $item['slug']) ?>">Получить билет</a>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
