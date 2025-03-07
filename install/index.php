<?php
/**
 * Installations-Script für das Lizenzverwaltungssystem
 */

// Überprüfen, ob bereits installiert
if (file_exists('../includes/config.php')) {
    // Konfigurationsdatei prüfen
    require_once '../includes/config.php';
    if (defined('INSTALLED') && INSTALLED === true) {
        die('Das System wurde bereits installiert. Aus Sicherheitsgründen wurde diese Seite deaktiviert.');
    }
}

$error = '';
$success = '';

// Wenn das Formular abgeschickt wurde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Datenbankverbindung testen
    $db_host = $_POST['db_host'] ?? '';
    $db_name = $_POST['db_name'] ?? '';
    $db_user = $_POST['db_user'] ?? '';
    $db_pass = $_POST['db_pass'] ?? '';
    $admin_email = $_POST['admin_email'] ?? '';
    $site_url = $_POST['site_url'] ?? '';
    
    if (empty($db_host) || empty($db_name) || empty($db_user) || empty($admin_email) || empty($site_url)) {
        $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
    } else {
        try {
            // Datenbankverbindung testen
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            
            // SQL-Datei einlesen und ausführen
            $sql = file_get_contents('schema.sql');
            $pdo->exec($sql);
            
            // Admin-Benutzer aktualisieren, falls E-Mail geändert wurde
            if ($admin_email != 'admin@example.com') {
                $stmt = $pdo->prepare("UPDATE admin_users SET email = ? WHERE username = 'admin'");
                $stmt->execute([$admin_email]);
            }
            
            // Konfigurationsdatei erstellen
            $config_content = "<?php
/**
 * Konfigurationsdatei für das Lizenzverwaltungssystem
 * Automatisch erstellt während der Installation
 */

// Installationsstatus
define('INSTALLED', true);

// Datenbankverbindung
define('DB_HOST', '{$db_host}');
define('DB_NAME', '{$db_name}');
define('DB_USER', '{$db_user}');
define('DB_PASS', '{$db_pass}');

// Webseitenkonfiguration
define('SITE_URL', '{$site_url}');
define('ADMIN_EMAIL', '{$admin_email}');

// Sicherheitseinstellungen
define('LICENSE_SECRET', '".bin2hex(random_bytes(16))."');

// Sitzungskonfiguration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Auf 0 setzen, wenn kein HTTPS verfügbar ist
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 3600); // 1 Stunde
";
            
            // Konfigurationsdatei schreiben
            if (file_put_contents('../includes/config.php', $config_content)) {
                $success = 'Installation erfolgreich abgeschlossen! Sie können sich jetzt <a href="../index.php">hier anmelden</a> (Benutzername: admin, Passwort: admin123).';
            } else {
                $error = 'Fehler beim Erstellen der Konfigurationsdatei. Bitte stellen Sie sicher, dass das Verzeichnis beschreibbar ist.';
            }
            
        } catch (PDOException $e) {
            $error = 'Datenbankfehler: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - Lizenzverwaltungssystem</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 2rem;
        }
        .install-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="container install-container">
        <div class="header">
            <h1>Lizenzverwaltungssystem</h1>
            <h3>Installation</h3>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php else: ?>
            <form method="post" action="">
                <h4>Datenbankeinstellungen</h4>
                <div class="mb-3">
                    <label for="db_host" class="form-label">Datenbank-Host:</label>
                    <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                </div>
                <div class="mb-3">
                    <label for="db_name" class="form-label">Datenbankname:</label>
                    <input type="text" class="form-control" id="db_name" name="db_name" required>
                </div>
                <div class="mb-3">
                    <label for="db_user" class="form-label">Datenbank-Benutzer:</label>
                    <input type="text" class="form-control" id="db_user" name="db_user" required>
                </div>
                <div class="mb-3">
                    <label for="db_pass" class="form-label">Datenbank-Passwort:</label>
                    <input type="password" class="form-control" id="db_pass" name="db_pass">
                </div>
                
                <h4 class="mt-4">Webseiteneinstellungen</h4>
                <div class="mb-3">
                    <label for="site_url" class="form-label">Website-URL (mit abschließendem /):</label>
                    <input type="url" class="form-control" id="site_url" name="site_url" 
                           value="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}" . preg_replace('#/install/?$#', '/', $_SERVER['REQUEST_URI']) ?>" 
                           required>
                </div>
                <div class="mb-3">
                    <label for="admin_email" class="form-label">Admin E-Mail-Adresse:</label>
                    <input type="email" class="form-control" id="admin_email" name="admin_email" required>
                </div>
                
                <div class="d-grid gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Installieren</button>
                </div>
                
                <div class="mt-3 small text-muted">
                    <p>
                        <strong>Hinweis:</strong> Stellen Sie sicher, dass Sie eine leere Datenbank vorbereitet haben und 
                        der Datenbank-Benutzer über ausreichende Berechtigungen verfügt.
                    </p>
                    <p>
                        Der Standard-Admin-Benutzer wird mit folgenden Anmeldedaten erstellt:<br>
                        Benutzername: <strong>admin</strong><br>
                        Passwort: <strong>admin123</strong><br>
                        <em>Bitte ändern Sie das Passwort nach der Installation umgehend!</em>
                    </p>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>