<?php
require_once __DIR__ . '/functions.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 8));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Errors while rendering pages get the same friendly page as actions, and are logged.
set_exception_handler(function (Throwable $e) {
    handle_fatal_exception($e);
});

refresh_session_user();
restore_remembered_login();
// Issued before any output so the CSRF cookie can still be set.
csrf_token();

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? null;

// Logout only goes through the CSRF-protected "logout" action.
if ($page === 'logout') redirect('index.php');

try {
if ($action) {
    verify_csrf();
    $user = current_user();

    if ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        if (too_many_login_attempts($email)) {
            $_SESSION['error'] = 'Trop de tentatives. Réessaie dans 15 minutes.';
            redirect('index.php?page=login');
        }

        try {
            bootstrap_configured_super_admin($email);
        } catch (Throwable $e) {
            error_log((string)$e);
        }

        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        // Unknown emails still pay for a bcrypt check so timing does not reveal accounts.
        $hash = $u ? (string)$u['password_hash'] : password_hash('timing-placeholder', PASSWORD_BCRYPT);
        if (password_verify((string)($_POST['password'] ?? ''), $hash) && $u) {
            clear_login_attempts($email);
            purge_expired_remember_tokens();
            login_user($u);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            if (!empty($_POST['remember'])) {
                remember_user((int)$u['id']);
            }
            $next = (string)($_SESSION['after_login'] ?? '');
            unset($_SESSION['after_login']);
            redirect(preg_match('/^index\.php\?page=[a-z_]+/', $next) ? $next : 'index.php');
        }

        record_failed_login($email);
        $_SESSION['error'] = 'Identifiants incorrects.';
        redirect('index.php?page=login');
    }

    if ($action === 'logout') {
        logout_user();
        redirect('index.php?page=login');
    }

    require_login();

    if ($action === 'change_password') {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([(int)$user['id']]);
        $currentHash = (string)$stmt->fetchColumn();
        if (!password_verify((string)($_POST['current_password'] ?? ''), $currentHash)) {
            throw new InvalidArgumentException('Mot de passe actuel incorrect.');
        }
        $newPassword = (string)($_POST['new_password'] ?? '');
        validate_password($newPassword);
        if ($newPassword !== (string)($_POST['confirm_password'] ?? '')) {
            throw new InvalidArgumentException('Les deux nouveaux mots de passe ne correspondent pas.');
        }

        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT), (int)$user['id']]);
        // Every remembered device must log in again with the new password.
        if (table_exists('auth_tokens')) {
            db()->prepare('DELETE FROM auth_tokens WHERE user_id=?')->execute([(int)$user['id']]);
        }
        clear_remember_token();
        session_regenerate_id(true);

        $_SESSION['success'] = 'Mot de passe modifié.';
        redirect('index.php?page=account');
    }

    if ($action === 'run_migrations') {
        require_role('super_admin');
        try {
            $_SESSION['migration_result'] = ['applied' => run_pending_migrations(), 'error' => null];
        } catch (Throwable $e) {
            error_log((string)$e);
            $_SESSION['migration_result'] = ['applied' => [], 'error' => $e->getMessage()];
        }
        redirect('index.php?page=run_migrations');
    }

    if ($action === 'create_coach') {
        require_role('super_admin');

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Nom ou email invalide.');
        }
        validate_password($password);
        if (email_taken($email)) {
            throw new InvalidArgumentException('Un compte existe déjà avec cet email.');
        }

        $stmt = db()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,"coach")');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT)]);
        $_SESSION['success'] = 'Coach créé.';

        redirect('index.php?page=admin');
    }

    if ($action === 'save_coach_settings') {
        require_role('coach');

        if (coach_session_field_settings_ready()) {
            save_coach_session_field_visibility((int)$user['id'], $_POST['session_fields'] ?? []);
            $_SESSION['success'] = 'Paramètres enregistrés.';
        } else {
            $_SESSION['success'] = 'Paramètres non enregistrés : lance d\'abord la page Migrations.';
        }

        redirect('index.php?page=coach_settings');
    }

    if ($action === 'create_athlete') {
        require_role('coach');

        $primaryCoachId = (int)$user['id'];
        if ($user['role'] === 'super_admin') {
            $primaryCoachId = nullable_int($_POST['primary_coach_id'] ?? null) ?? 0;
            $stmt = db()->prepare('SELECT id FROM users WHERE id=? AND role="coach"');
            $stmt->execute([$primaryCoachId]);
            if (!$stmt->fetch()) {
                throw new InvalidArgumentException('Coach principal invalide.');
            }
        }

        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email invalide.');
        }
        if (email_taken($email)) {
            throw new InvalidArgumentException('Un compte existe déjà avec cet email.');
        }
        $password = (string)($_POST['password'] ?? '');
        validate_password($password);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $name = trim($_POST['first_name'].' '.$_POST['last_name']);

        db()->beginTransaction();

        $stmt = db()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,"athlete")');
        $stmt->execute([$name, $email, $hash]);

        $userId = (int)db()->lastInsertId();

        $athleteId = db_insert('athletes', [
            'coach_id' => $primaryCoachId,
            'user_id' => $userId,
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'email' => $email,
            'sport' => trim($_POST['sport'] ?? 'Course'),
            'level' => trim($_POST['level'] ?? 'Intermédiaire'),
            'goal' => trim($_POST['goal'] ?? ''),
            'vma' => max(5, min(30, nullable_float($_POST['vma'] ?? null) ?? 15)),
            'notes' => trim($_POST['notes'] ?? '')
        ]);
        sync_athlete_coaches($athleteId, $primaryCoachId, nullable_int($_POST['secondary_coach_id'] ?? null));
        sync_athlete_paces($athleteId, $_POST['paces'] ?? []);
        ensure_athlete_paces($athleteId);

        db()->commit();

        $_SESSION['success'] = 'Athlète créé.';
        redirect('index.php?page=dashboard');
    }

    if ($action === 'update_athlete') {
        require_role('coach');

        $id = (int)$_POST['id'];

        if (!can_access_athlete($id)) deny_access();

        $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.id=?');
        $stmt->execute([$id]);
        $athleteToUpdate = $stmt->fetch();
        $primaryCoachId = (int)$athleteToUpdate['coach_id'];
        if ($user['role'] === 'super_admin') {
            $primaryCoachId = nullable_int($_POST['primary_coach_id'] ?? null) ?? 0;
            $stmt = db()->prepare('SELECT id FROM users WHERE id=? AND role="coach"');
            $stmt->execute([$primaryCoachId]);
            if (!$stmt->fetch()) {
                throw new InvalidArgumentException('Coach principal invalide.');
            }
        }

        // A secondary coach may edit the training profile, but not who coaches the athlete
        // nor the email the athlete logs in with.
        $canManageAccess = $user['role'] === 'super_admin' || (int)$athleteToUpdate['coach_id'] === (int)$user['id'];
        $email = $canManageAccess ? trim((string)($_POST['email'] ?? '')) : (string)$athleteToUpdate['email'];
        if ($canManageAccess && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email invalide.');
        }
        if ($email !== $athleteToUpdate['email'] && email_taken($email, (int)$athleteToUpdate['user_id'])) {
            throw new InvalidArgumentException('Un compte existe déjà avec cet email.');
        }
        if ($canManageAccess) {
            $secondaryCoachId = nullable_int($_POST['secondary_coach_id'] ?? null);
        } else {
            $secondaryCoachId = null;
            foreach (get_coaches_for_athlete($id) as $coach) {
                if ((int)$coach['id'] !== $primaryCoachId) $secondaryCoachId = (int)$coach['id'];
            }
        }

        db()->beginTransaction();
        db_update('athletes', [
            'coach_id' => $primaryCoachId,
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'email' => $email,
            'sport' => trim($_POST['sport'] ?? 'Course'),
            'level' => trim($_POST['level'] ?? 'Intermédiaire'),
            'goal' => trim($_POST['goal'] ?? ''),
            'vma' => max(5, min(30, nullable_float($_POST['vma'] ?? null) ?? 15)),
            'notes' => trim($_POST['notes'] ?? '')
        ], 'id=?', [$id]);
        sync_athlete_coaches($id, $primaryCoachId, $secondaryCoachId);
        sync_athlete_paces($id, $_POST['paces'] ?? []);
        ensure_athlete_paces($id);

        $stmt = db()->prepare('UPDATE users u JOIN athletes a ON a.user_id=u.id SET u.name=?, u.email=? WHERE a.id=?');
        $stmt->execute([
            trim($_POST['first_name'].' '.$_POST['last_name']),
            $email,
            $id
        ]);
        db()->commit();

        $_SESSION['success'] = 'Profil enregistré.';
        redirect('index.php?page=calendar&athlete_id=' . $id);
    }

    if ($action === 'delete_athlete') {
        require_role('coach');

        $id = (int)$_POST['id'];

        if (!can_access_athlete($id)) deny_access();

        if ($user['role'] !== 'super_admin' && !is_primary_coach_for_athlete($id, (int)$user['id'])) error_page('Seul le coach principal peut supprimer cet athlète.', 403);

        delete_athlete_completely($id);

        $_SESSION['success'] = 'Athlète supprimé.';
        redirect('index.php?page=dashboard');
    }

    if ($action === 'save_session') {
        require_role('coach');

        $athleteId = (int)$_POST['athlete_id'];

        if (!can_access_athlete($athleteId)) deny_access();

        $existingSession = !empty($_POST['id']) ? get_session_checked((int)$_POST['id']) : null;
        if ($existingSession && (int)$existingSession['athlete_id'] !== $athleteId) deny_access();
        $sessionDate = require_valid_date($_POST['date'] ?? '');
        $sessionTitle = trim((string)($_POST['title'] ?? ''));
        if ($sessionTitle === '') throw new InvalidArgumentException('Le titre est obligatoire.');
        $sessionType = allowed_value($_POST['type'] ?? '', session_types(), session_types()[0], $existingSession['type'] ?? null);
        $sessionStatus = allowed_value($_POST['status'] ?? '', array_keys(session_statuses()), 'planned');
        $sessionIntensity = allowed_value($_POST['intensity'] ?? '', array_keys(intensities()), 'moderate');
        $feeling = nullable_int($_POST['feeling'] ?? null);
        $pain = nullable_int($_POST['pain'] ?? null);
        $feeling = $feeling === null ? null : max(1, min(10, $feeling));
        $pain = $pain === null ? null : max(0, min(10, $pain));
        $externalLink = clean_external_link($_POST['external_link'] ?? '');

        $attachment = upload_attachment();
        $targetPaceCode = trim((string)($_POST['target_pace_code'] ?? ''));
        $targetPacePercent = $targetPaceCode !== '' ? pace_percent_for_athlete($athleteId, $targetPaceCode) : nullable_float($_POST['vma_percent'] ?? null);
        if ($targetPaceCode !== '' && $targetPacePercent === null) {
            $targetPaceCode = '';
        }
        $plannedDistance = nullable_float($_POST['planned_distance_km'] ?? null);
        $actualDistance = nullable_float($_POST['actual_distance_km'] ?? null);
        $plannedDistance = $plannedDistance === null ? null : max(0, $plannedDistance);
        $actualDistance = $actualDistance === null ? null : max(0, $actualDistance);

        if ($existingSession) {
            $session = $existingSession;
            $previousAttachment = $session['attachment_url'];
            $attachment = $attachment ?: $previousAttachment;

            db_update('sessions', [
                'date' => $sessionDate,
                'title' => $sessionTitle,
                'type' => $sessionType,
                'status' => $sessionStatus,
                'intensity' => $sessionIntensity,
                'duration_min' => nullable_int($_POST['duration_min'] ?? null),
                'planned_distance_km' => $plannedDistance,
                'actual_distance_km' => $actualDistance,
                'target_pace_code' => $targetPaceCode ?: null,
                'vma_percent' => $targetPacePercent,
                'description' => $_POST['description'] ?? '',
                'objective' => '',
                'warmup' => $_POST['warmup'] ?? '',
                'main_workout' => $_POST['main_workout'] ?? '',
                'cooldown' => '',
                'coach_notes' => $_POST['coach_notes'] ?? '',
                'actual_duration_min' => nullable_int($_POST['actual_duration_min'] ?? null),
                'feeling' => $feeling,
                'pain' => $pain,
                'athlete_feedback' => $_POST['athlete_feedback'] ?? '',
                'attachment_url' => $attachment,
                'external_link' => $externalLink
            ], 'id=?', [(int)$session['id']]);
            if ($previousAttachment && $previousAttachment !== $attachment) {
                delete_attachment_if_unused($previousAttachment);
            }
        } else {
            db_insert('sessions', [
                'athlete_id' => $athleteId,
                'coach_id' => $user['id'],
                'date' => $sessionDate,
                'title' => $sessionTitle,
                'type' => $sessionType,
                'status' => $sessionStatus,
                'intensity' => $sessionIntensity,
                'duration_min' => nullable_int($_POST['duration_min'] ?? null),
                'planned_distance_km' => $plannedDistance,
                'actual_distance_km' => $actualDistance,
                'target_pace_code' => $targetPaceCode ?: null,
                'vma_percent' => $targetPacePercent,
                'description' => $_POST['description'] ?? '',
                'objective' => '',
                'warmup' => $_POST['warmup'] ?? '',
                'main_workout' => $_POST['main_workout'] ?? '',
                'cooldown' => '',
                'coach_notes' => $_POST['coach_notes'] ?? '',
                'actual_duration_min' => nullable_int($_POST['actual_duration_min'] ?? null),
                'feeling' => $feeling,
                'pain' => $pain,
                'athlete_feedback' => $_POST['athlete_feedback'] ?? '',
                'attachment_url' => $attachment,
                'external_link' => $externalLink
            ]);
        }

        $_SESSION['success'] = 'Séance enregistrée.';
        redirect(session_calendar_url($athleteId, substr($sessionDate, 0, 7)));
    }

    if ($action === 'delete_session') {
        require_role('coach');

        $s = get_session_checked((int)$_POST['id']);

        db()->prepare('DELETE FROM sessions WHERE id=?')->execute([$s['id']]);
        delete_attachment_if_unused($s['attachment_url']);

        $_SESSION['success'] = 'Séance supprimée.';
        redirect(session_calendar_url((int)$s['athlete_id'], substr($s['date'], 0, 7)));
    }

    if ($action === 'duplicate_session') {
        require_role('coach');

        $s = get_session_checked((int)$_POST['id']);
        $date = require_valid_date($_POST['new_date'] ?? '');

        // The copy is a new planned session: the athlete's results stay on the original.
        db_insert('sessions', [
            'athlete_id' => $s['athlete_id'],
            'coach_id' => $user['id'],
            'date' => $date,
            'title' => $s['title'],
            'type' => $s['type'],
            'status' => 'planned',
            'intensity' => $s['intensity'],
            'duration_min' => $s['duration_min'],
            'planned_distance_km' => $s['planned_distance_km'],
            'actual_distance_km' => null,
            'target_pace_code' => $s['target_pace_code'],
            'vma_percent' => $s['vma_percent'],
            'description' => $s['description'],
            'objective' => $s['objective'],
            'warmup' => $s['warmup'],
            'main_workout' => $s['main_workout'],
            'cooldown' => $s['cooldown'],
            'coach_notes' => $s['coach_notes'],
            'actual_duration_min' => null,
            'feeling' => null,
            'pain' => null,
            'athlete_feedback' => '',
            'attachment_url' => $s['attachment_url'],
            'external_link' => $s['external_link'],
        ]);

        $_SESSION['success'] = 'Séance dupliquée.';
        redirect(session_calendar_url((int)$s['athlete_id'], substr($date, 0, 7)));
    }

    if ($action === 'move_session') {
        require_role('coach');

        $s = get_session_checked((int)$_POST['id']);
        $newDate = require_valid_date($_POST['new_date'] ?? '');

        db()->prepare('UPDATE sessions SET date=? WHERE id=?')->execute([
            $newDate,
            $s['id']
        ]);

        $_SESSION['success'] = 'Séance déplacée.';
        redirect(session_calendar_url((int)$s['athlete_id'], substr($newDate, 0, 7)));
    }

    if ($action === 'add_comment') {
        $s = get_session_checked((int)$_POST['session_id']);
        $content = trim((string)($_POST['content'] ?? ''));
        if ($content === '') throw new InvalidArgumentException('Le commentaire est vide.');

        db()->prepare('INSERT INTO comments (session_id,user_id,content) VALUES (?,?,?)')->execute([
            $s['id'],
            $user['id'],
            $content
        ]);

        redirect('index.php?page=session&id='.$s['id']);
    }

    if ($action === 'save_debrief') {
        $s = get_session_checked((int)$_POST['session_id']);
        save_session_debrief($s, $_POST);
        $_SESSION['success'] = 'Débrief enregistré.';
        redirect('index.php?page=session&id='.$s['id']);
    }

    if ($action === 'save_daily_debrief') {
        $athleteId = (int)$_POST['athlete_id'];
        $date = require_valid_date($_POST['date'] ?? '');
        save_daily_debrief($athleteId, $date, $_POST);
        $_SESSION['success'] = 'Retour journalier enregistré.';
        redirect('index.php?page=daily_debrief&athlete_id='.$athleteId.'&date='.$date);
    }

    if ($action === 'quick_session') {
        require_role('coach');
        $athleteId = (int)$_POST['athlete_id'];
        $date = $_POST['date'] ?? '';
        $sessionId = create_quick_session($athleteId, $date, $_POST['title'] ?? '', $_POST['description'] ?? '');
        $_SESSION['success'] = 'Séance ajoutée.';
        redirect('index.php?page=session&id='.$sessionId);
    }
}
} catch (Throwable $e) {
    handle_fatal_exception($e);
}


