<?php
// comment.php - Gère l'affichage et les interactions de la section commentaires

// Fonction pour formater la date en "Il y a X temps"
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false) {
        $now  = new DateTime;
        $ago  = new DateTime($datetime);
        $diff = $now->diff($ago);

        $weeks = intdiv($diff->d, 7);
        $days  = $diff->d % 7;

        $units = [
            'y' => 'an', 'm' => 'mois', 'w' => 'semaine', 'd' => 'jour',
            'h' => 'heure', 'i' => 'minute', 's' => 'seconde',
        ];
        $plural = [
            'y' => 'ans', 'm' => 'mois', 'w' => 'semaines', 'd' => 'jours',
            'h' => 'heures', 'i' => 'minutes', 's' => 'secondes',
        ];

        $parts = [];
        foreach ($units as $key => $label) {
            if ($key === 'w') $count = $weeks;
            elseif ($key === 'd') $count = $days;
            else $count = $diff->$key;

            if ($count) {
                $unitLabel = $count > 1 ? $plural[$key] : $label;
                $parts[$key] = $count . ' ' . $unitLabel;
            }
        }
        if (!$full) $parts = array_slice($parts, 0, 1);
        return $parts ? 'Il y a ' . implode(', ', $parts) : "à l'instant";
    }
}

