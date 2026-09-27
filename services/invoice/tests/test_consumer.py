import json
from datetime import UTC, datetime
from types import SimpleNamespace

import pytest

from invoice_service.consumer import OrderPlacedHandler


class FakeChannel:
    """Records what the handler does with the channel, in order."""

    def __init__(self, fail_publish: bool = False) -> None:
        self.calls: list[tuple] = []
        self.fail_publish = fail_publish

    def basic_publish(self, exchange, routing_key, body, properties, mandatory) -> None:
        if self.fail_publish:
            raise ConnectionError("channel closed")
        self.calls.append(("publish", exchange, routing_key, json.loads(body), properties, mandatory))

    def basic_ack(self, delivery_tag) -> None:
        self.calls.append(("ack", delivery_tag))

    def basic_reject(self, delivery_tag, requeue) -> None:
        self.calls.append(("reject", delivery_tag, requeue))


def deliver(routing_key: str = "order.placed") -> SimpleNamespace:
    return SimpleNamespace(routing_key=routing_key, delivery_tag=7)


handler = OrderPlacedHandler(now=lambda: datetime(2026, 9, 28, 12, 0, tzinfo=UTC))


def test_publishes_invoice_generated_then_acknowledges(order_placed_body: bytes) -> None:
    channel = FakeChannel()

    handler(channel, deliver(), None, order_placed_body)

    (action, exchange, routing_key, payload, properties, mandatory), ack = channel.calls
    assert (action, exchange, routing_key, mandatory) == (
        "publish",
        "webshop.events",
        "invoice.generated",
        True,
    )
    assert payload["invoiceNumber"] == "INV-01a0e480-2c56-7901-8a69-d653265d5d1f"
    assert payload["pdfBase64"]
    assert properties.content_type == "application/json"
    assert properties.message_id == payload["invoiceNumber"]
    assert properties.delivery_mode == 2
    assert ack == ("ack", 7), "The order event is acknowledged only after the invoice was published"


def test_failed_publish_leaves_the_order_event_unacknowledged(order_placed_body: bytes) -> None:
    channel = FakeChannel(fail_publish=True)

    with pytest.raises(ConnectionError):
        handler(channel, deliver(), None, order_placed_body)

    assert channel.calls == [], "Unacknowledged, so RabbitMQ delivers it again after reconnecting"


def test_malformed_event_is_rejected_without_requeue() -> None:
    channel = FakeChannel()

    handler(channel, deliver(), None, b"{broken")

    assert channel.calls == [("reject", 7, False)]


def test_other_events_are_acknowledged_and_ignored() -> None:
    channel = FakeChannel()

    handler(channel, deliver("order.cancelled"), None, b"{}")

    assert channel.calls == [("ack", 7)]
