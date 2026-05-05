<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');


function denyCsrf(): void {
  http_response_code(403);
  echo json_encode(['error' => 'CSRF validation failed']);
  exit;
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['mailcow_user']) || empty($_SESSION['mailcow_user'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Authentication required']);
  exit;
}

$pdo = new PDO(getenv('MC_DB_DSN'), getenv('MC_DB_USER'), getenv('MC_DB_PASS'), [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $stmt = $pdo->prepare('SELECT id, name, email_a, email_b, provider_a, provider_b, sync_direction, status FROM calendar_sync_links WHERE owner = :owner ORDER BY created_at DESC');
  $stmt->execute(['owner' => $_SESSION['mailcow_user']]);
  echo json_encode(['items' => $stmt->fetchAll(), 'csrf_token' => $_SESSION['csrf_token']]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!is_string($csrfHeader) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
    denyCsrf();
  }

  try {
    $input = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
  }

  if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
  }

  $errors = [];
  $requiredStringFields = [
    'name' => 120,
    'email_a' => 255,
    'email_b' => 255,
    'provider_a' => 32,
    'provider_b' => 32,
    'sync_direction' => 16,
    'mailcow_secret' => 255,
  ];

  foreach ($requiredStringFields as $field => $maxLength) {
    if (!array_key_exists($field, $input)) {
      $errors[$field][] = 'This field is required.';
      continue;
    }
    if (!is_string($input[$field])) {
      $errors[$field][] = 'This field must be a string.';
      continue;
    }

    $input[$field] = trim($input[$field]);
    if ($input[$field] === '') {
      $errors[$field][] = 'This field cannot be empty.';
      continue;
    }

    if (mb_strlen($input[$field]) > $maxLength) {
      $errors[$field][] = sprintf('Must be %d characters or fewer.', $maxLength);
    }
  }

  foreach (['email_a', 'email_b'] as $emailField) {
    if (isset($input[$emailField]) && is_string($input[$emailField]) && $input[$emailField] !== ''
      && filter_var($input[$emailField], FILTER_VALIDATE_EMAIL) === false) {
      $errors[$emailField][] = 'Must be a valid email address.';
    }
  }

  $allowedProviders = ['mailcow', 'google', 'outlook'];
  $allowedDirections = ['two_way', 'a_to_b', 'b_to_a'];
  if (isset($input['provider_a']) && is_string($input['provider_a']) && $input['provider_a'] !== ''
    && !in_array($input['provider_a'], $allowedProviders, true)) {
    $errors['provider_a'][] = 'Unsupported provider.';
  }
  if (isset($input['provider_b']) && is_string($input['provider_b']) && $input['provider_b'] !== ''
    && !in_array($input['provider_b'], $allowedProviders, true)) {
    $errors['provider_b'][] = 'Unsupported provider.';
  }
  if (isset($input['sync_direction']) && is_string($input['sync_direction']) && $input['sync_direction'] !== ''
    && !in_array($input['sync_direction'], $allowedDirections, true)) {
    $errors['sync_direction'][] = 'Unsupported sync direction.';
  }

  if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['error' => 'Validation failed', 'errors' => $errors]);
    exit;
  }

  if ($input['mailcow_secret'] === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Validation failed', 'errors' => ['mailcow_secret' => ['This field cannot be empty.']]]);
    exit;
  }

  $hashedSecret = password_hash((string)$input['mailcow_secret'], PASSWORD_ARGON2ID);

  $stmt = $pdo->prepare('INSERT INTO calendar_sync_links (owner, name, email_a, email_b, provider_a, provider_b, sync_direction, secret_hash, status) VALUES (:owner, :name, :email_a, :email_b, :provider_a, :provider_b, :sync_direction, :secret_hash, :status)');
  $stmt->execute([
    'owner' => $_SESSION['mailcow_user'],
    'name' => $input['name'],
    'email_a' => (string)$input['email_a'],
    'email_b' => (string)$input['email_b'],
    'provider_a' => (string)$input['provider_a'],
    'provider_b' => (string)$input['provider_b'],
    'sync_direction' => (string)$input['sync_direction'],
    'secret_hash' => $hashedSecret,
    'status' => 'pending_auth',
  ]);

  http_response_code(201);
  echo json_encode(['ok' => true]);
  exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
