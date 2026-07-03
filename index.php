<?php
$debugEnabled = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($debugEnabled) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            http_response_code(500);
            echo '<pre style="white-space:pre-wrap;background:#111;color:#f7f7f7;padding:16px">';
            echo htmlspecialchars($error['message'] . "\n" . $error['file'] . ':' . $error['line'], ENT_QUOTES, 'UTF-8');
            echo '</pre>';
        }
    });
}

session_start();
require_once __DIR__ . '/functions.php';

$page = $_GET['page'] ?? 'home';
$action = $_POST['action'] ?? null;

if ($page === 'logout') {
    logout_user();
    redirect('index.php?page=login');
}

try {
if ($action) {
    verify_csrf();
    $user = current_user();

    if ($action === 'login') {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([trim($_POST['email'] ?? '')]);
        $u = $stmt->fetch();

        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            $_SESSION['user'] = [
                'id' => (int)$u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'role' => $u['role']
            ];
            redirect('index.php');
        }

        $_SESSION['error'] = 'Identifiants incorrects.';
        redirect('index.php?page=login');
    }

    if ($action === 'logout') {
        logout_user();
        redirect('index.php?page=login');
    }

    require_login();

    if ($action === 'create_athlete') {
        require_role('coach');

        $hash = password_hash($_POST['password'] ?: 'password123', PASSWORD_BCRYPT);
        $name = trim($_POST['first_name'].' '.$_POST['last_name']);

        db()->beginTransaction();

        $stmt = db()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,"athlete")');
        $stmt->execute([$name, trim($_POST['email']), $hash]);

        $userId = (int)db()->lastInsertId();

        db_insert('athletes', [
            'coach_id' => $user['id'],
            'user_id' => $userId,
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'email' => trim($_POST['email']),
            'sport' => trim($_POST['sport'] ?? 'Course'),
            'level' => trim($_POST['level'] ?? 'Intermediaire'),
            'goal' => trim($_POST['goal'] ?? ''),
            'vma' => max(5, min(30, (float)($_POST['vma'] ?? 15))),
            'notes' => trim($_POST['notes'] ?? '')
        ]);

        db()->commit();

        redirect('index.php?page=dashboard');
    }

    if ($action === 'update_athlete') {
        require_role('coach');

        $id = (int)$_POST['id'];

        if (!can_access_athlete($id)) exit('Accès refusé');

        db_update('athletes', [
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'email' => trim($_POST['email']),
            'sport' => trim($_POST['sport'] ?? 'Course'),
            'level' => trim($_POST['level'] ?? 'Intermediaire'),
            'goal' => trim($_POST['goal'] ?? ''),
            'vma' => max(5, min(30, (float)($_POST['vma'] ?? 15))),
            'notes' => trim($_POST['notes'] ?? '')
        ], 'id=? AND coach_id=?', [$id, $user['id']]);

        $stmt = db()->prepare('UPDATE users u JOIN athletes a ON a.user_id=u.id SET u.name=?, u.email=? WHERE a.id=?');
        $stmt->execute([
            trim($_POST['first_name'].' '.$_POST['last_name']),
            trim($_POST['email']),
            $id
        ]);

        redirect('index.php?page=dashboard');
    }

    if ($action === 'delete_athlete') {
        require_role('coach');

        $id = (int)$_POST['id'];

        if (!can_access_athlete($id)) exit('Accès refusé');

        $stmt = db()->prepare('DELETE FROM athletes WHERE id=? AND coach_id=?');
        $stmt->execute([$id, $user['id']]);

        redirect('index.php?page=dashboard');
    }

    if ($action === 'save_session') {
        require_role('coach');

        $athleteId = (int)$_POST['athlete_id'];

        if (!can_access_athlete($athleteId)) exit('Accès refusé');

        $attachment = upload_attachment();

        if (!empty($_POST['id'])) {
            $session = get_session_checked((int)$_POST['id']);
            $attachment = $attachment ?: $session['attachment_url'];

            db_update('sessions', [
                'date' => $_POST['date'],
                'title' => $_POST['title'],
                'type' => $_POST['type'],
                'status' => $_POST['status'] ?? 'planned',
                'intensity' => $_POST['intensity'] ?? 'moderate',
                'duration_min' => nullable_int($_POST['duration_min'] ?? null),
                'vma_percent' => nullable_float($_POST['vma_percent'] ?? null),
                'description' => $_POST['description'],
                'objective' => '',
                'warmup' => $_POST['warmup'],
                'main_workout' => $_POST['main_workout'],
                'cooldown' => '',
                'coach_notes' => $_POST['coach_notes'],
                'actual_duration_min' => nullable_int($_POST['actual_duration_min'] ?? null),
                'feeling' => nullable_int($_POST['feeling'] ?? null),
                'pain' => nullable_int($_POST['pain'] ?? null),
                'athlete_feedback' => $_POST['athlete_feedback'] ?? '',
                'attachment_url' => $attachment,
                'external_link' => $_POST['external_link'] ?: null
            ], 'id=? AND coach_id=?', [(int)$_POST['id'], $user['id']]);
        } else {
            db_insert('sessions', [
                'athlete_id' => $athleteId,
                'coach_id' => $user['id'],
                'date' => $_POST['date'],
                'title' => $_POST['title'],
                'type' => $_POST['type'],
                'status' => $_POST['status'] ?? 'planned',
                'intensity' => $_POST['intensity'] ?? 'moderate',
                'duration_min' => nullable_int($_POST['duration_min'] ?? null),
                'vma_percent' => nullable_float($_POST['vma_percent'] ?? null),
                'description' => $_POST['description'],
                'objective' => '',
                'warmup' => $_POST['warmup'],
                'main_workout' => $_POST['main_workout'],
                'cooldown' => '',
                'coach_notes' => $_POST['coach_notes'],
                'actual_duration_min' => nullable_int($_POST['actual_duration_min'] ?? null),
                'feeling' => nullable_int($_POST['feeling'] ?? null),
                'pain' => nullable_int($_POST['pain'] ?? null),
                'athlete_feedback' => $_POST['athlete_feedback'] ?? '',
                'attachment_url' => $attachment,
                'external_link' => $_POST['external_link'] ?: null
            ]);
        }

        redirect('index.php?page=calendar&athlete_id='.$athleteId.'&month='.substr($_POST['date'], 0, 7));
    }

    if ($action === 'delete_session') {
        require_role('coach');

        $s = get_session_checked((int)$_POST['id']);

        db()->prepare('DELETE FROM sessions WHERE id=? AND coach_id=?')->execute([
            $s['id'],
            $user['id']
        ]);

        redirect('index.php?page=calendar&athlete_id='.$s['athlete_id']);
    }

    if ($action === 'duplicate_session') {
        require_role('coach');

        $s = get_session_checked((int)$_POST['id']);
        $date = $_POST['new_date'];

        $columns = array_values(array_filter([
            'athlete_id',
            'coach_id',
            'date',
            'title',
            'type',
            'status',
            'intensity',
            'duration_min',
            'vma_percent',
            'description',
            'objective',
            'warmup',
            'main_workout',
            'cooldown',
            'coach_notes',
            'actual_duration_min',
            'feeling',
            'pain',
            'athlete_feedback',
            'attachment_url',
            'external_link',
        ], function ($column) { return table_has_column('sessions', $column); }));
        $selects = array_map(function ($column) { return $column === 'date' ? '?' : $column; }, $columns);

        db()->prepare(
            'INSERT INTO sessions (' . implode(',', $columns) . ') SELECT ' .
            implode(',', $selects) . ' FROM sessions WHERE id=?'
        )->execute([$date, $s['id']]);

        redirect('index.php?page=calendar&athlete_id='.$s['athlete_id'].'&month='.substr($date, 0, 7));
    }

    if ($action === 'move_session') {
        require_role('coach');

        $s = get_session_checked((int)$_POST['id']);

        db()->prepare('UPDATE sessions SET date=? WHERE id=? AND coach_id=?')->execute([
            $_POST['new_date'],
            $s['id'],
            $user['id']
        ]);

        redirect('index.php?page=calendar&athlete_id='.$s['athlete_id'].'&month='.substr($_POST['new_date'], 0, 7));
    }

    if ($action === 'add_comment') {
        $s = get_session_checked((int)$_POST['session_id']);

        db()->prepare('INSERT INTO comments (session_id,user_id,content) VALUES (?,?,?)')->execute([
            $s['id'],
            $user['id'],
            trim($_POST['content'])
        ]);

        redirect('index.php?page=session&id='.$s['id']);
    }
}
} catch (Throwable $e) {
    http_response_code(500);
    exit('Erreur : ' . e($e->getMessage()));
}

