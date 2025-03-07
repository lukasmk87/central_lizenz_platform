<?php
/**
 * Neuen Kunden erstellen
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Seitentitel festlegen
$page_title = 'Neuen Kunden erstellen';

// Formularverarbeitung
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Ungültiger CSRF-Token";
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $password = $_POST['password'] ?? '';
        $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';
        
        // Validierung
        if (empty($name)) {
            $errors[] = "Der Name darf nicht leer sein.";
        }
        
        if (empty($email)) {
            $errors[] = "Die E-Mail-Adresse darf nicht leer sein.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Die E-Mail-Adresse ist ungültig.";
        } else {
            // Prüfen, ob die E-Mail-Adresse bereits existiert
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Diese E-Mail-Adresse ist bereits registriert.";
            }
        }
        
        if (empty($password)) {
            $errors[] = "Das Passwort darf nicht leer sein.";
        } elseif (strlen($password) < 8) {
            $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
        }
        
        // Wenn keine Fehler aufgetreten sind, Kunde erstellen
        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO customers (name, email, password, company, status) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            try {
                $result = $stmt->execute([$name, $email, $password_hash, $company, $status]);
                
                if ($result) {
                    $customer_id = $pdo->lastInsertId();
                    
                    // Cache löschen
                    clear_cache('dashboard_stats');
                    
                    // Erfolg und Weiterleitung
                    redirect("customers.php?created=1");
                } else {
                    $errors[] = "Fehler beim Erstellen des Kunden.";
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $errors[] = "Diese E-Mail-Adresse ist bereits registriert.";
                } else {
                    $errors[] = "Datenbankfehler: " . $e->getMessage();
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

<div class="card">
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_field() ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name *</label>
                    <input type="text" id="name" name="name" class="form-control" required value="<?= isset($_POST['name']) ? html_escape($_POST['name']) : '' ?>">
                </div>
                
                <div class="col-md-6">
                    <label for="email" class="form-label">E-Mail-Adresse *</label>
                    <input type="email" id="email" name="email" class="form-control" required value="<?= isset($_POST['email']) ? html_escape($_POST['email']) : '' ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="password" class="form-label">Passwort *</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control" required>
                        <button type="button" class="btn btn-outline-secondary" id="generate_password">Generieren</button>
                        <button type="button" class="btn btn-outline-secondary" id="toggle_password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">Das Passwort muss mindestens 8 Zeichen lang sein.</div>
                </div>
                
                <div class="col-md-6">
                    <label for="company" class="form-label">Firma</label>
                    <input type="text" id="company" name="company" class="form-control" value="<?= isset($_POST['company']) ? html_escape($_POST['company']) : '' ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="active" <?= (!isset($_POST['status']) || $_POST['status'] === 'active') ? 'selected' : '' ?>>Aktiv</option>
                        <option value="inactive" <?= (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : '' ?>>Inaktiv</option>
                    </select>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <a href="customers.php" class="btn btn-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-primary">Kunde erstellen</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Passwort-Generator
    document.getElementById('generate_password').addEventListener('click', function() {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+';
        let password = '';
        
        // Mindestens ein Großbuchstabe, ein Kleinbuchstabe, eine Zahl und ein Sonderzeichen
        password += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)];
        password += 'abcdefghijklmnopqrstuvwxyz'[Math.floor(Math.random() * 26)];
        password += '0123456789'[Math.floor(Math.random() * 10)];
        password += '!@#$%^&*()-_=+'[Math.floor(Math.random() * 14)];
        
        // Restliche Zeichen zufällig hinzufügen
        for (let i = 0; i < 8; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        
        // Zeichen mischen
        password = password.split('').sort(() => 0.5 - Math.random()).join('');
        
        document.getElementById('password').value = password;
        document.getElementById('password').type = 'text';
        document.getElementById('toggle_password').innerHTML = '<i class="bi bi-eye-slash"></i>';
    });
    
    // Passwort ein-/ausblenden
    document.getElementById('toggle_password').addEventListener('click', function() {
        const passwordField = document.getElementById('password');
        
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            this.innerHTML = '<i class="bi bi-eye-slash"></i>';
        } else {
            passwordField.type = 'password';
            this.innerHTML = '<i class="bi bi-eye"></i>';
        }
    });
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