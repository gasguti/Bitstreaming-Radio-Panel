# Probar el fork en GitHub Codespaces

Esta guía explica cómo probar **Bitstreaming Radio Panel** en un entorno aislado (Codespaces) **antes de desplegar en producción**.

## ¿Por qué Codespaces?

- **Gratis** para repos públicos (cuota mensual de horas).
- **Aislado** — no toca tus servidores (`azura1`, `azura2`).
- Trae **Docker incluido** — puedes levantar la app sin instalar nada.
- Puedes **ver la UI con tu marca** (login, navbar, colores) antes de desplegar.

## Pasos

### 1. Abrir un Codespace

1. Ve a tu repo: `https://github.com/gasguti/Bitstreaming-Radio-Panel`
2. Botón verde **Code** → pestaña **Codespaces** → **Create codespace on main**.
3. Espera a que se cree (1-3 min). Se abrirá VS Code en la nube.

### 2. Levantar la app (opción A: imagen ya construida)

La imagen ya está construida en GHCR con cada push. Para probarla directamente:

```bash
# Copiar config de desarrollo
cp dev.env .env
cp azuracast.dev.env azuracast.env
cp docker-compose.sample.yml docker-compose.yml

# Levantar con la imagen de desarrollo (compila el código del repo)
docker compose up -d --build
```

### 3. Ver la app

1. Codespaces reenvía el puerto 80 automáticamente.
2. Abre el navegador en la URL que te da Codespaces (o `http://localhost:80`).
3. Verás el **login con tu logo** → "Welcome to Bitstreaming Radio Panel!".
4. Entra con las credenciales de desarrollo (las de `azuracast.dev.env`).

### 4. Verificar el rebranding

- [ ] Login muestra el logo de Bitstreaming
- [ ] Navbar muestra el logo (no texto "azura cast")
- [ ] Colores más oscuros (azul `#1565c0`)
- [ ] Fondo degradado en páginas públicas
- [ ] Footer dice "Powered by Bitstreaming Radio Panel"
- [ ] No aparece la notificación de donación
- [ ] Título de pestaña dice "Bitstreaming Radio Panel"

### 5. Cerrar el Codespace

Cuando termines, cierra el Codespace (para no gastar horas):
- En VS Code: `Ctrl+Shift+P` → "Codespaces: Stop Codespace".
- O desde GitHub: tu repo → Codespaces → botón ⋮ → Stop.

## Solución de problemas

| Problema | Solución |
|---|---|
| El build tarda mucho | Es normal la primera vez (compila todo). Usa la imagen de GHCR si no necesitas cambios de código. |
| Puerto 80 no abre | En Codespaces, ve a la pestaña "Ports" y abre el puerto 80 manualmente. |
| Error de permisos | Ejecuta `sudo chown -R vscode:vscode /workspaces/*` |
| La app no arranca | Revisa logs: `docker compose logs web --tail 50` |

## Nota importante

Codespaces es para **probar**, no para producción. Cuando valides que todo se ve bien, despliega en `azura1` con Ansible (ver `deploy/README.md`).