<?php
// admin_dashboard.php
// Tableau de bord d'administration pour gérer le système d'orientation

require_once 'config.php';
session_start();

// Vérification de l'authentification et du rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Connexion à la base de données
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connexion échouée: " . $e->getMessage());
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Traitement des formulaires (ajout, modification, suppression)
    // Ceci est un exemple pour la table "users"
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $table = $_POST['table'] ?? '';

        switch ($action) {
            case 'add':
                // Traitement de l'ajout
                break;
            case 'edit':
                // Traitement de la modification
                break;
            case 'delete':
                // Traitement de la suppression
                break;
        }
    }
}

// Récupération des données pour les tableaux
$tables = [
    'users' => 'Utilisateurs',
    'classes' => 'Classes',
    'students' => 'Élèves',
    'specialties' => 'Spécialités',
    'events' => 'Événements',
    'preferences' => 'Préférences',
    'mentions' => 'Mentions',
    'opinions' => 'Avis',
    'notes' => 'Notes',
    'comments' => 'Commentaires',
    'comment_likes' => 'Likes de commentaires',
    'settings' => 'Paramètres'
];

// Récupération des données pour chaque table
$tableData = [];
foreach ($tables as $tableName => $tableLabel) {
    try {
        $stmt = $conn->prepare("SELECT * FROM $tableName");
        $stmt->execute();
        $tableData[$tableName] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $tableData[$tableName] = [];
    }
}

// Récupération des statistiques
$stats = [
    'users' => count($tableData['users']),
    'students' => count($tableData['students']),
    'classes' => count($tableData['classes']),
    'specialties' => count($tableData['specialties'])
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord Administrateur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.3/font/bootstrap-icons.css">
    <style>
        :root {
            --bs-primary-rgb: 78, 115, 223;
            --bs-success-rgb: 28, 200, 138;
            --bs-warning-rgb: 246, 194, 62;
            --bs-info-rgb: 54, 185, 204;
            --bs-dark-rgb: 58, 59, 69;
        }

        body {
            background-color: #f8f9fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .admin-header {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.2);
        }

        .stat-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border-radius: 0.75rem;
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.2);
        }

        .card {
            border-radius: 0.75rem;
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e3e6f0;
            padding: 1.25rem 1.5rem;
            border-radius: 0.75rem 0.75rem 0 0 !important;
        }

        .nav-tabs .nav-link {
            border: none;
            font-weight: 600;
            color: #6e707e;
            padding: 1rem 1.5rem;
        }

        .nav-tabs .nav-link.active {
            color: #4e73df;
            border-bottom: 3px solid #4e73df;
            background-color: transparent;
        }

        .table-responsive {
            border-radius: 0.75rem;
            overflow: hidden;
        }

        .table th {
            background-color: #f8f9fc;
            color: #4e73df;
            font-weight: 700;
            padding: 1rem 1.5rem;
        }

        .table td {
            padding: 0.75rem 1.5rem;
            vertical-align: middle;
        }

        .btn-action {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            margin: 0 2px;
        }

        .tab-content {
            padding: 1.5rem 0;
        }

        .quick-actions .btn {
            padding: 1rem;
            text-align: left;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        }

        .quick-actions .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }

        .admin-footer {
            background-color: #fff;
            padding: 1.5rem 0;
            margin-top: 2rem;
            border-top: 1px solid #e3e6f0;
        }
    </style>
