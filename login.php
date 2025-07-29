<?php
// login.php — page de connexion sans header pour éviter les boucles de redirection
require_once 'config.php';
session_start();

// 1. Si déjà connecté, rediriger vers dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$username = '';

// 2. Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Veuillez renseigner votre identifiant et mot de passe.';
    } else {
        // Rechercher l'utilisateur
        $stmt = $conn->prepare("
          SELECT id, username, password, role, name
            FROM users
           WHERE username = ?
           LIMIT 1
        ");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Identifiant ou mot de passe incorrect.';
        } else {
            // Authentification réussie
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            $_SESSION['name']     = $user['name'];
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Connexion — Suivi Orientation | Lycée Saint Elme</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <div class="login-container">
    <div class="login-card">
      <div class="login-header">
        <img src="https://www.stelme.fr/wp-content/uploads/2020/06/logo.png" alt="Logo Lycée Saint Elme" class="login-logo">
        <h2 class="login-title">Suivi d'Orientation</h2>
        <div class="login-subtitle">Plateforme professionnelle de suivi des élèves</div>
      </div>
      
      <div class="login-body">
        <h3 class="card-title text-center mb-4">Connexion à votre espace</h3>
        
        <?php if ($error): ?>
          <div class="alert alert-danger d-flex align-items-center">
            <i class="bi bi-exclamation-circle-fill me-2"></i>
            <div><?= htmlspecialchars($error) ?></div>
          </div>
        <?php endif; ?>
        
        <form method="post">
          <div class="floating-label mb-4">
            <input type="text" name="username" id="username" 
                   class="form-control ps-4" placeholder=" "
                   value="<?= htmlspecialchars($username) ?>" autofocus>
            <label for="username"><i class="bi bi-person-fill me-2"></i>Identifiant</label>
            <i class="bi bi-person password-toggle"></i>
          </div>
          
          <div class="floating-label mb-4 position-relative">
            <input type="password" name="password" id="password" 
                   class="form-control ps-4" placeholder=" " autocomplete="current-password">
            <label for="password"><i class="bi bi-lock-fill me-2"></i>Mot de passe</label>
            <i class="bi bi-eye password-toggle" id="togglePassword"></i>
          </div>
          
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="remember">
              <label class="form-check-label small" for="remember">Se souvenir de moi</label>
            </div>
            <a href="#" class="forgot-link">Mot de passe oublié ?</a>
          </div>
          
          <button type="submit" class="btn btn-login w-100 py-3 fw-bold">
            <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
          </button>
        </form>
      </div>
      
      <div class="login-footer">
        © Lycée Saint Elme • <?= date('Y') ?> • Tous droits réservés
      </div>
    </div>
  </div>

  <script>
    // Fonction pour basculer la visibilité du mot de passe
    document.getElementById('togglePassword').addEventListener('click', function() {
      const passwordInput = document.getElementById('password');
      const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
      passwordInput.setAttribute('type', type);
      
      // Changer l'icône
      this.classList.toggle('bi-eye');
      this.classList.toggle('bi-eye-slash');
    });
    
    // Focus sur le premier champ vide
    document.addEventListener('DOMContentLoaded', function() {
      const username = document.getElementById('username');
      const password = document.getElementById('password');
      
      if (username.value === '') {
        username.focus();
      } else {
        password.focus();
      }
    });
  </script>
</body>
</html>