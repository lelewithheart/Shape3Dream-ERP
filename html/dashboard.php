<?php
/**
 * dashboard.php – Hauptansicht des ERP-Systems
 *
 * Zeigt rollenbasierte Widgets:
 * – Alle Benutzer: Monatsgewinn-Widget, offene Anfragen, Materialbestand
 * – Admins: Zusätzlich Finanzübersicht und Link zu Einstellungen
 */

require_once __DIR__ . '/config.php';
requireLogin();   // Weiterleitung zu index.php wenn nicht eingeloggt

$db = getDB();

// -------------------------------------------------------
// Daten für die Dashboard-Widgets laden
// -------------------------------------------------------

// Widget 1: Gewinn diesen Monat (Einnahmen – Ausgaben)
$stmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN typ='einnahme' THEN betrag ELSE 0 END), 0) AS einnahmen,
        COALESCE(SUM(CASE WHEN typ='ausgabe'  THEN betrag ELSE 0 END), 0) AS ausgaben
     FROM transactions
     WHERE YEAR(datum) = YEAR(CURDATE())
       AND MONTH(datum) = MONTH(CURDATE())"
);
$stmt->execute();
$finanzen = $stmt->fetch();
$gewinn_monat = (float)$finanzen['einnahmen'] - (float)$finanzen['ausgaben'];

// Widget 2: Anzahl offener Einkaufsanfragen
$stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM purchase_requests WHERE status = 'pending'");
$stmt->execute();
$offene_anfragen = (int)$stmt->fetchColumn();

// Widget 3: Materialbestand (alle Materialien mit Restgewicht)
$stmt = $db->query("SELECT name, restgewicht_g FROM materials ORDER BY restgewicht_g ASC");
$materialien = $stmt->fetchAll();

// Widget 4: Letzte 5 Aufträge (nur eigene für Mitarbeiter, alle für Admin)
if (isAdmin()) {
    $stmt = $db->prepare(
        "SELECT o.id, o.kunde, o.modell_name, o.gesamtpreis, o.status, o.erstellt_am, u.username
         FROM orders o
         JOIN users u ON u.id = o.user_id
         ORDER BY o.erstellt_am DESC
         LIMIT 5"
    );
    $stmt->execute();
} else {
    $stmt = $db->prepare(
        "SELECT o.id, o.kunde, o.modell_name, o.gesamtpreis, o.status, o.erstellt_am, u.username
         FROM orders o
         JOIN users u ON u.id = o.user_id
         WHERE o.user_id = ?
         ORDER BY o.erstellt_am DESC
         LIMIT 5"
    );
    $stmt->execute([$_SESSION['user_id']]);
}
$letzte_auftraege = $stmt->fetchAll();

// Widget 5 (Admin): Monatliche Transaktionen der letzten 6 Monate
$monats_chart = [];
if (isAdmin()) {
    $stmt = $db->query(
        "SELECT
            DATE_FORMAT(datum, '%Y-%m') AS monat,
            SUM(CASE WHEN typ='einnahme' THEN betrag ELSE 0 END) AS einnahmen,
            SUM(CASE WHEN typ='ausgabe'  THEN betrag ELSE 0 END) AS ausgaben
         FROM transactions
         WHERE datum >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY monat
         ORDER BY monat ASC"
    );
    $monats_chart = $stmt->fetchAll();
}