function role_label(string $role): string {
    return ['super_admin' => 'Admin', 'coach' => 'Coach', 'athlete' => 'Athlète'][$role] ?? $role;
}

function header_html(string $title) {
    $u = current_user();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0f766e">
    <title><?=e($title)?> - <?=e(APP_NAME)?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if($u): ?>
<header class="topbar">
    <div class="brand"><?=e(APP_NAME)?></div>

    <nav class="nav">
        <span class="nav-user"><?=e($u['name'])?> · <?=e(role_label($u['role']))?></span>

        <?php if($u['role'] === 'coach' || $u['role'] === 'super_admin'): ?>
            <?php if($u['role'] === 'super_admin'): ?>
                <a href="index.php?page=admin">Admin</a>
                <a href="index.php?page=run_migrations">Migrations</a>
            <?php endif; ?>
            <a href="index.php?page=dashboard">Athlètes</a>
            <a href="index.php?page=coach_calendar">Calendrier général</a>
            <a href="index.php?page=coach_settings">Paramètres</a>
        <?php else: ?>
            <a href="index.php">Calendrier</a>
        <?php endif; ?>
        <a href="index.php?page=account">Mon compte</a>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn secondary small logout-button" type="submit">Déconnexion</button>
        </form>
    </nav>
</header>
<?php endif; ?>

<main class="container">
<?php
    $flash = $_SESSION['success'] ?? null;
    unset($_SESSION['success']);
    if ($flash): ?>
    <div class="success-alert" role="status"><?=e($flash)?></div>
<?php endif;
}

function footer_html() {
    ?>
</main>
<script>
// Stops a double tap on a slow connection from submitting a form twice.
document.addEventListener('submit', function (event) {
    if (event.defaultPrevented) return; // e.g. the user cancelled a confirm()
    var form = event.target;
    if (form.dataset.submitted) { event.preventDefault(); return; }
    form.dataset.submitted = '1';
    setTimeout(function () { delete form.dataset.submitted; }, 8000);
});
// Pages restored by the back button must accept a new submit right away.
window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitted]').forEach(function (form) { delete form.dataset.submitted; });
});
</script>
</body></html>
<?php
}

function debrief_form_fields(array $debrief): void {
?>
    <div class="field full">
        <label for="debrief-result">Chronos / résultat</label>
        <textarea id="debrief-result" name="result" placeholder="Ex : 10 km en 42'15, footing 1h15 - 14,2 km"><?=e($debrief['result'])?></textarea>
    </div>

    <div class="field">
        <label for="debrief-distance">Volume réalisé (km)</label>
        <input id="debrief-distance" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" name="actual_distance_km" value="<?=e($debrief['actual_distance_km'] ?? '')?>">
    </div>

    <fieldset class="difficulty-field">
        <legend>Difficulté</legend>
        <div class="difficulty-scale">
            <?php for($i = 1; $i <= 10; $i++): ?>
                <label class="difficulty-choice">
                    <input type="radio" name="difficulty" value="<?=$i?>" <?=$debrief['difficulty'] === $i ? 'checked' : ''?>>
                    <span><?=$i?></span>
                </label>
            <?php endfor; ?>
        </div>
        <p class="scale-help">1 très facile · 5 moyen · 10 très difficile</p>
    </fieldset>

    <div class="field full">
        <label for="debrief-sensations">Sensations / commentaires / douleurs / gênes</label>
        <textarea id="debrief-sensations" name="sensations" class="large-textarea" placeholder="Fatigue, douleurs, contexte, bonnes sensations, informations utiles au coach..."><?=e($debrief['sensations'])?></textarea>
    </div>

    <fieldset class="weather-field">
        <legend>Météo</legend>
        <div class="weather-options">
            <?php foreach(weather_options() as $key => $weather): ?>
                <label class="weather-choice <?=in_array($key, $debrief['weather'], true) ? 'selected' : ''?>">

                    <input type="checkbox" name="weather[]" value="<?=$key?>" <?=in_array($key, $debrief['weather'], true) ? 'checked' : ''?>>
                    <span aria-hidden="true"><?=e($weather['icon'])?></span>
                    <strong><?=e($weather['label'])?></strong>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="form-grid">
        <div class="field">
            <label for="debrief-temperature">Température (°C)</label>
            <input id="debrief-temperature" type="number" name="temperature_c" min="-30" max="55" step="any" value="<?=e($debrief['temperature_c'] ?? '')?>">
        </div>

        <div class="field">
            <label for="debrief-lactates">Lactates</label>
            <input id="debrief-lactates" name="lactates" value="<?=e($debrief['lactates'])?>" placeholder="Ex : 2.1 mmol/L ou Non mesuré">
        </div>
    </div>
<?php
}

