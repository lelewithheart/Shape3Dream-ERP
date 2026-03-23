<?php
/**
 * einstellungen.php – Globale Konfiguration (nur Admin)
 *
 * Ermöglicht dem Admin, die globalen Kosten-Parameter zu ändern:
 * – Strompreis, Drucker-Watt, Verschleiß, Stundensatz, Aufschlag
 * – Firmenangaben (für Rechnungen)
 */

require_once __DIR__ . '/config.php';
requireAdmin();   // Nur für Admins; wirft 403 wenn kein Admin

$db      = getDB();
$message = '';

// -------------------------------------------------------
// Einstellungen speichern (POST)
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Numerische Konfigurationswerte
    $felder = [
        'strompreis_kwh',
        'drucker_watt_durchschnitt',
        'verschleiss_pro_stunde',
        'stundensatz_arbeit',
        'standard_aufschlag_prozent',
        'firma_name',
        'firma_adresse',
        'firma_email',
    ];

    foreach ($felder as $feld) {
        if (isset($_POST[$feld])) {
            setSetting($feld, trim($_POST[$feld]));
        }
    }

    // Neues Material hinzufügen
    if (!empty($_POST['material_name'])) {
        $mat_name  = trim($_POST['material_name']);
        $mat_preis = (float)str_replace(',', '.', $_POST['material_preis'] ?? '0');
        $mat_stock = (int)($_POST['material_stock'] ?? 0);

        $stmt = $db->prepare(
            'INSERT INTO materials (name, preis_pro_kg, restgewicht_g) VALUES (?, ?, ?)'
        );
        $stmt->execute([$mat_name, $mat_preis, $mat_stock]);
    }

    // Materialbestand aktualisieren
    if (!empty($_POST['mat_ids'])) {
        foreach ($_POST['mat_ids'] as $mat_id) {
            $mat_id    = (int)$mat_id;
            $new_stock = (int)($_POST['mat_stock'][$mat_id] ?? 0);
            $new_preis = (float)str_replace(',', '.', $_POST['mat_preis'][$mat_id] ?? '0');

            $stmt = $db->prepare(
                'UPDATE materials SET restgewicht_g = ?, preis_pro_kg = ? WHERE id = ?'
            );
            $stmt->execute([$new_stock, $new_preis, $mat_id]);
        }
    }

    $message = 'Einstellungen gespeichert.';
}

// -------------------------------------------------------
// Aktuelle Werte laden
// -------------------------------------------------------
$settings = [];
$stmt = $db->query('SELECT `key`, `value` FROM settings');
foreach ($stmt->fetchAll() as $row) {
    $settings[$row['key']] = $row['value'];
}

