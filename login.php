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
  <style>
    :root {
      --primary-color: #0d6efd;
      --secondary-color: #6c757d;
      --admin-color: #dc3545;
      --direction-color: #198754;
      --prof-color: #6610f2;
    }
    
    body {
      background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    
    .login-container {
      max-width: 450px;
      width: 100%;
      margin: 0 auto;
    }
    
    .login-card {
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
      border: none;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .login-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
    }
    
    .login-header {
      background: var(--primary-color);
      padding: 30px 20px;
      text-align: center;
      color: white;
    }
    
    .login-logo {
      max-height: 70px;
      margin-bottom: 15px;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15));
    }
    
    .login-title {
      font-weight: 600;
      letter-spacing: 0.5px;
      margin-bottom: 5px;
      text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }
    
    .login-subtitle {
      font-size: 0.95rem;
      opacity: 0.9;
      font-weight: 400;
    }
    
    .login-body {
      padding: 30px;
      background: white;
    }
    
    .form-control {
      padding: 12px 15px;
      border-radius: 10px;
      border: 1px solid #dee2e6;
      transition: all 0.3s;
    }
    
    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }
    
    .input-group-text {
      background: #f8f9fa;
      border-radius: 10px 0 0 10px !important;
      border: 1px solid #dee2e6;
      border-right: none;
      padding: 0 15px;
    }
    
    .btn-login {
      background: var(--primary-color);
      border: none;
      padding: 12px;
      font-weight: 600;
      letter-spacing: 0.5px;
      border-radius: 10px;
      transition: all 0.3s;
      box-shadow: 0 4px 6px rgba(13, 110, 253, 0.3);
    }
    
    .btn-login:hover {
      background: #0b5ed7;
      transform: translateY(-2px);
      box-shadow: 0 6px 10px rgba(13, 110, 253, 0.4);
    }
    
    .btn-login:active {
      transform: translateY(0);
    }
    
    .forgot-link {
      color: var(--secondary-color);
      text-decoration: none;
      font-size: 0.9rem;
      transition: color 0.2s;
    }
    
    .forgot-link:hover {
      color: var(--primary-color);
      text-decoration: underline;
    }
    
    .login-footer {
      background: #f8f9fa;
      padding: 15px;
      text-align: center;
      color: #6c757d;
      font-size: 0.85rem;
      border-top: 1px solid #e9ecef;
    }
    
    .floating-label {
      position: relative;
      margin-bottom: 20px;
    }
    
    .floating-label label {
      position: absolute;
      top: 15px;
      left: 15px;
      color: #6c757d;
      transition: all 0.3s;
      pointer-events: none;
      font-size: 1rem;
    }
    
    .floating-label input:focus ~ label,
    .floating-label input:not(:placeholder-shown) ~ label {
      top: -10px;
      left: 10px;
      background: white;
      padding: 0 5px;
      font-size: 0.85rem;
      color: var(--primary-color);
      font-weight: 500;
    }
    
    .password-toggle {
      position: absolute;
      right: 15px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: #6c757d;
      z-index: 5;
    }
  </style>
</head>
<body>
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