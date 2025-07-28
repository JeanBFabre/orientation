<?php
// manage_classes.php — Création et suppression des groupes A–D
require_once 'config.php';

// 1. Accès réservé Admin & Direction
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','direction'])) {
    header('Location: login.php');
    exit;
}

$msg = '';

// 2. Suppression d’un groupe via del_group=Seconde-2025
if (isset($_GET['del_group'])) {
    // on explode sur '-', plus fiable qu'| en URL
    list($level, $year) = explode('-', $_GET['del_group']);
    $level = $conn->real_escape_string($level);
    $year  = (int)$year;

    $stmt = $conn->prepare("DELETE FROM classes WHERE level = ? AND year = ?");
    $stmt->bind_param('si', $level, $year);
    $stmt->execute();
    $stmt->close();

    $msg = "Toutes les classes <strong>$level $year</strong> ont été supprimées.";
    header('Location: manage_classes.php?msg=' . urlencode($msg));
    exit;
}

// 3. Création d’un nouveau groupe A–D
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $level    = $_POST['level'] ?? '';
    $year     = intval($_POST['year'] ?? 0);
    $teachers = $_POST['teacher'] ?? [];

    // Validation rapide
    if (!in_array($level, ['Seconde','Première','Terminale']) || $year < 2000) {
        $msg = 'Niveau ou année invalide.';
    } else {
        // Vérifier si le groupe existe déjà
        $chk = $conn->prepare("SELECT COUNT(*) AS nb FROM classes WHERE level = ? AND year = ?");
        $chk->bind_param('si', $level, $year);
        $chk->execute();
        $nb = $chk->get_result()->fetch_assoc()['nb'];
        $chk->close();

        if ($nb > 0) {
            $msg = "Le groupe <strong>$level $year</strong> existe déjà.";
        } else {
            // Création en transaction
            try {
                $conn->begin_transaction();
                $labels = ['A','B','C','D'];
                $ins = $conn->prepare("
                  INSERT INTO classes (name, level, year, teacher_id)
                  VALUES (?, ?, ?, ?)
                ");
                foreach ($labels as $i => $letter) {
                    $tid = isset($teachers[$i]) && intval($teachers[$i])>0
                          ? intval($teachers[$i])
                          : null;
                    $ins->bind_param('ssii', $letter, $level, $year, $tid);
                    $ins->execute();
                }
                $ins->close();
                $conn->commit();
                $msg = "Groupe <strong>$level $year</strong> créé (classes A–D).";
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($e->getCode() === 1062) {
                    $msg = "Erreur : le groupe <strong>$level $year</strong> existe déjà.";
                } else {
                    $msg = "Erreur BDD : " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }

    header('Location: manage_classes.php?msg=' . urlencode($msg));
    exit;
}

// 4. Message éventuel
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}

// 5. Récupérer les groupes (distinct level+year)
$groups = [];
$res = $conn->query("
  SELECT level, year, COUNT(*) AS count
    FROM classes
   GROUP BY level, year
   ORDER BY year DESC, FIELD(level,'Seconde','Première','Terminale')
");
while ($g = $res->fetch_assoc()) {
    $groups[] = $g;
}

// 6. Liste des profs pour affectation
$teachersList = [];
$res2 = $conn->query("SELECT id, name FROM users WHERE role = 'prof' ORDER BY name");
while ($t = $res2->fetch_assoc()) {
    $teachersList[] = $t;
}

include 'header.php';
?>

<h2>Gestion des groupes de classes (A–D)</h2>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= $msg ?></div>
<?php endif; ?>

<h4>Groupes existants</h4>
<?php if ($groups): ?>
  <table class="table table-striped mb-4">
    <thead>
      <tr>
        <th>Année</th>
        <th>Niveau</th>
        <th>Nb classes</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($groups as $g): ?>
        <tr>
          <td><?= htmlspecialchars($g['year']) ?></td>
          <td><?= htmlspecialchars($g['level']) ?></td>
          <td><?= htmlspecialchars($g['count']) ?></td>
          <td>
            <a href="manage_classes.php?del_group=<?= urlencode($g['level'] . '-' . $g['year']) ?>"
               class="btn btn-sm btn-danger"
               onclick="return confirm('Vraiment supprimer le groupe <?= addslashes($g['level'].' '.$g['year']) ?> ?');">
              Supprimer groupe
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php else: ?>
  <p><em>Aucun groupe créé pour le moment.</em></p>
<?php endif; ?>

<h4>Créer un nouveau groupe (A–D)</h4>
<form method="post" class="row g-3 mb-5">
  <div class="col-md-3">
    <label class="form-label">Niveau</label>
    <select name="level" class="form-select" required>
      <option value="">-- Choisir --</option>
      <option value="Seconde">Seconde</option>
      <option value="Première">Première</option>
      <option value="Terminale">Terminale</option>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Année</label>
    <input type="number" name="year" class="form-control" value="<?= date('Y') ?>" required>
  </div>

  <?php foreach (['A','B','C','D'] as $i => $letter): ?>
    <div class="col-md-2">
      <label class="form-label">Prof <?= $letter ?></label>
      <select name="teacher[<?= $i ?>]" class="form-select">
        <option value="">-- Aucun --</option>
        <?php foreach ($teachersList as $t): ?>
          <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endforeach; ?>

  <div class="col-md-1 d-flex align-items-end">
    <button type="submit" name="create_group" class="btn btn-primary w-100">
      Créer
    </button>
  </div>
</form>

<?php include 'footer.php'; ?>
