<?php
/**
 * einkauf.php – Einkaufsanfragen
 *
 * – Mitarbeiter: Neue Anfrage erstellen, eigene Anfragen sehen
 * – Admins: Alle Anfragen sehen, genehmigen oder ablehnen
 *   (Bei Genehmigung wird automatisch eine Ausgaben-Transaktion angelegt)
 */

require_once __DIR__ . '/config.php';
requireLogin();

$db      = getDB();
$message = '';
$error   = '';

// -------------------------------------------------------
// POST-Aktionen verarbeiten
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    // ── Neue Anfrage erstellen ──
    if ($action === 'create') {
        $artikel = trim($_POST['artikel_name'] ?? '');
        $preis   = (float)str_replace(',', '.', $_POST['geschaetzter_preis'] ?? '0');

        if ($artikel === '') {
            $error = 'Bitte einen Artikelnamen eingeben.';
        } else {
            $stmt = $db->prepare(
                'INSERT INTO purchase_requests (user_id, artikel_name, geschaetzter_preis)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$_SESSION['user_id'], $artikel, $preis]);
            $message = 'Anfrage erfolgreich erstellt.';
        }
    }

    // ── Anfrage genehmigen (nur Admin) ──
    if ($action === 'approve' && isAdmin()) {
        $request_id = (int)($_POST['request_id'] ?? 0);

        // Anfrage laden
        $stmt = $db->prepare('SELECT * FROM purchase_requests WHERE id = ?');
        $stmt->execute([$request_id]);
        $anfrage = $stmt->fetch();

        if ($anfrage && $anfrage['status'] === 'pending') {
            // Status auf "approved" setzen
            $stmt = $db->prepare("UPDATE purchase_requests SET status = 'approved' WHERE id = ?");
            $stmt->execute([$request_id]);

            // Automatisch eine Ausgaben-Transaktion anlegen
            $stmt = $db->prepare(
                "INSERT INTO transactions (typ, kategorie, betrag, beschreibung, datum)
                 VALUES ('ausgabe', 'material', ?, ?, CURDATE())"
            );
            $beschreibung = 'Einkauf: ' . $anfrage['artikel_name'];
            $stmt->execute([$anfrage['geschaetzter_preis'], $beschreibung]);

            $message = 'Anfrage genehmigt und Ausgabe in Finanzbuchhaltung eingetragen.';
        }
    }

    // ── Anfrage ablehnen (nur Admin) ──
    if ($action === 'reject' && isAdmin()) {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $stmt = $db->prepare("UPDATE purchase_requests SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$request_id]);
        $message = 'Anfrage abgelehnt.';
    }
}

// -------------------------------------------------------
// Anfragen aus der Datenbank laden
// -------------------------------------------------------
if (isAdmin()) {
    // Admin sieht alle Anfragen
    $stmt = $db->query(
        "SELECT pr.*, u.username
         FROM purchase_requests pr
         JOIN users u ON u.id = pr.user_id
         ORDER BY pr.erstellt_am DESC"
    );
} else {
    // Mitarbeiter sehen nur eigene Anfragen
    $stmt = $db->prepare(
        "SELECT pr.*, u.username
         FROM purchase_requests pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.user_id = ?
         ORDER BY pr.erstellt_am DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
}
$anfragen = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einkauf – PrintManager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        .app-layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 220px; min-height: 100vh;
            background: var(--pico-card-background-color);
            border-right: 1px solid var(--pico-muted-border-color);
            padding: 1.5rem 1rem;
            display: flex; flex-direction: column; gap: 0.5rem;
        }
        .sidebar h2 { font-size: 1.1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--pico-muted-border-color); }
        .sidebar a { display: block; padding: 0.5rem 0.75rem; border-radius: var(--pico-border-radius); text-decoration: none; color: var(--pico-color); font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background: var(--pico-primary-background); color: var(--pico-primary); }
        .sidebar .spacer { flex: 1; }
        .main-content { flex: 1; padding: 2rem; }
        .card { background: var(--pico-card-background-color); border: 1px solid var(--pico-muted-border-color); border-radius: var(--pico-border-radius); padding: 1.25rem; margin-bottom: 1.5rem; }
        .badge-pending  { color: #d6a318; font-weight: 600; }
        .badge-approved { color: #2d9e5a; font-weight: 600; }
        .badge-rejected { color: var(--pico-del-color); font-weight: 600; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="app-layout">
    <nav class="sidebar no-print">
        <h2>🖨️ PrintManager</h2>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="auftraege.php">📦 Aufträge</a>
        <a href="einkauf.php" class="active">🛒 Einkauf</a>
        <?php if (isAdmin()): ?>
            <a href="einstellungen.php">⚙️ Einstellungen</a>
        <?php endif; ?>
        <div class="spacer"></div>
        <small style="color:var(--pico-muted-color)">👤 <?= e($_SESSION['username']) ?> (<?= e($_SESSION['user_role']) ?>)</small>
        <a href="logout.php" style="color:var(--pico-del-color)">🚪 Abmelden</a>
    </nav>

    <main class="main-content">
        <h1>🛒 Einkaufsanfragen</h1>

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

        <!-- ── Neue Anfrage erstellen ── -->
        <div class="card">
            <h3>Neue Anfrage erstellen</h3>
            <form method="post" action="einkauf.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:1rem;align-items:end">
                    <label>
                        Artikelname / Beschreibung
                        <input type="text" name="artikel_name" placeholder="z.B. PLA Filament 1kg (schwarz)" required>
                    </label>
                    <label>
                        Geschätzter Preis (€)
                        <input type="number" name="geschaetzter_preis" step="0.01" min="0" placeholder="0.00">
                    </label>
                    <button type="submit" style="margin-bottom:0">Anfrage stellen</button>
                </div>
            </form>
        </div>

        <!-- ── Anfragenliste ── -->
        <div class="card">
            <h3><?= isAdmin() ? 'Alle Anfragen' : 'Meine Anfragen' ?></h3>
            <?php if (empty($anfragen)): ?>
                <p style="color:var(--pico-muted-color)">Keine Anfragen vorhanden.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <?php if (isAdmin()): ?><th>Mitarbeiter</th><?php endif; ?>
                                <th>Artikel</th>
                                <th>Geschätzter Preis</th>
                                <th>Status</th>
                                <th>Datum</th>
                                <?php if (isAdmin()): ?><th>Aktionen</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($anfragen as $anfrage): ?>
                                <tr>
                                    <td><?= $anfrage['id'] ?></td>
                                    <?php if (isAdmin()): ?>
                                        <td><?= e($anfrage['username']) ?></td>
                                    <?php endif; ?>
                                    <td><?= e($anfrage['artikel_name']) ?></td>
                                    <td><?= formatEuro((float)$anfrage['geschaetzter_preis']) ?></td>
                                    <td>
                                        <?php
                                        $status_labels = [
                                            'pending'  => ['label' => '⏳ Ausstehend', 'class' => 'badge-pending'],
                                            'approved' => ['label' => '✅ Genehmigt',  'class' => 'badge-approved'],
                                            'rejected' => ['label' => '❌ Abgelehnt',  'class' => 'badge-rejected'],
                                        ];
                                        $sl = $status_labels[$anfrage['status']] ?? ['label' => $anfrage['status'], 'class' => ''];
                                        ?>
                                        <span class="<?= $sl['class'] ?>"><?= $sl['label'] ?></span>
                                    </td>
                                    <td><?= date('d.m.Y', strtotime($anfrage['erstellt_am'])) ?></td>
                                    <?php if (isAdmin()): ?>
                                        <td>
                                            <?php if ($anfrage['status'] === 'pending'): ?>
                                                <!-- Genehmigen-Button -->
                                                <form method="post" action="einkauf.php" style="display:inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action"     value="approve">
                                                    <input type="hidden" name="request_id" value="<?= $anfrage['id'] ?>">
                                                    <button type="submit" style="font-size:0.8rem;padding:0.2rem 0.7rem" class="outline">
                                                        ✅ Genehmigen
                                                    </button>
                                                </form>
                                                <!-- Ablehnen-Button -->
                                                <form method="post" action="einkauf.php" style="display:inline;margin-left:0.3rem">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action"     value="reject">
                                                    <input type="hidden" name="request_id" value="<?= $anfrage['id'] ?>">
                                                    <button type="submit" style="font-size:0.8rem;padding:0.2rem 0.7rem;color:var(--pico-del-color)" class="outline">
                                                        ❌ Ablehnen
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:var(--pico-muted-color);font-size:0.85rem">–</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
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
