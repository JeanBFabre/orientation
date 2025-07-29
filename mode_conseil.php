<?php
/**
 * mode_conseil.php
 * Interface "Big Picture" pour le déroulement du conseil de classe.
 * v3.2 - Correction de l'affichage (couleur texte) et de la logique d'affichage des trimestres pour les avis.
 */
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$classId = $_POST['class_id'] ?? $_SESSION['conseil_class_id'] ?? null;
$term = $_POST['term'] ?? $_SESSION['conseil_term'] ?? null;
if (!$classId || !$term) { header('Location: lancer_conseil.php'); exit; }
$_SESSION['conseil_class_id'] = $classId;
$_SESSION['conseil_term'] = $term;

// --- Récupération des informations de la classe et vérification des droits ---
$stmt = $conn->prepare("SELECT name, level, teacher_id FROM classes WHERE id = ?");
$stmt->bind_param('i', $classId);
$stmt->execute();
$classInfo = $stmt->get_result()->fetch_assoc();
if (!$classInfo || $classInfo['teacher_id'] != $_SESSION['user_id']) { die("Accès non autorisé."); }
$stmt->close();

// --- Récupération de la liste des élèves et gestion de la navigation ---
$studentsQuery = $conn->prepare("SELECT id, first_name, last_name FROM students WHERE class_id = ? ORDER BY last_name, first_name");
$studentsQuery->bind_param('i', $classId);
$studentsQuery->execute();
$studentList = $studentsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$studentsQuery->close();
if (empty($studentList)) { die("Cette classe n'a aucun élève."); }

$currentStudentId = $_GET['student_id'] ?? $studentList[0]['id'];
$currentIndex = array_search($currentStudentId, array_column($studentList, 'id'));
$previousStudentId = ($currentIndex > 0) ? $studentList[$currentIndex - 1]['id'] : null;
$nextStudentId = ($currentIndex < count($studentList) - 1) ? $studentList[$currentIndex + 1]['id'] : null;

// --- Préparation du tableau de données de l'élève courant ---
$studentData = [];
$studentData['info'] = $studentList[$currentIndex];
$studentData['progress'] = ($currentIndex + 1) . ' / ' . count($studentList);

// --- Récupération des préférences, options, mention, notes et commentaires ---
$prefStmt = $conn->prepare("SELECT * FROM preferences WHERE student_id = ? AND grade = ? LIMIT 1");
$prefStmt->bind_param('is', $currentStudentId, $classInfo['level']);
$prefStmt->execute();
$studentData['preferences'] = $prefStmt->get_result()->fetch_assoc() ?? [];
$preferenceId = $studentData['preferences']['id'] ?? null;
$prefStmt->close();

$options_string = $studentData['preferences']['options'] ?? '';
$studentData['options_list'] = $options_string ? explode('||', $options_string) : [];

$mentionStmt = $conn->prepare("SELECT m.id, m.mention FROM mentions m JOIN preferences p ON m.preference_id = p.id WHERE p.student_id = ? AND p.grade = ? AND m.trimester = ?");
$mentionStmt->bind_param('isi', $currentStudentId, $classInfo['level'], $term);
$mentionStmt->execute();
$studentData['mention'] = $mentionStmt->get_result()->fetch_assoc() ?? ['id' => null, 'mention' => ''];
$mentionStmt->close();

$studentData['grades'] = [];
$gradesStmt = $conn->prepare("SELECT content FROM notes WHERE student_id = ?");
$gradesStmt->bind_param('i', $currentStudentId);
$gradesStmt->execute();
$result = $gradesStmt->get_result();
while ($row = $result->fetch_assoc()) {
    $gradeData = json_decode($row['content'], true);
    if (is_array($gradeData) && isset($gradeData['trimester']) && $gradeData['trimester'] == $term) {
        $studentData['grades'][] = $gradeData;
    }
}
$gradesStmt->close();

$commentsStmt = $conn->prepare("SELECT content, author_name, created_at FROM comments WHERE student_id = ? ORDER BY created_at DESC");
$commentsStmt->bind_param('i', $currentStudentId);
$commentsStmt->execute();
$studentData['comments'] = $commentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$commentsStmt->close();

