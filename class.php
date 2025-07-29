<?php
// class.php — Gestion d’une classe avec pagination
require_once 'config.php';

// 1. Vérifier authentification
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 2. Récupérer et valider l’ID de la classe
$classId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$classId) {
    die('Classe non spécifiée.');
}

// Messages
$msg   = '';
$error = '';

// 3. Charger infos de la classe
$stmt = $conn->prepare("
    SELECT name, level, year
      FROM classes
     WHERE id = ?
");
$stmt->bind_param('i', $classId);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$class) {
    die('Classe introuvable.');
}

// 4. Traitement des actions POST/GET

// 4.1 Suppression d’un élève
if (isset($_GET['delete_student'])) {
    $sid = filter_input(INPUT_GET, 'delete_student', FILTER_VALIDATE_INT);
    if ($sid) {
        $del = $conn->prepare("
            DELETE FROM students
             WHERE id = ? AND class_id = ?
        ");
        $del->bind_param('ii', $sid, $classId);
        if ($del->execute()) {
            $msg = "Élève supprimé.";
        } else {
            $error = "Erreur suppression élève.";
        }
        $del->close();
    }
}

// 4.2 Ajout simple d’un élève
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single'])) {
    $last  = isset($_POST['last_name'])  ? strtoupper(trim($_POST['last_name'])) : '';
    $first = isset($_POST['first_name']) ? ucfirst(strtolower(trim($_POST['first_name']))) : '';
    if ($last === '' || $first === '') {
        $error = "Nom et prénom sont requis.";
    } else {
        $ins = $conn->prepare("
            INSERT INTO students (last_name, first_name, class_id)
            VALUES (?, ?, ?)
        ");
        $ins->bind_param('ssi', $last, $first, $classId);
        if ($ins->execute()) {
            $msg = "Élève {$last} {$first} ajouté.";
        } else {
            $error = "Erreur ajout élève.";
        }
        $ins->close();
    }
}

// 4.3 Bulk-add d’élèves via textarea
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_students'])) {
    $lines = preg_split('/\r?\n/', trim($_POST['bulk_students']));
    $ins   = $conn->prepare("
        INSERT INTO students (last_name, first_name, class_id)
        VALUES (?, ?, ?)
    ");
    $count = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Séparer NOM et Prénom
        $parts = preg_split('/\s+/', $line, 2);
        $last  = strtoupper($parts[0]);
        $first = isset($parts[1]) 
                 ? ucfirst(strtolower($parts[1])) 
                 : '';
        if ($last === '' || $first === '') {
            continue;
        }
        $ins->bind_param('ssi', $last, $first, $classId);
        if ($ins->execute()) {
            $count++;
        }
    }
    $ins->close();
    $msg = "{$count} élèves ajoutés en masse.";
}

// 5. Pagination configuration
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($per_page, [10, 20, 50, 100, 1000])) {
    $per_page = 10;
}

$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

// 6. Recharger la liste des élèves avec pagination
$stmt_count = $conn->prepare("
    SELECT COUNT(id) AS total
      FROM students
     WHERE class_id = ?
");
$stmt_count->bind_param('i', $classId);
$stmt_count->execute();
$total_students = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_students / $per_page);
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

$offset = ($current_page - 1) * $per_page;

