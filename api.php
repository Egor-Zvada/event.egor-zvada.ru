<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$pdo = db();
$resource = (string) ($_GET['resource'] ?? 'events');

try {
  if ($resource === 'events') {
    $events = published_events($pdo);
    foreach ($events as &$event) {
      $event['days'] = event_days($pdo, (int) $event['id']);
      $event['url'] = app_url('?event=' . urlencode((string) $event['slug']));
    }
    echo json_encode(['ok' => true, 'events' => $events], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($resource === 'event') {
    $event = event_by_slug($pdo, (string) ($_GET['slug'] ?? ''));
    if (!$event) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'error' => 'event_not_found']);
      exit;
    }
    $event['days'] = event_days($pdo, (int) $event['id']);
    echo json_encode(['ok' => true, 'event' => $event], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($resource === 'ticket') {
    $ticket = ticket_by_code($pdo, strtoupper((string) ($_GET['code'] ?? '')));
    if (!$ticket) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'error' => 'ticket_not_found']);
      exit;
    }
    echo json_encode(['ok' => true, 'ticket' => $ticket], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'unknown_resource']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
