<?php
/**
 * Lizenz-Client-Bibliothek für Webtools
 */

class LicenseClient {
    private $licenseKey;
    private $domain;
    private $productSlug;
    private $apiUrl;
    private $cacheFile;
    private $cacheExpiry = 604800; // 7 Tage in Sekunden
    
    /**
     * Konstruktor
     *
     * @param string $licenseKey Der Lizenzschlüssel
     * @param string $productSlug Der Produkt-Slug
     * @param string $apiUrl Die API-URL
     */
    public function __construct($licenseKey, $productSlug, $apiUrl = 'https://your-license-server.com/api') {
        $this->licenseKey = $licenseKey;
        $this->productSlug = $productSlug;
        $this->apiUrl = $apiUrl;
        $this->domain = $_SERVER['HTTP_HOST'];
        $this->cacheFile = sys_get_temp_dir() . '/license_' . md5($licenseKey) . '.json';
    }
    
    /**
     * Lizenz validieren
     *
     * @return array Validierungsergebnis
     */
    public function validate() {
        // Prüfen, ob ein gültiger Cache existiert
        if ($this->checkCache()) {
            return $this->getCachedData();
        }
        
        // Online validieren
        return $this->validateOnline();
    }
    
    /**
     * Prüft, ob ein gültiger Cache existiert
     *
     * @return bool True wenn gültiger Cache existiert, sonst False
     */
    private function checkCache() {
        if (!file_exists($this->cacheFile)) {
            return false;
        }
        
        $fileTime = filemtime($this->cacheFile);
        return (time() - $fileTime) < $this->cacheExpiry;
    }
    
    /**
     * Liest die gecachten Daten
     *
     * @return array Die gecachten Lizenzdaten
     */
    private function getCachedData() {
        $data = json_decode(file_get_contents($this->cacheFile), true);
        return $data;
    }
    
    /**
     * Validiert die Lizenz online
     *
     * @return array Validierungsergebnis
     */
    private function validateOnline() {
        $data = [
            'license_key' => $this->licenseKey,
            'domain' => $this->domain,
            'product_slug' => $this->productSlug
        ];
        
        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
                'timeout' => 10
            ]
        ];
        
        $context = stream_context_create($options);
        $result = @file_get_contents($this->apiUrl . '/validate.php', false, $context);
        
        if ($result === false) {
            // Bei Fehlern, Cache verwenden falls vorhanden, aber als potenziell abgelaufen markieren
            if (file_exists($this->cacheFile)) {
                $cachedData = $this->getCachedData();
                $cachedData['cached'] = true;
                $cachedData['cache_warning'] = 'Offline-Modus, Lizenz konnte nicht online validiert werden';
                return $cachedData;
            }
            
            throw new Exception('Lizenzserver nicht erreichbar');
        }
        
        $responseData = json_decode($result, true);
        
        // Bei gültiger Lizenz den Cache aktualisieren
        if (isset($responseData['valid']) && $responseData['valid']) {
            file_put_contents($this->cacheFile, $result);
        } elseif (file_exists($this->cacheFile)) {
            // Cache löschen, wenn die Lizenz ungültig ist
            unlink($this->cacheFile);
        }
        
        return $responseData;
    }
    
    /**
     * Prüft, ob die Lizenz ein bestimmtes Feature enthält
     *
     * @param string $featureName Der Name des Features
     * @return bool True wenn das Feature vorhanden ist, sonst False
     */
    public function hasFeature($featureName) {
        $data = $this->validate();
        if (!isset($data['valid']) || !$data['valid']) {
            return false;
        }
        
        return in_array($featureName, $data['features'] ?? []);
    }
    
    /**
     * Cache-Datei löschen um eine erneute Validierung zu erzwingen
     *
     * @return bool True bei Erfolg, sonst False
     */
    public function clearCache() {
        if (file_exists($this->cacheFile)) {
            return unlink($this->cacheFile);
        }
        return true;
    }
    
    /**
     * Prüft, ob die Lizenz gültig ist
     *
     * @return bool True wenn gültig, sonst False
     */
    public function isValid() {
        $data = $this->validate();
        return isset($data['valid']) && $data['valid'] === true;
    }
    
    /**
     * Gibt das Ablaufdatum der Lizenz zurück
     *
     * @param string $format Datumsformat (Standard: Y-m-d)
     * @return string|null Ablaufdatum oder null bei unbegrenzter Lizenz
     */
    public function getExpiryDate($format = 'Y-m-d') {
        $data = $this->validate();
        if (!isset($data['valid']) || !$data['valid'] || empty($data['expires_at'])) {
            return null;
        }
        
        return date($format, strtotime($data['expires_at']));
    }
    
    /**
     * Prüft, ob die Lizenz abgelaufen ist
     *
     * @return bool True wenn abgelaufen, sonst False
     */
    public function isExpired() {
        $data = $this->validate();
        if (!isset($data['valid']) || !$data['valid']) {
            return true;
        }
        
        if (empty($data['expires_at'])) {
            return false; // Unbegrenzte Lizenz
        }
        
        return strtotime($data['expires_at']) < time();
    }
    
    /**
     * Gibt die Rohdaten der Lizenz zurück
     *
     * @return array Lizenzdaten
     */
    public function getLicenseData() {
        return $this->validate();
    }
}

// Beispielverwendung:
// $license = new LicenseClient('YOUR-LICENSE-KEY', 'product-name');
// try {
//     $result = $license->validate();
//     if ($result['valid']) {
//         // Produkt aktivieren
//         if ($license->hasFeature('premium')) {
//             // Premium-Features anzeigen
//         }
//     } else {
//         echo "Lizenz ungültig: " . ($result['message'] ?? 'Unbekannter Fehler');
//     }
// } catch (Exception $e) {
//     echo "Fehler bei der Lizenzvalidierung: " . $e->getMessage();
// }