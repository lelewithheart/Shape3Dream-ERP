"""
main.py – Shape3Dream-Manager
Modern desktop ERP application for a 3-D printing shop.

Requires: Python 3.12+

Dependencies:
    pip install customtkinter fpdf2

Usage:
    python main.py
"""

import os
import sys
from datetime import datetime
import tkinter as tk
import customtkinter as ctk
from fpdf import FPDF

import database as db

# ── App-wide appearance ──────────────────────────────────────────────────────
ctk.set_appearance_mode("dark")
ctk.set_default_color_theme("blue")

INVOICES_DIR = os.path.join(os.path.dirname(__file__), "Rechnungen")
os.makedirs(INVOICES_DIR, exist_ok=True)

# ── Colour palette ───────────────────────────────────────────────────────────
CLR_BG       = "#1e1e2e"
CLR_SIDEBAR  = "#181825"
CLR_CARD     = "#313244"
CLR_ACCENT   = "#89b4fa"
CLR_SUCCESS  = "#a6e3a1"
CLR_WARN     = "#fab387"
CLR_DANGER   = "#f38ba8"
CLR_TEXT     = "#cdd6f4"
CLR_SUBTEXT  = "#a6adc8"

FONT_TITLE   = ("Segoe UI", 20, "bold")
FONT_HEADING = ("Segoe UI", 14, "bold")
FONT_BODY    = ("Segoe UI", 12)
FONT_SMALL   = ("Segoe UI", 10)


# ═══════════════════════════════════════════════════════════════════════════
# Calculator – core pricing logic
# ═══════════════════════════════════════════════════════════════════════════
class Calculator:
    """
    Central price calculator.

    Formula:
        base = (weight_g / 1000 * material_price_per_kg)
               + (print_time_h * (printer_watt / 1000) * electricity_price_kwh)
               + (print_time_h * wear_per_hour)
               + (labor_hours  * hourly_rate)
        total = base * (1 + markup_percent / 100)
    """

    def __init__(self, settings: dict):
        self.electricity_price = float(settings.get("electricity_price_kwh", 0.32))
        self.hourly_rate       = float(settings.get("hourly_rate", 15.0))
        self.printer_watt      = float(settings.get("printer_watt", 200))
        self.wear_per_hour     = float(settings.get("wear_per_hour", 0.50))
        self.markup_percent    = float(settings.get("markup_percent", 20))

    def calculate(
        self,
        weight_g: float,
        material_price_per_kg: float,
        print_time_h: float,
        labor_hours: float,
        markup_percent: float | None = None,
    ) -> dict:
        markup = markup_percent if markup_percent is not None else self.markup_percent

        material_cost  = (weight_g / 1000.0) * material_price_per_kg
        electricity    = print_time_h * (self.printer_watt / 1000.0) * self.electricity_price
        wear           = print_time_h * self.wear_per_hour
        labor          = labor_hours * self.hourly_rate

        base           = material_cost + electricity + wear + labor
        total          = base * (1.0 + markup / 100.0)

        return {
            "material_cost":  round(material_cost,  2),
            "electricity":    round(electricity,    2),
            "wear":           round(wear,           2),
            "labor":          round(labor,          2),
            "base":           round(base,           2),
            "markup_percent": markup,
            "total":          round(total,          2),
        }


# ═══════════════════════════════════════════════════════════════════════════
# PDF Invoice generator
# ═══════════════════════════════════════════════════════════════════════════
class InvoicePDF(FPDF):
    def __init__(self, company_name: str, company_address: str):
        super().__init__()
        self.company_name    = company_name
        self.company_address = company_address

    def header(self):
        self.set_font("Helvetica", "B", 18)
        self.set_text_color(40, 40, 80)
        self.cell(0, 10, self.company_name, align="L", new_x="LMARGIN", new_y="NEXT")
        self.set_font("Helvetica", "", 10)
        self.set_text_color(100, 100, 100)
        self.cell(0, 6, self.company_address, new_x="LMARGIN", new_y="NEXT")
        self.ln(4)
        self.set_draw_color(89, 140, 250)
        self.set_line_width(0.8)
        self.line(10, self.get_y(), 200, self.get_y())
        self.ln(6)

    def footer(self):
        self.set_y(-15)
        self.set_font("Helvetica", "I", 8)
        self.set_text_color(150, 150, 150)
        self.cell(0, 10, f"Seite {self.page_no()}", align="C")


