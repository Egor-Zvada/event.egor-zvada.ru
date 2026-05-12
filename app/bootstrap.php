<?php
declare(strict_types=1);

const APP_NAME = 'Egor Zvada Events';
const APP_VERSION = '0.1-alpha';

$root = dirname(__DIR__);
$dataDir = $root . '/data';
if (!is_dir($dataDir)) {
  mkdir($dataDir, 0775, true);
}

function h($value): string {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'event.egor-zvada.ru';
  return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $path = dirname(__DIR__) . '/data/events.sqlite';
  $pdo = new PDO('sqlite:' . $path);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA foreign_keys = ON');
  init_db($pdo);
  return $pdo;
}

function init_db(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      slug TEXT NOT NULL UNIQUE,
      title TEXT NOT NULL,
      description TEXT NOT NULL DEFAULT '',
      starts_at TEXT NOT NULL,
      starts_time TEXT NOT NULL,
      venue TEXT NOT NULL,
      organizer TEXT NOT NULL,
      capacity INTEGER NOT NULL DEFAULT 100,
      is_paid INTEGER NOT NULL DEFAULT 0,
      price INTEGER NOT NULL DEFAULT 0,
      status TEXT NOT NULL DEFAULT 'published',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS event_days (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      event_id INTEGER NOT NULL,
      event_date TEXT NOT NULL,
      label TEXT NOT NULL,
      UNIQUE(event_id, event_date),
      FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS tickets (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      code TEXT NOT NULL UNIQUE,
      event_id INTEGER NOT NULL,
      seat_number INTEGER NOT NULL,
      buyer_name TEXT NOT NULL,
      buyer_email TEXT NOT NULL,
      buyer_phone TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'confirmed',
      paid_amount INTEGER NOT NULL DEFAULT 0,
      email_sent INTEGER NOT NULL DEFAULT 0,
      checked_in_at TEXT DEFAULT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS ticket_days (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      ticket_id INTEGER NOT NULL,
      event_day_id INTEGER NOT NULL,
      seat_number INTEGER NOT NULL,
      UNIQUE(event_day_id, seat_number),
      FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
      FOREIGN KEY(event_day_id) REFERENCES event_days(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS staff_users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      role TEXT NOT NULL CHECK(role IN ('admin','controller')),
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
  ");
  ensure_column($pdo, 'events', 'image', "TEXT NOT NULL DEFAULT ''");
  ensure_column($pdo, 'events', 'gallery', "TEXT NOT NULL DEFAULT '[]'");

  $count = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
  if ($count === 0) {
    seed_demo_events($pdo);
  }
  seed_staff_users($pdo);
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
  $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
  foreach ($columns as $item) {
    if (($item['name'] ?? '') === $column) {
      return;
    }
  }
  $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function seed_staff_users(PDO $pdo): void {
  $count = (int) $pdo->query('SELECT COUNT(*) FROM staff_users')->fetchColumn();
  if ($count > 0) {
    return;
  }
  $adminUser = getenv('ADMIN_USER') ?: 'admin';
  $adminPassword = getenv('ADMIN_PASSWORD') ?: 'change-me-now';
  $controllerPassword = getenv('CONTROLLER_PASSWORD') ?: 'controller-change-me';
  $stmt = $pdo->prepare('INSERT INTO staff_users (username,password_hash,role) VALUES (?,?,?)');
  $stmt->execute([$adminUser, password_hash($adminPassword, PASSWORD_DEFAULT), 'admin']);
  $stmt->execute(['controller', password_hash($controllerPassword, PASSWORD_DEFAULT), 'controller']);
}

function seed_demo_events(PDO $pdo): void {
  $events = [
    [
      'tavrida-tech-showcase',
      'Tavrida Tech Showcase',
      'Двухдневный технический шоукейс: сцена, медиа, свет, сети и аккуратная инженерная магия за кадром.',
      '2026-06-18',
      '18:30',
      'Севастополь, арт-кластер',
      'egor_zvada',
      96,
      1,
      250000,
      2,
    ],
    [
      'cyber-arena-open',
      'Cyber Arena Open',
      'Открытый турнир с живой сеткой, трансляцией и местами для зрителей.',
      '2026-07-04',
      '16:00',
      'Южно-Сахалинск, медиа-зал',
      'EZ Event Systems',
      64,
      0,
      0,
      1,
    ],
  ];

  $insert = $pdo->prepare('INSERT INTO events (slug,title,description,starts_at,starts_time,venue,organizer,capacity,is_paid,price) VALUES (?,?,?,?,?,?,?,?,?,?)');
  foreach ($events as $event) {
    [$slug, $title, $description, $startsAt, $time, $venue, $organizer, $capacity, $isPaid, $price, $days] = $event;
    $insert->execute([$slug, $title, $description, $startsAt, $time, $venue, $organizer, $capacity, $isPaid, $price]);
    $eventId = (int) $pdo->lastInsertId();
    sync_event_days($pdo, $eventId, $startsAt, $days);
  }
}

function slugify(string $value): string {
  $value = function_exists('mb_strtolower') ? trim(mb_strtolower($value, 'UTF-8')) : trim(strtolower($value));
  $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $value = strtr($value, $map);
  $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
  $value = trim($value, '-');
  return $value !== '' ? $value : 'event';
}

function sync_event_days(PDO $pdo, int $eventId, string $startDate, int $days): void {
  $days = max(1, min(30, $days));
  $pdo->prepare('DELETE FROM event_days WHERE event_id = ?')->execute([$eventId]);
  $date = new DateTimeImmutable($startDate);
  $stmt = $pdo->prepare('INSERT INTO event_days (event_id,event_date,label) VALUES (?,?,?)');
  for ($i = 0; $i < $days; $i++) {
    $eventDate = $date->modify('+' . $i . ' days')->format('Y-m-d');
    $stmt->execute([$eventId, $eventDate, 'День ' . ($i + 1)]);
  }
}

function event_days(PDO $pdo, int $eventId): array {
  $stmt = $pdo->prepare('SELECT * FROM event_days WHERE event_id = ? ORDER BY event_date');
  $stmt->execute([$eventId]);
  return $stmt->fetchAll();
}

function event_by_slug(PDO $pdo, string $slug): ?array {
  $stmt = $pdo->prepare("SELECT * FROM events WHERE slug = ? AND status != 'archived'");
  $stmt->execute([$slug]);
  $event = $stmt->fetch();
  return $event ?: null;
}

function published_events(PDO $pdo): array {
  return $pdo->query("SELECT * FROM events WHERE status = 'published' ORDER BY starts_at, starts_time")->fetchAll();
}

function occupied_seats(PDO $pdo, int $eventId, array $dayIds): array {
  if (!$dayIds) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($dayIds), '?'));
  $stmt = $pdo->prepare("
    SELECT DISTINCT td.seat_number
    FROM ticket_days td
    JOIN tickets t ON t.id = td.ticket_id
    WHERE t.event_id = ? AND t.status != 'cancelled' AND td.event_day_id IN ($placeholders)
  ");
  $stmt->execute(array_merge([$eventId], $dayIds));
  return array_map('intval', array_column($stmt->fetchAll(), 'seat_number'));
}

function occupied_seat_days(PDO $pdo, int $eventId): array {
  $stmt = $pdo->prepare("
    SELECT td.seat_number, td.event_day_id
    FROM ticket_days td
    JOIN tickets t ON t.id = td.ticket_id
    WHERE t.event_id = ? AND t.status != 'cancelled'
  ");
  $stmt->execute([$eventId]);
  $map = [];
  foreach ($stmt->fetchAll() as $row) {
    $seat = (int) $row['seat_number'];
    $map[$seat] ??= [];
    $map[$seat][] = (int) $row['event_day_id'];
  }
  return $map;
}

function format_date_ru(string $date): string {
  $months = ['01'=>'января','02'=>'февраля','03'=>'марта','04'=>'апреля','05'=>'мая','06'=>'июня','07'=>'июля','08'=>'августа','09'=>'сентября','10'=>'октября','11'=>'ноября','12'=>'декабря'];
  $dt = new DateTimeImmutable($date);
  return $dt->format('j') . ' ' . $months[$dt->format('m')] . ' ' . $dt->format('Y');
}

function money(int $kopeks): string {
  return number_format($kopeks / 100, 0, ',', ' ') . ' ₽';
}

function ticket_code(): string {
  return strtoupper(bin2hex(random_bytes(5)));
}

function gallery_items($value): array {
  if (is_array($value)) {
    return array_values(array_filter($value, 'is_string'));
  }
  $items = json_decode((string) $value, true);
  if (is_array($items)) {
    return array_values(array_filter($items, 'is_string'));
  }
  $parts = preg_split('/[\r\n,]+/', (string) $value) ?: [];
  $parts = array_map('trim', $parts);
  return array_values(array_filter($parts, static fn($item) => $item !== ''));
}

function upload_image(string $field, string $fallback = ''): string {
  if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return $fallback;
  }
  $file = $_FILES[$field];
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Ошибка загрузки файла.');
  }
  if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
    throw new RuntimeException('Изображение слишком большое. Максимум 8 МБ.');
  }
  $tmp = (string) $file['tmp_name'];
  $name = (string) $file['name'];
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) {
    throw new RuntimeException('Можно загружать jpg, png, webp, gif или svg.');
  }
  if ($ext === 'svg') {
    $content = file_get_contents($tmp, false, null, 0, 512 * 1024);
    if ($content === false || stripos($content, '<svg') === false || preg_match('/<\s*script|on[a-z]+\s*=|javascript\s*:/i', $content)) {
      throw new RuntimeException('SVG не прошел проверку безопасности.');
    }
  } elseif (@getimagesize($tmp) === false) {
    throw new RuntimeException('Файл не похож на изображение.');
  }
  $dir = dirname(__DIR__) . '/assets/img/uploads';
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }
  $base = slugify(pathinfo($name, PATHINFO_FILENAME));
  $target = $base . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
  if (!move_uploaded_file($tmp, $dir . '/' . $target)) {
    throw new RuntimeException('Не получилось сохранить изображение.');
  }
  return '/assets/img/uploads/' . $target;
}