function header_html(string $title) {
    $u = current_user();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=e($title)?> - <?=APP_NAME?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if($u): ?>
<header class="topbar">
    <div class="brand"><?=APP_NAME?></div>

    <nav class="nav">
        <span class="nav-user"><?=e($u['name'])?> · <?=e($u['role'])?></span>

        <?php if($u['role'] === 'coach'): ?>
            <a href="index.php?page=dashboard">Athlètes</a>
            <a href="index.php?page=coach_calendar">Calendrier général</a>
        <?php else: ?>
            <a href="index.php">Calendrier</a>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn danger small logout-button" type="submit">Déconnexion</button>
        </form>
    </nav>
</header>
<?php endif; ?>

<main class="container">
<?php
}

function footer_html() {
    echo '</main></body></html>';
}

if ($page === 'login') {
    header_html('Connexion');

    $err = $_SESSION['error'] ?? null;
    unset($_SESSION['error']);
?>
<div class="auth-body">
    <form class="auth-card" method="post">
        <h1><?=APP_NAME?></h1>
        <p>Connexion coach ou athlète</p>

        <?php if($err): ?>
            <div class="alert"><?=e($err)?></div>
        <?php endif; ?>

        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="login">

        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>

        <div class="field">
            <label>Mot de passe</label>
            <input type="password" name="password" required>
        </div>

        <button class="btn" type="submit">Se connecter</button>

        <p style="margin-top:18px;font-size:13px">
            Utilise les identifiants crees dans Hostinger.
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
    if ($u['role'] === 'coach') {
        redirect('index.php?page=dashboard');
    }

    $a = athlete_for_user($u['id']);
    if (!$a) exit('Aucune fiche athlete rattachee a ce compte.');
    redirect('index.php?page=calendar&athlete_id='.$a['id']);
}

