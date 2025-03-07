<?php
/**
 * Datenbankverbindung für das Lizenzverwaltungssystem
 */

if (!defined('INSTALLED') || INSTALLED !== true) {
    header('Location: install/index.php');
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    if (DEBUG_MODE) {
        die("Datenbankverbindung fehlgeschlagen: " . $e->getMessage());
    } else {
        die("Datenbankverbindung fehlgeschlagen. Bitte prüfen Sie Ihre Konfiguration oder kontaktieren Sie den Administrator.");
    }
}

/**
 * Funktion zum sicheren Ausführen von SQL-Abfragen
 *
 * @param string $sql SQL-Abfrage mit Platzhaltern
 * @param array $params Array mit den Parametern
 * @return array|bool Array mit Ergebnissen oder false bei Fehler
 */
function db_query($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Prüfen, ob es sich um eine SELECT-Abfrage handelt
        if (stripos(trim($sql), 'SELECT') === 0) {
            return $stmt->fetchAll();
        }
        
        return true;
    } catch (PDOException $e) {
        if (DEBUG_MODE) {
            die("Datenbankfehler: " . $e->getMessage());
        }
        return false;
    }
}

/**
 * Funktion zum Abrufen eines einzelnen Datensatzes
 *
 * @param string $sql SQL-Abfrage mit Platzhaltern
 * @param array $params Array mit den Parametern
 * @return array|bool Der erste Datensatz oder false bei Fehler
 */
function db_query_single($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    } catch (PDOException $e) {
        if (DEBUG_MODE) {
            die("Datenbankfehler: " . $e->getMessage());
        }
        return false;
    }
}

/**
 * Funktion zum Einfügen von Daten und Rückgabe der ID
 *
 * @param string $sql SQL-Insert-Abfrage mit Platzhaltern
 * @param array $params Array mit den Parametern
 * @return int|bool Die ID des eingefügten Datensatzes oder false bei Fehler
 */
function db_insert($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (DEBUG_MODE) {
            die("Datenbankfehler: " . $e->getMessage());
        }
        return false;
    }
}

/**
 * Funktion zum Aktualisieren oder Löschen von Daten
 *
 * @param string $sql SQL-Update/Delete-Abfrage mit Platzhaltern
 * @param array $params Array mit den Parametern
 * @return int|bool Die Anzahl der betroffenen Zeilen oder false bei Fehler
 */
function db_update($sql, $params = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        if (DEBUG_MODE) {
            die("Datenbankfehler: " . $e->getMessage());
        }
        return false;
    }
}