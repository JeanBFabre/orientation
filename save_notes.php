<?php
// save_notes.php — API AJAX pour l’autosave des notes d’un élève
require_once 'config.php';
header('Content-Type: application/json');

// 1. Vérifier session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error'=>'Non authentifié']);
    exit;
}

// 2. Lire le JSON envoyé
$data = json_decode(file_get_contents('php://input'), true);
$studentId = isset($data['student_id']) ? (int)$data['student_id'] : 0;
$content   = $data['content'] ?? '';

// 3. Vérifier les droits (professeurs principal / direction / admin)
if (!in_array($_SESSION['role'], ['admin','direction','prof'])) {
    http_response_code(403);
    echo json_encode(['error'=>'Accès refusé']);
    exit;
}

// 4. Mettre à jour
$stmt = $conn->prepare("
  UPDATE students
     SET notes = ?
   WHERE id = ?
");
$stmt->bind_param('si', $content, $studentId);
if ($stmt->execute()) {
    echo json_encode(['success'=>true,'timestamp'=>date('c')]);
} else {
    http_response_code(500);
    echo json_encode(['error'=>'Erreur SQL']);
}
$stmt->close();
