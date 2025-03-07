<?php
/**
 * Lizenzverwaltung für den Admin-Bereich
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Seitentitel festlegen
$page_title = 'Lizenzen';

// Seiten-Parameter für Paginierung
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Suchparameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Statusänderung verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'updateStatus') {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Ungültiger CSRF-Token");
    }
    
    $licenseId = (int)$_POST['license_id'];
    $newStatus = $_POST['status'];
    
    if (in_array($newStatus, ['active', 'inactive', 'expired'])) {
        $updateStmt = $pdo->prepare("UPDATE licenses SET status = ? WHERE id = ?");
        $updateStmt->execute([$newStatus, $licenseId]);
        
        // Cache löschen
        clear_cache('dashboard_stats');
        
        redirect("licenses.php?page={$page}&updated=1" . ($search ? "&search=" . urlencode($search) : ""));
    }
}

// SQL für die Lizenzabfrage vorbereiten
$sql = "
    SELECT l.*, c.name as customer_name, c.email as customer_email,
           p.name as plan_name, pr.name as product_name
    FROM licenses l
    JOIN customers c ON l.customer_id = c.id
    JOIN license_plans p ON l.plan_id = p.id
    JOIN products pr ON p.product_id = pr.id
";

$params = [];
$whereClauses = [];

// Suchfilter hinzufügen
if (!empty($search)) {
    $whereClauses[] = "(l.license_key LIKE ? OR c.name LIKE ? OR c.email LIKE ? OR pr.name LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Statusfilter hinzufügen
if (!empty($status_filter) && in_array($status_filter, ['active', 'inactive', 'expired'])) {
    $whereClauses[] = "l.status = ?";
    $params[] = $status_filter;
}

// WHERE-Klausel hinzufügen, wenn Filter aktiv sind
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}

// Sortierung und Limitierung hinzufügen
$sql .= " ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// Lizenzen mit Kundeninformationen abrufen
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenses = $stmt->fetchAll();

// Gesamtanzahl für Paginierung
$countSql = "SELECT COUNT(*) FROM licenses l
             JOIN customers c ON l.customer_id = c.id
             JOIN license_plans p ON l.plan_id = p.id
             JOIN products pr ON p.product_id = pr.id";

if (!empty($whereClauses)) {
    $countSql .= " WHERE " . implode(" AND ", $whereClauses);
}

$countStmt = $pdo->prepare($countSql);
// Die letzten beiden Parameter (Limit und Offset) entfernen
array_pop($params);
array_pop($params);
$countStmt->execute($params);
$totalLicenses = $countStmt->fetchColumn();
$totalPages = ceil($totalLicenses / $perPage);

// URL für Paginierung erstellen
$paginationUrl = "licenses.php?";
if (!empty($search)) {
    $paginationUrl .= "search=" . urlencode($search) . "&";
}
if (!empty($status_filter)) {
    $paginationUrl .= "status=" . urlencode($status_filter) . "&";
}
$paginationUrl .= "page=%d";

// Header einbinden
include 'header.php';
?>

<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Lizenzstatus erfolgreich aktualisiert.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <a href="create_license.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neue Lizenz erstellen
    </a>
    
    <form class="d-flex">
        <select name="status" class="form-select me-2" onchange="this.form.submit()">
            <option value="">Alle Status</option>
            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Aktiv</option>
            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
            <option value="expired" <?= $status_filter == 'expired' ? 'selected' : '' ?>>Abgelaufen</option>
        </select>
        <input type="text" name="search" class="form-control me-2" placeholder="Lizenzschlüssel oder Kunde" value="<?= html_escape($search) ?>">
        <button type="submit" class="btn btn-outline-primary">Suchen</button>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Lizenzschlüssel</th>
                        <th>Kunde</th>
                        <th>Produkt / Plan</th>
                        <th>Status</th>
                        <th>Erstellt am</th>
                        <th>Gültig bis</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($licenses as $license): ?>
                        <tr>
                            <td><?= html_escape($license['license_key']) ?></td>
                            <td>
                                <strong><?= html_escape($license['customer_name']) ?></strong><br>
                                <small><?= html_escape($license['customer_email']) ?></small>
                            </td>
                            <td>
                                <?= html_escape($license['product_name']) ?> / 
                                <?= html_escape($license['plan_name']) ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= get_status_color($license['status']) ?>">
                                    <?= get_status_label($license['status']) ?>
                                </span>
                            </td>
                            <td><?= format_date($license['created_at']) ?></td>
                            <td>
                                <?= $license['end_date'] ? format_date($license['end_date']) : 'Unbegrenzt' ?>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="view_license.php?id=<?= $license['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="post">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="updateStatus">
                                                <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $license['status'] == 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <?= $license['status'] == 'active' ? 'Deaktivieren' : 'Aktivieren' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li><a class="dropdown-item" href="edit_license.php?id=<?= $license['id'] ?>">Bearbeiten</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="delete_license.php?id=<?= $license['id'] ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($licenses)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-3">Keine Lizenzen gefunden</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginierung -->
        <?php if ($totalPages > 1): ?>
            <?= pagination($page, $totalPages, $paginationUrl) ?>
        <?php endif; ?>
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