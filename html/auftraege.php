<?php
/**
 * auftraege.php – Auftragsverwaltung
 *
 * – Neuen Auftrag erstellen (mit automatischer Kostenberechnung)
 * – Aufträge auflisten (eigene für Mitarbeiter, alle für Admins)
 * – Status ändern, Rechnung aufrufen
 */

require_once __DIR__ . '/config.php';
requireLogin();

$db      = getDB();
$message = '';
$error   = '';
$calc    = null;  // Vorschau der Kalkulation (vor dem Speichern)

// -------------------------------------------------------
// Materialien laden (für Dropdown)
// -------------------------------------------------------
$stmt      = $db->query('SELECT id, name, preis_pro_kg FROM materials ORDER BY name');
$materialien = $stmt->fetchAll();

// -------------------------------------------------------
// POST-Aktionen
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // ── Neuen Auftrag anlegen ──
    if ($action === 'create') {
        $kunde          = trim($_POST['kunde']          ?? '');
        $modell_name    = trim($_POST['modell_name']    ?? '');
        $druckzeit_min  = (int)($_POST['druckzeit_min'] ?? 0);
        $gewicht_g      = (float)str_replace(',', '.', $_POST['gewicht_g']      ?? '0');
        $arbeitszeit    = (int)($_POST['arbeitszeit_min'] ?? 0);
        $material_id    = (int)($_POST['material_id']   ?? 0);

        // Eingabevalidierung
        if ($kunde === '' || $modell_name === '' || $material_id === 0) {
            $error = 'Bitte alle Pflichtfelder ausfüllen.';
        } else {
            // Materialpreis laden
            $stmt = $db->prepare('SELECT preis_pro_kg FROM materials WHERE id = ?');
            $stmt->execute([$material_id]);
            $material = $stmt->fetch();

            if (!$material) {
                $error = 'Ungültiges Material ausgewählt.';
            } else {
                // Preis berechnen
                $calc = calculatePrintCost(
                    $gewicht_g,
                    (float)$material['preis_pro_kg'],
                    $druckzeit_min,
                    $arbeitszeit
                );

                // Auftrag speichern
                $stmt = $db->prepare(
                    'INSERT INTO orders
                     (user_id, kunde, modell_name, druckzeit_min, gewicht_g, arbeitszeit_min, material_id, gesamtpreis)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $_SESSION['user_id'],
                    $kunde,
                    $modell_name,
                    $druckzeit_min,
                    $gewicht_g,
                    $arbeitszeit,
                    $material_id,
                    $calc['endpreis'],
                ]);

                $new_order_id = (int)$db->lastInsertId();

                // Materialbestand reduzieren
                $stmt = $db->prepare(
                    'UPDATE materials SET restgewicht_g = GREATEST(0, restgewicht_g - ?) WHERE id = ?'
                );
                $stmt->execute([(int)$gewicht_g, $material_id]);

                // Einnahmen-Transaktion anlegen
                $stmt = $db->prepare(
                    "INSERT INTO transactions (typ, kategorie, betrag, beschreibung, datum)
                     VALUES ('einnahme', 'auftrag', ?, ?, CURDATE())"
                );
                $stmt->execute([
                    $calc['endpreis'],
                    'Auftrag #' . $new_order_id . ': ' . $modell_name . ' für ' . $kunde,
                ]);

                $message = 'Auftrag #' . $new_order_id . ' erfolgreich angelegt. Endpreis: ' . formatEuro($calc['endpreis']);
            }
        }
    }

    // ── Status eines Auftrags ändern (Admin oder eigener Auftrag) ──
    if ($action === 'status_update') {
        $order_id   = (int)($_POST['order_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $allowed    = ['offen', 'in_bearbeitung', 'abgeschlossen', 'storniert'];

        if (in_array($new_status, $allowed, true)) {
            if (isAdmin()) {
                $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
                $stmt->execute([$new_status, $order_id]);
            } else {
                // Mitarbeiter darf nur eigene Aufträge ändern
                $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ? AND user_id = ?');
                $stmt->execute([$new_status, $order_id, $_SESSION['user_id']]);
            }
            $message = 'Status aktualisiert.';
        }
    }
}

// -------------------------------------------------------
// Aufträge laden
// -------------------------------------------------------
if (isAdmin()) {
    $stmt = $db->query(
        "SELECT o.*, m.name AS material_name, u.username
         FROM orders o
         JOIN materials m ON m.id = o.material_id
         JOIN users    u ON u.id = o.user_id
         ORDER BY o.erstellt_am DESC"
    );
} else {
    $stmt = $db->prepare(
        "SELECT o.*, m.name AS material_name, u.username
         FROM orders o
         JOIN materials m ON m.id = o.material_id
         JOIN users    u ON u.id = o.user_id
         WHERE o.user_id = ?
         ORDER BY o.erstellt_am DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
}
$auftraege = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aufträge – PrintManager</title>
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
        .calc-preview { background: var(--pico-card-background-color); border: 2px solid var(--pico-primary); border-radius: var(--pico-border-radius); padding: 1rem; margin: 1rem 0; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="app-layout">
    <nav class="sidebar no-print">
        <h2>🖨️ PrintManager</h2>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="auftraege.php" class="active">📦 Aufträge</a>
        <a href="einkauf.php">🛒 Einkauf</a>
        <?php if (isAdmin()): ?>
            <a href="einstellungen.php">⚙️ Einstellungen</a>
        <?php endif; ?>
        <div class="spacer"></div>
        <small style="color:var(--pico-muted-color)">👤 <?= e($_SESSION['username']) ?> (<?= e($_SESSION['user_role']) ?>)</small>
        <a href="logout.php" style="color:var(--pico-del-color)">🚪 Abmelden</a>
    </nav>

    <main class="main-content">
        <h1>📦 Aufträge</h1>

        <?php if ($message): ?>
            <div role="alert" style="color:#2d9e5a;padding:0.75rem;border:1px solid currentColor;border-radius:var(--pico-border-radius);margin-bottom:1rem">
                ✅ <?= e($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div role="alert" style="color:var(--pico-del-color);padding:0.75rem;border:1px solid currentColor;border-radius:var(--pico-border-radius);margin-bottom:1rem">
                ❌ <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- ── Neuen Auftrag erstellen ── -->
        <details class="card">
            <summary style="cursor:pointer;font-weight:600">➕ Neuen Auftrag anlegen</summary>
            <br>
            <form method="post" action="auftraege.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <label>
                        Kundenname *
                        <input type="text" name="kunde" placeholder="Max Mustermann" required>
                    </label>
                    <label>
                        Modellname *
                        <input type="text" name="modell_name" placeholder="Halterung XY" required>
                    </label>
                    <label>
                        Material *
                        <select name="material_id" required>
                            <option value="">– Material wählen –</option>
                            <?php foreach ($materialien as $mat): ?>
                                <option value="<?= $mat['id'] ?>">
                                    <?= e($mat['name']) ?> (<?= formatEuro((float)$mat['preis_pro_kg']) ?>/kg)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Materialverbrauch (g) *
                        <input type="number" name="gewicht_g" step="0.1" min="0.1" placeholder="50" required>
                    </label>
                    <label>
                        Druckzeit (Minuten) *
                        <input type="number" name="druckzeit_min" min="1" placeholder="120" required>
                    </label>
                    <label>
                        Manuelle Arbeitszeit (Minuten)
                        <input type="number" name="arbeitszeit_min" min="0" placeholder="30" value="0">
                    </label>
                </div>

                <button type="submit" style="margin-top:1rem">💾 Auftrag speichern &amp; Preis berechnen</button>
            </form>
        </details>

        <!-- ── Auftragsliste ── -->
        <div class="card">
            <h3><?= isAdmin() ? 'Alle Aufträge' : 'Meine Aufträge' ?></h3>
            <?php if (empty($auftraege)): ?>
                <p style="color:var(--pico-muted-color)">Noch keine Aufträge vorhanden.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Kunde</th>
                                <th>Modell</th>
                                <th>Material</th>
                                <th>Gewicht</th>
                                <th>Druckzeit</th>
                                <th>Endpreis</th>
                                <th>Status</th>
                                <?php if (isAdmin()): ?><th>Mitarbeiter</th><?php endif; ?>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auftraege as $auftrag): ?>
                                <tr>
                                    <td><?= $auftrag['id'] ?></td>
                                    <td><?= e($auftrag['kunde']) ?></td>
                                    <td><?= e($auftrag['modell_name']) ?></td>
                                    <td><?= e($auftrag['material_name']) ?></td>
                                    <td><?= number_format((float)$auftrag['gewicht_g'], 1) ?> g</td>
                                    <td><?= $auftrag['druckzeit_min'] ?> min</td>
                                    <td style="font-weight:600"><?= formatEuro((float)$auftrag['gesamtpreis']) ?></td>
                                    <td>
                                        <!-- Inline-Status-Änderung -->
                                        <form method="post" action="auftraege.php" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action"   value="status_update">
                                            <input type="hidden" name="order_id" value="<?= $auftrag['id'] ?>">
                                            <select name="new_status" onchange="this.form.submit()" style="font-size:0.8rem;padding:0.15rem 0.4rem;width:auto">
                                                <?php
                                                $stati = ['offen', 'in_bearbeitung', 'abgeschlossen', 'storniert'];
                                                foreach ($stati as $s):
                                                ?>
                                                    <option value="<?= $s ?>" <?= $s === $auftrag['status'] ? 'selected' : '' ?>>
                                                        <?= e($s) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <?php if (isAdmin()): ?>
                                        <td><?= e($auftrag['username']) ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <a href="rechnung.php?id=<?= $auftrag['id'] ?>" class="outline" style="font-size:0.8rem;padding:0.2rem 0.6rem">
                                            🧾 Rechnung
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
