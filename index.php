<?php
// index.php — redirige automatiquement vers login ou dashboard
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;