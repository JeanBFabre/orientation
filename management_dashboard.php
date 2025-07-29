<?php
/**
 * management_dashboard.php
 * Tableau de bord de gestion pour la direction et les administrateurs.
 * Affiche les statistiques clés, les graphiques et fournit des outils d'export.
 *
 * @version 2.0 (Refactorisé et corrigé)
 */

require_once 'config.php';
session_start();

// --- Sécurité et Initialisation ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'direction'])) {
    header('Location: login.php');
    exit;
}

$currentYear = $_SESSION['current_year'];

// --- Fonctions de Récupération de Données ---

/**
 * Récupère les statistiques globales pour les cartes d'information.
 * @param mysqli $conn La connexion à la base de données.
 * @param string $currentYear L'année scolaire en cours.
 * @return array Les statistiques.
 */
function getDashboardStats(mysqli $conn, string $currentYear): array {
    $stats = [];

    // Nombre total d'élèves inscrits dans une classe de l'année en cours
    $stmt = $conn->prepare("SELECT COUNT(s.id) AS total FROM students s JOIN classes c ON s.class_id = c.id WHERE c.year = ?");
    $stmt->bind_param('s', $currentYear);
    $stmt->execute();
    $stats['students'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Nombre de classes pour l'année en cours
    $stmt = $conn->prepare("SELECT COUNT(id) AS total FROM classes WHERE year = ?");
    $stmt->bind_param('s', $currentYear);
    $stmt->execute();
    $stats['classes'] = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    // Nombre de spécialités depuis la table dédiée (performant)
    $stats['specialties'] = $conn->query("SELECT COUNT(*) as total FROM specialties")->fetch_assoc()['total'] ?? 0;

    // Pour les options, le parsing reste nécessaire
    $optionsResult = $conn->query("SELECT DISTINCT options FROM preferences WHERE options IS NOT NULL AND options != ''");
    $distinctOptions = [];
    while ($row = $optionsResult->fetch_assoc()) {
        $optionsList = explode('||', $row['options']);
        foreach ($optionsList as $option) {
            $distinctOptions[trim($option)] = true;
        }
    }
    $stats['options'] = count($distinctOptions);

    return $stats;
}

/**
 * Récupère les données nécessaires pour les graphiques.
 * @param mysqli $conn La connexion à la base de données.
 * @param string $currentYear L'année scolaire en cours.
 * @return array Les données pour les graphiques.
 */
function getChartData(mysqli $conn, string $currentYear): array {
    $chartData = [
        'levelDistribution' => ['Seconde' => 0, 'Première' => 0, 'Terminale' => 0],
        'classDistribution' => [],
        'specialtyDistribution' => []
    ];

    // Répartition des élèves par niveau et par classe (une seule requête)
    $stmt = $conn->prepare("
        SELECT c.level, c.name, COUNT(s.id) AS student_count
        FROM classes c
        LEFT JOIN students s ON s.class_id = c.id
        WHERE c.year = ?
        GROUP BY c.id, c.level, c.name
        ORDER BY FIELD(c.level, 'Seconde', 'Première', 'Terminale'), c.name
    ");
    $stmt->bind_param('s', $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $chartData['levelDistribution'][$row['level']] += $row['student_count'];
        $chartData['classDistribution'][] = [
            'label' => $row['level'] . ' ' . $row['name'],
            'count' => (int)$row['student_count']
        ];
    }
    $stmt->close();

    // Distribution des spécialités. NOTE: Une structure normalisée serait plus performante.
    $stmt = $conn->prepare("
        SELECT
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(p.specialties, '||', numbers.n), '||', -1)) AS specialty,
            COUNT(*) AS count
        FROM preferences p
        CROSS JOIN (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) AS numbers
            ON CHAR_LENGTH(p.specialties) - CHAR_LENGTH(REPLACE(p.specialties, '||', '')) >= numbers.n - 1
        JOIN students s ON p.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE c.year = ? AND p.specialties IS NOT NULL AND p.specialties != ''
        GROUP BY specialty ORDER BY count DESC
    ");
    $stmt->bind_param('s', $currentYear);
    $stmt->execute();
    $chartData['specialtyDistribution'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $chartData;
}

/**
 * Récupère les listes pour les formulaires d'export.
 * @param mysqli $conn La connexion à la base de données.
 * @param string $currentYear L'année scolaire en cours.
 * @return array Les listes pour les formulaires.
 */
function getFormLists(mysqli $conn, string $currentYear): array {
    $lists = ['classes' => [], 'specialties' => [], 'options' => []];

    $stmt = $conn->prepare("SELECT id, name, level FROM classes WHERE year = ? ORDER BY FIELD(level, 'Seconde', 'Première', 'Terminale'), name");
    $stmt->bind_param('s', $currentYear);
    $stmt->execute();
    $lists['classes'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $result = $conn->query("SELECT name FROM specialties ORDER BY name");
    while($row = $result->fetch_assoc()){
        $lists['specialties'][] = $row['name'];
    }

    $optionsResult = $conn->query("SELECT DISTINCT options FROM preferences WHERE options IS NOT NULL AND options != ''");
    $distinctOptions = [];
    while ($row = $optionsResult->fetch_assoc()) {
        foreach (explode('||', $row['options']) as $option) {
            $distinctOptions[trim($option)] = true;
        }
    }
    $lists['options'] = array_keys($distinctOptions);
    sort($lists['options']);

    return $lists;
}

// --- Exécution et Préparation des Données ---
$stats = getDashboardStats($conn, $currentYear);
$chartData = getChartData($conn, $currentYear);
$formLists = getFormLists($conn, $currentYear);

$totalStudentsForPercentage = $stats['students'] > 0 ? $stats['students'] : 1;
$specialtyLabels = array_column($chartData['specialtyDistribution'], 'specialty');
$specialtyCounts = array_column($chartData['specialtyDistribution'], 'count');
$classLabels = array_column($chartData['classDistribution'], 'label');
$classCounts = array_column($chartData['classDistribution'], 'count');

include 'header.php';
?>


<div class="container-fluid py-4">
    <header class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-clipboard-data me-2"></i>Tableau de Bord de Gestion</h1>
            <p class="text-muted mb-0">Statistiques et outils pour l'année <?= htmlspecialchars($currentYear) ?></p>
        </div>
        <span class="badge bg-primary bg-opacity-10 text-primary fs-6 py-2 px-3">
            <i class="bi bi-calendar-check me-1"></i> Année : <?= htmlspecialchars($currentYear) ?>
        </span>
    </header>

    <section class="row mb-4">
        <?php
        $statCards = [
            ['label' => 'Élèves', 'value' => $stats['students'], 'icon' => 'bi-people-fill', 'color' => 'primary'],
            ['label' => 'Classes', 'value' => $stats['classes'], 'icon' => 'bi-building', 'color' => 'success'],
            ['label' => 'Spécialités', 'value' => $stats['specialties'], 'icon' => 'bi-journal-bookmark', 'color' => 'warning'],
            ['label' => 'Options', 'value' => $stats['options'], 'icon' => 'bi-bookmark-plus', 'color' => 'info']
        ];
        ?>
        <?php foreach ($statCards as $card): ?>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card stat-card">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-<?= $card['color'] ?> bg-opacity-10 text-<?= $card['color'] ?> rounded p-3 me-3">
                        <i class="bi <?= $card['icon'] ?>" style="font-size: 2rem;"></i>
                    </div>
                    <div>
                        <h5 class="text-muted mb-1"><?= $card['label'] ?></h5>
                        <h2 class="mb-0 fw-bold"><?= $card['value'] ?></h2>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <section class="card mb-4">
        <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Statistiques d'Orientation</h5></div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-lg-4 col-md-6 mb-4 mb-lg-0">
                    <h6 class="mb-3 text-center">Répartition par spécialité</h6>
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="specialtyChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-8 col-md-6 mb-4 mb-lg-0">
                    <h6 class="mb-3 text-center">Répartition par niveau</h6>
                    <?php foreach ($chartData['levelDistribution'] as $level => $count):
                        $percentage = ($count / $totalStudentsForPercentage) * 100;
                        $color = ['Seconde' => 'primary', 'Première' => 'success', 'Terminale' => 'warning'][$level] ?? 'secondary';
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span><?= htmlspecialchars($level) ?></span>
                            <span class="fw-medium"><?= $count ?> élèves</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-<?= $color ?>" role="progressbar" style="width: <?= $percentage ?>%" aria-valuenow="<?= $percentage ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="col-12 mt-4">
                     <h6 class="mb-3 text-center">Nombre d'élèves par classe</h6>
                     <div class="chart-container">
                        <canvas id="classChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="row">
        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-file-earmark-arrow-down me-2"></i>Exporter des Listes</h5></div>
                <div class="card-body">
                    <form action="generate_report.php" method="post" target="_blank">
                        <div class="mb-3">
                            <label class="form-label fw-medium">Type d'export</label>
                            <select class="form-select" name="export_type" required>
                                <option value="" selected disabled>Sélectionner un type...</option>
                                <option value="class">Liste par classe</option>
                                <option value="specialty">Liste par spécialité</option>
                                <option value="option">Liste par option</option>
                                <option value="level">Liste par niveau</option>
                                <option value="all">Tous les élèves</option>
                            </select>
                        </div>

                        <div class="mb-3 export-field" style="display:none;" id="classExportField">
                            <label class="form-label fw-medium">Classe</label>
                            <select class="form-select" name="export_class">
                                <?php foreach ($formLists['classes'] as $class): ?>
                                <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['level'] . ' ' . $class['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3 export-field" style="display:none;" id="specialtyExportField">
                            <label class="form-label fw-medium">Spécialité</label>
                            <select class="form-select" name="export_specialty">
                                <?php foreach ($formLists['specialties'] as $spec): ?>
                                <option value="<?= htmlspecialchars($spec) ?>"><?= htmlspecialchars($spec) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3 export-field" style="display:none;" id="optionExportField">
                            <label class="form-label fw-medium">Option</label>
                            <select class="form-select" name="export_option">
                                <?php foreach ($formLists['options'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3 export-field" style="display:none;" id="levelExportField">
                            <label class="form-label fw-medium">Niveau</label>
                            <select class="form-select" name="export_level">
                                <option value="Seconde">Seconde</option>
                                <option value="Première">Première</option>
                                <option value="Terminale">Terminale</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-medium">Format</label>
                            <div class="d-flex gap-3">
                                <div class="form-check flex-grow-1"><input class="form-check-input" type="radio" name="export_format" id="pdfFormat" value="pdf" checked><label class="form-check-label d-flex align-items-center" for="pdfFormat"><i class="bi bi-file-earmark-pdf me-2 text-danger fs-3"></i><div><span class="fw-medium">PDF</span> <small class="d-block text-muted">Imprimable</small></div></label></div>
                                <div class="form-check flex-grow-1"><input class="form-check-input" type="radio" name="export_format" id="excelFormat" value="excel"><label class="form-check-label d-flex align-items-center" for="excelFormat"><i class="bi bi-file-earmark-spreadsheet me-2 text-success fs-3"></i><div><span class="fw-medium">Excel</span> <small class="d-block text-muted">Tableur</small></div></label></div>
                            </div>
                        </div>

                        <div class="d-grid"><button type="submit" class="btn btn-success btn-lg py-2 fw-bold"><i class="bi bi-download me-2"></i>Générer l'export</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Actions Rapides</h5></div>
                <div class="card-body d-flex flex-column"><div class="d-grid gap-3 my-auto">
                    <?php
                    $quickActions = [
                        ['label' => 'Gérer les classes', 'desc' => 'Créer, modifier, supprimer', 'link' => 'manage_classes.php', 'icon' => 'bi-building'],
                        ['label' => 'Gérer les utilisateurs', 'desc' => 'Ajouter ou modifier des comptes', 'link' => 'manage_users.php', 'icon' => 'bi-people'],
                        ['label' => 'Gérer le calendrier', 'desc' => 'Planifier des événements', 'link' => 'manage_events.php', 'icon' => 'bi-calendar-event'],
                        ['label' => 'Paramètres du système', 'desc' => 'Configurer l\'application', 'link' => 'settings.php', 'icon' => 'bi-gear']
                    ];
                    foreach ($quickActions as $action): ?>
                    <a href="<?= $action['link'] ?>" class="btn btn-outline-secondary text-start p-3 d-flex align-items-center"><i class="bi <?= $action['icon'] ?> me-3 fs-2"></i><div><h6 class="mb-0"><?= $action['label'] ?></h6><small class="text-muted"><?= $action['desc'] ?></small></div></a>
                    <?php endforeach; ?>
                </div></div>
            </div>
        </div>
    </section>
</div>

<?php include 'footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Logique du formulaire d'export ---
    const exportTypeSelect = document.querySelector('select[name="export_type"]');
    const exportFields = document.querySelectorAll('.export-field');
    exportTypeSelect.addEventListener('change', function() {
        const selectedType = this.value;
        exportFields.forEach(field => field.style.display = 'none');
        const fieldToShow = document.getElementById(selectedType + 'ExportField');
        if (fieldToShow) fieldToShow.style.display = 'block';
    });

    // --- Configuration des graphiques ---
    const chartColors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796', '#5a5c69'];

    // Graphique des spécialités (Doughnut)
    new Chart(document.getElementById('specialtyChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($specialtyLabels) ?>,
            datasets: [{ data: <?= json_encode($specialtyCounts) ?>, backgroundColor: chartColors, borderColor: '#fff', borderWidth: 2 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });

    // Graphique des élèves par classe (Barres horizontales)
    new Chart(document.getElementById('classChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($classLabels) ?>,
            datasets: [{
                label: "Nombre d'élèves",
                data: <?= json_encode($classCounts) ?>,
                backgroundColor: 'rgba(54, 185, 204, 0.7)',
                borderColor: 'rgba(54, 185, 204, 1)',
                borderWidth: 1, borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y', // Axe horizontal
            responsive: true,
            maintainAspectRatio: false, // Crucial pour que le graphique remplisse le conteneur
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { legend: { display: false } }
        }
    });
});
</script>