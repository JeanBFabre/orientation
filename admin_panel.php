<?php
/**
 * admin_panel.php
 * Panneau de contrôle complet pour les administrateurs.
 * Permet la gestion des utilisateurs, classes, élèves, spécialités, événements, et paramètres.
 *
 * @version 1.0
 */

require_once 'config.php'; // Fichier de connexion à la base de données (à créer)
session_start();

// --- SÉCURITÉ : Vérification du rôle Administrateur ---
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php'); // Redirige vers la page de connexion si non autorisé
    exit;
}

// --- GESTION DES REQUÊTES POST (Ajout, Modification, Suppression) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $conn->begin_transaction();

        switch ($_POST['action']) {
            // --- Actions Utilisateurs ---
            case 'add_user':
                $stmt = $conn->prepare("INSERT INTO users (username, password, role, name, email) VALUES (?, ?, ?, ?, ?)");
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt->bind_param('sssss', $_POST['username'], $hashed_password, $_POST['role'], $_POST['name'], $_POST['email']);
                $stmt->execute();
                break;

            case 'edit_user':
                $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                if (!empty($_POST['password'])) {
                    $stmt = $conn->prepare("UPDATE users SET username=?, role=?, name=?, email=?, password=? WHERE id=?");
                    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt->bind_param('sssssi', $_POST['username'], $_POST['role'], $_POST['name'], $_POST['email'], $hashed_password, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?, role=?, name=?, email=? WHERE id=?");
                    $stmt->bind_param('ssssi', $_POST['username'], $_POST['role'], $_POST['name'], $_POST['email'], $user_id);
                }
                $stmt->execute();
                break;

            case 'delete_user':
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $_POST['user_id']);
                $stmt->execute();
                break;

            // --- Actions Classes ---
            case 'add_class':
                $teacher_id = !empty($_POST['teacher_id']) ? $_POST['teacher_id'] : null;
                $stmt = $conn->prepare("INSERT INTO classes (name, level, year, teacher_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('sssi', $_POST['name'], $_POST['level'], $_POST['year'], $teacher_id);
                $stmt->execute();
                break;

            case 'edit_class':
                $teacher_id = !empty($_POST['teacher_id']) ? $_POST['teacher_id'] : null;
                $stmt = $conn->prepare("UPDATE classes SET name=?, level=?, year=?, teacher_id=? WHERE id=?");
                $stmt->bind_param('sssii', $_POST['name'], $_POST['level'], $_POST['year'], $teacher_id, $_POST['class_id']);
                $stmt->execute();
                break;

            case 'delete_class':
                $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                $stmt->bind_param('i', $_POST['class_id']);
                $stmt->execute();
                break;

            // --- Actions Spécialités ---
             case 'add_specialty':
                $stmt = $conn->prepare("INSERT INTO specialties (name) VALUES (?)");
                $stmt->bind_param('s', $_POST['name']);
                $stmt->execute();
                break;

            case 'delete_specialty':
                $stmt = $conn->prepare("DELETE FROM specialties WHERE id = ?");
                $stmt->bind_param('i', $_POST['specialty_id']);
                $stmt->execute();
                break;

            // --- Actions Événements ---
            case 'add_event':
                $stmt = $conn->prepare("INSERT INTO events (title, event_date, description, created_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('sssi', $_POST['title'], $_POST['event_date'], $_POST['description'], $_SESSION['user_id']);
                $stmt->execute();
                break;

            // --- Action Modération ---
            case 'delete_comment':
                 $stmt = $conn->prepare("DELETE FROM comments WHERE id = ?");
                 $stmt->bind_param('i', $_POST['comment_id']);
                 $stmt->execute();
                 break;

            // --- Action Paramètres ---
            case 'update_settings':
                $stmt = $conn->prepare("UPDATE settings SET value = ? WHERE name = ?");
                foreach ($_POST['settings'] as $name => $value) {
                    $stmt->bind_param('ss', $value, $name);
                    $stmt->execute();
                }
                break;
        }
        $conn->commit();
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        // Gérer l'erreur (par exemple, logger ou afficher un message)
        error_log("Database error: " . $exception->getMessage());
    }

    // Rediriger pour éviter la soumission multiple du formulaire
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- RÉCUPÉRATION DES DONNÉES POUR L'AFFICHAGE ---
$users = $conn->query("SELECT id, username, name, role, email FROM users ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$teachers = $conn->query("SELECT id, name FROM users WHERE role = 'prof' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$classes = $conn->query("SELECT c.id, c.name, c.level, c.year, u.name as teacher_name FROM classes c LEFT JOIN users u ON c.teacher_id = u.id ORDER BY c.year DESC, c.level, c.name")->fetch_all(MYSQLI_ASSOC);
$specialties = $conn->query("SELECT id, name FROM specialties ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$events = $conn->query("SELECT id, title, event_date, description FROM events ORDER BY event_date DESC")->fetch_all(MYSQLI_ASSOC);
$comments = $conn->query("SELECT c.id, c.content, c.author_name, c.created_at, s.first_name, s.last_name FROM comments c LEFT JOIN students s ON c.student_id = s.id ORDER BY c.created_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);

// Récupération des paramètres sous forme de tableau associatif
$settings_result = $conn->query("SELECT name, value FROM settings");
$settings = [];
while($row = $settings_result->fetch_assoc()){
    $settings[$row['name']] = $row['value'];
}


include 'header.php'; // Inclure l'en-tête HTML
?>

<style>
    /* Styles inspirés du modèle pour une interface moderne */
    :root { --bs-primary-rgb: 78, 115, 223; }
    .card { border-radius: 0.75rem; border: none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); }
    .card-header { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; font-weight: bold; }
    .nav-tabs .nav-link { border-top-left-radius: .5rem; border-top-right-radius: .5rem; }
    .nav-tabs .nav-link.active { color: #4e73df; border-color: #e3e6f0 #e3e6f0 #fff; background-color: #fff; }
    .table-hover tbody tr:hover { background-color: rgba(var(--bs-primary-rgb), 0.05); }
    .btn-action-sm { padding: 0.2rem 0.5rem; font-size: 0.8rem; }
</style>

<div class="container-fluid py-4">
    <header class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0"><i class="bi bi-sliders me-2"></i>Panneau d'Administration</h1>
        <span class="badge bg-danger bg-opacity-10 text-danger fs-6 py-2 px-3">
            <i class="bi bi-shield-lock-fill me-1"></i> Accès Administrateur
        </span>
    </header>

    <ul class="nav nav-tabs mb-4" id="adminTab" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Utilisateurs</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="classes-tab" data-bs-toggle="tab" data-bs-target="#classes" type="button" role="tab">Classes</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="specialties-tab" data-bs-toggle="tab" data-bs-target="#specialties" type="button" role="tab">Spécialités</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="events-tab" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">Événements</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="comments-tab" data-bs-toggle="tab" data-bs-target="#comments" type="button" role="tab">Modération</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab">Paramètres</button></li>
    </ul>

    <div class="tab-content" id="adminTabContent">

        <div class="tab-pane fade show active" id="users" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people-fill me-2"></i>Gestion des Utilisateurs</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-plus-circle me-1"></i> Ajouter</button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Email</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['name']) ?></td>
                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($user['role']) ?></span></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-action-sm" data-bs-toggle="modal" data-bs-target="#editUserModal" data-id="<?= $user['id'] ?>" data-name="<?= htmlspecialchars($user['name']) ?>" data-username="<?= htmlspecialchars($user['username']) ?>" data-email="<?= htmlspecialchars($user['email']) ?>" data-role="<?= $user['role'] ?>"><i class="bi bi-pencil-fill"></i></button>
                                        <form action="" method="POST" class="d-inline delete-form">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-action-sm"><i class="bi bi-trash-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="classes" role="tabpanel">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building me-2"></i>Gestion des Classes</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addClassModal"><i class="bi bi-plus-circle me-1"></i> Ajouter</button>
                </div>
                <div class="card-body">
                     <div class="table-responsive">
                        <table class="table table-hover">
                            <thead><tr><th>Classe</th><th>Niveau</th><th>Année</th><th>Prof. Principal</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td><?= htmlspecialchars($class['name']) ?></td>
                                    <td><?= htmlspecialchars($class['level']) ?></td>
                                    <td><?= htmlspecialchars($class['year']) ?></td>
                                    <td><?= htmlspecialchars($class['teacher_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <button class="btn btn-warning btn-action-sm" data-bs-toggle="modal" data-bs-target="#editClassModal" data-id="<?= $class['id'] ?>" data-name="<?= htmlspecialchars($class['name']) ?>" data-level="<?= $class['level'] ?>" data-year="<?= $class['year'] ?>" data-teacher_id="<?= $class['teacher_id'] ?? '' ?>"><i class="bi bi-pencil-fill"></i></button>
                                        <form action="" method="POST" class="d-inline delete-form">
                                            <input type="hidden" name="action" value="delete_class">
                                            <input type="hidden" name="class_id" value="<?= $class['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-action-sm"><i class="bi bi-trash-fill"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="specialties" role="tabpanel">
            <div class="card">
                 <div class="card-header">
                    <span><i class="bi bi-journal-bookmark me-2"></i>Gestion des Spécialités</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Liste des spécialités existantes</h6>
                             <ul class="list-group">
                                <?php foreach ($specialties as $spec): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= htmlspecialchars($spec['name']) ?>
                                    <form action="" method="POST" class="d-inline delete-form">
                                        <input type="hidden" name="action" value="delete_specialty">
                                        <input type="hidden" name="specialty_id" value="<?= $spec['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                </li>
                                <?php endforeach; ?>
                             </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Ajouter une nouvelle spécialité</h6>
                            <form action="" method="POST">
                                <input type="hidden" name="action" value="add_specialty">
                                <div class="mb-3">
                                    <label for="specName" class="form-label">Nom de la spécialité</label>
                                    <input type="text" class="form-control" id="specName" name="name" required>
                                </div>
                                <button type="submit" class="btn btn-primary">Ajouter la spécialité</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="events" role="tabpanel">
             <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-calendar-event me-2"></i>Gestion du Calendrier</span>
                     <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEventModal"><i class="bi bi-plus-circle me-1"></i> Ajouter</button>
                </div>
                <div class="card-body">
                    <p class="text-muted">Gérez ici les événements importants (conseils de classe, réunions, etc.).</p>
                    </div>
            </div>
        </div>

        <div class="tab-pane fade" id="comments" role="tabpanel">
             <div class="card">
                <div class="card-header">
                    <span><i class="bi bi-chat-quote-fill me-2"></i>Modération des Commentaires</span>
                </div>
                <div class="card-body">
                    <p class="text-muted">Voici les 20 derniers commentaires postés. Vous pouvez les supprimer si nécessaire.</p>
                     <div class="table-responsive">
                        <table class="table table-sm">
                           <thead><tr><th>Date</th><th>Auteur</th><th>Contenu</th><th>Profil Élève</th><th>Action</th></tr></thead>
                           <tbody>
                               <?php foreach ($comments as $comment): ?>
                               <tr>
                                   <td><?= date('d/m/Y H:i', strtotime($comment['created_at'])) ?></td>
                                   <td><?= htmlspecialchars($comment['author_name']) ?></td>
                                   <td><?= nl2br(htmlspecialchars(substr($comment['content'], 0, 100))) ?>...</td>
                                   <td><?= htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']) ?></td>
                                   <td>
                                       <form action="" method="POST" class="d-inline delete-form">
                                           <input type="hidden" name="action" value="delete_comment">
                                           <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                           <button type="submit" class="btn btn-danger btn-action-sm"><i class="bi bi-trash-fill"></i> Supprimer</button>
                                       </form>
                                   </td>
                               </tr>
                               <?php endforeach; ?>
                           </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="settings" role="tabpanel">
            <div class="card">
                <div class="card-header"><span><i class="bi bi-gear-fill me-2"></i>Paramètres Généraux</span></div>
                <div class="card-body">
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="update_settings">
                        <?php foreach ($settings as $name => $value): ?>
                        <div class="mb-3">
                            <label for="setting_<?= htmlspecialchars($name) ?>" class="form-label text-capitalize"><?= str_replace('_', ' ', htmlspecialchars($name)) ?></label>
                            <input type="text" class="form-control" id="setting_<?= htmlspecialchars($name) ?>" name="settings[<?= htmlspecialchars($name) ?>]" value="<?= htmlspecialchars($value) ?>">
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-success"><i class="bi bi-save me-2"></i>Sauvegarder les paramètres</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>


<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Ajouter un utilisateur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="add_user">
            <div class="mb-3"><label class="form-label">Nom complet</label><input type="text" name="name" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Identifiant</label><input type="text" name="username" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
            <div class="mb-3"><label class="form-label">Rôle</label><select name="role" class="form-select" required><option value="admin">Admin</option><option value="direction">Direction</option><option value="prof">Prof</option></select></div>
            <div class="mb-3"><label class="form-label">Mot de passe</label><input type="password" name="password" class="form-control" required></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Modifier un utilisateur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="mb-3"><label class="form-label">Nom complet</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Identifiant</label><input type="text" name="username" id="edit_username" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
            <div class="mb-3"><label class="form-label">Rôle</label><select name="role" id="edit_role" class="form-select" required><option value="admin">Admin</option><option value="direction">Direction</option><option value="prof">Prof</option></select></div>
            <div class="mb-3"><label class="form-label">Nouveau mot de passe</label><input type="password" name="password" class="form-control" placeholder="Laisser vide pour ne pas changer"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="addClassModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Ajouter une classe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form action="" method="POST">
        <div class="modal-body">
            <input type="hidden" name="action" value="add_class">
            <div class="mb-3"><label class="form-label">Nom</label><input type="text" name="name" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Niveau</label><select name="level" class="form-select" required><option value="Seconde">Seconde</option><option value="Première">Première</option><option value="Terminale">Terminale</option></select></div>
            <div class="mb-3"><label class="form-label">Année Scolaire</label><input type="number" name="year" class="form-control" value="<?= date('Y') ?>" required></div>
            <div class="mb-3"><label class="form-label">Prof. Principal</label><select name="teacher_id" class="form-select"><option value="">Aucun</option><?php foreach ($teachers as $teacher): ?><option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary">Ajouter</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editClassModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Modifier une classe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_class">
                <input type="hidden" name="class_id" id="edit_class_id">
                <div class="mb-3"><label class="form-label">Nom</label><input type="text" name="name" id="edit_class_name" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Niveau</label><select name="level" id="edit_class_level" class="form-select" required><option value="Seconde">Seconde</option><option value="Première">Première</option><option value="Terminale">Terminale</option></select></div>
                <div class="mb-3"><label class="form-label">Année Scolaire</label><input type="number" name="year" id="edit_class_year" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Prof. Principal</label><select name="teacher_id" id="edit_class_teacher_id" class="form-select"><option value="">Aucun</option><?php foreach ($teachers as $teacher): ?><option value="<?= $teacher['id'] ?>"><?= htmlspecialchars($teacher['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>
    </div>
  </div>
</div>

<div class="modal fade" id="addEventModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Ajouter un événement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_event">
                <div class="mb-3"><label class="form-label">Titre</label><input type="text" name="title" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Date</label><input type="date" name="event_date" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary">Ajouter</button></div>
        </form>
    </div>
  </div>
</div>


<?php include 'footer.php'; // Inclure le pied de page HTML ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // --- Script pour remplir les modales de modification ---

    // Pour les utilisateurs
    const editUserModal = document.getElementById('editUserModal');
    if (editUserModal) {
        editUserModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            editUserModal.querySelector('#edit_user_id').value = button.getAttribute('data-id');
            editUserModal.querySelector('#edit_name').value = button.getAttribute('data-name');
            editUserModal.querySelector('#edit_username').value = button.getAttribute('data-username');
            editUserModal.querySelector('#edit_email').value = button.getAttribute('data-email');
            editUserModal.querySelector('#edit_role').value = button.getAttribute('data-role');
        });
    }

    // Pour les classes
    const editClassModal = document.getElementById('editClassModal');
    if (editClassModal) {
        editClassModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            editClassModal.querySelector('#edit_class_id').value = button.getAttribute('data-id');
            editClassModal.querySelector('#edit_class_name').value = button.getAttribute('data-name');
            editClassModal.querySelector('#edit_class_level').value = button.getAttribute('data-level');
            editClassModal.querySelector('#edit_class_year').value = button.getAttribute('data-year');
            editClassModal.querySelector('#edit_class_teacher_id').value = button.getAttribute('data-teacher_id');
        });
    }

    // --- Confirmation de suppression ---
    const deleteForms = document.querySelectorAll('.delete-form');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!confirm('Êtes-vous sûr de vouloir supprimer cet élément ? Cette action est irréversible.')) {
                event.preventDefault();
            }
        });
    });

    // --- Garder l'onglet actif après rechargement ---
    const triggerTabList = [].slice.call(document.querySelectorAll('#adminTab button'));
    triggerTabList.forEach(function (triggerEl) {
      const tabTrigger = new bootstrap.Tab(triggerEl);
      triggerEl.addEventListener('click', function (event) {
        event.preventDefault();
        tabTrigger.show();
        localStorage.setItem('activeAdminTab', triggerEl.getAttribute('data-bs-target'));
      });
    });
    const activeTab = localStorage.getItem('activeAdminTab');
    if (activeTab) {
      const someTabTriggerEl = document.querySelector(`button[data-bs-target="${activeTab}"]`);
      if (someTabTriggerEl) {
          const tab = new bootstrap.Tab(someTabTriggerEl);
          tab.show();
      }
    }
});
</script>