if ($page === 'dashboard') {
    require_role('coach');

    header_html('Dashboard');

    $q = trim($_GET['q'] ?? '');

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.coach_id=? AND CONCAT(a.first_name," ",a.last_name," ",a.email) LIKE ? ORDER BY a.created_at DESC');
    $stmt->execute([$u['id'], '%'.$q.'%']);
    $athletes = $stmt->fetchAll();

    [$weekStart, $weekEnd] = week_bounds();

    $plannedWhere = table_has_column('sessions', 'status') ? 'status="planned" AND date >= CURDATE()' : 'date >= CURDATE()';
    $doneWhere = table_has_column('sessions', 'status') ? 'status="done"' : 'date < CURDATE()';
    $weekStatusWhere = table_has_column('sessions', 'status') ? ' AND status <> "cancelled"' : '';
    $loadExpr = table_has_column('sessions', 'actual_duration_min') || table_has_column('sessions', 'duration_min')
        ? 'COALESCE(SUM(COALESCE(' .
            (table_has_column('sessions', 'actual_duration_min') ? 'actual_duration_min' : 'NULL') . ', ' .
            (table_has_column('sessions', 'duration_min') ? 'duration_min' : 'NULL') . ', 0)), 0)'
        : '0';

    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE coach_id=? AND ' . $plannedWhere);
    $stmt->execute([$u['id']]);
    $plannedCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE coach_id=? AND ' . $doneWhere);
    $stmt->execute([$u['id']]);
    $doneCount = (int)$stmt->fetchColumn();

    $alertParts = [];
    if (table_has_column('sessions', 'feeling')) $alertParts[] = '(feeling IS NOT NULL AND feeling >= 7)';
    if (table_has_column('sessions', 'pain')) $alertParts[] = '(pain IS NOT NULL AND pain >= 7)';
    if ($alertParts) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE coach_id=? AND (' . implode(' OR ', $alertParts) . ')');
        $stmt->execute([$u['id']]);
        $alertCount = (int)$stmt->fetchColumn();
    } else {
        $alertCount = 0;
    }

    $stmt = db()->prepare('SELECT ' . $loadExpr . ' FROM sessions WHERE coach_id=?' . $weekStatusWhere . ' AND date BETWEEN ? AND ?');
    $stmt->execute([$u['id'], $weekStart, $weekEnd]);
    $weekLoad = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.coach_id=? ORDER BY a.first_name, a.last_name');
    $stmt->execute([$u['id']]);
    $overview = $stmt->fetchAll();

    foreach ($overview as &$row) {
        $stmt = db()->prepare('SELECT ' . session_select_sql('s') . ' FROM sessions s WHERE s.athlete_id=? AND s.coach_id=? AND ' . $plannedWhere . ' ORDER BY s.date LIMIT 1');
        $stmt->execute([$row['id'], $u['id']]);
        $row['next_session'] = $stmt->fetch() ?: null;
        $row['next_date'] = $row['next_session']['date'] ?? null;

        $stmt = db()->prepare('SELECT MAX(date) FROM sessions WHERE athlete_id=? AND coach_id=? AND ' . $doneWhere);
        $stmt->execute([$row['id'], $u['id']]);
        $row['last_done_date'] = $stmt->fetchColumn() ?: null;

        if (table_has_column('sessions', 'feeling')) {
            $stmt = db()->prepare('SELECT AVG(feeling) FROM sessions WHERE athlete_id=? AND coach_id=?');
            $stmt->execute([$row['id'], $u['id']]);
            $row['avg_feeling'] = $stmt->fetchColumn();
        } else {
            $row['avg_feeling'] = null;
        }

        $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE athlete_id=? AND coach_id=? AND ' . (table_has_column('sessions', 'status') ? 'status="planned" AND ' : '') . 'date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)');
        $stmt->execute([$row['id'], $u['id']]);
        $row['planned_next_week'] = (int)$stmt->fetchColumn();

        $stmt = db()->prepare('SELECT MAX(date) FROM sessions WHERE athlete_id=? AND coach_id=?');
        $stmt->execute([$row['id'], $u['id']]);
        $row['last_session_date'] = $stmt->fetchColumn() ?: null;
    }
    unset($row);

    $reminders = array_values(array_filter($overview, function ($row) { return (int)$row['planned_next_week'] === 0; }));
    $attentionRows = array_slice($reminders, 0, 4);
