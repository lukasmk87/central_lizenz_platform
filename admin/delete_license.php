<?php
/**
 * Lizenz löschen
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Sicherstellen, dass der Benutzer eingeloggt ist
require_login();

// Prüfen, ob eine Lizenz-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirect('licenses.php');
}

$license_id = (int)$_GET['id'];

// CSRF-Schutz
if (!isset($_GET['token']) || !validate_csrf_token($_GET['token'])) {
    // Bestätigungsseite anzeigen
    $page_title = 'Lizenz löschen';
    include 'header.php';
    ?>
    
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Lizenz wirklich löschen?</h5>
        </div>
        <div class="card-body">
            <p>Möchten Sie die Lizenz wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.</p>
            
            <?php
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
            
            if ($license):
            ?>
                <div class="alert alert-info">
                    <strong>Lizenzdetails:</strong><br>
                    Lizenzschlüssel: <?= html_escape($license['license_key']) ?><br>
                    Kunde: <?= html_escape($license['customer_name']) ?> (<?= html_escape($license['customer_email']) ?>)<br>
                    Produkt: <?= html_escape($license['product_name']) ?><br>
                    Plan: <?= html_escape($license['plan_name']) ?><br>
                    Status: <?= get_status_label($license['status']) ?><br>
                    Erstellt am: <?= format_date($license['created_at']) ?>
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="view_license.php?id=<?= $license_id ?>" class="btn btn-secondary">Abbrechen</a>
                    <a href="delete_license.php?id=<?= $license_id ?>&token=<?= $_SESSION['csrf_token'] ?>&confirm=1" class="btn btn-danger">Ja, Lizenz löschen</a>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    Die angegebene Lizenz wurde nicht gefunden.
                </div>
                
                <div class="d-flex justify-content-end">
                    <a href="licenses.php" class="btn btn-secondary">Zurück zur Übersicht</a>
                </div>
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
    <?php
    exit;
}

// Wenn bestätigt, Lizenz löschen
if (isset($_GET['confirm']) && $_GET['confirm'] == 1) {
    try {
        // Lizenz löschen
        $delete_stmt = $pdo->prepare("DELETE FROM licenses WHERE id = ?");
        $result = $delete_stmt->execute([$license_id]);
        
        if ($result) {
            // Cache löschen
            clear_cache('dashboard_stats');
            
            // Erfolgsmeldung und Weiterleitung
            redirect('licenses.php?deleted=1');
        } else {
            // Fehlermeldung und Weiterleitung
            redirect('licenses.php?error=delete_failed');
        }
    } catch (Exception $e) {
        // Fehlermeldung und Weiterleitung
        if (DEBUG_MODE) {
            die("Fehler beim Löschen der Lizenz: " . $e->getMessage());
        } else {
            redirect('licenses.php?error=delete_failed');
        }
    }
} else {
    // Wenn nicht bestätigt, zurück zur Übersicht
    redirect('licenses.php');
}