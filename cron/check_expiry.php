<?php
/**
 * Cronjob: Prüft Lizenzablauf und sendet Benachrichtigungen
 * 
 * Empfohlene Ausführung: Täglich (z.B. 0 3 * * *)
 */

// CLI-Modus prüfen
if (php_sapi_name() !== 'cli' && !isset($_GET['secret']) && !isset($_SERVER['HTTP_X_CRON_AUTH'])) {
    header('HTTP/1.0 403 Forbidden');
    echo "Zugriff verweigert";
    exit;
}

// Konfiguration und Funktionen einbinden
define('CRONJOB', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Geheimschlüssel für Webzugriff prüfen
if (php_sapi_name() !== 'cli' && isset($_GET['secret'])) {
    // Wenn ein Secret in der Konfiguration definiert ist, dieses prüfen
    if (defined('CRON_SECRET') && !empty(CRON_SECRET) && $_GET['secret'] !== CRON_SECRET) {
        header('HTTP/1.0 403 Forbidden');
        echo "Ungültiger Geheimschlüssel";
        exit;
    }
}

// Protokoll-Funktion
function log_message($message, $type = 'info') {
    $date = date('Y-m-d H:i:s');
    echo "[$date] [$type] $message" . PHP_EOL;
}

log_message("Starte Lizenzablauf-Prüfung...");

// Abgelaufene Lizenzen finden und Status ändern
$stmt = $pdo->prepare("
    UPDATE licenses 
    SET status = 'expired' 
    WHERE end_date IS NOT NULL 
    AND end_date < NOW() 
    AND status = 'active'
");
$stmt->execute();
$updated_count = $stmt->rowCount();

log_message("$updated_count Lizenzen auf 'abgelaufen' gesetzt.");

// E-Mail-Benachrichtigungen für bald ablaufende Lizenzen
$soon_expiring_stmt = $pdo->prepare("
    SELECT l.*, c.email, c.name, p.name as product_name
    FROM licenses l
    JOIN customers c ON l.customer_id = c.id
    JOIN license_plans lp ON l.plan_id = lp.id
    JOIN products p ON lp.product_id = p.id
    WHERE l.end_date IS NOT NULL 
    AND l.end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    AND l.status = 'active'
    AND l.expiry_notified = 0
");
$soon_expiring_stmt->execute();
$soon_expiring = $soon_expiring_stmt->fetchAll();

$notification_count = 0;

foreach ($soon_expiring as $license) {
    $to = $license['email'];
    $subject = "Ihre Lizenz läuft bald ab - {$license['product_name']}";
    
    // E-Mail-Text erstellen
    $message = "Sehr geehrte(r) {$license['name']},\n\n";
    $message .= "Ihre Lizenz für {$license['product_name']} (Schlüssel: {$license['license_key']}) läuft am " . date('d.m.Y', strtotime($license['end_date'])) . " ab.\n\n";
    $message .= "Um Unterbrechungen zu vermeiden, erneuern Sie bitte Ihre Lizenz rechtzeitig.\n\n";
    $message .= "Mit freundlichen Grüßen,\nIhr Lizenzverwaltungsteam";
    
    // E-Mail-Header
    $headers = "From: " . ADMIN_EMAIL . "\r\n";
    $headers .= "Reply-To: " . ADMIN_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // E-Mail senden
    if (mail($to, $subject, $message, $headers)) {
        // Markieren, dass die Benachrichtigung gesendet wurde
        $pdo->prepare("UPDATE licenses SET expiry_notified = 1 WHERE id = ?")->execute([$license['id']]);
        $notification_count++;
        log_message("Ablaufbenachrichtigung gesendet an: {$license['email']} für Lizenz: {$license['license_key']}");
    } else {
        log_message("Fehler beim Senden der Ablaufbenachrichtigung an: {$license['email']}", "error");
    }
}

log_message("$notification_count Benachrichtigungen über bald ablaufende Lizenzen gesendet.");

// Systemadministrator informieren
$admin_email = ADMIN_EMAIL;
$admin_subject = 'Lizenzablauf-Bericht';
$admin_message = "Lizenzablauf-Prüfung durchgeführt.\n\n";
$admin_message .= "- {$updated_count} Lizenzen auf 'abgelaufen' gesetzt.\n";
$admin_message .= "- {$notification_count} Benachrichtigungen über bald ablaufende Lizenzen gesendet.\n\n";
$admin_message .= "Zeitpunkt: " . date('Y-m-d H:i:s') . "\n";

// Admin-E-Mail-Header
$admin_headers = "From: " . ADMIN_EMAIL . "\r\n";
$admin_headers .= "X-Mailer: PHP/" . phpversion();

// Admin-E-Mail senden
if (mail($admin_email, $admin_subject, $admin_message, $admin_headers)) {
    log_message("Admin-Benachrichtigung gesendet an: {$admin_email}");
} else {
    log_message("Fehler beim Senden der Admin-Benachrichtigung", "error");
}

log_message("Lizenzablauf-Prüfung abgeschlossen.");