// Traitement des formulaires de commentaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ajouter un commentaire principal
    if (isset($_POST['add_comment'])) {
        $txt = trim($_POST['comment'] ?? '');
        if ($txt === '') {
            $error = 'Le commentaire est vide.';
        } else {
            $i = $conn->prepare("INSERT INTO comments (student_id, author_id, author_name, content) VALUES (?, ?, ?, ?)");
            $i->bind_param('iiss', $studentId, $meId, $myName, $txt);
            $i->execute();
            $i->close();
            $success = 'Commentaire ajouté avec succès.';
        }
    }

    // Ajouter une réponse
    if (isset($_POST['add_reply'])) {
        $commentId = filter_input(INPUT_POST, 'comment_id', FILTER_VALIDATE_INT);
        $replyText = trim($_POST['reply_content'] ?? '');
        if ($commentId && $replyText !== '') {
            $stmt = $conn->prepare("INSERT INTO comments (student_id, author_id, author_name, content, parent_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iissi', $studentId, $meId, $myName, $replyText, $commentId);
            $stmt->execute();
            $stmt->close();
            $success = 'Réponse ajoutée avec succès.';
        } else {
            $error = 'La réponse est vide.';
        }
    }

    // Gérer les Likes
    if (isset($_POST['like_comment'])) {
        $commentId = filter_input(INPUT_POST, 'comment_id', FILTER_VALIDATE_INT);
        if ($commentId) {
            $stmt = $conn->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $commentId, $meId);
            $stmt->execute();
            $alreadyLiked = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($alreadyLiked) {
                $stmt = $conn->prepare("DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            } else {
                $stmt = $conn->prepare("INSERT INTO comment_likes (comment_id, user_id) VALUES (?, ?)");
            }
            $stmt->bind_param('ii', $commentId, $meId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Supprimer un commentaire (admin/direction)
    if (isset($_POST['delete_comment']) && in_array($myRole, ['direction','admin'], true)) {
        $cid = intval($_POST['delete_comment']);
        $d = $conn->prepare("DELETE FROM comments WHERE id = ?");
        $d->bind_param('i', $cid);
        $d->execute();
        $d->close();
        $success = 'Commentaire supprimé avec succès.';
    }

    // Modifier un commentaire (admin/direction)
    if (isset($_POST['edit_comment']) && in_array($myRole, ['direction','admin'], true)) {
        $cid = intval($_POST['edit_comment_id']);
        $new = trim($_POST['edit_content'] ?? '');
        if ($new === '') {
            $error = 'Le commentaire ne peut pas être vide.';
        } else {
            $u = $conn->prepare("UPDATE comments SET content = ?, edited = 1, edited_at = NOW() WHERE id = ?");
            $u->bind_param('si', $new, $cid);
            $u->execute();
            $u->close();
            $success = 'Commentaire modifié avec succès.';
        }
    }
}

// Charger tous les commentaires et les likes associés
$stmt = $conn->prepare("
    SELECT
        c.id, c.author_id, c.author_name, c.content, c.created_at,
        c.edited, c.edited_at, c.parent_id,
        (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) AS like_count
    FROM comments c
    WHERE c.student_id = ?
    ORDER BY c.created_at ASC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$comments_flat = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Organiser les commentaires en arbre (principaux et réponses)
$comments = [];
$replies = [];
foreach ($comments_flat as $comment) {
    if ($comment['parent_id']) {
        $replies[$comment['parent_id']][] = $comment;
    } else {
        $comments[$comment['id']] = $comment;
    }
}


// Charger les likes de l'utilisateur connecté
$userLikes = [];
if (!empty($comments_flat)) {
    $idsStr = implode(',', array_column($comments_flat, 'id'));
    $stmt   = $conn->prepare("
        SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN ($idsStr)
    ");
    $stmt->bind_param('i', $meId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $userLikes[$row['comment_id']] = true;
    }
    $stmt->close();
}
?>


<div class="section-card">
  <div class="section-header">
    <span>Commentaires</span>
  </div>
  <div class="section-body comment-section">
    <form method="post" class="comment-form">
      <div class="avatar-comment"><?= strtoupper(substr($myName, 0, 1)) ?></div>
      <div class="comment-input-container">
        <textarea name="comment" class="comment-input" placeholder="Écrire un commentaire..." rows="1"></textarea>
        <div class="d-flex justify-content-end mt-2">
            <button type="submit" name="add_comment" class="comment-submit">Publier</button>
        </div>
      </div>
    </form>
    
    <div class="comment-list">
      <?php if(empty($comments)): ?>
        <p class="text-muted text-center py-2">Aucun commentaire</p>
      <?php else: 
        foreach($comments as $c): 
          $initials = strtoupper(substr($c['author_name'], 0, 1));
          $timeAgo = time_elapsed_string($c['created_at']);
          $isLiked = isset($userLikes[$c['id']]);
      ?>
        <div class="comment-item" id="comment-<?= $c['id'] ?>">
          <div class="avatar-comment"><?= $initials ?></div>
          <div class="comment-content-wrapper">
            <div class="comment-header">
              <div class="comment-author"><?= htmlspecialchars($c['author_name']) ?></div>
              <div class="comment-time"><?= $timeAgo ?></div>
              
              <?php if(in_array($myRole,['direction','admin'],true) || $c['author_id'] == $meId): ?>
                <div class="comment-options">
                  <button class="options-button" onclick="toggleOptionsMenu(event, <?= $c['id'] ?>)">
                    <i class="bi bi-three-dots"></i>
                  </button>
                  <ul class="options-menu" id="options-menu-<?= $c['id'] ?>">
                    <li><button class="option-item" onclick="toggleEdit(<?= $c['id'] ?>)"><i class="bi bi-pencil"></i> Modifier</button></li>
                    <li>
                        <form method="post" style="margin:0;">
                            <button name="delete_comment" value="<?= $c['id'] ?>" class="option-item delete" onclick="return confirm('Supprimer définitivement ce commentaire ?')">
                            <i class="bi bi-trash"></i> Supprimer
                            </button>
                        </form>
                    </li>
                  </ul>
                </div>
              <?php endif; ?>
            </div>
            
            <div class="comment-text" id="content-<?= $c['id'] ?>"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
            
            <?php if($c['edited']): ?>
              <div class="comment-edited"><small>Modifié</small></div>
            <?php endif; ?>
            
            <form method="post" id="editForm-<?= $c['id'] ?>" class="comment-edit-form">
                <textarea name="edit_content" class="edit-comment-input" rows="2"><?= htmlspecialchars($c['content']) ?></textarea>
                <input type="hidden" name="edit_comment_id" value="<?= $c['id'] ?>">
                <div class="edit-buttons">
                    <button type="button" class="edit-cancel" onclick="toggleEdit(<?= $c['id'] ?>)">Annuler</button>
                    <button name="edit_comment" class="edit-submit">Enregistrer</button>
                </div>
            </form>

            <div class="comment-actions">
                <form method="post" class="like-form d-inline-block">
                    <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                    <button type="submit" name="like_comment" class="comment-action like-btn <?= $isLiked ? 'liked' : '' ?>">
                        <i class="bi <?= $isLiked ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                        <span class="like-count"><?= $c['like_count'] ?></span>
                    </button>
                </form>
                <div class="comment-action" onclick="toggleReplyForm(<?= $c['id'] ?>)">
                    <i class="bi bi-reply"></i> Répondre
                </div>
            </div>
            
            <form method="post" class="reply-form" id="reply-form-<?= $c['id'] ?>">
                <div class="d-flex mt-2">
                    <div class="avatar-comment me-2"><?= strtoupper(substr($myName, 0, 1)) ?></div>
                    <div class="flex-grow-1">
                        <textarea name="reply_content" class="comment-input" placeholder="Écrire une réponse..." rows="1"></textarea>
                        <input type="hidden" name="comment_id" value="<?= $c['id'] ?>">
                        <div class="d-flex justify-content-end mt-2">
                            <button type="button" class="btn btn-sm btn-secondary me-2" onclick="toggleReplyForm(<?= $c['id'] ?>)">Annuler</button>
                            <button type="submit" name="add_reply" class="btn btn-sm btn-primary">Répondre</button>
                        </div>
                    </div>
                </div>
            </form>

            <div class="comment-replies">
              <?php if (isset($replies[$c['id']])): 
                foreach($replies[$c['id']] as $r): 
                  $replyInitials = strtoupper(substr($r['author_name'], 0, 1));
                  $replyTimeAgo = time_elapsed_string($r['created_at']);
                  $isReplyLiked = isset($userLikes[$r['id']]);
              ?>
                <div class="reply-item" id="comment-<?= $r['id'] ?>">
                  <div class="avatar-comment"><?= $replyInitials ?></div>
                  <div class="comment-content-wrapper">
                    <div class="comment-header">
                        <div class="comment-author"><?= htmlspecialchars($r['author_name']) ?></div>
                        <div class="comment-time"><?= $replyTimeAgo ?></div>
                    </div>
                    <div class="comment-text"><?= nl2br(htmlspecialchars($r['content'])) ?></div>
                    <div class="comment-actions mt-1">
                      <form method="post" class="like-form d-inline-block">
                        <input type="hidden" name="comment_id" value="<?= $r['id'] ?>">
                        <button type="submit" name="like_comment" class="comment-action like-btn <?= $isReplyLiked ? 'liked' : '' ?>">
                          <i class="bi <?= $isReplyLiked ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                          <span class="like-count"><?= $r['like_count'] ?></span>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
            
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
  function toggleEdit(id) {
    const form = document.getElementById('editForm-' + id);
    const content = document.getElementById('content-' + id);
    if (form && content) {
      form.style.display = (form.style.display === 'block') ? 'none' : 'block';
      content.style.display = (content.style.display === 'none') ? 'inline-block' : 'none';
      if (form.style.display === 'block') form.querySelector('textarea').focus();
    }
  }

  function toggleOptionsMenu(event, id) {
    event.stopPropagation();
    const menu = document.getElementById('options-menu-' + id);
    // Hide all other menus
    document.querySelectorAll('.options-menu').forEach(m => {
        if (m.id !== menu.id) m.style.display = 'none';
    });
    if (menu) {
      menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    }
  }
  
  // Close menus if clicking outside
  window.addEventListener('click', function(e) {
    document.querySelectorAll('.options-menu').forEach(m => {
        m.style.display = 'none';
    });
  });

  function showLikers(commentId, event) {
    fetch('get_likers.php?comment_id=' + commentId)
      .then(response => response.json())
      .then(data => {
        if (data.success && data.likers.length > 0) {
          const tooltip = document.createElement('div');
          tooltip.className = 'likers-tooltip';
          tooltip.innerHTML = '<strong>Aimé par :</strong><br>' + data.likers.join('<br>');
          tooltip.style.display = 'block';
          event.target.closest('.comment-action').appendChild(tooltip);
          
          event.target.closest('.comment-action').addEventListener('mouseleave', () => tooltip.remove(), { once: true });
        }
      });
  }

  function toggleReplyForm(commentId) {
    const form = document.getElementById('reply-form-' + commentId);
    form.style.display = form.style.display === 'block' ? 'none' : 'block';
    if (form.style.display === 'block') {
      form.querySelector('textarea').focus();
    }
  }

  document.querySelectorAll('.comment-input, .edit-comment-input, textarea[name="reply_content"]').forEach(textarea => {
    function autoResize() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    }
    textarea.addEventListener('input', autoResize, false);
    autoResize.call(textarea);
  });
</script>