function athlete_pace_fields(array $paces): void {
?>
    <div class="field full">
        <label>Repères d'allure</label>
        <div class="pace-editor">
            <?php foreach($paces as $i => $pace): ?>
                <div class="pace-edit-row">
                    <input type="hidden" name="paces[<?=$i?>][code]" value="<?=e($pace['code'])?>">
                    <div>
                        <label for="pace-label-<?=$i?>">Repère</label>
                        <input id="pace-label-<?=$i?>" name="paces[<?=$i?>][label]" value="<?=e($pace['label'])?>">
                    </div>
                    <div>
                        <label for="pace-percent-<?=$i?>">% VMA</label>
                        <input id="pace-percent-<?=$i?>" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" name="paces[<?=$i?>][percent_vma]" value="<?=e($pace['percent_vma'])?>">
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if(!athlete_paces_ready()): ?>
            <small>Personnalisation disponible après passage par la page Migrations.</small>
        <?php endif; ?>
    </div>
<?php
}

function session_form_field(string $fieldKey, array $session, array $athletePaces): void {
    $fieldId = 'session-' . str_replace('_', '-', $fieldKey);
    if ($fieldKey === 'type'):
        $currentType = (string)($session['type'] ?? session_types()[0]);
        $types = session_types();
        if ($currentType !== '' && !in_array($currentType, $types, true)) $types[] = $currentType;
    ?>
        <div class="field">
            <label for="<?=$fieldId?>">Type</label>
            <select id="<?=$fieldId?>" name="type">
                <?php foreach($types as $t): ?>
                    <option value="<?=e($t)?>" <?=$t === $currentType ? 'selected' : ''?>><?=e($t)?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php elseif ($fieldKey === 'status'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Statut</label>
            <select id="<?=$fieldId?>" name="status">
                <?php foreach(session_statuses() as $value => $label): ?>
                    <option value="<?=$value?>" <?=$value === ($session['status'] ?? 'planned') ? 'selected' : ''?>>
                        <?=e($label)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php elseif ($fieldKey === 'intensity'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Intensité</label>
            <select id="<?=$fieldId?>" name="intensity">
                <?php foreach(intensities() as $value => $label): ?>
                    <option value="<?=$value?>" <?=$value === ($session['intensity'] ?? 'moderate') ? 'selected' : ''?>>
                        <?=e($label)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php elseif ($fieldKey === 'planned_distance_km'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Volume total prévu (km)</label>
            <input id="<?=$fieldId?>" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" name="planned_distance_km" value="<?=e($session['planned_distance_km'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'target_pace_code'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Seuil / rythme visé</label>
            <select id="<?=$fieldId?>" name="target_pace_code">
                <option value="">Non précisé</option>
                <?php foreach($athletePaces as $pacePreset): ?>
                    <option value="<?=e($pacePreset['code'])?>" <?=($session['target_pace_code'] ?? '') === $pacePreset['code'] ? 'selected' : ''?>>
                        <?=e($pacePreset['label'])?> · <?=e(rtrim(rtrim(number_format((float)$pacePreset['percent_vma'], 2, ',', ' '), '0'), ','))?>% VMA
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php elseif ($fieldKey === 'actual_distance_km'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Volume réalisé (km)</label>
            <input id="<?=$fieldId?>" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" name="actual_distance_km" value="<?=e($session['actual_distance_km'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'description'): ?>
        <div class="field full">
            <label for="<?=$fieldId?>">Contenu de séance</label>
            <textarea id="<?=$fieldId?>" name="description"><?=e($session['description'] ?? '')?></textarea>
        </div>
    <?php elseif ($fieldKey === 'warmup'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Échauffement</label>
            <textarea id="<?=$fieldId?>" name="warmup"><?=e($session['warmup'] ?? '')?></textarea>
        </div>
    <?php elseif ($fieldKey === 'main_workout'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Corps de séance</label>
            <textarea id="<?=$fieldId?>" name="main_workout"><?=e($session['main_workout'] ?? '')?></textarea>
        </div>
    <?php elseif ($fieldKey === 'coach_notes'): ?>
        <div class="field full">
            <label for="<?=$fieldId?>">Conseils du coach</label>
            <textarea id="<?=$fieldId?>" name="coach_notes"><?=e($session['coach_notes'] ?? '')?></textarea>
        </div>
    <?php elseif ($fieldKey === 'duration_min'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Durée prévue (min)</label>
            <input id="<?=$fieldId?>" type="number" inputmode="numeric" name="duration_min" min="0" step="1" value="<?=e($session['duration_min'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'actual_duration_min'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Durée réelle (min)</label>
            <input id="<?=$fieldId?>" type="number" inputmode="numeric" name="actual_duration_min" min="0" step="1" value="<?=e($session['actual_duration_min'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'vma_percent'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">% VMA cible</label>
            <input id="<?=$fieldId?>" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" name="vma_percent" value="<?=e($session['vma_percent'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'external_link'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Lien optionnel</label>
            <input id="<?=$fieldId?>" type="url" inputmode="url" name="external_link" placeholder="https://..." value="<?=e($session['external_link'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'feeling'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Ressenti athlète / 10</label>
            <input id="<?=$fieldId?>" type="number" inputmode="numeric" name="feeling" min="1" max="10" step="1" value="<?=e($session['feeling'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'pain'): ?>
        <div class="field">
            <label for="<?=$fieldId?>">Douleur / fatigue / 10</label>
            <input id="<?=$fieldId?>" type="number" inputmode="numeric" name="pain" min="0" max="10" step="1" value="<?=e($session['pain'] ?? '')?>">
        </div>
    <?php elseif ($fieldKey === 'athlete_feedback'): ?>
        <div class="field full">
            <label for="<?=$fieldId?>">Retour athlète</label>
            <textarea id="<?=$fieldId?>" name="athlete_feedback"><?=e($session['athlete_feedback'] ?? '')?></textarea>
        </div>
    <?php elseif ($fieldKey === 'attachment'): ?>
        <div class="field full">
            <label for="<?=$fieldId?>">Pièce jointe <small>(PDF, image ou Word, 10 Mo max)</small></label>
            <input id="<?=$fieldId?>" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
        </div>
    <?php endif;
}

if ($page === 'login') {
    if (current_user()) redirect('index.php');

    $err = $_SESSION['error'] ?? null;
    unset($_SESSION['error']);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0f766e">
    <title>Connexion - <?=e(APP_NAME)?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="auth-body">
    <form class="auth-card" method="post">
        <h1><?=e(APP_NAME)?></h1>
        <p>Connexion coach, athlète ou admin</p>

        <?php if($err): ?>
            <div class="alert" role="alert"><?=e($err)?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="login">

        <div class="field">
            <label for="login-email">Email</label>
            <input id="login-email" type="email" name="email" autocomplete="username" autocapitalize="none" required>
        </div>

        <div class="field">
            <label for="login-password">Mot de passe</label>
            <input id="login-password" type="password" name="password" autocomplete="current-password" required>
        </div>

        <label class="remember-choice">
            <input type="checkbox" name="remember" value="1">
            <span>Rester connecté</span>
        </label>

        <button class="btn" type="submit">Se connecter</button>

        <p class="auth-help">
            Mot de passe oublié ? Demande à ton coach de t'en créer un nouveau.
        </p>
    </form>
</div>
</body>
</html>
<?php
    exit;
}

require_login();
$u = current_user();

if ($page === 'home') {
    if ($u['role'] === 'super_admin') {
        redirect('index.php?page=admin');
    }
    if ($u['role'] === 'coach') {
        redirect('index.php?page=dashboard');
    }

    $a = athlete_for_user($u['id']);
    if (!$a) error_page("Aucune fiche athlète n'est rattachée à ce compte. Contacte ton coach.", 404);
    redirect('index.php?page=calendar&athlete_id='.$a['id']);
}

if ($page === 'account') {
    header_html('Mon compte');
?>
<section class="card narrow-card">
    <h1>Mon compte</h1>
    <p class="muted-text"><?=e($u['name'])?> · <?=e($u['email'])?></p>

    <h2>Changer de mot de passe</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="change_password">
        <input type="email" name="email" value="<?=e($u['email'])?>" autocomplete="username" hidden>

        <div class="field">
            <label for="current-password">Mot de passe actuel</label>
            <input id="current-password" type="password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="new-password">Nouveau mot de passe <small>(8 caractères minimum)</small></label>
            <input id="new-password" type="password" name="new_password" minlength="8" autocomplete="new-password" required>
        </div>
        <div class="field">
            <label for="confirm-password">Confirmer le nouveau mot de passe</label>
            <input id="confirm-password" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
        </div>

        <button class="btn" type="submit">Enregistrer le mot de passe</button>
    </form>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'run_migrations') {
    require_role('super_admin');

    $migrationResult = $_SESSION['migration_result'] ?? null;
    unset($_SESSION['migration_result']);

    header_html('Migrations');
?>
<section class="card">
    <h1>Migrations base de données</h1>

    <?php if($migrationResult && $migrationResult['error']): ?>
        <div class="alert">Migration impossible : <?=e($migrationResult['error'])?></div>
    <?php elseif($migrationResult && $migrationResult['applied']): ?>
        <div class="success-alert" role="status">Migration appliquée : <?=e(implode(', ', $migrationResult['applied']))?></div>
    <?php elseif($migrationResult): ?>
        <div class="success-alert" role="status">Base déjà à jour.</div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="run_migrations">
        <button class="btn" type="submit">Lancer les migrations</button>
    </form>

    <div class="actions">
        <a class="btn" href="index.php?page=dashboard">Retour dashboard</a>
        <a class="btn secondary" href="index.php?page=coach_calendar">Ouvrir le calendrier</a>
    </div>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'coach_settings') {
    require_role('coach');

    $settingsReady = coach_session_field_settings_ready();
    $visibility = get_coach_session_field_visibility((int)$u['id']);
    $fieldOptions = session_field_options();
    $success = $_SESSION['success'] ?? null;
    unset($_SESSION['success']);

    header_html('Paramètres coach');
?>
<?php if($success): ?>
    <div class="success-alert" role="status"><?=e($success)?></div>
<?php endif; ?>

<div class="toolbar">
    <h1>Paramètres coach</h1>
    <div class="actions">
        <a class="btn secondary" href="index.php?page=dashboard">Retour athlètes</a>
    </div>
</div>

<section class="card">
    <h2>Création de séance</h2>
    <p class="muted-text">Choisis les champs affichés directement dans le formulaire. Les champs décochés restent disponibles dans “Champs masqués”.</p>

    <?php if(!$settingsReady): ?>
        <div class="alert">La personnalisation sera active après passage par la page Migrations.</div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="save_coach_settings">

        <div class="settings-grid">
            <?php foreach($fieldOptions as $fieldKey => $label): ?>
                <label class="setting-choice">
                    <input type="checkbox" name="session_fields[]" value="<?=e($fieldKey)?>" <?=!empty($visibility[$fieldKey]) ? 'checked' : ''?>>
                    <span><?=e($label)?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <button class="btn" type="submit">Enregistrer les paramètres</button>
    </form>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'admin') {
    require_role('super_admin');

    $stmt = db()->prepare('SELECT id, name, email, created_at FROM users WHERE role="coach" ORDER BY created_at DESC, name');
    $stmt->execute();
    $coaches = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT COUNT(*) FROM athletes');
    $stmt->execute();
    $athleteCount = (int)$stmt->fetchColumn();

    $success = $_SESSION['success'] ?? null;
    unset($_SESSION['success']);

    header_html('Administration');
?>
<?php if($success): ?>
    <div class="success-alert" role="status"><?=e($success)?></div>
<?php endif; ?>

<div class="toolbar">
    <h1>Administration</h1>
    <div class="actions">
        <a class="btn secondary" href="index.php?page=dashboard">Voir tous les athlètes</a>
        <a class="btn secondary" href="index.php?page=coach_calendar">Calendrier général</a>
        <a class="btn" href="index.php?page=coach_settings">Paramètres</a>
    </div>
</div>

<div class="stats-grid">
    <section class="stat-card"><strong><?=count($coaches)?></strong><span>Coachs</span></section>
    <section class="stat-card"><strong><?=$athleteCount?></strong><span>Athlètes</span></section>
</div>

<div class="grid">
    <section class="card">
        <h2>Ajouter un coach</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="create_coach">

            <div class="field">
                <label>Nom</label>
                <input name="name" required>
            </div>

            <div class="field">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>

            <div class="field">
                <label for="coach-password">Mot de passe temporaire <small>(8 caractères minimum, à transmettre au coach)</small></label>
                <input id="coach-password" name="password" minlength="8" autocomplete="new-password" required>
            </div>

            <button class="btn">Créer le coach</button>

        </form>
    </section>

    <section class="card">
        <h2>Coachs existants</h2>
        <?php if(!$coaches): ?>
            <p class="muted-text">Aucun coach pour le moment.</p>
        <?php endif; ?>
        <div class="overview-list">
            <?php foreach($coaches as $coach): ?>
                <article class="overview-row">
                    <div>
                        <strong><?=e($coach['name'])?></strong>
                        <p><?=e($coach['email'])?></p>
                    </div>
                    <span>Créé le <?=e(format_short_date($coach['created_at']))?></span>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php
    footer_html();
    exit;
}

if ($page === 'daily_debrief') {
    $athleteId = (int)($_GET['athlete_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!valid_date((string)$date) || !can_access_athlete($athleteId)) deny_access();

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.id=?');
    $stmt->execute([$athleteId]);
    $a = $stmt->fetch();
    $athletePaces = get_athlete_paces($athleteId);

    $debriefStorageReady = table_exists('daily_debriefs');
    $debrief = get_daily_debrief($athleteId, $date);

    // A day with a planned session is debriefed on the session itself, unless a daily
    // retour was already written before the session was added: then it stays readable here.
    $stmt = db()->prepare('SELECT id, title FROM sessions WHERE athlete_id=? AND date=? ORDER BY id');
    $stmt->execute([$athleteId, $date]);
    $daySessions = $stmt->fetchAll();
    if ($daySessions && !$debrief['exists']) redirect('index.php?page=session&id='.(int)$daySessions[0]['id']);

    $canEditDebrief = $u['role'] === 'athlete' && $debriefStorageReady;

    header_html('Retour journalier');
?>
<section class="card debrief-card">
    <div class="debrief-head">
        <div>
            <h1>Retour du <?=e(format_full_date($date))?></h1>
            <p class="muted-text"><?=e($a['first_name'].' '.$a['last_name'])?><?=$daySessions ? '' : ' · Aucune séance planifiée ce jour.'?></p>
        </div>
        <a class="btn secondary small" href="<?=e(session_calendar_url($athleteId, substr($date, 0, 7)))?>">Retour calendrier</a>
    </div>

    <?php if($daySessions): ?>
        <div class="alert">Ce retour a été écrit avant l'ajout d'une séance ce jour-là :
            <?php foreach($daySessions as $i => $daySession): ?>
                <?=$i ? ', ' : ''?><a href="index.php?page=session&id=<?=(int)$daySession['id']?>"><?=e($daySession['title'])?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if($debrief['exists']): ?>
        <div class="debrief-summary">
            <div class="metric-box"><strong><?=e($debrief['difficulty'] ?: '-')?>/10</strong><span>Difficulté</span></div>
            <div class="metric-box"><strong><?=e(format_distance($debrief['actual_distance_km']))?></strong><span>Volume réalisé</span></div>
            <div class="metric-box"><strong><?=e($debrief['temperature_c'] !== null ? $debrief['temperature_c'].' °C' : '-')?></strong><span>Température</span></div>
            <div class="metric-box"><strong><?=e($debrief['lactates'] ?: '-')?></strong><span>Lactates</span></div>
        </div>
        <?php if($debrief['result']): ?>
            <div class="detail-item highlight-item"><strong>Chronos / résultat</strong><br><?=nl2br(e($debrief['result']))?></div>
        <?php endif; ?>
        <?php if($debrief['sensations']): ?>
            <div class="detail-item highlight-item"><strong>Sensations / commentaires</strong><br><?=nl2br(e($debrief['sensations']))?></div>
        <?php endif; ?>
        <?php weather_read_html($debrief['weather']); ?>
    <?php endif; ?>

    <?php if($canEditDebrief): ?>
        <form class="debrief-form" method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="save_daily_debrief">
            <input type="hidden" name="athlete_id" value="<?=$athleteId?>">
            <input type="hidden" name="date" value="<?=e($date)?>">
            <?php debrief_form_fields($debrief); ?>
            <button class="btn" type="submit">Enregistrer le retour</button>
        </form>
    <?php elseif(!$debriefStorageReady): ?>
        <div class="alert">Les retours journaliers ne sont pas encore disponibles.</div>
    <?php elseif(!$debrief['exists']): ?>
        <p class="muted-text">Aucun retour enregistré pour cette journée.</p>
    <?php endif; ?>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'quick_session') {
    require_role('coach');
    $athleteId = (int)($_GET['athlete_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!valid_date((string)$date) || !can_access_athlete($athleteId)) deny_access();

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.id=?');
    $stmt->execute([$athleteId]);
    $a = $stmt->fetch();

    header_html('Ajouter une séance');
?>
<section class="card">
    <div class="debrief-head">
        <div>
            <h1>Ajouter une séance</h1>
            <p class="muted-text"><?=e($a['first_name'].' '.$a['last_name'])?> · <?=e(format_full_date($date))?></p>
        </div>
        <a class="btn secondary small" href="<?=e(session_calendar_url($athleteId, substr($date, 0, 7)))?>">Retour calendrier</a>
    </div>

    <form method="post" class="debrief-form">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="quick_session">
        <input type="hidden" name="athlete_id" value="<?=$athleteId?>">
        <input type="hidden" name="date" value="<?=e($date)?>">

        <div class="field">
            <label for="quick-title">Titre</label>
            <input id="quick-title" name="title" required autofocus>
        </div>

        <div class="field">
            <label for="quick-description">Description</label>
            <textarea id="quick-description" name="description" class="large-textarea"></textarea>
        </div>

        <div class="actions">
            <button class="btn" type="submit">Ajouter la séance</button>
            <a class="btn secondary" href="index.php?page=edit_session&athlete_id=<?=$athleteId?>&date=<?=e($date)?>">Formulaire complet</a>
        </div>
    </form>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'dashboard') {
    require_role('coach');

    header_html('Dashboard');

    $q = trim($_GET['q'] ?? '');

    $coachAthleteWhere = coach_athlete_where('a');
    $coachAthleteParams = coach_athlete_params((int)$u['id']);

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE ' . $coachAthleteWhere . ' AND CONCAT(a.first_name," ",a.last_name," ",a.email) LIKE ? ORDER BY a.created_at DESC');
    $stmt->execute(array_merge($coachAthleteParams, ['%'.$q.'%']));
    $athletes = $stmt->fetchAll();

    [$weekStart, $weekEnd] = week_bounds();

    $today = date('Y-m-d');
    $inSevenDays = (new DateTime($today))->modify('+7 days')->format('Y-m-d');
    $hasStatus = table_has_column('sessions', 'status');
    $plannedWhere = $hasStatus ? 's.status="planned" AND s.date >= ?' : 's.date >= ?';
    $doneWhere = $hasStatus ? 's.status="done"' : 's.date < ?';
    $doneParams = $hasStatus ? [] : [$today];
    $weekStatusWhere = $hasStatus ? ' AND s.status <> "cancelled"' : '';
    $usesDistanceLoad = table_has_column('sessions', 'actual_distance_km') || table_has_column('sessions', 'planned_distance_km');
    $debriefJoin = table_has_column('session_debriefs', 'actual_distance_km')
        ? ' LEFT JOIN session_debriefs sd ON sd.session_id=s.id'
        : '';
    $loadExpr = $usesDistanceLoad
        ? 'COALESCE(SUM(' . run_equivalent_sql('COALESCE(' .
            ($debriefJoin ? 'sd.actual_distance_km, ' : '') .
            (table_has_column('sessions', 'actual_distance_km') ? 's.actual_distance_km' : 'NULL') . ', ' .
            (table_has_column('sessions', 'planned_distance_km') ? 's.planned_distance_km' : 'NULL') . ', 0)', 's.type') . '), 0)'
        : (table_has_column('sessions', 'actual_duration_min') || table_has_column('sessions', 'duration_min')
        ? 'COALESCE(SUM(COALESCE(' .
            (table_has_column('sessions', 'actual_duration_min') ? 's.actual_duration_min' : 'NULL') . ', ' .
            (table_has_column('sessions', 'duration_min') ? 's.duration_min' : 'NULL') . ', 0)), 0)'
        : '0');

    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE ' . $coachAthleteWhere . ' AND ' . $plannedWhere);
    $stmt->execute(array_merge($coachAthleteParams, [$today]));
    $plannedCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE ' . $coachAthleteWhere . ' AND ' . $doneWhere);
    $stmt->execute(array_merge($coachAthleteParams, $doneParams));
    $doneCount = (int)$stmt->fetchColumn();

    $alertParts = [];
    if (table_has_column('sessions', 'feeling')) $alertParts[] = '(s.feeling IS NOT NULL AND s.feeling >= 7)';
    if (table_has_column('sessions', 'pain')) $alertParts[] = '(s.pain IS NOT NULL AND s.pain >= 7)';
    if ($alertParts) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE ' . $coachAthleteWhere . ' AND (' . implode(' OR ', $alertParts) . ')');
        $stmt->execute($coachAthleteParams);
        $alertCount = (int)$stmt->fetchColumn();
    } else {
        $alertCount = 0;
    }

    $stmt = db()->prepare('SELECT ' . $loadExpr . ' FROM sessions s JOIN athletes a ON a.id=s.athlete_id' . $debriefJoin . ' WHERE ' . $coachAthleteWhere . $weekStatusWhere . ' AND s.date BETWEEN ? AND ?');
    $stmt->execute(array_merge($coachAthleteParams, [$weekStart, $weekEnd]));
    $weekLoad = (float)$stmt->fetchColumn();
    if ($usesDistanceLoad && table_has_column('daily_debriefs', 'actual_distance_km')) {
        $stmt = db()->prepare('SELECT COALESCE(SUM(d.actual_distance_km), 0) FROM daily_debriefs d JOIN athletes a ON a.id=d.athlete_id WHERE ' . $coachAthleteWhere . ' AND d.date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM sessions s2 WHERE s2.athlete_id=d.athlete_id AND s2.date=d.date)');
        $stmt->execute(array_merge($coachAthleteParams, [$weekStart, $weekEnd]));
        $weekLoad += (float)$stmt->fetchColumn();
    }
    $weekLoadLabel = $usesDistanceLoad ? format_distance($weekLoad) : ((int)$weekLoad . ' min');

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE ' . $coachAthleteWhere . ' AND CONCAT(a.first_name," ",a.last_name," ",a.email) LIKE ? ORDER BY a.first_name, a.last_name');
    $stmt->execute(array_merge($coachAthleteParams, ['%'.$q.'%']));
    $overview = $stmt->fetchAll();

    // One grouped query for all athletes instead of five queries per athlete.
    $statsById = [];
    $nextById = [];
    $overviewIds = array_map(function ($row) { return (int)$row['id']; }, $overview);
    if ($overviewIds) {
        $in = implode(',', array_fill(0, count($overviewIds), '?'));
        $plannedFlag = $hasStatus ? 's.status="planned"' : '1=1';
        $stmt = db()->prepare(
            'SELECT s.athlete_id,
                MIN(CASE WHEN ' . $plannedFlag . ' AND s.date >= ? THEN s.date END) AS next_date,
                MAX(CASE WHEN ' . $doneWhere . ' THEN s.date END) AS last_done_date,
                ' . (table_has_column('sessions', 'feeling') ? 'AVG(s.feeling)' : 'NULL') . ' AS avg_feeling,
                SUM(CASE WHEN ' . $plannedFlag . ' AND s.date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS planned_next_week,
                MAX(s.date) AS last_session_date
             FROM sessions s WHERE s.athlete_id IN (' . $in . ') GROUP BY s.athlete_id'
        );
        $stmt->execute(array_merge([$today], $doneParams, [$today, $inSevenDays], $overviewIds));
        foreach ($stmt->fetchAll() as $statRow) {
            $statsById[(int)$statRow['athlete_id']] = $statRow;
        }

        $stmt = db()->prepare(
            'SELECT s.athlete_id, s.title, s.date FROM sessions s
             WHERE s.athlete_id IN (' . $in . ') AND ' . $plannedWhere . '
             ORDER BY s.date, s.id'
        );
        $stmt->execute(array_merge($overviewIds, [$today]));
        foreach ($stmt->fetchAll() as $nextRow) {
            $nextById[(int)$nextRow['athlete_id']] = $nextById[(int)$nextRow['athlete_id']] ?? $nextRow;
        }
    }

    foreach ($overview as &$row) {
        $stats = $statsById[(int)$row['id']] ?? [];
        $row['next_session'] = $nextById[(int)$row['id']] ?? null;
        $row['next_date'] = $row['next_session']['date'] ?? null;
        $row['last_done_date'] = $stats['last_done_date'] ?? null;
        $row['avg_feeling'] = $stats['avg_feeling'] ?? null;
        $row['planned_next_week'] = (int)($stats['planned_next_week'] ?? 0);
        $row['last_session_date'] = $stats['last_session_date'] ?? null;
    }
    unset($row);

    $reminders = array_values(array_filter($overview, function ($row) { return (int)$row['planned_next_week'] === 0; }));
    $availableCoaches = get_available_coaches();
    $defaultPaces = default_pace_presets();
?>
<div class="toolbar">
    <h1>Dashboard coach</h1>

    <div class="actions">
        <a class="btn" href="index.php?page=coach_calendar">Calendrier général</a>
        <a class="btn secondary" href="index.php?page=coach_settings">Paramètres</a>
    </div>

    <form role="search">
        <input type="hidden" name="page" value="dashboard">
        <input class="search" type="search" name="q" aria-label="Rechercher un athlète" placeholder="Rechercher un athlète" value="<?=e($q)?>">
    </form>
</div>

<div class="stats-grid">
    <section class="stat-card"><strong><?=count($athletes)?></strong><span>Athlètes</span></section>
    <section class="stat-card"><strong><?=$plannedCount?></strong><span>Séances à venir</span></section>
    <section class="stat-card"><strong><?=$doneCount?></strong><span>Séances réalisées</span></section>
    <section class="stat-card"><strong><?=e($weekLoadLabel)?></strong><span>Charge semaine</span></section>
    <section class="stat-card warning"><strong><?=count($reminders)?></strong><span>Rappels planning</span></section>
    <section class="stat-card danger"><strong><?=$alertCount?></strong><span>Alertes fatigue</span></section>
</div>

<section class="focus-strip">
    <div>
        <span class="eyebrow">Priorité coach</span>
        <strong><?=count($reminders)?> athlète<?=count($reminders) > 1 ? 's' : ''?> sans séance dans les 7 prochains jours</strong>
        <p>Commence par les athlètes sans prochaine séance planifiée.</p>
    </div>
    <div class="actions">
        <a class="btn secondary" href="#rappels">Voir les rappels</a>
        <a class="btn secondary" href="index.php?page=coach_calendar">Ouvrir le calendrier</a>
    </div>
</section>

<section class="card">
    <h2>Vision globale</h2>
    <div class="overview-list">
        <?php foreach($overview as $row): ?>
            <article class="overview-row">
                <div>
                    <strong><?=e($row['first_name'].' '.$row['last_name'])?></strong>
                    <p><?=e($row['sport'])?> · <?=e($row['level'])?> · VMA <?=e($row['vma'])?> km/h</p>
                </div>
                <div>
                    <span>Prochaine : <?=e($row['next_date'] ? relative_day_label($row['next_date']).' - '.format_short_date($row['next_date']) : 'aucune')?></span>
                    <span><?=e($row['next_session']['title'] ?? 'Aucune séance programmée')?></span>
                    <span>Ressenti moy. : <?=e($row['avg_feeling'] ? round((float)$row['avg_feeling'], 1).'/10' : '-')?></span>
                </div>
                <div class="row-actions">
                    <a class="btn secondary small" href="index.php?page=calendar&athlete_id=<?=$row['id']?>">Calendrier</a>
                    <a class="btn secondary small" href="index.php?page=edit_athlete&id=<?=$row['id']?>">Modifier</a>
                    <a class="btn small" href="index.php?page=edit_session&athlete_id=<?=$row['id']?>&date=<?=date('Y-m-d')?>">Séance</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" id="rappels">
    <h2>Athlètes sans séance dans les 7 prochains jours</h2>
    <?php if(!$reminders): ?>
        <p class="muted-text">Aucun rappel pour le moment.</p>
    <?php endif; ?>
    <div class="overview-list">
        <?php foreach($reminders as $row): ?>
            <article class="overview-row warning-row">
                <div>
                    <strong><?=e($row['first_name'].' '.$row['last_name'])?></strong>
                    <span>Dernière séance : <?=e($row['last_session_date'] ? relative_day_label($row['last_session_date']).' - '.format_short_date($row['last_session_date']) : 'aucune')?></span>
                </div>
                <a class="btn small" href="index.php?page=edit_session&athlete_id=<?=$row['id']?>&date=<?=date('Y-m-d')?>">Planifier</a>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid">
    <section class="card" id="add-athlete">
        <h2>Ajouter un athlète</h2>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="create_athlete">

            <div class="field">
                <label for="new-athlete-first-name">Prénom</label>
                <input id="new-athlete-first-name" name="first_name" autocomplete="off" required>
            </div>

            <div class="field">
                <label for="new-athlete-last-name">Nom</label>
                <input id="new-athlete-last-name" name="last_name" autocomplete="off" required>
            </div>

            <div class="field">
                <label for="new-athlete-email">Email</label>
                <input id="new-athlete-email" type="email" name="email" autocomplete="off" autocapitalize="none" required>
            </div>

            <div class="field">
                <label for="new-athlete-sport">Sport</label>
                <input id="new-athlete-sport" name="sport" value="Course" required>
            </div>

            <div class="field">
                <label for="new-athlete-level">Niveau</label>
                <input id="new-athlete-level" name="level" value="Intermédiaire" required>
            </div>

            <div class="field">
                <label for="new-athlete-goal">Objectif</label>
                <input id="new-athlete-goal" name="goal" placeholder="Marathon, reprise, 10 km...">
            </div>

            <div class="field">
                <label for="new-athlete-vma">VMA (km/h)</label>
                <input id="new-athlete-vma" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9])?" name="vma" value="15" required>
            </div>

            <?php athlete_pace_fields($defaultPaces); ?>

            <div class="field">
                <label for="new-athlete-notes">Notes</label>
                <textarea id="new-athlete-notes" name="notes" placeholder="Contraintes, blessures, disponibilités..."></textarea>
            </div>

            <?php if($u['role'] === 'super_admin'): ?>
                <div class="field">
                    <label>Coach principal</label>
                    <select name="primary_coach_id" required>
                        <option value="">Choisir un coach</option>
                        <?php foreach($availableCoaches as $coach): ?>
                            <option value="<?=$coach['id']?>"><?=e($coach['name'].' - '.$coach['email'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="field">
                <label>Coach secondaire</label>
                <select name="secondary_coach_id">
                    <option value="">Aucun</option>
                    <?php foreach($availableCoaches as $coach): ?>
                        <?php if($u['role'] !== 'super_admin' && (int)$coach['id'] === (int)$u['id']) continue; ?>
                        <option value="<?=$coach['id']?>"><?=e($coach['name'].' - '.$coach['email'])?></option>
                    <?php endforeach; ?>
                </select>
                <?php if(!athlete_coaches_ready()): ?>
                    <small>Disponible après passage par la page Migrations.</small>
                <?php endif; ?>
            </div>

            <div class="field">
                <label for="new-athlete-password">Mot de passe temporaire <small>(8 caractères minimum, à transmettre à l'athlète)</small></label>
                <input id="new-athlete-password" name="password" minlength="8" autocomplete="new-password" required>
            </div>

            <button class="btn">Créer</button>
        </form>
    </section>
</div>

<section class="card">
    <h2>Mes athlètes</h2>

    <table class="table">
        <thead>
            <tr>
                <th>Nom</th>
                <th>Email</th>
                <th>Profil</th>
                <th>Actions</th>
            </tr>
        </thead>

        <tbody>
        <?php foreach($athletes as $a): ?>
            <tr>
                <td><?=e($a['first_name'].' '.$a['last_name'])?></td>
                <td><?=e($a['email'])?></td>
                <td><?=e($a['sport'])?> · <?=e($a['level'])?> · VMA <?=e($a['vma'])?></td>
                <td><div class="actions">
                    <a class="btn small" href="index.php?page=calendar&athlete_id=<?=$a['id']?>">Calendrier</a>
                    <a class="btn secondary small" href="index.php?page=edit_athlete&id=<?=$a['id']?>">Modifier</a>

                    <?php if($u['role'] === 'super_admin' || (int)$a['coach_id'] === (int)$u['id']): ?>
                        <form method="post" onsubmit="return confirm('Supprimer cet athlète, ses séances, ses retours et son compte ? Cette action est définitive.')">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <input type="hidden" name="action" value="delete_athlete">
                            <input type="hidden" name="id" value="<?=$a['id']?>">
                            <button class="btn danger small">Supprimer</button>
                        </form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'edit_athlete') {
    require_role('coach');

    $id = (int)$_GET['id'];

    if (!can_access_athlete($id)) deny_access();

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.id=?');
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    $availableCoaches = get_available_coaches();
    $athletePaces = get_athlete_paces($id);
    $assignedCoaches = get_coaches_for_athlete($id);
    $secondaryCoachId = null;
    foreach ($assignedCoaches as $coach) {
        if ((int)$coach['id'] !== (int)$a['coach_id']) {
            $secondaryCoachId = (int)$coach['id'];
            break;
        }
    }
    $canManageAccess = $u['role'] === 'super_admin' || (int)$a['coach_id'] === (int)$u['id'];

    header_html('Modifier athlète');
?>
<section class="card">
    <div class="debrief-head">
        <h1>Modifier athlète</h1>
        <a class="btn secondary small" href="index.php?page=calendar&athlete_id=<?=$id?>">Annuler</a>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="update_athlete">
        <input type="hidden" name="id" value="<?=$a['id']?>">

        <div class="field">
            <label for="athlete-first-name">Prénom</label>
            <input id="athlete-first-name" name="first_name" value="<?=e($a['first_name'])?>" required>
        </div>

        <div class="field">
            <label for="athlete-last-name">Nom</label>
            <input id="athlete-last-name" name="last_name" value="<?=e($a['last_name'])?>" required>
        </div>

        <div class="field">
            <label for="athlete-email">Email <?=$canManageAccess ? '' : '<small>(modifiable par le coach principal)</small>'?></label>
            <input id="athlete-email" type="email" name="email" value="<?=e($a['email'])?>" autocapitalize="none" <?=$canManageAccess ? 'required' : 'readonly'?>>
        </div>

        <div class="field">
            <label for="athlete-sport">Sport</label>
            <input id="athlete-sport" name="sport" value="<?=e($a['sport'])?>" required>
        </div>

        <div class="field">
            <label for="athlete-level">Niveau</label>
            <input id="athlete-level" name="level" value="<?=e($a['level'])?>" required>
        </div>

        <div class="field">
            <label for="athlete-goal">Objectif</label>
            <input id="athlete-goal" name="goal" value="<?=e($a['goal'])?>">
        </div>

        <div class="field">
            <label for="athlete-vma">VMA (km/h)</label>
            <input id="athlete-vma" type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9])?" name="vma" value="<?=e($a['vma'])?>" required>
        </div>

        <?php athlete_pace_fields($athletePaces); ?>

        <div class="field">
            <label for="athlete-notes">Notes</label>
            <textarea id="athlete-notes" name="notes"><?=e($a['notes'])?></textarea>
        </div>

        <?php if($u['role'] === 'super_admin'): ?>
            <div class="field">
                <label>Coach principal</label>
                <select name="primary_coach_id" required>
                    <?php foreach($availableCoaches as $coach): ?>
                        <option value="<?=$coach['id']?>" <?=((int)$a['coach_id'] === (int)$coach['id']) ? 'selected' : ''?>>
                            <?=e($coach['name'].' - '.$coach['email'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="athlete-secondary-coach">Coach secondaire <?=$canManageAccess ? '' : '<small>(modifiable par le coach principal)</small>'?></label>
            <select id="athlete-secondary-coach" name="secondary_coach_id" <?=$canManageAccess ? '' : 'disabled'?>>
                <option value="">Aucun</option>
                <?php foreach($availableCoaches as $coach): ?>
                    <?php if((int)$coach['id'] === (int)$a['coach_id']) continue; ?>
                    <option value="<?=$coach['id']?>" <?=$secondaryCoachId === (int)$coach['id'] ? 'selected' : ''?>>
                        <?=e($coach['name'].' - '.$coach['email'])?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if(!athlete_coaches_ready()): ?>
                <small>Disponible après passage par la page Migrations.</small>
            <?php endif; ?>
        </div>

        <button class="btn">Enregistrer</button>
    </form>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'coach_calendar') {
    require_role('coach');

    $month = month_start($_GET['month'] ?? null);
    $start = (clone $month)->modify('monday this week');
    $end = (clone $month)->modify('last day of this month')->modify('sunday this week');

    $selectedAthleteId = (int)($_GET['athlete_id'] ?? 0);

    $coachAthleteWhere = coach_athlete_where('a');
    $coachAthleteParams = coach_athlete_params((int)$u['id']);

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE ' . $coachAthleteWhere . ' ORDER BY a.first_name, a.last_name');
    $stmt->execute($coachAthleteParams);
    $athletes = $stmt->fetchAll();

    if ($selectedAthleteId > 0 && !can_access_athlete($selectedAthleteId)) {
        deny_access();
    }

    if ($selectedAthleteId > 0) {
        $stmt = db()->prepare('SELECT ' . session_select_sql('s') . ', a.first_name, a.last_name FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE ' . $coachAthleteWhere . ' AND s.athlete_id=? AND s.date BETWEEN ? AND ? ORDER BY s.date, a.first_name, a.last_name');
        $stmt->execute(array_merge(
            $coachAthleteParams,
            [
                $selectedAthleteId,
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            ]
        ));
    } else {
        $stmt = db()->prepare('SELECT ' . session_select_sql('s') . ', a.first_name, a.last_name FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE ' . $coachAthleteWhere . ' AND s.date BETWEEN ? AND ? ORDER BY s.date, a.first_name, a.last_name');
        $stmt->execute(array_merge(
            $coachAthleteParams,
            [
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            ]
        ));
    }

    $sessions = [];

    $periodSessions = attach_debriefs_to_sessions($stmt->fetchAll());

    foreach ($periodSessions as $s) {
        $sessions[$s['date']][] = $s;
    }
    $dailyDebriefs = $selectedAthleteId > 0
        ? attach_daily_debriefs_to_calendar($selectedAthleteId, $start, $end)
        : [];

    header_html('Calendrier général');

    $prev = (clone $month)->modify('-1 month')->format('Y-m');
    $next = (clone $month)->modify('+1 month')->format('Y-m');

    $filterParam = $selectedAthleteId > 0 ? '&athlete_id=' . $selectedAthleteId : '';
?>
<div class="calendar-head">
    <div>
        <h1>Calendrier général coach</h1>
        <p><?=e(format_month_label($month))?></p>
    </div>

    <div class="actions">
        <a class="btn secondary" href="index.php?page=coach_calendar&month=<?=$prev?><?=$filterParam?>">Mois précédent</a>
        <a class="btn secondary" href="index.php?page=coach_calendar&month=<?=$next?><?=$filterParam?>">Mois suivant</a>
    </div>
</div>

<section class="card calendar-filter-card">
    <form class="calendar-filter" method="get">
        <input type="hidden" name="page" value="coach_calendar">
        <input type="hidden" name="month" value="<?=$month->format('Y-m')?>">

        <div class="field">
            <label for="calendar-athlete-filter">Filtrer par athlète</label>
            <select id="calendar-athlete-filter" name="athlete_id" onchange="this.form.submit()">
                <option value="0">Tous les athlètes</option>
                <?php foreach($athletes as $athlete): ?>
                    <option value="<?=$athlete['id']?>" <?=$selectedAthleteId === (int)$athlete['id'] ? 'selected' : ''?>>
                        <?=e($athlete['first_name'].' '.$athlete['last_name'])?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn secondary small">Filtrer</button>
    </form>
</section>

<div class="calendar iphone-calendar coach-calendar">
    <?php foreach(['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?>
        <div class="day-name"><?=$d?></div>
    <?php endforeach; ?>

    <?php
    $d = clone $start;

    while ($d <= $end):
        $date = $d->format('Y-m-d');
        $daySessions = $sessions[$date] ?? [];
        $dailyDebrief = $dailyDebriefs[$date] ?? null;
    ?>
        <div class="day <?=$d->format('m') !== $month->format('m') ? 'muted' : ''?>">
            <div class="day-num"><?=$d->format('d/m')?></div>

            <?php if($selectedAthleteId > 0 && !$daySessions): ?>
                <a class="empty-day-action" href="index.php?page=quick_session&athlete_id=<?=$selectedAthleteId?>&date=<?=$date?>" aria-label="Ajouter une séance le <?=e(format_full_date($date))?>" title="Ajouter une séance">
                    <span aria-hidden="true">+</span>
                </a>
            <?php elseif($selectedAthleteId > 0): ?>
                <a class="day-add-link" href="index.php?page=quick_session&athlete_id=<?=$selectedAthleteId?>&date=<?=$date?>" aria-label="Ajouter une autre séance le <?=e(format_full_date($date))?>" title="Ajouter une autre séance">+</a>
            <?php endif; ?>

            <?php if($dailyDebrief): ?>
                <a class="daily-debrief-pill" href="index.php?page=daily_debrief&athlete_id=<?=$selectedAthleteId?>&date=<?=$date?>" aria-label="Consulter le retour du <?=e(format_full_date($date))?>" title="Retour enregistré">
                    <span class="debrief-dot debrief-debriefed" aria-hidden="true"></span>
                </a>
            <?php endif; ?>

            <?php foreach($daySessions as $s):
                $debriefStatus = debrief_summary_status($s);
            ?>
                <a class="session-pill <?=e(type_class($s['type']))?>" href="index.php?page=session&id=<?=$s['id']?>" title="<?=e($s['first_name'].' '.$s['last_name'].' — '.$s['title'])?>">
                    <span class="session-athlete"><?=e($s['first_name'])?></span>
                    <span class="session-initials" aria-hidden="true"><?=e(athlete_initials($s['first_name'], $s['last_name']))?></span>
                    <span class="session-title"><?=e($s['title'])?></span>
                    <span class="session-meta"><?=e($s['type'])?><?=session_volume_label($s) ? ' · '.e(session_volume_label($s)) : ''?></span>
                    <span class="debrief-dot debrief-<?=$debriefStatus?>" role="img" aria-label="<?=e(debrief_status_label($debriefStatus))?>"></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php
        $d->modify('+1 day');
    endwhile;
    ?>
</div>
<?php
    footer_html();
    exit;
}

if ($page === 'calendar') {
    $athleteId = (int)$_GET['athlete_id'];

    if (!can_access_athlete($athleteId)) deny_access();

    $month = month_start($_GET['month'] ?? null);
    $start = (clone $month)->modify('monday this week');
    $end = (clone $month)->modify('last day of this month')->modify('sunday this week');

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.id=?');
    $stmt->execute([$athleteId]);
    $a = $stmt->fetch();

    $stmt = db()->prepare('SELECT ' . session_select_sql('s') . ' FROM sessions s WHERE s.athlete_id=? AND s.date BETWEEN ? AND ? ORDER BY s.date');
    $stmt->execute([
        $athleteId,
        $start->format('Y-m-d'),
        $end->format('Y-m-d')
    ]);

    $sessions = [];
    $monthSessions = [];
    $nextSession = null;
    $athletePaces = get_athlete_paces($athleteId);
    [$currentWeekStart, $currentWeekEnd] = week_bounds();

    $weekStatusWhere = table_has_column('sessions', 'status') ? ' AND s.status <> "cancelled"' : '';
    $weekStmt = db()->prepare('SELECT ' . session_select_sql('s') . ' FROM sessions s WHERE s.athlete_id=? AND s.date BETWEEN ? AND ?' . $weekStatusWhere);
    $weekStmt->execute([$athleteId, $currentWeekStart, $currentWeekEnd]);
    $weekSessions = attach_debriefs_to_sessions($weekStmt->fetchAll());
    $weekSessionCount = count($weekSessions);
    $weekVolumes = ['run' => 0.0, 'bike' => 0.0, 'swim' => 0.0];
    $weekRunEquivalent = 0.0;
    foreach ($weekSessions as $weekSession) {
        $volume = session_volume($weekSession);
        $weekVolumes[session_sport((string)$weekSession['type'])] += $volume;
        $weekRunEquivalent += run_equivalent_km($volume, (string)$weekSession['type']);
    }
    // Km from daily retours count as running, only on days without a session
    // (otherwise the session's own km are already counted).
    $weekSessionDates = array_flip(array_column($weekSessions, 'date'));
    foreach (attach_daily_debriefs_to_calendar($athleteId, new DateTime($currentWeekStart), new DateTime($currentWeekEnd)) as $dailyDate => $weekDailyDebrief) {
        if (isset($weekSessionDates[$dailyDate])) continue;
        $volume = (float)($weekDailyDebrief['actual_distance_km'] ?? 0);
        $weekVolumes['run'] += $volume;
        $weekRunEquivalent += $volume;
    }

    $periodSessions = attach_debriefs_to_sessions($stmt->fetchAll());

    foreach ($periodSessions as $s) {
        $sessions[$s['date']][] = $s;
        $monthSessions[] = $s;

        if ($s['date'] >= date('Y-m-d') && (!$nextSession || $s['date'] < $nextSession['date'])) {
            $nextSession = $s;
        }

    }
    $dailyDebriefs = attach_daily_debriefs_to_calendar($athleteId, $start, $end);

    header_html('Calendrier');

    $prev = (clone $month)->modify('-1 month')->format('Y-m');
    $next = (clone $month)->modify('+1 month')->format('Y-m');
?>
<div class="calendar-head">
    <div>
        <h1>Calendrier — <?=e($a['first_name'].' '.$a['last_name'])?></h1>
        <p><?=e(format_month_label($month))?></p>
    </div>

    <div class="actions">
        <a class="btn secondary" href="index.php?page=calendar&athlete_id=<?=$athleteId?>&month=<?=$prev?>">Mois précédent</a>
        <a class="btn secondary" href="index.php?page=calendar&athlete_id=<?=$athleteId?>&month=<?=$next?>">Mois suivant</a>

        <?php if(in_array($u['role'], ['coach', 'super_admin'], true)): ?>
            <a class="btn secondary" href="index.php?page=edit_athlete&id=<?=$athleteId?>">Modifier le profil</a>
            <a class="btn" href="index.php?page=edit_session&athlete_id=<?=$athleteId?>&date=<?=date('Y-m-d')?>">Ajouter une séance</a>
        <?php endif; ?>
    </div>
</div>

<section class="athlete-next">
    <div class="next-session-panel">
        <span class="eyebrow">Prochaine séance</span>
        <?php if($nextSession): ?>
            <strong><?=e($nextSession['title'])?></strong>
            <p><?=e(relative_day_label($nextSession['date']))?> - <?=e(format_full_date($nextSession['date']))?> - <?=e($nextSession['type'])?></p>
            <a class="btn secondary small" href="index.php?page=session&id=<?=$nextSession['id']?>">Voir le détail</a>
        <?php else: ?>
            <strong>Aucune séance programmée</strong>
            <p>Le planning est vide pour les prochains jours affichés.</p>
        <?php endif; ?>
    </div>
    <div class="mini-metrics week-metrics">
        <div><strong><?=$weekSessionCount?></strong><span>Séances cette semaine</span></div>
        <div><strong><?=e(format_distance($weekVolumes['run']))?></strong><span>Course / marche</span></div>
        <div><strong><?=e(format_distance($weekVolumes['bike']))?></strong><span>Vélo</span></div>
        <div><strong><?=e(format_distance($weekVolumes['swim']))?></strong><span>Natation</span></div>
        <p class="week-equivalent">Équivalent course semaine : <strong><?=e(format_distance($weekRunEquivalent))?></strong> <small>(4 km vélo = 1 km · 1 km natation = 7 km)</small></p>
    </div>
</section>

<details class="card pace-card">
    <summary><h2>Rappel des allures <small class="muted-text">VMA <?=e($a['vma'])?> km/h</small></h2></summary>
    <div class="pace-grid">
        <?php foreach($athletePaces as $pacePreset):
            $percent = (float)$pacePreset['percent_vma'];
            $pace = pace_from_vma((float)$a['vma'], $percent);
            $speed = speed_from_vma((float)$a['vma'], $percent);
        ?>
            <div class="pace-cell">
                <strong><?=e($pacePreset['label'])?></strong>
                <span><?=e(format_speed($speed))?> · <?=e(format_pace($pace))?></span>
                <small><?=e(rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ','))?>% VMA · 400m <?=e(format_split($pace, 0.4))?> · 1000m <?=e(format_split($pace, 1))?></small>
            </div>
        <?php endforeach; ?>
    </div>
</details>

<div class="calendar iphone-calendar">
    <?php foreach(['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?>
        <div class="day-name"><?=$d?></div>
    <?php endforeach; ?>

    <?php
    $d = clone $start;

    while ($d <= $end):
        $date = $d->format('Y-m-d');
        $daySessions = $sessions[$date] ?? [];
        $dailyDebrief = $dailyDebriefs[$date] ?? null;
    ?>
        <div class="day <?=$d->format('m') !== $month->format('m') ? 'muted' : ''?>">
            <div class="day-num"><?=$d->format('d/m')?></div>

            <?php if(!$daySessions && !$dailyDebrief): ?>
                <?php if(in_array($u['role'], ['coach', 'super_admin'], true)): ?>
                    <a class="empty-day-action" href="index.php?page=quick_session&athlete_id=<?=$athleteId?>&date=<?=$date?>" aria-label="Ajouter une séance le <?=e(format_full_date($date))?>" title="Ajouter une séance">
                        <span aria-hidden="true">+</span>
                    </a>
                <?php elseif($u['role'] === 'athlete'): ?>
                    <a class="empty-day-action" href="index.php?page=daily_debrief&athlete_id=<?=$athleteId?>&date=<?=$date?>" aria-label="Ajouter un retour le <?=e(format_full_date($date))?>" title="Ajouter un retour">
                        <span aria-hidden="true">+</span>
                    </a>
                <?php endif; ?>
            <?php elseif(in_array($u['role'], ['coach', 'super_admin'], true)): ?>
                <a class="day-add-link" href="index.php?page=edit_session&athlete_id=<?=$athleteId?>&date=<?=$date?>" aria-label="Ajouter une autre séance le <?=e(format_full_date($date))?>">+</a>
            <?php endif; ?>

            <?php if($dailyDebrief): ?>
                <a class="daily-debrief-pill" href="index.php?page=daily_debrief&athlete_id=<?=$athleteId?>&date=<?=$date?>" aria-label="Consulter le retour du <?=e(format_full_date($date))?>" title="Retour enregistré">
                    <span class="debrief-dot debrief-debriefed" aria-hidden="true"></span>
                </a>
            <?php endif; ?>

            <?php foreach($daySessions as $s):
                $debriefStatus = debrief_summary_status($s);
            ?>
                <a class="session-pill <?=e(type_class($s['type']))?>" href="index.php?page=session&id=<?=$s['id']?>">
                    <span class="session-title"><?=e($s['title'])?></span>
                    <span class="session-meta"><?=e($s['type'])?><?=session_volume_label($s) ? ' · '.e(session_volume_label($s)) : ''?></span>
                    <span class="debrief-dot debrief-<?=$debriefStatus?>" role="img" aria-label="<?=e(debrief_status_label($debriefStatus))?>"></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php
        $d->modify('+1 day');
    endwhile;
    ?>
</div>
<section class="card month-agenda">
    <h2>Séances de la période</h2>
    <?php if(!$monthSessions): ?>
        <p class="muted-text">Aucune séance sur cette période.</p>
    <?php endif; ?>
    <div class="agenda-list">
        <?php foreach($monthSessions as $s):
            $debriefStatus = debrief_summary_status($s);
        ?>
            <a class="agenda-row" href="index.php?page=session&id=<?=$s['id']?>">
                <div>
                    <strong><?=e($s['title'])?></strong>
                    <span><?=e(format_full_date($s['date']))?> - <?=e($s['type'])?><?=session_volume_label($s) ? ' - '.e(session_volume_label($s)) : ''?></span>
                </div>
                <span>
                    <span class="status-badge status-<?=e($s['status'])?>"><?=e(status_label($s['status']))?></span>
                    <span class="status-badge debrief-badge debrief-<?=$debriefStatus?>"><?=e(debrief_status_label($debriefStatus))?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'edit_session') {
    require_role('coach');
    $fieldVisibility = get_coach_session_field_visibility((int)$u['id']);
    $fieldKeys = array_keys(session_field_options());
    $visibleFields = array_values(array_filter($fieldKeys, function ($fieldKey) use ($fieldVisibility) {
        return !empty($fieldVisibility[$fieldKey]);
    }));
    $hiddenFields = array_values(array_filter($fieldKeys, function ($fieldKey) use ($fieldVisibility) {
        return empty($fieldVisibility[$fieldKey]);
    }));

    $session = null;
    $athleteId = (int)($_GET['athlete_id'] ?? 0);

    if (!empty($_GET['id'])) {
        $session = get_session_checked((int)$_GET['id']);
        $athleteId = (int)$session['athlete_id'];
    }

    if (!can_access_athlete($athleteId)) deny_access();
    $athletePaces = get_athlete_paces($athleteId);
    $defaultDate = (string)($_GET['date'] ?? '');
    if (!valid_date($defaultDate)) $defaultDate = date('Y-m-d');
    $cancelUrl = $session ? 'index.php?page=session&id=' . (int)$session['id'] : session_calendar_url($athleteId, substr($defaultDate, 0, 7));

    header_html($session ? 'Modifier séance' : 'Créer séance');
?>
<section class="card">
    <div class="debrief-head">
        <h1><?=$session ? 'Modifier' : 'Créer'?> une séance</h1>
        <a class="btn secondary small" href="<?=e($cancelUrl)?>">Annuler</a>
    </div>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="save_session">
        <input type="hidden" name="athlete_id" value="<?=$athleteId?>">

        <?php if($session): ?>
            <input type="hidden" name="id" value="<?=$session['id']?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label for="session-date">Date</label>
                <input id="session-date" type="date" name="date" value="<?=e($session['date'] ?? $defaultDate)?>" required>
            </div>

            <div class="field">
                <label for="session-title">Titre court</label>
                <input id="session-title" name="title" value="<?=e($session['title'] ?? '')?>" required>
            </div>

            <?php foreach($visibleFields as $fieldKey): ?>
                <?php session_form_field($fieldKey, $session ?? [], $athletePaces); ?>
            <?php endforeach; ?>

            <?php if($hiddenFields): ?>
                <details class="advanced-fields full">
                    <summary>Champs masqués</summary>
                    <div class="form-grid">
                        <?php foreach($hiddenFields as $fieldKey): ?>
                            <?php session_form_field($fieldKey, $session ?? [], $athletePaces); ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <button class="btn">Enregistrer</button>
    </form>
</section>
<?php
    footer_html();
    exit;
}

if ($page === 'session') {
    $s = get_session_checked((int)$_GET['id']);
    $sessionPaces = get_athlete_paces((int)$s['athlete_id']);
    $pace = !empty($s['vma_percent']) ? pace_from_vma((float)$s['vma'], (float)$s['vma_percent']) : null;

    $stmt = db()->prepare('SELECT c.*, u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.session_id=? ORDER BY c.created_at');
    $stmt->execute([$s['id']]);
    $comments = $stmt->fetchAll();
    $debrief = get_debrief_for_session((int)$s['id']);
    $debriefStatus = debrief_summary_status(['date' => $s['date'], 'debrief' => $debrief]);
    $debriefStorageReady = table_exists('session_debriefs');
    $canEditDebrief = $u['role'] === 'athlete' && $debriefStorageReady;
    $dailyDebriefSameDay = table_exists('daily_debriefs') ? get_daily_debrief((int)$s['athlete_id'], (string)$s['date']) : empty_debrief();

    header_html('Détail séance');
?>
<a class="back-link" href="<?=e(session_calendar_url((int)$s['athlete_id'], substr((string)$s['date'], 0, 7)))?>">← Retour au calendrier</a>
<section class="card">
    <div class="toolbar">
        <div>
            <h1><?=e($s['title'])?></h1>
            <p>
                <?=e(format_long_date($s['date']))?> ·
                <span class="session-pill session-pill-inline <?=e(type_class($s['type']))?>">
                    <?=e($s['type'])?>
                </span>
                <span class="status-badge status-<?=e($s['status'])?>"><?=e(status_label($s['status']))?></span>
                <span class="status-badge debrief-badge debrief-<?=$debriefStatus?>"><?=e(debrief_status_label($debriefStatus))?></span>
            </p>
        </div>

        <?php if(in_array($u['role'], ['coach', 'super_admin'], true)): ?>
            <div class="actions">
                <a class="btn" href="index.php?page=edit_session&id=<?=$s['id']?>">Modifier</a>

                <form method="post" onsubmit="return confirm('Supprimer cette séance et son débrief ?')">
                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                    <input type="hidden" name="action" value="delete_session">
                    <input type="hidden" name="id" value="<?=$s['id']?>">
                    <button class="btn danger">Supprimer</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="detail-list">
        <div class="metrics-row">
            <div class="metric-box"><strong><?=e(intensity_label($s['intensity']))?></strong><span>Intensité</span></div>
            <div class="metric-box"><strong><?=e(format_distance($s['planned_distance_km']))?></strong><span>Volume prévu</span></div>
            <div class="metric-box"><strong><?=e(format_distance(session_actual_distance($s, $debrief)))?></strong><span>Volume réel</span></div>
            <div class="metric-box"><strong><?=e(pace_label_from_code($sessionPaces, $s['target_pace_code']))?></strong><span>Rythme visé</span></div>
            <div class="metric-box"><strong><?=e(format_pace($pace))?></strong><span>Allure</span></div>
            <div class="metric-box"><strong><?=e($s['duration_min'] ? $s['duration_min'].' min' : '-')?></strong><span>Durée prévue</span></div>
            <div class="metric-box"><strong><?=e($s['feeling'] ?: '-')?>/10</strong><span>Ressenti</span></div>
            <div class="metric-box"><strong><?=e($s['pain'] ?? '-')?>/10</strong><span>Douleur</span></div>
        </div>
        <?php foreach([
            'description' => 'Description',
            'objective' => 'Objectif',
            'warmup' => 'Échauffement',
            'main_workout' => 'Corps de séance',
            'cooldown' => 'Retour au calme',
            'coach_notes' => 'Conseils du coach',
            'athlete_feedback' => 'Retour athlète'
        ] as $k => $label): ?>
            <?php if(($s[$k] ?? '') === '') continue; ?>
            <div class="detail-item">
                <strong><?=$label?></strong><br>
                <?=nl2br(e($s[$k]))?>
            </div>
        <?php endforeach; ?>

        <?php if($s['external_link']): ?>
            <?php $safeLink = clean_external_link($s['external_link']); ?>
            <?php if($safeLink): ?>
                <a class="btn secondary keep-print" href="<?=e($safeLink)?>" target="_blank" rel="noopener noreferrer">Lien externe</a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if($s['attachment_url']): ?>
            <a class="btn secondary keep-print" href="<?=e($s['attachment_url'])?>" target="_blank" rel="noopener">Pièce jointe</a>
        <?php endif; ?>
    </div>
</section>

<section class="card debrief-card">
    <div class="debrief-head">
        <div>
            <h2>Débrief de la séance</h2>
            <p class="muted-text">
                <?php if($debrief['exists']): ?>
                    Dernière modification : <?=e(format_datetime($debrief['updated_at'] ?: $debrief['created_at']))?>
                <?php else: ?>
                    Aucun débrief enregistré pour le moment.
                <?php endif; ?>
            </p>
        </div>
        <span class="status-badge debrief-badge debrief-<?=$debriefStatus?>"><?=e(debrief_status_label($debriefStatus))?></span>
    </div>

    <?php if($debrief['exists']): ?>
        <div class="debrief-summary">
            <div class="metric-box"><strong><?=e($debrief['difficulty'] ?: '-')?>/10</strong><span>Difficulté</span></div>
            <div class="metric-box"><strong><?=e(format_distance($debrief['actual_distance_km']))?></strong><span>Volume réalisé</span></div>
            <div class="metric-box"><strong><?=e($debrief['temperature_c'] !== null ? $debrief['temperature_c'].' °C' : '-')?></strong><span>Température</span></div>
            <div class="metric-box"><strong><?=e($debrief['lactates'] ?: '-')?></strong><span>Lactates</span></div>
        </div>
        <?php if($debrief['result']): ?>
            <div class="detail-item highlight-item"><strong>Chronos / résultat</strong><br><?=nl2br(e($debrief['result']))?></div>
        <?php endif; ?>
        <?php if($debrief['sensations']): ?>
            <div class="detail-item highlight-item"><strong>Sensations / commentaires</strong><br><?=nl2br(e($debrief['sensations']))?></div>
        <?php endif; ?>
        <?php weather_read_html($debrief['weather']); ?>
    <?php endif; ?>

    <?php if($dailyDebriefSameDay['exists']): ?>
        <p class="muted-text">Un retour journalier a aussi été écrit ce jour-là : <a href="index.php?page=daily_debrief&athlete_id=<?=(int)$s['athlete_id']?>&date=<?=e($s['date'])?>">le consulter</a>.</p>
    <?php endif; ?>

    <?php if($canEditDebrief): ?>
        <form class="debrief-form" method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="save_debrief">
            <input type="hidden" name="session_id" value="<?=$s['id']?>">
            <?php debrief_form_fields($debrief); ?>

            <button class="btn" type="submit">Enregistrer le débrief</button>
        </form>
    <?php elseif(!$debriefStorageReady): ?>
        <div class="alert">Le débrief n'est pas encore disponible.</div>
    <?php elseif(!$debrief['exists']): ?>
        <p class="muted-text">Le débrief sera visible ici dès que l'athlète l'aura enregistré.</p>
    <?php endif; ?>
</section>

<?php if(in_array($u['role'], ['coach', 'super_admin'], true)): ?>
<section class="card">
    <h2>Actions coach</h2>

    <div class="grid">
        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="duplicate_session">
            <input type="hidden" name="id" value="<?=$s['id']?>">

            <div class="field">
                <label for="duplicate-date">Dupliquer à la date</label>
                <input id="duplicate-date" type="date" name="new_date" required>
            </div>

            <button class="btn secondary">Dupliquer</button>
        </form>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="move_session">
            <input type="hidden" name="id" value="<?=$s['id']?>">

            <div class="field">
                <label for="move-date">Déplacer à la date</label>
                <input id="move-date" type="date" name="new_date" value="<?=e($s['date'])?>" required>
            </div>

            <button class="btn secondary">Déplacer</button>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <h2>Commentaires</h2>

    <?php foreach($comments as $c): ?>
        <div class="comment">
            <strong><?=e($c['name'])?> a écrit :</strong><br>
            <?=nl2br(e($c['content']))?><br>
            <small><?=e(format_datetime($c['created_at']))?></small>
        </div>
    <?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="session_id" value="<?=$s['id']?>">

        <div class="field">
            <label for="comment-content">Ajouter un commentaire</label>
            <textarea id="comment-content" name="content" required></textarea>
        </div>

        <button class="btn">Envoyer</button>
    </form>
</section>
<?php
    footer_html();
    exit;
}

error_page('Page introuvable', 404);

