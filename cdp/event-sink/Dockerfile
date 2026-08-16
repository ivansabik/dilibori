FROM python:3.12-alpine

WORKDIR /app

COPY server.py /app/server.py

ENV EVENT_SINK_PORT=8090
ENV EVENT_SINK_DATA=/data/events.jsonl

EXPOSE 8090

CMD ["python", "-u", "/app/server.py"]