?>
<div class="toolbar">
    <h1>Dashboard coach</h1>

    <div class="actions">
        <a class="btn" href="index.php?page=coach_calendar">Calendrier général</a>
    </div>

    <form>
        <input type="hidden" name="page" value="dashboard">
        <input class="search" name="q" placeholder="Rechercher un athlète" value="<?=e($q)?>">
    </form>
</div>

<div class="stats-grid">
    <section class="stat-card"><strong><?=count($athletes)?></strong><span>Athletes</span></section>
    <section class="stat-card"><strong><?=$plannedCount?></strong><span>Seances a venir</span></section>
    <section class="stat-card"><strong><?=$doneCount?></strong><span>Seances realisees</span></section>
    <section class="stat-card"><strong><?=$weekLoad?> min</strong><span>Charge semaine</span></section>
    <section class="stat-card warning"><strong><?=count($reminders)?></strong><span>Rappels planning</span></section>
    <section class="stat-card danger"><strong><?=$alertCount?></strong><span>Alertes fatigue</span></section>
</div>

<section class="focus-strip">
    <div>
        <span class="eyebrow">Priorite coach</span>
        <strong><?=count($reminders)?> athlete<?=count($reminders) > 1 ? 's' : ''?> sans seance cette semaine</strong>
        <p>Commence par les athletes sans prochaine seance planifiee.</p>
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
                    <span>Prochaine: <?=e($row['next_date'] ? relative_day_label($row['next_date']).' - '.format_short_date($row['next_date']) : 'Aucune')?></span>
                    <span><?=e($row['next_session']['title'] ?? 'Aucune seance programmee')?></span>
                    <span>Ressenti moy.: <?=e($row['avg_feeling'] ? round((float)$row['avg_feeling'], 1).'/10' : '-')?></span>
                </div>
                <div class="row-actions">
                    <a class="btn secondary small" href="index.php?page=calendar&athlete_id=<?=$row['id']?>">Calendrier</a>
                    <a class="btn small" href="index.php?page=edit_session&athlete_id=<?=$row['id']?>&date=<?=date('Y-m-d')?>">Seance</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card" id="rappels">
    <h2>Rappels manque de seance</h2>
    <?php if(!$reminders): ?>
        <p class="muted-text">Aucun rappel pour le moment.</p>
    <?php endif; ?>
    <div class="overview-list">
        <?php foreach($attentionRows as $row): ?>
            <article class="overview-row warning-row">
                <div>
                    <strong><?=e($row['first_name'].' '.$row['last_name'])?></strong>
                    <span>Derniere seance: <?=e($row['last_session_date'] ? relative_day_label($row['last_session_date']).' - '.format_short_date($row['last_session_date']) : 'aucune')?></span>
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
                <label>Prénom</label>
                <input name="first_name" required>
            </div>

            <div class="field">
                <label>Nom</label>
                <input name="last_name" required>
            </div>

            <div class="field">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>

            <div class="field">
                <label>Sport</label>
                <input name="sport" value="Course" required>
            </div>

            <div class="field">
                <label>Niveau</label>
                <input name="level" value="Intermediaire" required>
            </div>

            <div class="field">
                <label>Objectif</label>
                <input name="goal" placeholder="Marathon, reprise, 10 km...">
            </div>

            <div class="field">
                <label>VMA (km/h)</label>
                <input type="number" name="vma" min="5" max="30" step="0.1" value="15" required>
            </div>

            <div class="field">
                <label>Notes</label>
                <textarea name="notes" placeholder="Contraintes, blessures, disponibilites..."></textarea>
            </div>

            <div class="field">
                <label>Mot de passe temporaire</label>
                <input name="password" placeholder="Laisser vide pour password123">
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
                <td class="actions">
                    <a class="btn small" href="index.php?page=calendar&athlete_id=<?=$a['id']?>">Calendrier</a>
                    <a class="btn secondary small" href="index.php?page=edit_athlete&id=<?=$a['id']?>">Modifier</a>

                    <form method="post" onsubmit="return confirm('Supprimer cet athlète ?')">
                        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                        <input type="hidden" name="action" value="delete_athlete">
                        <input type="hidden" name="id" value="<?=$a['id']?>">
                        <button class="btn danger small">Supprimer</button>
                    </form>
                </td>
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

    if (!can_access_athlete($id)) exit('Accès refusé');

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.id=?');
    $stmt->execute([$id]);
    $a = $stmt->fetch();

    header_html('Modifier athlète');