def generate_invoice(order: dict, breakdown: dict, settings: dict) -> str:
    """Generate a PDF invoice and return the file path."""
    company_name    = settings.get("company_name", "Shape3Dream GmbH")
    company_address = settings.get("company_address", "")

    pdf = InvoicePDF(company_name, company_address)
    pdf.add_page()

    invoice_no = f"RE-{order['id']:05d}"
    date_str   = datetime.now().strftime("%d.%m.%Y")

    # Invoice title + metadata
    pdf.set_font("Helvetica", "B", 14)
    pdf.set_text_color(40, 40, 80)
    pdf.cell(0, 8, "RECHNUNG", new_x="LMARGIN", new_y="NEXT")

    pdf.set_font("Helvetica", "", 10)
    pdf.set_text_color(60, 60, 60)
    pdf.cell(60, 6, f"Rechnungsnummer: {invoice_no}")
    pdf.cell(0, 6, f"Datum: {date_str}", new_x="LMARGIN", new_y="NEXT")

    pdf.ln(4)
    pdf.set_font("Helvetica", "B", 10)
    pdf.cell(0, 6, f"Kunde: {order['customer_name']}", new_x="LMARGIN", new_y="NEXT")
    pdf.set_font("Helvetica", "", 10)
    pdf.cell(0, 6, f"Beschreibung: {order['description']}", new_x="LMARGIN", new_y="NEXT")
    pdf.ln(6)

    # Table header
    col_w = [100, 40, 40]
    pdf.set_fill_color(89, 140, 250)
    pdf.set_text_color(255, 255, 255)
    pdf.set_font("Helvetica", "B", 10)
    pdf.cell(col_w[0], 8, "Position",   border=1, fill=True)
    pdf.cell(col_w[1], 8, "Einheit",    border=1, fill=True)
    pdf.cell(col_w[2], 8, "Betrag (€)", border=1, fill=True, new_x="LMARGIN", new_y="NEXT")

    # Table rows
    rows = [
        ("Materialkosten",     f"{order['weight_g']} g",           f"{breakdown['material_cost']:.2f}"),
        ("Stromkosten",        f"{order['print_time_h']} h",       f"{breakdown['electricity']:.2f}"),
        ("Drucker-Verschleiß", f"{order['print_time_h']} h",       f"{breakdown['wear']:.2f}"),
        ("Arbeitszeit",        f"{order['labor_hours']} h",        f"{breakdown['labor']:.2f}"),
        ("Zwischensumme",      "",                                  f"{breakdown['base']:.2f}"),
        (f"Gewinnaufschlag ({breakdown['markup_percent']:.0f} %)", "", ""),
    ]
    pdf.set_text_color(40, 40, 40)
    pdf.set_font("Helvetica", "", 10)
    fill = False
    for pos, unit, amount in rows:
        pdf.set_fill_color(240, 244, 255) if fill else pdf.set_fill_color(255, 255, 255)
        pdf.cell(col_w[0], 7, pos,    border=1, fill=True)
        pdf.cell(col_w[1], 7, unit,   border=1, fill=True)
        pdf.cell(col_w[2], 7, amount, border=1, fill=True, new_x="LMARGIN", new_y="NEXT")
        fill = not fill

    # Total row
    pdf.set_fill_color(40, 40, 80)
    pdf.set_text_color(255, 255, 255)
    pdf.set_font("Helvetica", "B", 11)
    pdf.cell(col_w[0] + col_w[1], 9, "GESAMTBETRAG (inkl. Aufschlag)", border=1, fill=True)
    pdf.cell(col_w[2], 9, f"{order['total_price']:.2f} €", border=1, fill=True,
             new_x="LMARGIN", new_y="NEXT")

    pdf.ln(10)
    pdf.set_text_color(100, 100, 100)
    pdf.set_font("Helvetica", "I", 9)
    pdf.cell(0, 6, "Vielen Dank für Ihren Auftrag! – Shape3Dream", align="C")

    filename = os.path.join(INVOICES_DIR, f"{invoice_no}_{order['customer_name']}.pdf")
    pdf.output(filename)
    return filename


# ═══════════════════════════════════════════════════════════════════════════
# Reusable UI helpers
# ═══════════════════════════════════════════════════════════════════════════
def section_title(parent, text: str) -> ctk.CTkLabel:
    lbl = ctk.CTkLabel(parent, text=text, font=FONT_TITLE,
                       text_color=CLR_ACCENT)
    return lbl


def stat_card(parent, title: str, value: str, color: str = CLR_TEXT) -> ctk.CTkFrame:
    frame = ctk.CTkFrame(parent, fg_color=CLR_CARD, corner_radius=12)
    ctk.CTkLabel(frame, text=title, font=FONT_SMALL,
                 text_color=CLR_SUBTEXT).pack(padx=16, pady=(12, 2))
    ctk.CTkLabel(frame, text=value, font=FONT_HEADING,
                 text_color=color).pack(padx=16, pady=(2, 12))
    return frame


