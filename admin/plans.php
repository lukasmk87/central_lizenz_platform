<?php
/**
 * Verwaltung von Lizenzplänen
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Seitentitel festlegen
$page_title = 'Lizenzpläne';

// Produkt-ID für Filterung
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

// Formularverarbeitung
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Ungültiger CSRF-Token";
    } else {
        $action = $_POST['action'] ?? '';
        
        // Plan erstellen
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $product_id = (int)$_POST['product_id'];
            $duration = (int)$_POST['duration'];
            $max_domains = (int)$_POST['max_domains'];
            $price = floatval(str_replace(',', '.', $_POST['price']));
            $features = $_POST['features'] ?? '';
            
            // Features in Array umwandeln
            $features_array = [];
            if (!empty($features)) {
                $features_array = array_map('trim', explode(',', $features));
                $features_array = array_filter($features_array);
            }
            
            // Validierung
            if (empty($name)) {
                $errors[] = "Der Planname darf nicht leer sein.";
            }
            
            if ($product_id <= 0) {
                $errors[] = "Bitte wählen Sie ein Produkt aus.";
            }
            
            if ($max_domains <= 0) {
                $errors[] = "Die maximale Anzahl an Domains muss mindestens 1 sein.";
            }
            
            if ($price < 0) {
                $errors[] = "Der Preis darf nicht negativ sein.";
            }
            
            // Wenn keine Fehler aufgetreten sind, Plan erstellen
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    INSERT INTO license_plans (product_id, name, duration, max_domains, price, features)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                try {
                    $result = $stmt->execute([
                        $product_id,
                        $name,
                        $duration,
                        $max_domains,
                        $price,
                        !empty($features_array) ? json_encode($features_array) : null
                    ]);
                    
                    if ($result) {
                        // Cache löschen
                        clear_cache('dashboard_stats');
                        
                        // Erfolgsmeldung
                        $success = "Lizenzplan erfolgreich erstellt.";
                        
                        // Formularfelder zurücksetzen
                        $_POST = [];
                    } else {
                        $errors[] = "Fehler beim Erstellen des Lizenzplans.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Datenbankfehler: " . $e->getMessage();
                }
            }
        }
        
        // Plan aktualisieren
        elseif ($action === 'update') {
            $plan_id = (int)$_POST['plan_id'];
            $name = trim($_POST['name'] ?? '');
            $duration = (int)$_POST['duration'];
            $max_domains = (int)$_POST['max_domains'];
            $price = floatval(str_replace(',', '.', $_POST['price']));
            $features = $_POST['features'] ?? '';
            
            // Features in Array umwandeln
            $features_array = [];
            if (!empty($features)) {
                $features_array = array_map('trim', explode(',', $features));
                $features_array = array_filter($features_array);
            }
            
            // Validierung
            if (empty($name)) {
                $errors[] = "Der Planname darf nicht leer sein.";
            }
            
            if ($max_domains <= 0) {
                $errors[] = "Die maximale Anzahl an Domains muss mindestens 1 sein.";
            }
            
            if ($price < 0) {
                $errors[] = "Der Preis darf nicht negativ sein.";
            }
            
            // Wenn keine Fehler aufgetreten sind, Plan aktualisieren
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    UPDATE license_plans
                    SET name = ?, duration = ?, max_domains = ?, price = ?, features = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                try {
                    $result = $stmt->execute([
                        $name,
                        $duration,
                        $max_domains,
                        $price,
                        !empty($features_array) ? json_encode($features_array) : null,
                        $plan_id
                    ]);
                    
                    if ($result) {
                        // Cache löschen
                        clear_cache('dashboard_stats');
                        
                        // Erfolgsmeldung
                        $success = "Lizenzplan erfolgreich aktualisiert.";
                    } else {
                        $errors[] = "Fehler beim Aktualisieren des Lizenzplans.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Datenbankfehler: " . $e->getMessage();
                }
            }
        }
        
        // Plan löschen
        elseif ($action === 'delete') {
            $plan_id = (int)$_POST['plan_id'];
            
            // Prüfen, ob es Lizenzen für diesen Plan gibt
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE plan_id = ?");
            $stmt->execute([$plan_id]);
            $license_count = $stmt->fetchColumn();
            
            if ($license_count > 0) {
                $errors[] = "Dieser Lizenzplan kann nicht gelöscht werden, da es aktive Lizenzen gibt.";
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM license_plans WHERE id = ?");
                    $result = $stmt->execute([$plan_id]);
                    
                    if ($result) {
                        // Cache löschen
                        clear_cache('dashboard_stats');
                        
                        // Erfolgsmeldung
                        $success = "Lizenzplan erfolgreich gelöscht.";
                    } else {
                        $errors[] = "Fehler beim Löschen des Lizenzplans.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Datenbankfehler: " . $e->getMessage();
                }
            }
        }
    }
}

// Produkte für Dropdown laden
$products = db_query("SELECT id, name FROM products ORDER BY name");

// SQL für die Planabfrage vorbereiten
$sql = "
    SELECT lp.*, p.name as product_name, 
           (SELECT COUNT(*) FROM licenses l WHERE l.plan_id = lp.id) AS license_count
    FROM license_plans lp
    JOIN products p ON lp.product_id = p.id
";

$params = [];

// Produkt-Filter hinzufügen
if ($product_id > 0) {
    $sql .= " WHERE lp.product_id = ?";
    $params[] = $product_id;
}

// Sortierung hinzufügen
$sql .= " ORDER BY p.name, lp.name";

// Pläne abrufen
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$plans = $stmt->fetchAll();

// Header einbinden
include 'header.php';
?>

<?= display_errors($errors) ?>
<?= display_success($success) ?>

<div class="d-flex justify-content-between mb-3">
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPlanModal">
            <i class="bi bi-plus-circle"></i> Neuen Lizenzplan erstellen
        </button>
    </div>
    
    <div>
        <form class="d-flex">
            <select name="product_id" class="form-select" onchange="this.form.submit()">
                <option value="">Alle Produkte</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= $product['id'] ?>" <?= $product_id == $product['id'] ? 'selected' : '' ?>>
                        <?= html_escape($product['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Produkt</th>
                        <th>Dauer</th>
                        <th>Max. Domains</th>
                        <th>Preis</th>
                        <th>Features</th>
                        <th>Lizenzen</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td><?= html_escape($plan['name']) ?></td>
                            <td><?= html_escape($plan['product_name']) ?></td>
                            <td>
                                <?php if ($plan['duration'] > 0): ?>
                                    <?= $plan['duration'] ?> Tage
                                <?php else: ?>
                                    <span class="badge bg-success">Unbegrenzt</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $plan['max_domains'] ?></td>
                            <td><?= number_format($plan['price'], 2, ',', '.') ?> €</td>
                            <td>
                                <?php
                                $features = json_decode($plan['features'], true);
                                if (!empty($features)):
                                ?>
                                    <button type="button" class="btn btn-sm btn-outline-info view-features" 
                                            data-bs-toggle="modal" data-bs-target="#viewFeaturesModal"
                                            data-plan-name="<?= html_escape($plan['name']) ?>"
                                            data-features="<?= html_escape(implode(', ', $features)) ?>">
                                        <i class="bi bi-list-check"></i> <?= count($features) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Keine</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="licenses.php?search=<?= urlencode($plan['name']) ?>" class="badge bg-primary text-decoration-none">
                                    <?= $plan['license_count'] ?>
                                </a>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-plan" 
                                            data-bs-toggle="modal" data-bs-target="#editPlanModal"
                                            data-plan-id="<?= $plan['id'] ?>"
                                            data-plan-name="<?= html_escape($plan['name']) ?>"
                                            data-plan-product-id="<?= $plan['product_id'] ?>"
                                            data-plan-duration="<?= $plan['duration'] ?>"
                                            data-plan-max-domains="<?= $plan['max_domains'] ?>"
                                            data-plan-price="<?= number_format($plan['price'], 2, ',', '.') ?>"
                                            data-plan-features="<?= html_escape($plan['features']) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($plan['license_count'] == 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-plan"
                                                data-bs-toggle="modal" data-bs-target="#deletePlanModal"
                                                data-plan-id="<?= $plan['id'] ?>"
                                                data-plan-name="<?= html_escape($plan['name']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-3">Keine Lizenzpläne gefunden</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Neuen Lizenzplan erstellen -->
<div class="modal fade" id="createPlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">Neuen Lizenzplan erstellen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="product_id" class="form-label">Produkt *</label>
                        <select class="form-select" id="product_id" name="product_id" required>
                            <option value="">-- Produkt auswählen --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= $product['id'] ?>" <?= (isset($_POST['product_id']) && $_POST['product_id'] == $product['id']) || $product_id == $product['id'] ? 'selected' : '' ?>>
                                    <?= html_escape($product['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Planname *</label>
                        <input type="text" class="form-control" id="name" name="name" required value="<?= isset($_POST['name']) ? html_escape($_POST['name']) : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label for="duration" class="form-label">Dauer (in Tagen)</label>
                        <input type="number" class="form-control" id="duration" name="duration" min="0" value="<?= isset($_POST['duration']) ? (int)$_POST['duration'] : '365' ?>">
                        <div class="form-text">0 für unbegrenzte Laufzeit</div>
                    </div>
                    <div class="mb-3">
                        <label for="max_domains" class="form-label">Maximale Anzahl an Domains *</label>
                        <input type="number" class="form-control" id="max_domains" name="max_domains" min="1" required value="<?= isset($_POST['max_domains']) ? (int)$_POST['max_domains'] : '1' ?>">
                    </div>
                    <div class="mb-3">
                        <label for="price" class="form-label">Preis (€) *</label>
                        <input type="text" class="form-control" id="price" name="price" required value="<?= isset($_POST['price']) ? html_escape($_POST['price']) : '0,00' ?>">
                    </div>
                    <div class="mb-3">
                        <label for="features" class="form-label">Features (durch Komma getrennt)</label>
                        <textarea class="form-control" id="features" name="features" rows="3"><?= isset($_POST['features']) ? html_escape($_POST['features']) : '' ?></textarea>
                        <div class="form-text">z.B. "premium, api_access, export" (diese Features werden für Feature-Gates verwendet)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Lizenzplan erstellen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lizenzplan bearbeiten -->
<div class="modal fade" id="editPlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="plan_id" id="edit_plan_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">Lizenzplan bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_product_id" class="form-label">Produkt</label>
                        <select class="form-select" id="edit_product_id" disabled>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= $product['id'] ?>">
                                    <?= html_escape($product['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Das Produkt kann nach der Erstellung nicht mehr geändert werden.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Planname *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_duration" class="form-label">Dauer (in Tagen)</label>
                        <input type="number" class="form-control" id="edit_duration" name="duration" min="0">
                        <div class="form-text">0 für unbegrenzte Laufzeit</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_max_domains" class="form-label">Maximale Anzahl an Domains *</label>
                        <input type="number" class="form-control" id="edit_max_domains" name="max_domains" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_price" class="form-label">Preis (€) *</label>
                        <input type="text" class="form-control" id="edit_price" name="price" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_features" class="form-label">Features (durch Komma getrennt)</label>
                        <textarea class="form-control" id="edit_features" name="features" rows="3"></textarea>
                        <div class="form-text">z.B. "premium, api_access, export" (diese Features werden für Feature-Gates verwendet)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Lizenzplan löschen -->
<div class="modal fade" id="deletePlanModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="plan_id" id="delete_plan_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">Lizenzplan löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p>Möchten Sie den Lizenzplan <strong id="delete_plan_name"></strong> wirklich löschen?</p>
                    <p class="text-danger">Diese Aktion kann nicht rückgängig gemacht werden.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Lizenzplan löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Features anzeigen -->
<div class="modal fade" id="viewFeaturesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Features für <span id="view_plan_name"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <ul id="features_list" class="list-group"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Script für Modals -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Plan bearbeiten
    const editButtons = document.querySelectorAll('.edit-plan');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const planId = this.getAttribute('data-plan-id');
            const planName = this.getAttribute('data-plan-name');
            const planProductId = this.getAttribute('data-plan-product-id');
            const planDuration = this.getAttribute('data-plan-duration');
            const planMaxDomains = this.getAttribute('data-plan-max-domains');
            const planPrice = this.getAttribute('data-plan-price');
            const planFeaturesJson = this.getAttribute('data-plan-features');
            
            document.getElementById('edit_plan_id').value = planId;
            document.getElementById('edit_name').value = planName;
            document.getElementById('edit_product_id').value = planProductId;
            document.getElementById('edit_duration').value = planDuration;
            document.getElementById('edit_max_domains').value = planMaxDomains;
            document.getElementById('edit_price').value = planPrice;
            
            // Features aus JSON extrahieren
            if (planFeaturesJson) {
                try {
                    const features = JSON.parse(planFeaturesJson);
                    document.getElementById('edit_features').value = features.join(', ');
                } catch (e) {
                    document.getElementById('edit_features').value = '';
                }
            } else {
                document.getElementById('edit_features').value = '';
            }
        });
    });
    
    // Plan löschen
    const deleteButtons = document.querySelectorAll('.delete-plan');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            const planId = this.getAttribute('data-plan-id');
            const planName = this.getAttribute('data-plan-name');
            
            document.getElementById('delete_plan_id').value = planId;
            document.getElementById('delete_plan_name').textContent = planName;
        });
    });
    
    // Features anzeigen
    const viewFeaturesButtons = document.querySelectorAll('.view-features');
    viewFeaturesButtons.forEach(button => {
        button.addEventListener('click', function() {
            const planName = this.getAttribute('data-plan-name');
            const features = this.getAttribute('data-features').split(',').map(f => f.trim()).filter(f => f);
            
            document.getElementById('view_plan_name').textContent = planName;
            
            const featuresList = document.getElementById('features_list');
            featuresList.innerHTML = '';
            
            features.forEach(feature => {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.textContent = feature;
                featuresList.appendChild(li);
            });
        });
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