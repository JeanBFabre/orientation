<?php
// get_likers.php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non authentifié']);
    exit;
}

$commentId = filter_input(INPUT_GET, 'comment_id', FILTER_VALIDATE_INT);
if (!$commentId) {
    echo json_encode(['success' => false, 'error' => 'ID de commentaire invalide']);
    exit;
}

// Récupérer les utilisateurs qui ont aimé le commentaire
$stmt = $conn->prepare("
    SELECT u.username 
    FROM comment_likes cl
    JOIN users u ON cl.user_id = u.id
    WHERE cl.comment_id = ?
    ORDER BY cl.created_at DESC
    LIMIT 10
");
$stmt->bind_param('i', $commentId);
$stmt->execute();
$res = $stmt->get_result();
$likers = [];
while ($row = $res->fetch_assoc()) {
    $likers[] = $row['username'];
}
$stmt->close();

echo json_encode(['success' => true, 'likers' => $likers]);