def form_row(parent, label: str, placeholder: str = "", width: int = 260):
    row = ctk.CTkFrame(parent, fg_color="transparent")
    ctk.CTkLabel(row, text=label, font=FONT_BODY,
                 text_color=CLR_TEXT, width=140, anchor="w").pack(side="left", padx=(0, 8))
    entry = ctk.CTkEntry(row, placeholder_text=placeholder, width=width,
                         fg_color=CLR_CARD, border_color=CLR_ACCENT)
    entry.pack(side="left")
    return row, entry


# ═══════════════════════════════════════════════════════════════════════════
# Individual view frames
# ═══════════════════════════════════════════════════════════════════════════

class DashboardFrame(ctk.CTkFrame):
    def __init__(self, master, **kwargs):
        super().__init__(master, fg_color=CLR_BG, **kwargs)
        self._build()

    def _build(self):
        section_title(self, "📊  Dashboard").pack(anchor="w", padx=24, pady=(20, 10))
        self.stats_row = ctk.CTkFrame(self, fg_color="transparent")
        self.stats_row.pack(fill="x", padx=24, pady=(0, 16))

        self.card_revenue  = stat_card(self.stats_row, "Monatlicher Gewinn",       "…", CLR_SUCCESS)
        self.card_filament = stat_card(self.stats_row, "Filament-Verbrauch",       "…", CLR_ACCENT)
        self.card_requests = stat_card(self.stats_row, "Offene Mitarbeiter-Anfragen", "…", CLR_WARN)
        self.card_expenses = stat_card(self.stats_row, "Monatliche Ausgaben",      "…", CLR_DANGER)

        for card in (self.card_revenue, self.card_filament,
                     self.card_requests, self.card_expenses):
            card.pack(side="left", expand=True, fill="x", padx=6)

        # Recent orders list
        ctk.CTkLabel(self, text="Letzte Aufträge", font=FONT_HEADING,
                     text_color=CLR_TEXT).pack(anchor="w", padx=24, pady=(4, 6))
        self.orders_frame = ctk.CTkScrollableFrame(self, fg_color=CLR_CARD,
                                                   corner_radius=12, height=260)
        self.orders_frame.pack(fill="both", expand=True, padx=24, pady=(0, 20))

        self.refresh()

    def refresh(self):
        now   = datetime.now()
        year  = now.year
        month = now.month

        revenue  = db.get_monthly_revenue(year, month)
        filament = db.get_monthly_filament(year, month)
        requests = db.get_open_request_count()
        expenses = db.get_monthly_expenses(year, month)

        # Update cards
        self._update_card(self.card_revenue,  f"{revenue:.2f} €")
        self._update_card(self.card_filament, f"{filament:.0f} g")
        self._update_card(self.card_requests, str(requests))
        self._update_card(self.card_expenses, f"{expenses:.2f} €")

        # Clear and refill orders list
        for widget in self.orders_frame.winfo_children():
            widget.destroy()

        headers = ["#", "Kunde", "Beschreibung", "Gewicht", "Zeit", "Preis", "Status"]
        widths  = [40, 140, 200, 80, 70, 90, 100]
        hdr = ctk.CTkFrame(self.orders_frame, fg_color="#3d3f5e", corner_radius=6)
        hdr.pack(fill="x", pady=(0, 4), padx=2)
        for h, w in zip(headers, widths):
            ctk.CTkLabel(hdr, text=h, font=FONT_SMALL, text_color=CLR_ACCENT,
                         width=w, anchor="w").pack(side="left", padx=4)

        orders = db.get_recent_orders(5)
        if not orders:
            ctk.CTkLabel(self.orders_frame, text="Noch keine Aufträge vorhanden.",
                         text_color=CLR_SUBTEXT, font=FONT_BODY).pack(pady=20)
            return

        for order in orders:
            status_color = {
                "Offen":        CLR_WARN,
                "In Bearbeitung": CLR_ACCENT,
                "Abgeschlossen": CLR_SUCCESS,
            }.get(order["status"], CLR_TEXT)

            row_frame = ctk.CTkFrame(self.orders_frame, fg_color=CLR_CARD,
                                     corner_radius=6)
            row_frame.pack(fill="x", pady=2, padx=2)
            values = [
                str(order["id"]),
                order["customer_name"],
                order["description"][:28],
                f"{order['weight_g']:.0f} g",
                f"{order['print_time_h']:.1f} h",
                f"{order['total_price']:.2f} €",
            ]
            for val, w in zip(values, widths[:-1]):
                ctk.CTkLabel(row_frame, text=val, font=FONT_SMALL,
                             text_color=CLR_TEXT, width=w, anchor="w").pack(side="left", padx=4)
            ctk.CTkLabel(row_frame, text=order["status"], font=FONT_SMALL,
                         text_color=status_color, width=widths[-1],
                         anchor="w").pack(side="left", padx=4)

    @staticmethod
    def _update_card(card: ctk.CTkFrame, value: str):
        for widget in card.winfo_children():
            if isinstance(widget, ctk.CTkLabel) and widget.cget("font") == FONT_HEADING:
                widget.configure(text=value)
                return


