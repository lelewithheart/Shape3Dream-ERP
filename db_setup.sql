-- ============================================================
-- PrintManager-Native – Datenbankschema
-- Kompatibel mit MariaDB / MySQL
-- ============================================================

-- Datenbank auswählen (wird durch docker-entrypoint bereits erstellt)
USE printmanager;

-- -------------------------------------------------------
-- Tabelle: users
-- Speichert Benutzerkonten mit gehashten Passwörtern
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)     NOT NULL UNIQUE,
    password_hash VARCHAR(255)    NOT NULL,
    role          ENUM('admin','employee') NOT NULL DEFAULT 'employee',
    erstellt_am   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabelle: settings
-- Schlüssel-Wert-Paare für globale Konfiguration
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(64)   NOT NULL PRIMARY KEY,
    `value` VARCHAR(255)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Standard-Einstellungen eintragen (können im UI überschrieben werden)
INSERT INTO settings (`key`, `value`) VALUES
    ('strompreis_kwh',            '0.35'),   -- €/kWh
    ('drucker_watt_durchschnitt', '200'),     -- Watt
    ('verschleiss_pro_stunde',    '1.50'),    -- €/Stunde
    ('stundensatz_arbeit',        '25.00'),   -- €/Stunde
    ('standard_aufschlag_prozent','20'),      -- Prozent
    ('firma_name',                'Shape3Dream'),
    ('firma_adresse',             'Musterstraße 1, 12345 Musterstadt'),
    ('firma_email',               'info@shape3dream.de')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- -------------------------------------------------------
-- Tabelle: materials
-- Druckmaterialien (Filament, Harz …)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS materials (
    id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(128)  NOT NULL,
    preis_pro_kg   DECIMAL(10,4) NOT NULL COMMENT 'Preis in € pro Kilogramm',
    restgewicht_g  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Restbestand in Gramm',
    erstellt_am    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Beispiel-Materialien
INSERT INTO materials (name, preis_pro_kg, restgewicht_g) VALUES
    ('PLA Standard',    '22.00', 2000),
    ('PETG',            '28.00', 1500),
    ('ABS',             '24.00', 800),
    ('TPU Flex',        '35.00', 500)
ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------
-- Tabelle: purchase_requests
-- Einkaufsanfragen von Mitarbeitern
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_requests (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED  NOT NULL,
    artikel_name     VARCHAR(255)  NOT NULL,
    geschaetzter_preis DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    erstellt_am      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabelle: orders
-- Druckaufträge
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED    NOT NULL,
    kunde         VARCHAR(255)    NOT NULL,
    modell_name   VARCHAR(255)    NOT NULL,
    druckzeit_min INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Druckzeit in Minuten',
    gewicht_g     DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT 'Materialverbrauch in Gramm',
    arbeitszeit_min INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Manuelle Arbeitszeit in Minuten',
    material_id   INT UNSIGNED    NOT NULL,
    gesamtpreis   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    status        ENUM('offen','in_bearbeitung','abgeschlossen','storniert')
                  NOT NULL DEFAULT 'offen',
    notizen       TEXT            DEFAULT NULL,
    erstellt_am   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE RESTRICT,
    FOREIGN KEY (material_id) REFERENCES materials(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Tabelle: transactions
-- Finanztransaktionen (Einnahmen & Ausgaben)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    typ          ENUM('einnahme','ausgabe') NOT NULL,
    kategorie    ENUM('material','strom','sonstiges','auftrag') NOT NULL DEFAULT 'sonstiges',
    betrag       DECIMAL(10,2)   NOT NULL,
    beschreibung VARCHAR(512)    DEFAULT NULL,
    datum        DATE            NOT NULL DEFAULT (CURRENT_DATE),
    erstellt_am  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Standard-Admin-Benutzer anlegen
-- WICHTIG: Das Passwort 'admin123' MUSS nach dem ersten
-- Login sofort geändert werden!
-- Hash wurde mit password_hash('admin123', PASSWORD_BCRYPT) erzeugt
-- -------------------------------------------------------
INSERT INTO users (username, password_hash, role) VALUES
    ('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')
ON DUPLICATE KEY UPDATE username = username;
