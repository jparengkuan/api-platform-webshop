"""Entry point: python -m invoice_service. Reconnects to RabbitMQ until stopped."""

import logging
import os
import signal
import sys
import time

from pika.exceptions import AMQPConnectionError, AMQPError

from .consumer import consume

RETRY_SECONDS = 5


def stop(_signum: int, _frame: object) -> None:
    raise SystemExit(0)


def main() -> None:
    logging.basicConfig(
        level=os.environ.get("LOG_LEVEL", "INFO"),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
        stream=sys.stdout,
    )
    # pika logs every connection step at INFO; its warnings and errors are enough.
    logging.getLogger("pika").setLevel(logging.WARNING)
    signal.signal(signal.SIGTERM, stop)
    amqp_url = os.environ["AMQP_URL"]

    while True:
        try:
            consume(amqp_url)
        except AMQPConnectionError:
            logging.warning("RabbitMQ is not reachable; retrying in %s seconds", RETRY_SECONDS)
        except AMQPError:
            logging.exception("Lost the RabbitMQ connection; reconnecting in %s seconds", RETRY_SECONDS)
        time.sleep(RETRY_SECONDS)


if __name__ == "__main__":
    main()
