FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client \
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
