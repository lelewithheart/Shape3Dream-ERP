# Shape3Dream-ERP – PrintManager-Native

Internes ERP-System für eine 3D-Druckerei. Reines PHP 8.3, MariaDB, Apache, Pico.css.

## Schnellstart (keine extra Setup-Schritte nötig)

```bash
# 1. Repository klonen
git clone <repo-url>
cd Shape3Dream-ERP

# 2. Container bauen und starten – funktioniert sofort ohne .env
podman-compose up --build -d

# 3. Im Browser öffnen
# http://localhost:8080
# Login: admin / admin123  (Passwort nach erstem Login ändern!)
```

> **Optional – eigene Passwörter setzen (empfohlen für Produktion):**
> ```bash
> cp .env.example .env
> # .env öffnen und DB_PASS / DB_ROOT_PASS auf sichere Werte setzen
> podman-compose up --build -d
> ```
>
> ⚠️ Die eingebauten Standardpasswörter sind **nur für lokale Entwicklung** gedacht — niemals in Produktion verwenden!

## Technologie-Stack

| Schicht | Technologie |
|---------|-------------|
| Sprache | PHP 8.3 (Vanilla, kein Framework) |
| Datenbank | MariaDB 11.4 |
| Webserver | Apache (in Podman) |
| Frontend | Pico.css CDN |
| Container | podman-compose |

## Dateistruktur

```
├── Dockerfile              # PHP 8.3 + Apache + pdo_mysql
├── podman-compose.yml      # Web- und DB-Service
├── db_setup.sql            # Datenbankschema + Seed-Daten
├── .env.example            # Vorlage für Umgebungsvariablen
└── html/
    ├── config.php          # DB-Verbindung, CSRF, Hilfsfunktionen
    ├── index.php           # Login
    ├── dashboard.php       # Hauptansicht
    ├── auftraege.php       # Auftragsverwaltung
    ├── einkauf.php         # Einkaufsanfragen
    ├── einstellungen.php   # Admin-Einstellungen
    ├── rechnung.php        # Druckoptimierte Rechnung
    └── logout.php          # Abmelden
```
