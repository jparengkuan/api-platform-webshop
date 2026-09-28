"""RabbitMQ side: consumes OrderPaid from the order_paid queue, publishes invoice.generated."""

import json
import logging
from collections.abc import Callable
from datetime import UTC, datetime

import pika
from pika.adapters.blocking_connection import BlockingChannel
from pika.spec import Basic, BasicProperties

from .events import EXCHANGE, INVOICE_GENERATED, ORDER_PAID, InvalidEvent, OrderPaid, invoice_generated
from .pdf import render_invoice

log = logging.getLogger(__name__)

ORDER_PAID_QUEUE = "order_paid"
INVOICE_GENERATED_QUEUE = "invoice_generated"


def declare_topology(channel: BlockingChannel) -> None:
    """Declares exactly what the API declares (same flags), plus the queue for the invoices."""
    channel.exchange_declare(EXCHANGE, exchange_type="topic", durable=True)
    channel.queue_declare(ORDER_PAID_QUEUE, durable=True)
    channel.queue_bind(ORDER_PAID_QUEUE, EXCHANGE, routing_key=ORDER_PAID)
    channel.queue_declare(INVOICE_GENERATED_QUEUE, durable=True)
    channel.queue_bind(INVOICE_GENERATED_QUEUE, EXCHANGE, routing_key=INVOICE_GENERATED)


InvoiceRenderer = Callable[[OrderPaid], bytes]
"""Strategy that turns an order into an invoice document, e.g. render_invoice (PDF)."""


class OrderPaidHandler:
    """Handles one delivery: the invoice is published first, then the order event is acknowledged."""

    def __init__(
        self,
        render: InvoiceRenderer = render_invoice,
        now: Callable[[], datetime] = lambda: datetime.now(UTC),
    ) -> None:
        self._render = render
        self._now = now

    def __call__(
        self, channel: BlockingChannel, method: Basic.Deliver, properties: BasicProperties, body: bytes
    ) -> None:
        if method.routing_key != ORDER_PAID:
            log.info("Ignoring a message with routing key %s", method.routing_key)
            channel.basic_ack(method.delivery_tag)
            return

        try:
            order = OrderPaid.from_json(body)
        except InvalidEvent:
            # Retrying can't fix a malformed message: drop it, but leave a trace.
            log.exception("Dropping an invalid OrderPaid message: %r", body[:500])
            channel.basic_reject(method.delivery_tag, requeue=False)
            return

        payload = invoice_generated(order, self._render(order), self._now())
        # With publisher confirms on, this returns only once RabbitMQ has stored the invoice. If it fails,
        # the exception closes the channel and the unacknowledged OrderPaid is delivered again.
        channel.basic_publish(
            EXCHANGE,
            INVOICE_GENERATED,
            json.dumps(payload).encode(),
            BasicProperties(
                content_type="application/json",
                delivery_mode=pika.DeliveryMode.Persistent,
                message_id=payload["invoiceNumber"],
                type=INVOICE_GENERATED,
                # Symfony Messenger (the API's mailer worker) reads the event type from this header.
                headers={"type": INVOICE_GENERATED},
            ),
            mandatory=True,
        )
        channel.basic_ack(method.delivery_tag)
        log.info("Published %s for order %s", payload["invoiceNumber"], order.order_id)


def consume(amqp_url: str) -> None:
    """Consumes until the connection drops or the process is stopped."""
    connection = pika.BlockingConnection(pika.URLParameters(amqp_url))
    try:
        channel = connection.channel()
        channel.confirm_delivery()
        declare_topology(channel)
        channel.basic_qos(prefetch_count=1)
        channel.basic_consume(ORDER_PAID_QUEUE, OrderPaidHandler())
        log.info("Waiting for OrderPaid events on queue %s", ORDER_PAID_QUEUE)
        channel.start_consuming()
    finally:
        if connection.is_open:
            connection.close()
