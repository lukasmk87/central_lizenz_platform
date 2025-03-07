<?php
/**
 * Lizenzschlüssel-Generator für das Lizenzverwaltungssystem
 */

/**
 * Generiert einen formatierten Lizenzschlüssel
 *
 * @param int $length Gesamtlänge des Schlüssels (ohne Bindestriche)
 * @param int $segments Anzahl der Segmente durch Bindestriche getrennt
 * @return string Formatierter Lizenzschlüssel
 */
function generate_license_key($length = 16, $segments = 4) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $key = '';
    
    $segment_length = $length / $segments;
    
    for ($s = 0; $s < $segments; $s++) {
        for ($i = 0; $i < $segment_length; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        if ($s < $segments - 1) {
            $key .= '-';
        }
    }
    
    return $key;
}

/**
 * Generiert einen Lizenzschlüssel und prüft, ob er bereits existiert
 *
 * @return string Ein eindeutiger Lizenzschlüssel
 */
function generate_unique_license_key() {
    global $pdo;
    
    do {
        $key = generate_license_key();
        
        // Prüfen, ob der Schlüssel bereits existiert
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE license_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetchColumn() > 0;
        
    } while ($exists);
    
    return $key;
}

/**
 * Erstellt eine neue Lizenz in der Datenbank
 *
 * @param int $customer_id Kunden-ID
 * @param int $plan_id Plan-ID
 * @param string $license_key Optional: Lizenzschlüssel (falls nicht gesetzt, wird einer generiert)
 * @param string $start_date Optional: Startdatum (falls nicht gesetzt, wird das aktuelle Datum verwendet)
 * @param string $end_date Optional: Enddatum (falls nicht gesetzt, wird basierend auf dem Plan berechnet)
 * @param string $status Optional: Status (active, inactive)
 * @return int|bool ID der neu erstellten Lizenz oder false bei Fehler
 */
function create_license($customer_id, $plan_id, $license_key = null, $start_date = null, $end_date = null, $status = 'active') {
    global $pdo;
    
    // Lizenzschlüssel generieren, falls nicht gesetzt
    if ($license_key === null) {
        $license_key = generate_unique_license_key();
    }
    
    // Startdatum festlegen, falls nicht gesetzt
    if ($start_date === null) {
        $start_date = date('Y-m-d H:i:s');
    }
    
    // Plan-Details abrufen
    $plan_stmt = $pdo->prepare("SELECT duration FROM license_plans WHERE id = ?");
    $plan_stmt->execute([$plan_id]);
    $plan = $plan_stmt->fetch();
    
    // Enddatum berechnen, falls nicht gesetzt und Dauer > 0
    if ($end_date === null && isset($plan['duration']) && $plan['duration'] > 0) {
        $end_date = date('Y-m-d H:i:s', strtotime($start_date) + ($plan['duration'] * 86400));
    }
    
    // Lizenz in der Datenbank erstellen
    $stmt = $pdo->prepare("
        INSERT INTO licenses 
        (license_key, customer_id, plan_id, start_date, end_date, status) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $license_key,
        $customer_id,
        $plan_id,
        $start_date,
        $end_date,
        $status
    ]);
    
    if ($result) {
        return $pdo->lastInsertId();
    }
    
    return false;
}

/**
 * Generiert die Antwort für eine Lizenzvalidierung
 *
 * @param array $license Lizenz-Daten aus der Datenbank
 * @param bool $is_valid Ob die Lizenz gültig ist
 * @param string $message Optional: Meldung (nur bei ungültiger Lizenz)
 * @return array Antwort-Array für die API
 */
function generate_license_response($license, $is_valid, $message = '') {
    if (!$is_valid) {
        return [
            'valid' => false,
            'message' => $message
        ];
    }
    
    // Features aus JSON decodieren
    $features = json_decode($license['features'] ?? '[]', true) ?: [];
    
    // Antwort zusammenstellen
    $response = [
        'valid' => true,
        'license_key' => $license['license_key'],
        'expires_at' => $license['end_date'],
        'features' => $features
    ];
    
    // Signatur hinzufügen
    $response['signature'] = sign_license_data($response);
    
    return $response;
}