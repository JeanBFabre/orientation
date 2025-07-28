<?php
// distribute_students.php — Répartition des élèves & changement d’année

require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$currentYear = $_SESSION['current_year'];

// 1. Traitement POST — mise à jour de l’année scolaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_year'])) {
    $newYear = intval($_POST['current_year']);
    if ($newYear >= 2000 && $newYear <= 2100) {
        $stmt = $conn->prepare("
          INSERT INTO `settings` (`name`,`value`)
          VALUES ('current_year', ?)
          ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        $stmt->bind_param('s', $newYear);
        $stmt->execute();
        $stmt->close();
        $_SESSION['current_year'] = $newYear;
        $currentYear = $newYear;
        header('Location: distribute_students.php');
        exit;
    }
}

// 2. Récupérer classes A–D pour l’année courante
$stmt = $conn->prepare("
  SELECT id, level, name
    FROM classes
   WHERE year = ?
   ORDER BY FIELD(level,'Seconde','Première','Terminale'), name
");
$stmt->bind_param('i', $currentYear);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3. Récupérer tous les élèves
$res      = $conn->query("SELECT id, last_name, first_name, class_id FROM students ORDER BY last_name, first_name");
$students = $res->fetch_all(MYSQLI_ASSOC);

// 4. Organiser par niveau & lettre + “Sans classe”
$byLevel = ['Seconde'=>[], 'Première'=>[], 'Terminale'=>[], 'Sans classe'=>[]];
foreach ($classes as $c) {
    $byLevel[$c['level']][$c['name']] = [];
}
foreach ($students as $s) {
    $found = false;
    foreach ($classes as $c) {
        if ($s['class_id'] === $c['id']) {
            $byLevel[$c['level']][$c['name']][] = $s;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $byLevel['Sans classe'][] = $s;
    }
}

include 'header.php';
?>

<h2><span class="nav-icon">⚖️</span> Répartition des élèves — Année <?= htmlspecialchars($currentYear) ?></h2>

<form method="post" class="row gx-2 gy-2 align-items-end mb-4">
  <div class="col-md-3">
    <label class="form-label">Année scolaire</label>
    <input type="number" name="current_year" class="form-control"
           value="<?= htmlspecialchars($currentYear) ?>">
  </div>
  <div class="col-md-2">
    <button type="submit" name="update_year" class="btn btn-primary w-100">
      💾 Mettre à jour
    </button>
  </div>
</form>

<div class="row">
  <?php foreach (['Seconde','Première','Terminale','Sans classe'] as $lvl): ?>
    <div class="col-lg-3 mb-4">
      <h4><?= htmlspecialchars($lvl) ?></h4>
      <?php if ($lvl === 'Sans classe'): ?>
        <ul class="list-group">
          <?php foreach ($byLevel['Sans classe'] as $stu): ?>
            <li class="list-group-item">
              <?= htmlspecialchars("{$stu['last_name']} {$stu['first_name']}") ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <?php foreach ($byLevel[$lvl] as $letter => $list): ?>
          <div class="card mb-3">
            <div class="card-header"><?= htmlspecialchars("{$lvl} {$letter}") ?></div>
            <ul class="list-group list-group-flush">
              <?php foreach ($list as $stu): ?>
                <li class="list-group-item">
                  <?= htmlspecialchars("{$stu['last_name']} {$stu['first_name']}") ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php include 'footer.php'; ?>
