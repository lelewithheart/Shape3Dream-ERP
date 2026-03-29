<?php
/**
 * logout.php – Benutzer abmelden
 *
 * Zerstört die aktuelle Session und leitet zur Login-Seite weiter.
 */

require_once __DIR__ . '/config.php';

// Session-Daten löschen
$_SESSION = [];

// Session-Cookie löschen (falls gesetzt)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Session in der Datenbank/Datei zerstören
session_destroy();

// Zur Login-Seite weiterleiten
header('Location: index.php');
exit;
