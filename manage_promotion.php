<?php
// manage_promotion.php
require_once 'config.php';

// 1. Accès réservé Admin & Direction
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','direction'])) {
    header('Location: login.php');
    exit;
}

// 2. Traitement du reset d'année via GET
if (isset($_GET['reset_year'])) {
    // - Remettre T1
    $conn->query("UPDATE settings SET value='1' WHERE name='current_term'");
    $_SESSION['current_term'] = 1;
    // - Vider toutes les affectations de classes
    $conn->query("UPDATE students SET class_id = NULL");
    // Redirection pour purger le paramètre
    header('Location: manage_promotion.php');
    exit;
}

// 3. Charger préférences T3 (SPE, options, repeated)
$prefs = [];
$res = $conn->query("
    SELECT student_id, grade, specialties, options, repeated
    FROM preferences
    WHERE term = 3
");
while ($r = $res->fetch_assoc()) {
    $prefs[$r['grade']][$r['student_id']] = $r;
}

// 4. Préparer la liste des élèves et leur nouveau grade
$students = [];
$res2 = $conn->query("
    SELECT s.id, s.last_name, s.first_name, c.level, c.year
    FROM students s
    JOIN classes c ON s.class_id = c.id
");
while ($st = $res2->fetch_assoc()) {
    if ($st['level'] === 'Seconde') {
        $newGrade = 'Première';
    } elseif ($st['level'] === 'Première') {
        $newGrade = 'Terminale';
    } else {
        $newGrade = null; // Terminale → fin de cursus
    }
    $students[] = [
        'id'    => $st['id'],
        'last'  => $st['last_name'],
        'first' => $st['first_name'],
        'old'   => $st['level'],
        'grade' => $newGrade,
        'year'  => $st['year'],
    ];
}

// 5. Charger les 4 classes A–D par niveau pour l'année en cours
$year = date('Y');
$classesByGrade = [];
$res3 = $conn->query("
    SELECT id, name, level
    FROM classes
    WHERE year = $year
");
while ($cl = $res3->fetch_assoc()) {
    $classesByGrade[$cl['level']][] = $cl;
}

// 6. Listes SPE & options pour les filtres
$sep = '||';
$specialties = [
    'Histoire-Géographie, géopolitique et sciences politiques',
    'Humanités, littérature et philosophie',
    'Mathématiques',
    'Physique-Chimie',
    'Sciences de la Vie et de la Terre',
    'Sciences économiques et sociales',
    'Langues, littérature et cultures étrangères',
    'Arts Plastiques'
];
$optionsList = [
    'Langues et cultures de l’Antiquité',
    'Maths complémentaires',
    'Maths expertes',
    'DNL',
    'DGEMC'
];

include 'header.php';
?>


<?php if ($_SESSION['current_term'] === 3): ?>
  <div class="text-center my-4">
    <button id="resetBtn" class="btn btn-lg btn-danger">
      🎓 Nouvelle année scolaire <?= $year ?>-<?= $year + 1 ?>
    </button>
  </div>
  <div id="yearOverlay">
    Bienvenue pour cette nouvelle année scolaire <?= $year ?>-<?= $year + 1 ?> !
  </div>
<?php else: ?>
  <div class="alert alert-warning my-4">
    La promotion annuelle n’est disponible qu’à la fin du 3ᵉ trimestre.
  </div>
<?php endif; ?>

<?php if ($_SESSION['current_term'] === 1): ?>
  <h2 class="mb-3">Répartition des élèves</h2>

  <!-- Filtres -->
  <div class="row mb-3">
    <div class="col-md-3">
      <label class="form-label">Niveau</label>
      <select id="filterGrade" class="form-select">
        <option value="">Tous</option>
        <option>Seconde</option>
        <option>Première</option>
        <option>Terminale</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Spécialité (SPE)</label>
      <select id="filterSpe" class="form-select">
        <option value="">Toutes</option>
        <?php foreach ($specialties as $sp): ?>
          <option><?= htmlspecialchars($sp) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label">Options</label>
      <select id="filterOpt" class="form-select">
        <option value="">Toutes</option>
        <?php foreach ($optionsList as $op): ?>
          <option><?= htmlspecialchars($op) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <form method="post" action="manage_promotion.php">
    <table class="table table-bordered" id="distTable">
      <thead class="table-light">
        <tr>
          <th>Élève</th>
          <th>Ancien niveau</th>
          <th>SPE1/2/3</th>
          <th>Options</th>
          <th>Niveau suivant</th>
          <th>Classe <?= $year ?>-<?= $year+1 ?></th>
          <th>Suppr.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $st):
            $id   = $st['id'];
            $next = $st['grade'];
            $spe  = $prefs[$st['old']][$id]['specialties'] ?? '';
            $opt  = $prefs[$st['old']][$id]['options']     ?? '';
        ?>
        <tr data-grade="<?= $st['old'] ?>"
            data-spe="<?= htmlspecialchars($spe) ?>"
            data-opt="<?= htmlspecialchars($opt) ?>">
          <td><?= htmlspecialchars("{$st['last']} {$st['first']}") ?></td>
          <td><?= $st['old'] ?></td>
          <td><?= $spe  ? htmlspecialchars(str_replace($sep, ', ', $spe)) : '—' ?></td>
          <td><?= $opt  ? htmlspecialchars(str_replace($sep, ', ', $opt)) : '—' ?></td>
          <td><?= $next ?: '–' ?></td>
          <td>
            <?php if ($next && isset($classesByGrade[$next])): ?>
              <select name="assign[<?= $id ?>]" class="form-select form-select-sm">
                <option value="">-- Choisir --</option>
                <?php foreach ($classesByGrade[$next] as $cl): ?>
                  <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <span class="text-muted">N/A</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <input type="checkbox" name="delete[<?= $id ?>]">
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="mt-3">
      <button type="submit" class="btn btn-primary">
        Appliquer répartitions & suppressions
      </button>
    </div>
  </form>
<?php endif; ?>

<?php include 'footer.php'; ?>

<script>
<?php if ($_SESSION['current_term'] === 3): ?>
  const overlay = document.getElementById('yearOverlay');
  document.getElementById('resetBtn').addEventListener('click', function(e) {
    e.preventDefault();
    overlay.style.display = 'flex';
    setTimeout(() => overlay.style.opacity = 1, 10);
    setTimeout(() => {
      // redirection GET pour lancer le reset
      window.location.href = 'manage_promotion.php?reset_year=1';
    }, 2500);
  });
<?php endif; ?>

// Filtrage live
['filterGrade','filterSpe','filterOpt'].forEach(id => {
  document.getElementById(id).addEventListener('change', () => {
    const g = document.getElementById('filterGrade').value;
    const s = document.getElementById('filterSpe').value;
    const o = document.getElementById('filterOpt').value;
    document.querySelectorAll('#distTable tbody tr').forEach(tr => {
      const tg = tr.dataset.grade;
      const ts = tr.dataset.spe;
      const to = tr.dataset.opt;
      tr.style.display =
        (!g || tg === g) &&
        (!s || ts.includes(s)) &&
        (!o || to.includes(o))
        ? '' : 'none';
    });
  });
});
</script>
