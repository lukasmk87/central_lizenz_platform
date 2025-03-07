<?php
/**
 * Administratorprofil verwalten
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Sicherstellen, dass der Benutzer eingeloggt ist
require_login();

// Benutzerinformationen abrufen
$user = get_user();

// Seitentitel festlegen
$page_title = 'Mein Profil';

// Formularverarbeitung
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Ungültiger CSRF-Token";
    } else {
        $action = $_POST['action'] ?? '';
        
        // Passwort ändern
        if ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            // Validierung
            if (empty($current_password)) {
                $errors[] = "Bitte geben Sie Ihr aktuelles Passwort ein.";
            }
            
            if (empty($new_password)) {
                $errors[] = "Bitte geben Sie ein neues Passwort ein.";
            } elseif (strlen($new_password) < 8) {
                $errors[] = "Das neue Passwort muss mindestens 8 Zeichen lang sein.";
            }
            
            if ($new_password !== $confirm_password) {
                $errors[] = "Die Passwörter stimmen nicht überein.";
            }
            
            // Prüfen, ob das aktuelle Passwort korrekt ist
            if (empty($errors)) {
                $stmt = $pdo->prepare("SELECT password FROM admin_users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user_data = $stmt->fetch();
                
                if (!$user_data || !password_verify($current_password, $user_data['password'])) {
                    $errors[] = "Das aktuelle Passwort ist nicht korrekt.";
                } else {
                    // Passwort ändern
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("
                        UPDATE admin_users 
                        SET password = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    try {
                        $result = $stmt->execute([$password_hash, $_SESSION['user_id']]);
                        
                        if ($result) {
                            $success = "Passwort erfolgreich geändert.";
                        } else {
                            $errors[] = "Fehler beim Ändern des Passworts.";
                        }
                    } catch (PDOException $e) {
                        $errors[] = "Datenbankfehler: " . $e->getMessage();
                    }
                }
            }
        }
        
        // Profilinformationen aktualisieren
        elseif ($action === 'update_profile') {
            $email = trim($_POST['email'] ?? '');
            
            // Validierung
            if (empty($email)) {
                $errors[] = "Die E-Mail-Adresse darf nicht leer sein.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Die angegebene E-Mail-Adresse ist ungültig.";
            } else {
                // Prüfen, ob die E-Mail-Adresse bereits von einem anderen Benutzer verwendet wird
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $_SESSION['user_id']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "Diese E-Mail-Adresse wird bereits verwendet.";
                }
            }
            
            // Wenn keine Fehler aufgetreten sind, Profil aktualisieren
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    UPDATE admin_users 
                    SET email = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                try {
                    $result = $stmt->execute([$email, $_SESSION['user_id']]);
                    
                    if ($result) {
                        // Aktualisierte Benutzerdaten abrufen
                        $user = get_user();
                        
                        $success = "Profil erfolgreich aktualisiert.";
                    } else {
                        $errors[] = "Fehler beim Aktualisieren des Profils.";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $errors[] = "Diese E-Mail-Adresse wird bereits verwendet.";
                    } else {
                        $errors[] = "Datenbankfehler: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Header einbinden
include 'header.php';
?>

<?= display_errors($errors) ?>
<?= display_success($success) ?>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Profilinformationen</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Benutzername</label>
                        <input type="text" id="username" class="form-control" value="<?= html_escape($user['username']) ?>" readonly>
                        <div class="form-text">Der Benutzername kann nicht geändert werden.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">E-Mail-Adresse *</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= html_escape($user['email']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Letzte Anmeldung</label>
                        <input type="text" class="form-control" value="<?= $user['last_login'] ? format_date($user['last_login'], true) : 'Nie' ?>" readonly>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Profil aktualisieren</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Passwort ändern</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Aktuelles Passwort *</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Neues Passwort *</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8">
                        <div class="form-text">Das Passwort muss mindestens 8 Zeichen lang sein.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Passwort bestätigen *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Passwort ändern</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password Confirmation Validation
    const password = document.getElementById('new_password');
    const confirm = document.getElementById('confirm_password');
    
    function validatePassword() {
        if (password.value != confirm.value) {
            confirm.setCustomValidity('Die Passwörter stimmen nicht überein.');
        } else {
            confirm.setCustomValidity('');
        }
    }
    
    password.addEventListener('change', validatePassword);
    confirm.addEventListener('keyup', validatePassword);
});
</script>

<?php
// Footer einbinden
?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>