"""The events this service reads and writes, and their JSON form."""

import base64
import json
from dataclasses import dataclass
from datetime import datetime

EXCHANGE = "webshop.events"
ORDER_PAID = "order.paid"
INVOICE_GENERATED = "invoice.generated"


class InvalidEvent(ValueError):
    """The message isn't a well-formed OrderPaid event; retrying won't help."""


@dataclass(frozen=True)
class OrderLine:
    product_id: str | None
    sku: str
    name: str
    quantity: int
    unit_price: int
    """In minor units (cents)."""

    @property
    def line_total(self) -> int:
        return self.unit_price * self.quantity


@dataclass(frozen=True)
class OrderPaid:
    """Mirrors App\\Message\\OrderPaid in the API, as the Symfony serializer writes it."""

    order_id: str
    customer_id: str
    placed_at: datetime
    paid_at: datetime
    total_amount: int
    """In minor units (cents)."""
    currency: str
    lines: tuple[OrderLine, ...]

    @classmethod
    def from_json(cls, body: bytes) -> "OrderPaid":
        try:
            data = json.loads(body)
            return cls(
                order_id=str(data["orderId"]),
                customer_id=str(data["customerId"]),
                placed_at=datetime.fromisoformat(data["placedAt"]),
                paid_at=datetime.fromisoformat(data["paidAt"]),
                total_amount=int(data["totalAmount"]),
                currency=str(data["currency"]),
                lines=tuple(
                    OrderLine(
                        product_id=line["productId"],
                        sku=str(line["sku"]),
                        name=str(line["name"]),
                        quantity=int(line["quantity"]),
                        unit_price=int(line["unitPrice"]),
                    )
                    for line in data["lines"]
                ),
            )
        except (ValueError, KeyError, TypeError) as error:
            raise InvalidEvent(f"Not a valid OrderPaid event: {error!r}") from error


def invoice_number(order: OrderPaid) -> str:
    """Derived from the order, so a redelivered event yields the same invoice, never a second one."""
    return f"INV-{order.order_id}"


def invoice_generated(order: OrderPaid, pdf: bytes, generated_at: datetime) -> dict[str, str]:
    """The invoice.generated payload: the PDF travels inside the JSON, Base64 encoded."""
    number = invoice_number(order)
    return {
        "invoiceNumber": number,
        "orderId": order.order_id,
        "customerId": order.customer_id,
        "generatedAt": generated_at.isoformat(timespec="seconds"),
        "fileName": f"{number}.pdf",
        "contentType": "application/pdf",
        "pdfBase64": base64.b64encode(pdf).decode("ascii"),
    }
