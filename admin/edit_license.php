<?php
/**
 * Lizenz bearbeiten
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Prüfen, ob eine Lizenz-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirect('licenses.php');
}

$license_id = (int)$_GET['id'];

// Lizenzdetails abrufen
$license_stmt = $pdo->prepare("
    SELECT l.*, c.name as customer_name, c.email as customer_email,
           lp.name as plan_name, p.name as product_name
    FROM licenses l
    JOIN customers c ON l.customer_id = c.id
    JOIN license_plans lp ON l.plan_id = lp.id
    JOIN products p ON lp.product_id = p.id
    WHERE l.id = ?
");
$license_stmt->execute([$license_id]);
$license = $license_stmt->fetch();

// Prüfen, ob die Lizenz existiert
if (!$license) {
    redirect('licenses.php');
}

// Daten für Dropdown-Felder laden
$customers = db_query("SELECT id, name, email FROM customers WHERE status = 'active' ORDER BY name");
$plans = db_query("
    SELECT lp.id, lp.name, p.name as product_name, lp.price, lp.duration, lp.max_domains
    FROM license_plans lp
    JOIN products p ON lp.product_id = p.id
    ORDER BY p.name, lp.name
");

// Formularverarbeitung
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Ungültiger CSRF-Token";
    } else {
        $customer_id = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
        $status = isset($_POST['status']) && in_array($_POST['status'], ['active', 'inactive', 'expired']) ? $_POST['status'] : 'active';
        
        // Start- und Enddatum
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        // Validierung
        if ($customer_id <= 0) {
            $errors[] = "Bitte wählen Sie einen Kunden aus.";
        }
        
        if ($plan_id <= 0) {
            $errors[] = "Bitte wählen Sie einen Lizenzplan aus.";
        }
        
        // Wenn keine Fehler aufgetreten sind, Lizenz aktualisieren
        if (empty($errors)) {
            try {
                // Lizenz aktualisieren
                $update_stmt = $pdo->prepare("
                    UPDATE licenses
                    SET customer_id = ?, plan_id = ?, status = ?, start_date = ?, end_date = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $result = $update_stmt->execute([
                    $customer_id,
                    $plan_id,
                    $status,
                    $start_date,
                    $end_date,
                    $license_id
                ]);
                
                if ($result) {
                    // Cache löschen
                    clear_cache('dashboard_stats');
                    
                    // Weiterleitung zur Detailseite
                    redirect("view_license.php?id={$license_id}&updated=1");
                } else {
                    $errors[] = "Fehler beim Aktualisieren der Lizenz.";
                }
            } catch (Exception $e) {
                $errors[] = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Seitentitel festlegen
$page_title = 'Lizenz bearbeiten';

// Header einbinden
include 'header.php';
?>

<?= display_errors($errors) ?>
<?= display_success($success) ?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Lizenz #<?= $license_id ?> bearbeiten</h5>
    </div>
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_field() ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="license_key" class="form-label">Lizenzschlüssel</label>
                    <input type="text" id="license_key" class="form-control" value="<?= html_escape($license['license_key']) ?>" readonly>
                    <div class="form-text">Der Lizenzschlüssel kann nicht geändert werden.</div>
                </div>
                
                <div class="col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="active" <?= $license['status'] == 'active' ? 'selected' : '' ?>>Aktiv</option>
                        <option value="inactive" <?= $license['status'] == 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
                        <option value="expired" <?= $license['status'] == 'expired' ? 'selected' : '' ?>>Abgelaufen</option>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="customer_id" class="form-label">Kunde *</label>
                    <select id="customer_id" name="customer_id" class="form-select" required>
                        <option value="">-- Kunden auswählen --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>" <?= $license['customer_id'] == $customer['id'] ? 'selected' : '' ?>>
                                <?= html_escape($customer['name']) ?> (<?= html_escape($customer['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label for="plan_id" class="form-label">Lizenzplan *</label>
                    <select id="plan_id" name="plan_id" class="form-select" required>
                        <option value="">-- Plan auswählen --</option>
                        <?php foreach ($plans as $plan): ?>
                            <option value="<?= $plan['id'] ?>" <?= $license['plan_id'] == $plan['id'] ? 'selected' : '' ?>
                                    data-duration="<?= $plan['duration'] ?>" data-price="<?= $plan['price'] ?>">
                                <?= html_escape($plan['product_name']) ?> - <?= html_escape($plan['name']) ?> 
                                (<?= $plan['price'] ?> €, <?= $plan['duration'] > 0 ? $plan['duration'] . ' Tage' : 'Unbegrenzt' ?>, <?= $plan['max_domains'] ?> Domain<?= $plan['max_domains'] > 1 ? 's' : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="start_date" class="form-label">Startdatum</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= date('Y-m-d', strtotime($license['start_date'])) ?>">
                </div>
                
                <div class="col-md-6">
                    <label for="end_date" class="form-label">Enddatum</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= $license['end_date'] ? date('Y-m-d', strtotime($license['end_date'])) : '' ?>">
                    <div class="form-text">Leer lassen für eine unbegrenzte Lizenz.</div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <a href="view_license.php?id=<?= $license_id ?>" class="btn btn-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-primary">Änderungen speichern</button>
            </div>
        </form>
    </div>
</div>

<?php
// Footer einbinden
?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>