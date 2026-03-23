"""
database.py – Shape3Dream-Manager
All SQLite table definitions and CRUD helpers.

Requires: Python 3.12+
"""

import sqlite3
import os
from datetime import datetime

DB_PATH = os.path.join(os.path.dirname(__file__), "shape3dream.db")


def get_connection() -> sqlite3.Connection:
    """Return a new SQLite connection with row_factory set."""
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def init_db() -> None:
    """Create all tables if they do not exist and seed default settings."""
    with get_connection() as conn:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS orders (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_name   TEXT    NOT NULL,
                description     TEXT    NOT NULL,
                weight_g        REAL    NOT NULL DEFAULT 0,
                print_time_h    REAL    NOT NULL DEFAULT 0,
                material_price  REAL    NOT NULL DEFAULT 0,
                labor_hours     REAL    NOT NULL DEFAULT 0,
                markup_percent  REAL    NOT NULL DEFAULT 20,
                total_price     REAL    NOT NULL DEFAULT 0,
                status          TEXT    NOT NULL DEFAULT 'Offen',
                created_at      TEXT    NOT NULL
            );

            CREATE TABLE IF NOT EXISTS purchase_requests (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                employee     TEXT    NOT NULL,
                material     TEXT    NOT NULL,
                quantity     TEXT    NOT NULL,
                cost         REAL    NOT NULL DEFAULT 0,
                status       TEXT    NOT NULL DEFAULT 'Offen',
                created_at   TEXT    NOT NULL
            );
            """
        )
        # Seed default settings if not present
        defaults = {
            "electricity_price_kwh": "0.32",   # €/kWh
            "hourly_rate": "15.00",             # €/h labour
            "printer_watt": "200",              # Watt
            "wear_per_hour": "0.50",            # €/h printer wear
            "markup_percent": "20",             # % markup
            "company_name": "Shape3Dream GmbH",
            "company_address": "Musterstraße 1, 12345 Musterstadt",
        }
        for key, value in defaults.items():
            conn.execute(
                "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)",
                (key, value),
            )
        conn.commit()


# ──────────────────── Settings ────────────────────

def get_settings() -> dict:
    with get_connection() as conn:
        rows = conn.execute("SELECT key, value FROM settings").fetchall()
        return {row["key"]: row["value"] for row in rows}


def set_setting(key: str, value: str) -> None:
    with get_connection() as conn:
        conn.execute(
            "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)",
            (key, value),
        )
        conn.commit()


# ──────────────────── Orders ────────────────────

def create_order(
    customer_name: str,
    description: str,
    weight_g: float,
    print_time_h: float,
    material_price: float,
    labor_hours: float,
    markup_percent: float,
    total_price: float,
    status: str = "Offen",
) -> int:
    """Insert a new order and return its id."""
    created_at = datetime.now().strftime("%Y-%m-%d %H:%M")
    with get_connection() as conn:
        cur = conn.execute(
            """
            INSERT INTO orders
                (customer_name, description, weight_g, print_time_h,
                 material_price, labor_hours, markup_percent, total_price,
                 status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                customer_name,
                description,
                weight_g,
                print_time_h,
                material_price,
                labor_hours,
                markup_percent,
                total_price,
                status,
                created_at,
            ),
        )
        conn.commit()
        return cur.lastrowid


def get_recent_orders(limit: int = 5) -> list:
    with get_connection() as conn:
        rows = conn.execute(
            "SELECT * FROM orders ORDER BY id DESC LIMIT ?", (limit,)
        ).fetchall()
        return [dict(row) for row in rows]


def get_all_orders() -> list:
    with get_connection() as conn:
        rows = conn.execute("SELECT * FROM orders ORDER BY id DESC").fetchall()
        return [dict(row) for row in rows]


def get_monthly_revenue(year: int, month: int) -> float:
    """Sum of total_price for completed orders in the given month."""
    period = f"{year}-{month:02d}"
    with get_connection() as conn:
        row = conn.execute(
            """
            SELECT COALESCE(SUM(total_price), 0) AS revenue
            FROM orders
            WHERE status = 'Abgeschlossen' AND created_at LIKE ?
            """,
            (f"{period}%",),
        ).fetchone()
        return float(row["revenue"])


def get_monthly_filament(year: int, month: int) -> float:
    """Total filament weight (g) used in the given month."""
    period = f"{year}-{month:02d}"
    with get_connection() as conn:
        row = conn.execute(
            """
            SELECT COALESCE(SUM(weight_g), 0) AS total
            FROM orders
            WHERE created_at LIKE ?
            """,
            (f"{period}%",),
        ).fetchone()
        return float(row["total"])


def update_order_status(order_id: int, status: str) -> None:
    with get_connection() as conn:
        conn.execute(
            "UPDATE orders SET status = ? WHERE id = ?", (status, order_id)
        )
        conn.commit()


# ──────────────────── Purchase Requests ────────────────────

def create_purchase_request(
    employee: str, material: str, quantity: str, cost: float
) -> int:
    created_at = datetime.now().strftime("%Y-%m-%d %H:%M")
    with get_connection() as conn:
        cur = conn.execute(
            """
            INSERT INTO purchase_requests
                (employee, material, quantity, cost, status, created_at)
            VALUES (?, ?, ?, ?, 'Offen', ?)
            """,
            (employee, material, quantity, cost, created_at),
        )
        conn.commit()
        return cur.lastrowid


def get_purchase_requests(status: str | None = None) -> list:
    with get_connection() as conn:
        if status:
            rows = conn.execute(
                "SELECT * FROM purchase_requests WHERE status = ? ORDER BY id DESC",
                (status,),
            ).fetchall()
        else:
            rows = conn.execute(
                "SELECT * FROM purchase_requests ORDER BY id DESC"
            ).fetchall()
        return [dict(row) for row in rows]


def approve_purchase_request(request_id: int) -> None:
    with get_connection() as conn:
        conn.execute(
            "UPDATE purchase_requests SET status = 'Genehmigt' WHERE id = ?",
            (request_id,),
        )
        conn.commit()


def reject_purchase_request(request_id: int) -> None:
    with get_connection() as conn:
        conn.execute(
            "UPDATE purchase_requests SET status = 'Abgelehnt' WHERE id = ?",
            (request_id,),
        )
        conn.commit()


def get_monthly_expenses(year: int, month: int) -> float:
    """Sum of approved purchase costs for the given month."""
    period = f"{year}-{month:02d}"
    with get_connection() as conn:
        row = conn.execute(
            """
            SELECT COALESCE(SUM(cost), 0) AS total
            FROM purchase_requests
            WHERE status = 'Genehmigt' AND created_at LIKE ?
            """,
            (f"{period}%",),
        ).fetchone()
        return float(row["total"])


def get_open_request_count() -> int:
    with get_connection() as conn:
        row = conn.execute(
            "SELECT COUNT(*) AS cnt FROM purchase_requests WHERE status = 'Offen'"
        ).fetchone()
        return int(row["cnt"])
