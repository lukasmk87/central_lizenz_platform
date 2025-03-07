<?php
/**
 * Hilfsfunktionen für das Lizenzverwaltungssystem
 */

/**
 * Sicheres Ausgeben von HTML-Inhalt
 *
 * @param string $text Der auszugebende Text
 * @return string HTML-escaped Text
 */
function html_escape($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Fehler-Array in HTML-Alerts umwandeln
 *
 * @param array $errors Array mit Fehlermeldungen
 * @return string HTML-Fehlerblock
 */
function display_errors($errors) {
    if (empty($errors)) {
        return '';
    }
    
    $html = '<div class="alert alert-danger"><ul class="mb-0">';
    foreach ($errors as $error) {
        $html .= '<li>' . html_escape($error) . '</li>';
    }
    $html .= '</ul></div>';
    
    return $html;
}

/**
 * Erfolgsmeldung als HTML-Alert ausgeben
 *
 * @param string $message Die Erfolgsmeldung
 * @return string HTML-Erfolgsblock
 */
function display_success($message) {
    if (empty($message)) {
        return '';
    }
    
    return '<div class="alert alert-success">' . html_escape($message) . '</div>';
}

/**
 * Umleitung zu einer anderen Seite
 *
 * @param string $url Die Ziel-URL
 * @param bool $permanent 301 (permanent) oder 302 (temporär) Weiterleitung
 */
function redirect($url, $permanent = false) {
    header('Location: ' . $url, true, $permanent ? 301 : 302);
    exit;
}

/**
 * Formatiert ein Datum in deutsches Format
 *
 * @param string $date Datum im Format Y-m-d oder Timestamp
 * @param bool $with_time Ob die Uhrzeit auch angezeigt werden soll
 * @return string Formatiertes Datum
 */
function format_date($date, $with_time = false) {
    if (empty($date)) {
        return 'Nie';
    }
    
    if (is_numeric($date)) {
        $timestamp = $date;
    } else {
        $timestamp = strtotime($date);
    }
    
    if ($with_time) {
        return date('d.m.Y H:i', $timestamp);
    }
    
    return date('d.m.Y', $timestamp);
}

/**
 * Generiert einen sicheren Token
 *
 * @param int $length Länge des Tokens
 * @return string Generierter Token
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * CSRF-Token erstellen und in der Session speichern
 *
 * @return string CSRF-Token
 */
function generate_csrf_token() {
    $token = generate_token();
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * CSRF-Token validieren
 *
 * @param string $token Der zu überprüfende Token
 * @return bool True wenn Token gültig, sonst False
 */
function validate_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRF-Token-Formular-Feld erstellen
 *
 * @return string HTML-Input-Feld mit CSRF-Token
 */
function csrf_field() {
    $token = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Gibt den korrekten CSS-Klassenname für einen Status zurück
 *
 * @param string $status Der Status (active, inactive, expired)
 * @return string CSS-Klassenname (success, warning, danger)
 */
function get_status_color($status) {
    switch ($status) {
        case 'active': return 'success';
        case 'inactive': return 'warning';
        case 'expired': return 'danger';
        default: return 'secondary';
    }
}

/**
 * Gibt die übersetzte Bezeichnung für einen Status zurück
 *
 * @param string $status Der Status (active, inactive, expired)
 * @return string Übersetzter Statusname
 */
function get_status_label($status) {
    switch ($status) {
        case 'active': return 'Aktiv';
        case 'inactive': return 'Inaktiv';
        case 'expired': return 'Abgelaufen';
        default: return $status;
    }
}

/**
 * Dateibasiertes Caching für Abfragen
 *
 * @param string $key Eindeutiger Cache-Schlüssel
 * @param int $ttl Gültigkeitsdauer in Sekunden
 * @return mixed Gecachte Daten oder null
 */
function get_cache($key, $ttl = 3600) {
    $cache_dir = __DIR__ . '/../cache/';
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    
    $cache_file = $cache_dir . md5($key) . '.cache';
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        return unserialize(file_get_contents($cache_file));
    }
    
    return null;
}

/**
 * Daten im Cache speichern
 *
 * @param string $key Eindeutiger Cache-Schlüssel
 * @param mixed $data Zu speichernde Daten
 * @return bool Erfolg oder Misserfolg
 */
function set_cache($key, $data) {
    $cache_dir = __DIR__ . '/../cache/';
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    
    $cache_file = $cache_dir . md5($key) . '.cache';
    return file_put_contents($cache_file, serialize($data)) !== false;
}

/**
 * Cache löschen
 *
 * @param string $key Eindeutiger Cache-Schlüssel oder null um alle zu löschen
 * @return bool Erfolg oder Misserfolg
 */
function clear_cache($key = null) {
    $cache_dir = __DIR__ . '/../cache/';
    
    if (!file_exists($cache_dir)) {
        return true;
    }
    
    if ($key === null) {
        // Alle Cache-Dateien löschen
        $files = glob($cache_dir . '*.cache');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return true;
    } else {
        // Nur einen bestimmten Cache löschen
        $cache_file = $cache_dir . md5($key) . '.cache';
        if (file_exists($cache_file)) {
            return unlink($cache_file);
        }
        return true;
    }
}

/**
 * Einfaches Rate-Limiting für die API
 *
 * @param string $ip IP-Adresse
 * @param int $limit Anzahl der erlaubten Anfragen
 * @param int $period Zeitraum in Sekunden
 * @return bool True wenn erlaubt, False wenn Limit überschritten
 */
function check_rate_limit($ip, $limit = 100, $period = 3600) {
    $cache_dir = __DIR__ . '/../cache/rate_limits/';
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    
    $cache_file = $cache_dir . md5($ip) . '.txt';
    
    if (file_exists($cache_file)) {
        $data = json_decode(file_get_contents($cache_file), true);
        $now = time();
        
        // Alte Einträge entfernen
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($now, $period) {
            return $timestamp > ($now - $period);
        });
        
        // Neuen Request hinzufügen
        $data['requests'][] = $now;
        
        // Prüfen, ob Limit überschritten
        if (count($data['requests']) > $limit) {
            return false;
        }
    } else {
        $data = ['requests' => [time()]];
    }
    
    // Daten speichern
    file_put_contents($cache_file, json_encode($data));
    return true;
}

/**
 * Lizenzschlüssel-Daten signieren
 *
 * @param array $data Die zu signierenden Daten
 * @param string $secret Der geheime Schlüssel
 * @return string Die generierte Signatur
 */
function sign_license_data($data, $secret = LICENSE_SECRET) {
    return hash_hmac('sha256', json_encode($data), $secret);
}

/**
 * Signatur einer Lizenz überprüfen
 *
 * @param array $data Die zu überprüfenden Daten
 * @param string $signature Die zu überprüfende Signatur
 * @param string $secret Der geheime Schlüssel
 * @return bool True wenn Signatur gültig, sonst False
 */
function verify_license_signature($data, $signature, $secret = LICENSE_SECRET) {
    $expected_signature = hash_hmac('sha256', json_encode($data), $secret);
    return hash_equals($expected_signature, $signature);
}

/**
 * Aktuelle Seiten-URL ermitteln
 *
 * @param bool $with_query_string Mit oder ohne Query-String
 * @return string Die aktuelle URL
 */
function current_url($with_query_string = true) {
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $url .= "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    if (!$with_query_string) {
        $url = strtok($url, '?');
    }
    
    return $url;
}