class NewOrderFrame(ctk.CTkFrame):
    def __init__(self, master, on_order_created=None, **kwargs):
        super().__init__(master, fg_color=CLR_BG, **kwargs)
        self.on_order_created = on_order_created
        self._calc_result: dict = {}
        self._last_order_id: int | None = None
        self._build()

    def _build(self):
        section_title(self, "🖨️  Neuer Auftrag").pack(anchor="w", padx=24, pady=(20, 10))

        scroll = ctk.CTkScrollableFrame(self, fg_color=CLR_BG)
        scroll.pack(fill="both", expand=True, padx=24, pady=(0, 10))

        # ── Input form ──────────────────────────────────────────────────
        form = ctk.CTkFrame(scroll, fg_color=CLR_CARD, corner_radius=12)
        form.pack(fill="x", pady=(0, 16))

        ctk.CTkLabel(form, text="Auftragsdaten", font=FONT_HEADING,
                     text_color=CLR_ACCENT).grid(row=0, column=0, columnspan=2,
                                                  sticky="w", padx=16, pady=(12, 4))

        fields = [
            ("Kundenname",               "Max Mustermann"),
            ("Beschreibung",             "z.B. Halterung 20×30 mm"),
            ("Gewicht (g)",              "150"),
            ("Druckzeit (h)",            "4.5"),
            ("Materialpreis (€/kg)",     "25.00"),
            ("Arbeitszeit (h)",          "0.5"),
            ("Gewinnaufschlag (%)",       "20"),
        ]
        self._entries: dict[str, ctk.CTkEntry] = {}
        for i, (label, placeholder) in enumerate(fields, start=1):
            ctk.CTkLabel(form, text=label, font=FONT_BODY,
                         text_color=CLR_TEXT, anchor="w", width=200).grid(
                row=i, column=0, sticky="w", padx=(16, 8), pady=4
            )
            entry = ctk.CTkEntry(form, placeholder_text=placeholder, width=280,
                                 fg_color="#2a2a3e", border_color=CLR_ACCENT)
            entry.grid(row=i, column=1, sticky="w", padx=(0, 16), pady=4)
            self._entries[label] = entry

        btn_frame = ctk.CTkFrame(form, fg_color="transparent")
        btn_frame.grid(row=len(fields)+1, column=0, columnspan=2, pady=12, padx=16, sticky="w")

        ctk.CTkButton(btn_frame, text="💡 Preis berechnen",
                      command=self._calculate,
                      fg_color=CLR_ACCENT, text_color="#1e1e2e",
                      font=("Segoe UI", 12, "bold"),
                      hover_color="#7aa2f7").pack(side="left", padx=(0, 10))
        ctk.CTkButton(btn_frame, text="💾 Auftrag speichern",
                      command=self._save_order,
                      fg_color=CLR_SUCCESS, text_color="#1e1e2e",
                      font=("Segoe UI", 12, "bold"),
                      hover_color="#94d9a2").pack(side="left", padx=(0, 10))
        ctk.CTkButton(btn_frame, text="📄 Rechnung PDF",
                      command=self._generate_pdf,
                      fg_color=CLR_WARN, text_color="#1e1e2e",
                      font=("Segoe UI", 12, "bold"),
                      hover_color="#e8a87c").pack(side="left")

        # ── Cost breakdown ───────────────────────────────────────────────
        self.result_frame = ctk.CTkFrame(scroll, fg_color=CLR_CARD, corner_radius=12)
        self.result_frame.pack(fill="x")
        ctk.CTkLabel(self.result_frame, text="Kostenaufschlüsselung",
                     font=FONT_HEADING, text_color=CLR_ACCENT).pack(
            anchor="w", padx=16, pady=(12, 4))

        self.result_labels: dict[str, ctk.CTkLabel] = {}
        result_rows = [
            ("Materialkosten",    "material_cost"),
            ("Stromkosten",       "electricity"),
            ("Drucker-Verschleiß","wear"),
            ("Arbeitskosten",     "labor"),
            ("Zwischensumme",     "base"),
            ("Gesamtpreis",       "total"),
        ]
        for rname, rkey in result_rows:
            row_f = ctk.CTkFrame(self.result_frame, fg_color="transparent")
            row_f.pack(fill="x", padx=16, pady=2)
            ctk.CTkLabel(row_f, text=rname, font=FONT_BODY,
                         text_color=CLR_SUBTEXT, width=200, anchor="w").pack(side="left")
            lbl = ctk.CTkLabel(row_f, text="–", font=FONT_BODY,
                               text_color=CLR_TEXT, anchor="w")
            lbl.pack(side="left")
            self.result_labels[rkey] = lbl

        self.status_lbl = ctk.CTkLabel(self.result_frame, text="",
                                       font=FONT_BODY, text_color=CLR_SUCCESS)
        self.status_lbl.pack(pady=(4, 12), padx=16, anchor="w")

    def _get_float(self, label: str, default: float = 0.0) -> float:
        try:
            return float(self._entries[label].get().replace(",", "."))
        except ValueError:
            return default

    def _calculate(self):
        settings = db.get_settings()
        calc = Calculator(settings)
        result = calc.calculate(
            weight_g              = self._get_float("Gewicht (g)"),
            material_price_per_kg = self._get_float("Materialpreis (€/kg)"),
            print_time_h          = self._get_float("Druckzeit (h)"),
            labor_hours           = self._get_float("Arbeitszeit (h)"),
            markup_percent        = self._get_float("Gewinnaufschlag (%)"),
        )
        self._calc_result = result
        for key, lbl in self.result_labels.items():
            val = result.get(key, 0)
            if key == "markup_percent":
                lbl.configure(text=f"{val:.0f} %")
            else:
                lbl.configure(text=f"{val:.2f} €")
        self.result_labels["total"].configure(
            text=f"{result['total']:.2f} €", text_color=CLR_SUCCESS
        )
        self.status_lbl.configure(text="")

    def _save_order(self):
        if not self._calc_result:
            self._calculate()
        customer = self._entries["Kundenname"].get().strip()
        desc     = self._entries["Beschreibung"].get().strip()
        if not customer or not desc:
            self.status_lbl.configure(text="⚠ Bitte Kundenname und Beschreibung ausfüllen.",
                                      text_color=CLR_WARN)
            return
        oid = db.create_order(
            customer_name  = customer,
            description    = desc,
            weight_g       = self._get_float("Gewicht (g)"),
            print_time_h   = self._get_float("Druckzeit (h)"),
            material_price = self._get_float("Materialpreis (€/kg)"),
            labor_hours    = self._get_float("Arbeitszeit (h)"),
            markup_percent = self._get_float("Gewinnaufschlag (%)"),
            total_price    = self._calc_result.get("total", 0),
        )
        self._last_order_id = oid
        self.status_lbl.configure(
            text=f"✅  Auftrag #{oid} gespeichert!", text_color=CLR_SUCCESS
        )
        if self.on_order_created:
            self.on_order_created()

    def _generate_pdf(self):
        if self._last_order_id is None:
            self._save_order()
        if self._last_order_id is None:
            return
        orders = db.get_all_orders()
        order  = next((o for o in orders if o["id"] == self._last_order_id), None)
        if not order:
            self.status_lbl.configure(text="⚠ Auftrag nicht gefunden.", text_color=CLR_WARN)
            return
        if not self._calc_result:
            self._calculate()
        settings = db.get_settings()
        path = generate_invoice(order, self._calc_result, settings)
        self.status_lbl.configure(
            text=f"📄 PDF gespeichert: {path}", text_color=CLR_ACCENT
        )


