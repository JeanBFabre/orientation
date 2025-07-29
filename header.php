<?php
// header.php — menu principal professionnel avec adaptation au rôle
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$year = $_SESSION['current_year'];
$username = $_SESSION['name'] ?? 'Utilisateur';
$userRole = $_SESSION['role'] ?? 'prof';
$userId = $_SESSION['user_id'];

// Récupération des classes de l'enseignant si c'est un professeur
$teacherClasses = [];
if ($userRole === 'prof') {
    $stmt = $conn->prepare("
        SELECT id, name, level
        FROM classes
        WHERE teacher_id = ? AND year = ?
    ");
    $stmt->bind_param('is', $userId, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacherClasses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suivi Orientation — <?= htmlspecialchars($year) ?>-<?= htmlspecialchars($year + 1) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
      <img src="https://www.stelme.fr/wp-content/uploads/2020/06/logo.png" alt="Logo Saint Elme" class="me-2">
      <div>
        <span class="fs-5 fw-bold d-block">Lycée Saint Elme</span>
        <span class="d-block small text-muted">Suivi d'orientation</span>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#mainNav" aria-controls="mainNav"
            aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link fw-medium" href="dashboard.php">
            <i class="bi bi-house-door-fill me-1"></i> Accueil
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link fw-medium" href="list_students.php">
            <i class="bi bi-people-fill me-1"></i> Élèves
          </a>
        </li>

        <?php if ($userRole === 'prof' && !empty($teacherClasses)): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle fw-medium" href="#" id="classesDropdown" role="button"
               data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-journal-bookmark-fill me-1"></i> Mes Classes
            </a>
            <ul class="dropdown-menu" aria-labelledby="classesDropdown">
              <?php foreach ($teacherClasses as $class): ?>
                <li>
                  <a class="dropdown-item" href="class.php?id=<?= $class['id'] ?>">
                    <i class="bi bi-building me-2"></i>
                    <?= htmlspecialchars($class['name']) ?> (<?= htmlspecialchars($class['level']) ?>)
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link fw-medium" href="list_classes.php">
              <i class="bi bi-journal-bookmark-fill me-1"></i> Classes
            </a>
          </li>
        <?php endif; ?>

        <?php if ($userRole === 'prof' && !empty($teacherClasses)): ?>
            <li class="nav-item">
                <a class="nav-link fw-medium" href="lancer_conseil.php">
                    <i class="bi bi-display-fill me-1"></i> Conseil de Classe
                </a>
            </li>
        <?php endif; ?>

        <li class="nav-item">
          <a class="nav-link fw-medium" href="manage_events.php">
            <i class="bi bi-calendar-event-fill me-1"></i> Calendrier
          </a>
        </li>

        <?php if ($userRole === 'admin' || $userRole === 'direction'): ?>
          <li class="nav-item">
            <a class="nav-link fw-medium" href="manage_users.php">
              <i class="bi bi-file-person me-1"></i> Utilisateurs
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link fw-medium" href="management_dashboard.php">
              <i class="bi bi-clipboard-data-fill me-1"></i> Tableau de Bord
            </a>
          </li>
        <?php endif; ?>

        <?php if ($userRole === 'admin'): ?>
          <li class="nav-item">
            <a class="nav-link fw-medium" href="admin_panel.php">
              <i class="bi bi-sliders me-1"></i> Administration
            </a>
          </li>
        <?php endif; ?>
      </ul>

      <div class="d-flex align-items-center gap-3">
        <span class="year-badge">
          <i class="bi bi-calendar me-1"></i>
          <?= htmlspecialchars($year) ?>-<?= htmlspecialchars($year+1) ?>
        </span>

        <div class="dropdown">
          <a class="d-flex align-items-center text-decoration-none dropdown-toggle"
             href="#" role="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="user-avatar me-2">
              <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
            <div>
              <div class="fw-medium"><?= htmlspecialchars($username) ?></div>
              <div class="small">
                <?php if ($userRole === 'admin'): ?>
                  <span class="user-role-badge badge-admin">Administrateur</span>
                <?php elseif ($userRole === 'direction'): ?>
                  <span class="user-role-badge badge-direction">Direction</span>
                <?php else: ?>
                  <span class="user-role-badge badge-prof">Professeur</span>
                <?php endif; ?>
              </div>
            </div>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li>
              <a class="dropdown-item" href="profileuser.php">
                <i class="bi bi-person-circle me-2"></i> Mon profil
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="settings.php?tab=account">
                <i class="bi bi-gear me-2"></i> Paramètres du compte
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item text-danger" href="logout.php">
                <i class="bi bi-box-arrow-right me-2"></i> Déconnexion
              </a>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>

<div class="container mt-4">