</head>
<body>
    <!-- En-tête -->
    <header class="admin-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h2 mb-0"><i class="bi bi-shield-lock me-2"></i>Tableau de Bord Administrateur</h1>
                    <p class="mb-0 opacity-75">Gestion complète du système d'orientation</p>
                </div>
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <span class="badge bg-white bg-opacity-25 text-white fs-6 py-2 px-3">
                            <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?>
                        </span>
                    </div>
                    <a href="logout.php" class="btn btn-outline-light">
                        <i class="bi bi-box-arrow-right me-1"></i> Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Cartes de statistiques -->
        <section class="row mb-4">
            <?php
            $statCards = [
                ['label' => 'Utilisateurs', 'value' => $stats['users'], 'icon' => 'bi-people-fill', 'color' => 'primary'],
                ['label' => 'Élèves', 'value' => $stats['students'], 'icon' => 'bi-person-badge', 'color' => 'success'],
                ['label' => 'Classes', 'value' => $stats['classes'], 'icon' => 'bi-building', 'color' => 'warning'],
                ['label' => 'Spécialités', 'value' => $stats['specialties'], 'icon' => 'bi-journal-bookmark', 'color' => 'info']
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

        <!-- Navigation par onglets -->
        <section class="card mb-4">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="adminTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Utilisateurs</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="classes-tab" data-bs-toggle="tab" data-bs-target="#classes" type="button" role="tab">Classes</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab">Élèves</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="specialties-tab" data-bs-toggle="tab" data-bs-target="#specialties" type="button" role="tab">Spécialités</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">Événements</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="other-tab" data-bs-toggle="tab" data-bs-target="#other" type="button" role="tab">Autres Tables</button>
                    </li>
                </ul>
            </div>

            <div class="tab-content" id="adminTabsContent">
                <!-- Onglet Utilisateurs -->
                <div class="tab-pane fade show active" id="users" role="tabpanel">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">Gestion des Utilisateurs</h5>
                            <button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Ajouter un utilisateur</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom d'utilisateur</th>
                                        <th>Nom</th>
                                        <th>Email</th>
                                        <th>Rôle</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData['users'] as $user): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user['id']) ?></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= htmlspecialchars($user['name']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><span class="badge bg-<?= $user['role'] === 'admin' ? 'primary' : ($user['role'] === 'direction' ? 'warning' : 'success') ?>"><?= htmlspecialchars($user['role']) ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary btn-action"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Onglet Classes -->
                <div class="tab-pane fade" id="classes" role="tabpanel">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">Gestion des Classes</h5>
                            <button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Ajouter une classe</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Niveau</th>
                                        <th>Année</th>
                                        <th>Enseignant</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData['classes'] as $class): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($class['id']) ?></td>
                                        <td><?= htmlspecialchars($class['name']) ?></td>
                                        <td><?= htmlspecialchars($class['level']) ?></td>
                                        <td><?= htmlspecialchars($class['year']) ?></td>
                                        <td><?= htmlspecialchars($class['teacher_id']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary btn-action"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Onglet Élèves -->
                <div class="tab-pane fade" id="students" role="tabpanel">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">Gestion des Élèves</h5>
                            <button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Ajouter un élève</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Prénom</th>
                                        <th>Classe</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData['students'] as $student): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($student['id']) ?></td>
                                        <td><?= htmlspecialchars($student['last_name']) ?></td>
                                        <td><?= htmlspecialchars($student['first_name']) ?></td>
                                        <td><?= htmlspecialchars($student['class_id']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary btn-action"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-info btn-action"><i class="bi bi-eye"></i></button>
                                            <button class="btn btn-sm btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Onglet Spécialités -->
                <div class="tab-pane fade" id="specialties" role="tabpanel">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">Gestion des Spécialités</h5>
                            <button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Ajouter une spécialité</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData['specialties'] as $specialty): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($specialty['id']) ?></td>
                                        <td><?= htmlspecialchars($specialty['name']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary btn-action"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Onglet Événements -->
                <div class="tab-pane fade" id="events" role="tabpanel">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="mb-0">Gestion des Événements</h5>
                            <button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Ajouter un événement</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Titre</th>
                                        <th>Date</th>
                                        <th>Créé par</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData['events'] as $event): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($event['id']) ?></td>
                                        <td><?= htmlspecialchars($event['title']) ?></td>
                                        <td><?= htmlspecialchars($event['event_date']) ?></td>
                                        <td><?= htmlspecialchars($event['created_by']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary btn-action"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Onglet Autres Tables -->
                <div class="tab-pane fade" id="other" role="tabpanel">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-journal me-1"></i> Préférences d'Orientation</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Élève</th>
                                                        <th>Niveau</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($tableData['preferences'], 0, 5) as $pref): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($pref['id']) ?></td>
                                                        <td><?= htmlspecialchars($pref['student_id']) ?></td>
                                                        <td><?= htmlspecialchars($pref['grade']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-center mt-2">
                                            <a href="#" class="btn btn-sm btn-outline-primary">Voir toutes les préférences</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-chat-dots me-1"></i> Commentaires</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Auteur</th>
                                                        <th>Contenu</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($tableData['comments'], 0, 5) as $comment): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($comment['id']) ?></td>
                                                        <td><?= htmlspecialchars($comment['author_name']) ?></td>
                                                        <td><?= substr(htmlspecialchars($comment['content']), 0, 30) ?>...</td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-center mt-2">
                                            <a href="#" class="btn btn-sm btn-outline-primary">Voir tous les commentaires</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-star me-1"></i> Mentions</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Préférence</th>
                                                        <th>Mention</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach (array_slice($tableData['mentions'], 0, 5) as $mention): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($mention['id']) ?></td>
                                                        <td><?= htmlspecialchars($mention['preference_id']) ?></td>
                                                        <td><?= htmlspecialchars($mention['mention']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-center mt-2">
                                            <a href="#" class="btn btn-sm btn-outline-primary">Voir toutes les mentions</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h6 class="mb-0"><i class="bi bi-gear me-1"></i> Paramètres</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Nom</th>
                                                        <th>Valeur</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($tableData['settings'] as $setting): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($setting['name']) ?></td>
                                                        <td><?= htmlspecialchars($setting['value']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-center mt-2">
                                            <button class="btn btn-sm btn-outline-primary">Modifier les paramètres</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Actions rapides et export -->
        <section class="row">
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Actions Rapides</h5></div>
                    <div class="card-body quick-actions">
                        <div class="d-grid gap-2">
                            <a href="#" class="btn btn-outline-primary">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-people fs-3 me-3"></i>
                                    <div>
                                        <h6 class="mb-0">Gérer les rôles utilisateurs</h6>
                                        <small class="text-muted">Modifier les permissions des comptes</small>
                                    </div>
                                </div>
                            </a>

                            <a href="#" class="btn btn-outline-success">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-file-earmark-spreadsheet fs-3 me-3"></i>
                                    <div>
                                        <h6 class="mb-0">Exporter les données</h6>
                                        <small class="text-muted">Générer des rapports au format Excel ou PDF</small>
                                    </div>
                                </div>
                            </a>

                            <a href="#" class="btn btn-outline-warning">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-calendar-event fs-3 me-3"></i>
                                    <div>
                                        <h6 class="mb-0">Planifier un événement</h6>
                                        <small class="text-muted">Ajouter un nouvel événement au calendrier</small>
                                    </div>
                                </div>
                            </a>

                            <a href="#" class="btn btn-outline-info">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-arrow-repeat fs-3 me-3"></i>
                                    <div>
                                        <h6 class="mb-0">Mettre à jour l'année scolaire</h6>
                                        <small class="text-muted">Passer à la nouvelle année académique</small>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header py-3"><h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Statistiques</h5></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <h6>Répartition des utilisateurs</h6>
                                <p class="text-muted mb-0">Par type de rôle</p>
                            </div>
                            <div class="bg-primary bg-opacity-10 text-primary rounded p-2">
                                <i class="bi bi-pie-chart fs-4"></i>
                            </div>
                        </div>

                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1 me-3">
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: 15%"></div>
                                </div>
                                <small class="d-flex justify-content-between">
                                    <span>Administrateurs</span>
                                    <span class="fw-medium">15%</span>
                                </small>
                            </div>
                        </div>

                        <div class="d-flex align-items-center mb-4">
                            <div class="flex-grow-1 me-3">
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: 25%"></div>
                                </div>
                                <small class="d-flex justify-content-between">
                                    <span>Direction</span>
                                    <span class="fw-medium">25%</span>
                                </small>
                            </div>
                        </div>

                        <div class="d-flex align-items-center mb-2">
                            <div class="flex-grow-1 me-3">
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-warning" role="progressbar" style="width: 60%"></div>
                                </div>
                                <small class="d-flex justify-content-between">
                                    <span>Enseignants</span>
                                    <span class="fw-medium">60%</span>
                                </small>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>Élèves par niveau</h6>
                                <p class="text-muted mb-0">Répartition académique</p>
                            </div>
                            <div class="bg-success bg-opacity-10 text-success rounded p-2">
                                <i class="bi bi-bar-chart fs-4"></i>
                            </div>
                        </div>

                        <div class="row text-center mt-3">
                            <div class="col-4">
                                <h4 class="fw-bold text-primary">42%</h4>
                                <small class="text-muted">Seconde</small>
                            </div>
                            <div class="col-4">
                                <h4 class="fw-bold text-success">35%</h4>
                                <small class="text-muted">Première</small>
                            </div>
                            <div class="col-4">
                                <h4 class="fw-bold text-warning">23%</h4>
                                <small class="text-muted">Terminale</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Pied de page -->
    <footer class="admin-footer">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted">Système d'Orientation &copy; <?= date('Y') ?></span>
                </div>
                <div>
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-database me-1"></i>
                        <?= count($tables) ?> tables gérées
                    </span>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialisation des tooltips Bootstrap
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Gestion des actions
            document.querySelectorAll('.btn-action').forEach(button => {
                button.addEventListener('click', function() {
                    const action = this.querySelector('i').className.includes('pencil') ? 'edit' : 'delete';
                    const row = this.closest('tr');
                    const id = row.querySelector('td:first-child').textContent;

                    alert(`Action: ${action} sur ID: ${id}`);
                });
            });
        });
    </script>
</body>
</html>