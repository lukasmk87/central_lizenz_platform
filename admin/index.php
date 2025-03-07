<?php
/**
 * Dashboard für den Admin-Bereich
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Seitentitel festlegen
$page_title = 'Dashboard';

// Dashboard-Statistiken laden
function load_dashboard_stats() {
    global $pdo;
    
    // Aus Cache laden, wenn verfügbar
    $stats = get_cache('dashboard_stats', 3600);
    if ($stats !== null) {
        return $stats;
    }
    
    $stats = [];
    
    // Gesamtanzahl aktiver Lizenzen
    $stmt = $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active'");
    $stats['active_licenses'] = $stmt->fetchColumn();
    
    // Abgelaufene Lizenzen
    $stmt = $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'expired'");
    $stats['expired_licenses'] = $stmt->fetchColumn();
    
    // Inaktive Lizenzen
    $stmt = $pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'inactive'");
    $stats['inactive_licenses'] = $stmt->fetchColumn();
    
    // Anzahl der Kunden
    $stmt = $pdo->query("SELECT COUNT(*) FROM customers WHERE status = 'active'");
    $stats['active_customers'] = $stmt->fetchColumn();
    
    // Anzahl der Produkte
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $stats['products'] = $stmt->fetchColumn();
    
    // Anzahl der Pläne
    $stmt = $pdo->query("SELECT COUNT(*) FROM license_plans");
    $stats['plans'] = $stmt->fetchColumn();
    
    // Validierungen heute
    $stmt = $pdo->query("SELECT COUNT(*) FROM validation_logs WHERE DATE(created_at) = CURDATE()");
    $stats['validations_today'] = $stmt->fetchColumn();
    
    // Letzte Validierungen
    $stmt = $pdo->query("
        SELECT vl.*, l.license_key, c.name as customer_name, p.name as product_name
        FROM validation_logs vl
        JOIN licenses l ON vl.license_id = l.id
        JOIN customers c ON l.customer_id = c.id
        JOIN license_plans lp ON l.plan_id = lp.id
        JOIN products p ON lp.product_id = p.id
        ORDER BY vl.created_at DESC
        LIMIT 5
    ");
    $stats['recent_validations'] = $stmt->fetchAll();
    
    // Neueste Lizenzen
    $stmt = $pdo->query("
        SELECT l.*, c.name as customer_name, p.name as product_name, lp.name as plan_name
        FROM licenses l
        JOIN customers c ON l.customer_id = c.id
        JOIN license_plans lp ON l.plan_id = lp.id
        JOIN products p ON lp.product_id = p.id
        ORDER BY l.created_at DESC
        LIMIT 5
    ");
    $stats['recent_licenses'] = $stmt->fetchAll();
    
    // Bald ablaufende Lizenzen
    $stmt = $pdo->query("
        SELECT l.*, c.name as customer_name, p.name as product_name, lp.name as plan_name
        FROM licenses l
        JOIN customers c ON l.customer_id = c.id
        JOIN license_plans lp ON l.plan_id = lp.id
        JOIN products p ON lp.product_id = p.id
        WHERE l.end_date IS NOT NULL 
        AND l.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND l.status = 'active'
        ORDER BY l.end_date ASC
        LIMIT 5
    ");
    $stats['soon_expiring'] = $stmt->fetchAll();
    
    // Im Cache speichern
    set_cache('dashboard_stats', $stats);
    
    return $stats;
}

// Dashboard-Statistiken laden
$stats = load_dashboard_stats();

// Header einbinden
include 'header.php';
?>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card bg-primary text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Aktive Lizenzen</h6>
                        <h2 class="mb-0"><?= $stats['active_licenses'] ?></h2>
                    </div>
                    <i class="bi bi-key-fill fs-1"></i>
                </div>
            </div>
            <div class="card-footer d-flex align-items-center justify-content-between">
                <a href="licenses.php" class="text-white text-decoration-none">Details ansehen</a>
                <i class="bi bi-arrow-right text-white"></i>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card bg-success text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Aktive Kunden</h6>
                        <h2 class="mb-0"><?= $stats['active_customers'] ?></h2>
                    </div>
                    <i class="bi bi-people-fill fs-1"></i>
                </div>
            </div>
            <div class="card-footer d-flex align-items-center justify-content-between">
                <a href="customers.php" class="text-white text-decoration-none">Details ansehen</a>
                <i class="bi bi-arrow-right text-white"></i>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card bg-info text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Validierungen heute</h6>
                        <h2 class="mb-0"><?= $stats['validations_today'] ?></h2>
                    </div>
                    <i class="bi bi-shield-check fs-1"></i>
                </div>
            </div>
            <div class="card-footer d-flex align-items-center justify-content-between">
                <span class="text-white">Heutige Aktivität</span>
                <i class="bi bi-activity text-white"></i>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card bg-warning text-dark h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Bald ablaufend</h6>
                        <h2 class="mb-0"><?= count($stats['soon_expiring']) ?></h2>
                    </div>
                    <i class="bi bi-alarm fs-1"></i>
                </div>
            </div>
            <div class="card-footer d-flex align-items-center justify-content-between">
                <span class="text-dark">In den nächsten 7 Tagen</span>
                <i class="bi bi-calendar-event text-dark"></i>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Neueste Lizenzen</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['recent_licenses'])): ?>
                    <p class="text-muted">Keine Lizenzen gefunden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Lizenzschlüssel</th>
                                    <th>Kunde</th>
                                    <th>Produkt</th>
                                    <th>Status</th>
                                    <th>Erstellt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_licenses'] as $license): ?>
                                    <tr>
                                        <td><a href="view_license.php?id=<?= $license['id'] ?>"><?= substr($license['license_key'], 0, 8) ?>...</a></td>
                                        <td><?= html_escape($license['customer_name']) ?></td>
                                        <td><?= html_escape($license['product_name']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= get_status_color($license['status']) ?>">
                                                <?= get_status_label($license['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= format_date($license['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <a href="licenses.php" class="btn btn-sm btn-primary">Alle Lizenzen anzeigen</a>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="card-title mb-0">Letzte Validierungen</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['recent_validations'])): ?>
                    <p class="text-muted">Keine Validierungen gefunden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Lizenz</th>
                                    <th>Domain</th>
                                    <th>Ergebnis</th>
                                    <th>Datum</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_validations'] as $validation): ?>
                                    <tr>
                                        <td><?= substr($validation['license_key'], 0, 8) ?>...</td>
                                        <td><?= html_escape($validation['domain']) ?></td>
                                        <td>
                                            <?php if ($validation['is_valid']): ?>
                                                <span class="badge bg-success">Gültig</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Ungültig</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= format_date($validation['created_at'], true) ?></td>
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

<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Bald ablaufende Lizenzen</h5>
            </div>
            <div class="card-body">
                <?php if (empty($stats['soon_expiring'])): ?>
                    <p class="text-muted">Keine bald ablaufenden Lizenzen gefunden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Lizenzschlüssel</th>
                                    <th>Kunde</th>
                                    <th>Produkt / Plan</th>
                                    <th>Läuft ab am</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['soon_expiring'] as $license): ?>
                                    <tr>
                                        <td><a href="view_license.php?id=<?= $license['id'] ?>"><?= substr($license['license_key'], 0, 8) ?>...</a></td>
                                        <td><?= html_escape($license['customer_name']) ?></td>
                                        <td><?= html_escape($license['product_name']) ?> / <?= html_escape($license['plan_name']) ?></td>
                                        <td><?= format_date($license['end_date']) ?></td>
                                        <td>
                                            <a href="edit_license.php?id=<?= $license['id'] ?>" class="btn btn-sm btn-primary">Verlängern</a>
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
</div>

<?php
// Footer einbinden
?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
</body>
</html>