?>
<section class="card">
    <h1>Modifier athlète</h1>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="update_athlete">
        <input type="hidden" name="id" value="<?=$a['id']?>">

        <div class="field">
            <label>Prénom</label>
            <input name="first_name" value="<?=e($a['first_name'])?>" required>
        </div>

        <div class="field">
            <label>Nom</label>
            <input name="last_name" value="<?=e($a['last_name'])?>" required>
        </div>

        <div class="field">
            <label>Email</label>
            <input type="email" name="email" value="<?=e($a['email'])?>" required>
        </div>

        <div class="field">
            <label>Sport</label>
            <input name="sport" value="<?=e($a['sport'])?>" required>
        </div>

        <div class="field">
            <label>Niveau</label>
            <input name="level" value="<?=e($a['level'])?>" required>
        </div>

        <div class="field">
            <label>Objectif</label>
            <input name="goal" value="<?=e($a['goal'])?>">
        </div>

        <div class="field">
            <label>VMA (km/h)</label>
            <input type="number" name="vma" min="5" max="30" step="0.1" value="<?=e($a['vma'])?>" required>
        </div>

        <div class="field">
            <label>Notes</label>
            <textarea name="notes"><?=e($a['notes'])?></textarea>
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

    $stmt = db()->prepare('SELECT ' . athlete_select_sql('a') . ' FROM athletes a WHERE a.coach_id=? ORDER BY a.first_name, a.last_name');
    $stmt->execute([$u['id']]);
    $athletes = $stmt->fetchAll();

    if ($selectedAthleteId > 0 && !can_access_athlete($selectedAthleteId)) {
        exit('Accès refusé');
    }

    if ($selectedAthleteId > 0) {
        $stmt = db()->prepare('SELECT ' . session_select_sql('s') . ', a.first_name, a.last_name FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE s.coach_id=? AND s.athlete_id=? AND s.date BETWEEN ? AND ? ORDER BY s.date, a.first_name, a.last_name');
        $stmt->execute([
            $u['id'],
            $selectedAthleteId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        ]);
    } else {
        $stmt = db()->prepare('SELECT ' . session_select_sql('s') . ', a.first_name, a.last_name FROM sessions s JOIN athletes a ON a.id=s.athlete_id WHERE s.coach_id=? AND s.date BETWEEN ? AND ? ORDER BY s.date, a.first_name, a.last_name');
        $stmt->execute([
            $u['id'],
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        ]);
    }

    $sessions = [];

    foreach ($stmt->fetchAll() as $s) {
        $sessions[$s['date']][] = $s;
    }

    header_html('Calendrier général');

    $prev = (clone $month)->modify('-1 month')->format('Y-m');
    $next = (clone $month)->modify('+1 month')->format('Y-m');

    $filterParam = $selectedAthleteId > 0 ? '&athlete_id=' . $selectedAthleteId : '';
