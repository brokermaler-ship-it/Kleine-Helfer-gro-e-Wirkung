# Ein Image für Sprach-Agent und Web-Server; der Befehl entscheidet, was läuft
# (siehe docker-compose.yml). Build-Kontext ist das Repository, weil der Server
# Lernmodule aus content/ und den Browser-Client aus frontend/ braucht.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    TUTOR_DATA_DIR=/data

WORKDIR /app
COPY backend/pyproject.toml backend/
COPY backend/tutor backend/tutor
# Editierbar installiert, damit die Pfade zu content/ und frontend/ relativ zum Code stimmen.
RUN pip install -e backend

# VAD- und Turn-Detector-Modelle beim Bauen laden, nicht bei jedem Start.
RUN python -m tutor.agent download-files

COPY content content
COPY frontend frontend

RUN useradd --create-home tutor && mkdir -p /data && chown tutor /data
USER tutor
VOLUME /data

EXPOSE 8000
CMD ["uvicorn", "tutor.server:app", "--host", "0.0.0.0", "--port", "8000"]
