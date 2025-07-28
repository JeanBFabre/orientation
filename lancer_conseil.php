<?php
/**
 * lancer_conseil.php
 * Portail pour démarrer une session de "Conseil de Classe".
 */
require_once 'config.php';

// Sécurité : L'utilisateur doit être connecté et avoir un rôle (prof ou plus)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Récupérer les classes où l'utilisateur est professeur principal
$stmt = $conn->prepare("SELECT id, name, level, year FROM classes WHERE teacher_id = ? ORDER BY year DESC, name ASC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include 'header.php'; // Votre en-tête de page standard
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-body p-5 text-center">
            <i class="bi bi-display-fill text-primary" style="font-size: 4rem;"></i>
            <h1 class="h2 mt-3">Mode Conseil de Classe</h1>
            <p class="text-muted">Lancez une session immersive pour le conseil de votre classe.</p>

            <?php if (count($classes) > 0): ?>
            <form action="mode_conseil.php" method="POST" class="mt-4">
                <div class="mb-3">
                    <label for="class_id" class="form-label fw-bold">Choisissez votre classe :</label>
                    <select name="class_id" id="class_id" class="form-select form-select-lg" required>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['id'] ?>">
                                <?= htmlspecialchars($class['level'] . ' ' . $class['name'] . ' (' . $class['year'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="term" class="form-label fw-bold">Choisissez le trimestre :</label>
                    <select name="term" id="term" class="form-select form-select-lg" required>
                        <option value="1">Trimestre 1</option>
                        <option value="2">Trimestre 2</option>
                        <option value="3">Trimestre 3</option>
                    </select>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-play-circle-fill me-2"></i>Lancer la session
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="alert alert-warning mt-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Vous n'êtes assigné comme professeur principal à aucune classe pour le moment.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; // Votre pied de page standard ?>