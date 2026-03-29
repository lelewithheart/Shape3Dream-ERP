<?php
/**
 * rechnung.php – Druckoptimierte Rechnungsansicht
 *
 * Zeigt eine saubere Rechnungsseite mit:
 * – Firmenlogo/Firmendaten oben rechts
 * – Kundendaten links
 * – Kostenaufstellung als Tabelle
 * – CSS @media print: Navigation und Aktions-Buttons werden ausgeblendet
 */

require_once __DIR__ . '/config.php';
requireLogin();

$db = getDB();

// Auftrags-ID aus GET-Parameter lesen
$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) {
    header('Location: auftraege.php');
    exit;
}

// Auftrag laden (mit Material- und Benutzerdaten)
$stmt = $db->prepare(
    "SELECT o.*, m.name AS material_name, m.preis_pro_kg,
            u.username,
            o.kunde AS rechnungs_empfaenger
     FROM orders o
     JOIN materials m ON m.id = o.material_id
     JOIN users    u ON u.id = o.user_id
     WHERE o.id = ?"
);
$stmt->execute([$order_id]);
$auftrag = $stmt->fetch();

// Sicherheitscheck: Existiert der Auftrag?
if (!$auftrag) {
    http_response_code(404);
    die('<p>Auftrag nicht gefunden.</p>');
}

