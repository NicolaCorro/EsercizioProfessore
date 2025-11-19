<?php
// Includiamo config.php che contiene session_name('PAY_STEAM_SESSION') e session_start()
require_once 'config.php';

// Svuota l'array di sessione
$_SESSION = array();

// Distruggi la sessione
session_destroy();

// Cancella anche il cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect alla homepage
header('Location: index.php');
exit();
?>