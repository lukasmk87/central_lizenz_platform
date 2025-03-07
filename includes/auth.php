<?php
/**
 * Authentifizierungs-Funktionen für das Lizenzverwaltungssystem
 */

/**
 * Prüft, ob ein Benutzer eingeloggt ist
 *
 * @return bool True wenn eingeloggt, sonst False
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Benutzer authentifizieren
 *
 * @param string $username Benutzername
 * @param string $password Passwort (unverschlüsselt)
 * @return bool True bei erfolgreicher Anmeldung, sonst False
 */
function authenticate_user($username, $password) {
    global $pdo;
    
    // Benutzer in der Datenbank suchen
    $stmt = $pdo->prepare("SELECT id, username, password FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    // Prüfen, ob Benutzer gefunden wurde und Passwort übereinstimmt
    if ($user && password_verify($password, $user['password'])) {
        // Sitzung starten und Benutzer-ID speichern
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        
        // Letzten Login aktualisieren
        $update_stmt = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
        $update_stmt->execute([$user['id']]);
        
        return true;
    }
    
    return false;
}

/**
 * Benutzer abmelden
 */
function logout() {
    // Sitzungsvariablen löschen
    $_SESSION = [];
    
    // Cookie löschen, das für die Sitzung verwendet wird
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Sitzung zerstören
    session_destroy();
}

/**
 * Benötigt Anmeldung
 * 
 * Leitet nicht angemeldete Benutzer zur Login-Seite weiter
 */
function require_login() {
    if (!is_logged_in()) {
        // URL speichern, zu der der Benutzer zurückkehren soll
        $_SESSION['redirect_url'] = current_url();
        redirect('../index.php');
    }
}

/**
 * Passwort-Hash erstellen
 *
 * @param string $password Das zu hashende Passwort
 * @return string Der Passwort-Hash
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Benutzerinformationen abrufen
 *
 * @param int $user_id Benutzer-ID oder null für aktuellen Benutzer
 * @return array|bool Benutzerinformationen oder false
 */
function get_user($user_id = null) {
    global $pdo;
    
    if ($user_id === null && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    if (empty($user_id)) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, username, email, last_login, created_at, updated_at
        FROM admin_users
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    
    return $stmt->fetch();
}

/**
 * Passwort ändern
 *
 * @param int $user_id Benutzer-ID
 * @param string $new_password Neues Passwort
 * @return bool Erfolg oder Misserfolg
 */
function change_password($user_id, $new_password) {
    global $pdo;
    
    $hash = hash_password($new_password);
    
    $stmt = $pdo->prepare("
        UPDATE admin_users
        SET password = ?, updated_at = NOW()
        WHERE id = ?
    ");
    
    return $stmt->execute([$hash, $user_id]);
}

/**
 * Prüfen, ob ein Passwort den Sicherheitsanforderungen entspricht
 *
 * @param string $password Das zu prüfende Passwort
 * @return array Array mit Fehlern oder leeres Array, wenn gültig
 */
function validate_password($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Das Passwort muss mindestens 8 Zeichen lang sein.";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Das Passwort muss mindestens einen Großbuchstaben enthalten.";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Das Passwort muss mindestens einen Kleinbuchstaben enthalten.";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Das Passwort muss mindestens eine Zahl enthalten.";
    }
    
    return $errors;
}