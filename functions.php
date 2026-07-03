<?php
$debugEnabled = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($debugEnabled) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit($debugEnabled ? 'config.php manquant dans public_html.' : 'Configuration manquante.');
}

require_once __DIR__ . '/config.php';

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Coach Training Planner');
}

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $requiredConfigConstant) {
    if (!defined($requiredConfigConstant)) {
        http_response_code(500);
        exit($debugEnabled ? 'Constante manquante dans config.php : ' . $requiredConfigConstant : 'Configuration incomplete.');
    }
}

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
function redirect(string $url) { header('Location: ' . $url); exit; }
function current_user() { return $_SESSION['user'] ?? null; }
function require_login() { if (!current_user()) redirect('index.php?page=login'); }
function require_role(string $role) { require_login(); if (current_user()['role'] !== $role) { http_response_code(403); exit('Accès refusé'); } }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf() { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(400); exit('Token CSRF invalide'); } }

function table_has_column(string $table, string $column): bool {
    $knownColumns = [
        'users' => ['id', 'name', 'email', 'password_hash', 'role', 'created_at'],
        'athletes' => ['id', 'coach_id', 'user_id', 'first_name', 'last_name', 'email', 'created_at'],
        'sessions' => ['id', 'athlete_id', 'coach_id', 'date', 'title', 'type', 'description', 'objective', 'warmup', 'main_workout', 'cooldown', 'coach_notes', 'attachment_url', 'external_link', 'created_at', 'updated_at'],
        'comments' => ['id', 'session_id', 'user_id', 'content', 'created_at'],
    ];
    if (in_array($column, $knownColumns[$table] ?? [], true)) return true;

    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function sql_optional(string $table, string $alias, string $column, string $fallback): string {
    return table_has_column($table, $column) ? $alias . '.' . $column : $fallback;
}

function athlete_select_sql(string $alias = 'a'): string {
    return implode(', ', [
        "$alias.id",
        "$alias.coach_id",
        "$alias.user_id",
        "$alias.first_name",
        "$alias.last_name",
        "$alias.email",
        "$alias.created_at",
        sql_optional('athletes', $alias, 'sport', "'Course'") . ' AS sport',
        sql_optional('athletes', $alias, 'level', "'Intermediaire'") . ' AS level',
        sql_optional('athletes', $alias, 'goal', "''") . ' AS goal',
        sql_optional('athletes', $alias, 'vma', '15') . ' AS vma',
        sql_optional('athletes', $alias, 'notes', "''") . ' AS notes',
    ]);
}

function session_select_sql(string $alias = 's'): string {
    return implode(', ', [
        "$alias.id",
        "$alias.athlete_id",
        "$alias.coach_id",
        "$alias.date",
        "$alias.title",
        "$alias.type",
        "$alias.description",
        "$alias.objective",
        "$alias.warmup",
        "$alias.main_workout",
        "$alias.cooldown",
        "$alias.coach_notes",
        "$alias.attachment_url",
        "$alias.external_link",
        "$alias.created_at",
        "$alias.updated_at",
        sql_optional('sessions', $alias, 'status', "'planned'") . ' AS status',
        sql_optional('sessions', $alias, 'intensity', "'moderate'") . ' AS intensity',
        sql_optional('sessions', $alias, 'duration_min', 'NULL') . ' AS duration_min',
        sql_optional('sessions', $alias, 'vma_percent', 'NULL') . ' AS vma_percent',
        sql_optional('sessions', $alias, 'actual_duration_min', 'NULL') . ' AS actual_duration_min',
        sql_optional('sessions', $alias, 'feeling', 'NULL') . ' AS feeling',
        sql_optional('sessions', $alias, 'pain', 'NULL') . ' AS pain',
        sql_optional('sessions', $alias, 'athlete_feedback', "''") . ' AS athlete_feedback',
    ]);
}

function filter_existing_columns(string $table, array $values): array {
    return array_filter(
        $values,
        function ($column) use ($table) { return table_has_column($table, (string)$column); },
        ARRAY_FILTER_USE_KEY
    );
}

function db_insert(string $table, array $values): int {
    $values = filter_existing_columns($table, $values);
    if (!$values) return 0;

    $columns = array_keys($values);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . $placeholders . ')';
    db()->prepare($sql)->execute(array_values($values));
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $values, string $where, array $whereParams) {
    $values = filter_existing_columns($table, $values);
    if (!$values) return;

    $sets = array_map(function ($column) { return $column . '=?'; }, array_keys($values));
    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    db()->prepare($sql)->execute(array_merge(array_values($values), $whereParams));
}

function nullable_int($value) {
    return $value === '' || $value === null ? null : (int)$value;
}

function nullable_float($value) {
    return $value === '' || $value === null ? null : (float)$value;
}

function logout_user() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

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

function month_start($month): DateTime {
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) return new DateTime($month . '-01');
    return new DateTime(date('Y-m-01'));
}

function athlete_for_user(int $userId) {
    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.user_id = ?');
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
    $stmt = db()->prepare(
        'SELECT ' . session_select_sql('s') . ', a.first_name, a.last_name, ' .
        sql_optional('athletes', 'a', 'vma', '15') . ' AS vma ' .
        'FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE s.id=?'
    );
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session || !can_access_athlete((int)$session['athlete_id'])) { http_response_code(404); exit('Séance introuvable'); }
    return $session;
}

function upload_attachment() {
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

function status_label($status): string {
    $statuses = session_statuses();
    return $statuses[$status ?: 'planned'] ?? $statuses['planned'];
}

function intensity_label($intensity): string {
    $intensities = intensities();
    return $intensities[$intensity ?: 'moderate'] ?? $intensities['moderate'];
}

function pace_from_vma(float $vma, float $percent) {
    if ($vma <= 0 || $percent <= 0) return null;
    return 60 / ($vma * $percent / 100);
}

function format_pace($minutesPerKm): string {
    if (!$minutesPerKm || $minutesPerKm <= 0) return '-';
    $seconds = (int)round($minutesPerKm * 60);
    return floor($seconds / 60) . "'" . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . '/km';
}

function format_split($minutesPerKm, float $distanceKm): string {
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

function format_short_date($date): string {
    if (!$date) return '-';
    return (new DateTime($date))->format('d/m');
}

function format_full_date($date): string {
    if (!$date) return '-';
    return (new DateTime($date))->format('d/m/Y');
}

function relative_day_label($date): string {
    if (!$date) return '-';

    $today = new DateTime(date('Y-m-d'));
    $target = new DateTime($date);
    $diff = (int)$today->diff($target)->format('%r%a');

    if ($diff === 0) return "Aujourd'hui";
    if ($diff === 1) return 'Demain';
    if ($diff === -1) return 'Hier';
    if ($diff > 1) return 'Dans ' . $diff . ' jours';
    return 'Il y a ' . abs($diff) . ' jours';
}
