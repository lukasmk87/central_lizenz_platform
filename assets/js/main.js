/**
 * JavaScript für das Lizenzverwaltungssystem
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // Automatisches Ausblenden von Alerts nach 5 Sekunden
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Tooltips initialisieren
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    
    // Popovers initialisieren
    const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
    const popoverList = [...popoverTriggerList].map(popoverTriggerEl => new bootstrap.Popover(popoverTriggerEl));
    
    // Bestätigungsdialoge für Lösch-Aktionen
    const deleteButtons = document.querySelectorAll('.delete-confirm');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            if (!confirm('Wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.')) {
                e.preventDefault();
            }
        });
    });
    
    // Datumsselektor für Datumseingabefelder initialisieren
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(function(input) {
        // Wenn kein Wert gesetzt ist, aktuelles Datum vorausfüllen
        if (input.value === '' && !input.hasAttribute('data-no-default')) {
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            input.value = `${year}-${month}-${day}`;
        }
    });
    
    // Lizenzschlüssel-Kopierfunktion
    const copyButtons = document.querySelectorAll('.copy-button');
    copyButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const textToCopy = document.getElementById(targetId).textContent || document.getElementById(targetId).value;
            
            navigator.clipboard.writeText(textToCopy).then(
                function() {
                    // Erfolgreich
                    const originalText = button.innerHTML;
                    button.innerHTML = '<i class="bi bi-check"></i> Kopiert!';
                    
                    setTimeout(function() {
                        button.innerHTML = originalText;
                    }, 2000);
                },
                function() {
                    // Fehler
                    alert('Kopieren fehlgeschlagen. Bitte manuell kopieren.');
                }
            );
        });
    });
    
    // Lizenzschlüssel formatieren
    const licenseKeyInputs = document.querySelectorAll('.license-key-input');
    licenseKeyInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            // Nur Großbuchstaben und Zahlen zulassen
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            
            // Bindestriche nach jeweils 4 Zeichen einfügen
            if (this.value.length > 0) {
                let formatted = '';
                for (let i = 0; i < this.value.length; i++) {
                    if (i > 0 && i % 4 === 0 && i < this.value.length - 1) {
                        formatted += '-';
                    }
                    formatted += this.value[i];
                }
                this.value = formatted;
            }
        });
    });
    
    // Suche mit Verzögerung (für Echtzeit-Suche)
    const searchInputs = document.querySelectorAll('.search-delay');
    let searchTimeout;
    
    searchInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            
            const form = this.closest('form');
            searchTimeout = setTimeout(function() {
                form.submit();
            }, 500);
        });
    });
    
    // Formularvalidierung initialisieren
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Automatisches Ausloggen nach Inaktivität
    let inactivityTimeout;
    const inactivityTime = 30 * 60 * 1000; // 30 Minuten
    
    function resetInactivityTimer() {
        clearTimeout(inactivityTimeout);
        inactivityTimeout = setTimeout(function() {
            window.location.href = '../index.php?session_expired=1';
        }, inactivityTime);
    }
    
    // Timer bei Aktivität zurücksetzen
    document.addEventListener('mousemove', resetInactivityTimer);
    document.addEventListener('keydown', resetInactivityTimer);
    document.addEventListener('click', resetInactivityTimer);
    
    // Timer initial starten
    resetInactivityTimer();
});