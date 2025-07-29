<?php
// list_classes.php — Liste des classes du cycle (Seconde, Première, Terminale) pour l’année en cours,
// avec affectation des professeurs principaux, sans mention d’année

require_once 'config.php';
session_start();

// 1. Accès réservé Admin & Direction
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','direction'])) {
    header('Location: login.php');
    exit;
}

// 2. Traitement du formulaire d’affectation des profs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_teachers'])) {
    foreach ($_POST['teacher'] as $classId => $teacherId) {
        $cid = (int)$classId;
        if ($teacherId === '') {
            $stmt = $conn->prepare("UPDATE classes SET teacher_id = NULL WHERE id = ?");
            $stmt->bind_param('i', $cid);
        } else {
            $tid  = (int)$teacherId;
            $stmt = $conn->prepare("UPDATE classes SET teacher_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $tid, $cid);
        }
        $stmt->execute();
        $stmt->close();
    }
    header('Location: list_classes.php?msg=' . urlencode('Professeurs principaux mis à jour.'));
    exit;
}

// 3. Récupérer la liste des profs pour le dropdown
$teachersList = [];
$resT = $conn->query("SELECT id, name FROM users WHERE role = 'prof' ORDER BY name");
while ($t = $resT->fetch_assoc()) {
    $teachersList[] = $t;
}

