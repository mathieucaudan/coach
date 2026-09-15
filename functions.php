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
function is_super_admin(): bool { return (current_user()['role'] ?? null) === 'super_admin'; }
function require_login() { if (!current_user()) redirect('index.php?page=login'); }
function require_role(string $role) {
    require_login();
    $currentRole = current_user()['role'];
    if ($currentRole === $role || ($role === 'coach' && $currentRole === 'super_admin')) return;
    http_response_code(403);
    exit('Accès refusé');
}
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf() { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(400); exit('Token CSRF invalide'); } }

function table_has_column(string $table, string $column): bool {
    $knownColumns = [
        'users' => ['id', 'name', 'email', 'password_hash', 'role', 'created_at'],
        'auth_tokens' => ['id', 'user_id', 'selector', 'validator_hash', 'expires_at', 'created_at'],
        'athletes' => ['id', 'coach_id', 'user_id', 'first_name', 'last_name', 'email', 'created_at'],
        'athlete_coaches' => ['athlete_id', 'coach_id', 'created_at'],
        'sessions' => ['id', 'athlete_id', 'coach_id', 'date', 'title', 'type', 'description', 'objective', 'warmup', 'main_workout', 'cooldown', 'coach_notes', 'attachment_url', 'external_link', 'created_at', 'updated_at'],
        'athlete_paces' => ['id', 'athlete_id', 'code', 'label', 'percent_vma', 'sort_order', 'created_at', 'updated_at'],
        'session_debriefs' => ['id', 'session_id', 'result', 'difficulty', 'sensations', 'weather', 'temperature_c', 'lactates', 'created_at', 'updated_at'],
        'daily_debriefs' => ['id', 'athlete_id', 'date', 'result', 'difficulty', 'sensations', 'weather', 'temperature_c', 'lactates', 'created_at', 'updated_at'],
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

function table_exists(string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
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

function add_column_if_missing(string $table, string $column, string $definition, array &$applied): void {
    if (table_has_column($table, $column)) return;

    db()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    $applied[] = $table . '.' . $column;
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
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role']
    ];
}

function remember_cookie_options(int $expires): array {
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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

    login_user($token);
}

function nullable_int($value) {
    return $value === '' || $value === null ? null : (int)$value;
}

function nullable_float($value) {
    return $value === '' || $value === null ? null : (float)$value;
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
        http_response_code(403);
        exit('Accès refusé');
    }

    $stmt = db()->prepare('SELECT * FROM daily_debriefs WHERE athlete_id=? AND date=? LIMIT 1');
    $stmt->execute([$athleteId, $date]);
    $row = $stmt->fetch();
    return $row ? normalize_daily_debrief($row) : empty_debrief();
}

function attach_daily_debriefs_to_calendar(array $sessionsByDate, int $athleteId, DateTime $start, DateTime $end): array {
    if (!table_exists('daily_debriefs')) return [];

    $stmt = db()->prepare('SELECT * FROM daily_debriefs WHERE athlete_id=? AND date BETWEEN ? AND ?');
    $stmt->execute([$athleteId, $start->format('Y-m-d'), $end->format('Y-m-d')]);

    $dailyDebriefs = [];
    foreach ($stmt->fetchAll() as $row) {
        $date = $row['date'];
        if (!empty($sessionsByDate[$date])) continue;
        $dailyDebriefs[$date] = normalize_daily_debrief($row);
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
        http_response_code(403);
        exit('Seul le compte athlète peut modifier le débrief.');
    }

    $athlete = athlete_for_user((int)$user['id']);
    if (!$athlete || (int)$athlete['id'] !== (int)$session['athlete_id']) {
        http_response_code(403);
        exit('Accès refusé');
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
        http_response_code(403);
        exit('Seul le compte athlète peut modifier ce retour.');
    }

    $athlete = athlete_for_user((int)$user['id']);
    if (!$athlete || (int)$athlete['id'] !== $athleteId) {
        http_response_code(403);
        exit('Accès refusé');
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
    if (!$user || $user['role'] !== 'coach' || !can_access_athlete($athleteId)) {
        http_response_code(403);
        exit('Accès refusé');
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
        $applied[] = 'auth_tokens';
    }

    if (ensure_configured_super_admin()) {
        $applied[] = 'super_admin_config';
    }

    if (table_exists('sessions')) {
        add_column_if_missing('sessions', 'planned_distance_km', 'DECIMAL(6,2) NULL AFTER duration_min', $applied);
        add_column_if_missing('sessions', 'actual_distance_km', 'DECIMAL(6,2) NULL AFTER actual_duration_min', $applied);
        add_column_if_missing('sessions', 'target_pace_code', 'VARCHAR(40) NULL AFTER vma_percent', $applied);
    }
    if (table_exists('session_debriefs')) {
        add_column_if_missing('session_debriefs', 'actual_distance_km', 'DECIMAL(6,2) NULL AFTER lactates', $applied);
    }
    if (table_exists('daily_debriefs')) {
        add_column_if_missing('daily_debriefs', 'actual_distance_km', 'DECIMAL(6,2) NULL AFTER lactates', $applied);
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
        $applied[] = 'athlete_paces';
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
        $applied[] = 'daily_debriefs';
    }

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

    if ($existing) {
        if (
            $existing['name'] === ($name ?: 'Super Admin')
            && $existing['role'] === 'super_admin'
            && password_verify($password, (string)$existing['password_hash'])
        ) {
            return false;
        }

        db_update('users', [
            'name' => $name ?: 'Super Admin',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'super_admin',
        ], 'id=?', [(int)$existing['id']]);
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

function bootstrap_configured_super_admin(): void {
    if (!defined('SUPER_ADMIN_EMAIL') || !defined('SUPER_ADMIN_PASSWORD')) return;

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
