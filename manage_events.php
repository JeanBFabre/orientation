<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','direction'])) {
    header("Location: login.php");
    exit;
}

// Suppression d'un événement
if (isset($_GET['del_event'])) {
    $eid = intval($_GET['del_event']);
    $conn->query("DELETE FROM events WHERE id = $eid");
    header("Location: manage_events.php");
    exit;
}

// Ajout d'un nouvel événement
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $date = $_POST['date'] ?? '';
    $desc = $_POST['description'] ?? '';
    if (!$title || !$date) {
        $msg = "Titre et date sont requis.";
    } else {
        $stmt = $conn->prepare("INSERT INTO events (title, event_date, description, created_by) VALUES (?, ?, ?, ?)");
        $uid = $_SESSION['user_id'];
        $stmt->bind_param("sssi", $title, $date, $desc, $uid);
        if ($stmt->execute()) {
            $msg = "Événement ajouté.";
        } else {
            $msg = "Erreur lors de l'ajout.";
        }
        $stmt->close();
    }
}

// Charger tous les événements
$events = [];
$res = $conn->query("SELECT id, title, event_date, description FROM events ORDER BY event_date ASC");
if ($res) {
    while ($ev = $res->fetch_assoc()) {
        $events[] = $ev;
    }
}
?>
<?php include 'header.php'; ?>
<div class="row">
  <div class="col-md-7">
    <h2>Événements</h2>
    <table class="table table-hover">
      <tr><th>Date</th><th>Titre</th><th>Description</th><th></th></tr>
      <?php foreach ($events as $ev): ?>
      <tr>
        <td><?php echo htmlspecialchars($ev['event_date']); ?></td>
        <td><?php echo htmlspecialchars($ev['title']); ?></td>
        <td><?php echo htmlspecialchars($ev['description']); ?></td>
        <td>
          <a href="manage_events.php?del_event=<?php echo $ev['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer cet événement ?');">Supprimer</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="col-md-5">
    <h3>Ajouter un événement</h3>
    <?php if ($msg): ?>
      <div class="alert alert-info"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <form method="post" action="manage_events.php">
      <div class="mb-3">
        <label class="form-label">Date de l'événement</label>
        <input type="date" name="date" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Titre</label>
        <input type="text" name="title" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Description (optionnelle)</label>
        <textarea name="description" class="form-control" rows="2"></textarea>
      </div>
      <button type="submit" class="btn btn-primary">Ajouter</button>
    </form>
  </div>
</div>
</div> <!-- fin .container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
