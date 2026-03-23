<?php
/**
 * config.php – Datenbankverbindung, globale Hilfsfunktionen & Sicherheits-Utilities
 *
 * Wird von allen anderen Seiten eingebunden:
 *   require_once __DIR__ . '/config.php';
 */

// -------------------------------------------------------
// Session starten (sicher konfiguriert)
// -------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,          // Cookie nur für Browser-Session
        'path'     => '/',
        'secure'   => false,      // Auf true setzen wenn HTTPS vorhanden
        'httponly' => true,        // Kein JavaScript-Zugriff auf das Cookie
        'samesite' => 'Strict',
    ]);
    session_start();
}

// -------------------------------------------------------
// Datenbankverbindung via PDO
// Zugangsdaten aus Umgebungsvariablen lesen (podman-compose setzt diese)
// -------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_NAME', getenv('DB_NAME') ?: 'printmanager');
define('DB_USER', getenv('DB_USER') ?: 'erp_user');
define('DB_PASS', getenv('DB_PASS') ?: 'erp_secret_pass');

/**
 * Gibt eine (gecachte) PDO-Datenbankverbindung zurück.
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Wirft Exceptions bei Fehlern
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Ergebnisse als assoziatives Array
            PDO::ATTR_EMULATE_PREPARES   => false,                    // Echte Prepared Statements
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Fehler serverseitig protokollieren, keine technischen Details nach außen geben
            error_log('DB-Verbindungsfehler: ' . $e->getMessage());
            die('<p style="color:red;font-family:sans-serif">Datenbankverbindung fehlgeschlagen. Bitte Administrator kontaktieren.</p>');
        }
    }

    return $pdo;
}

// -------------------------------------------------------
// Einstellungs-Hilfsfunktionen
// -------------------------------------------------------

/**
 * Liest einen einzelnen Wert aus der settings-Tabelle.
 */
function getSetting(string $key, string $default = ''): string
{
    $db   = getDB();
    $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $row  = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

/**
 * Schreibt/aktualisiert einen Wert in der settings-Tabelle.
 */
function setSetting(string $key, string $value): void
{
    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    );
    $stmt->execute([$key, $value]);
}

// -------------------------------------------------------
// Kalkulations-Funktion
// -------------------------------------------------------

/**
 * Berechnet den Endpreis eines 3D-Druckauftrags.
 *
 * @param float $gewicht_g        Materialverbrauch in Gramm
 * @param float $preis_pro_kg     Materialpreis in €/kg
 * @param int   $druckzeit_min    Druckzeit in Minuten
 * @param int   $arbeitszeit_min  Manuelle Arbeitszeit in Minuten
 * @return array                  Aufschlüsselung + Endpreis
 */
function calculatePrintCost(
    float $gewicht_g,
    float $preis_pro_kg,
    int   $druckzeit_min,
    int   $arbeitszeit_min = 0
): array {
    // Einstellungen aus Datenbank laden
    $strompreis   = (float) getSetting('strompreis_kwh',            '0.35');
    $watt         = (float) getSetting('drucker_watt_durchschnitt', '200');
    $verschleiss  = (float) getSetting('verschleiss_pro_stunde',    '1.50');
    $stundensatz  = (float) getSetting('stundensatz_arbeit',        '25.00');
    $aufschlag    = (float) getSetting('standard_aufschlag_prozent','20');

    // Einzelne Kostenpositionen berechnen
    $materialkosten  = ($gewicht_g / 1000) * $preis_pro_kg;
    $stromkosten     = ($druckzeit_min / 60) * ($watt / 1000) * $strompreis;
    $verschleisskosten = ($druckzeit_min / 60) * $verschleiss;
    $arbeitskosten   = ($arbeitszeit_min / 60) * $stundensatz;

    $basispreis = $materialkosten + $stromkosten + $verschleisskosten + $arbeitskosten;
    $endpreis   = $basispreis * (1 + $aufschlag / 100);

    return [
        'materialkosten'    => round($materialkosten,    2),
        'stromkosten'       => round($stromkosten,       2),
        'verschleisskosten' => round($verschleisskosten, 2),
        'arbeitskosten'     => round($arbeitskosten,     2),
        'basispreis'        => round($basispreis,        2),
        'aufschlag_prozent' => $aufschlag,
        'endpreis'          => round($endpreis,          2),
    ];
}

// -------------------------------------------------------
// CSRF-Schutz
// -------------------------------------------------------

/**
 * Generiert (oder liest) das CSRF-Token für die aktuelle Session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Überprüft, ob das gesendete CSRF-Token gültig ist.
 * Bei ungültigem Token wird die Ausführung sofort abgebrochen.
 */
function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('<p style="color:red;font-family:sans-serif">Ungültiger CSRF-Token. Bitte Seite neu laden.</p>');
    }
}

/**
 * Gibt ein verstecktes CSRF-Eingabefeld für Formulare aus.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// -------------------------------------------------------
// Authentifizierungs-Hilfsfunktionen
// -------------------------------------------------------

/**
 * Prüft, ob ein Benutzer eingeloggt ist.
 * Leitet zur Login-Seite weiter, wenn nicht.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Prüft, ob der eingeloggte Benutzer ein Admin ist.
 * Gibt einen Fehler aus wenn nicht.
 */
function requireAdmin(): void
{
    requireLogin();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('<p style="color:red;font-family:sans-serif">Kein Zugriff – nur für Administratoren.</p>');
    }
}

/**
 * Gibt zurück, ob der aktuelle Benutzer ein Admin ist.
 */
function isAdmin(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

// -------------------------------------------------------
// Ausgabe-Hilfsfunktionen
// -------------------------------------------------------

/**
 * Schützt Ausgaben vor XSS durch HTML-Escaping.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formatiert einen Betrag als Euro-Währung.
 */
function formatEuro(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' €';
}
