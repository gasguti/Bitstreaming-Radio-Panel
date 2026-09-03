# Bitstreaming Radio Panel — Guía de Despliegue (Docker)

Este documento explica cómo desplegar la imagen Docker propia en tus servidores de producción.

**Imagen principal:** `ghcr.io/gasguti/bitstreaming-radio-panel:latest`
*(construida automáticamente por GitHub Actions en cada push a `main`)*

---

## ¿Qué necesitas en el servidor?

- **Docker** + **Docker Compose v2** (ya lo tienes en `azura1` / `azura2`).
- Acceso por SSH a los servidores (tu flujo actual con Ansible sirve).

## Archivos de configuración

En el servidor tienes dos archivos clave:

| Archivo | Función |
|---|---|
| `.env` | Variables de Docker Compose (puertos, versión de la imagen, etc.) |
| `azuracast.env` | Variables de la aplicación (base URL, credenciales, etc.) |

> ⚠️ **IMPORTANTE:** mantén estos archivos tal como los tienes hoy. Solo vas a cambiar la **fuente de la imagen** de `ghcr.io/azuracast/azuracast` → `ghcr.io/gasguti/bitstreaming-radio-panel`.

---

## Paso a paso (primera vez / cambio de imagen)

### 1. Editar el `docker-compose.yml` del servidor

Verifica que el servicio `web` apunte a tu imagen:

```yaml
services:
  web:
    image: ghcr.io/gasguti/bitstreaming-radio-panel:latest
```

> NOTA: el `docker-compose.sample.yml` en este repo ya está actualizado. Si tu servidor usa el `<compose` que copió desde AzuraCast, solo cambia esa línea.

### 2. (Opcional) Quitar el servicio `updater`

El repo de AzuraCast traía un contenedor `updater` (Watchtower) que se auto-actualiza solo contra las imágenes de AzuraCast. **Para no depender de ellos, elimina ese bloque** del `docker-compose.yml` del servidor (queda documentado en este repo con el bloque eliminado).

### 3. Autenticarse a GHCR en el servidor

El `ghcr.io` solo necesita que el servidor pueda descargar **imágenes públicas** (tu repo es público), así que **normalmente no hace falta login**. Si tuvieras el repo privado, habría que añadir un token:

```bash
echo $GITHUB_TOKEN | docker login ghcr.io -u gasguti --password-stdin
```

### 4. Pull y levantar

```bash
cd /ruta/a/azura
docker compose pull
docker compose up -d
```

---

## Actualización normal

Cada vez que hagas `push` a `main` en tu repo, GitHub Actions construye y publica una imagen nueva con tag `latest`.

Para actualizar los servidores:

```bash
cd /ruta/a/azura
docker compose pull
docker compose up -d   # recrea el contenedor con la imagen nueva
```

> El primer pull puede tardar (la imagen es grande, ~6 GB según plataforma). Después usa caché local.

---

## Rollback

Las imágenes quedan versionadas por commit (`ghcr.io/gitipo/bitstreaming-radio-panel:main-<hash>` y por tag si creas `v1.0.0`). Para volver a una anterior:

```bash
docker compose pull ghcr.io/gasguti/bitstreaming-radio-panel:main-<hash_anterior>
docker compose down --timeout 60
docker compose up -d
```

---

## Uso de Ansible (opcional)

Si usas Ansible, un playbook mínimo para ambos servidores:

```yaml
- hosts: azura_servers
  tasks:
    - name: Actualizar imagen AzuraCast propia
      community.docker.docker_compose_v2:
        project_src: /opt/azuracast
        pull: always
        state: present
```

(Ajusta `project_src` a la ruta real de tu instalación.)

---

## Solución de problemas

| Problema | Solución |
|---|---|
| `Error response from daemon: manifest unknown` | La imagen/committal no existe. Verifica el nombre exacto o usa `latest`. |
| El contenedor se reinicia en bucle | Revisa logs: `docker logs azuracast --tail 50`. Suele ser permisos/`azuracast.env`. |
| No hay cambios tras el deploy | Confirma que el push llegó: `git push origin main` + Actions verdes. |
| Puerto ocupado | Revisa `AZURACAST_HTTP_PORT`/`HTTPS_PORT` en `.env`. |