class PurchaseFrame(ctk.CTkFrame):
    def __init__(self, master, **kwargs):
        super().__init__(master, fg_color=CLR_BG, **kwargs)
        self._admin_mode = False
        self._build()

    def _build(self):
        top = ctk.CTkFrame(self, fg_color="transparent")
        top.pack(fill="x", padx=24, pady=(20, 4))
        section_title(top, "🛒  Einkauf / Anfragen").pack(side="left")

        self.mode_btn = ctk.CTkButton(
            top, text="Admin-Modus aktivieren",
            command=self._toggle_admin,
            fg_color=CLR_CARD, text_color=CLR_ACCENT,
            hover_color="#3d3f5e", width=200
        )
        self.mode_btn.pack(side="right")

        scroll = ctk.CTkScrollableFrame(self, fg_color=CLR_BG)
        scroll.pack(fill="both", expand=True, padx=24, pady=(0, 10))

        # ── Request form ─────────────────────────────────────────────────
        form = ctk.CTkFrame(scroll, fg_color=CLR_CARD, corner_radius=12)
        form.pack(fill="x", pady=(0, 16))
        ctk.CTkLabel(form, text="Neue Material-Anfrage", font=FONT_HEADING,
                     text_color=CLR_ACCENT).grid(row=0, column=0, columnspan=2,
                                                  sticky="w", padx=16, pady=(12, 4))
        fields = [
            ("Mitarbeiter",   "Name"),
            ("Material",      "z.B. PLA schwarz 1 kg"),
            ("Menge",         "z.B. 2 Rollen"),
            ("Kosten (€)",    "25.00"),
        ]
        self._pr_entries: dict[str, ctk.CTkEntry] = {}
        for i, (lbl, ph) in enumerate(fields, start=1):
            ctk.CTkLabel(form, text=lbl, font=FONT_BODY, text_color=CLR_TEXT,
                         anchor="w", width=160).grid(row=i, column=0, sticky="w",
                                                      padx=(16, 8), pady=4)
            entry = ctk.CTkEntry(form, placeholder_text=ph, width=280,
                                 fg_color="#2a2a3e", border_color=CLR_ACCENT)
            entry.grid(row=i, column=1, sticky="w", padx=(0, 16), pady=4)
            self._pr_entries[lbl] = entry

        btn_row = ctk.CTkFrame(form, fg_color="transparent")
        btn_row.grid(row=len(fields)+1, column=0, columnspan=2,
                     padx=16, pady=(4, 12), sticky="w")
        ctk.CTkButton(btn_row, text="📨  Anfrage senden",
                      command=self._send_request,
                      fg_color=CLR_ACCENT, text_color="#1e1e2e",
                      font=("Segoe UI", 12, "bold"),
                      hover_color="#7aa2f7").pack(side="left")
        self.pr_status = ctk.CTkLabel(btn_row, text="", font=FONT_BODY,
                                      text_color=CLR_SUCCESS)
        self.pr_status.pack(side="left", padx=12)

        # ── Requests list ────────────────────────────────────────────────
        ctk.CTkLabel(scroll, text="Alle Anfragen", font=FONT_HEADING,
                     text_color=CLR_TEXT).pack(anchor="w", pady=(4, 6))
        self.list_frame = ctk.CTkFrame(scroll, fg_color=CLR_CARD, corner_radius=12)
        self.list_frame.pack(fill="x")

        self.refresh()

    def _toggle_admin(self):
        self._admin_mode = not self._admin_mode
        label = "Admin-Modus deaktivieren" if self._admin_mode else "Admin-Modus aktivieren"
        color = CLR_DANGER if self._admin_mode else CLR_CARD
        self.mode_btn.configure(text=label, fg_color=color)
        self.refresh()

    def _send_request(self):
        employee = self._pr_entries["Mitarbeiter"].get().strip()
        material = self._pr_entries["Material"].get().strip()
        quantity = self._pr_entries["Menge"].get().strip()
        try:
            cost = float(self._pr_entries["Kosten (€)"].get().replace(",", "."))
        except ValueError:
            cost = 0.0
        if not employee or not material:
            self.pr_status.configure(text="⚠ Bitte alle Felder ausfüllen.", text_color=CLR_WARN)
            return
        db.create_purchase_request(employee, material, quantity, cost)
        self.pr_status.configure(text="✅  Anfrage gesendet!", text_color=CLR_SUCCESS)
        for entry in self._pr_entries.values():
            entry.delete(0, "end")
        self.refresh()

    def refresh(self):
        for widget in self.list_frame.winfo_children():
            widget.destroy()

        requests = db.get_purchase_requests()
        if not requests:
            ctk.CTkLabel(self.list_frame, text="Keine Anfragen vorhanden.",
                         text_color=CLR_SUBTEXT, font=FONT_BODY).pack(pady=16)
            return

        headers = ["#", "Mitarbeiter", "Material", "Menge", "Kosten", "Status", "Datum"]
        widths  = [40, 120, 200, 100, 80, 100, 130]
        hdr = ctk.CTkFrame(self.list_frame, fg_color="#3d3f5e", corner_radius=6)
        hdr.pack(fill="x", pady=(4, 2), padx=4)
        for h, w in zip(headers, widths):
            ctk.CTkLabel(hdr, text=h, font=FONT_SMALL, text_color=CLR_ACCENT,
                         width=w, anchor="w").pack(side="left", padx=4)
        if self._admin_mode:
            ctk.CTkLabel(hdr, text="Aktionen", font=FONT_SMALL,
                         text_color=CLR_ACCENT, width=160, anchor="w").pack(side="left", padx=4)

        for req in requests:
            status_color = {
                "Offen":      CLR_WARN,
                "Genehmigt":  CLR_SUCCESS,
                "Abgelehnt":  CLR_DANGER,
            }.get(req["status"], CLR_TEXT)

            row_f = ctk.CTkFrame(self.list_frame, fg_color="#2a2a3e", corner_radius=6)
            row_f.pack(fill="x", pady=2, padx=4)

            values = [
                str(req["id"]),
                req["employee"],
                req["material"][:28],
                req["quantity"],
                f"{req['cost']:.2f} €",
                req["status"],
                req["created_at"],
            ]
            for val, w in zip(values[:5], widths[:5]):
                ctk.CTkLabel(row_f, text=val, font=FONT_SMALL,
                             text_color=CLR_TEXT, width=w, anchor="w").pack(side="left", padx=4)
            ctk.CTkLabel(row_f, text=req["status"], font=FONT_SMALL,
                         text_color=status_color, width=widths[5], anchor="w").pack(side="left", padx=4)
            ctk.CTkLabel(row_f, text=req["created_at"], font=FONT_SMALL,
                         text_color=CLR_SUBTEXT, width=widths[6], anchor="w").pack(side="left", padx=4)

            if self._admin_mode and req["status"] == "Offen":
                ctk.CTkButton(
                    row_f, text="✔ Genehmigen", width=90,
                    fg_color=CLR_SUCCESS, text_color="#1e1e2e",
                    hover_color="#94d9a2", font=FONT_SMALL,
                    command=lambda rid=req["id"]: self._approve(rid)
                ).pack(side="left", padx=2)
                ctk.CTkButton(
                    row_f, text="✖ Ablehnen", width=80,
                    fg_color=CLR_DANGER, text_color="#1e1e2e",
                    hover_color="#e06c75", font=FONT_SMALL,
                    command=lambda rid=req["id"]: self._reject(rid)
                ).pack(side="left", padx=2)

    def _approve(self, rid: int):
        db.approve_purchase_request(rid)
        self.refresh()

    def _reject(self, rid: int):
        db.reject_purchase_request(rid)
        self.refresh()