?>
<div class="calendar-head">
    <div>
        <h1>Calendrier général coach</h1>
        <p><?=e($month->format('m/Y'))?></p>
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
            <label>Filtrer par athlète</label>
            <select name="athlete_id" onchange="this.form.submit()">
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
    ?>
        <div class="day <?=$d->format('m') !== $month->format('m') ? 'muted' : ''?>">
            <div class="day-num"><?=$d->format('d/m')?></div>

            <?php foreach($sessions[$date] ?? [] as $s): ?>
                <a class="session-pill <?=type_class($s['type'])?>" href="index.php?page=session&id=<?=$s['id']?>" title="<?=e($s['first_name'].' '.$s['last_name'].' — '.$s['title'])?>">
                    <span class="session-athlete"><?=e($s['first_name'])?></span>
                    <?=e($s['title'])?>
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

    if (!can_access_athlete($athleteId)) exit('Accès refusé');

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
    $lastSession = null;
    $weekSessionCount = 0;
    $displayLoad = 0;
    [$currentWeekStart, $currentWeekEnd] = week_bounds();

    foreach ($stmt->fetchAll() as $s) {
        $sessions[$s['date']][] = $s;
        $monthSessions[] = $s;

        if ($s['date'] >= date('Y-m-d') && (!$nextSession || $s['date'] < $nextSession['date'])) {
            $nextSession = $s;
        }

        if ($s['date'] < date('Y-m-d') && (!$lastSession || $s['date'] > $lastSession['date'])) {
            $lastSession = $s;
        }

        if ($s['date'] >= $currentWeekStart && $s['date'] <= $currentWeekEnd) {
            $weekSessionCount++;
        }

        $displayLoad += (int)($s['actual_duration_min'] ?: ($s['duration_min'] ?: 0));
    }

    header_html('Calendrier');

    $prev = (clone $month)->modify('-1 month')->format('Y-m');
    $next = (clone $month)->modify('+1 month')->format('Y-m');
?>
<div class="calendar-head">
    <div>
        <h1>Calendrier — <?=e($a['first_name'].' '.$a['last_name'])?></h1>
        <p><?=e($month->format('m/Y'))?></p>
    </div>

    <div class="actions">
        <a class="btn secondary" href="index.php?page=calendar&athlete_id=<?=$athleteId?>&month=<?=$prev?>">Mois précédent</a>
        <a class="btn secondary" href="index.php?page=calendar&athlete_id=<?=$athleteId?>&month=<?=$next?>">Mois suivant</a>

        <?php if($u['role'] === 'coach'): ?>
            <a class="btn" href="index.php?page=edit_session&athlete_id=<?=$athleteId?>&date=<?=date('Y-m-d')?>">Ajouter une séance</a>
        <?php endif; ?>
    </div>
</div>

<section class="athlete-next">
    <div class="next-session-panel">
        <span class="eyebrow">Prochaine seance</span>
        <?php if($nextSession): ?>
            <strong><?=e($nextSession['title'])?></strong>
            <p><?=e(relative_day_label($nextSession['date']))?> - <?=e(format_full_date($nextSession['date']))?> - <?=e($nextSession['type'])?></p>
            <a class="btn secondary small" href="index.php?page=session&id=<?=$nextSession['id']?>">Voir le detail</a>
        <?php else: ?>
            <strong>Aucune seance programmee</strong>
            <p>Le planning est vide pour les prochains jours affiches.</p>
        <?php endif; ?>
    </div>
    <div class="mini-metrics">
        <div><strong><?=$weekSessionCount?></strong><span>Cette semaine</span></div>
        <div><strong><?=$displayLoad?> min</strong><span>Charge affichee</span></div>
        <div><strong><?=e($lastSession ? format_short_date($lastSession['date']) : '-')?></strong><span>Derniere</span></div>
    </div>
</section>

