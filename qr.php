<?php
declare(strict_types=1);

$text = trim((string) ($_GET['text'] ?? 'https://event.egor-zvada.ru'));
if ($text === '') {
  $text = 'https://event.egor-zvada.ru';
}

function qr_gf_mul(int $x, int $y): int {
  $result = 0;
  while ($y > 0) {
    if (($y & 1) !== 0) {
      $result ^= $x;
    }
    $x <<= 1;
    if (($x & 0x100) !== 0) {
      $x ^= 0x11D;
    }
    $y >>= 1;
  }
  return $result & 0xFF;
}

function qr_generator_poly(int $degree): array {
  $result = array_fill(0, $degree, 0);
  $result[$degree - 1] = 1;
  $root = 1;
  for ($i = 0; $i < $degree; $i++) {
    for ($j = 0; $j < $degree; $j++) {
      $result[$j] = qr_gf_mul($result[$j], $root);
      if ($j + 1 < $degree) {
        $result[$j] ^= $result[$j + 1];
      }
    }
    $root = qr_gf_mul($root, 2);
  }
  return $result;
}

function qr_reed_solomon(array $data, int $degree): array {
  $generator = qr_generator_poly($degree);
  $result = array_fill(0, $degree, 0);
  foreach ($data as $byte) {
    $factor = $byte ^ $result[0];
    array_shift($result);
    $result[] = 0;
    for ($i = 0; $i < $degree; $i++) {
      $result[$i] ^= qr_gf_mul($generator[$i], $factor);
    }
  }
  return $result;
}

function qr_append_bits(array &$bits, int $value, int $length): void {
  for ($i = $length - 1; $i >= 0; $i--) {
    $bits[] = (($value >> $i) & 1) !== 0;
  }
}

function qr_data_codewords(string $text): array {
  $bytes = array_values(unpack('C*', $text) ?: []);
  if (count($bytes) > 78) {
    $bytes = array_slice($bytes, 0, 78);
  }

  $bits = [];
  qr_append_bits($bits, 0b0100, 4);
  qr_append_bits($bits, count($bytes), 8);
  foreach ($bytes as $byte) {
    qr_append_bits($bits, $byte, 8);
  }

  $capacityBits = 80 * 8;
  qr_append_bits($bits, 0, min(4, $capacityBits - count($bits)));
  while ((count($bits) % 8) !== 0) {
    $bits[] = false;
  }

  $codewords = [];
  for ($i = 0; $i < count($bits); $i += 8) {
    $value = 0;
    for ($j = 0; $j < 8; $j++) {
      $value = ($value << 1) | ($bits[$i + $j] ? 1 : 0);
    }
    $codewords[] = $value;
  }

  for ($pad = 0xEC; count($codewords) < 80; $pad ^= 0xEC ^ 0x11) {
    $codewords[] = $pad;
  }

  return $codewords;
}

function qr_set(array &$modules, array &$reserved, int $x, int $y, bool $dark, bool $reserve = true): void {
  $modules[$y][$x] = $dark;
  if ($reserve) {
    $reserved[$y][$x] = true;
  }
}

function qr_finder(array &$modules, array &$reserved, int $x, int $y): void {
  for ($dy = -1; $dy <= 7; $dy++) {
    for ($dx = -1; $dx <= 7; $dx++) {
      $xx = $x + $dx;
      $yy = $y + $dy;
      if ($xx < 0 || $yy < 0 || $xx >= 33 || $yy >= 33) {
        continue;
      }
      $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
        && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
      qr_set($modules, $reserved, $xx, $yy, $dark);
    }
  }
}

function qr_alignment(array &$modules, array &$reserved, int $cx, int $cy): void {
  for ($dy = -2; $dy <= 2; $dy++) {
    for ($dx = -2; $dx <= 2; $dx++) {
      $dark = max(abs($dx), abs($dy)) !== 1;
      qr_set($modules, $reserved, $cx + $dx, $cy + $dy, $dark);
    }
  }
}

