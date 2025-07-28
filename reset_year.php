<?php
// reset_year.php — Préparation de l’année suivante : création des classes A–D et réinitialisation des validations

require_once 'config.php';

// 1. Droits & contexte : seul admin/direction, idéalement en fin de T3
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','direction'])) {
    header('Location: settings.php');
    exit;
}

// Charger l’année en cours et calculer la suivante
$currentYear = (int)$_SESSION['current_year'];
$nextYear    = $currentYear + 1;
$levels      = ['Seconde','Première','Terminale'];
$letters     = ['A','B','C','D'];

// 2. Si on a cliqué sur “Créer classes”
if (isset($_GET['create_classes'])) {
    // Désactiver FKs le temps de créer les classes manquantes
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($levels as $lvl) {
        // Récupérer celles déjà présentes
        $stmt = $conn->prepare("
            SELECT name
              FROM classes
             WHERE level = ? AND year = ?
        ");
        $stmt->bind_param('si', $lvl, $nextYear);
        $stmt->execute();
        $existing = array_column(
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
            'name'
        );
        $stmt->close();

        // Insérer les lettres manquantes
        $ins = $conn->prepare("
            INSERT INTO classes (name, level, year, teacher_id)
            VALUES (?, ?, ?, NULL)
        ");
        foreach ($letters as $ltr) {
            if (!in_array($ltr, $existing, true)) {
                $ins->bind_param('ssi', $ltr, $lvl, $nextYear);
                $ins->execute();
            }
        }
        $ins->close();
    }
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    // Réinitialiser les validations de term=3 pour repartir à zéro
    $conn->query("
        UPDATE preferences
           SET next_class_id = NULL,
               repeated      = 0,
               validated     = 0
         WHERE term = 3
    ");

    // Passer au T1
    $conn->query("
        UPDATE settings
           SET value = '1'
         WHERE name = 'current_term'
    ");
    $_SESSION['current_term'] = 1;

    header('Location: distribute_students.php');
    exit;
}

include 'header.php';
?>
<div class="container mt-5 text-center">
  <h2>Préparation de l’année <?= $nextYear ?></h2>
  <p>Créez les classes A–D pour Seconde, Première et Terminale <?= $nextYear ?>-<?= $nextYear+1 ?>.</p>
  <a href="reset_year.php?create_classes=1" class="btn btn-lg btn-primary">
    Créer les classes & Réinitialiser validations
  </a>
</div>
<?php include 'footer.php'; ?>