/**
 * Pagination-HTML erstellen
 *
 * @param int $current_page Aktuelle Seite
 * @param int $total_pages Gesamtzahl der Seiten
 * @param string $url_pattern URL-Muster (mit %d als Platzhalter für die Seitenzahl)
 * @return string HTML für die Pagination
 */
function pagination($current_page, $total_pages, $url_pattern = '?page=%d') {
    if ($total_pages <= 1) {
        return '';
    }
    
    $html = '<nav aria-label="Pagination"><ul class="pagination justify-content-center">';
    
    // Zurück-Button
    $html .= '<li class="page-item ' . ($current_page <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . ($current_page > 1 ? sprintf($url_pattern, $current_page - 1) : '#') . '" aria-label="Zurück">';
    $html .= '<span aria-hidden="true">&laquo;</span>';
    $html .= '</a></li>';
    
    // Seitenzahlen
    $range = 2;
    for ($i = max(1, $current_page - $range); $i <= min($total_pages, $current_page + $range); $i++) {
        $html .= '<li class="page-item ' . ($i == $current_page ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . sprintf($url_pattern, $i) . '">' . $i . '</a>';
        $html .= '</li>';
    }
    
    // Weiter-Button
    $html .= '<li class="page-item ' . ($current_page >= $total_pages ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . ($current_page < $total_pages ? sprintf($url_pattern, $current_page + 1) : '#') . '" aria-label="Weiter">';
    $html .= '<span aria-hidden="true">&raquo;</span>';
    $html .= '</a></li>';
    
    $html .= '</ul></nav>';
    
    return $html;
}