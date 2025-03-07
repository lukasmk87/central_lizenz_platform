<?php
/**
 * Lizenzdetails anzeigen
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
    SELECT l.*, c.name as customer_name, c.email as customer_email, c.company as customer_company,
           lp.name as plan_name, lp.max_domains, lp.features, lp.price,
           p.name as product_name, p.slug as product_slug
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

// Domains für diese Lizenz abrufen
$domains_stmt = $pdo->prepare("SELECT * FROM license_domains WHERE license_id = ? ORDER BY created_at DESC");
$domains_stmt->execute([$license_id]);
$domains = $domains_stmt->fetchAll();

// Validierungslogs für diese Lizenz abrufen
$logs_stmt = $pdo->prepare("
    SELECT * FROM validation_logs 
    WHERE license_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
");
$logs_stmt->execute([$license_id]);
$logs = $logs_stmt->fetchAll();

// Features aus JSON decodieren
$features = json_decode($license['features'], true) ?: [];

// Seitentitel festlegen
$page_title = 'Lizenzdetails: ' . $license['license_key'];

// Domain-Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Ungültiger CSRF-Token");
    }
    
    // Domain entfernen
    if ($_POST['action'] === 'remove_domain' && isset($_POST['domain_id'])) {
        $domain_id = (int)$_POST['domain_id'];
        $domain_stmt = $pdo->prepare("DELETE FROM license_domains WHERE id = ? AND license_id = ?");
        $result = $domain_stmt->execute([$domain_id, $license_id]);
        
        if ($result) {
            redirect("view_license.php?id={$license_id}&domain_removed=1");
        }
    }
    
    // Domain hinzufügen
    if ($_POST['action'] === 'add_domain' && isset($_POST['domain'])) {
        $domain = trim($_POST['domain']);
        
        if (!empty($domain)) {
            // Prüfen, ob bereits die maximale Anzahl an Domains erreicht ist
            if (count($domains) >= $license['max_domains']) {
                redirect("view_license.php?id={$license_id}&domain_error=max_domains");
            }
            
            // Prüfen, ob die Domain bereits existiert
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM license_domains WHERE license_id = ? AND domain = ?");
            $check_stmt->execute([$license_id, $domain]);
            $domain_exists = $check_stmt->fetchColumn() > 0;
            
            if ($domain_exists) {
                redirect("view_license.php?id={$license_id}&domain_error=exists");
            }
            
            // Domain hinzufügen
            $add_stmt = $pdo->prepare("INSERT INTO license_domains (license_id, domain) VALUES (?, ?)");
            $result = $add_stmt->execute([$license_id, $domain]);
            
            if ($result) {
                redirect("view_license.php?id={$license_id}&domain_added=1");
            }
        }
    }
}

// Header einbinden
include 'header.php';
?>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Lizenz erfolgreich erstellt.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Lizenz erfolgreich aktualisiert.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['domain_added'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Domain erfolgreich hinzugefügt.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['domain_removed'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Domain erfolgreich entfernt.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['domain_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php if ($_GET['domain_error'] === 'max_domains'): ?>
            Die maximale Anzahl an Domains (<?= $license['max_domains'] ?>) wurde bereits erreicht.
        <?php elseif ($_GET['domain_error'] === 'exists'): ?>
            Diese Domain ist bereits mit dieser Lizenz verknüpft.
        <?php else: ?>
            Fehler beim Hinzufügen der Domain.
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <div>
        <a href="licenses.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurück zur Übersicht
        </a>
    </div>
    <div>
        <a href="edit_license.php?id=<?= $license_id ?>" class="btn btn-primary me-2">
            <i class="bi bi-pencil"></i> Bearbeiten
        </a>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                Aktionen
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <form method="post" action="licenses.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="updateStatus">
                        <input type="hidden" name="license_id" value="<?= $license_id ?>">
                        <input type="hidden" name="status" value="<?= $license['status'] == 'active' ? 'inactive' : 'active' ?>">
                        <button type="submit" class="dropdown-item">
                            <?= $license['status'] == 'active' ? 'Deaktivieren' : 'Aktivieren' ?>
                        </button>
                    </form>
                </li>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#copyLicenseKeyModal">Lizenzschlüssel kopieren</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="delete_license.php?id=<?= $license_id ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a></li>
            </ul>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Lizenzinformationen</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Lizenzschlüssel:</div>
                    <div class="col-md-8 font-monospace">
                        <strong><?= html_escape($license['license_key']) ?></strong>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Status:</div>
                    <div class="col-md-8">
                        <span class="badge bg-<?= get_status_color($license['status']) ?>">
                            <?= get_status_label($license['status']) ?>
                        </span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Produkt:</div>
                    <div class="col-md-8">
                        <?= html_escape($license['product_name']) ?> 
                        <span class="text-muted">(<?= html_escape($license['product_slug']) ?>)</span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Lizenzplan:</div>
                    <div class="col-md-8">
                        <?= html_escape($license['plan_name']) ?> 
                        <span class="text-muted">(<?= number_format($license['price'], 2, ',', '.') ?> €)</span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Erstellt am:</div>
                    <div class="col-md-8"><?= format_date($license['created_at'], true) ?></div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Gültig von:</div>
                    <div class="col-md-8"><?= format_date($license['start_date']) ?></div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Gültig bis:</div>
                    <div class="col-md-8">
                        <?php if ($license['end_date']): ?>
                            <?= format_date($license['end_date']) ?>
                            <?php if (strtotime($license['end_date']) < time()): ?>
                                <span class="badge bg-danger">Abgelaufen</span>
                            <?php elseif (strtotime($license['end_date']) < strtotime('+7 days')): ?>
                                <span class="badge bg-warning text-dark">Läuft bald ab</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-success">Unbegrenzt</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Max. Domains:</div>
                    <div class="col-md-8">
                        <?= $license['max_domains'] ?> 
                        <span class="text-muted">(<?= count($domains) ?> verwendet)</span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Letzte Validierung:</div>
                    <div class="col-md-8">
                        <?= $license['last_validation'] ? format_date($license['last_validation'], true) : 'Noch keine Validierung' ?>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Validierungszähler:</div>
                    <div class="col-md-8"><?= $license['validation_count'] ?></div>
                </div>
                
                <?php if (!empty($features)): ?>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Features:</div>
                        <div class="col-md-8">
                            <ul class="mb-0">
                                <?php foreach ($features as $feature): ?>
                                    <li><?= html_escape($feature) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Domains</h5>
            </div>
            <div class="card-body">
                <?php if (count($domains) < $license['max_domains']): ?>
                    <form method="post" action="" class="mb-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_domain">
                        <div class="input-group">
                            <input type="text" name="domain" class="form-control" placeholder="example.com" required>
                            <button type="submit" class="btn btn-primary">Domain hinzufügen</button>
                        </div>
                        <div class="form-text">
                            <?= count($domains) ?> von <?= $license['max_domains'] ?> Domains verwendet
                        </div>
                    </form>
                <?php endif; ?>
                
                <?php if (empty($domains)): ?>
                    <div class="alert alert-info">
                        Noch keine Domains mit dieser Lizenz verknüpft.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Erstellt am</th>
                                    <th>Verifiziert</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($domains as $domain): ?>
                                    <tr>
                                        <td><?= html_escape($domain['domain']) ?></td>
                                        <td><?= format_date($domain['created_at']) ?></td>
                                        <td>
                                            <?php if ($domain['verified']): ?>
                                                <span class="badge bg-success">Ja</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Nein</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" action="" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="remove_domain">
                                                <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Wirklich entfernen?')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-5">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Kundeninformationen</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">Name:</div>
                    <div class="col-md-8"><?= html_escape($license['customer_name']) ?></div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4 text-muted">E-Mail:</div>
                    <div class="col-md-8">
                        <a href="mailto:<?= html_escape($license['customer_email']) ?>">
                            <?= html_escape($license['customer_email']) ?>
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($license['customer_company'])): ?>
                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">Firma:</div>
                        <div class="col-md-8"><?= html_escape($license['customer_company']) ?></div>
                    </div>
                <?php endif; ?>
                
                <hr>
                
                <div class="d-grid">
                    <a href="edit_customer.php?id=<?= $license['customer_id'] ?>" class="btn btn-outline-primary">
                        <i class="bi bi-person"></i> Kundendetails anzeigen
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Validierungslogs</h5>
            </div>
            <div class="card-body">
                <?php if (empty($logs)): ?>
                    <div class="alert alert-info">
                        Noch keine Validierungen für diese Lizenz.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Domain</th>
                                    <th>Status</th>
                                    <th>Meldung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= format_date($log['created_at'], true) ?></td>
                                        <td><?= html_escape($log['domain']) ?></td>
                                        <td>
                                            <?php if ($log['is_valid']): ?>
                                                <span class="badge bg-success">Gültig</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Ungültig</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= html_escape($log['message']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal zum Kopieren des Lizenzschlüssels -->
<div class="modal fade" id="copyLicenseKeyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Lizenzschlüssel kopieren</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <p>Lizenzschlüssel:</p>
                <div class="input-group mb-3">
                    <input type="text" id="licenseCopyInput" class="form-control font-monospace" value="<?= html_escape($license['license_key']) ?>" readonly>
                    <button class="btn btn-outline-primary" type="button" id="copyButton">Kopieren</button>
                </div>
                
                <p>PHP-Code für die Integration:</p>
                <pre class="bg-light p-3 rounded"><code>$license = new LicenseClient('<?= html_escape($license['license_key']) ?>', '<?= html_escape($license['product_slug']) ?>');</code></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Script zum Kopieren des Lizenzschlüssels -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('copyButton').addEventListener('click', function() {
        const copyInput = document.getElementById('licenseCopyInput');
        copyInput.select();
        document.execCommand('copy');
        
        // Feedback anzeigen
        this.innerText = 'Kopiert!';
        setTimeout(() => {
            this.innerText = 'Kopieren';
        }, 2000);
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