// Status-Farben für Auftrags-Badges
$status_farben = [
    'offen'          => 'secondary',
    'in_bearbeitung' => 'primary',
    'abgeschlossen'  => 'contrast',
    'storniert'      => '',
];
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – PrintManager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        /* ── Layout ── */
        .app-layout { display: flex; min-height: 100vh; }

        /* ── Sidebar-Navigation ── */
        .sidebar {
            width: 220px;
            min-height: 100vh;
            background: var(--pico-card-background-color);
            border-right: 1px solid var(--pico-muted-border-color);
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .sidebar h2 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--pico-muted-border-color);
        }
        .sidebar a {
            display: block;
            padding: 0.5rem 0.75rem;
            border-radius: var(--pico-border-radius);
            text-decoration: none;
            color: var(--pico-color);
            font-size: 0.95rem;
        }
        .sidebar a:hover, .sidebar a.active {
            background: var(--pico-primary-background);
            color: var(--pico-primary);
        }
        .sidebar .spacer { flex: 1; }

        /* ── Haupt-Inhalt ── */
        .main-content { flex: 1; padding: 2rem; overflow-y: auto; }

        /* ── Widget-Grid ── */
        .widget-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .widget {
            background: var(--pico-card-background-color);
            border: 1px solid var(--pico-muted-border-color);
            border-radius: var(--pico-border-radius);
            padding: 1.25rem;
        }
        .widget-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--pico-muted-color);
            margin-bottom: 0.5rem;
        }
        .widget-value {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .widget-value.positive { color: #2d9e5a; }
        .widget-value.negative { color: var(--pico-del-color); }

        /* ── Tabellen-Karte ── */
        .card {
            background: var(--pico-card-background-color);
            border: 1px solid var(--pico-muted-border-color);
            border-radius: var(--pico-border-radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .card h3 { margin-top: 0; font-size: 1rem; }

        /* Lager-Balken */
        .stock-bar {
            height: 8px;
            background: var(--pico-muted-border-color);
            border-radius: 4px;
            margin-top: 4px;
        }
        .stock-fill {
            height: 100%;
            border-radius: 4px;
            background: var(--pico-primary);
            transition: width 0.3s;
        }
        .stock-fill.low  { background: #e8490f; }
        .stock-fill.mid  { background: #d6a318; }
        .stock-fill.high { background: #2d9e5a; }

        /* Druck-CSS: Navigation ausblenden */
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="app-layout">

    <!-- ── Seitennavigation ── -->
    <nav class="sidebar no-print">
        <h2>🖨️ PrintManager</h2>

        <a href="dashboard.php" class="active">📊 Dashboard</a>
        <a href="auftraege.php">📦 Aufträge</a>
        <a href="einkauf.php">🛒 Einkauf</a>

        <?php if (isAdmin()): ?>
            <a href="einstellungen.php">⚙️ Einstellungen</a>
        <?php endif; ?>

        <div class="spacer"></div>

        <!-- Benutzerinfo & Logout -->
        <small style="color:var(--pico-muted-color)">
            👤 <?= e($_SESSION['username']) ?>
            (<?= e($_SESSION['user_role']) ?>)
        </small>
        <a href="logout.php" style="color:var(--pico-del-color)">🚪 Abmelden</a>
    </nav>

    <!-- ── Hauptinhalt ── -->
    <main class="main-content">
        <hgroup>
            <h1>Dashboard</h1>
            <p>Willkommen zurück, <?= e($_SESSION['username']) ?>!</p>
        </hgroup>

        <!-- ── Übersichts-Widgets ── -->
        <div class="widget-grid">
            <!-- Gewinn diesen Monat -->
            <div class="widget">
                <div class="widget-title">💰 Gewinn diesen Monat</div>
                <div class="widget-value <?= $gewinn_monat >= 0 ? 'positive' : 'negative' ?>">
                    <?= formatEuro($gewinn_monat) ?>
                </div>
                <small style="color:var(--pico-muted-color)">
                    ↑ <?= formatEuro((float)$finanzen['einnahmen']) ?> Einnahmen
                    &nbsp;|&nbsp;
                    ↓ <?= formatEuro((float)$finanzen['ausgaben']) ?> Ausgaben
                </small>
            </div>

            <!-- Offene Anfragen -->
            <div class="widget">
                <div class="widget-title">🛒 Offene Einkaufsanfragen</div>
                <div class="widget-value <?= $offene_anfragen > 0 ? 'negative' : 'positive' ?>">
                    <?= $offene_anfragen ?>
                </div>
                <small><a href="einkauf.php">Zur Anfrageliste →</a></small>
            </div>

            <!-- Materialbestand (niedrigstes Material) -->
            <?php
            $kritisches_material = null;
            foreach ($materialien as $mat) {
                if ($mat['restgewicht_g'] < 500) {
                    $kritisches_material = $mat;
                    break;
                }
            }
            ?>
            <div class="widget">
                <div class="widget-title">🧵 Materialbestand</div>
                <?php if ($kritisches_material): ?>
                    <div class="widget-value negative"><?= e($kritisches_material['name']) ?></div>
                    <small style="color:var(--pico-del-color)">
                        ⚠️ Nur noch <?= number_format($kritisches_material['restgewicht_g']) ?> g
                    </small>
                <?php else: ?>
                    <div class="widget-value positive">OK</div>
                    <small>Alle Materialien ausreichend</small>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Materialbestand (Detailliste) ── -->
        <div class="card">
            <h3>🧵 Materialbestand</h3>
            <?php
            // Maximalwert für Prozentbalken (2000 g als Referenzwert)
            $max_g = 2000;
            foreach ($materialien as $mat):
                $prozent = min(100, ($mat['restgewicht_g'] / $max_g) * 100);
                $klasse  = $prozent < 25 ? 'low' : ($prozent < 60 ? 'mid' : 'high');
            ?>
                <div style="margin-bottom:0.75rem">
                    <div style="display:flex;justify-content:space-between">
                        <span><?= e($mat['name']) ?></span>
                        <small><?= number_format($mat['restgewicht_g']) ?> g</small>
                    </div>
                    <div class="stock-bar">
                        <div class="stock-fill <?= $klasse ?>" style="width:<?= $prozent ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Letzte Aufträge ── -->
        <div class="card">
            <h3>📦 <?= isAdmin() ? 'Letzte Aufträge (alle)' : 'Meine letzten Aufträge' ?></h3>
            <?php if (empty($letzte_auftraege)): ?>
                <p style="color:var(--pico-muted-color)">Noch keine Aufträge vorhanden.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Kunde</th>
                                <th>Modell</th>
                                <?php if (isAdmin()): ?><th>Mitarbeiter</th><?php endif; ?>
                                <th>Preis</th>
                                <th>Status</th>
                                <th>Datum</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($letzte_auftraege as $auftrag): ?>
                                <tr>
                                    <td><?= $auftrag['id'] ?></td>
                                    <td><?= e($auftrag['kunde']) ?></td>
                                    <td><?= e($auftrag['modell_name']) ?></td>
                                    <?php if (isAdmin()): ?>
                                        <td><?= e($auftrag['username']) ?></td>
                                    <?php endif; ?>
                                    <td><?= formatEuro((float)$auftrag['gesamtpreis']) ?></td>
                                    <td>
                                        <span data-tooltip="<?= e($auftrag['status']) ?>">
                                            <?php
                                            $icons = [
                                                'offen'          => '🟡',
                                                'in_bearbeitung' => '🔵',
                                                'abgeschlossen'  => '🟢',
                                                'storniert'      => '🔴',
                                            ];
                                            echo $icons[$auftrag['status']] ?? '⚪';
                                            ?>
                                            <?= e($auftrag['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= date('d.m.Y', strtotime($auftrag['erstellt_am'])) ?></td>
                                    <td>
                                        <a href="rechnung.php?id=<?= $auftrag['id'] ?>" class="outline" style="font-size:0.8rem;padding:0.2rem 0.6rem">
                                            Rechnung
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <a href="auftraege.php" style="font-size:0.9rem">Alle Aufträge anzeigen →</a>
        </div>

        <?php if (isAdmin() && !empty($monats_chart)): ?>
        <!-- ── Finanzübersicht der letzten 6 Monate (nur Admin) ── -->
        <div class="card">
            <h3>📈 Finanzübersicht – letzte 6 Monate</h3>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Monat</th>
                            <th>Einnahmen</th>
                            <th>Ausgaben</th>
                            <th>Gewinn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monats_chart as $zeile):
                            $gewinn = (float)$zeile['einnahmen'] - (float)$zeile['ausgaben'];
                        ?>
                            <tr>
                                <td><?= e($zeile['monat']) ?></td>
                                <td style="color:#2d9e5a"><?= formatEuro((float)$zeile['einnahmen']) ?></td>
                                <td style="color:var(--pico-del-color)"><?= formatEuro((float)$zeile['ausgaben']) ?></td>
                                <td style="font-weight:700;color:<?= $gewinn >= 0 ? '#2d9e5a' : 'var(--pico-del-color)' ?>">
                                    <?= formatEuro($gewinn) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