class SettingsFrame(ctk.CTkFrame):
    def __init__(self, master, **kwargs):
        super().__init__(master, fg_color=CLR_BG, **kwargs)
        self._build()

    def _build(self):
        section_title(self, "⚙️  Einstellungen").pack(anchor="w", padx=24, pady=(20, 10))

        scroll = ctk.CTkScrollableFrame(self, fg_color=CLR_BG)
        scroll.pack(fill="both", expand=True, padx=24, pady=(0, 10))

        settings = db.get_settings()

        # ── Calculator settings ──────────────────────────────────────────
        calc_frame = ctk.CTkFrame(scroll, fg_color=CLR_CARD, corner_radius=12)
        calc_frame.pack(fill="x", pady=(0, 16))
        ctk.CTkLabel(calc_frame, text="Kalkulations-Parameter", font=FONT_HEADING,
                     text_color=CLR_ACCENT).grid(row=0, column=0, columnspan=2,
                                                   sticky="w", padx=16, pady=(12, 4))

        self._setting_entries: dict[str, ctk.CTkEntry] = {}
        calc_fields = [
            ("Strompreis (€/kWh)",   "electricity_price_kwh"),
            ("Stundensatz (€/h)",    "hourly_rate"),
            ("Drucker-Watt",         "printer_watt"),
            ("Verschleiß (€/h)",     "wear_per_hour"),
            ("Gewinnaufschlag (%)",  "markup_percent"),
        ]
        for i, (label, key) in enumerate(calc_fields, start=1):
            ctk.CTkLabel(calc_frame, text=label, font=FONT_BODY,
                         text_color=CLR_TEXT, anchor="w", width=200).grid(
                row=i, column=0, sticky="w", padx=(16, 8), pady=6
            )
            entry = ctk.CTkEntry(calc_frame, width=220,
                                 fg_color="#2a2a3e", border_color=CLR_ACCENT)
            entry.insert(0, settings.get(key, ""))
            entry.grid(row=i, column=1, sticky="w", padx=(0, 16), pady=6)
            self._setting_entries[key] = entry

        # ── Company settings ─────────────────────────────────────────────
        comp_frame = ctk.CTkFrame(scroll, fg_color=CLR_CARD, corner_radius=12)
        comp_frame.pack(fill="x", pady=(0, 16))
        ctk.CTkLabel(comp_frame, text="Firmendaten", font=FONT_HEADING,
                     text_color=CLR_ACCENT).grid(row=0, column=0, columnspan=2,
                                                   sticky="w", padx=16, pady=(12, 4))
        comp_fields = [
            ("Firmenname",  "company_name"),
            ("Adresse",     "company_address"),
        ]
        for i, (label, key) in enumerate(comp_fields, start=1):
            ctk.CTkLabel(comp_frame, text=label, font=FONT_BODY,
                         text_color=CLR_TEXT, anchor="w", width=200).grid(
                row=i, column=0, sticky="w", padx=(16, 8), pady=6
            )
            entry = ctk.CTkEntry(comp_frame, width=340,
                                 fg_color="#2a2a3e", border_color=CLR_ACCENT)
            entry.insert(0, settings.get(key, ""))
            entry.grid(row=i, column=1, sticky="w", padx=(0, 16), pady=6)
            self._setting_entries[key] = entry

        # Save button
        btn_row = ctk.CTkFrame(scroll, fg_color="transparent")
        btn_row.pack(anchor="w", pady=8)
        ctk.CTkButton(btn_row, text="💾  Einstellungen speichern",
                      command=self._save,
                      fg_color=CLR_ACCENT, text_color="#1e1e2e",
                      font=("Segoe UI", 12, "bold"),
                      hover_color="#7aa2f7").pack(side="left")
        self.save_lbl = ctk.CTkLabel(btn_row, text="", font=FONT_BODY,
                                     text_color=CLR_SUCCESS)
        self.save_lbl.pack(side="left", padx=12)

    def _save(self):
        for key, entry in self._setting_entries.items():
            db.set_setting(key, entry.get().strip())
        self.save_lbl.configure(text="✅  Einstellungen gespeichert!", text_color=CLR_SUCCESS)
        self.after(3000, lambda: self.save_lbl.configure(text=""))


