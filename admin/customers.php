<?php
/**
 * Kundenverwaltung für den Admin-Bereich
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Seitentitel festlegen
$page_title = 'Kunden';

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
    
    $customer_id = (int)$_POST['customer_id'];
    $newStatus = $_POST['status'];
    
    if (in_array($newStatus, ['active', 'inactive'])) {
        $updateStmt = $pdo->prepare("UPDATE customers SET status = ? WHERE id = ?");
        $updateStmt->execute([$newStatus, $customer_id]);
        
        // Cache löschen
        clear_cache('dashboard_stats');
        
        redirect("customers.php?page={$page}&updated=1" . ($search ? "&search=" . urlencode($search) : ""));
    }
}

// SQL für die Kundenabfrage vorbereiten
$sql = "SELECT * FROM customers";
$params = [];
$whereClauses = [];

// Suchfilter hinzufügen
if (!empty($search)) {
    $whereClauses[] = "(name LIKE ? OR email LIKE ? OR company LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Statusfilter hinzufügen
if (!empty($status_filter) && in_array($status_filter, ['active', 'inactive'])) {
    $whereClauses[] = "status = ?";
    $params[] = $status_filter;
}

// WHERE-Klausel hinzufügen, wenn Filter aktiv sind
if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(" AND ", $whereClauses);
}

// Sortierung und Limitierung hinzufügen
$sql .= " ORDER BY name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// Kunden abrufen
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Gesamtanzahl für Paginierung
$countSql = "SELECT COUNT(*) FROM customers";

if (!empty($whereClauses)) {
    $countSql .= " WHERE " . implode(" AND ", $whereClauses);
}

$countStmt = $pdo->prepare($countSql);
// Die letzten beiden Parameter (Limit und Offset) entfernen
array_pop($params);
array_pop($params);
$countStmt->execute($params);
$totalCustomers = $countStmt->fetchColumn();
$totalPages = ceil($totalCustomers / $perPage);

// URL für Paginierung erstellen
$paginationUrl = "customers.php?";
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
        Kundenstatus erfolgreich aktualisiert.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Kunde erfolgreich erstellt.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Kunde erfolgreich gelöscht.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <a href="create_customer.php" class="btn btn-primary">
        <i class="bi bi-plus-circle"></i> Neuen Kunden erstellen
    </a>
    
    <form class="d-flex">
        <select name="status" class="form-select me-2" onchange="this.form.submit()">
            <option value="">Alle Status</option>
            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Aktiv</option>
            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
        </select>
        <input type="text" name="search" class="form-control me-2" placeholder="Name, E-Mail oder Firma" value="<?= html_escape($search) ?>">
        <button type="submit" class="btn btn-outline-primary">Suchen</button>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Firma</th>
                        <th>Status</th>
                        <th>Erstellt am</th>
                        <th>Lizenzen</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><?= html_escape($customer['name']) ?></td>
                            <td><a href="mailto:<?= html_escape($customer['email']) ?>"><?= html_escape($customer['email']) ?></a></td>
                            <td><?= html_escape($customer['company'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-<?= $customer['status'] == 'active' ? 'success' : 'warning' ?>">
                                    <?= $customer['status'] == 'active' ? 'Aktiv' : 'Inaktiv' ?>
                                </span>
                            </td>
                            <td><?= format_date($customer['created_at']) ?></td>
                            <td>
                                <?php
                                // Anzahl der Lizenzen für diesen Kunden abrufen
                                $license_stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE customer_id = ?");
                                $license_stmt->execute([$customer['id']]);
                                $license_count = $license_stmt->fetchColumn();
                                ?>
                                <a href="licenses.php?search=<?= urlencode($customer['email']) ?>" class="badge bg-info text-decoration-none">
                                    <?= $license_count ?>
                                </a>
                            </td>
                            <td>
                                <div class="btn-group">
                                    <a href="edit_customer.php?id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <form method="post" action="">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="updateStatus">
                                                <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
                                                <input type="hidden" name="status" value="<?= $customer['status'] == 'active' ? 'inactive' : 'active' ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <?= $customer['status'] == 'active' ? 'Deaktivieren' : 'Aktivieren' ?>
                                                </button>
                                            </form>
                                        </li>
                                        <li><a class="dropdown-item" href="create_license.php?customer_id=<?= $customer['id'] ?>">Neue Lizenz erstellen</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item text-danger" href="delete_customer.php?id=<?= $customer['id'] ?>" onclick="return confirm('Wirklich löschen? Alle Lizenzen des Kunden werden ebenfalls gelöscht!')">Löschen</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-3">Keine Kunden gefunden</td>
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