// 4. Charger les classes pour l’année en cours (SESSION)
$currentYear = (int)($_SESSION['current_year'] ?? date('Y'));
$res = $conn->prepare("
    SELECT
      c.id,
      c.level,
      c.name   AS letter,
      c.teacher_id,
      u.name   AS teacher_name,
      COUNT(s.id) AS student_count
    FROM classes c
    LEFT JOIN users    u ON c.teacher_id = u.id
    LEFT JOIN students s ON s.class_id   = c.id
   WHERE c.year = ?
    GROUP BY c.id
    ORDER BY FIELD(c.level,'Seconde','Première','Terminale'), c.name
");
$res->bind_param('i', $currentYear);
$res->execute();
$classes = $res->get_result()->fetch_all(MYSQLI_ASSOC);
$res->close();

// 5. Grouper par niveau (sans année)
$levels = ['Seconde','Première','Terminale'];
$classesByLevel = array_fill_keys($levels, []);
foreach ($classes as $c) {
    if (in_array($c['level'], $levels, true)) {
        $classesByLevel[$c['level']][] = $c;
    }
}

$msg = $_GET['msg'] ?? '';

include 'header.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestion des Classes - Lycée Saint Elme</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #0d6efd;
      --secondary-color: #6c757d;
      --success-color: #198754;
      --light-bg: #f8f9fa;
    }
    
    body {
      background-color: #f8f9fa;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .header-card {
      background: linear-gradient(120deg, #0d6efd, #6610f2);
      color: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
      margin-bottom: 1.5rem;
    }
    
    .header-content {
      padding: 1.5rem;
    }
    
    .level-card {
      background: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
      margin-bottom: 1.5rem;
    }
    
    .level-header {
      padding: 0.8rem 1.2rem;
      font-weight: 600;
      color: white;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .level-seconde .level-header { background-color: #0d6efd; }
    .level-premiere .level-header { background-color: #198754; }
    .level-terminale .level-header { background-color: #6f42c1; }
    
    .level-badge {
      background-color: rgba(255, 255, 255, 0.3);
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-weight: 600;
      color: white;
    }
    
    .class-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }
    
    .class-table th {
      background-color: #f8f9fa;
      font-weight: 600;
      color: #495057;
      padding: 0.8rem;
      border-bottom: 2px solid #e9ecef;
      font-size: 0.9rem;
    }
    
    .class-table td {
      padding: 1rem;
      border-bottom: 1px solid #e9ecef;
      vertical-align: middle;
    }
    
    .class-table tr:last-child td {
      border-bottom: none;
    }
    
    .class-table tr:hover td {
      background-color: rgba(13, 110, 253, 0.03);
    }
    
    .class-group {
      font-weight: 600;
      color: #212529;
    }
    
    .student-count-badge {
      background-color: rgba(13, 110, 253, 0.1);
      color: #0d6efd;
      font-weight: 600;
      padding: 0.3rem 0.6rem;
      border-radius: 20px;
      min-width: 60px;
      display: inline-block;
      text-align: center;
      font-size: 0.9rem;
    }
    
    .teacher-select {
      min-width: 180px;
      border-radius: 6px;
      padding: 0.4rem 0.8rem;
      border: 1px solid #dee2e6;
      font-size: 0.9rem;
    }
    
    .teacher-select:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
    }
    
    .btn-view {
      background-color: transparent;
      border: 1px solid #dee2e6;
      color: #495057;
      border-radius: 6px;
      padding: 0.4rem 0.8rem;
      font-size: 0.9rem;
      transition: all 0.2s;
    }
    
    .btn-view:hover {
      background-color: rgba(13, 110, 253, 0.1);
      border-color: #0d6efd;
      color: #0d6efd;
    }
    
    .btn-update {
      background: linear-gradient(120deg, #198754, #0d6efd);
      border: none;
      padding: 0.7rem 1.5rem;
      font-weight: 600;
      border-radius: 8px;
      font-size: 0.95rem;
      box-shadow: 0 3px 8px rgba(25, 135, 84, 0.2);
      position: sticky;
      bottom: 20px;
      z-index: 100;
    }
    
    .btn-update:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 12px rgba(25, 135, 84, 0.3);
    }
    
    .no-classes {
      padding: 1.5rem;
      text-align: center;
      color: #6c757d;
      font-style: italic;
    }
    
    .status-message {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 1050;
      max-width: 350px;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
      border-radius: 10px;
      overflow: hidden;
      animation: slideIn 0.3s ease-out;
    }
    
    @keyframes slideIn {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    
    .action-bar {
      background: white;
      padding: 1rem;
      border-radius: 8px;
      box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
      margin-top: 1.5rem;
      position: sticky;
      bottom: 0;
      z-index: 100;
    }
  </style>
</head>
<body>
  <div class="container py-3">
    <!-- En-tête -->
    <div class="header-card">
      <div class="header-content">
        <h1 class="mb-1"><i class="bi bi-building me-2"></i>Gestion des Classes</h1>
        <p class="mb-0">Affectation des professeurs principaux</p>
      </div>
    </div>

    <!-- Message de statut -->
    <?php if ($msg): ?>
      <div class="status-message">
        <div class="alert alert-success d-flex align-items-center mb-0">
          <i class="bi bi-check-circle-fill me-2"></i>
          <div class="fw-medium"><?= htmlspecialchars($msg) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <form method="post">
      <?php foreach ($levels as $lvl): 
        $levelClass = 'level-' . strtolower(str_replace('è', 'e', $lvl));
      ?>
        <div class="level-card <?= $levelClass ?>">
          <div class="level-header">
            <div class="d-flex align-items-center">
              <i class="bi bi-journal-bookmark me-2"></i>
              <h3 class="mb-0"><?= htmlspecialchars($lvl) ?></h3>
            </div>
            <span class="level-badge">
              <?= count($classesByLevel[$lvl]) ?> classes
            </span>
          </div>

          <?php if (!empty($classesByLevel[$lvl])): ?>
            <div class="p-2">
              <table class="class-table">
                <thead>
                  <tr>
                    <th width="15%">Classe</th>
                    <th width="40%">Professeur principal</th>
                    <th width="15%">Élèves</th>
                    <th width="30%">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($classesByLevel[$lvl] as $c): ?>
                    <tr>
                      <td>
                        <span class="class-group"><?= htmlspecialchars($c['letter']) ?></span>
                      </td>
                      <td>
                        <select name="teacher[<?= $c['id'] ?>]" class="teacher-select form-select form-select-sm">
                          <option value="">-- Non affecté --</option>
                          <?php foreach ($teachersList as $t): ?>
                            <option value="<?= $t['id'] ?>"
                              <?= ($c['teacher_id'] !== null && $c['teacher_id'] == $t['id']) ? 'selected' : '' ?>>
                              <?= htmlspecialchars($t['name']) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <span class="student-count-badge">
                          <i class="bi bi-people me-1"></i>
                          <?= htmlspecialchars($c['student_count']) ?>
                        </span>
                      </td>
                      <td>
                        <a href="class.php?id=<?= $c['id'] ?>" class="btn btn-view btn-sm">
                          <i class="bi bi-eye me-1"></i> Détails
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="no-classes">
              <i class="bi bi-journal-x" style="font-size: 2.5rem; opacity: 0.3;"></i>
              <p class="mt-2 mb-0">Aucune classe pour ce niveau</p>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <!-- Barre d'actions fixe en bas -->
      <div class="action-bar">
        <div class="d-flex justify-content-between align-items-center">
          <a href="add_class.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Nouvelle classe
          </a>
          <button type="submit" name="assign_teachers" class="btn btn-update text-white">
            <i class="bi bi-save me-1"></i> Enregistrer
          </button>
        </div>
      </div>
    </form>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Fermer automatiquement les messages de statut après 5 secondes
      const statusMessage = document.querySelector('.status-message');
      if (statusMessage) {
        setTimeout(() => {
          statusMessage.style.transition = 'transform 0.5s ease, opacity 0.5s ease';
          statusMessage.style.transform = 'translateX(100%)';
          statusMessage.style.opacity = '0';
          
          setTimeout(() => {
            statusMessage.remove();
          }, 500);
        }, 5000);
      }
      
      // Fixer la barre d'actions en bas lors du défilement
      const actionBar = document.querySelector('.action-bar');
      window.addEventListener('scroll', function() {
        if (window.scrollY > 100) {
          actionBar.style.boxShadow = '0 -3px 10px rgba(0, 0, 0, 0.1)';
        } else {
          actionBar.style.boxShadow = '0 3px 10px rgba(0, 0, 0, 0.08)';
        }
      });
    });
  </script>
</body>
</html>

<?php include 'footer.php'; ?>