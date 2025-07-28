<?php
// manage_users.php
require_once 'config.php';

// 1. Seuls admin & direction y ont accès
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','direction'])) {
    header('Location: login.php');
    exit;
}

$msg = '';

// 2. Suppression d'un compte
if (isset($_GET['del_user'])) {
    $uid = intval($_GET['del_user']);

    // Récupérer le rôle de la cible
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();
    $targetRole = $row['role'] ?? '';

    $canDelete = false;
    if ($_SESSION['role'] === 'admin' && $targetRole !== 'admin') {
        $canDelete = true;
    }
    if ($_SESSION['role'] === 'direction' && $targetRole === 'prof') {
        $canDelete = true;
    }

    if ($canDelete) {
        if ($targetRole === 'prof') {
            // Vérifier absence de rattachement à une classe
            $chk = $conn->prepare("SELECT COUNT(*) AS nb FROM classes WHERE teacher_id = ?");
            $chk->bind_param("i", $uid);
            $chk->execute();
            $nb = $chk->get_result()->fetch_assoc()['nb'] ?? 0;
            $chk->close();
            if ($nb > 0) {
                $msg = "Impossible de supprimer ce professeur : il est rattaché à $nb classe(s).";
            } else {
                $del = $conn->prepare("DELETE FROM users WHERE id = ?");
                $del->bind_param("i", $uid);
                $del->execute();
                $del->close();
                $msg = "Compte professeur supprimé.";
            }
        } else {
            // Suppression d'une direction par l'admin
            $del = $conn->prepare("DELETE FROM users WHERE id = ?");
            $del->bind_param("i", $uid);
            $del->execute();
            $del->close();
            $msg = "Compte direction supprimé.";
        }
    }

    header('Location: manage_users.php?msg=' . urlencode($msg));
    exit;
}

// 3. Création d'un compte
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = ($_SESSION['role'] === 'admin') ? 'direction' : 'prof';

    if (strlen($name) < 1 || strlen($username) < 3 || strlen($password) < 3) {
        $msg = "Nom, identifiant et mot de passe doivent contenir au moins 3 caractères.";
    } else {
        // Unicité du username
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $chk->bind_param("s", $username);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $msg = "Identifiant déjà pris.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare("
                INSERT INTO users (username, password, role, name)
                VALUES (?, ?, ?, ?)
            ");
            $ins->bind_param("ssss", $username, $hash, $role, $name);
            if ($ins->execute()) {
                $msg = "Compte $role créé (login : $username).";
            } else {
                $msg = "Erreur lors de la création du compte.";
            }
            $ins->close();
        }
        $chk->close();
    }

    header('Location: manage_users.php?msg=' . urlencode($msg));
    exit;
}

// 4. Lecture du message
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}

// 5. Récupérer les comptes
if ($_SESSION['role'] === 'admin') {
    $sql = "SELECT id, name, username, role
            FROM users
            WHERE role IN ('direction','prof')
            ORDER BY role, name";
} else {
    // direction : forcer role à 'prof'
    $sql = "SELECT id, name, username, 'prof' AS role
            FROM users
            WHERE role = 'prof'
            ORDER BY name";
}
$res = $conn->query($sql);
$users = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

include 'header.php';
?>

<div class="container mt-4">
  <h2>Gestion des comptes</h2>
  <?php if ($msg): ?>
    <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <table class="table table-striped">
    <thead>
      <tr>
        <th>Nom</th>
        <th>Identifiant</th>
        <?php if ($_SESSION['role'] === 'admin'): ?><th>Rôle</th><?php endif; ?>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['name']) ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <?php if ($_SESSION['role'] === 'admin'): ?>
            <td><?= htmlspecialchars($u['role']) ?></td>
          <?php endif; ?>
          <td>
            <?php
              $canDel = false;
              if ($_SESSION['role'] === 'admin' && $u['role'] !== 'admin') {
                $canDel = true;
              }
              if ($_SESSION['role'] === 'direction' && $u['role'] === 'prof') {
                $canDel = true;
              }
            ?>
            <?php if ($canDel): ?>
              <a href="manage_users.php?del_user=<?= $u['id'] ?>"
                 class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('Supprimer le compte <?= addslashes($u['name']) ?> ?');">
                Supprimer
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <hr>

  <h3>Créer un nouveau compte <?= ($_SESSION['role'] === 'admin' ? 'direction' : 'professeur principal') ?></h3>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">Nom et Prénom</label>
      <input type="text" name="name" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Identifiant</label>
      <input type="text" name="username" class="form-control" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Mot de passe initial</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary">Créer le compte</button>
  </form>
</div>

<?php include 'footer.php'; ?>
