<?php
/**
 * Startseite / Login für das Lizenzverwaltungssystem
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once 'includes/config.php';

// Prüfen, ob das System bereits installiert ist
if (!defined('INSTALLED') || INSTALLED !== true) {
    header('Location: install/index.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Abmeldung verarbeiten
if (isset($_GET['logout'])) {
    logout();
    header('Location: index.php?logged_out=1');
    exit;
}

// Wenn bereits eingeloggt, zum Dashboard weiterleiten
if (is_logged_in()) {
    header('Location: admin/index.php');
    exit;
}

// Formularverarbeitung
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Bitte geben Sie Benutzername und Passwort ein.';
    } else {
        // Anmeldung versuchen
        if (authenticate_user($username, $password)) {
            // Umleitung zum Dashboard oder zur ursprünglich angeforderten Seite
            $redirect_url = isset($_SESSION['redirect_url']) ? $_SESSION['redirect_url'] : 'admin/index.php';
            unset($_SESSION['redirect_url']);
            
            header('Location: ' . $redirect_url);
            exit;
        } else {
            $error = 'Ungültiger Benutzername oder Passwort.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lizenzverwaltung - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        body {
            height: 100vh;
            display: flex;
            align-items: center;
            background-color: #f5f5f5;
        }
        
        .form-signin {
            max-width: 400px;
            padding: 15px;
        }
        
        .form-signin .form-floating:focus-within {
            z-index: 2;
        }
        
        .logo {
            width: 120px;
            height: 120px;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body class="text-center">
    <main class="form-signin w-100 m-auto">
        <form method="post" action="">
            <i class="bi bi-key-fill text-primary logo d-block mx-auto"></i>
            <h1 class="h3 mb-3 fw-normal">Lizenzverwaltung</h1>
            
            <?php if (isset($_GET['logged_out'])): ?>
                <div class="alert alert-success">Sie wurden erfolgreich abgemeldet.</div>
            <?php endif; ?>
            
            <?php if (isset($_GET['session_expired'])): ?>
                <div class="alert alert-warning">Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.</div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= html_escape($error) ?></div>
            <?php endif; ?>
            
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="username" name="username" placeholder="Benutzername" required autofocus>
                <label for="username">Benutzername</label>
            </div>
            
            <div class="form-floating mb-3">
                <input type="password" class="form-control" id="password" name="password" placeholder="Passwort" required>
                <label for="password">Passwort</label>
            </div>
            
            <button class="w-100 btn btn-lg btn-primary" type="submit">Anmelden</button>
            
            <p class="mt-4 mb-3 text-muted">
                <small>Lizenzverwaltungssystem &copy; <?= date('Y') ?></small>
            </p>
        </form>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>