$stmt = $conn->prepare("
    SELECT id, last_name, first_name
      FROM students
     WHERE class_id = ?
     ORDER BY last_name, first_name
     LIMIT ? OFFSET ?
");
$stmt->bind_param('iii', $classId, $per_page, $offset);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 7. Affichage
include 'header.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestion de Classe - Lycée Saint Elme</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="class-page">
  <div class="container py-4">
    <!-- En-tête de la classe -->
    <div class="class-header">
      <div class="header-content">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="mb-1"><i class="bi bi-people-fill me-2"></i>Classe <?= htmlspecialchars("{$class['level']} {$class['name']}") ?></h1>
            <p class="mb-0">Année scolaire <?= htmlspecialchars($class['year']) ?></p>
          </div>
          <div class="bg-white bg-opacity-20 rounded-circle p-3">
            <i class="bi bi-building" style="font-size: 2rem;"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Messages de statut -->
    <?php if ($msg): ?>
      <div class="status-message">
        <div class="alert alert-success d-flex align-items-center mb-0">
          <i class="bi bi-check-circle-fill me-2"></i>
          <div class="fw-medium"><?= htmlspecialchars($msg) ?></div>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="status-message">
        <div class="alert alert-danger d-flex align-items-center mb-0">
          <i class="bi bi-exclamation-circle-fill me-2"></i>
          <div class="fw-medium"><?= htmlspecialchars($error) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Onglets de navigation -->
    <ul class="nav nav-tabs mb-0" id="classTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab">
          <i class="bi bi-people me-1"></i> Élèves (<?= $total_students ?>)
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="add-single-tab" data-bs-toggle="tab" data-bs-target="#add-single" type="button" role="tab">
          <i class="bi bi-person-plus me-1"></i> Ajouter un élève
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="add-multiple-tab" data-bs-toggle="tab" data-bs-target="#add-multiple" type="button" role="tab">
          <i class="bi bi-people me-1"></i> Ajouter plusieurs élèves
        </button>
      </li>
    </ul>

    <!-- Contenu des onglets -->
    <div class="tab-content" id="classTabsContent">
      <!-- Onglet 1: Liste des élèves -->
      <div class="tab-pane fade show active" id="students" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5>Liste des élèves</h5>
          
          <!-- Sélecteur d'éléments par page -->
          <div class="per-page-selector">
            <span class="text-muted me-2">Élèves par page :</span>
            <select class="form-select form-select-sm" id="per-page-select">
              <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
              <option value="20" <?= $per_page == 20 ? 'selected' : '' ?>>20</option>
              <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
              <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
              <option value="1000" <?= $per_page == 1000 ? 'selected' : '' ?>>Tous</option>
            </select>
          </div>
        </div>
        
        <?php if (empty($students)): ?>
          <div class="empty-state">
            <i class="bi bi-people"></i>
            <h5 class="mb-2">Aucun élève dans cette classe</h5>
            <p class="text-muted mb-0">Commencez par ajouter des élèves en utilisant les onglets ci-dessus</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="student-table">
              <thead>
                <tr>
                  <th width="35%">Nom</th>
                  <th width="35%">Prénom</th>
                  <th width="30%">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                  <td>
                    <span class="student-name"><?= htmlspecialchars($s['last_name']) ?></span>
                  </td>
                  <td>
                    <span class="student-name"><?= htmlspecialchars($s['first_name']) ?></span>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <a href="profile.php?id=<?= $s['id'] ?>" class="btn btn-view action-btn">
                        <i class="bi bi-eye me-1"></i> Profil
                      </a>
                      <a href="class.php?id=<?= $classId ?>&delete_student=<?= $s['id'] ?>"
                         class="btn btn-delete action-btn"
                         onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet élève ?');">
                        <i class="bi bi-trash me-1"></i> Supprimer
                      </a>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          
          <!-- Pagination -->
          <div class="pagination-controls">
            <div class="page-info">
              Affichage de <?= min($offset + 1, $total_students) ?> à <?= min($offset + $per_page, $total_students) ?> 
              sur <?= $total_students ?> élève<?= $total_students > 1 ? 's' : '' ?>
            </div>
            
            <nav>
              <ul class="pagination mb-0">
                <?php if ($current_page > 1): ?>
                  <li class="page-item">
                    <a class="page-link" href="?id=<?= $classId ?>&page=1&per_page=<?= $per_page ?>">
                      <i class="bi bi-chevron-bar-left"></i>
                    </a>
                  </li>
                  <li class="page-item">
                    <a class="page-link" href="?id=<?= $classId ?>&page=<?= $current_page - 1 ?>&per_page=<?= $per_page ?>">
                      <i class="bi bi-chevron-left"></i>
                    </a>
                  </li>
                <?php endif; ?>
                
                <?php
                // Calcul des pages à afficher
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                if ($start_page > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?id='.$classId.'&page=1&per_page='.$per_page.'">1</a></li>';
                    if ($start_page > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                  <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="?id=<?= $classId ?>&page=<?= $i ?>&per_page=<?= $per_page ?>">
                      <?= $i ?>
                    </a>
                  </li>
                <?php endfor; 
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a class="page-link" href="?id='.$classId.'&page='.$total_pages.'&per_page='.$per_page.'">'.$total_pages.'</a></li>';
                }
                ?>
                
                <?php if ($current_page < $total_pages): ?>
                  <li class="page-item">
                    <a class="page-link" href="?id=<?= $classId ?>&page=<?= $current_page + 1 ?>&per_page=<?= $per_page ?>">
                      <i class="bi bi-chevron-right"></i>
                    </a>
                  </li>
                  <li class="page-item">
                    <a class="page-link" href="?id=<?= $classId ?>&page=<?= $total_pages ?>&per_page=<?= $per_page ?>">
                      <i class="bi bi-chevron-bar-right"></i>
                    </a>
                  </li>
                <?php endif; ?>
              </ul>
            </nav>
          </div>
        <?php endif; ?>
      </div>
      
      <!-- Onglet 2: Ajouter un élève -->
      <div class="tab-pane fade" id="add-single" role="tabpanel">
        <h5 class="mb-4">Ajouter un nouvel élève</h5>
        <form method="post" class="row g-3">
          <input type="hidden" name="add_single" value="1">
          <div class="col-md-6">
            <label class="form-label">Nom de famille</label>
            <input type="text" name="last_name" class="form-control" placeholder="DUPONT" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input type="text" name="first_name" class="form-control" placeholder="Jean" required>
          </div>
          <div class="col-12 mt-4">
            <button type="submit" class="btn btn-primary px-4 py-2">
              <i class="bi bi-person-plus me-2"></i> Ajouter l'élève
            </button>
          </div>
        </form>
      </div>
      
      <!-- Onglet 3: Ajouter plusieurs élèves -->
      <div class="tab-pane fade" id="add-multiple" role="tabpanel">
        <h5 class="mb-4">Ajouter plusieurs élèves</h5>
        <form method="post">
          <div class="mb-4">
            <label class="form-label">
              Saisissez les noms des élèves (un par ligne) au format "NOM Prénom"
            </label>
            <textarea name="bulk_students" class="form-control bulk-textarea" 
                      placeholder="DUPONT Alice&#10;MARTIN Jean&#10;DURAND Pierre" required></textarea>
          </div>
          <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Exemple de format :<br>
            <code>DUPONT Alice</code><br>
            <code>MARTIN Jean</code><br>
            <code>DURAND Pierre</code>
          </div>
          <div class="d-flex justify-content-end mt-4">
            <button type="submit" class="btn btn-success px-4 py-2">
              <i class="bi bi-people me-2"></i> Ajouter les élèves
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Fermer automatiquement les messages de statut
      const statusMessages = document.querySelectorAll('.status-message');
      statusMessages.forEach(msg => {
        setTimeout(() => {
          msg.style.transition = 'transform 0.5s ease, opacity 0.5s ease';
          msg.style.transform = 'translateX(100%)';
          msg.style.opacity = '0';
          
          setTimeout(() => {
            msg.remove();
          }, 500);
        }, 5000);
      });
      
      // Conserver l'onglet actif après rechargement
      const classTabs = document.getElementById('classTabs');
      const activeTab = localStorage.getItem('activeClassTab');
      
      if (activeTab) {
        const tabTrigger = new bootstrap.Tab(classTabs.querySelector(`[data-bs-target="${activeTab}"]`));
        tabTrigger.show();
      }
      
      classTabs.addEventListener('click', function(e) {
        const target = e.target.closest('[data-bs-toggle="tab"]');
        if (target) {
          localStorage.setItem('activeClassTab', target.dataset.bsTarget);
        }
      });
      
      // Gestion du changement du nombre d'éléments par page
      const perPageSelect = document.getElementById('per-page-select');
      if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
          const perPage = this.value;
          const url = new URL(window.location.href);
          url.searchParams.set('per_page', perPage);
          url.searchParams.set('page', 1); // Retour à la première page
          window.location.href = url.toString();
        });
      }
    });
  </script>
</body>
</html>

<?php include 'footer.php'; ?>