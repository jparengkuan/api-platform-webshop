import json

import pytest


@pytest.fixture
def order_paid_body() -> bytes:
    """An OrderPaid event exactly as the API publishes it (Symfony serializer, JSON)."""
    return json.dumps(
        {
            "orderId": "01a0e480-2c56-7901-8a69-d653265d5d1f",
            "customerId": "01a0e480-2bc1-7f5f-8f48-3edd8527ee2c",
            "placedAt": "2026-09-22T20:54:48+00:00",
            "paidAt": "2026-09-22T20:58:12+00:00",
            "totalAmount": 5249,
            "currency": "EUR",
            "lines": [
                {
                    "productId": "01a0e480-2bf6-706d-b99f-ff1c55f121de",
                    "sku": "MUG-01",
                    "name": "Mok Amsterdam",
                    "quantity": 2,
                    "unitPrice": 1375,
                },
                {
                    "productId": None,
                    "sku": "SHIRT-01",
                    "name": "T-shirt ‘Café’",
                    "quantity": 1,
                    "unitPrice": 2499,
                },
            ],
        }
    ).encode()
