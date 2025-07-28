<?php
// dashboard.php — Tableau de bord professionnel avec statistiques, calendrier et actualités
require_once 'config.php';

// Correction pour la notification "session already active"
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$today = date('Y-m-d');
$currentYear = $_SESSION['current_year'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Récupération des données pour le tableau de bord
$stats = [];
$events = [];
$latestComments = [];
$classes = [];

// 1. Statistiques principales (adaptées au rôle)
if ($userRole === 'admin' || $userRole === 'direction') {
    // Vue globale pour la direction et l'admin
    $stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(*) FROM students s JOIN classes c ON s.class_id = c.id WHERE c.year = ?) AS total_students,
            (SELECT COUNT(*) FROM classes WHERE year = ?) AS total_classes,
            (SELECT COUNT(*) FROM events WHERE event_date >= ?) AS upcoming_events,
            (SELECT COUNT(*) FROM comments WHERE DATE(created_at) = ?) AS today_comments,
            (SELECT COUNT(*) FROM preferences p JOIN students s ON p.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE p.validated = 1 AND c.year = ?) AS validated_prefs
    ");
    $stmt->bind_param('sssss', $currentYear, $currentYear, $today, $today, $currentYear);
} else {
    // Vue restreinte pour le professeur (uniquement ses classes)
    $stmt = $conn->prepare("
        SELECT
            (SELECT COUNT(s.id) FROM students s JOIN classes c ON s.class_id = c.id WHERE c.teacher_id = ? AND c.year = ?) AS total_students,
            (SELECT COUNT(*) FROM classes WHERE teacher_id = ? AND year = ?) AS total_classes,
            (SELECT COUNT(*) FROM events WHERE event_date >= ?) AS upcoming_events,
            (SELECT COUNT(cm.id) FROM comments cm JOIN students s ON cm.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE c.teacher_id = ? AND DATE(cm.created_at) = ?) AS today_comments,
            (SELECT COUNT(p.id) FROM preferences p JOIN students s ON p.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE c.teacher_id = ? AND p.validated = 1) AS validated_prefs
    ");
    // CORRECTION APPLIQUÉE ICI : 'isisisi' -> 'isisisii'
    $stmt->bind_param('isisisii', $userId, $currentYear, $userId, $currentYear, $today, $userId, $today, $userId);
}
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();


// 2. Prochains événements (limit 5), avec auteur - Global pour tout le monde
$stmt = $conn->prepare("
    SELECT e.id, e.title, e.event_date, u.name AS author, e.description
    FROM events e
    LEFT JOIN users u ON e.created_by = u.id
    WHERE e.event_date >= ?
    ORDER BY e.event_date
    LIMIT 5
");
$stmt->bind_param('s', $today);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3. Derniers commentaires ajoutés (Déjà correct)
if ($userRole === 'admin' || $userRole === 'direction') {
    $stmt = $conn->prepare("
        SELECT c.id, c.content, c.created_at, s.first_name, s.last_name, u.name AS author
        FROM comments c
        JOIN students s ON c.student_id = s.id
        LEFT JOIN users u ON c.author_id = u.id
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
} else {
    // Pour les profs: uniquement les commentaires des élèves de leurs classes
    $stmt = $conn->prepare("
        SELECT c.id, c.content, c.created_at, s.first_name, s.last_name, u.name AS author
        FROM comments c
        JOIN students s ON c.student_id = s.id
        LEFT JOIN users u ON c.author_id = u.id
        JOIN classes cl ON s.class_id = cl.id
        WHERE cl.teacher_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param('i', $userId);
}
$stmt->execute();
$latestComments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 4. Classes de l'utilisateur (pour les professeurs) (Déjà correct)
if ($userRole === 'prof') {
    $stmt = $conn->prepare("
        SELECT id, name, level
        FROM classes
        WHERE teacher_id = ? AND year = ?
    ");
    $stmt->bind_param('is', $userId, $currentYear);
    $stmt->execute();
    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 5. Jours du mois en cours avec événements - Global pour tout le monde
$month = date('n');
$year = date('Y');
$stmt = $conn->prepare("
    SELECT event_date
    FROM events
    WHERE MONTH(event_date) = ? AND YEAR(event_date) = ?
");
$stmt->bind_param('ii', $month, $year);
$stmt->execute();
$dates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$hasEvent = array_column($dates, 'event_date');

include 'header.php';
?>

<style>
    .stat-card {
        transition: transform 0.3s, box-shadow 0.3s;
        border-radius: 12px;
        overflow: hidden;
        border: none;
    }
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    .stat-icon {
        font-size: 2.5rem;
        opacity: 0.8;
    }
    .calendar {
        border-collapse: separate;
        border-spacing: 3px;
    }
    .calendar th {
        width: 32px;
        height: 32px;
        text-align: center;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .calendar td {
        width: 32px;
        height: 32px;
        text-align: center;
        vertical-align: middle;
        border-radius: 50%;
        cursor: pointer;
        font-size: 0.85rem;
        transition: all 0.2s;
    }
    .calendar td:hover {
        background-color: #e9ecef;
    }
    .calendar td.today {
        background-color: #0d6efd;
        color: white;
        font-weight: bold;
    }
    .calendar td.has-event {
        position: relative;
    }
    .calendar td.has-event::after {
        content: '';
        position: absolute;
        bottom: 2px;
        left: 50%;
        transform: translateX(-50%);
        width: 5px;
        height: 5px;
        background-color: #dc3545;
        border-radius: 50%;
    }
    .event-card {
        border-left: 4px solid #0d6efd;
        transition: all 0.2s;
    }
    .event-card:hover {
        transform: translateX(5px);
        background-color: #f8f9fa;
    }
    .comment-card {
        border-left: 4px solid #20c997;
    }
    .activity-timeline {
        position: relative;
        padding-left: 30px;
    }
    .activity-timeline::before {
        content: '';
        position: absolute;
        left: 10px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: #dee2e6;
    }
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -24px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: #0d6efd;
        border: 2px solid white;
    }
    .badge-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 5px;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-speedometer2 me-2"></i>Tableau de Bord</h1>
            <p class="text-muted mb-0">Bienvenue, <?= htmlspecialchars($_SESSION['name']) ?></p>
        </div>
        <div>
            <span class="badge bg-primary bg-opacity-10 text-primary py-2 px-3">
                <i class="bi bi-calendar me-1"></i> Année scolaire: <?= $currentYear ?>
            </span>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h5 class="text-uppercase text-muted fw-semibold mb-2">Élèves</h5>
                            <h2 class="mb-0"><?= $stats['total_students'] ?></h2>
                        </div>
                        <div class="col-4 text-end">
                            <div class="stat-icon text-primary">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-primary bg-opacity-10 py-2">
                    <a href="list_students.php" class="text-primary text-decoration-none small">
                        Voir tous les élèves <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h5 class="text-uppercase text-muted fw-semibold mb-2">Classes</h5>
                            <h2 class="mb-0"><?= $stats['total_classes'] ?></h2>
                        </div>
                        <div class="col-4 text-end">
                            <div class="stat-icon text-success">
                                <i class="bi bi-building"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-success bg-opacity-10 py-2">
                    <a href="manage_classes.php" class="text-success text-decoration-none small">
                        Gérer les classes <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h5 class="text-uppercase text-muted fw-semibold mb-2">Événements</h5>
                            <h2 class="mb-0"><?= $stats['upcoming_events'] ?></h2>
                            <p class="text-muted mb-0 small">à venir</p>
                        </div>
                        <div class="col-4 text-end">
                            <div class="stat-icon text-warning">
                                <i class="bi bi-calendar-event"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-warning bg-opacity-10 py-2">
                    <a href="manage_events.php" class="text-warning text-decoration-none small">
                        Voir le calendrier <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-8">
                            <h5 class="text-uppercase text-muted fw-semibold mb-2">Commentaires</h5>
                            <h2 class="mb-0"><?= $stats['today_comments'] ?></h2>
                            <p class="text-muted mb-0 small">aujourd'hui</p>
                        </div>
                        <div class="col-4 text-end">
                            <div class="stat-icon text-info">
                                <i class="bi bi-chat-left-text"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-info bg-opacity-10 py-2">
                    <a href="#" class="text-info text-decoration-none small">
                        Voir les commentaires <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="row">
                <div class="col-md-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Prochains Événements</h5>
                            <a href="manage_events.php" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus"></i> Ajouter
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($events)): ?>
                                <div class="text-center p-5">
                                    <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                    <p class="text-muted mt-3">Aucun événement à venir</p>
                                    <a href="manage_events.php" class="btn btn-primary mt-2">
                                        Créer un événement
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($events as $ev): ?>
                                        <a href="manage_events.php?edit=<?= $ev['id'] ?>" class="list-group-item list-group-item-action event-card py-3">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-primary bg-opacity-10 text-primary rounded p-2 me-3">
                                                    <i class="bi bi-calendar-date" style="font-size: 1.5rem;"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between">
                                                        <h6 class="mb-1"><?= htmlspecialchars($ev['title']) ?></h6>
                                                        <span class="text-muted small"><?= date('d/m/Y', strtotime($ev['event_date'])) ?></span>
                                                    </div>
                                                    <p class="mb-0 text-muted small"><?= htmlspecialchars($ev['author'] ?? '—') ?></p>
                                                    <?php if (!empty($ev['description'])): ?>
                                                        <p class="mt-1 mb-0 text-truncate small"><?= htmlspecialchars(substr($ev['description'], 0, 80)) ?>...</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-light py-2">
                            <a href="manage_events.php" class="text-decoration-none small">
                                Voir tous les événements <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>Calendrier</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0 text-uppercase fw-bold"><?= date('F Y', strtotime("$year-$month-01")) ?></h6>
                                <div>
                                    <a href="manage_events.php?month=<?= $month-1 ?>&year=<?= $year ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                    <a href="manage_events.php?month=<?= $month+1 ?>&year=<?= $year ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </div>
                            </div>
                            <?php
                            // Générer le mini-calendrier du mois en cours
                            $firstDay    = strtotime("$year-$month-01");
                            $daysInMonth = date('t', $firstDay);
                            $startDow    = date('N', $firstDay) - 1; // 0 = lun → 6 = dim

                            echo '<table class="calendar mb-0">';
                            echo '<thead><tr>';
                            foreach (['L','M','M','J','V','S','D'] as $d) {
                                echo "<th class='text-muted small'>$d</th>";
                            }
                            echo '</tr></thead><tbody><tr>';
                            // cellules vides avant
                            for ($i = 0; $i < $startDow; $i++) echo '<td></td>';
                            for ($day = 1, $cell = $startDow; $day <= $daysInMonth; $day++, $cell++) {
                                if ($cell % 7 === 0) echo '</tr><tr>';
                                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                $classes_cal = []; // Utiliser un nom de variable différent pour éviter conflit
                                if ($dateStr === $today) $classes_cal[] = 'today';
                                if (in_array($dateStr, $hasEvent)) $classes_cal[] = 'has-event';
                                $classAttr = $classes_cal ? ' class="'.implode(' ',$classes_cal).'"' : '';
                                if (in_array($dateStr, $hasEvent)) {
                                    echo "<td{$classAttr}><a href=\"manage_events.php?day={$dateStr}\" class='text-decoration-none d-block'>$day</a></td>";
                                } else {
                                    echo "<td{$classAttr}>$day</td>";
                                }
                            }
                            // cellules vides après
                            while ((++$cell) % 7 !== 0) echo '<td></td>';
                            echo '</tr></tbody></table>';
                            ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="bi bi-journal me-2"></i>Mes Classes</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($userRole === 'prof' && !empty($classes)): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($classes as $class): ?>
                                        <li class="list-group-item border-0 px-0 py-2">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-info bg-opacity-10 text-info rounded-circle p-2 me-3">
                                                    <i class="bi bi-building"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0"><?= htmlspecialchars($class['name']) ?></h6>
                                                    <p class="mb-0 small text-muted"><?= htmlspecialchars($class['level']) ?></p>
                                                </div>
                                                <div class="ms-auto">
                                                    <a href="class_details.php?id=<?= $class['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        Voir
                                                    </a>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-building text-muted" style="font-size: 3rem;"></i>
                                    <p class="text-muted mt-3">
                                        <?php if ($userRole === 'prof'): ?>
                                            Vous n'avez pas de classes attribuées
                                        <?php else: ?>
                                            Gestion des classes
                                        <?php endif; ?>
                                    </p>
                                    <a href="manage_classes.php" class="btn btn-outline-primary">
                                        Gérer les classes
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>Derniers Commentaires</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($latestComments)): ?>
                        <div class="text-center p-5">
                            <i class="bi bi-chat-left-text text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3">Aucun commentaire récent</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($latestComments as $comment): ?>
                                <div class="list-group-item comment-card py-3">
                                    <div class="d-flex">
                                        <div class="flex-grow-1 ms-3">
                                            <div class="d-flex justify-content-between mb-1">
                                                <h6 class="mb-0">
                                                    <?= htmlspecialchars($comment['first_name']) ?>
                                                    <?= htmlspecialchars($comment['last_name']) ?>
                                                </h6>
                                                <span class="text-muted small">
                                                    <?= date('H:i', strtotime($comment['created_at'])) ?>
                                                </span>
                                            </div>
                                            <p class="mb-1 small"><?= htmlspecialchars(substr($comment['content'], 0, 100)) ?>...</p>
                                            <p class="mb-0 small text-muted">
                                                <i class="bi bi-person me-1"></i>
                                                <?= htmlspecialchars($comment['author'] ?? 'Anonyme') ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light py-2">
                    <a href="#" class="text-decoration-none small">
                        Voir tous les commentaires <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>