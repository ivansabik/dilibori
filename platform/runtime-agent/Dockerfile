FROM quay.io/minio/mc:RELEASE.2025-04-16T18-13-26Z AS mc

FROM python:3.12-slim

# OSS dl.min.io/client path returns 410; use the same mc image compose pins.
COPY --from=mc /bin/mc /usr/local/bin/mc

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl mariadb-client \
    && chmod +x /usr/local/bin/mc \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY app /app/app

ENV RUNTIME_AGENT_HOST=0.0.0.0
ENV RUNTIME_AGENT_PORT=8100
ENV DATA_DIR=/var/lib/dilibori

EXPOSE 8100

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100"]
