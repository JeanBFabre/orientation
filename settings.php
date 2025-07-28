<?php
// settings.php — Paramètres & modification de l’année scolaire

require_once 'config.php';
if (!in_array($_SESSION['role'], ['admin','direction'], true)) {
    header('Location: dashboard.php');
    exit;
}

// Traitement du formulaire de mise à jour de l'année scolaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_year'])) {
    $y = intval($_POST['current_year']);
    $stmt = $conn->prepare("
      INSERT INTO `settings` (`name`,`value`)
      VALUES ('current_year', ?)
      ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->bind_param('s', $y);
    $stmt->execute();
    $stmt->close();
    $_SESSION['current_year'] = $y;
    header('Location: settings.php?msg=Année+scolaire+mise+à+jour');
    exit;
}

include 'header.php';
?>

<h2><span class="nav-icon">⚙️</span> Paramètres</h2>

<?php if (!empty($_GET['msg'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
<?php endif; ?>

<form method="post" class="mb-4">
  <div class="mb-3">
    <label for="currentYear" class="form-label">Année scolaire</label>
    <input type="number" name="current_year" id="currentYear"
           class="form-control"
           value="<?= htmlspecialchars($_SESSION['current_year']) ?>">
  </div>
  <button type="submit" name="save_year" class="btn btn-primary">
    💾 Enregistrer l’année
  </button>
</form>

<!-- Vos autres sections de debug / batch / génération aléatoire ici -->

<?php include 'footer.php'; ?>
