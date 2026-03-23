<?php
/**
 * index.php – Login-Formular & zentrales Routing
 *
 * Diese Datei dient als einziger Einstiegspunkt.
 * – Wenn der Benutzer eingeloggt ist → Weiterleitung zum Dashboard
 * – Wenn ein POST-Request kommt    → Login verarbeiten
 * – Sonst                          → Login-Formular anzeigen
 */

require_once __DIR__ . '/config.php';

// Bereits eingeloggt? → direkt zum Dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

// -------------------------------------------------------
// Login-Logik (POST-Request)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Einfache Eingabevalidierung
    if ($username === '' || $password === '') {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } else {
        // Benutzer in der Datenbank suchen
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Passwort prüfen
        if ($user && password_verify($password, $user['password_hash'])) {
            // Login erfolgreich → Session neu generieren (Session-Fixation verhindern)
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['user_role'] = $user['role'];

            header('Location: dashboard.php');
            exit;
        } else {
            // Fehlermeldung absichtlich vage halten (kein Hinweis ob User oder Passwort falsch)
            $error = 'Ungültiger Benutzername oder Passwort.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PrintManager – Login</title>
    <!-- Pico.css: minimalistisches CSS-Framework ohne Build-Prozess -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        /* Login-Seite zentriert darstellen */
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: var(--pico-background-color);
        }
        .login-card {
            width: 100%;
            max-width: 380px;
            padding: 2rem;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .login-logo h1 {
            font-size: 1.6rem;
            margin-bottom: 0.2rem;
        }
        .login-logo p {
            color: var(--pico-muted-color);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <article class="login-card">
        <div class="login-logo">
            <h1>🖨️ PrintManager</h1>
            <p>3D-Druck ERP System</p>
        </div>

        <?php if ($error !== ''): ?>
            <!-- Fehlermeldung anzeigen -->
            <div role="alert" style="color:var(--pico-del-color);padding:0.5rem;margin-bottom:1rem;border:1px solid currentColor;border-radius:var(--pico-border-radius)">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <!-- CSRF-Schutzfeld (versteckt) -->
            <?= csrfField() ?>

            <label for="username">Benutzername</label>
            <input
                type="text"
                id="username"
                name="username"
                placeholder="Benutzername"
                value="<?= e($_POST['username'] ?? '') ?>"
                required
                autocomplete="username"
            >

            <label for="password">Passwort</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="Passwort"
                required
                autocomplete="current-password"
            >

            <button type="submit" style="width:100%;margin-top:0.5rem">
                Anmelden
            </button>
        </form>
    </article>
</body>
</html>
