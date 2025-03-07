<?php
/**
 * Neue Lizenz erstellen
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';
require_once '../includes/license_generator.php';

// Seitentitel festlegen
$page_title = 'Neue Lizenz erstellen';

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
        $license_key = !empty($_POST['license_key']) ? trim($_POST['license_key']) : null;
        $status = isset($_POST['status']) && in_array($_POST['status'], ['active', 'inactive']) ? $_POST['status'] : 'active';
        
        // Start- und Enddatum
        $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        
        // Benutzerdefiniertes Enddatum
        $custom_duration = isset($_POST['custom_duration']) && $_POST['custom_duration'] === '1';
        
        // Validierung
        if ($customer_id <= 0) {
            $errors[] = "Bitte wählen Sie einen Kunden aus.";
        }
        
        if ($plan_id <= 0) {
            $errors[] = "Bitte wählen Sie einen Lizenzplan aus.";
        }
        
        // Wenn keine Fehler aufgetreten sind, Lizenz erstellen
        if (empty($errors)) {
            try {
                // Planlaufzeit abrufen, wenn kein benutzerdefiniertes Enddatum verwendet wird
                if (!$custom_duration) {
                    $plan_stmt = $pdo->prepare("SELECT duration FROM license_plans WHERE id = ?");
                    $plan_stmt->execute([$plan_id]);
                    $plan = $plan_stmt->fetch();
                    
                    if ($plan && $plan['duration'] > 0) {
                        $end_date = date('Y-m-d', strtotime($start_date . " + {$plan['duration']} days"));
                    } else {
                        $end_date = null; // Unbegrenzt
                    }
                }
                
                // Lizenz erstellen
                $license_id = create_license($customer_id, $plan_id, $license_key, $start_date, $end_date, $status);
                
                if ($license_id) {
                    // Cache löschen
                    clear_cache('dashboard_stats');
                    
                    // Erfolgsmeldung
                    $success = "Lizenz erfolgreich erstellt.";
                    
                    // Weiterleitung zur Detailseite
                    redirect("view_license.php?id={$license_id}&created=1");
                } else {
                    $errors[] = "Fehler beim Erstellen der Lizenz.";
                }
            } catch (Exception $e) {
                $errors[] = "Fehler: " . $e->getMessage();
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
                    <label for="customer_id" class="form-label">Kunde *</label>
                    <select id="customer_id" name="customer_id" class="form-select" required>
                        <option value="">-- Kunden auswählen --</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>" <?= (isset($_POST['customer_id']) && $_POST['customer_id'] == $customer['id']) ? 'selected' : '' ?>>
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
                            <option value="<?= $plan['id'] ?>" <?= (isset($_POST['plan_id']) && $_POST['plan_id'] == $plan['id']) ? 'selected' : '' ?> 
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
                    <label for="license_key" class="form-label">Lizenzschlüssel (optional)</label>
                    <div class="input-group">
                        <input type="text" id="license_key" name="license_key" class="form-control" value="<?= isset($_POST['license_key']) ? html_escape($_POST['license_key']) : '' ?>" placeholder="Wird automatisch generiert, wenn leer">
                        <button type="button" class="btn btn-outline-secondary" id="generate_key">Generieren</button>
                    </div>
                    <div class="form-text">Lassen Sie dieses Feld leer, um automatisch einen Schlüssel zu generieren.</div>
                </div>
                
                <div class="col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="active" <?= (!isset($_POST['status']) || $_POST['status'] == 'active') ? 'selected' : '' ?>>Aktiv</option>
                        <option value="inactive" <?= (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : '' ?>>Inaktiv</option>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="start_date" class="form-label">Startdatum</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= isset($_POST['start_date']) ? html_escape($_POST['start_date']) : date('Y-m-d') ?>">
                </div>
                
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input type="checkbox" id="custom_duration" name="custom_duration" value="1" class="form-check-input" <?= isset($_POST['custom_duration']) ? 'checked' : '' ?>>
                        <label for="custom_duration" class="form-check-label">Benutzerdefiniertes Enddatum</label>
                    </div>
                    
                    <div id="end_date_container" style="<?= isset($_POST['custom_duration']) ? '' : 'display: none;' ?>">
                        <label for="end_date" class="form-label">Enddatum</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= isset($_POST['end_date']) ? html_escape($_POST['end_date']) : '' ?>">
                        <div class="form-text">Leer lassen für eine unbegrenzte Lizenz.</div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <a href="licenses.php" class="btn btn-secondary">Abbrechen</a>
                <button type="submit" class="btn btn-primary">Lizenz erstellen</button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript für Formularinteraktionen -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Lizenzschlüssel-Generator
    document.getElementById('generate_key').addEventListener('click', function() {
        // Einfacher Zufallsschlüssel mit 4 Segmenten à 4 Zeichen (XXXX-XXXX-XXXX-XXXX)
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let key = '';
        
        for (let s = 0; s < 4; s++) {
            for (let i = 0; i < 4; i++) {
                key += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            if (s < 3) {
                key += '-';
            }
        }
        
        document.getElementById('license_key').value = key;
    });
    
    // Benutzerdefiniertes Enddatum ein-/ausblenden
    document.getElementById('custom_duration').addEventListener('change', function() {
        const endDateContainer = document.getElementById('end_date_container');
        endDateContainer.style.display = this.checked ? 'block' : 'none';
    });
    
    // Plan-Änderung verarbeiten
    document.getElementById('plan_id').addEventListener('change', function() {
        if (!document.getElementById('custom_duration').checked) {
            const selectedOption = this.options[this.selectedIndex];
            const duration = selectedOption.getAttribute('data-duration');
            
            if (duration > 0) {
                const startDate = new Date(document.getElementById('start_date').value);
                const endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + parseInt(duration));
                
                // Formatiere das Datum als YYYY-MM-DD
                const formattedEndDate = endDate.toISOString().split('T')[0];
                document.getElementById('end_date').value = formattedEndDate;
            } else {
                document.getElementById('end_date').value = '';
            }
        }
    });
    
    // Startdatum-Änderung verarbeiten
    document.getElementById('start_date').addEventListener('change', function() {
        if (!document.getElementById('custom_duration').checked) {
            const planSelect = document.getElementById('plan_id');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            
            if (selectedOption.value) {
                const duration = selectedOption.getAttribute('data-duration');
                
                if (duration > 0) {
                    const startDate = new Date(this.value);
                    const endDate = new Date(startDate);
                    endDate.setDate(startDate.getDate() + parseInt(duration));
                    
                    // Formatiere das Datum als YYYY-MM-DD
                    const formattedEndDate = endDate.toISOString().split('T')[0];
                    document.getElementById('end_date').value = formattedEndDate;
                }
            }
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