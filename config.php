<?php
// config.php — connexion DB, session et trimestre courant

$db_host = 'localhost';
$db_user = 'orientation_user';
$db_pass = 'Orient@123';
$db_name = 'orientationdb';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die('Erreur de connexion à la base : ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// DÉBUT DE LA SOLUTION
// 1. Obtenir le décalage actuel de Paris par rapport à UTC
$tz = new DateTimeZone('Europe/Paris');
$now = new DateTime('now', $tz);
$offsetInSeconds = $tz->getOffset($now);

// 2. Formater le décalage en "+HH:MM" ou "-HH:MM" pour MySQL
$offsetHours = intdiv($offsetInSeconds, 3600);
$offsetMinutes = intdiv(abs($offsetInSeconds) % 3600, 60);
$mysqlOffset = sprintf('%+03d:%02d', $offsetHours, $offsetMinutes);

// 3. Appliquer le fuseau horaire à la connexion MySQL
mysqli_query($conn, "SET time_zone = '{$mysqlOffset}'");
// FIN DE LA SOLUTION

$error   = '';
$success = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    if ($message['type'] === 'success') {
        $success = $message['text'];
    } else {
        $error = $message['text'];
    }
    // Effacer le message pour qu'il ne s'affiche qu'une seule fois
    unset($_SESSION['flash_message']);
}

// Charger l’année scolaire
$stmt = $conn->prepare("
  SELECT `value`
    FROM `settings`
   WHERE `name` = 'current_year'
");
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

session_start();
$_SESSION['current_year'] = intval($res['value']);