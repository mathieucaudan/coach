<?php
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Configuration manquante.');
}

require_once __DIR__ . '/config.php';

// Debug output is opt-in from config.php only, never from the URL.
$debugEnabled = defined('APP_DEBUG') && APP_DEBUG;
if ($debugEnabled) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Paris');

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
        // Keep CURDATE()/NOW() on the same clock as PHP date().
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url) { header('Location: ' . $url); exit; }
function current_user() { return $_SESSION['user'] ?? null; }
function is_super_admin(): bool { return (current_user()['role'] ?? null) === 'super_admin'; }
function require_login() {
    if (current_user()) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['page'])) {
        $_SESSION['after_login'] = 'index.php?' . http_build_query($_GET);
    }
    redirect('index.php?page=login');
}
function require_role(string $role) {
    require_login();
    $currentRole = current_user()['role'];
    if ($currentRole === $role || ($role === 'coach' && $currentRole === 'super_admin')) return;
    deny_access();
}

function error_page(string $message, int $status = 400): void {
    $user = current_user();
    $home = $user ? 'index.php' : 'index.php?page=login';
    $card = '<section class="card error-card">'
        . '<h1>' . e($message) . '</h1>'
        . '<p class="muted-text">Tes saisies sont normalement conservées si tu reviens à la page précédente.</p>'
        . '<div class="actions">'
        . '<a class="btn" href="javascript:history.back()">Revenir en arrière</a>'
        . '<a class="btn secondary" href="' . e($home) . '">Accueil</a>'
        . '</div></section>';

    // Part of a page is already out: close it with the card instead of a second document.
    if (headers_sent()) {
        echo $card . '</main></body></html>';
        exit;
    }

    http_response_code($status);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($message) . ' - ' . e(APP_NAME) . '</title>'
        . '<link rel="stylesheet" href="assets/style.css"></head><body>'
        . '<main class="container">' . $card . '</main></body></html>';
    exit;
}

function deny_access(): void { error_page('Accès refusé', 403); }

// Logs the real error server-side and shows the user a message without SQL details.
function handle_fatal_exception(Throwable $e): void {
    try {
        if (db()->inTransaction()) db()->rollBack();
    } catch (Throwable $ignored) {
    }

    if ($e instanceof InvalidArgumentException) {
        error_page($e->getMessage(), 400);
    }

    error_log((string)$e);
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
        error_page('Cette valeur existe déjà (par exemple un email déjà utilisé).', 409);
    }
    error_page('Une erreur est survenue. Réessaie dans un instant.', 500);
}

// The CSRF token is mirrored in a cookie so it survives an expired PHP session
// (remember-me restores the user, the posted token still matches).
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $cookie = (string)($_COOKIE['csrf_token'] ?? '');
        $_SESSION['csrf'] = preg_match('/^[a-f0-9]{64}$/', $cookie) ? $cookie : bin2hex(random_bytes(32));
    }
    if (($_COOKIE['csrf_token'] ?? '') !== $_SESSION['csrf'] && !headers_sent()) {
        setcookie('csrf_token', $_SESSION['csrf'], remember_cookie_options(time() + 60 * 60 * 24 * 30));
        $_COOKIE['csrf_token'] = $_SESSION['csrf'];
    }
    return $_SESSION['csrf'];
}
function verify_csrf() {
    $posted = (string)($_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['csrf'] ?? ($_COOKIE['csrf_token'] ?? ''));
    if ($expected === '' || !hash_equals($expected, $posted)) {
        error_page('Ta session a expiré. Reconnecte-toi puis réessaie.', 400);
    }
}

// One INFORMATION_SCHEMA query per request; migrations call it with $reset after DDL.
function db_schema(bool $reset = false): array {
    static $schema = null;
    if ($schema !== null && !$reset) return $schema;

    $schema = [];
    try {
        $stmt = db()->query('SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($stmt->fetchAll() as $row) {
            $schema[strtolower($row['TABLE_NAME'])][strtolower($row['COLUMN_NAME'])] = true;
        }
    } catch (Throwable $e) {
        $schema = [];
    }

    // If INFORMATION_SCHEMA is unreadable, assume the base schema rather than none.
    if (!$schema) {
        $base = [
            'users' => ['id', 'name', 'email', 'password_hash', 'role', 'created_at'],
            'athletes' => ['id', 'coach_id', 'user_id', 'first_name', 'last_name', 'email', 'created_at'],
            'sessions' => ['id', 'athlete_id', 'coach_id', 'date', 'title', 'type', 'description', 'objective', 'warmup', 'main_workout', 'cooldown', 'coach_notes', 'attachment_url', 'external_link', 'created_at', 'updated_at'],
            'comments' => ['id', 'session_id', 'user_id', 'content', 'created_at'],
        ];
        foreach ($base as $table => $columns) {
            $schema[$table] = array_fill_keys($columns, true);
        }
    }
    return $schema;
}

function table_has_column(string $table, string $column): bool {
    return isset(db_schema()[strtolower($table)][strtolower($column)]);
}

function table_exists(string $table): bool {
    return isset(db_schema()[strtolower($table)]);
}

function user_role_needs_migration(): bool {
    try {
        $stmt = db()->prepare('SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "users" AND COLUMN_NAME = "role" LIMIT 1');
        $stmt->execute();
        $column = $stmt->fetch();
        if (!$column) return false;

        if ($column['DATA_TYPE'] === 'enum') {
            return strpos((string)$column['COLUMN_TYPE'], 'super_admin') === false;
        }

        return $column['DATA_TYPE'] !== 'varchar' || (int)$column['CHARACTER_MAXIMUM_LENGTH'] < 32;
    } catch (Throwable $e) {
        return false;
    }
}