// --- Récupération de l'historique complet (mentions, etc.) ---
$historyData = ['mentions' => []];
$historyStmt = $conn->prepare("
    SELECT p.grade, m.trimester, m.mention
    FROM mentions m
    JOIN preferences p ON m.preference_id = p.id
    WHERE p.student_id = ? AND m.mention != ''
    ORDER BY p.grade, m.trimester
");
$historyStmt->bind_param('i', $currentStudentId);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();
while ($row = $historyResult->fetch_assoc()) {
    $historyData['mentions'][$row['grade']][$row['trimester']] = $row['mention'];
}
$historyStmt->close();


// --- Récupération des données liées aux spécialités selon le niveau ---
$specialtyData = [
    'seconde_voeux' => [], 'seconde_opinions' => [], 'premiere_spe' => [],
    'premiere_abandon' => null, 'terminale_spe' => [], 'terminale_historique' => []
];

if ($preferenceId) {
    if ($classInfo['level'] === 'Seconde') {
        $specialtyData['seconde_voeux']['t1'] = !empty($studentData['preferences']['specialties']) ? explode('||', $studentData['preferences']['specialties']) : [];
        $specialtyData['seconde_voeux']['t2'] = !empty($studentData['preferences']['specialties_t2']) ? explode('||', $studentData['preferences']['specialties_t2']) : [];
        $specialtyData['seconde_voeux']['t3'] = !empty($studentData['preferences']['specialties_t3']) ? explode('||', $studentData['preferences']['specialties_t3']) : [];

        $opinionsStmt = $conn->prepare("SELECT trimester, specialty, status FROM opinions WHERE preference_id = ?");
        $opinionsStmt->bind_param('i', $preferenceId);
        $opinionsStmt->execute();
        $opinionsResult = $opinionsStmt->get_result();
        while($row = $opinionsResult->fetch_assoc()) {
            $specialtyData['seconde_opinions'][$row['trimester']][$row['specialty']] = $row['status'];
        }
        $opinionsStmt->close();
    }
    elseif ($classInfo['level'] === 'Première') {
        $specialtyData['premiere_spe'] = !empty($studentData['preferences']['specialties']) ? explode('||', $studentData['preferences']['specialties']) : [];
        $specialtyData['premiere_abandon'] = $studentData['preferences']['drop_specialty'] ?? null;

        $archiveSecondeStmt = $conn->prepare("SELECT specialties_t3 FROM preferences WHERE student_id = ? AND grade = 'Seconde' LIMIT 1");
        $archiveSecondeStmt->bind_param('i', $currentStudentId);
        $archiveSecondeStmt->execute();
        $archiveSecondeData = $archiveSecondeStmt->get_result()->fetch_assoc();
        $archiveSecondeStmt->close();
        if($archiveSecondeData && !empty($archiveSecondeData['specialties_t3'])){
            $specialtyData['premiere_historique']['choix_seconde'] = explode('||', $archiveSecondeData['specialties_t3']);
        }

    }
    elseif ($classInfo['level'] === 'Terminale') {
        $archiveStmt = $conn->prepare("SELECT specialties, drop_specialty FROM preferences WHERE student_id = ? AND grade = 'Première' LIMIT 1");
        $archiveStmt->bind_param('i', $currentStudentId);
        $archiveStmt->execute();
        $archiveData = $archiveStmt->get_result()->fetch_assoc();
        $archiveStmt->close();

        if ($archiveData) {
            $spePremiere = !empty($archiveData['specialties']) ? explode('||', $archiveData['specialties']) : [];
            $speAbandon = $archiveData['drop_specialty'] ?? '';
            $specialtyData['terminale_spe'] = array_diff($spePremiere, [$speAbandon]);
            $specialtyData['terminale_historique']['abandon'] = $speAbandon;
        }

        $archiveSecondeStmt = $conn->prepare("SELECT specialties_t3 FROM preferences WHERE student_id = ? AND grade = 'Seconde' LIMIT 1");
        $archiveSecondeStmt->bind_param('i', $currentStudentId);
        $archiveSecondeStmt->execute();
        $archiveSecondeData = $archiveSecondeStmt->get_result()->fetch_assoc();
        $archiveSecondeStmt->close();
        if($archiveSecondeData && !empty($archiveSecondeData['specialties_t3'])){
            $specialtyData['terminale_historique']['choix_seconde'] = explode('||', $archiveSecondeData['specialties_t3']);
        }
    }
}

// --- GESTION DES REQUÊTES AJAX ---
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Action inconnue.'];
    $studentIdAjax = $_POST['student_id'] ?? null;
    $preferenceIdAjax = $_POST['preference_id'] ?? null;

    if ($_POST['ajax_action'] === 'save_opinion' && $preferenceIdAjax) {
        $trimester = $_POST['trimester'] ?? null;
        $specialty = $_POST['specialty'] ?? null;
        $status = $_POST['status'] ?? null;
        if ($trimester && $specialty && $status) {
            $updateStmt = $conn->prepare("UPDATE opinions SET status = ? WHERE preference_id = ? AND trimester = ? AND specialty = ?");
            $updateStmt->bind_param('siis', $status, $preferenceIdAjax, $trimester, $specialty);
            $updateStmt->execute();
            if ($updateStmt->affected_rows === 0) {
                $insertStmt = $conn->prepare("INSERT INTO opinions (preference_id, trimester, specialty, status) VALUES (?, ?, ?, ?)");
                $insertStmt->bind_param('iiss', $preferenceIdAjax, $trimester, $specialty, $status);
                if ($insertStmt->execute()) { $response = ['success' => true]; }
            } else { $response = ['success' => true]; }
        }
    }
    elseif ($_POST['ajax_action'] === 'save_mention' && $preferenceIdAjax) {
        $mention = $_POST['mention'];
        $updateStmt = $conn->prepare("UPDATE mentions SET mention = ? WHERE preference_id = ? AND trimester = ?");
        $updateStmt->bind_param('sii', $mention, $preferenceIdAjax, $term);
        $updateStmt->execute();
        if ($updateStmt->affected_rows === 0 && !empty($mention)) {
            $insertStmt = $conn->prepare("INSERT INTO mentions (preference_id, trimester, mention) VALUES (?, ?, ?)");
            $insertStmt->bind_param('iis', $preferenceIdAjax, $term, $mention);
            $insertStmt->execute();
        }
        $response = ['success' => true];
    }
    elseif ($_POST['ajax_action'] === 'add_note' && $studentIdAjax) {
        $content = $_POST['content'];
        $authorId = $_SESSION['user_id'];
        $authorName = $_SESSION['name'];
        $addNoteStmt = $conn->prepare("INSERT INTO comments (student_id, author_id, author_name, content, parent_id) VALUES (?, ?, ?, ?, NULL)");
        $addNoteStmt->bind_param('iiss', $studentIdAjax, $authorId, $authorName, $content);
        if ($addNoteStmt->execute()) {
            $response = ['success' => true, 'author' => $authorName, 'content' => htmlspecialchars($content), 'date' => date('d/m/Y H:i')];
        } else {
            $response = ['success' => false, 'message' => 'Erreur BDD.'];
        }
    }
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conseil de Classe - <?= htmlspecialchars($studentData['info']['first_name'] . ' ' . $studentData['info']['last_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #4361ee; --primary-light: #4895ef; --secondary: #aface7f5; --dark: #1e2a38; --dark-panel: #2a3b4d; --light: #f8f9fa; --text-light: #e1e9f2; --text-muted: #8495a9; --border-color: #40566e; --success: #4cc9f0; --warning: #f7b801; --danger: #f72585; --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, var(--dark) 0%, #16202d 100%); color: var(--text-light); font-family: 'Roboto', sans-serif; min-height: 100vh; overflow-x: hidden; overscroll-behavior: none; }
        h1, h2, h3, h4, h5, .section-title { font-family: 'Montserrat', sans-serif; font-weight: 600; }
        .main-container { display: grid; grid-template-rows: auto 1fr; height: 100vh; padding: 1.5rem; gap: 1.5rem; position: relative; max-width: 1600px; margin: 0 auto; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; background: rgba(42, 59, 77, 0.85); border-radius: 16px; border: 1px solid var(--border-color); backdrop-filter: blur(10px); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25); z-index: 20; animation: slideDown 0.5s ease-out; }
        .student-info h1 { font-size: 2.2rem; font-weight: 700; margin-bottom: 0.25rem; background: linear-gradient(to right, var(--text-light), var(--primary-light)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.5px; }
        .student-info h2 { font-size: 1.1rem; font-weight: 400; color: var(--text-muted); }
        .progress-indicator { position: absolute; bottom: 1.5rem; left: 50%; transform: translateX(-50%); background: rgba(42, 59, 77, 0.9); padding: 0.5rem 1.25rem; border-radius: 50px; font-weight: 500; font-size: 0.9rem; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); z-index: 30; animation: fadeInUp 0.6s 0.2s both; }
        .nav-arrow { position: absolute; top: 50%; transform: translateY(-50%); z-index: 40; width: 65px; height: 65px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: rgba(42, 59, 77, 0.7); border: 1px solid var(--border-color); color: var(--text-light); font-size: 2.2rem; text-decoration: none; transition: var(--transition); backdrop-filter: blur(5px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2); }
        #prev-link { left: 1.5rem; animation: fadeInLeft 0.6s 0.4s both; }
        #next-link { right: 1.5rem; animation: fadeInRight 0.6s 0.4s both; }
        .nav-arrow:hover:not(.disabled) { background: var(--primary); transform: translateY(-50%) scale(1.1); box-shadow: 0 0 25px rgba(67, 97, 238, 0.4); }
        .nav-arrow.disabled { opacity: 0.3; cursor: not-allowed; }
        .content-panel { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: auto auto; gap: 1.5rem; height: 100%; animation: fadeIn 0.8s 0.1s both; }
        .panel-section { background: rgba(42, 59, 77, 0.7); border-radius: 20px; border: 1px solid var(--border-color); padding: 1.75rem; backdrop-filter: blur(8px); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2); overflow: hidden; display: flex; flex-direction: column; }
        .panel-section.left { animation: slideInLeft 0.7s 0.2s both; }
        .panel-section.right { animation: slideInRight 0.7s 0.2s both; }
        .section-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 2px solid var(--primary); color: var(--text-light); display: flex; align-items: center; }
        .section-title i { margin-right: 0.75rem; color: var(--primary-light); }
        .grades-container, .right-panel-content { flex: 1; overflow-y: auto; padding-right: 0.5rem; }
        .subject-card { background: rgba(30, 42, 56, 0.6); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; transition: var(--transition); }
        .subject-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2); border-color: var(--primary-light); }
        .grade-value { font-weight: 700; font-size: 1.4rem; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: rgba(0, 0, 0, 0.3); }
        .grade-value.good { color: var(--success); border: 2px solid var(--success); }
        .grade-value.medium { color: var(--warning); border: 2px solid var(--warning); }
        .grade-value.bad { color: var(--danger); border: 2px solid var(--danger); }
        .pref-card { background: rgba(30, 42, 56, 0.6); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; border: 1px solid var(--border-color); }
        .pref-title { font-weight: 600; color: var(--primary-light); margin-bottom: 0.75rem; display: flex; align-items: center; font-size: 1.1rem; }
        .pref-title i { margin-right: 0.5rem; }
        .tag { display: inline-block; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; font-weight: 500; margin: 0.2rem; background-color: rgba(76, 201, 240, 0.15); color: var(--success); border: 1px solid rgba(76, 201, 240, 0.2); }
        .tag.abandon { background-color: rgba(247, 37, 133, 0.15); color: var(--danger); border-color: rgba(247, 37, 133, 0.2); }
        .tag.option { background-color: rgba(132, 149, 169, 0.15); color: #8495a9; border-color: rgba(132, 149, 169, 0.2); }
        .h6-subtitle { font-size: 0.9rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1rem; }
        .opinion-interactive-group { display: flex; align-items: center; justify-content: space-between; padding: .5rem 0; }
        .btn-check:checked+.btn-outline-success, .btn-check:checked+.btn-outline-warning, .btn-check:checked+.btn-outline-danger { color: #fff; }
        .accordion-item { background-color: transparent !important; border: 1px solid var(--border-color) !important; border-radius: 12px !important; margin-bottom: 0.75rem; }
        .accordion-button { background: var(--dark-panel) !important; color: var(--text-light) !important; font-weight: 600; box-shadow: none !important; border-radius: 11px !important; }
        .accordion-button:not(.collapsed) { background: rgba(30, 42, 56, 0.9) !important; color: var(--primary-light) !important; }
        .accordion-button::after { filter: invert(1) grayscale(100%) brightness(200%); }
        .text-success { color: var(--success) !important; }
        .text-warning { color: var(--warning) !important; }
        .text-danger { color: var(--danger) !important; }
        .mention-container, .comments-container { margin-top: auto; }
        .add-comment button:hover { background: var(--secondary); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3); }
        .no-data { text-align: center; padding: 2rem; color: var(--text-muted); font-style: italic; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @keyframes slideInLeft { from { transform: translateX(-50px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideInRight { from { transform: translateX(50px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes fadeInUp { from { transform: translate(-50%, 20px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(30, 42, 56, 0.3); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary-light); }
        @media (max-width: 992px) { .content-panel { grid-template-columns: 1fr; } .nav-arrow { width: 50px; height: 50px; font-size: 1.8rem; } .student-info h1 { font-size: 1.8rem; } }
        @media (max-width: 768px) { .main-container { padding: 1rem; } .top-bar { flex-direction: column; text-align: center; gap: 1rem; padding: 1rem; } .progress-indicator { position: relative; bottom: auto; left: auto; transform: none; margin-top: 1rem; } .panel-section { padding: 1.25rem; } }
    </style>
</head>
<body>

    <div class="main-container">
        <a href="<?= $previousStudentId ? '?student_id='.$previousStudentId : '#' ?>" id="prev-link" class="nav-arrow <?= !$previousStudentId ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
        <a href="<?= $nextStudentId ? '?student_id='.$nextStudentId : '#' ?>" id="next-link" class="nav-arrow <?= !$nextStudentId ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
        <div class="progress-indicator">Élève <?= htmlspecialchars($studentData['progress']) ?></div>

        <header class="top-bar">
            <div class="student-info">
                <h1><?= htmlspecialchars($studentData['info']['first_name'] . ' ' . $studentData['info']['last_name']) ?></h1>
                <h2><?= htmlspecialchars($classInfo['level'] . ' ' . $classInfo['name']) ?> - Trimestre <?= $term ?></h2>
            </div>
            <a href="#" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#exitModal"><i class="bi bi-x-lg me-2"></i>Quitter</a>
        </header>

        <main class="content-panel">
            <section class="panel-section left">
                <h3 class="section-title"><i class="bi bi-journal-bookmark-fill"></i>Résultats Académiques</h3>
                <div class="grades-container">
                    <?php if (!empty($studentData['grades'])): ?>
                        <?php foreach($studentData['grades'] as $gradeInfo): ?>
                            <?php
                                $moyenne = floatval($gradeInfo['moyenne'] ?? 0);
                                $gradeClass = ($moyenne >= 12) ? 'good' : (($moyenne >= 8) ? 'medium' : 'bad');
                            ?>
                            <div class="subject-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0" style="font-weight: 500;"><?= htmlspecialchars($gradeInfo['matiere'] ?? 'N/A') ?></h5>
                                    <div class="grade-value <?= $gradeClass ?>"><?= htmlspecialchars(number_format($moyenne, 2, ',', ' ')) ?></div>
                                </div>
                                <div class="mt-2 text-muted" style="font-style: italic;"><?= htmlspecialchars($gradeInfo['appreciation'] ?? '') ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data"><i class="bi bi-journal-x" style="font-size: 3rem; margin-bottom: 1rem;"></i><h4>Aucun résultat enregistré</h4></div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel-section right">
                <h3 class="section-title"><i class="bi bi-briefcase-fill"></i>Projet & Avis du Conseil</h3>
                <div class="right-panel-content">

                    <?php if ($classInfo['level'] === 'Seconde'): ?>
                        <div class="pref-card">
                            <div class="pref-title"><i class="bi bi-stars"></i>Vœux de Spécialités & Avis</div>
                            <div class="accordion" id="accordionSeconde">
                                <?php for ($t = 1; $t <= 3; $t++): ?>
                                    <?php
                                        $voeux_trimestre = $specialtyData['seconde_voeux']['t'.$t] ?? [];
                                        $isCurrentTerm = ($t == $term);
                                        // MODIFICATION: Afficher la section si (ce sont des vœux non-vides) OU (c'est le trimestre actuel)
                                        if (empty($voeux_trimestre) && !$isCurrentTerm) {
                                            continue;
                                        }
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="heading-T<?= $t ?>">
                                            <button class="accordion-button <?= !$isCurrentTerm ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-T<?= $t ?>">
                                                Trimestre <?= $t ?>
                                                <?php if ($t == 3 && !empty($voeux_trimestre)): ?><span class="badge bg-primary ms-2">Vœux Définitifs</span><?php endif; ?>
                                            </button>
                                        </h2>
                                        <div id="collapse-T<?= $t ?>" class="accordion-collapse collapse <?= $isCurrentTerm ? 'show' : '' ?>" data-bs-parent="#accordionSeconde">
                                            <div class="accordion-body">
                                                <?php if (!empty($voeux_trimestre)): ?>
                                                    <p><strong style="color: var(--text-light);">Vœux :</strong> <?php foreach ($voeux_trimestre as $v): ?><span class="tag"><?= htmlspecialchars($v) ?></span><?php endforeach; ?></p>
                                                    <hr style="border-color: var(--border-color); margin: 1rem 0;">
                                                    <strong style="color: var(--text-light);">Avis du conseil :</strong>

                                                    <?php if ($isCurrentTerm): ?>
                                                        <?php foreach ($voeux_trimestre as $spe): ?>
                                                            <?php $currentStatus = $specialtyData['seconde_opinions'][$t][$spe] ?? ''; ?>
                                                            <div class="opinion-interactive-group">
                                                                <span class="specialty-name text-truncate" title="<?= htmlspecialchars($spe) ?>" style="color: var(--text-light);"><?= htmlspecialchars($spe) ?></span>
                                                                <div class="btn-group btn-group-sm" role="group">
                                                                    <input type="radio" class="btn-check opinion-radio" name="opinion-T<?= $t ?>-<?= md5($spe) ?>" id="f-T<?= $t ?>-<?= md5($spe) ?>" value="Favorable" autocomplete="off" <?= $currentStatus === 'Favorable' ? 'checked' : '' ?> data-preference-id="<?= $preferenceId ?>" data-trimester="<?= $t ?>" data-specialty="<?= htmlspecialchars($spe, ENT_QUOTES) ?>">
                                                                    <label class="btn btn-outline-success" for="f-T<?= $t ?>-<?= md5($spe) ?>" title="Favorable">F</label>

                                                                    <input type="radio" class="btn-check opinion-radio" name="opinion-T<?= $t ?>-<?= md5($spe) ?>" id="r-T<?= $t ?>-<?= md5($spe) ?>" value="Réserve" autocomplete="off" <?= $currentStatus === 'Réserve' ? 'checked' : '' ?> data-preference-id="<?= $preferenceId ?>" data-trimester="<?= $t ?>" data-specialty="<?= htmlspecialchars($spe, ENT_QUOTES) ?>">
                                                                    <label class="btn btn-outline-warning" for="r-T<?= $t ?>-<?= md5($spe) ?>" title="Réservé">R</label>

                                                                    <input type="radio" class="btn-check opinion-radio" name="opinion-T<?= $t ?>-<?= md5($spe) ?>" id="d-T<?= $t ?>-<?= md5($spe) ?>" value="Défavorable" autocomplete="off" <?= $currentStatus === 'Défavorable' ? 'checked' : '' ?> data-preference-id="<?= $preferenceId ?>" data-trimester="<?= $t ?>" data-specialty="<?= htmlspecialchars($spe, ENT_QUOTES) ?>">
                                                                    <label class="btn btn-outline-danger" for="d-T<?= $t ?>-<?= md5($spe) ?>" title="Défavorable">D</label>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: /* HISTORIQUE (trimestres passés avec vœux) */ ?>
                                                        <?php foreach ($voeux_trimestre as $spe): ?>
                                                            <?php
                                                                $pastStatus = $specialtyData['seconde_opinions'][$t][$spe] ?? 'N/A';
                                                                $statusInitial = '•'; $statusClass = 'text-muted';
                                                                if ($pastStatus === 'Favorable') { $statusInitial = 'F'; $statusClass = 'text-success'; }
                                                                if ($pastStatus === 'Réserve') { $statusInitial = 'R'; $statusClass = 'text-warning'; }
                                                                if ($pastStatus === 'Défavorable') { $statusInitial = 'D'; $statusClass = 'text-danger'; }
                                                            ?>
                                                            <div class="d-flex justify-content-between py-1">
                                                                <span class="text-truncate" title="<?= htmlspecialchars($spe) ?>" style="color: var(--text-light);"><?= htmlspecialchars($spe) ?></span>
                                                                <strong class="<?= $statusClass ?> fs-5"><?= $statusInitial ?></strong>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <p class="fst-italic" style="color: #afc1d4;">Aucun vœu n'a été exprimé pour ce trimestre.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($classInfo['level'] === 'Première'): ?>
                        <div class="pref-card">
                            <div class="pref-title"><i class="bi bi-scissors"></i>Projet pour la Terminale</div>
                            <div class="pref-content">
                                <?php if (!empty($specialtyData['premiere_spe'])): ?>
                                    <p class="mb-2"><strong>Spécialités suivies :</strong></p>
                                    <p><?php foreach ($specialtyData['premiere_spe'] as $spe): ?><span class="tag <?= ($spe === $specialtyData['premiere_abandon']) ? 'abandon' : '' ?>"><?= htmlspecialchars($spe) ?></span><?php endforeach; ?></p>
                                    <hr style="border-color: var(--border-color);">
                                    <?php if ($specialtyData['premiere_abandon']): ?>
                                        <p class="mb-2"><strong>Spécialité abandonnée :</strong></p>
                                        <p><span class="tag abandon"><?= htmlspecialchars($specialtyData['premiere_abandon']) ?></span></p>
                                    <?php elseif ($term == 3): ?>
                                        <div class="alert alert-warning" style="background-color: rgba(247, 184, 1, 0.1); color: var(--warning); border-color: rgba(247, 184, 1, 0.3);"><strong>Attention :</strong> L'élève doit choisir une spécialité à abandonner.</div>
                                    <?php else: ?><p class="fst-italic" style="color: #afc1d4;">Aucun projet d'abandon de spécialité pour le moment.</p><?php endif; ?>
                                <?php else: ?><p class="no-data">Aucune spécialité enregistrée.</p><?php endif; ?>
                            </div>
                        </div>
                        <div class="pref-card">
                               <div class="pref-title"><i class="bi bi-archive-fill"></i>Historique du parcours</div>
                               <div class="pref-content">
                                  <?php if(!empty($specialtyData['premiere_historique']['choix_seconde']) || !empty($historyData['mentions']['Seconde']) ): ?>
                                  <h6 class="h6-subtitle"><i class="bi bi-calendar-check"></i> En Seconde</h6>
                                  <?php if(!empty($specialtyData['premiere_historique']['choix_seconde'])): ?>
                                      <p><strong>Vœux finaux :</strong> <?php foreach($specialtyData['premiere_historique']['choix_seconde'] as $spe): ?><span class="tag option"><?= htmlspecialchars($spe) ?></span><?php endforeach; ?></p>
                                  <?php endif; ?>
                                  <?php if(!empty($historyData['mentions']['Seconde'])): ?>
                                      <p><strong>Mentions :</strong> <?php foreach($historyData['mentions']['Seconde'] as $mt => $mn): ?><span class="tag option">T<?= $mt ?>: <?= htmlspecialchars($mn) ?></span><?php endforeach; ?></p>
                                  <?php endif; ?>
                                  <?php endif; ?>
                               </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($classInfo['level'] === 'Terminale'): ?>
                        <div class="pref-card">
                               <div class="pref-title"><i class="bi bi-check2-circle"></i>Spécialités de Terminale</div>
                               <div class="pref-content">
                                   <?php if (!empty($specialtyData['terminale_spe'])): ?>
                                       <?php foreach ($specialtyData['terminale_spe'] as $spe): ?><span class="tag"><?= htmlspecialchars($spe) ?></span><?php endforeach; ?>
                                   <?php else: ?><p class="no-data">Données non disponibles.</p><?php endif; ?>
                               </div>
                        </div>
                        <div class="pref-card">
                               <div class="pref-title"><i class="bi bi-archive-fill"></i>Historique du parcours</div>
                               <div class="pref-content">
                                   <h6 class="h6-subtitle"><i class="bi bi-calendar-check"></i> En Première</h6>
                                   <?php if (!empty($specialtyData['terminale_historique']['abandon'])): ?>
                                       <p><strong>Spécialité abandonnée :</strong> <span class="tag abandon"><?= htmlspecialchars($specialtyData['terminale_historique']['abandon']) ?></span></p>
                                   <?php endif; ?>
                                   <?php if(!empty($historyData['mentions']['Première'])): ?>
                                       <p><strong>Mentions :</strong> <?php foreach($historyData['mentions']['Première'] as $mt => $mn): ?><span class="tag option">T<?= $mt ?>: <?= htmlspecialchars($mn) ?></span><?php endforeach; ?></p>
                                   <?php endif; ?>

                                   <h6 class="h6-subtitle"><i class="bi bi-calendar-event"></i> En Seconde</h6>
                                   <?php if (!empty($specialtyData['terminale_historique']['choix_seconde'])): ?>
                                       <p><strong>Vœux finaux :</strong> <?php foreach ($specialtyData['terminale_historique']['choix_seconde'] as $spe): ?><span class="tag option"><?= htmlspecialchars($spe) ?></span><?php endforeach; ?></p>
                                   <?php endif; ?>
                                   <?php if(!empty($historyData['mentions']['Seconde'])): ?>
                                       <p><strong>Mentions :</strong> <?php foreach($historyData['mentions']['Seconde'] as $mt => $mn): ?><span class="tag option">T<?= $mt ?>: <?= htmlspecialchars($mn) ?></span><?php endforeach; ?></p>
                                   <?php endif; ?>
                               </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($studentData['options_list'])): ?>
                    <div class="pref-card">
                        <div class="pref-title"><i class="bi bi-plus-circle-dotted"></i>Options Facultatives</div>
                        <div class="pref-content"><?php foreach($studentData['options_list'] as $option): ?><span class="tag option"><?= htmlspecialchars($option) ?></span><?php endforeach; ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="mention-container mt-auto pt-3">
                         <h4 class="section-title mb-3" style="font-size: 1.1rem; border:0;"><i class="bi bi-award-fill"></i>Mention du Conseil</h4>
                         <select class="form-select" id="mentionSelect" data-preference-id="<?= $preferenceId ?? '' ?>" style="background: rgba(30, 42, 56, 0.6); border: 1px solid var(--border-color); color: var(--text-light);">
                             <option value="" <?= empty($studentData['mention']['mention']) ? 'selected' : '' ?>>Aucune mention</option>
                             <option value="Encouragement" <?= $studentData['mention']['mention'] === 'Encouragement' ? 'selected' : '' ?>>Encouragement</option>
                             <option value="Compliments" <?= $studentData['mention']['mention'] === 'Compliments' ? 'selected' : '' ?>>Compliments</option>
                             <option value="Félicitations" <?= $studentData['mention']['mention'] === 'Félicitations' ? 'selected' : '' ?>>Félicitations</option>
                         </select>
                         <div id="saveMentionFeedback" class="form-text" style="height: 1.2rem;"></div>
                    </div>

                </div>
            </section>

             <section class="panel-section" style="grid-column: 1 / -1; animation: slideInLeft 0.7s 0.2s both;">
                   <h3 class="section-title"><i class="bi bi-chat-left-text-fill"></i>Commentaires Généraux</h3>
                   <div style="display: flex; flex-direction: column; flex-grow: 1; min-height: 0;">
                     <div class="flex-grow-1" style="display: grid; grid-template-columns: 3fr 2fr; gap: 1.5rem; min-height: 0;">
                        <div class="comment-box" id="comment-list" style="overflow-y: auto; padding-right: 0.5rem;">
                            <?php if(!empty($studentData['comments'])): ?>
                                <?php foreach($studentData['comments'] as $comment): ?>
                                    <div class="comment" style="background: rgba(30, 42, 56, 0.6); border-radius: 12px; padding: 1rem; margin-bottom: 0.75rem; border: 1px solid var(--border-color);">
                                        <div class="d-flex justify-content-between mb-2"><div class="fw-bold" style="color:var(--primary-light)"><?= htmlspecialchars($comment['author_name']) ?></div><div class="small text-muted"><?= date('d/m/Y H:i', strtotime($comment['created_at'])) ?></div></div>
                                        <div><?= nl2br(htmlspecialchars($comment['content'])) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-data" id="no-comment-msg"><i class="bi bi-chat-left" style="font-size: 2rem; margin-bottom: 0.5rem;"></i><p>Aucun commentaire.</p></div>
                            <?php endif; ?>
                        </div>
                        <div class="add-comment" style="display: flex; flex-direction: column;">
                           <textarea class="form-control" id="newNote" placeholder="Ajoutez un commentaire général..." rows="4" style="background: rgba(30, 42, 56, 0.6); color: var(--text-light); border-color: var(--border-color); flex-grow: 1;"></textarea>
                           <div class="d-grid">
                               <button class="btn btn-primary mt-2" id="addNoteBtn"><i class="bi bi-send-fill me-2"></i>Enregistrer</button>
                           </div>
                        </div>
                     </div>
                   </div>
             </section>
        </main>
    </div>

    <div class="modal fade" id="exitModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="background: var(--dark-panel); border: 1px solid var(--border-color);"><div class="modal-header"><h5 class="modal-title">Quitter le Conseil de Classe</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Êtes-vous sûr de vouloir mettre fin à la session ?</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Rester</button><a href="lancer_conseil.php" class="btn btn-danger">Confirmer et Quitter</a></div></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const studentId = <?= $currentStudentId ?>;

        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
            if (e.key === 'ArrowRight' && document.getElementById('next-link')) document.getElementById('next-link').click();
            if (e.key === 'ArrowLeft' && document.getElementById('prev-link')) document.getElementById('prev-link').click();
        });

        const mentionSelect = document.getElementById('mentionSelect');
        if (mentionSelect) {
            mentionSelect.addEventListener('change', function() {
                const preferenceId = this.dataset.preferenceId;
                const feedbackMention = document.getElementById('saveMentionFeedback');
                if (!preferenceId) {
                    feedbackMention.textContent = "Erreur: Fiche de vœux non trouvée.";
                    feedbackMention.style.color = 'var(--danger)';
                    return;
                }
                const formData = new FormData();
                formData.append('ajax_action', 'save_mention');
                formData.append('preference_id', preferenceId);
                formData.append('mention', this.value);
                fetch(window.location.pathname, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
                    if (data.success) {
                        feedbackMention.textContent = "Mention enregistrée !";
                        feedbackMention.style.color = 'var(--success)';
                        setTimeout(() => { feedbackMention.textContent = ""; }, 2000);
                    }
                });
            });
        }

        const addNoteBtn = document.getElementById('addNoteBtn');
        if(addNoteBtn) {
            addNoteBtn.addEventListener('click', function() {
                const newNoteTextarea = document.getElementById('newNote');
                const content = newNoteTextarea.value.trim();
                if (content === '') return;
                const formData = new FormData();
                formData.append('ajax_action', 'add_note');
                formData.append('student_id', studentId);
                formData.append('content', content);
                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Enregistrement...';
                fetch(window.location.pathname, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
                    if (data.success) {
                        newNoteTextarea.value = '';
                        const commentList = document.getElementById('comment-list');
                        const noCommentMsg = document.getElementById('no-comment-msg');
                        if (noCommentMsg) noCommentMsg.remove();
                        const newCommentDiv = document.createElement('div');
                        newCommentDiv.style.cssText = "background: rgba(30, 42, 56, 0.6); border-radius: 12px; padding: 1rem; margin-bottom: 0.75rem; border: 1px solid var(--border-color);";
                        newCommentDiv.innerHTML = `<div class="d-flex justify-content-between mb-2"><div class="fw-bold" style="color:var(--primary-light)">${data.author}</div><div class="small text-muted">${data.date}</div></div><div>${data.content.replace(/\n/g, '<br>')}</div>`;
                        commentList.prepend(newCommentDiv);
                    }
                }).finally(() => {
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-send-fill me-2"></i> Enregistrer';
                });
            });
        }

        document.querySelectorAll('.opinion-radio').forEach(radio => {
            radio.addEventListener('change', function() {
                const prefId = this.dataset.preferenceId;
                const trimester = this.dataset.trimester;
                if (!prefId) {
                    console.error('Erreur: Fiche de vœux non trouvée.');
                    return;
                }
                const formData = new FormData();
                formData.append('ajax_action', 'save_opinion');
                formData.append('preference_id', prefId);
                formData.append('trimester', trimester);
                formData.append('specialty', this.dataset.specialty);
                formData.append('status', this.value);
                fetch(window.location.pathname, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) { console.error('Erreur lors de la sauvegarde de l\'avis.'); }
                });
            });
        });
    });
    </script>
</body>
</html>