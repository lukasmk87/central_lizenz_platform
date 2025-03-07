<?php
/**
 * Cronjob: Bereinigung alter Validierungslogs
 * 
 * Empfohlene Ausführung: Wöchentlich (z.B. 0 4 * * 0)
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

log_message("Starte Bereinigung der Validierungslogs...");

// Alter in Tagen (standardmäßig 90 Tage)
$days = isset($_GET['days']) ? (int)$_GET['days'] : 90;

// Sicherstellung, dass Tage nicht zu kurz gesetzt werden
if ($days < 30) {
    $days = 30;
    log_message("Warnhinweis: Mindestalter für die Bereinigung auf 30 Tage gesetzt.", "warning");
}

// Datum berechnen, vor dem Logs gelöscht werden sollen
$cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));

// Alte Logs löschen
$stmt = $pdo->prepare("
    DELETE FROM validation_logs 
    WHERE created_at < ?
");
$stmt->execute([$cutoff_date]);
$deleted_count = $stmt->rowCount();

log_message("$deleted_count Validierungslogs gelöscht, die älter als $days Tage sind.");

// Cache-Dateien bereinigen
$clean_cache = true; // Kann per Parameter deaktiviert werden
if (isset($_GET['clean_cache']) && $_GET['clean_cache'] === '0') {
    $clean_cache = false;
}

if ($clean_cache) {
    $cache_dir = __DIR__ . '/../cache/';
    $cache_count = 0;
    
    // Nur Cache-Dateien löschen, die älter als 7 Tage sind
    $cache_cutoff = time() - (7 * 24 * 60 * 60);
    
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '*.cache');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cache_cutoff) {
                unlink($file);
                $cache_count++;
            }
        }
    }
    
    // Rate-Limit-Cache bereinigen
    $rate_limit_dir = __DIR__ . '/../cache/rate_limits/';
    $rate_limit_count = 0;
    
    if (is_dir($rate_limit_dir)) {
        $files = glob($rate_limit_dir . '*.txt');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cache_cutoff) {
                unlink($file);
                $rate_limit_count++;
            }
        }
    }
    
    log_message("$cache_count Cache-Dateien und $rate_limit_count Rate-Limit-Dateien gelöscht, die älter als 7 Tage sind.");
}

// Bereinigung der Datenbank-Tabellen optimieren
$optimize = true; // Kann per Parameter deaktiviert werden
if (isset($_GET['optimize']) && $_GET['optimize'] === '0') {
    $optimize = false;
}

if ($optimize) {
    try {
        // Tabellen optimieren
        $pdo->exec("OPTIMIZE TABLE validation_logs");
        log_message("Datenbank-Tabelle 'validation_logs' optimiert.");
    } catch (PDOException $e) {
        log_message("Fehler bei der Optimierung der Datenbank-Tabellen: " . $e->getMessage(), "error");
    }
}

// Systemadministrator informieren
$admin_email = ADMIN_EMAIL;
$admin_subject = 'Log-Bereinigung abgeschlossen';
$admin_message = "Log-Bereinigung durchgeführt.\n\n";
$admin_message .= "- {$deleted_count} Validierungslogs gelöscht (älter als {$days} Tage).\n";

if ($clean_cache) {
    $admin_message .= "- {$cache_count} Cache-Dateien und {$rate_limit_count} Rate-Limit-Dateien bereinigt.\n";
}

if ($optimize) {
    $admin_message .= "- Datenbank-Tabellen wurden optimiert.\n";
}

$admin_message .= "\nZeitpunkt: " . date('Y-m-d H:i:s') . "\n";

// Admin-E-Mail-Header
$admin_headers = "From: " . ADMIN_EMAIL . "\r\n";
$admin_headers .= "X-Mailer: PHP/" . phpversion();

// Admin-E-Mail senden
if (mail($admin_email, $admin_subject, $admin_message, $admin_headers)) {
    log_message("Admin-Benachrichtigung gesendet an: {$admin_email}");
} else {
    log_message("Fehler beim Senden der Admin-Benachrichtigung", "error");
}

log_message("Bereinigung der Validierungslogs abgeschlossen.");