<section class="card athlete-summary">
    <h2>Profil athlete</h2>
    <div class="metrics-row">
        <div class="metric-box"><strong><?=e($a['sport'])?></strong><span>Sport</span></div>
        <div class="metric-box"><strong><?=e($a['level'])?></strong><span>Niveau</span></div>
        <div class="metric-box"><strong><?=e($a['vma'])?> km/h</strong><span>VMA</span></div>
        <div class="metric-box"><strong><?=e($a['goal'] ?: '-')?></strong><span>Objectif</span></div>
    </div>
    <?php if($a['notes']): ?>
        <p><?=nl2br(e($a['notes']))?></p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Allures VMA</h2>
    <div class="pace-grid">
        <?php foreach([60,70,80,90,100,105] as $percent):
            $pace = pace_from_vma((float)$a['vma'], $percent);
        ?>
            <div class="pace-cell">
                <strong><?=$percent?>%</strong>
                <span><?=e(format_pace($pace))?></span>
                <small>400m <?=e(format_split($pace, 0.4))?> · 1000m <?=e(format_split($pace, 1))?></small>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="calendar iphone-calendar">
    <?php foreach(['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?>
        <div class="day-name"><?=$d?></div>
    <?php endforeach; ?>

    <?php
    $d = clone $start;

    while ($d <= $end):
        $date = $d->format('Y-m-d');
    ?>
        <div class="day <?=$d->format('m') !== $month->format('m') ? 'muted' : ''?>">
            <div class="day-num"><?=$d->format('d/m')?></div>

            <?php if($u['role'] === 'coach'): ?>
                <a class="btn secondary small" href="index.php?page=edit_session&athlete_id=<?=$athleteId?>&date=<?=$date?>">+</a>
            <?php endif; ?>

            <?php foreach($sessions[$date] ?? [] as $s): ?>
                <a class="session-pill <?=type_class($s['type'])?>" href="index.php?page=session&id=<?=$s['id']?>">
                    <?=e($s['title'])?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php
        $d->modify('+1 day');
    endwhile;
    ?>
</div>
<section class="card month-agenda">
    <h2>Seances de la periode</h2>
    <?php if(!$monthSessions): ?>
        <p class="muted-text">Aucune seance sur cette periode.</p>
    <?php endif; ?>
    <div class="agenda-list">
        <?php foreach($monthSessions as $s): ?>
            <a class="agenda-row" href="index.php?page=session&id=<?=$s['id']?>">
                <div>
                    <strong><?=e($s['title'])?></strong>
                    <span><?=e(format_full_date($s['date']))?> - <?=e($s['type'])?></span>
                </div>
                <span class="status-badge status-<?=e($s['status'])?>"><?=e(status_label($s['status']))?></span>
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

    $session = null;
    $athleteId = (int)($_GET['athlete_id'] ?? 0);

    if (!empty($_GET['id'])) {
        $session = get_session_checked((int)$_GET['id']);
        $athleteId = (int)$session['athlete_id'];
    }

    if (!can_access_athlete($athleteId)) exit('Accès refusé');

    header_html($session ? 'Modifier séance' : 'Créer séance');
?>
<section class="card">
    <h1><?=$session ? 'Modifier' : 'Créer'?> une séance</h1>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="save_session">
        <input type="hidden" name="athlete_id" value="<?=$athleteId?>">

        <?php if($session): ?>
            <input type="hidden" name="id" value="<?=$session['id']?>">
        <?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label>Date</label>
                <input type="date" name="date" value="<?=e($session['date'] ?? ($_GET['date'] ?? date('Y-m-d')))?>" required>
            </div>

            <div class="field">
                <label>Titre court</label>
                <input name="title" value="<?=e($session['title'] ?? '')?>" required>
            </div>

            <div class="field">
                <label>Type</label>
                <select name="type">
                    <?php foreach(session_types() as $t): ?>
                        <option <?=$t === ($session['type'] ?? 'footing') ? 'selected' : ''?>>
                            <?=e($t)?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Statut</label>
                <select name="status">
                    <?php foreach(session_statuses() as $value => $label): ?>
                        <option value="<?=$value?>" <?=$value === ($session['status'] ?? 'planned') ? 'selected' : ''?>>
                            <?=e($label)?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Intensite</label>
                <select name="intensity">
                    <?php foreach(intensities() as $value => $label): ?>
                        <option value="<?=$value?>" <?=$value === ($session['intensity'] ?? 'moderate') ? 'selected' : ''?>>
                            <?=e($label)?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Duree prevue (min)</label>
                <input type="number" name="duration_min" min="1" step="5" value="<?=e($session['duration_min'] ?? '')?>">
            </div>

            <div class="field">
                <label>% VMA cible</label>
                <input type="number" name="vma_percent" min="40" max="130" step="1" value="<?=e($session['vma_percent'] ?? '')?>">
            </div>

            <div class="field">
                <label>Lien optionnel</label>
                <input name="external_link" value="<?=e($session['external_link'] ?? '')?>">
            </div>

            <div class="field full">
                <label>Description</label>
                <textarea name="description"><?=e($session['description'] ?? '')?></textarea>
            </div>

            <div class="field">
                <label>Échauffement</label>
                <textarea name="warmup"><?=e($session['warmup'] ?? '')?></textarea>
            </div>

            <div class="field">
                <label>Corps de séance</label>
                <textarea name="main_workout"><?=e($session['main_workout'] ?? '')?></textarea>
            </div>

            <div class="field full">
                <label>Conseils du coach</label>
                <textarea name="coach_notes"><?=e($session['coach_notes'] ?? '')?></textarea>
            </div>

            <div class="field">
                <label>Duree reelle (min)</label>
                <input type="number" name="actual_duration_min" min="1" step="5" value="<?=e($session['actual_duration_min'] ?? '')?>">
            </div>

            <div class="field">
                <label>Ressenti athlete / 10</label>
                <input type="number" name="feeling" min="1" max="10" step="1" value="<?=e($session['feeling'] ?? '')?>">
            </div>

            <div class="field">
                <label>Douleur / fatigue / 10</label>
                <input type="number" name="pain" min="0" max="10" step="1" value="<?=e($session['pain'] ?? '')?>">
            </div>

            <div class="field full">
                <label>Retour athlete</label>
                <textarea name="athlete_feedback"><?=e($session['athlete_feedback'] ?? '')?></textarea>
            </div>

            <div class="field full">
                <label>Pièce jointe</label>
                <input type="file" name="attachment">
            </div>
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
    $pace = !empty($s['vma_percent']) ? pace_from_vma((float)$s['vma'], (float)$s['vma_percent']) : null;

    $stmt = db()->prepare('SELECT c.*, u.name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.session_id=? ORDER BY c.created_at');
    $stmt->execute([$s['id']]);
    $comments = $stmt->fetchAll();

    header_html('Détail séance');
?>
<section class="card">
    <div class="toolbar">
        <div>
            <h1><?=e($s['title'])?></h1>
            <p>
                <?=e($s['date'])?> ·
                <span class="session-pill <?=type_class($s['type'])?>" style="display:inline-block">
                    <?=e($s['type'])?>
                </span>
                <span class="status-badge status-<?=e($s['status'])?>"><?=e(status_label($s['status']))?></span>
            </p>
        </div>

        <?php if($u['role'] === 'coach'): ?>
            <div class="actions">
                <a class="btn" href="index.php?page=edit_session&id=<?=$s['id']?>">Modifier</a>

                <form method="post">
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
            <div class="metric-box"><strong><?=e(intensity_label($s['intensity']))?></strong><span>Intensite</span></div>
            <div class="metric-box"><strong><?=e($s['duration_min'] ?: '-')?> min</strong><span>Prevu</span></div>
            <div class="metric-box"><strong><?=e($s['actual_duration_min'] ?: '-')?> min</strong><span>Reel</span></div>
            <div class="metric-box"><strong><?=e($s['vma_percent'] ?: '-')?>%</strong><span>VMA</span></div>
            <div class="metric-box"><strong><?=e(format_pace($pace))?></strong><span>Allure</span></div>
            <div class="metric-box"><strong><?=e($s['feeling'] ?: '-')?>/10</strong><span>Ressenti</span></div>
            <div class="metric-box"><strong><?=e($s['pain'] ?? '-')?>/10</strong><span>Douleur</span></div>
        </div>
        <?php foreach([
            'description' => 'Description',
            'warmup' => 'Échauffement',
            'main_workout' => 'Corps de séance',
            'coach_notes' => 'Conseils du coach',
            'athlete_feedback' => 'Retour athlete'
        ] as $k => $label): ?>
            <div class="detail-item">
                <strong><?=$label?></strong><br>
                <?=nl2br(e($s[$k]))?>
            </div>
        <?php endforeach; ?>

        <?php if($s['external_link']): ?>
            <a class="btn secondary" href="<?=e($s['external_link'])?>" target="_blank">Lien externe</a>
        <?php endif; ?>

        <?php if($s['attachment_url']): ?>
            <a class="btn secondary" href="<?=e($s['attachment_url'])?>" target="_blank">Pièce jointe</a>
        <?php endif; ?>
    </div>
</section>

<?php if($u['role'] === 'coach'): ?>
<section class="card">
    <h2>Actions coach</h2>

    <div class="grid">
        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="duplicate_session">
            <input type="hidden" name="id" value="<?=$s['id']?>">

            <div class="field">
                <label>Dupliquer à la date</label>
                <input type="date" name="new_date" required>
            </div>

            <button class="btn secondary">Dupliquer</button>
        </form>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="action" value="move_session">
            <input type="hidden" name="id" value="<?=$s['id']?>">

            <div class="field">
                <label>Déplacer à la date</label>
                <input type="date" name="new_date" required>
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
            <small><?=e($c['created_at'])?></small>
        </div>
    <?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="add_comment">
        <input type="hidden" name="session_id" value="<?=$s['id']?>">

        <div class="field">
            <label>Ajouter un commentaire</label>
            <textarea name="content" required></textarea>
        </div>

        <button class="btn">Envoyer</button>
    </form>
</section>
<?php
    footer_html();
    exit;
}

http_response_code(404);
echo 'Page introuvable';
