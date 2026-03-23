#!/bin/bash
# ============================================================
# init-db.sh – Datenbank, Benutzer und Schema initialisieren
#
# Wird bei JEDEM Start als eigenständiger Service ausgeführt.
# Alle SQL-Operationen sind idempotent:
#   - CREATE DATABASE IF NOT EXISTS
#   - CREATE USER IF NOT EXISTS
#   - GRANT
#   - CREATE TABLE IF NOT EXISTS / ON DUPLICATE KEY UPDATE
#
# Damit funktioniert das System auch dann korrekt, wenn das
# db_data-Volume aus einem früheren, fehlgeschlagenen Start
# noch vorhanden ist (docker-entrypoint-initdb.d läuft nur
# beim allerersten Start mit leerem Datenverzeichnis).
# ============================================================

set -e

: "${DB_HOST:=db}"
: "${DB_NAME:=printmanager}"
: "${DB_USER:=erp_user}"
: "${DB_PASS:=erp_secret_pass}"
: "${DB_ROOT_PASS:=root_erp_pass}"

echo "[init-db] Lege Datenbank \`${DB_NAME}\` und Benutzer '${DB_USER}' an …"

MYSQL_PWD="${DB_ROOT_PASS}" mysql -h"${DB_HOST}" -uroot --connect-timeout=10 -e "
  CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
  GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
  FLUSH PRIVILEGES;
"

echo "[init-db] Spiele Schema und Seed-Daten ein …"

MYSQL_PWD="${DB_PASS}" mysql -h"${DB_HOST}" -u"${DB_USER}" "${DB_NAME}" < /db_setup.sql

echo "[init-db] Datenbank ist bereit."