// Mitarbeiter dürfen nur eigene Aufträge sehen
if (!isAdmin() && (int)$auftrag['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    die('<p>Kein Zugriff auf diesen Auftrag.</p>');
}

// Kostenaufstellung neu berechnen (für die Darstellung)
$kalkulation = calculatePrintCost(
    (float)$auftrag['gewicht_g'],
    (float)$auftrag['preis_pro_kg'],
    (int)$auftrag['druckzeit_min'],
    (int)$auftrag['arbeitszeit_min']
);

// Firmenangaben aus Einstellungen
$firma_name    = getSetting('firma_name',    'Shape3Dream');
$firma_adresse = getSetting('firma_adresse', '');
$firma_email   = getSetting('firma_email',   '');

// Rechnungsnummer aus Datum + ID
$rechnungsnummer = 'INV-' . date('Y') . '-' . str_pad($auftrag['id'], 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rechnung <?= e($rechnungsnummer) ?> – PrintManager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        /* ── Bildschirm-Styles ── */
        body { max-width: 900px; margin: 0 auto; padding: 2rem; }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2.5rem;
        }
        .company-block { text-align: right; }
        .company-block h2 { font-size: 1.4rem; margin-bottom: 0.2rem; }
        .company-block p  { margin: 0; color: var(--pico-muted-color); font-size: 0.9rem; }

        .customer-block h3 { font-size: 1rem; margin-bottom: 0.3rem; }
        .customer-block p  { margin: 0.1rem 0; font-size: 0.9rem; }

        .invoice-meta {
            display: flex;
            justify-content: space-between;
            background: var(--pico-card-background-color);
            border: 1px solid var(--pico-muted-border-color);
            border-radius: var(--pico-border-radius);
            padding: 0.8rem 1.2rem;
            margin-bottom: 2rem;
        }
        .invoice-meta div { text-align: center; }
        .invoice-meta small { color: var(--pico-muted-color); display: block; font-size: 0.75rem; }
        .invoice-meta strong { font-size: 1rem; }

        .totals-table { margin-left: auto; width: 300px; }
        .totals-table td { padding: 0.3rem 0.5rem; }
        .totals-table .total-row td {
            font-weight: 700;
            font-size: 1.1rem;
            border-top: 2px solid var(--pico-muted-border-color);
        }

        .invoice-footer {
            margin-top: 3rem;
            padding-top: 1rem;
            border-top: 1px solid var(--pico-muted-border-color);
            font-size: 0.8rem;
            color: var(--pico-muted-color);
            text-align: center;
        }

        /* ── Aktions-Leiste (wird beim Drucken ausgeblendet) ── */
        .action-bar {
            position: sticky;
            top: 0;
            background: var(--pico-background-color);
            border-bottom: 1px solid var(--pico-muted-border-color);
            padding: 0.75rem 1rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            z-index: 10;
            margin: -2rem -2rem 2rem;
            padding-left: 2rem;
            padding-right: 2rem;
        }

        /* ── Drucken-spezifische Styles ── */
        @media print {
            /* Alles ausblenden was nicht auf die Rechnung gehört */
            .no-print { display: none !important; }

            body { max-width: 100%; padding: 0; margin: 0; }

            /* Seitenumbrüche verhindern */
            table { page-break-inside: avoid; }

            /* Hintergrundfarben auch beim Drucken */
            @page { margin: 1.5cm; }
        }
    </style>
</head>
<body>

    <!-- ── Aktions-Leiste (nur Bildschirm) ── -->
    <div class="action-bar no-print">
        <a href="auftraege.php">← Zurück zu Aufträgen</a>
        <span style="flex:1"></span>
        <button onclick="window.print()" class="outline">🖨️ Als PDF drucken / speichern</button>
    </div>

    <!-- ── Rechnungs-Kopf ── -->
    <div class="invoice-header">
        <!-- Kundendaten (links) -->
        <div class="customer-block">
            <small style="color:var(--pico-muted-color)">RECHNUNGSEMPFÄNGER</small>
            <h3><?= e($auftrag['kunde']) ?></h3>
        </div>

        <!-- Firmenangaben (rechts) -->
        <div class="company-block">
            <h2>🖨️ <?= e($firma_name) ?></h2>
            <?php if ($firma_adresse): ?>
                <p><?= e($firma_adresse) ?></p>
            <?php endif; ?>
            <?php if ($firma_email): ?>
                <p><?= e($firma_email) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Rechnungs-Metadaten ── -->
    <div class="invoice-meta">
        <div>
            <small>Rechnungsnummer</small>
            <strong><?= e($rechnungsnummer) ?></strong>
        </div>
        <div>
            <small>Auftragsdatum</small>
            <strong><?= date('d.m.Y', strtotime($auftrag['erstellt_am'])) ?></strong>
        </div>
        <div>
            <small>Status</small>
            <strong><?= e($auftrag['status']) ?></strong>
        </div>
        <div>
            <small>Bearbeitet von</small>
            <strong><?= e($auftrag['username']) ?></strong>
        </div>
    </div>

    <!-- ── Rechnungs-Titel ── -->
    <h2>Rechnung</h2>

    <!-- ── Auftragsdetails ── -->
    <table>
        <thead>
            <tr>
                <th>Pos.</th>
                <th>Beschreibung</th>
                <th>Details</th>
                <th style="text-align:right">Betrag</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>
                    <strong>3D-Druck: <?= e($auftrag['modell_name']) ?></strong><br>
                    <small>Material: <?= e($auftrag['material_name']) ?></small>
                </td>
                <td>
                    <small>
                        Gewicht: <?= number_format((float)$auftrag['gewicht_g'], 1) ?> g<br>
                        Druckzeit: <?= $auftrag['druckzeit_min'] ?> min
                        (<?= number_format($auftrag['druckzeit_min'] / 60, 1) ?> h)
                        <?php if ($auftrag['arbeitszeit_min'] > 0): ?>
                            <br>Arbeitszeit: <?= $auftrag['arbeitszeit_min'] ?> min
                        <?php endif; ?>
                    </small>
                </td>
                <td style="text-align:right"><?= formatEuro($kalkulation['basispreis']) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- ── Kostenaufstellung (Detailansicht) ── -->
    <details style="margin:1rem 0">
        <summary style="cursor:pointer;color:var(--pico-muted-color);font-size:0.9rem">
            Kostenaufstellung anzeigen
        </summary>
        <table style="margin-top:0.5rem">
            <tbody>
                <tr>
                    <td>Materialkosten</td>
                    <td style="text-align:right"><?= formatEuro($kalkulation['materialkosten']) ?></td>
                </tr>
                <tr>
                    <td>Stromkosten</td>
                    <td style="text-align:right"><?= formatEuro($kalkulation['stromkosten']) ?></td>
                </tr>
                <tr>
                    <td>Verschleiß &amp; Abnutzung</td>
                    <td style="text-align:right"><?= formatEuro($kalkulation['verschleisskosten']) ?></td>
                </tr>
                <?php if ($kalkulation['arbeitskosten'] > 0): ?>
                <tr>
                    <td>Arbeitskosten</td>
                    <td style="text-align:right"><?= formatEuro($kalkulation['arbeitskosten']) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="font-weight:600">
                    <td>Zwischensumme</td>
                    <td style="text-align:right"><?= formatEuro($kalkulation['basispreis']) ?></td>
                </tr>
                <tr>
                    <td>Gewinn-Aufschlag (<?= $kalkulation['aufschlag_prozent'] ?>%)</td>
                    <td style="text-align:right">
                        <?= formatEuro($kalkulation['endpreis'] - $kalkulation['basispreis']) ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </details>

    <!-- ── Gesamtbetrag ── -->
    <table class="totals-table">
        <tbody>
            <tr>
                <td>Zwischensumme (netto)</td>
                <td style="text-align:right"><?= formatEuro($kalkulation['basispreis']) ?></td>
            </tr>
            <tr>
                <td>Aufschlag (<?= $kalkulation['aufschlag_prozent'] ?>%)</td>
                <td style="text-align:right">
                    <?= formatEuro($kalkulation['endpreis'] - $kalkulation['basispreis']) ?>
                </td>
            </tr>
            <tr class="total-row">
                <td>Gesamtbetrag</td>
                <td style="text-align:right"><?= formatEuro((float)$auftrag['gesamtpreis']) ?></td>
            </tr>
        </tbody>
    </table>

    <?php if (!empty($auftrag['notizen'])): ?>
    <div style="margin-top:2rem;padding:1rem;background:var(--pico-card-background-color);border-radius:var(--pico-border-radius);border:1px solid var(--pico-muted-border-color)">
        <strong>Notizen:</strong><br>
        <?= nl2br(e($auftrag['notizen'])) ?>
    </div>
    <?php endif; ?>

    <!-- ── Fußzeile ── -->
    <div class="invoice-footer">
        <p>
            <?= e($firma_name) ?>
            <?php if ($firma_adresse): ?> · <?= e($firma_adresse) ?><?php endif; ?>
            <?php if ($firma_email): ?> · <?= e($firma_email) ?><?php endif; ?>
        </p>
        <p>Generiert am <?= date('d.m.Y H:i') ?> Uhr durch PrintManager-Native</p>
    </div>

</body>
</html>