function column_data_type(string $table, string $column): ?string {
    try {
        $stmt = db()->prepare('SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : strtolower((string)$type);
    } catch (Throwable $e) {
        return null;
    }
}

function add_column_if_missing(string $table, string $column, string $definition, array &$applied): void {
    if (table_has_column($table, $column)) return;

    db()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    db_schema(true);
    $applied[] = $table . '.' . $column;
}

function index_exists(string $table, string $index): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function add_index_if_missing(string $table, string $index, string $columns, array &$applied): void {
    if (!table_exists($table) || index_exists($table, $index)) return;
    try {
        db()->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $index . ' (' . $columns . ')');
        $applied[] = $table . '.' . $index;
    } catch (Throwable $e) {
        // A missing index only costs speed; never block the other migrations for it.
    }
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
        sql_optional('sessions', $alias, 'planned_distance_km', 'NULL') . ' AS planned_distance_km',
        sql_optional('sessions', $alias, 'actual_distance_km', 'NULL') . ' AS actual_distance_km',
        sql_optional('sessions', $alias, 'target_pace_code', "''") . ' AS target_pace_code',
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

function login_user(array $user): void {
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'auth_v' => 2
    ];
}

// Reloads the session user from the database so deleted or demoted accounts lose access.
// Sessions without auth_v come from the old remember-me code (token id stored as user id):
// they are dropped, and remember-me logs the user back in correctly.
function refresh_session_user(): void {
    $user = current_user();
    if (!$user) return;

    if (($user['auth_v'] ?? null) !== 2) {
        unset($_SESSION['user']);
        return;
    }

    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['user']);
        return;
    }

    $_SESSION['user']['name'] = $row['name'];
    $_SESSION['user']['email'] = $row['email'];
    $_SESSION['user']['role'] = $row['role'];
}

function remember_cookie_options(int $expires): array {
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function remember_user(int $userId): void {
    if (!table_exists('auth_tokens')) return;

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + 60 * 60 * 24 * 30;

    db()->prepare('INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at, created_at) VALUES (?,?,?,?,NOW())')
        ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);

    setcookie('remember_login', $selector . ':' . $validator, remember_cookie_options($expires));
}

function clear_remember_token(): void {
    if (!empty($_COOKIE['remember_login']) && table_exists('auth_tokens')) {
        [$selector] = array_pad(explode(':', $_COOKIE['remember_login'], 2), 2, null);
        if ($selector) {
            db()->prepare('DELETE FROM auth_tokens WHERE selector=?')->execute([$selector]);
        }
    }

    setcookie('remember_login', '', remember_cookie_options(time() - 3600));
}

function restore_remembered_login(): void {
    if (current_user() || empty($_COOKIE['remember_login']) || !table_exists('auth_tokens')) return;

    [$selector, $validator] = array_pad(explode(':', $_COOKIE['remember_login'], 2), 2, null);
    if (!$selector || !$validator) {
        clear_remember_token();
        return;
    }

    $stmt = db()->prepare(
        'SELECT t.*, u.name, u.email, u.role
         FROM auth_tokens t
         JOIN users u ON u.id=t.user_id
         WHERE t.selector=? AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$selector]);
    $token = $stmt->fetch();

    if (!$token || !hash_equals((string)$token['validator_hash'], hash('sha256', $validator))) {
        clear_remember_token();
        return;
    }

    // No rotation on use: on flaky mobile networks a lost response would leave the phone
    // with a dead token and log the athlete out. Tokens are revoked on password change instead.
    login_user(['id' => $token['user_id']] + $token);
}

function purge_expired_remember_tokens(): void {
    if (!table_exists('auth_tokens')) return;
    db()->exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
}

function nullable_int($value) {
    return $value === '' || $value === null ? null : (int)$value;
}

