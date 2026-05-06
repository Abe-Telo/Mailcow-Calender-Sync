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

function internalErrorResponse(string $message, Throwable $exception, int $statusCode = 500): void {
  error_log(sprintf('%s: %s in %s:%d', $message, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
  jsonResponse($statusCode, ['error' => 'Internal server error']);
}

function audit(PDO $pdo, string $owner, string $action, string $targetType, ?int $targetId, string $result, array $metadata = []): void {
  $stmt = $pdo->prepare('INSERT INTO calendar_sync_audit_log (owner_mailbox, actor, action, target_type, target_id, result, metadata_json) VALUES (:owner,:actor,:action,:target_type,:target_id,:result,:metadata_json)');
  $stmt->execute([
    'owner' => $owner,
    'actor' => $owner,
    'action' => $action,
    'target_type' => $targetType,
    'target_id' => $targetId,
    'result' => $result,
    'metadata_json' => json_encode($metadata),
  ]);
}

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['mailcow_user']) || empty($_SESSION['mailcow_user'])) {
  jsonResponse(401, ['error' => 'Authentication required']);
}
$owner = (string)$_SESSION['mailcow_user'];

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
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_GET['path'] ?? 'jobs';

try {
  if ($method === 'GET' && $path === 'jobs') {
    $stmt = $pdo->prepare('SELECT j.id, j.mailcow_calendar_id, j.external_calendar_id, j.direction, j.conflict_policy, j.interval_seconds, j.enabled, j.status, j.last_run_at, j.last_success_at, j.last_error_code, j.last_error_message, a.provider FROM calendar_sync_jobs j JOIN calendar_sync_accounts a ON a.id = j.external_account_id WHERE j.owner_mailbox = :owner ORDER BY j.created_at DESC');
    $stmt->execute(['owner' => $owner]);
    jsonResponse(200, ['items' => $stmt->fetchAll(), 'csrf_token' => $_SESSION['csrf_token']]);
  }
  if ($method === 'GET' && $path === 'accounts') {
    $stmt = $pdo->prepare('SELECT id, provider, provider_account_id, status, created_at FROM calendar_sync_accounts WHERE owner_mailbox = :owner ORDER BY created_at DESC');
    $stmt->execute(['owner' => $owner]);
    jsonResponse(200, ['items' => $stmt->fetchAll(), 'csrf_token' => $_SESSION['csrf_token']]);
  }

  if (in_array($method, ['POST','PATCH','DELETE'], true)) {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrfHeader) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
      denyCsrf();
    }
  }

  if ($method === 'POST' && $path === 'jobs') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(400, ['error' => 'Invalid JSON body']);

    $required = ['mailcow_calendar_id','external_account_id','external_calendar_id','direction','conflict_policy'];
    foreach ($required as $f) {
      if (!array_key_exists($f, $input)) jsonResponse(422, ['error' => "Missing field: {$f}"]);
    }

    $allowedDirections = ['two_way','mailcow_to_external','external_to_mailcow'];
    $allowedPolicies = ['newest_wins','prefer_mailcow','prefer_external','manual'];
    if (!in_array((string)$input['direction'], $allowedDirections, true)) jsonResponse(422, ['error' => 'Unsupported direction']);
    if (!in_array((string)$input['conflict_policy'], $allowedPolicies, true)) jsonResponse(422, ['error' => 'Unsupported conflict policy']);

    $accountStmt = $pdo->prepare('SELECT id FROM calendar_sync_accounts WHERE id = :id AND owner_mailbox = :owner');
    $accountStmt->execute(['id' => (int)$input['external_account_id'], 'owner' => $owner]);
    if (!$accountStmt->fetch()) jsonResponse(403, ['error' => 'Invalid account ownership']);

    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare('INSERT INTO calendar_sync_jobs (owner_mailbox, mailcow_calendar_id, external_account_id, external_calendar_id, direction, conflict_policy, interval_seconds, enabled, status, next_run_at) VALUES (:owner, :mailcow_calendar_id, :external_account_id, :external_calendar_id, :direction, :conflict_policy, :interval_seconds, 1, :status, DATE_ADD(NOW(), INTERVAL 1 MINUTE))');
      $stmt->execute([
        'owner' => $owner,
        'mailcow_calendar_id' => mb_substr((string)$input['mailcow_calendar_id'], 0, 255),
        'external_account_id' => (int)$input['external_account_id'],
        'external_calendar_id' => mb_substr((string)$input['external_calendar_id'], 0, 255),
        'direction' => (string)$input['direction'],
        'conflict_policy' => (string)$input['conflict_policy'],
        'interval_seconds' => max(60, min(3600, (int)($input['interval_seconds'] ?? 300))),
        'status' => 'idle',
      ]);

      $jobId = (int)$pdo->lastInsertId();
      audit($pdo, $owner, 'job_create', 'job', $jobId, 'success');
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }

    jsonResponse(201, ['ok' => true, 'id' => $jobId]);
  }

  if ($method === 'POST' && $path === 'accounts') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(400, ['error' => 'Invalid JSON body']);

    $provider = (string)($input['provider'] ?? '');
    if (!in_array($provider, ['google','microsoft'], true)) jsonResponse(422, ['error' => 'Unsupported provider']);

    $stmt = $pdo->prepare('INSERT INTO calendar_sync_accounts (owner_mailbox, provider, provider_account_id, encrypted_access_token, encrypted_refresh_token, token_expires_at, scopes, status) VALUES (:owner,:provider,:provider_account_id,:access,:refresh,:expires,:scopes,:status)');
    $stmt->execute([
      'owner' => $owner,
      'provider' => $provider,
      'provider_account_id' => mb_substr((string)($input['provider_account_id'] ?? uniqid($provider . '_', true)), 0, 255),
      'access' => 'oauth_token_placeholder',
      'refresh' => 'oauth_refresh_placeholder',
      'expires' => date('Y-m-d H:i:s', time() + 3600),
      'scopes' => (string)($input['scopes'] ?? 'calendar.readwrite'),
      'status' => 'active',
    ]);
    $accountId = (int)$pdo->lastInsertId();
    audit($pdo, $owner, 'account_connect', 'account', $accountId, 'success', ['provider' => $provider]);
    jsonResponse(201, ['ok' => true, 'id' => $accountId]);
  }

  jsonResponse(405, ['error' => 'Method not allowed']);
} catch (PDOException $e) {
  internalErrorResponse('Database request failed', $e, 503);
} catch (Throwable $e) {
  internalErrorResponse('Unhandled request error', $e, 500);
}