function qr_format_bits(int $mask): int {
  $data = (1 << 3) | $mask; // Error correction level L.
  $bits = $data << 10;
  for ($i = 14; $i >= 10; $i--) {
    if ((($bits >> $i) & 1) !== 0) {
      $bits ^= 0x537 << ($i - 10);
    }
  }
  return (($data << 10) | $bits) ^ 0x5412;
}

function qr_build_matrix(string $text): array {
  $size = 33;
  $modules = array_fill(0, $size, array_fill(0, $size, false));
  $reserved = array_fill(0, $size, array_fill(0, $size, false));

  qr_finder($modules, $reserved, 0, 0);
  qr_finder($modules, $reserved, $size - 7, 0);
  qr_finder($modules, $reserved, 0, $size - 7);
  qr_alignment($modules, $reserved, 26, 26);

  for ($i = 8; $i < $size - 8; $i++) {
    qr_set($modules, $reserved, $i, 6, $i % 2 === 0);
    qr_set($modules, $reserved, 6, $i, $i % 2 === 0);
  }

  qr_set($modules, $reserved, 8, 25, true);

  for ($i = 0; $i < 9; $i++) {
    if ($i !== 6) {
      $reserved[8][$i] = true;
      $reserved[$i][8] = true;
    }
  }
  for ($i = 0; $i < 8; $i++) {
    $reserved[8][$size - 1 - $i] = true;
    $reserved[$size - 1 - $i][8] = true;
  }

  $data = qr_data_codewords($text);
  $codewords = array_merge($data, qr_reed_solomon($data, 20));
  $bits = [];
  foreach ($codewords as $codeword) {
    qr_append_bits($bits, $codeword, 8);
  }

  $bitIndex = 0;
  $upward = true;
  for ($x = $size - 1; $x >= 1; $x -= 2) {
    if ($x === 6) {
      $x--;
    }
    for ($i = 0; $i < $size; $i++) {
      $y = $upward ? $size - 1 - $i : $i;
      for ($dx = 0; $dx < 2; $dx++) {
        $xx = $x - $dx;
        if ($reserved[$y][$xx]) {
          continue;
        }
        $dark = $bits[$bitIndex] ?? false;
        $mask = (($xx + $y) % 2) === 0;
        qr_set($modules, $reserved, $xx, $y, $dark !== $mask, false);
        $bitIndex++;
      }
    }
    $upward = !$upward;
  }

  $format = qr_format_bits(0);
  for ($i = 0; $i <= 5; $i++) {
    qr_set($modules, $reserved, 8, $i, (($format >> $i) & 1) !== 0);
  }
  qr_set($modules, $reserved, 8, 7, (($format >> 6) & 1) !== 0);
  qr_set($modules, $reserved, 8, 8, (($format >> 7) & 1) !== 0);
  qr_set($modules, $reserved, 7, 8, (($format >> 8) & 1) !== 0);
  for ($i = 9; $i < 15; $i++) {
    qr_set($modules, $reserved, 14 - $i, 8, (($format >> $i) & 1) !== 0);
  }
  for ($i = 0; $i < 8; $i++) {
    qr_set($modules, $reserved, $size - 1 - $i, 8, (($format >> $i) & 1) !== 0);
  }
  for ($i = 8; $i < 15; $i++) {
    qr_set($modules, $reserved, 8, $size - 15 + $i, (($format >> $i) & 1) !== 0);
  }
  qr_set($modules, $reserved, 8, $size - 8, true);

  return $modules;
}

$matrix = qr_build_matrix($text);
$module = 8;
$quiet = 4;
$size = count($matrix);
$pixelSize = ($size + $quiet * 2) * $module;

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');

echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $pixelSize . ' ' . $pixelSize . '" role="img" aria-label="Telegram QR code">';
echo '<rect width="100%" height="100%" fill="#fff"/>';
echo '<g fill="#050505">';
foreach ($matrix as $y => $row) {
  foreach ($row as $x => $dark) {
    if ($dark) {
      echo '<rect x="' . (($x + $quiet) * $module) . '" y="' . (($y + $quiet) * $module) . '" width="' . $module . '" height="' . $module . '"/>';
    }
  }
}
echo '</g></svg>';