// Accepts the French decimal comma ("4,5") as well as "4.5".
function nullable_float($value) {
    if ($value === null) return null;
    $value = str_replace([',', ' '], ['.', ''], trim((string)$value));
    return $value === '' || !is_numeric($value) ? null : (float)$value;
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function valid_date(string $date): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function require_valid_date($date): string {
    $date = (string)$date;
    if (!valid_date($date)) throw new InvalidArgumentException('Date invalide.');
    return $date;
}

// Only http(s) links may be stored, so a javascript: URL can never reach an href.
function clean_external_link($link): ?string {
    $link = trim((string)$link);
    if ($link === '') return null;
    if (!preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
    return filter_var($link, FILTER_VALIDATE_URL) ? $link : null;
}

function validate_password(string $password): void {
    if (mb_strlen($password) < 8) {
        throw new InvalidArgumentException('Le mot de passe doit contenir au moins 8 caractères.');
    }
}

function email_taken(string $email, int $exceptUserId = 0): bool {
    $stmt = db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
    $stmt->execute([$email, $exceptUserId]);
    return (bool)$stmt->fetch();
}

// Failed logins are counted per email and per IP in login_attempts (created by migrations):
// 10 failures in 15 minutes block further attempts.
function login_attempts_ready(): bool {
    return table_exists('login_attempts');
}

function too_many_login_attempts(string $email): bool {
    if (!login_attempts_ready()) return false;
    $stmt = db()->prepare(
        'SELECT
            SUM(email=?) AS by_email,
            SUM(ip=?) AS by_ip
         FROM login_attempts
         WHERE (email=? OR ip=?) AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([strtolower($email), client_ip(), strtolower($email), client_ip()]);
    $counts = $stmt->fetch();
    // A club Wi-Fi or mobile carrier shares one IP between many people: allow far more per IP.
    return (int)($counts['by_email'] ?? 0) >= 10 || (int)($counts['by_ip'] ?? 0) >= 50;
}

function record_failed_login(string $email): void {
    if (!login_attempts_ready()) return;
    db()->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?,?,NOW())')
        ->execute([strtolower($email), client_ip()]);
}

function clear_login_attempts(string $email): void {
    if (!login_attempts_ready()) return;
    db()->prepare('DELETE FROM login_attempts WHERE email=? OR (ip=? AND email=?) OR attempted_at < (NOW() - INTERVAL 1 DAY)')
        ->execute([strtolower($email), client_ip(), strtolower($email)]);
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function weather_options(): array {
    return [
        'rain' => ['label' => 'Pluie', 'icon' => '☔'],
        'wet_surface' => ['label' => 'Revêtement humide', 'icon' => '≈'],
        'wind' => ['label' => 'Vent', 'icon' => '↝'],
        'sunny' => ['label' => 'Soleil', 'icon' => '☀'],
        'cloudy' => ['label' => 'Nuageux', 'icon' => '☁'],
    ];
}

function default_pace_presets(): array {
    return [
        ['code' => 'VMA105', 'label' => 'VMA 105%', 'percent_vma' => 105],
        ['code' => 'VMA100', 'label' => 'VMA 100%', 'percent_vma' => 100],
        ['code' => 'AS3', 'label' => 'AS3', 'percent_vma' => 98.45],
        ['code' => 'AS5', 'label' => 'AS5', 'percent_vma' => 94],
        ['code' => 'AS10', 'label' => 'AS10', 'percent_vma' => 93.1],
        ['code' => 'AS21', 'label' => 'AS21', 'percent_vma' => 88.25],
        ['code' => 'seuil2', 'label' => 'Seuil 2', 'percent_vma' => 87],
        ['code' => 'seuil1', 'label' => 'Seuil 1 / EA', 'percent_vma' => 74.2],
        ['code' => 'EF', 'label' => 'Endurance fondamentale', 'percent_vma' => 70],
        ['code' => 'recup', 'label' => 'Endurance récupération', 'percent_vma' => 67],
    ];
}

function athlete_paces_ready(): bool {
    return table_exists('athlete_paces');
}

function get_athlete_paces(int $athleteId): array {
    if (!athlete_paces_ready()) return default_pace_presets();

    $stmt = db()->prepare('SELECT code, label, percent_vma FROM athlete_paces WHERE athlete_id=? ORDER BY sort_order, id');
    $stmt->execute([$athleteId]);
    $paces = $stmt->fetchAll();
    return $paces ?: default_pace_presets();
}

function pace_percent_for_athlete(int $athleteId, string $code) {
    foreach (get_athlete_paces($athleteId) as $pace) {
        if ($pace['code'] === $code) return (float)$pace['percent_vma'];
    }
    return null;
}

function sync_athlete_paces(int $athleteId, array $paces): void {
    if (!athlete_paces_ready()) return;

    db()->prepare('DELETE FROM athlete_paces WHERE athlete_id=?')->execute([$athleteId]);

    $insert = db()->prepare(
        'INSERT INTO athlete_paces (athlete_id, code, label, percent_vma, sort_order, created_at, updated_at)
         VALUES (?,?,?,?,?,NOW(),NOW())'
    );

    $order = 0;
    foreach ($paces as $pace) {
        $code = trim((string)($pace['code'] ?? ''));
        $label = trim((string)($pace['label'] ?? ''));
        $percent = nullable_float($pace['percent_vma'] ?? null);
        if ($code === '' || $label === '' || $percent === null) continue;
        $insert->execute([$athleteId, $code, $label, max(40, min(130, $percent)), $order++]);
    }
}

function ensure_athlete_paces(int $athleteId): void {
    if (!athlete_paces_ready()) return;

    $stmt = db()->prepare('SELECT COUNT(*) FROM athlete_paces WHERE athlete_id=?');
    $stmt->execute([$athleteId]);
    if ((int)$stmt->fetchColumn() > 0) return;

    sync_athlete_paces($athleteId, default_pace_presets());
}

function seed_missing_athlete_paces(): void {
    if (!athlete_paces_ready() || !table_exists('athletes')) return;

    $stmt = db()->query('SELECT id FROM athletes ORDER BY id');
    foreach ($stmt->fetchAll() as $athlete) {
        ensure_athlete_paces((int)$athlete['id']);
    }
}

function session_field_options(): array {
    return [
        'type' => 'Type',
        'status' => 'Statut',
        'intensity' => 'Intensité',
        'planned_distance_km' => 'Volume total prévu (km)',
        'target_pace_code' => 'Rythme visé',
        'actual_distance_km' => 'Volume réalisé (km)',
        'description' => 'Contenu de séance',
        'warmup' => 'Échauffement',
        'main_workout' => 'Corps de séance',
        'coach_notes' => 'Conseils du coach',
        'duration_min' => 'Durée prévue (min)',
        'actual_duration_min' => 'Durée réelle (min)',
        'vma_percent' => '% VMA cible',
        'external_link' => 'Lien optionnel',
        'feeling' => 'Ressenti athlète / 10',
        'pain' => 'Douleur / fatigue / 10',
        'athlete_feedback' => 'Retour athlète',
        'attachment' => 'Pièce jointe',
    ];
}

function default_session_field_visibility(): array {
    return [
        'type' => true,
        'status' => true,
        'intensity' => true,
        'planned_distance_km' => true,
        'target_pace_code' => true,
        'actual_distance_km' => false,
        'description' => true,
        'warmup' => false,
        'main_workout' => false,
        'coach_notes' => false,
        'duration_min' => false,
        'actual_duration_min' => false,
        'vma_percent' => false,
        'external_link' => false,
        'feeling' => false,
        'pain' => false,
        'athlete_feedback' => false,
        'attachment' => false,
    ];
}

function coach_session_field_settings_ready(): bool {
    return table_exists('coach_session_field_settings');
}

function get_coach_session_field_visibility(int $coachId): array {
    $visibility = default_session_field_visibility();
    if (!coach_session_field_settings_ready()) return $visibility;

    $stmt = db()->prepare('SELECT field_key, visible FROM coach_session_field_settings WHERE coach_id=?');
    $stmt->execute([$coachId]);
    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists($row['field_key'], $visibility)) {
            $visibility[$row['field_key']] = (bool)$row['visible'];
        }
    }

    return $visibility;
}

function save_coach_session_field_visibility(int $coachId, array $visibleFields): void {
    if (!coach_session_field_settings_ready()) return;

    $allowed = array_keys(session_field_options());
    $visibleMap = array_fill_keys(array_values($visibleFields), true);
    $stmt = db()->prepare(
        'INSERT INTO coach_session_field_settings (coach_id, field_key, visible, updated_at)
         VALUES (?,?,?,NOW())
         ON DUPLICATE KEY UPDATE visible=VALUES(visible), updated_at=NOW()'
    );

    foreach ($allowed as $fieldKey) {
        $stmt->execute([$coachId, $fieldKey, isset($visibleMap[$fieldKey]) ? 1 : 0]);
    }
}

function empty_debrief(): array {
    return [
        'id' => null,
        'session_id' => null,
        'result' => '',
        'difficulty' => null,
        'sensations' => '',
        'weather' => [],
        'temperature_c' => null,
        'lactates' => '',
        'actual_distance_km' => null,
        'created_at' => null,
        'updated_at' => null,
        'exists' => false,
    ];
}

function normalize_debrief(array $row): array {
    $weather = [];
    if (!empty($row['weather'])) {
        $decoded = json_decode((string)$row['weather'], true);
        $weather = is_array($decoded) ? array_values(array_intersect(array_keys(weather_options()), $decoded)) : [];
    }

    return [
        'id' => $row['id'] ?? null,
        'session_id' => $row['session_id'] ?? null,
        'result' => $row['result'] ?? '',
        'difficulty' => isset($row['difficulty']) ? (int)$row['difficulty'] : null,
        'sensations' => $row['sensations'] ?? '',
        'weather' => $weather,
        'temperature_c' => $row['temperature_c'] ?? null,
        'lactates' => $row['lactates'] ?? '',
        'actual_distance_km' => $row['actual_distance_km'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'exists' => true,
    ];
}

function get_debrief_for_session(int $sessionId): array {
    if (!table_exists('session_debriefs')) return empty_debrief();

    $stmt = db()->prepare('SELECT * FROM session_debriefs WHERE session_id=? LIMIT 1');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ? normalize_debrief($row) : empty_debrief();
}

function normalize_daily_debrief(array $row): array {
    $debrief = normalize_debrief($row);
    $debrief['athlete_id'] = $row['athlete_id'] ?? null;
    $debrief['date'] = $row['date'] ?? null;
    return $debrief;
}

function get_daily_debrief(int $athleteId, string $date): array {
    if (!table_exists('daily_debriefs')) return empty_debrief();
    if (!can_access_athlete($athleteId)) {
        deny_access();
    }

    $stmt = db()->prepare('SELECT * FROM daily_debriefs WHERE athlete_id=? AND date=? LIMIT 1');
    $stmt->execute([$athleteId, $date]);
    $row = $stmt->fetch();
    return $row ? normalize_daily_debrief($row) : empty_debrief();
}

function attach_daily_debriefs_to_calendar(int $athleteId, DateTime $start, DateTime $end): array {
    if (!table_exists('daily_debriefs')) return [];

    $stmt = db()->prepare('SELECT * FROM daily_debriefs WHERE athlete_id=? AND date BETWEEN ? AND ?');
    $stmt->execute([$athleteId, $start->format('Y-m-d'), $end->format('Y-m-d')]);

    $dailyDebriefs = [];
    foreach ($stmt->fetchAll() as $row) {
        $dailyDebriefs[$row['date']] = normalize_daily_debrief($row);
    }

    return $dailyDebriefs;
}

function attach_debriefs_to_sessions(array $sessions): array {
    if (!$sessions || !table_exists('session_debriefs')) return $sessions;

    $ids = array_values(array_filter(array_map(function ($session) {
        return (int)($session['id'] ?? 0);
    }, $sessions)));
    if (!$ids) return $sessions;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT * FROM session_debriefs WHERE session_id IN (' . $placeholders . ')');
    $stmt->execute($ids);

    $debriefs = [];
    foreach ($stmt->fetchAll() as $row) {
        $debriefs[(int)$row['session_id']] = normalize_debrief($row);
    }

    foreach ($sessions as &$session) {
        $session['debrief'] = $debriefs[(int)$session['id']] ?? empty_debrief();
    }
    unset($session);

    return $sessions;
}

function debrief_summary_status(array $session): string {
    if (!empty($session['debrief']['exists'])) return 'debriefed';
    if (($session['date'] ?? '') < date('Y-m-d')) return 'missing';
    return 'upcoming';
}

function debrief_status_label(string $status): string {
    return [
        'debriefed' => 'Débriefé',
        'missing' => 'Débrief à compléter',
        'upcoming' => 'Prévue',
    ][$status] ?? 'Prévue';
}

function save_session_debrief(array $session, array $post): void {
    if (!table_exists('session_debriefs')) {
        throw new RuntimeException('Migration session_debriefs manquante.');
    }

    $user = current_user();
    if (!$user || $user['role'] !== 'athlete') {
        error_page('Seul le compte athlète peut modifier le débrief.', 403);
    }

    $athlete = athlete_for_user((int)$user['id']);
    if (!$athlete || (int)$athlete['id'] !== (int)$session['athlete_id']) {
        deny_access();
    }

    $payload = parse_debrief_payload($post);

    $hasDistance = table_has_column('session_debriefs', 'actual_distance_km');
    $columns = 'session_id,result,difficulty,sensations,weather,temperature_c,lactates';
    $placeholders = '?,?,?,?,?,?,?';
    $updates = 'result=VALUES(result), difficulty=VALUES(difficulty), sensations=VALUES(sensations),
         weather=VALUES(weather), temperature_c=VALUES(temperature_c), lactates=VALUES(lactates)';
    $values = [
        (int)$session['id'],
        $payload['result'],
        $payload['difficulty'],
        $payload['sensations'],
        $payload['weather'],
        $payload['temperature_c'],
        $payload['lactates'],
    ];

    if ($hasDistance) {
        $columns .= ',actual_distance_km';
        $placeholders .= ',?';
        $updates .= ', actual_distance_km=VALUES(actual_distance_km)';
        $values[] = $payload['actual_distance_km'];
    }

    db()->prepare(
        'INSERT INTO session_debriefs (' . $columns . ',created_at,updated_at)
         VALUES (' . $placeholders . ',NOW(),NOW())
         ON DUPLICATE KEY UPDATE ' . $updates . ', updated_at=NOW()'
    )->execute($values);
}

function assert_athlete_owns_athlete_id(int $athleteId): void {
    $user = current_user();
    if (!$user || $user['role'] !== 'athlete') {
        error_page('Seul le compte athlète peut modifier ce retour.', 403);
    }

    $athlete = athlete_for_user((int)$user['id']);
    if (!$athlete || (int)$athlete['id'] !== $athleteId) {
        deny_access();
    }
}

function parse_debrief_payload(array $post): array {
    $weather = array_values(array_intersect(array_keys(weather_options()), $post['weather'] ?? []));
    $difficulty = nullable_int($post['difficulty'] ?? null);
    if ($difficulty !== null) $difficulty = max(1, min(10, $difficulty));

    $temperature = $post['temperature_c'] ?? null;
    $temperature = ($temperature === '' || $temperature === null) ? null : max(-30, min(55, (float)$temperature));
    $actualDistance = nullable_float($post['actual_distance_km'] ?? null);

    return [
        'result' => trim((string)($post['result'] ?? '')),
        'difficulty' => $difficulty,
        'sensations' => trim((string)($post['sensations'] ?? '')),
        'weather' => json_encode($weather, JSON_UNESCAPED_UNICODE),
        'temperature_c' => $temperature,
        'lactates' => trim((string)($post['lactates'] ?? '')),
        'actual_distance_km' => $actualDistance === null ? null : max(0, $actualDistance),
    ];
}

function save_daily_debrief(int $athleteId, string $date, array $post): void {
    if (!table_exists('daily_debriefs')) {
        throw new RuntimeException('Migration daily_debriefs manquante.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Date invalide.');
    }

    assert_athlete_owns_athlete_id($athleteId);
    $payload = parse_debrief_payload($post);

    $hasDistance = table_has_column('daily_debriefs', 'actual_distance_km');
    $columns = 'athlete_id,date,result,difficulty,sensations,weather,temperature_c,lactates';
    $placeholders = '?,?,?,?,?,?,?,?';
    $updates = 'result=VALUES(result), difficulty=VALUES(difficulty), sensations=VALUES(sensations),
         weather=VALUES(weather), temperature_c=VALUES(temperature_c), lactates=VALUES(lactates)';
    $values = [
        $athleteId,
        $date,
        $payload['result'],
        $payload['difficulty'],
        $payload['sensations'],
        $payload['weather'],
        $payload['temperature_c'],
        $payload['lactates'],
    ];

    if ($hasDistance) {
        $columns .= ',actual_distance_km';
        $placeholders .= ',?';
        $updates .= ', actual_distance_km=VALUES(actual_distance_km)';
        $values[] = $payload['actual_distance_km'];
    }

    db()->prepare(
        'INSERT INTO daily_debriefs (' . $columns . ',created_at,updated_at)
         VALUES (' . $placeholders . ',NOW(),NOW())
         ON DUPLICATE KEY UPDATE ' . $updates . ', updated_at=NOW()'
    )->execute($values);
}

function create_quick_session(int $athleteId, string $date, string $title, string $description): int {
    $user = current_user();
    if (!$user || !in_array($user['role'], ['coach', 'super_admin'], true) || !can_access_athlete($athleteId)) {
        deny_access();
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Date invalide.');
    }

    $values = [
        'athlete_id' => $athleteId,
        'coach_id' => $user['id'],
        'date' => $date,
        'title' => trim($title) ?: 'Séance',
        'type' => 'footing',
        'status' => 'planned',
        'intensity' => 'moderate',
        'description' => trim($description),
        'objective' => '',
        'warmup' => '',
        'main_workout' => '',
        'cooldown' => '',
        'coach_notes' => '',
    ];

    return db_insert('sessions', array_filter(
        $values,
        function ($column) { return table_has_column('sessions', (string)$column); },
        ARRAY_FILTER_USE_KEY
    ));
}

function run_pending_migrations(): array {
    $applied = [];

    if (user_role_needs_migration()) {
        try {
            db()->exec("ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL DEFAULT 'athlete'");
            $applied[] = 'users_role';
        } catch (Throwable $e) {
            // Some hosts restrict ALTER; existing VARCHAR schemas can continue without this step.
        }
    }

    if (!table_exists('auth_tokens')) {
        db()->exec(
            'CREATE TABLE auth_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                selector VARCHAR(32) NOT NULL,
                validator_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_auth_tokens_selector (selector),
                KEY idx_auth_tokens_user (user_id),
                KEY idx_auth_tokens_expires (expires_at),
                CONSTRAINT fk_auth_tokens_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        $applied[] = 'auth_tokens';
    }

    if (ensure_configured_super_admin()) {
        $applied[] = 'super_admin_config';
    }

    // No AFTER clauses: older production schemas may lack the anchor columns.
    if (table_exists('athletes')) {
        add_column_if_missing('athletes', 'sport', "VARCHAR(80) NOT NULL DEFAULT 'Course'", $applied);
        add_column_if_missing('athletes', 'level', "VARCHAR(80) NOT NULL DEFAULT 'Intermediaire'", $applied);
        add_column_if_missing('athletes', 'goal', 'TEXT NULL', $applied);
        add_column_if_missing('athletes', 'vma', 'DECIMAL(4,1) NOT NULL DEFAULT 15', $applied);
        add_column_if_missing('athletes', 'notes', 'TEXT NULL', $applied);
    }
    // Legacy schemas declare sessions.type as an ENUM, which silently blanks new types like vélo.
    if (table_exists('sessions') && column_data_type('sessions', 'type') === 'enum') {
        db()->exec("ALTER TABLE sessions MODIFY type VARCHAR(64) NULL DEFAULT 'footing'");
        db_schema(true);
        $applied[] = 'sessions.type';
    }
    if (table_exists('sessions')) {
        add_column_if_missing('sessions', 'status', "VARCHAR(32) NOT NULL DEFAULT 'planned'", $applied);
        add_column_if_missing('sessions', 'intensity', "VARCHAR(32) NOT NULL DEFAULT 'moderate'", $applied);
        add_column_if_missing('sessions', 'duration_min', 'INT NULL', $applied);
        add_column_if_missing('sessions', 'vma_percent', 'DECIMAL(5,2) NULL', $applied);
        add_column_if_missing('sessions', 'planned_distance_km', 'DECIMAL(6,2) NULL', $applied);
        add_column_if_missing('sessions', 'actual_duration_min', 'INT NULL', $applied);
        add_column_if_missing('sessions', 'actual_distance_km', 'DECIMAL(6,2) NULL', $applied);
        add_column_if_missing('sessions', 'target_pace_code', 'VARCHAR(40) NULL', $applied);
        add_column_if_missing('sessions', 'feeling', 'TINYINT NULL', $applied);
        add_column_if_missing('sessions', 'pain', 'TINYINT NULL', $applied);
        add_column_if_missing('sessions', 'athlete_feedback', 'TEXT NULL', $applied);
    }
    if (table_exists('session_debriefs')) {
        add_column_if_missing('session_debriefs', 'actual_distance_km', 'DECIMAL(6,2) NULL', $applied);
    }
    if (table_exists('daily_debriefs')) {
        add_column_if_missing('daily_debriefs', 'actual_distance_km', 'DECIMAL(6,2) NULL', $applied);
    }

    if (!table_exists('athlete_paces')) {
        db()->exec(
            'CREATE TABLE athlete_paces (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                athlete_id INT NOT NULL,
                code VARCHAR(40) NOT NULL,
                label VARCHAR(120) NOT NULL,
                percent_vma DECIMAL(5,2) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_athlete_paces_code (athlete_id, code),
                CONSTRAINT fk_athlete_paces_athlete
                    FOREIGN KEY (athlete_id) REFERENCES athletes(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        $applied[] = 'athlete_paces';
    }
    seed_missing_athlete_paces();

    if (!table_exists('coach_session_field_settings')) {
        db()->exec(
            'CREATE TABLE coach_session_field_settings (
                coach_id INT NOT NULL,
                field_key VARCHAR(80) NOT NULL,
                visible TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (coach_id, field_key),
                CONSTRAINT fk_coach_session_field_settings_coach
                    FOREIGN KEY (coach_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        $applied[] = 'coach_session_field_settings';
    }

    if (!table_exists('athlete_coaches')) {
        db()->exec(
            'CREATE TABLE athlete_coaches (
                athlete_id INT NOT NULL,
                coach_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (athlete_id, coach_id),
                CONSTRAINT fk_athlete_coaches_athlete
                    FOREIGN KEY (athlete_id) REFERENCES athletes(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_athlete_coaches_coach
                    FOREIGN KEY (coach_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        db()->exec(
            'INSERT IGNORE INTO athlete_coaches (athlete_id, coach_id, created_at)
             SELECT id, coach_id, NOW() FROM athletes WHERE coach_id IS NOT NULL'
        );
        $applied[] = 'athlete_coaches';
    }

    if (!table_exists('session_debriefs')) {
        db()->exec(
            'CREATE TABLE session_debriefs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id INT NOT NULL,
                result TEXT NULL,
                difficulty TINYINT UNSIGNED NULL,
                sensations TEXT NULL,
                weather JSON NULL,
                temperature_c DECIMAL(4,1) NULL,
                lactates TEXT NULL,
                actual_distance_km DECIMAL(6,2) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_session_debriefs_session (session_id),
                CONSTRAINT chk_session_debriefs_difficulty CHECK (difficulty IS NULL OR difficulty BETWEEN 1 AND 10),
                CONSTRAINT fk_session_debriefs_session
                    FOREIGN KEY (session_id) REFERENCES sessions(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        $applied[] = 'session_debriefs';
    }

    if (!table_exists('daily_debriefs')) {
        db()->exec(
            'CREATE TABLE daily_debriefs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                athlete_id INT NOT NULL,
                date DATE NOT NULL,
                result TEXT NULL,
                difficulty TINYINT UNSIGNED NULL,
                sensations TEXT NULL,
                weather JSON NULL,
                temperature_c DECIMAL(4,1) NULL,
                lactates TEXT NULL,
                actual_distance_km DECIMAL(6,2) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_daily_debriefs_athlete_date (athlete_id, date),
                CONSTRAINT chk_daily_debriefs_difficulty CHECK (difficulty IS NULL OR difficulty BETWEEN 1 AND 10),
                CONSTRAINT fk_daily_debriefs_athlete
                    FOREIGN KEY (athlete_id) REFERENCES athletes(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        $applied[] = 'daily_debriefs';
    }

    if (!table_exists('login_attempts')) {
        db()->exec(
            'CREATE TABLE login_attempts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                attempted_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_login_attempts_email (email, attempted_at),
                KEY idx_login_attempts_ip (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db_schema(true);
        $applied[] = 'login_attempts';
    }

    add_index_if_missing('sessions', 'idx_sessions_athlete_date', 'athlete_id, date', $applied);
    add_index_if_missing('sessions', 'idx_sessions_date', 'date', $applied);
    add_index_if_missing('comments', 'idx_comments_session', 'session_id, created_at', $applied);
    add_index_if_missing('athletes', 'idx_athletes_user', 'user_id', $applied);
    add_index_if_missing('sessions', 'idx_sessions_attachment', 'attachment_url(191)', $applied);

    purge_expired_remember_tokens();

    return $applied;
}

function logout_user() {
    clear_remember_token();
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
        'vélo',
        'natation',
        'footing',
    ];
}
// Class names are restricted to [a-z0-9-] so a stored type can never break out of the attribute.
function type_class(string $type): string {
    $slug = strtolower(str_replace([' ', 'é', 'è', 'ê', 'à'], ['-', 'e', 'e', 'e', 'a'], $type));
    return 'type-' . preg_replace('/[^a-z0-9-]/', '', $slug);
}

// Keeps a legacy stored value valid on edit while rejecting anything else.
function allowed_value($value, array $allowed, string $default, $current = null): string {
    $value = (string)$value;
    if (in_array($value, $allowed, true)) return $value;
    if ($current !== null && $value === (string)$current) return $value;
    return $default;
}

// Vélo and natation volumes are tracked apart; this ratio converts them to running km.
function session_sport_ratios(): array {
    return [
        'vélo' => ['sport' => 'bike', 'run_km_per_km' => 0.25],
        'natation' => ['sport' => 'swim', 'run_km_per_km' => 7],
    ];
}

function session_sport(string $type): string {
    return session_sport_ratios()[$type]['sport'] ?? 'run';
}

function run_equivalent_km(float $km, string $type): float {
    return $km * (session_sport_ratios()[$type]['run_km_per_km'] ?? 1);
}

function run_equivalent_sql(string $distanceExpr, string $typeColumn = 'type'): string {
    $cases = '';
    foreach (session_sport_ratios() as $type => $ratio) {
        $cases .= ' WHEN ' . $typeColumn . ' = ' . db()->quote($type) . ' THEN ' . $distanceExpr . ' * ' . $ratio['run_km_per_km'];
    }
    return '(CASE' . $cases . ' ELSE ' . $distanceExpr . ' END)';
}

// Real km: the athlete's debrief first, then the coach's "réalisé", then the planned km.
function session_volume(array $session): float {
    $debriefKm = $session['debrief']['actual_distance_km'] ?? null;
    if ($debriefKm !== null && $debriefKm !== '') return (float)$debriefKm;
    return (float)($session['actual_distance_km'] ?: ($session['planned_distance_km'] ?: 0));
}

function session_actual_distance(array $session, array $debrief) {
    if (($debrief['actual_distance_km'] ?? null) !== null && $debrief['actual_distance_km'] !== '') {
        return $debrief['actual_distance_km'];
    }
    return $session['actual_distance_km'] ?? null;
}

// Coaches land on the general calendar after editing a session; athletes keep their own.
function session_calendar_url(int $athleteId, string $month = ''): string {
    $month = preg_match('/^\d{4}-\d{2}$/', $month) ? '&month=' . $month : '';
    $user = current_user();
    if ($user && in_array($user['role'], ['coach', 'super_admin'], true)) {
        return 'index.php?page=coach_calendar&athlete_id=' . $athleteId . $month;
    }
    return 'index.php?page=calendar&athlete_id=' . $athleteId . $month;
}

function month_start($month): DateTime {
    if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) return new DateTime($month . '-01');
    return new DateTime(date('Y-m-01'));
}

function athlete_for_user(int $userId) {
    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function athlete_coaches_ready(): bool {
    return table_exists('athlete_coaches');
}

function coach_athlete_where(string $alias = 'a'): string {
    if (is_super_admin()) return '1=1';
    if (!athlete_coaches_ready()) return $alias . '.coach_id = ?';

    return '(' . $alias . '.coach_id = ? OR EXISTS (
        SELECT 1 FROM athlete_coaches ac
        WHERE ac.athlete_id = ' . $alias . '.id AND ac.coach_id = ?
    ))';
}

function coach_athlete_params(int $coachId): array {
    if (is_super_admin()) return [];
    return athlete_coaches_ready() ? [$coachId, $coachId] : [$coachId];
}

function is_primary_coach_for_athlete(int $athleteId, int $coachId): bool {
    $stmt = db()->prepare('SELECT id FROM athletes WHERE id=? AND coach_id=?');
    $stmt->execute([$athleteId, $coachId]);
    return (bool)$stmt->fetch();
}

function get_coaches_for_athlete(int $athleteId): array {
    if (!athlete_coaches_ready()) {
        $stmt = db()->prepare('SELECT u.id, u.name, u.email FROM users u JOIN athletes a ON a.coach_id=u.id WHERE a.id=?');
        $stmt->execute([$athleteId]);
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email
         FROM athlete_coaches ac
         JOIN users u ON u.id=ac.coach_id
         WHERE ac.athlete_id=?
         ORDER BY u.name'
    );
    $stmt->execute([$athleteId]);
    return $stmt->fetchAll();
}

function get_available_coaches(): array {
    $stmt = db()->prepare('SELECT id, name, email FROM users WHERE role="coach" ORDER BY name, email');
    $stmt->execute();
    return $stmt->fetchAll();
}

function sync_athlete_coaches(int $athleteId, int $primaryCoachId, ?int $secondaryCoachId = null): void {
    if (!athlete_coaches_ready()) return;

    db()->prepare('INSERT IGNORE INTO athlete_coaches (athlete_id, coach_id, created_at) VALUES (?,?,NOW())')
        ->execute([$athleteId, $primaryCoachId]);

    db()->prepare('DELETE FROM athlete_coaches WHERE athlete_id=? AND coach_id<>?')
        ->execute([$athleteId, $primaryCoachId]);

    if ($secondaryCoachId && $secondaryCoachId !== $primaryCoachId) {
        $stmt = db()->prepare('SELECT id FROM users WHERE id=? AND role="coach"');
        $stmt->execute([$secondaryCoachId]);
        if ($stmt->fetch()) {
            db()->prepare('INSERT IGNORE INTO athlete_coaches (athlete_id, coach_id, created_at) VALUES (?,?,NOW())')
                ->execute([$athleteId, $secondaryCoachId]);
        }
    }
}

function ensure_configured_super_admin(): bool {
    if (!defined('SUPER_ADMIN_EMAIL') || !defined('SUPER_ADMIN_PASSWORD')) return false;

    $email = trim((string)SUPER_ADMIN_EMAIL);
    $password = (string)SUPER_ADMIN_PASSWORD;
    $name = defined('SUPER_ADMIN_NAME') ? trim((string)SUPER_ADMIN_NAME) : 'Super Admin';

    if ($email === '' || $password === '') return false;

    $stmt = db()->prepare('SELECT id, name, password_hash, role FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    // An existing account keeps its own password (changed on "Mon compte"); only the role is enforced.
    if ($existing) {
        if ($existing['role'] === 'super_admin') return false;
        db()->prepare('UPDATE users SET role="super_admin" WHERE id=?')->execute([(int)$existing['id']]);
    } else {
        db_insert('users', [
            'name' => $name ?: 'Super Admin',
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'super_admin',
        ]);
    }

    return true;
}

// Called only when someone tries to log in with the configured super admin email,
// so the bcrypt check no longer runs on every page.
function bootstrap_configured_super_admin(string $loginEmail): void {
    if (!defined('SUPER_ADMIN_EMAIL') || !defined('SUPER_ADMIN_PASSWORD')) return;
    if (strcasecmp(trim($loginEmail), trim((string)SUPER_ADMIN_EMAIL)) !== 0) return;

    try {
        if (user_role_needs_migration()) {
            db()->exec("ALTER TABLE users MODIFY role VARCHAR(32) NOT NULL DEFAULT 'athlete'");
        }
        ensure_configured_super_admin();
    } catch (Throwable $e) {
        // The normal login flow should still render even if bootstrap is unavailable.
    }
}

function can_access_athlete(int $athleteId): bool {
    if ($athleteId <= 0) return false;
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'super_admin') return true;
    if ($user['role'] === 'coach') {
        $stmt = db()->prepare('SELECT a.id FROM athletes a WHERE a.id = ? AND ' . coach_athlete_where('a'));
        $stmt->execute(array_merge([$athleteId], coach_athlete_params((int)$user['id'])));
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
    if (!$session || !can_access_athlete((int)$session['athlete_id'])) error_page('Séance introuvable', 404);
    return $session;
}

function upload_attachment() {
    if (empty($_FILES['attachment']['name']) || ($_FILES['attachment']['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException("La pièce jointe n'a pas pu être envoyée (fichier trop lourd ?).");
    }
    if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('La pièce jointe dépasse 10 Mo.');
    }
    $allowed = ['pdf','jpg','jpeg','png','webp','doc','docx'];
    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new InvalidArgumentException('Format de pièce jointe non accepté (PDF, image, Word).');
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException("La pièce jointe n'a pas pu être enregistrée.");
    }
    return 'uploads/' . $name;
}

// Deletes the athlete with everything attached: sessions, comments, debriefs, login account
// and attachment files. The base tables may lack ON DELETE CASCADE, so rows are removed explicitly.
function delete_athlete_completely(int $athleteId): void {
    $stmt = db()->prepare('SELECT id, user_id FROM athletes WHERE id=?');
    $stmt->execute([$athleteId]);
    $athlete = $stmt->fetch();
    if (!$athlete) return;

    $stmt = db()->prepare('SELECT DISTINCT attachment_url FROM sessions WHERE athlete_id=? AND attachment_url IS NOT NULL AND attachment_url<>""');
    $stmt->execute([$athleteId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    db()->beginTransaction();
    $sessionIds = 'SELECT id FROM sessions WHERE athlete_id=?';
    db()->prepare('DELETE FROM comments WHERE session_id IN (' . $sessionIds . ')')->execute([$athleteId]);
    if (table_exists('session_debriefs')) {
        db()->prepare('DELETE FROM session_debriefs WHERE session_id IN (' . $sessionIds . ')')->execute([$athleteId]);
    }
    db()->prepare('DELETE FROM sessions WHERE athlete_id=?')->execute([$athleteId]);
    db()->prepare('DELETE FROM athletes WHERE id=?')->execute([$athleteId]);
    if (!empty($athlete['user_id'])) {
        if (table_exists('auth_tokens')) {
            db()->prepare('DELETE FROM auth_tokens WHERE user_id=?')->execute([(int)$athlete['user_id']]);
        }
        db()->prepare('DELETE FROM comments WHERE user_id=?')->execute([(int)$athlete['user_id']]);
        db()->prepare('DELETE FROM users WHERE id=? AND role="athlete"')->execute([(int)$athlete['user_id']]);
    }
    db()->commit();

    foreach ($attachments as $path) {
        delete_attachment_if_unused($path);
    }
}

// Removes an uploaded file once no session references it anymore (duplicates share files).
function delete_attachment_if_unused(?string $path): void {
    if (!$path || strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) return;
    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE attachment_url=?');
    $stmt->execute([$path]);
    if ((int)$stmt->fetchColumn() > 0) return;
    $file = __DIR__ . '/' . $path;
    if (is_file($file)) @unlink($file);
}

function session_statuses(): array {
    return [
        'planned' => 'Planifiée',
        'done' => 'Réalisée',
        'missed' => 'Manquée',
        'cancelled' => 'Annulée',
    ];
}

function intensities(): array {
    return [
        'low' => 'Faible',
        'moderate' => 'Modérée',
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

function speed_from_vma(float $vma, float $percent) {
    if ($vma <= 0 || $percent <= 0) return null;
    return $vma * $percent / 100;
}

function format_speed($speed): string {
    if (!$speed || $speed <= 0) return '-';
    return rtrim(rtrim(number_format((float)$speed, 2, ',', ' '), '0'), ',') . ' km/h';
}

function format_pace($minutesPerKm): string {
    if (!$minutesPerKm || $minutesPerKm <= 0) return '-';
    $seconds = (int)round($minutesPerKm * 60);
    return floor($seconds / 60) . "'" . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT) . '/km';
}

function format_distance($distance): string {
    if ($distance === null || $distance === '') return '-';
    return rtrim(rtrim(number_format((float)$distance, 2, ',', ' '), '0'), ',') . ' km';
}

function pace_label_from_code(array $paces, ?string $code): string {
    if (!$code) return '-';
    foreach ($paces as $pace) {
        if ($pace['code'] === $code) return $pace['label'];
    }
    return $code;
}

function session_volume_label(array $session): string {
    if (($session['planned_distance_km'] ?? null) !== null && $session['planned_distance_km'] !== '') {
        return format_distance($session['planned_distance_km']);
    }
    if (!empty($session['duration_min'])) {
        return (int)$session['duration_min'] . ' min';
    }
    return '';
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

function french_month_names(): array {
    return ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
}

function format_month_label(DateTime $month): string {
    return ucfirst(french_month_names()[(int)$month->format('n') - 1]) . ' ' . $month->format('Y');
}

function format_long_date($date): string {
    if (!$date) return '-';
    $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $d = new DateTime($date);
    return ucfirst($days[(int)$d->format('N') - 1]) . ' ' . $d->format('j') . ' ' . french_month_names()[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

function format_datetime($datetime): string {
    if (!$datetime) return '-';
    return (new DateTime($datetime))->format('d/m/Y à H:i');
}

function athlete_initials($firstName, $lastName): string {
    return mb_strtoupper(mb_substr((string)$firstName, 0, 1) . mb_substr((string)$lastName, 0, 1));
}

function weather_read_html(array $weatherKeys): void {
    if (!$weatherKeys) return;
    echo '<div class="weather-read">';
    foreach ($weatherKeys as $key) {
        $weather = weather_options()[$key] ?? null;
        if ($weather) echo '<span>' . e($weather['icon']) . ' ' . e($weather['label']) . '</span>';
    }
    echo '</div>';
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
