<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

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
  echo json_encode(['items' => $stmt->fetchAll()]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $input = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

  $allowedProviders = ['mailcow', 'google', 'outlook'];
  $allowedDirections = ['two_way', 'a_to_b', 'b_to_a'];
  if (!in_array($input['provider_a'] ?? '', $allowedProviders, true) || !in_array($input['provider_b'] ?? '', $allowedProviders, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Unsupported provider']);
    exit;
  }
  if (!in_array($input['sync_direction'] ?? '', $allowedDirections, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Unsupported sync direction']);
    exit;
  }

  $hashedSecret = password_hash((string)$input['mailcow_secret'], PASSWORD_ARGON2ID);

  $stmt = $pdo->prepare('INSERT INTO calendar_sync_links (owner, name, email_a, email_b, provider_a, provider_b, sync_direction, secret_hash, status) VALUES (:owner, :name, :email_a, :email_b, :provider_a, :provider_b, :sync_direction, :secret_hash, :status)');
  $stmt->execute([
    'owner' => $_SESSION['mailcow_user'],
    'name' => mb_substr((string)$input['name'], 0, 120),
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
