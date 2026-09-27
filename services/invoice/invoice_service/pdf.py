"""Renders an order as an invoice PDF."""

from fpdf import FPDF
from fpdf.enums import XPos, YPos

from .events import OrderPlaced, invoice_number

COLUMNS = (
    ("SKU", 30, "L"),
    ("Product", 70, "L"),
    ("Qty", 15, "R"),
    ("Unit price", 35, "R"),
    ("Amount", 40, "R"),
)


def money(amount: int, currency: str) -> str:
    """Formats minor units, e.g. 1250 EUR as "EUR 12.50"."""
    return f"{currency} {amount // 100:,}.{amount % 100:02d}"


def latin1(text: str) -> str:
    """The PDF core fonts only cover Latin-1: replace anything else instead of failing."""
    return text.encode("latin-1", "replace").decode("latin-1")


def render_invoice(order: OrderPlaced) -> bytes:
    pdf = FPDF(format="A4")
    pdf.set_title(invoice_number(order))
    pdf.set_creation_date(order.placed_at)
    pdf.add_page()

    pdf.set_font("Helvetica", "B", 20)
    pdf.cell(0, 12, "Invoice", new_x=XPos.LMARGIN, new_y=YPos.NEXT)

    pdf.set_font("Helvetica", size=10)
    for label, value in (
        ("Invoice number", invoice_number(order)),
        ("Invoice date", order.placed_at.date().isoformat()),
        ("Order", order.order_id),
        ("Customer", order.customer_id),
    ):
        pdf.cell(35, 6, label)
        pdf.cell(0, 6, value, new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(6)

    pdf.set_font("Helvetica", "B", 10)
    for heading, width, align in COLUMNS:
        pdf.cell(width, 8, heading, border="B", align=align)
    pdf.ln()

    pdf.set_font("Helvetica", size=10)
    for line in order.lines:
        values = (
            latin1(line.sku),
            latin1(line.name),
            str(line.quantity),
            money(line.unit_price, order.currency),
            money(line.line_total, order.currency),
        )
        for (_, width, align), value in zip(COLUMNS, values, strict=True):
            pdf.cell(width, 7, value, align=align)
        pdf.ln()

    pdf.set_font("Helvetica", "B", 11)
    pdf.cell(sum(width for _, width, _ in COLUMNS[:-1]), 10, "Total", border="T", align="R")
    pdf.cell(COLUMNS[-1][1], 10, money(order.total_amount, order.currency), border="T", align="R")

    return bytes(pdf.output())
