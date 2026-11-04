# Sprach-Agent für LiveKit Cloud. deploy-cloud.ps1 baut im Ordner build\livekit-cloud,
# dorthin kopiert es backend/, content/ und diese Dateien.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    TUTOR_DATA_DIR=/data

WORKDIR /app
COPY backend/pyproject.toml backend/
COPY backend/tutor backend/tutor
RUN pip install -e backend

# VAD- und Turn-Detector-Modelle beim Bauen laden, nicht bei jedem Start.
RUN python -m tutor.agent download-files

COPY content content

RUN useradd --create-home tutor && mkdir -p /data && chown tutor /data
USER tutor

CMD ["python", "-m", "tutor.agent", "start"]
