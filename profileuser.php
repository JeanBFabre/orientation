<?php
// profileuser.php - Page de profil et paramètres du compte
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$currentYear = $_SESSION['current_year'];
$msg = '';
$error = '';

// Récupération des données utilisateur
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: logout.php');
    exit;
}

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        // Mise à jour du profil
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($name)) {
            $error = 'Le nom ne peut pas être vide';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($email)) {
            $error = 'L\'adresse email n\'est pas valide';
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->bind_param('ssi', $name, $email, $userId);
            
            if ($stmt->execute()) {
                $_SESSION['name'] = $name;
                $user['name'] = $name;
                $user['email'] = $email;
                $msg = 'Profil mis à jour avec succès';
            } else {
                $error = 'Erreur lors de la mise à jour du profil';
            }
            $stmt->close();
        }
    }
    elseif ($action === 'change_password') {
        // Changement de mot de passe
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'Tous les champs sont obligatoires';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $error = 'Le mot de passe actuel est incorrect';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Les nouveaux mots de passe ne correspondent pas';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                $msg = 'Mot de passe mis à jour avec succès';
            } else {
                $error = 'Erreur lors de la mise à jour du mot de passe';
            }
            $stmt->close();
        }
    }
    elseif ($action === 'update_notifications') {
        // Mise à jour des préférences de notification (exemple)
        $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
        $appNotifications = isset($_POST['app_notifications']) ? 1 : 0;
        
        // Dans une implémentation réelle, vous stockeriez ces préférences dans la base
        $msg = 'Préférences de notification mises à jour';
    }
}

