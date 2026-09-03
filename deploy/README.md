# Despliegue con Ansible

Este playbook despliega el fork **Bitstreaming Radio Panel** en tus servidores.

## Requisitos

- Ansible instalado en tu WSL (`sudo apt install ansible`)
- Llave SSH configurada en WSL para acceder a los servidores (usuario `ubuntu`)
- La imagen ya construida en `ghcr.io/gasguti/bitstreaming-radio-panel:latest`

## Uso

Desde WSL, dentro de esta carpeta:

```bash
# 1. Probar conexión a los servidores (solo azura1 por ahora)
ansible -i inventory.yml all -m ping

# 2. Desplegar (backup + cambiar imagen + pull + reload)
ansible-playbook -i inventory.yml deploy.yml
```

## Qué hace el playbook

1. **Backup completo** de AzuraCast (base + config) → `/var/azuracast/backups/azuracast-backup-<fecha>.zip`
2. **Cambia la imagen** en `docker-compose.yml` de `ghcr.io/azuracast/azuracast` → `ghcr.io/gasguti/bitstreaming-radio-panel:latest`
3. **Descarga** la imagen nueva (`docker compose pull`)
4. **Recrea** el contenedor (`docker compose up -d`) — los datos (volumes) NO se tocan
5. **Verifica** que el panel responde

## Activar el segundo servidor

Cuando valides en `azura1`, descomenta el bloque `azura2` en `inventory.yml` y vuelve a ejecutar el playbook. Se desplegará en ambos.

## Rollback

Si algo sale mal, restaura el backup desde el servidor:

```bash
cd /var/azuracast
./docker.sh restore /var/azuracast/backups/azuracast-backup-<fecha>.zip
```

O vuelve a la imagen anterior de AzuraCast cambiando la línea `image:` en `docker-compose.yml` a `ghcr.io/azuracast/azuracast:stable` y `docker compose up -d`.