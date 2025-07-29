<?php
 /** * profile.php - Version corrigée et optimisée
  * * Principales corrections :
  * 1. Résolution du problème de réinitialisation des avis sur les spécialités
  * 2. Réorganisation complète du code pour une meilleure maintenabilité
  * 3. Amélioration de la gestion des transactions SQL
  * 4. Validation renforcée des données
  * 5. Interface utilisateur améliorée
  */

 // Configuration et sécurité
 ini_set('display_errors', 1);
 ini_set('display_startup_errors', 1);
 error_reporting(E_ALL);
 mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
 date_default_timezone_set('Europe/Paris');

 require_once 'config.php';

 // Vérification authentification
 if (!isset($_SESSION['user_id'])) {
     header('Location: login.php');
     exit;
 }

 $meId = $_SESSION['user_id'];
 $myName = '';
 $myRole = '';

 // Récupérer le nom et le rôle de l'utilisateur connecté
 $stmtUser = $conn->prepare("SELECT name, role FROM users WHERE id = ?");
 $stmtUser->bind_param('i', $meId);
 $stmtUser->execute();
 $userResult = $stmtUser->get_result();
 if ($connectedUser = $userResult->fetch_assoc()) {
     $myName = $connectedUser['name'];
     $myRole = $connectedUser['role'];
 }
 $stmtUser->close();

 $studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

 // ======================================================================
 // FONCTIONS UTILITAIRES
 // ======================================================================

 /** * Charge les données de l'élève
  */
 function load_student_data($conn, $studentId) {
     $stmt = $conn->prepare("
         SELECT s.id, s.first_name, s.last_name, s.class_id,
                c.level, c.name AS class_letter, c.year AS class_year
         FROM students s
         LEFT JOIN classes c ON s.class_id = c.id
         WHERE s.id = ?
     ");
     $stmt->bind_param('i', $studentId);
     $stmt->execute();
     $student = $stmt->get_result()->fetch_assoc();
     $stmt->close();

     return $student;
 }

 /** * Charge les préférences de l'élève
  */
 function load_preferences($conn, $studentId, $grade, $classId) {
     $stmt = $conn->prepare("SELECT * FROM preferences WHERE student_id = ? AND grade = ? AND term = 1");
     $stmt->bind_param('is', $studentId, $grade);
     $stmt->execute();
     $pref = $stmt->get_result()->fetch_assoc();
     $stmt->close();

     if (!$pref) {
         $conn->begin_transaction();
         try {
             $ins = $conn->prepare("INSERT INTO preferences (student_id, class_id, grade, term) VALUES (?, ?, ?, 1)");
             $ins->bind_param('iis', $studentId, $classId, $grade);
             $ins->execute();
             $newId = $ins->insert_id;
             $ins->close();

             // Copie des données de Seconde pour Première
             if ($grade === 'Première') {
                 copy_second_to_first($conn, $studentId, $newId);
             }

             $conn->commit();

             // Rechargement des données
             $stmt = $conn->prepare("SELECT * FROM preferences WHERE id = ?");
             $stmt->bind_param('i', $newId);
             $stmt->execute();
             $pref = $stmt->get_result()->fetch_assoc();
             $stmt->close();
         } catch (Exception $e) {
             $conn->rollback();
             die("Erreur création préférences: " . $e->getMessage());
         }
     }

     return $pref;
 }

 /** * Copie les données de Seconde pour un élève entrant en Première
  */
 function copy_second_to_first($conn, $studentId, $newId) {
     $stmt = $conn->prepare("SELECT id, specialties_t3, options FROM preferences WHERE student_id = ? AND grade = 'Seconde' AND term = 1");
     $stmt->bind_param('i', $studentId);
     $stmt->execute();
     $secondePref = $stmt->get_result()->fetch_assoc();
     $stmt->close();

     if ($secondePref) {
         $initialSpe = $secondePref['specialties_t3'] ?: '';
         $upd = $conn->prepare("UPDATE preferences SET specialties = ?, options = ? WHERE id = ?");
         $upd->bind_param('ssi', $initialSpe, $secondePref['options'], $newId);
         $upd->execute();
         $upd->close();

         // Copie des avis
         $stmt = $conn->prepare("SELECT trimester, specialty, status FROM opinions WHERE preference_id = ?");
         $stmt->bind_param('i', $secondePref['id']);
         $stmt->execute();
         $res = $stmt->get_result();

         while ($row = $res->fetch_assoc()) {
             $insO = $conn->prepare("INSERT INTO opinions (preference_id, trimester, specialty, status) VALUES (?, ?, ?, ?)");
             $insO->bind_param('iiss', $newId, $row['trimester'], $row['specialty'], $row['status']);
             $insO->execute();
             $insO->close();
         }
         $res->free();
         $stmt->close();
     }
 }

 /** * Charge les données d'archives (années précédentes)
  */
 function load_archives($conn, $studentId, $grade) {
     $archives = [];
     if (!in_array($grade, ['Première', 'Terminale'], true)) return $archives;

     $prevGrades = ($grade === 'Première') ? ['Seconde'] : ['Première', 'Seconde'];

     foreach ($prevGrades as $pg) {
         $stmt = $conn->prepare("SELECT id, specialties, specialties_t2, specialties_t3, drop_specialty, abandoned_specialty, options
                                 FROM preferences WHERE student_id = ? AND grade = ? AND term = 1");
         $stmt->bind_param('is', $studentId, $pg);
         $stmt->execute();
         $archiveData = $stmt->get_result()->fetch_assoc();
         $stmt->close();

         if ($archiveData) {
             // Chargement des avis et mentions
             [$op, $men] = load_opinions_mentions($conn, $archiveData['id']);

             $archives[$pg] = [
                 'specialties'    => $archiveData['specialties'] ? explode('||', $archiveData['specialties']) : [],
                 'specialties_t2' => $archiveData['specialties_t2'] ? explode('||', $archiveData['specialties_t2']) : [],
                 'specialties_t3' => $archiveData['specialties_t3'] ? explode('||', $archiveData['specialties_t3']) : [],
                 'drop'           => $archiveData['drop_specialty'],
                 'abandoned'      => $archiveData['abandoned_specialty'],
                 'options'        => $archiveData['options'] ? explode('||', $archiveData['options']) : [],
                 'opinions'       => $op,
                 'mentions'       => $men,
             ];
         }
     }
     return $archives;
 }

 /** * Charge les avis et mentions pour une préférence donnée
  */
 function load_opinions_mentions($conn, $prefId) {
     // Chargement des avis
     $opinions = [];
     $stmt = $conn->prepare("SELECT trimester, specialty, status FROM opinions WHERE preference_id = ?");
     $stmt->bind_param('i', $prefId);
     $stmt->execute();
     $res = $stmt->get_result();
     while ($row = $res->fetch_assoc()) {
         $opinions[$row['trimester']][$row['specialty']] = $row['status'];
     }
     $res->free();
     $stmt->close();

     // Chargement des mentions
     $mentions = [];
     $stmt = $conn->prepare("SELECT trimester, mention FROM mentions WHERE preference_id = ?");
     $stmt->bind_param('i', $prefId);
     $stmt->execute();
     $res = $stmt->get_result();
     while ($row = $res->fetch_assoc()) {
         $mentions[$row['trimester']] = $row['mention'];
     }
     $res->free();
     $stmt->close();

     return [$opinions, $mentions];
 }

 /** * Gère la mise à jour des spécialités (Seconde)
  */
 function handle_spe_update($conn, &$pref) {
     $spe1 = array_values(array_filter($_POST['spe'] ?? []));
     $spe2 = array_values(array_filter($_POST['spe_t2'] ?? []));
     $spe3 = array_values(array_filter($_POST['spe_t3'] ?? []));

     // Validation
     if (count($spe1) !== 3 || count(array_unique($spe1)) !== 3) {
         return ['Veuillez choisir 3 spécialités distinctes pour le T1.', ''];
     }
     if ($spe2 && (count($spe2) !== 3 || count(array_unique($spe2)) !== 3)) {
         return ['Veuillez choisir 3 spécialités distinctes pour le T2.', ''];
     }
     if ($spe3 && (count($spe3) !== 3 || count(array_unique($spe3)) !== 3)) {
         return ['Veuillez choisir 3 spécialités distinctes pour le T3.', ''];
     }

     // Mise à jour
     $str1 = implode('||', $spe1);
     $str2 = $spe2 ? implode('||', $spe2) : null;
     $str3 = $spe3 ? implode('||', $spe3) : null;

     $u = $conn->prepare("UPDATE preferences SET specialties = ?, specialties_t2 = ?, specialties_t3 = ? WHERE id = ?");
     $u->bind_param('sssi', $str1, $str2, $str3, $pref['id']);
     $u->execute();
     $u->close();

     return ['', 'Spécialités mises à jour avec succès.'];
 }

 /** * Gère la sauvegarde des mentions
  */

 function handle_mentions_save($conn, &$pref) {
     // Suppression des anciennes mentions
     $d = $conn->prepare("DELETE FROM mentions WHERE preference_id = ?");
     $d->bind_param('i', $pref['id']);
     $d->execute();
     $d->close();

     // Insertion des nouvelles mentions
     $iM = $conn->prepare("INSERT INTO mentions (preference_id, trimester, mention) VALUES (?, ?, ?)");
     foreach ([1, 2, 3] as $t) {
         $m = $_POST['mention'][$t] ?? '';
         if ($m) {
             $iM->bind_param('iis', $pref['id'], $t, $m);
             $iM->execute();
         }
     }
     $iM->close();

     return ['', 'Mentions enregistrées avec succès.'];
 }

 /** * Gère la sauvegarde des avis (CORRECTION PRINCIPALE)
  */
 function handle_opinions_save($conn, $pref) {
     // 1. Déterminer les trimestres avec données dans le formulaire
     $trimestersInForm = [];
     for ($t = 1; $t <= 3; $t++) {
         if (!empty($_POST['opinion'][$t])) {
             $trimestersInForm[] = $t;
         }
     }

     // 2. Supprimer uniquement les avis des trimestres présents dans le formulaire
     if (!empty($trimestersInForm)) {
         $placeholders = implode(',', array_fill(0, count($trimestersInForm), '?'));
         $sql = "DELETE FROM opinions WHERE preference_id = ? AND trimester IN ($placeholders)";
         $d = $conn->prepare($sql);

         $params = array_merge([$pref['id']], $trimestersInForm);
         $types = str_repeat('i', count($params));
         $d->bind_param($types, ...$params);
         $d->execute();
         $d->close();
     }

     // 3. Insérer les nouveaux avis
     $inserted = false;
     $iO = $conn->prepare("INSERT INTO opinions (preference_id, trimester, specialty, status) VALUES (?, ?, ?, ?)");

     foreach ($trimestersInForm as $t) {
         foreach ($_POST['opinion'][$t] as $sp => $st) {
             if (!empty($st)) {
                 $sp = htmlspecialchars_decode($sp, ENT_QUOTES);
                 $iO->bind_param('iiss', $pref['id'], $t, $sp, $st);
                 $iO->execute();
                 $inserted = true;
             }
         }
     }
     $iO->close();

    return ['', 'Avis enregistrés avec succès.'];
}

 /** * Gère la mise à jour des options
  */
 function handle_options_update($conn, &$pref) {
     $opts = array_filter($_POST['options'] ?? []);
     $os = $opts ? implode('||', $opts) : null;
     $u = $conn->prepare("UPDATE preferences SET options = ? WHERE id = ?");
     $u->bind_param('si', $os, $pref['id']);
     $u->execute();
     $u->close();
     return ['', 'Options mises à jour avec succès.'];
 }

 /** * Gère la mise à jour du statut de redoublement
  */
 function handle_repeated_update($conn, &$pref) {
     $r = isset($_POST['repeated']) ? 1 : 0;
     $u = $conn->prepare("UPDATE preferences SET repeated = ? WHERE id = ?");
     $u->bind_param('ii', $r, $pref['id']);
     $u->execute();
     $u->close();
     return ['', 'Statut de redoublement mis à jour.'];
 }

 /** * Gère l'abandon de spécialité en Première
  */
 function handle_drop_specialty($conn, &$pref, $speArr) {
     $drop = $_POST['drop_specialty'] ?? '';
     if (!in_array($drop, $speArr, true)) {
         return ['Spécialité invalide.', ''];
     }

     $u = $conn->prepare("UPDATE preferences SET drop_specialty = ? WHERE id = ?");
     $u->bind_param('si', $drop, $pref['id']);
     $u->execute();
     $u->close();
     return ['', 'Spécialité abandonnée avec succès.'];
 }

 /** * Gère toutes les soumissions POST
  */
 function handle_post_requests($conn, $grade, &$pref, $studentId, $classId) {
     $error = $success = '';

     if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
         return [$error, $success];
     }

     // Préparation des données pour validation
     [$speArr, $speArrT2, $speArrT3, $optArr, $drop, $abandoned, $repeated] = prepare_display_data($pref);

     try {
         $conn->begin_transaction();

         // Mise à jour des spécialités (Seconde)
         if (isset($_POST['update_spe']) && $grade === 'Seconde') {
             [$error, $success] = handle_spe_update($conn, $pref);
             if ($error) throw new Exception($error);
         }

         // Mise à jour des options
         elseif (isset($_POST['update_options'])) {
             [$error, $success] = handle_options_update($conn, $pref);
         }

         // Enregistrement des avis (Seconde)
         elseif (isset($_POST['save_opinions']) && $grade === 'Seconde') {
             [$error, $success] = handle_opinions_save($conn, $pref);
             if ($error) throw new Exception($error);
         }

         // Enregistrement des mentions
         elseif (isset($_POST['save_mentions'])) {
             [$error, $success] = handle_mentions_save($conn, $pref);
         }

         // Mise à jour du statut de redoublement
         elseif (isset($_POST['set_repeated'])) {
             [$error, $success] = handle_repeated_update($conn, $pref);
         }

         // Abandon de spécialité (Première)
         elseif (isset($_POST['set_drop_specialty']) && $grade === 'Première') {
             [$error, $success] = handle_drop_specialty($conn, $pref, $speArr);
             if ($error) throw new Exception($error);
         }

         $conn->commit();
     } catch (Exception $e) {
         $conn->rollback();
         $error = "Erreur traitement: " . $e->getMessage();
     }

     return [$error, $success];
 }

 /** * Prépare les données pour l'affichage
  */
 function prepare_display_data($pref) {
     return [
         $pref['specialties'] ? explode('||', $pref['specialties']) : [],
         $pref['specialties_t2'] ? explode('||', $pref['specialties_t2']) : [],
         $pref['specialties_t3'] ? explode('||', $pref['specialties_t3']) : [],
         $pref['options'] ? explode('||', $pref['options']) : [],
         $pref['drop_specialty'],
         $pref['abandoned_specialty'],
         (bool)($pref['repeated'] ?? false)
     ];
 }

 /** * Liste des spécialités disponibles
  */
 function get_specialties_list() {
     return [
         'Histoire-Géographie, géopolitique et sciences politiques',
         'Humanités, littérature et philosophie',
         'Mathématiques',
         'Physique-Chimie',
         'Sciences de la Vie et de la Terre',
         'Sciences économiques et sociales',
         'Langues, littérature et cultures étrangères',
         'Arts Plastiques'
     ];
 }

 /** * Liste des options disponibles
  */
 function get_options_list() {
     return ['Latin', 'Maths complémentaires', 'Maths expertes', 'DNL', 'DGEMC'];
 }

 /** * Abréviations pour l'affichage
  */
 function get_abbreviations() {
     $abreviations = [
         'Histoire-Géographie, géopolitique et sciences politiques' => 'HGGSP',
         'Humanités, littérature et philosophie' => 'HLP',
         'Mathématiques' => 'Maths',
         'Physique-Chimie' => 'PC',
         'Sciences de la Vie et de la Terre' => 'SVT',
         'Sciences économiques et sociales' => 'SES',
         'Langues, littérature et cultures étrangères' => 'LLCE',
         'Arts Plastiques' => 'Arts'
     ];

     $mentionAbbr = [
         'Encouragement' => 'Enc.',
         'Compliments'   => 'Comp.',
         'Félicitations' => 'Fel.'
     ];

     return [$abreviations, $mentionAbbr];
 }

 /** * Recharge les préférences après mise à jour
  */
 function reload_preferences($conn, $prefId) {
     $stmt = $conn->prepare("SELECT * FROM preferences WHERE id = ?");
     $stmt->bind_param('i', $prefId);
     $stmt->execute();
     $pref = $stmt->get_result()->fetch_assoc();
     $stmt->close();
     return $pref;
 }

 // ======================================================================
 // EXÉCUTION PRINCIPALE
 // ======================================================================

 // Chargement des données de l'élève
 $stud = load_student_data($conn, $studentId);
 if (!$stud) die('Élève introuvable.');

 $classId = (int)$stud['class_id'];
 $grade = $stud['level'];

 // Chargement des préférences
 $pref = load_preferences($conn, $studentId, $grade, $classId);

 // Chargement des archives
 $archives = load_archives($conn, $studentId, $grade);

 // Chargement des avis et mentions
 [$opinions, $mentions] = load_opinions_mentions($conn, $pref['id']);

 // Traitement des formulaires
 [$error, $success] = handle_post_requests($conn, $grade, $pref, $studentId, $classId);

 // Préparation des données pour la vue
 [$speArr, $speArrT2, $speArrT3, $optArr, $drop, $abandoned, $repeated] = prepare_display_data($pref);
 $allSpe = get_specialties_list();
 $allOptions = get_options_list();
 [$abreviations, $mentionAbbr] = get_abbreviations();

 // Rechargement après modification
if ($success && !$error) {
    $pref = reload_preferences($conn, $pref['id']);
    [$opinions, $mentions] = load_opinions_mentions($conn, $pref['id']);
    [$speArr, $speArrT2, $speArrT3, $optArr, $drop, $abandoned, $repeated] = prepare_display_data($pref);
}


// Inclusion de l'en-tête
include 'header.php';
 ?>
 <!DOCTYPE html>
 <html lang="fr">
 <head>
     <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1">
     <title>Profil Élève - Lycée Saint Elme</title>
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
     <style>
         :root {
             --primary: #2c3e50; --secondary: #34495e; --accent: #3498db;
             --light: #ecf0f1; --success: #27ae60; --warning: #f39c12; --danger: #e74c3c;
         }
         body.student-profile {
             font-family: 'Segoe UI', system-ui, sans-serif;
             background-color: #f8f9fa;
             min-height: 100vh;
         }
         .header-card {
             background: linear-gradient(120deg, var(--primary), var(--secondary));
             color: white;
             border-radius: 10px;
             box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
             margin-bottom: 1.5rem;
         }
         .info-badge {
             background: rgba(255, 255, 255, 0.15);
             color: white;
             font-weight: 500;
             padding: 0.3rem 0.8rem;
             border-radius: 20px;
             font-size: 0.85rem;
             display: inline-flex;
             align-items: center;
             gap: 0.3rem;
             margin-right: 0.5rem;
             margin-bottom: 0.3rem;
         }
         .section-card {
             border: none;
             border-radius: 10px;
             box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
             margin-bottom: 1.5rem;
             overflow: hidden;
             background: white;
         }
         .section-header {
             background-color: #f8f9fa;
             border-bottom: 1px solid #e9ecef;
             padding: 0.8rem 1rem;
             font-weight: 600;
             color: var(--primary);
             display: flex;
             justify-content: space-between;
             align-items: center;
         }
         .section-body { padding: 1rem; }
         .tag {
             display: inline-block;
             padding: 0.3rem 0.8rem;
             border-radius: 20px;
             font-size: 0.85rem;
             font-weight: 500;
             margin: 0.15rem;
         }
         .tag-primary {
             background-color: rgba(52, 152, 219, 0.1);
             color: var(--accent);
             border: 1px solid rgba(52, 152, 219, 0.2);
         }
         .tag-secondary {
             background-color: rgba(108, 117, 125, 0.08);
             color: #6c757d;
             border: 1px solid rgba(108, 117, 125, 0.15);
         }
         .tag-danger {
             background-color: rgba(231, 76, 60, 0.1);
             color: var(--danger);
             border: 1px solid rgba(231, 76, 60, 0.2);
         }
         .grid-container {
             display: grid;
             grid-template-columns: 1fr;
             gap: 1.5rem;
         }
         @media (min-width: 992px) {
             .grid-container {
                 grid-template-columns: 1fr 1fr;
             }
         }
         .mentions-grid {
             display: grid;
             grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
             gap: 0.8rem;
             margin-bottom: 1rem;
         }
         .mention-card {
             background: white;
             border-radius: 8px;
             padding: 0.8rem;
             border: 1px solid #e9ecef;
         }
         .accordion-toggle {
             display: flex;
             justify-content: space-between;
             align-items: center;
             cursor: pointer;
             padding: 0.6rem 0;
             border-bottom: 1px solid #eee;
         }
         .abbreviation {
             font-weight: 600;
             background: #f8f9fa;
             padding: 0.2rem 0.5rem;
             border-radius: 4px;
             display: inline-block;
             margin-right: 0.5rem;
             min-width: 50px;
             text-align: center;
             border: 1px solid #dee2e6;
         }
         .trimester-tabs {
             display: flex;
             gap: 5px;
             margin-bottom: 15px;
             flex-wrap: wrap;
         }
         .trimester-tab {
             padding: 5px 10px;
             border-radius: 5px;
             background: #e9ecef;
             cursor: pointer;
             font-size: 0.85rem;
             margin-bottom: 0.3rem;
         }
         .trimester-tab.active {
             background: var(--accent);
             color: white;
         }
         .specialty-status {
             display: flex;
             align-items: center;
             margin-bottom: 8px;
         }
         .status-label {
             min-width: 100px;
             font-weight: 500;
         }
         .status-badge {
             padding: 3px 8px;
             border-radius: 4px;
             font-size: 0.8rem;
             font-weight: 500;
         }
         .status-favorable { background-color: rgba(39, 174, 96, 0.15); color: #27ae60; }
         .status-reserve { background-color: rgba(243, 156, 18, 0.15); color: #f39c12; }
        .status-defavorable { background-color: rgba(231, 76, 60, 0.15); color: #e74c3c; }
    </style>
 </head>
 <body class="student-profile">
     <div class="container py-3">
         <div class="header-card mb-4">
             <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3">
                 <div>
                     <h2 class="h4 fw-bold mb-1"><?= htmlspecialchars("{$stud['first_name']} {$stud['last_name']}") ?></h2>
                     <div class="d-flex align-items-center flex-wrap gap-2">
                         <span class="info-badge"><i class="bi bi-building"></i> <?= $classId ? htmlspecialchars("{$stud['level']} {$stud['class_letter']}") : 'Sans classe' ?></span>
                         <span class="info-badge"><i class="bi bi-calendar"></i> <?= htmlspecialchars($stud['class_year']) ?></span>
                         <?php if ($repeated) : ?><span class="info-badge"><i class="bi bi-arrow-repeat"></i> Redoublant</span><?php endif; ?>
                     </div>
                 </div>
                 <a href="list_students.php" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Retour</a>
             </div>
         </div>

         <?php if (!empty($error)) : ?>
             <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
         <?php endif; ?>
         <?php if (!empty($success)) : ?>
             <div class="alert alert-success mb-4"><?= htmlspecialchars($success) ?></div>
         <?php endif; ?>

         <div class="grid-container">
             <div>
                 <div class="section-card">
                     <div class="section-header">
                         <?php if ($grade === 'Seconde') : ?>
                             <span>Vœux de spécialités - <?= htmlspecialchars($grade) ?></span>
                             <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSpeModal"><i class="bi bi-pencil"></i> Modifier</button>
                         <?php else : ?>
                             <span>Spécialités suivies - <?= htmlspecialchars($grade) ?></span>
                         <?php endif; ?>
                     </div>
                     <div class="section-body">
                         <?php if ($grade === 'Seconde') : ?>
                             <p class="text-muted small mb-3">Vœux de spécialités pour la Première, affinés à chaque trimestre.</p>
                             <div class="trimester-tabs">
                                 <div class="trimester-tab active" data-tab="t1">T1</div>
                                 <div class="trimester-tab" data-tab="t2">T2</div>
                                 <div class="trimester-tab" data-tab="t3">T3</div>
                             </div>
                             <div class="trimester-content" id="t1-content">
                                 <div class="d-flex flex-wrap mb-2">
                                     <?php foreach ($speArr as $sp) : ?>
                                         <span class="tag tag-primary"><?= htmlspecialchars($sp) ?></span>
                                     <?php endforeach; ?>
                                 </div>
                             </div>
                             <div class="trimester-content" id="t2-content" style="display:none;">
                                 <div class="d-flex flex-wrap mb-2">
                                     <?php if (!empty($speArrT2)) : ?>
                                         <?php foreach ($speArrT2 as $sp) : ?>
                                             <span class="tag tag-primary"><?= htmlspecialchars($sp) ?></span>
                                         <?php endforeach; ?>
                                     <?php else : ?>
                                         <span class="text-muted small">Aucun vœu défini pour ce trimestre</span>
                                     <?php endif; ?>
                                 </div>
                             </div>
                             <div class="trimester-content" id="t3-content" style="display:none;">
                                 <div class="d-flex flex-wrap mb-2">
                                     <?php if (!empty($speArrT3)) : ?>
                                         <?php foreach ($speArrT3 as $sp) : ?>
                                             <span class="tag tag-primary"><?= htmlspecialchars($sp) ?></span>
                                         <?php endforeach; ?>
                                     <?php else : ?>
                                         <span class="text-muted small">Aucun vœu défini pour ce trimestre</span>
                                     <?php endif; ?>
                                 </div>
                             </div>
                         <?php elseif ($grade === 'Première') : ?>
                             <p class="text-muted small mb-3">L'élève doit abandonner une de ses trois spécialités pour la Terminale.</p>
                             <div class="d-flex flex-wrap mb-3">
                                 <?php if ($speArr) : ?>
                                     <?php foreach ($speArr as $sp) : ?>
                                         <span class="tag <?= $sp === $drop ? 'tag-danger' : 'tag-primary' ?>">
                                             <?= htmlspecialchars($sp) ?><?= $sp === $drop ? ' (abandonnée)' : '' ?>
                                         </span>
                                     <?php endforeach; ?>
                                 <?php else : ?>
                                     <span class="text-muted">Aucune spécialité enregistrée.</span>
                                 <?php endif; ?>
                             </div>
                             <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#dropSpecialtyModal"><i class="bi bi-trash"></i> Choisir la spé à abandonner</button>
                         <?php else : // Terminale ?>
                             <p class="text-muted small mb-3">Affiche les deux spécialités conservées en Terminale.</p>
                             <div class="d-flex flex-wrap mb-2">
                                 <?php
                                 $kept = $archives['Première']['specialties'] ?? [];
                                 $dropPrev = $archives['Première']['drop'] ?? '';
                                 $displaySpe = array_diff($kept, [$dropPrev]);
                                 foreach ($displaySpe as $sp) : ?>
                                     <span class="tag tag-primary"><?= htmlspecialchars($sp) ?></span>
                                 <?php endforeach;
                                 if ($dropPrev) : ?>
                                     <span class="tag tag-danger"><?= htmlspecialchars($dropPrev) ?> (abandonnée)</span>
                                 <?php endif; ?>
                             </div>
                         <?php endif; ?>
                     </div>
                 </div>

                 <div class="section-card">
                     <div class="section-header">
                         <span>Options</span>
                         <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editOptionsModal"><i class="bi bi-pencil"></i> Modifier</button>
                     </div>
                     <div class="section-body">
                         <div class="d-flex flex-wrap gap-1 mb-2">
                             <?php if ($optArr) : ?>
                                 <?php foreach ($optArr as $op) : ?>
                                     <span class="tag tag-secondary"><?= htmlspecialchars($op) ?></span>
                                 <?php endforeach; ?>
                             <?php else : ?>
                                 <span class="text-muted">Aucune option</span>
                             <?php endif; ?>
                         </div>
                     </div>
                 </div>

                 <div class="section-card">
                     <div class="section-header"><span>Mentions</span></div>
                     <div class="section-body">
                         <form method="post">
                             <div class="mentions-grid">
                                 <?php for ($t = 1; $t <= 3; $t++) : ?>
                                     <div class="mention-card">
                                         <div class="fw-bold small mb-2">Trimestre <?= $t ?></div>
                                         <div class="form-check mb-1">
                                             <input class="form-check-input" type="radio" name="mention[<?= $t ?>]" id="men-<?= $t ?>-enc" value="Encouragement" <?= ($mentions[$t] ?? '') === 'Encouragement' ? 'checked' : '' ?>>
                                             <label class="form-check-label" for="men-<?= $t ?>-enc">Encouragement</label>
                                         </div>
                                         <div class="form-check mb-1">
                                             <input class="form-check-input" type="radio" name="mention[<?= $t ?>]" id="men-<?= $t ?>-comp" value="Compliments" <?= ($mentions[$t] ?? '') === 'Compliments' ? 'checked' : '' ?>>
                                             <label class="form-check-label" for="men-<?= $t ?>-comp">Compliments</label>
                                         </div>
                                         <div class="form-check mb-1">
                                             <input class="form-check-input" type="radio" name="mention[<?= $t ?>]" id="men-<?= $t ?>-fel" value="Félicitations" <?= ($mentions[$t] ?? '') === 'Félicitations' ? 'checked' : '' ?>>
                                             <label class="form-check-label" for="men-<?= $t ?>-fel">Félicitations</label>
                                         </div>
                                         <div class="form-check">
                                             <input class="form-check-input" type="radio" name="mention[<?= $t ?>]" id="men-<?= $t ?>-none" value="" <?= empty($mentions[$t]) ? 'checked' : '' ?>>
                                             <label class="form-check-label" for="men-<?= $t ?>-none">Aucune</label>
                                         </div>
                                     </div>
                                 <?php endfor; ?>
                             </div>
                            <button type="submit" name="save_mentions" class="btn btn-info mt-3"><i class="bi bi-save"></i> Enregistrer</button>
                        </form>
                    </div>
                </div>

            </div>

            <div>
                <?php if ($grade === 'Seconde') : ?>
                    <div class="section-card">
                         <div class="section-header"><span>Conseil de classe – Avis sur les vœux</span></div>
                         <div class="section-body">
                             <form method="post">
                                 <?php for ($t = 1; $t <= 3; $t++) : ?>
                                     <div class="mb-4">
                                         <h6 class="fw-bold border-bottom pb-2">Trimestre <?= $t ?></h6>
                                         <div class="row g-2">
                                             <?php
                                             $field = $t > 1 ? "_t$t" : "";
                                             $list = $pref["specialties{$field}"] ? explode('||', $pref["specialties{$field}"]) : [];

                                             if (empty($list) && $t > 1) : ?>
                                                 <p class="text-muted col-12 small">Veuillez d'abord définir les vœux pour ce trimestre.</p>
                                             <?php else :
                                                 foreach ($list as $sp) :
                                                     $abbr = $abreviations[$sp] ?? substr($sp, 0, 4);
                                                     $currentStatus = $opinions[$t][$sp] ?? '';
                                             ?>
                                                     <div class="col-md-6 mb-2">
                                                         <div class="specialty-status">
                                                             <span class="abbreviation me-2" title="<?= htmlspecialchars($sp) ?>"><?= $abbr ?></span>
                                                             <div class="btn-group btn-group-sm flex-grow-1">
                                                                 <input type="radio" class="btn-check" name="opinion[<?= $t ?>][<?= htmlspecialchars($sp, ENT_QUOTES) ?>]" id="favorable-<?= $t ?>-<?= md5($sp) ?>" value="Favorable" autocomplete="off" <?= $currentStatus === 'Favorable' ? 'checked' : '' ?>>
                                                                 <label class="btn btn-outline-success flex-grow-1" for="favorable-<?= $t ?>-<?= md5($sp) ?>">F</label>

                                                                 <input type="radio" class="btn-check" name="opinion[<?= $t ?>][<?= htmlspecialchars($sp, ENT_QUOTES) ?>]" id="reserve-<?= $t ?>-<?= md5($sp) ?>" value="Réserve" autocomplete="off" <?= $currentStatus === 'Réserve' ? 'checked' : '' ?>>
                                                                 <label class="btn btn-outline-warning flex-grow-1" for="reserve-<?= $t ?>-<?= md5($sp) ?>">R</label>

                                                                 <input type="radio" class="btn-check" name="opinion[<?= $t ?>][<?= htmlspecialchars($sp, ENT_QUOTES) ?>]" id="defavorable-<?= $t ?>-<?= md5($sp) ?>" value="Défavorable" autocomplete="off" <?= $currentStatus === 'Défavorable' ? 'checked' : '' ?>>
                                                                 <label class="btn btn-outline-danger flex-grow-1" for="defavorable-<?= $t ?>-<?= md5($sp) ?>">D</label>
                                                             </div>
                                                         </div>
                                                     </div>
                                             <?php endforeach; endif; ?>
                                         </div>
                                     </div>
                                 <?php endfor; ?>
                                 <button type="submit" name="save_opinions" class="btn btn-info"><i class="bi bi-save"></i> Enregistrer les avis</button>
                             </form>
                         </div>
                     </div>
                 <?php endif; ?>

                 <?php if (in_array($grade, ['Seconde', 'Première'], true)) : ?>
                     <div class="section-card">
                         <div class="section-header"><span>Statut scolaire</span></div>
                         <div class="section-body">
                             <form method="post" class="d-flex justify-content-between align-items-center">
                                 <div class="form-check form-switch">
                                     <input class="form-check-input" type="checkbox" id="chkRep" name="repeated" value="1" <?= $repeated ? 'checked' : '' ?>>
                                     <label class="form-check-label" for="chkRep">Élève redoublant</label>
                                 </div>
                                 <button type="submit" name="set_repeated" class="btn btn-outline-secondary"><i class="bi bi-save"></i> Mettre à jour</button>
                             </form>
                         </div>
                     </div>
                 <?php endif; ?>

                 <?php if ($archives) : ?>
                     <div class="section-card">
                         <div class="section-header"><span>Historique académique</span></div>
                         <div class="section-body">
                             <?php foreach ($archives as $ag => $data) : ?>
                             <button
                             class="accordion-toggle btn btn-link w-100 text-start d-flex justify-content-between align-items-center p-0"
                             type="button"
                             data-bs-toggle="collapse"
                             data-bs-target="#arch-<?= md5($ag) ?>"
                             aria-expanded="false"
                             aria-controls="arch-<?= md5($ag) ?>"
 >
                             <span class="fw-bold"><?= htmlspecialchars($ag) ?></span>
                             <i class="bi bi-chevron-down"></i>
                             </button>
                                 <div id="arch-<?= md5($ag) ?>" class="collapse accordion-content">
                                     <div class="py-2">
                                         <h6 class="fw-bold mb-2">Spécialités</h6>
                                         <div class="d-flex flex-wrap gap-1 mb-3">
                                             <?php
                                             foreach ($data['specialties'] as $sp) :
                                                 $isAbandoned = ($sp === $data['drop']);
                                             ?>
                                                 <span class="tag <?= $isAbandoned ? 'tag-danger' : 'tag-primary' ?>">
                                                     <?= htmlspecialchars($sp) ?><?= $isAbandoned ? ' (abandonnée)' : '' ?>
                                                 </span>
                                             <?php endforeach; ?>
                                         </div>

                                         <?php if (!empty($data['options'])) : ?>
                                             <h6 class="fw-bold mb-2">Options</h6>
                                             <div class="d-flex flex-wrap gap-1 mb-3">
                                                 <?php foreach ($data['options'] as $op) : ?>
                                                     <span class="tag tag-secondary"><?= htmlspecialchars($op) ?></span>
                                                 <?php endforeach; ?>
                                             </div>
                                         <?php endif; ?>

                                         <h6 class="fw-bold mb-2">Mentions &amp; Avis</h6>
                                         <div class="row">
                                             <?php for ($t = 1; $t <= 3; $t++) : ?>
                                                 <div class="col-md-4 mb-3">
                                                     <div class="fw-bold small">Trimestre <?= $t ?></div>
                                                     <?php if (isset($data['mentions'][$t])) : ?>
                                                         <div class="text-success small mb-1">
                                                             <i class="bi bi-award"></i> <?= htmlspecialchars($mentionAbbr[$data['mentions'][$t]] ?? $data['mentions'][$t]) ?>
                                                         </div>
                                                     <?php endif; ?>
                                                     <?php if (!empty($data['opinions'][$t])) : ?>
                                                         <?php foreach ($data['opinions'][$t] as $sp => $status) : ?>
                                                             <?php
                                                             $statusClass = [
                                                                 'Favorable' => 'status-favorable',
                                                                 'Réserve' => 'status-reserve',
                                                                 'Défavorable' => 'status-defavorable'
                                                             ][$status] ?? '';
                                                             ?>
                                                             <div class="d-flex align-items-center mb-1">
                                                                 <span class="me-2 small"><?= $abreviations[$sp] ?? substr($sp, 0, 4) ?></span>
                                                                 <span class="status-badge <?= $statusClass ?>"><?= substr($status, 0, 1) ?></span>
                                                             </div>
                                                         <?php endforeach; ?>
                                                     <?php endif; ?>
                                                 </div>
                                             <?php endfor; ?>
                                         </div>
                                     </div>
                                 </div>
                             <?php endforeach; ?>
                         </div>
                     </div>
                 <?php endif; ?>
                 <?php include 'comment.php'; ?>
             </div>
         </div>
     </div>

     <?php if ($grade === 'Seconde') : ?>
         <div class="modal fade" id="editSpeModal" tabindex="-1">
             <div class="modal-dialog">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title">Modifier les vœux de spécialités</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                     </div>
                     <form method="post">
                         <div class="modal-body">
                             <ul class="nav nav-tabs mb-3" id="speTabs">
                                 <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t1-modal">T1</button></li>
                                 <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t2-modal">T2</button></li>
                                 <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t3-modal">T3</button></li>
                             </ul>
                             <div class="tab-content">
                                 <div class="tab-pane fade show active" id="t1-modal">
                                     <?php for ($i = 0; $i < 3; $i++) : ?>
                                         <select name="spe[<?= $i ?>]" class="form-select mb-3" required>
                                             <option value="">— Vœu <?= $i + 1 ?> —</option>
                                             <?php foreach ($allSpe as $opt) : ?>
                                                 <option value="<?= htmlspecialchars($opt) ?>" <?= ($speArr[$i] ?? '') === $opt ? 'selected' : ''; ?>>
                                                     <?= htmlspecialchars($opt) ?>
                                                 </option>
                                             <?php endforeach; ?>
                                         </select>
                                     <?php endfor; ?>
                                 </div>
                                 <div class="tab-pane fade" id="t2-modal">
                                     <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="copyT1toT2">Copier les vœux du T1</button>
                                     <?php for ($i = 0; $i < 3; $i++) : ?>
                                         <select name="spe_t2[<?= $i ?>]" class="form-select mb-3">
                                             <option value="">— Vœu <?= $i + 1 ?> —</option>
                                             <?php foreach ($allSpe as $opt) : ?>
                                                 <option value="<?= htmlspecialchars($opt) ?>" <?= ($speArrT2[$i] ?? '') === $opt ? 'selected' : ''; ?>>
                                                     <?= htmlspecialchars($opt) ?>
                                                 </option>
                                             <?php endforeach; ?>
                                         </select>
                                     <?php endfor; ?>
                                 </div>
                                 <div class="tab-pane fade" id="t3-modal">
                                     <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="copyT2toT3">Copier les vœux du T2</button>
                                     <?php for ($i = 0; $i < 3; $i++) : ?>
                                         <select name="spe_t3[<?= $i ?>]" class="form-select mb-3">
                                             <option value="">— Vœu <?= $i + 1 ?> —</option>
                                             <?php foreach ($allSpe as $opt) : ?>
                                                 <option value="<?= htmlspecialchars($opt) ?>" <?= ($speArrT3[$i] ?? '') === $opt ? 'selected' : ''; ?>>
                                                     <?= htmlspecialchars($opt) ?>
                                                 </option>
                                             <?php endforeach; ?>
                                         </select>
                                     <?php endfor; ?>
                                 </div>
                             </div>
                         </div>
                         <div class="modal-footer">
                             <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                             <button type="submit" name="update_spe" class="btn btn-primary">Enregistrer</button>
                         </div>
                     </form>
                 </div>
             </div>
         </div>
     <?php endif; ?>

     <?php if ($grade === 'Première') : ?>
         <div class="modal fade" id="dropSpecialtyModal" tabindex="-1">
             <div class="modal-dialog">
                 <div class="modal-content">
                     <div class="modal-header">
                         <h5 class="modal-title">Abandonner une spécialité</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                     </div>
                     <form method="post">
                         <div class="modal-body">
                             <p>Sélectionnez la spécialité à abandonner pour la classe de Terminale.</p>
                             <select name="drop_specialty" class="form-select" required>
                                 <option value="">— Sélectionner une spécialité —</option>
                                 <?php foreach ($speArr as $sp) : ?>
                                     <option value="<?= htmlspecialchars($sp) ?>" <?= $sp === $drop ? 'selected' : '' ?>>
                                         <?= htmlspecialchars($sp) ?>
                                     </option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                         <div class="modal-footer">
                             <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                             <button type="submit" name="set_drop_specialty" class="btn btn-danger">Confirmer l'abandon</button>
                         </div>
                     </form>
                 </div>
             </div>
         </div>
     <?php endif; ?>

     <div class="modal fade" id="editOptionsModal" tabindex="-1">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title">Modifier les options</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                 </div>
                 <form method="post">
                     <div class="modal-body">
                         <?php foreach ($allOptions as $op) : ?>
                             <div class="form-check mb-2">
                                 <input class="form-check-input" type="checkbox" name="options[]" id="opt-<?= md5($op) ?>" value="<?= htmlspecialchars($op) ?>" <?= in_array($op, $optArr, true) ? 'checked' : ''; ?>>
                                 <label class="form-check-label" for="opt-<?= md5($op) ?>"><?= htmlspecialchars($op) ?></label>
                             </div>
                         <?php endforeach; ?>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                         <button type="submit" name="update_options" class="btn btn-primary">Enregistrer</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
         // Gestion des onglets de trimestre
         document.querySelectorAll('.trimester-tab').forEach(tab => {
             tab.addEventListener('click', () => {
                 document.querySelectorAll('.trimester-tab').forEach(t => t.classList.remove('active'));
                 document.querySelectorAll('.trimester-content').forEach(c => c.style.display = 'none');
                 tab.classList.add('active');
                 document.getElementById(tab.dataset.tab + '-content').style.display = 'block';
             });
         });

         // Boutons de copie dans la modale
         document.getElementById('copyT1toT2')?.addEventListener('click', () => {
             for (let i = 0; i < 3; i++) {
                 const val = document.querySelector(`#t1-modal select[name="spe[${i}]"]`).value;
                 document.querySelector(`#t2-modal select[name="spe_t2[${i}]"]`).value = val;
             }
         });

         document.getElementById('copyT2toT3')?.addEventListener('click', () => {
             for (let i = 0; i < 3; i++) {
                 const val = document.querySelector(`#t2-modal select[name="spe_t2[${i}]"]`).value;
                 document.querySelector(`#t3-modal select[name="spe_t3[${i}]"]`).value = val;
             }
         });

         // Initialisation des onglets Bootstrap
         document.querySelectorAll('#speTabs button[data-bs-toggle="tab"]').forEach(btn => {
             btn.addEventListener('click', e => {
                 e.preventDefault();
                 new bootstrap.Tab(btn).show();
             });
         });

         // Gestion ouverture / fermeture + rotation de l’icône
 document.querySelectorAll('.accordion-toggle').forEach(btn => {
   const targetId = btn.getAttribute('data-bs-target');
   const icon     = btn.querySelector('.bi');
   const collapseEl = document.querySelector(targetId);

   // Lorsque la section s’ouvre
   collapseEl.addEventListener('show.bs.collapse', () => {
     btn.setAttribute('aria-expanded', 'true');
     icon.classList.replace('bi-chevron-down', 'bi-chevron-up');
   });

   // Lorsque la section se referme
  collapseEl.addEventListener('hide.bs.collapse', () => {
    btn.setAttribute('aria-expanded', 'false');
    icon.classList.replace('bi-chevron-up', 'bi-chevron-down');
  });
});




     </script>
 </body>
 </html>
 <?php
 // Inclusion du pied de page
 include 'footer.php';
 ?>