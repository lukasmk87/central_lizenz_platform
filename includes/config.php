<?php
/**
 * Konfigurationsdatei für das Lizenzverwaltungssystem
 * 
 * Diese Datei wird während der Installation überschrieben.
 * Kopieren Sie diese Datei nicht manuell, sondern verwenden Sie den Installer.
 */

// Installationsstatus - wird während der Installation auf "true" gesetzt
define('INSTALLED', false);

// Datenbankverbindung
define('DB_HOST', 'localhost');
define('DB_NAME', 'license_manager');
define('DB_USER', 'username');
define('DB_PASS', 'password');

// Webseitenkonfiguration
define('SITE_URL', 'https://your-domain.com/');
define('ADMIN_EMAIL', 'admin@example.com');

// Sicherheitseinstellungen
define('LICENSE_SECRET', 'change_this_to_a_random_string');

// Sitzungskonfiguration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Auf 0 setzen, wenn kein HTTPS verfügbar ist
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 3600); // 1 Stunde

// Debugging (auf "false" für Produktion)
define('DEBUG_MODE', false);

// Fehlerbehandlung
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Zeitzone
date_default_timezone_set('Europe/Berlin');