<?php
/**
 * Kunden bearbeiten
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Prüfen, ob eine Kunden-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirect('customers.php');
}

$customer_id = (int)$_GET['id'];

// Kundendetails abrufen
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

// Prüfen, ob der Kunde existiert
if (!$customer) {
    redirect('customers.php');
}

// Seitentitel festlegen
$page_title = 'Kunden bearbeiten';

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
            // Prüfen, ob die E-Mail-Adresse bereits einem anderen Kunden gehört
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE email = ? AND id != ?");
            $stmt->execute([$email, $customer_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Diese E-Mail-Adresse ist bereits registriert.";
            }
        }
        
        // Passwort-Validierung nur, wenn ein neues Passwort eingegeben wurde
        if (!empty($password) && strlen($password) < 8) {
            $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
        }
        
        // Wenn keine Fehler aufgetreten sind, Kunde aktualisieren
        if (empty($errors)) {
            // SQL-Abfrage vorbereiten
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE customers 
                    SET name = ?, email = ?, password = ?, company = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $params = [$name, $email, $password_hash, $company, $status, $customer_id];
            } else {
                $stmt = $pdo->prepare("
                    UPDATE customers 
                    SET name = ?, email = ?, company = ?, status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $params = [$name, $email, $company, $status, $customer_id];
            }
            
            try {
                $result = $stmt->execute($params);
                
                if ($result) {
                    // Cache löschen
                    clear_cache('dashboard_stats');
                    
                    // Erfolg und aktualisierte Daten laden
                    $success = "Kundendaten erfolgreich aktualisiert.";
                    
                    // Aktualisierte Daten laden
                    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
                    $stmt->execute([$customer_id]);
                    $customer = $stmt->fetch();
                } else {
                    $errors[] = "Fehler beim Aktualisieren des Kunden.";
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

// Lizenzen des Kunden abrufen
$license_stmt = $pdo->prepare("
    SELECT l.*, p.name as product_name, lp.name as plan_name
    FROM licenses l
    JOIN license_plans lp ON l.plan_id = lp.id
    JOIN products p ON lp.product_id = p.id
    WHERE l.customer_id = ?
    ORDER BY l.created_at DESC
    LIMIT 10
");
$license_stmt->execute([$customer_id]);
$licenses = $license_stmt->fetchAll();

// Header einbinden
include 'header.php';
?>

<?= display_errors($errors) ?>
<?= display_success($success) ?>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Kundendaten bearbeiten</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <?= csrf_field() ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Name *</label>
                            <input type="text" id="name" name="name" class="form-control" required value="<?= html_escape($customer['name']) ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="email" class="form-label">E-Mail-Adresse *</label>
                            <input type="email" id="email" name="email" class="form-control" required value="<?= html_escape($customer['email']) ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label">Passwort <small class="text-muted">(leer lassen, um nicht zu ändern)</small></label>
                            <div class="input-group">
                                <input type="password" id="password" name="password" class="form-control">
                                <button type="button" class="btn btn-outline-secondary" id="generate_password">Generieren</button>
                                <button type="button" class="btn btn-outline-secondary" id="toggle_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Das Passwort muss mindestens 8 Zeichen lang sein.</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="company" class="form-label">Firma</label>
                            <input type="text" id="company" name="company" class="form-control" value="<?= html_escape($customer['company'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>>Aktiv</option>
                                <option value="inactive" <?= $customer['status'] === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Erstellt am</label>
                            <input type="text" class="form-control" value="<?= format_date($customer['created_at'], true) ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="customers.php" class="btn btn-secondary">Zurück zur Übersicht</a>
                        <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Kundenstatistik</h5>
            </div>
            <div class="card-body">
                <?php
                // Statistiken berechnen
                $total_licenses_stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE customer_id = ?");
                $total_licenses_stmt->execute([$customer_id]);
                $total_licenses = $total_licenses_stmt->fetchColumn();
                
                $active_licenses_stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE customer_id = ? AND status = 'active'");
                $active_licenses_stmt->execute([$customer_id]);
                $active_licenses = $active_licenses_stmt->fetchColumn();
                
                $expired_licenses_stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE customer_id = ? AND status = 'expired'");
                $expired_licenses_stmt->execute([$customer_id]);
                $expired_licenses = $expired_licenses_stmt->fetchColumn();
                ?>
                
                <div class="mb-3">
                    <strong>Gesamtzahl Lizenzen:</strong> <?= $total_licenses ?>
                </div>
                
                <div class="mb-3">
                    <strong>Aktive Lizenzen:</strong> <?= $active_licenses ?>
                </div>
                
                <div class="mb-3">
                    <strong>Abgelaufene Lizenzen:</strong> <?= $expired_licenses ?>
                </div>
                
                <hr>
                
                <div class="d-grid">
                    <a href="create_license.php?customer_id=<?= $customer_id ?>" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Neue Lizenz erstellen
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Lizenzen</h5>
                <?php if (count($licenses) > 0): ?>
                    <a href="licenses.php?search=<?= urlencode($customer['email']) ?>" class="btn btn-sm btn-outline-primary">Alle anzeigen</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($licenses)): ?>
                    <div class="alert alert-info">Dieser Kunde hat noch keine Lizenzen.</div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($licenses as $license): ?>
                            <a href="view_license.php?id=<?= $license['id'] ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= html_escape($license['product_name']) ?></h6>
                                    <span class="badge bg-<?= get_status_color($license['status']) ?>">
                                        <?= get_status_label($license['status']) ?>
                                    </span>
                                </div>
                                <p class="mb-1 small text-truncate"><?= html_escape($license['license_key']) ?></p>
                                <small>
                                    <?= html_escape($license['plan_name']) ?> - 
                                    <?= $license['end_date'] ? format_date($license['end_date']) : 'Unbegrenzt' ?>
                                </small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
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