# syntax=docker/dockerfile:1

# Invoice service: consumes OrderPlaced from RabbitMQ and publishes invoice.generated.
FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
	PYTHONUNBUFFERED=1

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY invoice_service ./invoice_service

# Run as an unprivileged user.
RUN useradd --create-home --shell /usr/sbin/nologin app
USER app

CMD ["python", "-m", "invoice_service"]
