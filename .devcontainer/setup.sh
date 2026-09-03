#!/usr/bin/env bash
set -e

echo "=== Configurando entorno de desarrollo Bitstreaming Radio Panel ==="

cd /workspaces/*

# Copiar archivos de entorno de desarrollo
cp dev.env .env 2>/dev/null || true
cp azuracast.dev.env azuracast.env 2>/dev/null || true
cp docker-compose.sample.yml docker-compose.yml 2>/dev/null || true

# Configurar line endings
git config core.autocrlf input

echo "=== Entorno listo. Ejecuta: docker compose up -d --build ==="
echo "=== Luego abre http://localhost en el navegador ==="