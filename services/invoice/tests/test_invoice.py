import base64
import io
from datetime import UTC, datetime

import pytest
from pypdf import PdfReader

from invoice_service.events import InvalidEvent, OrderPaid, invoice_generated
from invoice_service.pdf import money, render_invoice


def pdf_text(pdf: bytes) -> str:
    return "\n".join(page.extract_text() for page in PdfReader(io.BytesIO(pdf)).pages)


def test_parses_the_event_as_the_api_publishes_it(order_paid_body: bytes) -> None:
    order = OrderPaid.from_json(order_paid_body)

    assert order.order_id == "01a0e480-2c56-7901-8a69-d653265d5d1f"
    assert order.placed_at == datetime(2026, 9, 22, 20, 54, 48, tzinfo=UTC)
    assert order.paid_at == datetime(2026, 9, 22, 20, 58, 12, tzinfo=UTC)
    assert [(line.sku, line.line_total) for line in order.lines] == [("MUG-01", 2750), ("SHIRT-01", 2499)]
    assert order.lines[1].product_id is None


@pytest.mark.parametrize("body", [b"not json", b"{}", b'{"orderId": "x", "lines": "nope"}', b"[]"])
def test_rejects_malformed_events(body: bytes) -> None:
    with pytest.raises(InvalidEvent):
        OrderPaid.from_json(body)


def test_invoice_pdf_lists_the_order(order_paid_body: bytes) -> None:
    text = pdf_text(render_invoice(OrderPaid.from_json(order_paid_body)))

    assert "INV-01a0e480-2c56-7901-8a69-d653265d5d1f" in text
    assert "2026-09-22" in text
    assert "Mok Amsterdam" in text
    assert "EUR 27.50" in text
    assert "Café" in text, "Latin-1 characters are kept; only characters outside it are replaced"
    assert "EUR 52.49" in text
    assert "Paid" in text


def test_money_formats_minor_units() -> None:
    assert money(5, "EUR") == "EUR 0.05"
    assert money(123456789, "EUR") == "EUR 1,234,567.89"


def test_invoice_generated_carries_the_pdf_as_base64(order_paid_body: bytes) -> None:
    order = OrderPaid.from_json(order_paid_body)
    pdf = render_invoice(order)

    payload = invoice_generated(order, pdf, datetime(2026, 9, 28, 12, 0, tzinfo=UTC))

    assert payload == {
        "invoiceNumber": "INV-01a0e480-2c56-7901-8a69-d653265d5d1f",
        "orderId": order.order_id,
        "customerId": order.customer_id,
        "generatedAt": "2026-09-28T12:00:00+00:00",
        "fileName": "INV-01a0e480-2c56-7901-8a69-d653265d5d1f.pdf",
        "contentType": "application/pdf",
        "pdfBase64": payload["pdfBase64"],
    }
    assert base64.b64decode(payload["pdfBase64"]) == pdf
    assert pdf.startswith(b"%PDF-")