function upload_gallery(string $field, array $existing = []): array {
  if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
    return $existing;
  }
  $result = $existing;
  foreach ($_FILES[$field]['name'] as $index => $name) {
    if (($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
      continue;
    }
    $_FILES['_gallery_single'] = [
      'name' => $name,
      'type' => $_FILES[$field]['type'][$index] ?? '',
      'tmp_name' => $_FILES[$field]['tmp_name'][$index] ?? '',
      'error' => $_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE,
      'size' => $_FILES[$field]['size'][$index] ?? 0,
    ];
    $result[] = upload_image('_gallery_single');
    unset($_FILES['_gallery_single']);
  }
  return array_values(array_unique($result));
}

function create_ticket(PDO $pdo, array $event, array $dayIds, int $seat, string $name, string $email, string $phone): array {
  $pdo->beginTransaction();
  try {
    $code = ticket_code();
    $paidAmount = ((int) $event['is_paid'] === 1) ? (int) $event['price'] * count($dayIds) : 0;
    $stmt = $pdo->prepare('INSERT INTO tickets (code,event_id,seat_number,buyer_name,buyer_email,buyer_phone,paid_amount) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$code, (int) $event['id'], $seat, $name, $email, $phone, $paidAmount]);
    $ticketId = (int) $pdo->lastInsertId();

    $dayStmt = $pdo->prepare('INSERT INTO ticket_days (ticket_id,event_day_id,seat_number) VALUES (?,?,?)');
    foreach ($dayIds as $dayId) {
      $dayStmt->execute([$ticketId, (int) $dayId, $seat]);
    }

    $pdo->commit();
    $ticket = ticket_by_code($pdo, $code);
    if (!$ticket) {
      throw new RuntimeException('Билет создан, но не найден.');
    }
    return $ticket;
  } catch (Throwable $error) {
    $pdo->rollBack();
    if (str_contains($error->getMessage(), 'UNIQUE')) {
      throw new RuntimeException('Это место уже заняли на один из выбранных дней.');
    }
    throw $error;
  }
}

function ticket_by_code(PDO $pdo, string $code): ?array {
  $stmt = $pdo->prepare('SELECT t.*, e.title, e.venue, e.starts_time, e.organizer FROM tickets t JOIN events e ON e.id = t.event_id WHERE t.code = ?');
  $stmt->execute([$code]);
  $ticket = $stmt->fetch();
  if (!$ticket) {
    return null;
  }
  $days = $pdo->prepare('SELECT d.* FROM ticket_days td JOIN event_days d ON d.id = td.event_day_id WHERE td.ticket_id = ? ORDER BY d.event_date');
  $days->execute([(int) $ticket['id']]);
  $ticket['days'] = $days->fetchAll();
  return $ticket;
}

function mail_config(): array {
  $file = dirname(__DIR__) . '/config/mail.php';
  if (is_file($file)) {
    $config = require $file;
    if (is_array($config)) {
      return $config;
    }
  }
  return [
    'host' => getenv('SMTP_HOST') ?: '',
    'port' => (int) (getenv('SMTP_PORT') ?: 587),
    'username' => getenv('SMTP_USER') ?: '',
    'password' => getenv('SMTP_PASS') ?: '',
    'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
    'from_email' => getenv('SMTP_FROM') ?: 'tickets@event.egor-zvada.ru',
    'from_name' => getenv('SMTP_FROM_NAME') ?: 'Egor Zvada Events',
  ];
}

function smtp_send(array $config, string $to, string $subject, string $html): bool {
  $host = trim((string) ($config['host'] ?? ''));
  if ($host === '') {
    return false;
  }

  $port = (int) ($config['port'] ?? 587);
  $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
  $socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
  if (!$socket) {
    return false;
  }

  $read = static function () use ($socket): string {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
      $data .= $line;
      if (isset($line[3]) && $line[3] === ' ') {
        break;
      }
    }
    return $data;
  };
  $write = static function (string $command) use ($socket, $read): string {
    fwrite($socket, $command . "\r\n");
    return $read();
  };
  $ok = static fn(string $response, array $codes): bool => in_array(substr($response, 0, 3), $codes, true);

  if (!$ok($read(), ['220'])) {
    fclose($socket);
    return false;
  }

  $serverName = $_SERVER['SERVER_NAME'] ?? 'event.egor-zvada.ru';
  if (!$ok($write('EHLO ' . $serverName), ['250'])) {
    fclose($socket);
    return false;
  }

  if ($encryption === 'tls') {
    if (!$ok($write('STARTTLS'), ['220']) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($socket);
      return false;
    }
    if (!$ok($write('EHLO ' . $serverName), ['250'])) {
      fclose($socket);
      return false;
    }
  }

  $username = (string) ($config['username'] ?? '');
  $password = (string) ($config['password'] ?? '');
  if ($username !== '') {
    if (!$ok($write('AUTH LOGIN'), ['334'])
      || !$ok($write(base64_encode($username)), ['334'])
      || !$ok($write(base64_encode($password)), ['235'])) {
      fclose($socket);
      return false;
    }
  }

  $from = (string) ($config['from_email'] ?? $username);
  $fromName = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader((string) ($config['from_name'] ?? 'Tickets'), 'UTF-8') : (string) ($config['from_name'] ?? 'Tickets');
  $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
  $headers = [
    'From: ' . $fromName . ' <' . $from . '>',
    'To: <' . $to . '>',
    'Subject: ' . $encodedSubject,
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
  ];
  $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $html);

  $sent = $ok($write('MAIL FROM:<' . $from . '>'), ['250'])
    && $ok($write('RCPT TO:<' . $to . '>'), ['250', '251'])
    && $ok($write('DATA'), ['354']);
  if ($sent) {
    fwrite($socket, $message . "\r\n.\r\n");
    $sent = $ok($read(), ['250']);
  }
  $write('QUIT');
  fclose($socket);
  return $sent;
}