include 'header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Colonne latérale avec navigation -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-body text-center p-4">
                    <div class="mb-4">
                        <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 120px; height: 120px;">
                            <span class="display-4 text-primary"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
                        </div>
                    </div>
                    
                    <h3 class="mb-1"><?= htmlspecialchars($user['name']) ?></h3>
                    <p class="text-muted mb-3">
                        <?php if ($userRole === 'admin'): ?>
                            <span class="badge bg-danger">Administrateur</span>
                        <?php elseif ($userRole === 'direction'): ?>
                            <span class="badge bg-success">Direction</span>
                        <?php else: ?>
                            <span class="badge bg-primary">Professeur</span>
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-flex justify-content-center gap-2 mb-4">
                        <div class="text-center">
                            <div class="fs-4 fw-bold">12</div>
                            <div class="text-muted small">Élèves</div>
                        </div>
                        <div class="text-center">
                            <div class="fs-4 fw-bold">3</div>
                            <div class="text-muted small">Classes</div>
                        </div>
                        <div class="text-center">
                            <div class="fs-4 fw-bold">28</div>
                            <div class="text-muted small">Notes</div>
                        </div>
                    </div>
                    
                    <div class="list-group">
                        <a href="profileuser.php?tab=profile" class="list-group-item list-group-item-action border-0 rounded-3 mb-2 <?= ($_GET['tab'] ?? 'profile') === 'profile' ? 'active' : '' ?>">
                            <i class="bi bi-person me-2"></i> Mon profil
                        </a>
                        <a href="profileuser.php?tab=account" class="list-group-item list-group-item-action border-0 rounded-3 mb-2 <?= ($_GET['tab'] ?? '') === 'account' ? 'active' : '' ?>">
                            <i class="bi bi-lock me-2"></i> Sécurité du compte
                        </a>
                        <a href="profileuser.php?tab=notifications" class="list-group-item list-group-item-action border-0 rounded-3 mb-2 <?= ($_GET['tab'] ?? '') === 'notifications' ? 'active' : '' ?>">
                            <i class="bi bi-bell me-2"></i> Notifications
                        </a>
                        <?php if ($userRole === 'prof'): ?>
                            <a href="profileuser.php?tab=classes" class="list-group-item list-group-item-action border-0 rounded-3 mb-2 <?= ($_GET['tab'] ?? '') === 'classes' ? 'active' : '' ?>">
                                <i class="bi bi-building me-2"></i> Mes classes
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Contenu principal -->
        <div class="col-lg-8">
            <?php if ($msg): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4">
                    <?= $msg ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <?php $activeTab = $_GET['tab'] ?? 'profile'; ?>
                    
                    <!-- Onglet Profil -->
                    <?php if ($activeTab === 'profile'): ?>
                        <h3 class="mb-4"><i class="bi bi-person me-2"></i> Mon profil</h3>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-medium">Nom complet</label>
                                    <input type="text" name="name" class="form-control" 
                                           value="<?= htmlspecialchars($user['name']) ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-medium">Adresse email</label>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-medium">Nom d'utilisateur</label>
                                    <input type="text" class="form-control" 
                                           value="<?= htmlspecialchars($user['username']) ?>" disabled>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-medium">Rôle</label>
                                    <input type="text" class="form-control" 
                                           value="<?= 
                                                $userRole === 'admin' ? 'Administrateur' : 
                                                ($userRole === 'direction' ? 'Direction' : 'Professeur')
                                           ?>" disabled>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> Enregistrer les modifications
                            </button>
                        </form>
                    
                    <!-- Onglet Sécurité du compte -->
                    <?php elseif ($activeTab === 'account'): ?>
                        <h3 class="mb-4"><i class="bi bi-lock me-2"></i> Sécurité du compte</h3>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-4">
                                <label class="form-label fw-medium">Mot de passe actuel</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="row mb-4">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-medium">Nouveau mot de passe</label>
                                    <input type="password" name="new_password" class="form-control" required>
                                    <div class="form-text">Minimum 8 caractères</div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-medium">Confirmer le nouveau mot de passe</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i> Pour des raisons de sécurité, nous vous recommandons d'utiliser un mot de passe unique que vous n'utilisez pas sur d'autres sites.
                            </div>
                            
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-key me-1"></i> Changer le mot de passe
                            </button>
                        </form>
                    
                    <!-- Onglet Notifications -->
                    <?php elseif ($activeTab === 'notifications'): ?>
                        <h3 class="mb-4"><i class="bi bi-bell me-2"></i> Préférences de notification</h3>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="update_notifications">
                            
                            <div class="mb-4">
                                <h5 class="mb-3">Email</h5>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="email_notifications" id="emailNotifications" checked>
                                    <label class="form-check-label fw-medium" for="emailNotifications">
                                        Activer les notifications par email
                                    </label>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label fw-medium">Fréquence des emails</label>
                                    <select class="form-select" name="email_frequency">
                                        <option value="immediate">Immédiatement</option>
                                        <option value="daily" selected>Résumé quotidien</option>
                                        <option value="weekly">Résumé hebdomadaire</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <h5 class="mb-3">Notifications dans l'application</h5>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="app_notifications" id="appNotifications" checked>
                                    <label class="form-check-label fw-medium" for="appNotifications">
                                        Activer les notifications dans l'application
                                    </label>
                                </div>
                                
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="app_sound" id="appSound" checked>
                                    <label class="form-check-label fw-medium" for="appSound">
                                        Activer les sons de notification
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <h5 class="mb-3">Types de notifications</h5>
                                
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="notif_events" id="notifEvents" checked>
                                    <label class="form-check-label fw-medium" for="notifEvents">
                                        Nouveaux événements
                                    </label>
                                </div>
                                
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="notif_comments" id="notifComments" checked>
                                    <label class="form-check-label fw-medium" for="notifComments">
                                        Nouveaux commentaires sur mes élèves
                                    </label>
                                </div>
                                
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="notif_updates" id="notifUpdates">
                                    <label class="form-check-label fw-medium" for="notifUpdates">
                                        Mises à jour du système
                                    </label>
                                </div>
                                
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="notif_reminders" id="notifReminders" checked>
                                    <label class="form-check-label fw-medium" for="notifReminders">
                                        Rappels de tâches
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-save me-1"></i> Enregistrer les préférences
                            </button>
                        </form>
                    
                    <!-- Onglet Mes Classes (pour les professeurs) -->
                    <?php elseif ($activeTab === 'classes' && $userRole === 'prof'): ?>
                        <h3 class="mb-4"><i class="bi bi-building me-2"></i> Mes classes</h3>
                        
                        <?php
                        // Récupérer les classes du professeur
                        $stmt = $conn->prepare("
                            SELECT c.id, c.name, c.level, COUNT(s.id) AS student_count
                            FROM classes c
                            LEFT JOIN students s ON s.class_id = c.id
                            WHERE c.teacher_id = ? AND c.year = ?
                            GROUP BY c.id
                        ");
                        $stmt->bind_param('is', $userId, $currentYear);
                        $stmt->execute();
                        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        ?>
                        
                        <?php if (empty($classes)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-building text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-3">Vous n'avez aucune classe attribuée pour cette année scolaire</p>
                                <a href="list_classes.php" class="btn btn-outline-primary">
                                    Voir toutes les classes
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($classes as $class): ?>
                                    <div class="col-md-6 mb-4">
                                        <div class="card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center mb-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-2 me-3">
                                                        <i class="bi bi-building" style="font-size: 1.5rem;"></i>
                                                    </div>
                                                    <div>
                                                        <h5 class="mb-0"><?= htmlspecialchars($class['name']) ?></h5>
                                                        <p class="mb-0 text-muted"><?= htmlspecialchars($class['level']) ?></p>
                                                    </div>
                                                </div>
                                                
                                                <div class="d-flex justify-content-between border-top pt-3">
                                                    <div>
                                                        <i class="bi bi-people me-1"></i>
                                                        <?= $class['student_count'] ?> élève<?= $class['student_count'] > 1 ? 's' : '' ?>
                                                    </div>
                                                    <div>
                                                        <span class="badge bg-primary bg-opacity-10 text-primary">
                                                            Année <?= $currentYear ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-footer bg-transparent border-0 pt-0">
                                                <a href="class.php?id=<?= $class['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                                                    <i class="bi bi-eye me-1"></i> Voir la classe
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-exclamation-circle text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3">Onglet non disponible</p>
                            <a href="profileuser.php" class="btn btn-outline-primary">
                                Retour au profil
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>