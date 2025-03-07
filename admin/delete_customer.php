<?php
/**
 * Kunden löschen
 */

// Konfiguration und Funktionen einbinden
session_start();
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Sicherstellen, dass der Benutzer eingeloggt ist
require_login();

// Prüfen, ob eine Kunden-ID übergeben wurde
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirect('customers.php');
}

$customer_id = (int)$_GET['id'];

// CSRF-Schutz
if (!isset($_GET['token']) || !validate_csrf_token($_GET['token'])) {
    // Bestätigungsseite anzeigen
    $page_title = 'Kunden löschen';
    include 'header.php';
    ?>
    
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Kunden wirklich löschen?</h5>
        </div>
        <div class="card-body">
            <p>Möchten Sie den Kunden wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.</p>
            <p class="text-danger"><strong>Achtung:</strong> Alle Lizenzen dieses Kunden werden ebenfalls gelöscht!</p>
            
            <?php
            // Kundendetails abrufen
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();
            
            if ($customer):
            ?>
                <div class="alert alert-info">
                    <strong>Kundendetails:</strong><br>
                    Name: <?= html_escape($customer['name']) ?><br>
                    E-Mail: <?= html_escape($customer['email']) ?><br>
                    <?php if (!empty($customer['company'])): ?>
                        Firma: <?= html_escape($customer['company']) ?><br>
                    <?php endif; ?>
                    Status: <?= $customer['status'] === 'active' ? 'Aktiv' : 'Inaktiv' ?><br>
                    Erstellt am: <?= format_date($customer['created_at']) ?>
                </div>
                
                <?php
                // Anzahl der Lizenzen abrufen
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE customer_id = ?");
                $stmt->execute([$customer_id]);
                $license_count = $stmt->fetchColumn();
                ?>
                
                <div class="alert alert-warning">
                    Dieser Kunde hat <?= $license_count ?> Lizenz(en). Diese werden ebenfalls gelöscht.
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="customers.php" class="btn btn-secondary">Abbrechen</a>
                    <a href="delete_customer.php?id=<?= $customer_id ?>&token=<?= $_SESSION['csrf_token'] ?>&confirm=1" class="btn btn-danger">Ja, Kunden löschen</a>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    Der angegebene Kunde wurde nicht gefunden.
                </div>
                
                <div class="d-flex justify-content-end">
                    <a href="customers.php" class="btn btn-secondary">Zurück zur Übersicht</a>
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

// Wenn bestätigt, Kunden löschen
if (isset($_GET['confirm']) && $_GET['confirm'] == 1) {
    try {
        // Transaktion starten
        $pdo->beginTransaction();
        
        // Zuerst alle Domains für Lizenzen des Kunden löschen
        $pdo->prepare("
            DELETE ld FROM license_domains ld
            JOIN licenses l ON ld.license_id = l.id
            WHERE l.customer_id = ?
        ")->execute([$customer_id]);
        
        // Dann alle Validierungslogs für Lizenzen des Kunden löschen
        $pdo->prepare("
            DELETE vl FROM validation_logs vl
            JOIN licenses l ON vl.license_id = l.id
            WHERE l.customer_id = ?
        ")->execute([$customer_id]);
        
        // Alle Lizenzen des Kunden löschen
        $pdo->prepare("DELETE FROM licenses WHERE customer_id = ?")->execute([$customer_id]);
        
        // Zuletzt den Kunden selbst löschen
        $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$customer_id]);
        
        // Transaktion abschließen
        $pdo->commit();
        
        // Cache löschen
        clear_cache('dashboard_stats');
        
        // Erfolgsmeldung und Weiterleitung
        redirect('customers.php?deleted=1');
    } catch (Exception $e) {
        // Transaktion zurückrollen
        $pdo->rollBack();
        
        // Fehlermeldung und Weiterleitung
        if (DEBUG_MODE) {
            die("Fehler beim Löschen des Kunden: " . $e->getMessage());
        } else {
            redirect('customers.php?error=delete_failed');
        }
    }
} else {
    // Wenn nicht bestätigt, zurück zur Übersicht
    redirect('customers.php');
}