# ═══════════════════════════════════════════════════════════════════════════
# Main Application Window
# ═══════════════════════════════════════════════════════════════════════════

class App(ctk.CTk):
    VIEWS = [
        ("📊  Dashboard",        "dashboard"),
        ("🖨️  Neuer Auftrag",    "new_order"),
        ("🛒  Einkauf/Anfragen", "purchase"),
        ("⚙️  Einstellungen",    "settings"),
    ]

    def __init__(self):
        super().__init__()
        self.title("Shape3Dream-Manager")
        self.geometry("1200x750")
        self.minsize(960, 640)
        self.configure(fg_color=CLR_BG)

        db.init_db()

        self._active_view = tk.StringVar(value="dashboard")
        self._frames: dict[str, ctk.CTkFrame] = {}

        self._build_sidebar()
        self._build_content_area()
        self._show_view("dashboard")

    # ── Sidebar ──────────────────────────────────────────────────────────
    def _build_sidebar(self):
        sidebar = ctk.CTkFrame(self, fg_color=CLR_SIDEBAR, width=220, corner_radius=0)
        sidebar.pack(side="left", fill="y")
        sidebar.pack_propagate(False)

        # Logo / title
        logo_frame = ctk.CTkFrame(sidebar, fg_color="transparent")
        logo_frame.pack(fill="x", pady=(24, 8), padx=16)
        ctk.CTkLabel(logo_frame, text="🔷", font=("Segoe UI", 28)).pack(anchor="w")
        ctk.CTkLabel(logo_frame, text="Shape3Dream",
                     font=("Segoe UI", 15, "bold"),
                     text_color=CLR_ACCENT).pack(anchor="w")
        ctk.CTkLabel(logo_frame, text="Manager",
                     font=("Segoe UI", 11),
                     text_color=CLR_SUBTEXT).pack(anchor="w")

        ctk.CTkFrame(sidebar, height=1, fg_color=CLR_CARD).pack(fill="x", padx=12, pady=12)

        self._nav_buttons: dict[str, ctk.CTkButton] = {}
        for label, view_id in self.VIEWS:
            btn = ctk.CTkButton(
                sidebar,
                text=label,
                anchor="w",
                font=FONT_BODY,
                fg_color="transparent",
                text_color=CLR_SUBTEXT,
                hover_color=CLR_CARD,
                corner_radius=8,
                height=42,
                command=lambda v=view_id: self._show_view(v),
            )
            btn.pack(fill="x", padx=12, pady=2)
            self._nav_buttons[view_id] = btn

        # Version label at the bottom
        ctk.CTkLabel(sidebar, text="v1.0.0", font=FONT_SMALL,
                     text_color=CLR_SUBTEXT).pack(side="bottom", pady=12)

    # ── Content area ─────────────────────────────────────────────────────
    def _build_content_area(self):
        self.content = ctk.CTkFrame(self, fg_color=CLR_BG, corner_radius=0)
        self.content.pack(side="left", fill="both", expand=True)

    def _show_view(self, view_id: str):
        # Lazy-create frames
        if view_id not in self._frames:
            if view_id == "dashboard":
                frame = DashboardFrame(self.content)
            elif view_id == "new_order":
                frame = NewOrderFrame(self.content,
                                      on_order_created=self._on_order_created)
            elif view_id == "purchase":
                frame = PurchaseFrame(self.content)
            elif view_id == "settings":
                frame = SettingsFrame(self.content)
            else:
                return
            self._frames[view_id] = frame

        # Hide all frames
        for f in self._frames.values():
            f.pack_forget()

        # Show chosen frame
        self._frames[view_id].pack(fill="both", expand=True)

        # Refresh if the view supports it
        if hasattr(self._frames[view_id], "refresh"):
            self._frames[view_id].refresh()

        # Update nav button highlighting
        for vid, btn in self._nav_buttons.items():
            if vid == view_id:
                btn.configure(fg_color=CLR_CARD, text_color=CLR_ACCENT)
            else:
                btn.configure(fg_color="transparent", text_color=CLR_SUBTEXT)

        self._active_view.set(view_id)

    def _on_order_created(self):
        # Refresh dashboard stats when a new order is saved
        if "dashboard" in self._frames:
            self._frames["dashboard"].refresh()


# ═══════════════════════════════════════════════════════════════════════════
# Entry point
# ═══════════════════════════════════════════════════════════════════════════

if __name__ == "__main__":
    app = App()
    app.mainloop()
