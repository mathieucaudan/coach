<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!current_user()) redirect('index.php?page=login'); }
function require_role(string $role): void { require_login(); if (current_user()['role'] !== $role) { http_response_code(403); exit('Accès refusé'); } }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(400); exit('Token CSRF invalide'); } }

function session_types(): array
{
    return [
        'récupération',
        'séance spécifique',
        'compétition',
        'volume',
        'technique',
        'renforcement',
        'aérobie',
        'résistance',
    ];
}
function type_class(string $type): string { return 'type-' . str_replace([' ', 'é'], ['-', 'e'], $type); }

function month_start(?string $month): DateTime {
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) return new DateTime($month . '-01');
    return new DateTime(date('Y-m-01'));
}

function athlete_for_user(int $userId): ?array {
    $stmt = db()->prepare('SELECT * FROM athletes WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function can_access_athlete(int $athleteId): bool {
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'coach') {
        $stmt = db()->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
        $stmt->execute([$athleteId, $user['id']]);
        return (bool)$stmt->fetch();
    }
    $athlete = athlete_for_user((int)$user['id']);
    return $athlete && (int)$athlete['id'] === $athleteId;
}

function get_session_checked(int $sessionId): array {
    $stmt = db()->prepare('SELECT s.*, a.first_name, a.last_name, a.vma FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE s.id=?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session || !can_access_athlete((int)$session['athlete_id'])) { http_response_code(404); exit('Séance introuvable'); }
    return $session;
}

function upload_attachment(): ?string {
    if (empty($_FILES['attachment']['name'])) return null;
    if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) return null;
    $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx'];
    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    move_uploaded_file($_FILES['attachment']['tmp_name'], __DIR__ . '/uploads/' . $name);
    return 'uploads/' . $name;
}

function session_statuses(): array {
    return [
        'planned' => 'Planifiee',
        'done' => 'Realisee',
        'missed' => 'Manquee',
        'cancelled' => 'Annulee',
    ];
}

function intensities(): array {
    return [
        'low' => 'Faible',
        'moderate' => 'Moderee',
        'high' => 'Forte',
        'max' => 'Maximale',
    ];
}

function status_label(?string $status): string {
    $statuses = session_statuses();
    return $statuses[$status ?: 'planned'] ?? $statuses['planned'];
}

function intensity_label(?string $intensity): string {
    $intensities = intensities();
    return $intensities[$intensity ?: 'moderate'] ?? $intensities['moderate'];
}

function pace_from_vma(float $vma, float $percent): ?float {
    if ($vma <= 0 || $percent <= 0) return null;
    return 60 / ($vma * $percent / 100);
}

function format_pace(?float $minutesPerKm): string {
    if (!$minutesPerKm || $minutesPerKm <= 0) return '-';
    $seconds = (int)round($minutesPerKm * 60);
    return floor($seconds / 60) . "'" . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . '/km';
}

function format_split(?float $minutesPerKm, float $distanceKm): string {
    if (!$minutesPerKm || $minutesPerKm <= 0) return '-';
    $seconds = (int)round($minutesPerKm * $distanceKm * 60);
    if ($seconds < 60) return $seconds . 's';
    return floor($seconds / 60) . "'" . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function week_bounds(): array {
    $start = new DateTime('monday this week');
    $end = (clone $start)->modify('+6 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}
