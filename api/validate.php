<?php
/**
 * Lizenzvalidierungs-API für das Lizenzverwaltungssystem
 */

// Konfiguration und Funktionen einbinden
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/license_generator.php';

// JSON-Ausgabe setzen
header('Content-Type: application/json');

// CORS-Header für die API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS-Anfragen für CORS direkt beantworten
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Nur POST-Anfragen erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'Nur POST-Anfragen sind erlaubt']);
    exit;
}

// IP-Whitelist prüfen (falls konfiguriert)
if (defined('API_IP_WHITELIST') && !empty(API_IP_WHITELIST)) {
    $allowed_ips = explode(',', API_IP_WHITELIST);
    if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
        http_response_code(403);
        echo json_encode(['valid' => false, 'message' => 'Zugriff verweigert']);
        exit;
    }
}

// Rate-Limit prüfen
if (!check_rate_limit($_SERVER['REMOTE_ADDR'], 100, 3600)) {
    http_response_code(429);
    echo json_encode(['valid' => false, 'message' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.']);
    exit;
}

// Eingabe verarbeiten
$input = json_decode(file_get_contents('php://input'), true);
$license_key = $input['license_key'] ?? '';
$domain = $input['domain'] ?? '';
$product_slug = $input['product_slug'] ?? '';

if (empty($license_key) || empty($domain) || empty($product_slug)) {
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'Fehlende Parameter: license_key, domain und product_slug sind erforderlich']);
    exit;
}

// Lizenz aus Datenbank abrufen
$stmt = $pdo->prepare("
    SELECT l.*, lp.features, lp.max_domains, p.slug as product_slug
    FROM licenses l
    JOIN license_plans lp ON l.plan_id = lp.id
    JOIN products p ON lp.product_id = p.id
    WHERE l.license_key = ? AND l.status = 'active'
");
$stmt->execute([$license_key]);
$license = $stmt->fetch();

// Validierungslog-Eintrag vorbereiten
$log_stmt = $pdo->prepare("
    INSERT INTO validation_logs (license_id, domain, ip_address, user_agent, is_valid, message)
    VALUES (?, ?, ?, ?, ?, ?)
");

if (!$license) {
    // Ungültige Lizenz - ins Log eintragen mit ID 0
    $log_stmt->execute([0, $domain, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', false, 'Lizenz nicht gefunden']);
    echo json_encode(['valid' => false, 'message' => 'Ungültiger Lizenzschlüssel']);
    exit;
}

// Produkt-Match prüfen
if ($license['product_slug'] !== $product_slug) {
    $log_stmt->execute([$license['id'], $domain, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', false, 'Produktmismatch']);
    echo json_encode(['valid' => false, 'message' => 'Lizenz nicht für dieses Produkt']);
    exit;
}

// Ablaufdatum prüfen
if ($license['end_date'] !== null && strtotime($license['end_date']) < time()) {
    // Lizenzstatus auf abgelaufen setzen
    $pdo->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?")->execute([$license['id']]);
    $log_stmt->execute([$license['id'], $domain, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', false, 'Lizenz abgelaufen']);
    echo json_encode(['valid' => false, 'message' => 'Lizenz abgelaufen']);
    exit;
}

// Domain-Validierung
$domain_stmt = $pdo->prepare("SELECT * FROM license_domains WHERE license_id = ? AND domain = ?");
$domain_stmt->execute([$license['id'], $domain]);
$domain_record = $domain_stmt->fetch();

if (!$domain_record) {
    // Anzahl der registrierten Domains prüfen
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM license_domains WHERE license_id = ?");
    $count_stmt->execute([$license['id']]);
    $domain_count = $count_stmt->fetchColumn();
    
    if ($domain_count >= $license['max_domains']) {
        $log_stmt->execute([$license['id'], $domain, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', false, 'Maximale Domainanzahl erreicht']);
        echo json_encode(['valid' => false, 'message' => 'Maximale Anzahl an Domains erreicht']);
        exit;
    }
    
    // Neue Domain hinzufügen
    $add_domain_stmt = $pdo->prepare("INSERT INTO license_domains (license_id, domain) VALUES (?, ?)");
    $add_domain_stmt->execute([$license['id'], $domain]);
}

// Lizenz als valide markieren und Validierungszähler aktualisieren
$pdo->prepare("
    UPDATE licenses 
    SET validation_count = validation_count + 1, last_validation = NOW() 
    WHERE id = ?
")->execute([$license['id']]);

// Erfolgreiche Validierung loggen
$log_stmt->execute([$license['id'], $domain, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', true, 'Validierung erfolgreich']);

// Erfolgreiche Antwort senden
echo json_encode(generate_license_response($license, true));