function send_ticket_email(PDO $pdo, array $ticket): bool {
  $to = (string) $ticket['buyer_email'];
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return false;
  }

  $verifyUrl = app_url('?ticket=' . urlencode((string) $ticket['code']));
  $qrUrl = app_url('qr.php?text=' . urlencode($verifyUrl));
  $subject = 'Билет ' . $ticket['code'] . ' — ' . $ticket['title'];
  $days = implode(', ', array_map(static fn($day) => format_date_ru($day['event_date']), $ticket['days'] ?? []));
  $body = "
    <html><body style=\"font-family:Arial,sans-serif;background:#0d0f10;color:#f4f4f1;padding:24px\">
      <div style=\"max-width:640px;margin:0 auto;border:1px solid rgba(244,244,241,.18);padding:24px;background:#131618\">
      <p style=\"margin:0 0 18px;color:rgba(244,244,241,.55);font-size:12px;text-transform:uppercase;letter-spacing:.08em\">event ticket / {$ticket['code']}</p>
      <p><img src=\"{$qrUrl}\" alt=\"QR\" width=\"240\" height=\"240\" style=\"display:block;background:#fff;padding:10px;margin-bottom:22px\"></p>
      <h1 style=\"font-weight:400;margin:0 0 16px;font-size:42px;line-height:.95\">Ваш билет</h1>
      <p><b>{$ticket['title']}</b></p>
      <p>Даты: {$days}<br>Время: {$ticket['starts_time']}<br>Место проведения: {$ticket['venue']}<br>Место в зале: {$ticket['seat_number']}</p>
      <p>Код билета: <b>{$ticket['code']}</b></p>
      <p><a style=\"color:#f4f4f1\" href=\"{$verifyUrl}\">Открыть билет</a></p>
      </div>
    </body></html>";

  $sent = smtp_send(mail_config(), $to, $subject, $body);
  if ($sent) {
    $pdo->prepare('UPDATE tickets SET email_sent = 1 WHERE id = ?')->execute([(int) $ticket['id']]);
  }
  return $sent;
}

function csrf_token(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function check_csrf(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $token = $_POST['csrf_token'] ?? '';
  if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    exit('Bad CSRF token');
  }
}
