<?php
/**
 * Produktverwaltung für den Admin-Bereich
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Seitentitel festlegen
$page_title = 'Produkte';

// Seiten-Parameter für Paginierung
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Suchparameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Formularverarbeitung für neues Produkt
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = "Ungültiger CSRF-Token";
    } else {
        $action = $_POST['action'] ?? '';
        
        // Neues Produkt erstellen
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $slug = trim($_POST['slug'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            // Automatisch Slug generieren, wenn leer
            if (empty($slug) && !empty($name)) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $name));
                $slug = preg_replace('/-+/', '-', $slug);
                $slug = trim($slug, '-');
            }
            
            // Validierung
            if (empty($name)) {
                $errors[] = "Der Produktname darf nicht leer sein.";
            }
            
            if (empty($slug)) {
                $errors[] = "Der Produkt-Slug darf nicht leer sein.";
            } else {
                // Prüfen, ob der Slug bereits existiert
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
                $stmt->execute([$slug]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "Dieser Produkt-Slug existiert bereits.";
                }
            }
            
            // Wenn keine Fehler aufgetreten sind, Produkt erstellen
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    INSERT INTO products (name, slug, description)
                    VALUES (?, ?, ?)
                ");
                
                try {
                    $result = $stmt->execute([$name, $slug, $description]);
                    
                    if ($result) {
                        // Cache löschen
                        clear_cache('dashboard_stats');
                        
                        // Erfolgsmeldung
                        $success = "Produkt erfolgreich erstellt.";
                        
                        // Formularfelder zurücksetzen
                        $_POST = [];
                    } else {
                        $errors[] = "Fehler beim Erstellen des Produkts.";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $errors[] = "Dieser Produkt-Slug existiert bereits.";
                    } else {
                        $errors[] = "Datenbankfehler: " . $e->getMessage();
                    }
                }
            }
        }
        
        // Produkt aktualisieren
        elseif ($action === 'update') {
            $product_id = (int)$_POST['product_id'];
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            // Validierung
            if (empty($name)) {
                $errors[] = "Der Produktname darf nicht leer sein.";
            }
            
            // Wenn keine Fehler aufgetreten sind, Produkt aktualisieren
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    UPDATE products 
                    SET name = ?, description = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                try {
                    $result = $stmt->execute([$name, $description, $product_id]);
                    
                    if ($result) {
                        // Cache löschen
                        clear_cache('dashboard_stats');
                        
                        // Erfolgsmeldung
                        $success = "Produkt erfolgreich aktualisiert.";
                    } else {
                        $errors[] = "Fehler beim Aktualisieren des Produkts.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Datenbankfehler: " . $e->getMessage();
                }
            }
        }
        
        // Produkt löschen
        elseif ($action === 'delete') {
            $product_id = (int)$_POST['product_id'];
            
            // Prüfen, ob es Lizenzen für dieses Produkt gibt
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM licenses l
                JOIN license_plans lp ON l.plan_id = lp.id
                WHERE lp.product_id = ?
            ");
            $stmt->execute([$product_id]);
            $license_count = $stmt->fetchColumn();
            
            if ($license_count > 0) {
                $errors[] = "Dieses Produkt kann nicht gelöscht werden, da es aktive Lizenzen gibt.";
            } else {
                try {
                    // Zuerst alle Pläne für dieses Produkt löschen
                    $pdo->prepare("DELETE FROM license_plans WHERE product_id = ?")->execute([$product_id]);
                    
                    // Dann das Produkt löschen
                    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
                    $result = $stmt->execute([$product_id]);
                    
                    if ($result) {
                        // Cache löschen
                        clear_cache('dashboard_stats');
                        
                        // Erfolgsmeldung
                        $success = "Produkt erfolgreich gelöscht.";
                    } else {
                        $errors[] = "Fehler beim Löschen des Produkts.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Datenbankfehler: " . $e->getMessage();
                }
            }
        }
    }
}

// SQL für die Produktabfrage vorbereiten
$sql = "SELECT p.*, 
               (SELECT COUNT(*) FROM license_plans lp WHERE lp.product_id = p.id) AS plan_count,
               (SELECT COUNT(*) FROM licenses l JOIN license_plans lp ON l.plan_id = lp.id WHERE lp.product_id = p.id) AS license_count
        FROM products p";

$params = [];

// Suchfilter hinzufügen
if (!empty($search)) {
    $sql .= " WHERE p.name LIKE ? OR p.slug LIKE ? OR p.description LIKE ?";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Sortierung und Limitierung hinzufügen
$sql .= " ORDER BY p.name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// Produkte abrufen
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Gesamtanzahl für Paginierung
$countSql = "SELECT COUNT(*) FROM products";

if (!empty($search)) {
    $countSql .= " WHERE name LIKE ? OR slug LIKE ? OR description LIKE ?";
}

$countStmt = $pdo->prepare($countSql);

if (!empty($search)) {
    $searchTerm = "%{$search}%";
    $countStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
} else {
    $countStmt->execute();
}

$totalProducts = $countStmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);

// URL für Paginierung erstellen
$paginationUrl = "products.php?";
if (!empty($search)) {
    $paginationUrl .= "search=" . urlencode($search) . "&";
}
$paginationUrl .= "page=%d";

// Header einbinden
include 'header.php';
?>

<?= display_errors($errors) ?>
<?= display_success($success) ?>

<div class="d-flex justify-content-between mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createProductModal">
        <i class="bi bi-plus-circle"></i> Neues Produkt erstellen
    </button>
    
    <form class="d-flex">
        <input type="text" name="search" class="form-control me-2" placeholder="Produkt suchen" value="<?= html_escape($search) ?>">
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
                        <th>Slug</th>
                        <th>Beschreibung</th>
                        <th>Pläne</th>
                        <th>Lizenzen</th>
                        <th>Erstellt am</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= html_escape($product['name']) ?></td>
                            <td><code><?= html_escape($product['slug']) ?></code></td>
                            <td><?= html_escape(substr($product['description'] ?? '', 0, 50)) ?><?= strlen($product['description'] ?? '') > 50 ? '...' : '' ?></td>
                            <td>
                                <a href="plans.php?product_id=<?= $product['id'] ?>" class="badge bg-info text-decoration-none">
                                    <?= $product['plan_count'] ?>
                                </a>
                            </td>
                            <td>
                                <a href="licenses.php?search=<?= urlencode($product['name']) ?>" class="badge bg-primary text-decoration-none">
                                    <?= $product['license_count'] ?>
                                </a>
                            </td>
                            <td><?= format_date($product['created_at']) ?></td>
                            <td>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-primary edit-product" 
                                            data-bs-toggle="modal" data-bs-target="#editProductModal"
                                            data-product-id="<?= $product['id'] ?>"
                                            data-product-name="<?= html_escape($product['name']) ?>"
                                            data-product-slug="<?= html_escape($product['slug']) ?>"
                                            data-product-description="<?= html_escape($product['description'] ?? '') ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="plans.php?product_id=<?= $product['id'] ?>" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-card-list"></i>
                                    </a>
                                    <?php if ($product['license_count'] == 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger delete-product"
                                                data-bs-toggle="modal" data-bs-target="#deleteProductModal"
                                                data-product-id="<?= $product['id'] ?>"
                                                data-product-name="<?= html_escape($product['name']) ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-3">Keine Produkte gefunden</td>
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

<!-- Modal: Neues Produkt erstellen -->
<div class="modal fade" id="createProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                
                <div class="modal-header">
                    <h5 class="modal-title">Neues Produkt erstellen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Produktname *</label>
                        <input type="text" class="form-control" id="name" name="name" required value="<?= isset($_POST['name']) ? html_escape($_POST['name']) : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label for="slug" class="form-label">Produkt-Slug *</label>
                        <input type="text" class="form-control" id="slug" name="slug" value="<?= isset($_POST['slug']) ? html_escape($_POST['slug']) : '' ?>" pattern="[a-z0-9\-]+" title="Nur Kleinbuchstaben, Zahlen und Bindestriche erlaubt">
                        <div class="form-text">Wird für die API-Anfragen verwendet. Nur Kleinbuchstaben, Zahlen und Bindestriche erlaubt. Leer lassen für automatische Generierung.</div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Beschreibung</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?= isset($_POST['description']) ? html_escape($_POST['description']) : '' ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Produkt erstellen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Produkt bearbeiten -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="product_id" id="edit_product_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">Produkt bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Produktname *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_slug" class="form-label">Produkt-Slug</label>
                        <input type="text" class="form-control" id="edit_slug" disabled>
                        <div class="form-text">Der Slug kann nach der Erstellung nicht mehr geändert werden.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Beschreibung</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
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

<!-- Modal: Produkt löschen -->
<div class="modal fade" id="deleteProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="product_id" id="delete_product_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">Produkt löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p>Möchten Sie das Produkt <strong id="delete_product_name"></strong> wirklich löschen?</p>
                    <p class="text-danger">Diese Aktion kann nicht rückgängig gemacht werden. Alle Lizenzpläne für dieses Produkt werden ebenfalls gelöscht.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">Produkt löschen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Script für Modals -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Slug automatisch generieren
    document.getElementById('name').addEventListener('input', function() {
        const slug = document.getElementById('slug');
        if (slug.value === '') {
            slug.value = this.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    });
    
    // Produkt bearbeiten
    const editButtons = document.querySelectorAll('.edit-product');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product-name');
            const productSlug = this.getAttribute('data-product-slug');
            const productDescription = this.getAttribute('data-product-description');
            
            document.getElementById('edit_product_id').value = productId;
            document.getElementById('edit_name').value = productName;
            document.getElementById('edit_slug').value = productSlug;
            document.getElementById('edit_description').value = productDescription;
        });
    });
    
    // Produkt löschen
    const deleteButtons = document.querySelectorAll('.delete-product');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product-name');
            
            document.getElementById('delete_product_id').value = productId;
            document.getElementById('delete_product_name').textContent = productName;
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