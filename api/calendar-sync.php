<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');


function jsonResponse(int $statusCode, array $payload): void {
  http_response_code($statusCode);
  header('Content-Type: application/json');
  echo json_encode($payload);
  exit;
}

function denyCsrf(): void {
  jsonResponse(403, ['error' => 'CSRF validation failed']);
}

function internalErrorResponse(string $logMessage, Throwable $exception, int $statusCode = 500): void {
  error_log(sprintf('%s: %s in %s:%d', $logMessage, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
  jsonResponse($statusCode, ['error' => 'Internal server error']);
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['mailcow_user']) || empty($_SESSION['mailcow_user'])) {
  jsonResponse(401, ['error' => 'Authentication required']);
}

$dbDsn = getenv('MC_DB_DSN');
$dbUser = getenv('MC_DB_USER');
$dbPass = getenv('MC_DB_PASS');
if (!is_string($dbDsn) || $dbDsn === '' || !is_string($dbUser) || $dbUser === '' || !is_string($dbPass) || $dbPass === '') {
  error_log('Missing required DB environment variables: MC_DB_DSN, MC_DB_USER, and/or MC_DB_PASS');
  jsonResponse(503, ['error' => 'Service temporarily unavailable']);
}

try {
  $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  internalErrorResponse('Database connection failed', $e, 503);
} catch (Throwable $e) {
  internalErrorResponse('Unexpected error during database initialization', $e, 500);
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, name, email_a, email_b, provider_a, provider_b, sync_direction, status FROM calendar_sync_links WHERE owner = :owner ORDER BY created_at DESC');
    $stmt->execute(['owner' => $_SESSION['mailcow_user']]);
    jsonResponse(200, ['items' => $stmt->fetchAll(), 'csrf_token' => $_SESSION['csrf_token']]);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrfHeader) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
      denyCsrf();
    }

    try {
      $input = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
      jsonResponse(400, ['error' => 'Invalid JSON body']);
    }

    if (!is_array($input)) {
      jsonResponse(400, ['error' => 'Invalid JSON body']);
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
      jsonResponse(422, ['error' => 'Validation failed', 'errors' => $errors]);
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

    jsonResponse(201, ['ok' => true]);
  }

  jsonResponse(405, ['error' => 'Method not allowed']);
} catch (PDOException $e) {
  internalErrorResponse('Database request failed', $e, 503);
} catch (Throwable $e) {
  internalErrorResponse('Unhandled request error', $e, 500);
}