// Materialien laden
$stmt      = $db->query('SELECT * FROM materials ORDER BY name');
$materialien = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen – PrintManager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        .app-layout { display: flex; min-height: 100vh; }
        .sidebar { width: 220px; min-height: 100vh; background: var(--pico-card-background-color); border-right: 1px solid var(--pico-muted-border-color); padding: 1.5rem 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
        .sidebar h2 { font-size: 1.1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--pico-muted-border-color); }
        .sidebar a { display: block; padding: 0.5rem 0.75rem; border-radius: var(--pico-border-radius); text-decoration: none; color: var(--pico-color); font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: var(--pico-primary-background); color: var(--pico-primary); }
        .sidebar .spacer { flex: 1; }
        .main-content { flex: 1; padding: 2rem; }
        .card { background: var(--pico-card-background-color); border: 1px solid var(--pico-muted-border-color); border-radius: var(--pico-border-radius); padding: 1.25rem; margin-bottom: 1.5rem; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="app-layout">
    <nav class="sidebar no-print">
        <h2>🖨️ PrintManager</h2>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="auftraege.php">📦 Aufträge</a>
        <a href="einkauf.php">🛒 Einkauf</a>
        <a href="einstellungen.php" class="active">⚙️ Einstellungen</a>
        <div class="spacer"></div>
        <small style="color:var(--pico-muted-color)">👤 <?= e($_SESSION['username']) ?> (<?= e($_SESSION['user_role']) ?>)</small>
        <a href="logout.php" style="color:var(--pico-del-color)">🚪 Abmelden</a>
    </nav>

    <main class="main-content">
        <h1>⚙️ Einstellungen</h1>

        <?php if ($message): ?>
            <div role="alert" style="color:#2d9e5a;padding:0.75rem;border:1px solid currentColor;border-radius:var(--pico-border-radius);margin-bottom:1rem">
                ✅ <?= e($message) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="einstellungen.php">
            <?= csrfField() ?>

            <!-- ── Firmenangaben ── -->
            <div class="card">
                <h3>🏢 Firmeninformationen</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <label>
                        Firmenname
                        <input type="text" name="firma_name" value="<?= e($settings['firma_name'] ?? '') ?>" placeholder="Shape3Dream">
                    </label>
                    <label>
                        E-Mail
                        <input type="email" name="firma_email" value="<?= e($settings['firma_email'] ?? '') ?>" placeholder="info@firma.de">
                    </label>
                    <label style="grid-column:1/-1">
                        Adresse
                        <input type="text" name="firma_adresse" value="<?= e($settings['firma_adresse'] ?? '') ?>" placeholder="Straße, PLZ Ort">
                    </label>
                </div>
            </div>

            <!-- ── Kalkulations-Parameter ── -->
            <div class="card">
                <h3>🧮 Kalkulations-Parameter</h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
                    <label>
                        Strompreis (€/kWh)
                        <input type="number" step="0.001" name="strompreis_kwh"
                               value="<?= e($settings['strompreis_kwh'] ?? '0.35') ?>">
                        <small>Aktueller Arbeitspreis Ihrer Stromversorgung</small>
                    </label>
                    <label>
                        Drucker Durchschnittsleistung (Watt)
                        <input type="number" step="1" name="drucker_watt_durchschnitt"
                               value="<?= e($settings['drucker_watt_durchschnitt'] ?? '200') ?>">
                        <small>Watt Verbrauch des Druckers im Betrieb</small>
                    </label>
                    <label>
                        Verschleiß (€/Stunde)
                        <input type="number" step="0.01" name="verschleiss_pro_stunde"
                               value="<?= e($settings['verschleiss_pro_stunde'] ?? '1.50') ?>">
                        <small>Reparatur &amp; Abnutzungskosten pro Druckstunde</small>
                    </label>
                    <label>
                        Stundensatz Arbeit (€/Stunde)
                        <input type="number" step="0.01" name="stundensatz_arbeit"
                               value="<?= e($settings['stundensatz_arbeit'] ?? '25.00') ?>">
                        <small>Lohnkosten pro Arbeitsstunde</small>
                    </label>
                    <label>
                        Standard-Aufschlag (%)
                        <input type="number" step="1" name="standard_aufschlag_prozent"
                               value="<?= e($settings['standard_aufschlag_prozent'] ?? '20') ?>">
                        <small>Gewinnmarge auf die Selbstkosten</small>
                    </label>
                </div>
            </div>

            <button type="submit">💾 Einstellungen speichern</button>
        </form>

        <!-- ── Materialverwaltung ── -->
        <form method="post" action="einstellungen.php" style="margin-top:2rem">
            <?= csrfField() ?>
            <div class="card">
                <h3>🧵 Materialien verwalten</h3>

                <?php if (!empty($materialien)): ?>
                    <div style="overflow-x:auto">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Preis/kg (€)</th>
                                    <th>Restbestand (g)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materialien as $mat): ?>
                                    <tr>
                                        <td><?= e($mat['name']) ?></td>
                                        <td>
                                            <input type="hidden" name="mat_ids[]" value="<?= $mat['id'] ?>">
                                            <input type="number" step="0.01"
                                                   name="mat_preis[<?= $mat['id'] ?>]"
                                                   value="<?= e($mat['preis_pro_kg']) ?>"
                                                   style="width:120px">
                                        </td>
                                        <td>
                                            <input type="number" step="1"
                                                   name="mat_stock[<?= $mat['id'] ?>]"
                                                   value="<?= e($mat['restgewicht_g']) ?>"
                                                   style="width:120px">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Neues Material hinzufügen -->
                <hr>
                <h4>➕ Neues Material hinzufügen</h4>
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:1rem;align-items:end">
                    <label>
                        Materialname
                        <input type="text" name="material_name" placeholder="z.B. ASA">
                    </label>
                    <label>
                        Preis/kg (€)
                        <input type="number" step="0.01" name="material_preis" placeholder="30.00">
                    </label>
                    <label>
                        Startbestand (g)
                        <input type="number" step="1" name="material_stock" placeholder="1000">
                    </label>
                    <button type="submit" style="margin-bottom:0">Hinzufügen</button>
                </div>
            </div>
        </form>
    </main>
</div>
</body>
</html>
