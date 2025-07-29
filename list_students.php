<?php
// list_students.php — Version finale avec toutes les corrections

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Europe/Paris');

require_once 'config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$msg = '';
$checkedStudentIds = [];

// Gestion des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $studentIds = $_POST['students'] ?? [];
    $studentIds = array_map('intval', $studentIds);
    
    // Sauvegarder les IDs cochés pour la persistance
    $_SESSION['checked_students'] = $studentIds;
    $checkedStudentIds = $studentIds;

    if (empty($studentIds)) {
        $msg = 'Aucun élève sélectionné.';
    } else {
        switch ($action) {
            case 'assign':
                $classId = intval($_POST['class_id'] ?? 0);
                if ($classId > 0) {
                    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                    $sql = "UPDATE students SET class_id = ? WHERE id IN ($placeholders)";
                    $stmt = $conn->prepare($sql);
                    $types = str_repeat('i', count($studentIds) + 1);
                    $stmt->bind_param($types, $classId, ...$studentIds);
                    $stmt->execute();
                    $stmt->close();
                    $msg = 'Affectation de classe réalisée.';
                } else {
                    $msg = 'Veuillez choisir une classe.';
                }
                break;

            case 'mass_spe':
                $spe = $_POST['spe'] ?? '';
                $allSpe = [
                    'Histoire-Géographie, géopolitique et sciences politiques',
                    'Humanités, littérature et philosophie',
                    'Mathématiques', 'Physique-Chimie',
                    'Sciences de la Vie et de la Terre',
                    'Sciences économiques et sociales',
                    'Langues, littérature et cultures étrangères',
                    'Arts Plastiques'
                ];
                
                if (!in_array($spe, $allSpe, true)) {
                    $msg = 'Spécialité invalide.';
                } else {
                    $updated = 0;
                    
                    // Préparer les requêtes
                    $getPrefStmt = $conn->prepare("
                        SELECT p.id, p.specialties
                        FROM preferences p
                        JOIN students s ON p.student_id = s.id
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.id = ? AND p.term = 1 AND p.grade = c.level
                    ");
                    
                    $insertPrefStmt = $conn->prepare("
                        INSERT INTO preferences (student_id, term, grade, specialties, options)
                        SELECT s.id, 1, c.level, '', ''
                        FROM students s
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.id = ?
                    ");
                    
                    $updatePrefStmt = $conn->prepare("
                        UPDATE preferences 
                        SET specialties = ?, 
                            drop_specialty = NULL, 
                            abandoned_specialty = NULL 
                        WHERE id = ?
                    ");
                    
                    foreach ($studentIds as $sid) {
                        // Récupérer ou créer la préférence
                        $getPrefStmt->bind_param('i', $sid);
                        $getPrefStmt->execute();
                        $result = $getPrefStmt->get_result();
                        $pref = $result->fetch_assoc();
                        
                        $prefId = null;
                        $currentSpe = [];
                        
                        if ($pref) {
                            $prefId = $pref['id'];
                            $currentSpe = $pref['specialties'] ? explode('||', $pref['specialties']) : [];
                        } else {
                            // Créer une nouvelle préférence
                            $insertPrefStmt->bind_param('i', $sid);
                            $insertPrefStmt->execute();
                            $prefId = $insertPrefStmt->insert_id;
                        }
                        
                        // Vérifier si on peut ajouter (max 3 SPE)
                        if (count($currentSpe) < 3 && !in_array($spe, $currentSpe, true)) {
                            $currentSpe[] = $spe;
                            $newSpe = implode('||', $currentSpe);
                            $updatePrefStmt->bind_param('si', $newSpe, $prefId);
                            $updatePrefStmt->execute();
                            $updated++;
                        }
                    }
                    
                    $getPrefStmt->close();
                    $insertPrefStmt->close();
                    $updatePrefStmt->close();
                    
                    $msg = "SPE attribuée en masse. $updated élèves mis à jour.";
                }
                break;

            case 'mass_opt':
                $opt = $_POST['opt'] ?? '';
                $allOpts = ['Latin', 'Maths complémentaires', 'Maths expertes', 'DNL', 'DGEMC'];
                
                if (!in_array($opt, $allOpts, true)) {
                    $msg = 'Option invalide.';
                } else {
                    $updated = 0;
                    
                    // Préparer les requêtes
                    $getPrefStmt = $conn->prepare("
                        SELECT p.id, p.options
                        FROM preferences p
                        JOIN students s ON p.student_id = s.id
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.id = ? AND p.term = 1 AND p.grade = c.level
                    ");
                    
                    $insertPrefStmt = $conn->prepare("
                        INSERT INTO preferences (student_id, term, grade, specialties, options)
                        SELECT s.id, 1, c.level, '', ''
                        FROM students s
                        JOIN classes c ON s.class_id = c.id
                        WHERE s.id = ?
                    ");
                    
                    $updatePrefStmt = $conn->prepare("
                        UPDATE preferences 
                        SET options = ? 
                        WHERE id = ?
                    ");
                    
                    foreach ($studentIds as $sid) {
                        // Récupérer ou créer la préférence
                        $getPrefStmt->bind_param('i', $sid);
                        $getPrefStmt->execute();
                        $result = $getPrefStmt->get_result();
                        $pref = $result->fetch_assoc();
                        
                        $prefId = null;
                        $currentOpt = [];
                        
                        if ($pref) {
                            $prefId = $pref['id'];
                            $currentOpt = $pref['options'] ? explode('||', $pref['options']) : [];
                        } else {
                            // Créer une nouvelle préférence
                            $insertPrefStmt->bind_param('i', $sid);
                            $insertPrefStmt->execute();
                            $prefId = $insertPrefStmt->insert_id;
                        }
                        
                        // Ajouter l'option si pas déjà présente
                        if (!in_array($opt, $currentOpt, true)) {
                            $currentOpt[] = $opt;
                            $newOpt = implode('||', $currentOpt);
                            $updatePrefStmt->bind_param('si', $newOpt, $prefId);
                            $updatePrefStmt->execute();
                            $updated++;
                        }
                    }
                    
                    $getPrefStmt->close();
                    $insertPrefStmt->close();
                    $updatePrefStmt->close();
                    
                    $msg = "Option attribuée en masse. $updated élèves mis à jour.";
                }
                break;

            case 'mass_delete':
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $sql = "DELETE FROM students WHERE id IN ($placeholders)";
                $stmt = $conn->prepare($sql);
                $types = str_repeat('i', count($studentIds));
                $stmt->bind_param($types, ...$studentIds);
                $stmt->execute();
                $stmt->close();
                $msg = count($studentIds) . ' élève(s) supprimé(s).';
                break;

            case 'edit':
                $rawLast  = trim($_POST['last_name']  ?? '');
                $rawFirst = trim($_POST['first_name'] ?? '');
                $newLast  = mb_strtoupper($rawLast, 'UTF-8');
                $newFirst = mb_convert_case(
                    mb_strtolower($rawFirst, 'UTF-8'),
                    MB_CASE_TITLE, 'UTF-8'
                );
                
                if ($newLast === '' || $newFirst === '') {
                    $msg = 'Nom et prénom ne peuvent pas être vides.';
                } else {
                    $stmt = $conn->prepare("UPDATE students SET last_name = ?, first_name = ? WHERE id = ?");
                    foreach ($studentIds as $sid) {
                        $stmt->bind_param('ssi', $newLast, $newFirst, $sid);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $msg = 'Élève modifié.';
                }
                break;

            default:
                $msg = 'Action inconnue.';
        }
    }
} elseif (isset($_SESSION['checked_students'])) {
    $checkedStudentIds = $_SESSION['checked_students'];
}

// Chargement affichage
$currentYear = intval($_SESSION['current_year'] ?? date('Y'));
$stmt = $conn->prepare("
    SELECT id, level, name 
    FROM classes 
    WHERE year = ? 
    ORDER BY FIELD(level, 'Seconde', 'Première', 'Terminale'), name
");
$stmt->bind_param('i', $currentYear);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$abbr = [
    'Histoire-Géographie, géopolitique et sciences politiques' => 'HG',
    'Humanités, littérature et philosophie' => 'HLP',
    'Mathématiques' => 'Math',
    'Physique-Chimie' => 'PC',
    'Sciences de la Vie et de la Terre' => 'SVT',
    'Sciences économiques et sociales' => 'SES',
    'Langues, littérature et cultures étrangères' => 'LLCE',
    'Arts Plastiques' => 'Arts',
    'Latin' => 'Lat',
    'Maths complémentaires' => 'MC',
    'Maths expertes' => 'ME',
    'DNL' => 'DNL',
    'DGEMC' => 'DGEMC'
];

$stmt = $conn->prepare("
    SELECT
        s.id AS student_id,
        s.class_id,
        s.last_name, s.first_name,
        currc.level AS curr_level, currc.name AS curr_class,
        p.id AS pref_id, p.specialties, p.options, p.drop_specialty,
        oldc.level AS old_level, oldc.name AS old_class
    FROM students s
    LEFT JOIN classes currc ON s.class_id = currc.id AND currc.year = ?
    LEFT JOIN preferences p
        ON p.student_id = s.id AND p.term = 1 AND p.grade = currc.level
    LEFT JOIN preferences po
        ON po.student_id = s.id AND po.term = 1
        AND po.grade = CASE
            WHEN currc.level = 'Première' THEN 'Seconde'
            WHEN currc.level = 'Terminale' THEN 'Première'
            ELSE NULL END
    LEFT JOIN classes oldc ON oldc.id = po.class_id
    ORDER BY s.last_name, s.first_name
");
$stmt->bind_param('i', $currentYear);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Préparation filtres
$speSet = []; $optSet = []; $oldSet = [];
foreach ($students as $st) {
    if ($st['specialties']) {
        foreach (explode('||', $st['specialties']) as $sp) {
            $speSet[$abbr[$sp] ?? $sp] = true;
        }
    }
    if ($st['options']) {
        foreach (explode('||', $st['options']) as $op) {
            $optSet[$abbr[$op] ?? $op] = true;
        }
    }
    $old = $st['old_class'] ? "{$st['old_level']} {$st['old_class']}" : '—';
    $oldSet[$old] = true;
}
$speList = array_keys($speSet); sort($speList);
$optList = array_keys($optSet); sort($optList);
$oldList = array_keys($oldSet); sort($oldList);

include 'header.php';
?>

<div class="container my-4">
    <?php if ($msg) : ?>
        <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">
            <i class="bi bi-people-fill me-1"></i><strong>Tous les élèves</strong> — Année <?= $currentYear ?>
        </h2>
        <div>
            <input type="text" id="searchInput" class="form-control" placeholder="Recherche…" style="width: 250px;">
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center">
            <span id="selectedCount" class="badge bg-primary me-2">0 sélectionné(s)</span>
            <button id="deselectAllBtn" class="btn btn-sm btn-outline-secondary me-2">
                <i class="bi bi-x-circle"></i> Tout décocher
            </button>
            
            <select id="selectClassToCheck" class="form-select form-select-sm w-auto me-2">
                <option value="">Cocher une classe...</option>
                <?php foreach ($classes as $c) : ?>
                    <option value="<?= $c['id'] ?>"><?= $c['level'] ?> <?= $c['name'] ?></option>
                <?php endforeach; ?>
            </select>
            <button id="checkClassBtn" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-check2-square"></i> Cocher
            </button>
        </div>
        
        <div class="d-flex align-items-center">
            <span class="text-muted me-2" id="filteredCount">
                <?= count($students) ?> élève(s)
            </span>
            <select id="massAction" class="form-select w-auto d-inline-block me-2">
                <option value="">Actions de masse</option>
                <option value="assign">Affecter</option>
                <option value="mass_spe">Attribuer SPE</option>
                <option value="mass_opt">Attribuer Options</option>
                <option value="mass_delete">Supprimer</option>
            </select>
            <button type="button" id="massActionBtn" class="btn btn-primary">
                <i class="bi bi-check2-circle"></i> Appliquer
            </button>
        </div>
    </div>

    <div class="row mb-3 gx-2">
        <div class="col-md-4">
            <label class="form-label"><em>SPE</em></label>
            <select id="filterSpe" multiple class="form-select form-select-sm">
                <?php foreach ($speList as $sp) : ?>
                    <option><?= htmlspecialchars($sp) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label"><em>Options</em></label>
            <select id="filterOpt" multiple class="form-select form-select-sm">
                <?php foreach ($optList as $op) : ?>
                    <option><?= htmlspecialchars($op) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label"><em>Ancienne classe</em></label>
            <select id="filterOld" class="form-select form-select-sm">
                <option value="">— Tous —</option>
                <?php foreach ($oldList as $oc) : ?>
                    <option><?= htmlspecialchars($oc) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <form id="massForm" method="post">
        <input type="hidden" name="action" id="massActionInput">
        <input type="hidden" name="class_id" id="massClassIdInput">
        <input type="hidden" name="spe" id="massSpeInput">
        <input type="hidden" name="opt" id="massOptInput">
        <input type="hidden" name="last_name" id="massLastNameInput">
        <input type="hidden" name="first_name" id="massFirstNameInput">

        <div class="table-responsive">
            <table id="studentsTable" class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th data-sort="last_name" class="fw-semibold">Nom</th>
                        <th data-sort="first_name" class="fw-semibold">Prénom</th>
                        <th data-sort="old_class" class="fw-semibold">Ancienne</th>
                        <th data-sort="curr_class" class="fw-semibold">Actuelle</th>
                        <th class="fw-semibold">SPE</th>
                        <th class="fw-semibold">Options</th>
                        <th class="fw-semibold">Supprimée</th>
                        <th class="text-end fw-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $st) :
                        $speArr = $st['specialties'] ? explode('||', $st['specialties']) : [];
                        $optArr = $st['options'] ? explode('||', $st['options']) : [];
                        $drop   = $st['drop_specialty'];
                        $old    = $st['old_class'] ? "{$st['old_level']} {$st['old_class']}" : '—';
                        $curr   = $st['curr_class'] ? "{$st['curr_level']} {$st['curr_class']}" : 'Sans classe';
                        $isChecked = in_array($st['student_id'], $checkedStudentIds);
                    ?>
                        <tr data-class-id="<?= $st['class_id'] ?? 0 ?>"
                            data-spe='<?= json_encode(array_map(fn($v)=> $abbr[$v] ?? $v, $speArr)) ?>'
                            data-opt='<?= json_encode(array_map(fn($v)=> $abbr[$v] ?? $v, $optArr)) ?>'
                            data-old-class="<?= $old ?>"
                            data-curr-class="<?= $curr ?>">
                            <td>
                                <input type="checkbox" class="row-checkbox form-check-input" name="students[]"
                                       value="<?= $st['student_id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
                            </td>
                            <td class="col-name">
                                <a href="profile.php?id=<?= $st['student_id'] ?>" class="link-primary fw-bold">
                                    <?= $st['last_name'] ?>
                                </a>
                            </td>
                            <td class="col-first"><?= $st['first_name'] ?></td>
                            <td class="col-old"><em><?= $old ?></em></td>
                            <td class="col-curr"><em><?= $curr ?></em></td>
                            <td>
                                <?php if ($st['curr_level'] !== 'Seconde'): ?>
                                    <?php if ($speArr): foreach ($speArr as $sp):
                                        $a = $abbr[$sp] ?? $sp;
                                    ?>
                                        <?php if ($sp === $drop): ?>
                                            <span class="badge bg-danger text-decoration-line-through fst-italic"><?= $a ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark"><?= $a ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; else: ?>
                                        <span class="text-muted fst-italic">—</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted fst-italic">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($optArr): foreach ($optArr as $op):
                                    $a = $abbr[$op] ?? $op;
                                ?>
                                    <span class="badge bg-secondary text-white"><?= $a ?></span>
                                <?php endforeach; else: ?>
                                    <span class="text-muted fst-italic">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($drop): ?>
                                    <span class="text-danger fst-italic"><?= $abbr[$drop] ?? $drop ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary assign-btn" data-id="<?= $st['student_id'] ?>">
                                    <i class="bi bi-building"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-btn" data-id="<?= $st['student_id'] ?>" data-last_name="<?= $st['last_name'] ?>" data-first_name="<?= $st['first_name'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger delete-btn" data-id="<?= $st['student_id'] ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<!-- Modals -->
<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Affecter à une classe</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select id="assignClassSelect" class="form-select">
                    <option value="">— Choisir —</option>
                    <?php foreach ($classes as $c) : ?>
                        <option value="<?= $c['id'] ?>"><?= $c['level'] ?> <?= $c['name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="assignConfirmBtn" class="btn btn-primary">Affecter</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Modifier l'élève</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Nom</label>
                    <input type="text" id="editLastNameInput" class="form-control">
                </div>
                <div>
                    <label class="form-label">Prénom</label>
                    <input type="text" id="editFirstNameInput" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="editConfirmBtn" class="btn btn-primary">Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<!-- SPE Modal -->
<div class="modal fade" id="speModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Attribuer SPE</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select id="speSelect" class="form-select">
                    <option value="">— Choisir SPE —</option>
                    <?php foreach ([
                        'Histoire-Géographie, géopolitique et sciences politiques',
                        'Humanités, littérature et philosophie',
                        'Mathématiques', 'Physique-Chimie',
                        'Sciences de la Vie et de la Terre',
                        'Sciences économiques et sociales',
                        'Langues, littérature et cultures étrangères',
                        'Arts Plastiques'
                    ] as $sp) : ?>
                        <option><?= htmlspecialchars($sp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="speConfirmBtn" class="btn btn-primary">Attribuer</button>
            </div>
        </div>
    </div>
</div>

<!-- Option Modal -->
<div class="modal fade" id="optModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Attribuer Option</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select id="optSelect" class="form-select">
                    <option value="">— Choisir Option —</option>
                    <?php foreach (['Latin', 'Maths complémentaires', 'Maths expertes', 'DNL', 'DGEMC'] as $op) : ?>
                        <option><?= htmlspecialchars($op) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="optConfirmBtn" class="btn btn-primary">Attribuer</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Supprimer les élèves sélectionnés&nbsp;?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="deleteConfirmBtn" class="btn btn-danger">Supprimer</button>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('massForm'),
        actionInput = document.getElementById('massActionInput'),
        classInput = document.getElementById('massClassIdInput'),
        speInput = document.getElementById('massSpeInput'),
        optInput = document.getElementById('massOptInput'),
        lastNameInput = document.getElementById('massLastNameInput'),
        firstNameInput = document.getElementById('massFirstNameInput'),
        table = document.getElementById('studentsTable'),
        tbody = table.tBodies[0],
        selectAll = document.getElementById('selectAll'),
        searchInput = document.getElementById('searchInput'),
        filterSpe = document.getElementById('filterSpe'),
        filterOpt = document.getElementById('filterOpt'),
        filterOld = document.getElementById('filterOld'),
        selectedCount = document.getElementById('selectedCount'),
        filteredCount = document.getElementById('filteredCount'),
        deselectAllBtn = document.getElementById('deselectAllBtn'),
        selectClassToCheck = document.getElementById('selectClassToCheck'),
        checkClassBtn = document.getElementById('checkClassBtn');

    const assignModal = new bootstrap.Modal(document.getElementById('assignModal')),
        speModal = new bootstrap.Modal(document.getElementById('speModal')),
        optModal = new bootstrap.Modal(document.getElementById('optModal')),
        deleteModal = new bootstrap.Modal(document.getElementById('deleteModal')),
        editModal = new bootstrap.Modal(document.getElementById('editModal'));

    function getSelectedIds() {
        return Array.from(tbody.querySelectorAll('.row-checkbox:checked')).map(cb => parseInt(cb.value));
    }

    function updateSelectionCount() {
        const count = getSelectedIds().length;
        selectedCount.textContent = `${count} sélectionné(s)`;
        selectedCount.className = count > 0 ? 'badge bg-primary me-2' : 'badge bg-secondary me-2';
    }

    function updateDisplay() {
        let term = searchInput.value.trim().toLowerCase(),
            speF = Array.from(filterSpe.selectedOptions).map(o => o.value),
            optF = Array.from(filterOpt.selectedOptions).map(o => o.value),
            oldF = filterOld.value;

        let visibleCount = 0;
        
        tbody.querySelectorAll('tr').forEach(row => {
            let ok = true,
                name = row.querySelector('.col-name').textContent.toLowerCase(),
                first = row.querySelector('.col-first').textContent.toLowerCase(),
                spe = JSON.parse(row.dataset.spe),
                opt = JSON.parse(row.dataset.opt),
                oldc = row.dataset.oldClass;
            
            if (term && !name.includes(term) && !first.includes(term)) ok = false;
            if (speF.length && !speF.every(v => spe.includes(v))) ok = false;
            if (optF.length && !optF.every(v => opt.includes(v))) ok = false;
            if (oldF && oldc !== oldF) ok = false;
            
            row.style.display = ok ? '' : 'none';
            if (ok) visibleCount++;
        });
        
        filteredCount.textContent = `${visibleCount} élève(s) affiché(s)`;
    }

    function updateFilteredCount() {
        const visibleRows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.style.display !== 'none');
        filteredCount.textContent = `${visibleRows.length} élève(s) affiché(s)`;
    }

    [searchInput, filterSpe, filterOpt, filterOld].forEach(el => {
        el.addEventListener('input', updateDisplay);
        el.addEventListener('change', updateDisplay);
    });

    table.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            let col = Array.from(th.parentNode.children).indexOf(th),
                asc = !(th.dataset.asc === 'true');
            
            th.dataset.asc = asc;
            let rows = Array.from(tbody.rows);
            
            rows.sort((a, b) => {
                let aT = a.cells[col].textContent.trim().toLowerCase(),
                    bT = b.cells[col].textContent.trim().toLowerCase();
                return asc ? aT.localeCompare(bT) : bT.localeCompare(aT);
            });
            
            rows.forEach(r => tbody.appendChild(r));
            updateFilteredCount();
        });
    });

    selectAll.addEventListener('change', e => {
        tbody.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateSelectionCount();
    });

    deselectAllBtn.addEventListener('click', () => {
        tbody.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        updateSelectionCount();
    });

    checkClassBtn.addEventListener('click', () => {
        const classId = selectClassToCheck.value;
        if (!classId) return;
        tbody.querySelectorAll('tr').forEach(row => {
            if (parseInt(row.dataset.classId) === parseInt(classId)) {
                row.querySelector('.row-checkbox').checked = true;
            }
        });
        updateSelectionCount();
    });

    tbody.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            const row = this.closest('tr');
            row.classList.toggle('selected-row', this.checked);
            updateSelectionCount();
        });
        if (cb.checked) {
            cb.closest('tr').classList.add('selected-row');
        }
    });

    document.getElementById('massActionBtn').addEventListener('click', () => {
        let act = document.getElementById('massAction').value,
            ids = getSelectedIds();
        
        if (!act) return alert('Choisissez une action.');
        if (!ids.length) return alert('Sélectionnez au moins un élève.');
        
        actionInput.value = act;
        
        if (act === 'assign') assignModal.show();
        else if (act === 'mass_spe') speModal.show();
        else if (act === 'mass_opt') optModal.show();
        else if (act === 'mass_delete') deleteModal.show();
    });

    document.getElementById('assignConfirmBtn').addEventListener('click', () => {
        classInput.value = document.getElementById('assignClassSelect').value;
        if (!classInput.value) {
            alert('Veuillez sélectionner une classe');
            return;
        }
        form.submit();
    });

    document.getElementById('speConfirmBtn').addEventListener('click', () => {
        speInput.value = document.getElementById('speSelect').value;
        if (!speInput.value) {
            alert('Veuillez sélectionner une spécialité');
            return;
        }
        form.submit();
    });

    document.getElementById('optConfirmBtn').addEventListener('click', () => {
        optInput.value = document.getElementById('optSelect').value;
        if (!optInput.value) {
            alert('Veuillez sélectionner une option');
            return;
        }
        form.submit();
    });

    document.getElementById('deleteConfirmBtn').addEventListener('click', () => {
        form.submit();
    });

    document.querySelectorAll('.assign-btn').forEach(b => {
        b.addEventListener('click', () => {
            form.querySelectorAll('input[name="students[]"]').forEach(i => i.checked = false);
            let id = b.dataset.id;
            form.querySelector(`input[value="${id}"]`).checked = true;
            actionInput.value = 'assign';
            assignModal.show();
        });
    });

    document.querySelectorAll('.edit-btn').forEach(b => {
        b.addEventListener('click', () => {
            form.querySelectorAll('input[name="students[]"]').forEach(i => i.checked = false);
            let id = b.dataset.id;
            form.querySelector(`input[value="${id}"]`).checked = true;
            actionInput.value = 'edit';
            document.getElementById('editLastNameInput').value = b.dataset.last_name;
            document.getElementById('editFirstNameInput').value = b.dataset.first_name;
            editModal.show();
        });
    });

    document.getElementById('editConfirmBtn').addEventListener('click', () => {
        lastNameInput.value = document.getElementById('editLastNameInput').value;
        firstNameInput.value = document.getElementById('editFirstNameInput').value;
        form.submit();
    });

    document.querySelectorAll('.delete-btn').forEach(b => {
        b.addEventListener('click', () => {
            form.querySelectorAll('input[name="students[]"]').forEach(i => i.checked = false);
            form.querySelector(`input[value="${b.dataset.id}"]`).checked = true;
            actionInput.value = 'mass_delete';
            deleteModal.show();
        });
    });

    updateSelectionCount();
    updateDisplay();
    updateFilteredCount();
});
</script>
