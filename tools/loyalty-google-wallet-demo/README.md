# Google Wallet Demo para Fidepuntos

Genera un enlace `Agregar a Google Wallet` para una tarjeta de fidelizacion de demo. No levanta servidor ni toca el backend.

## Instalacion

```bash
cd backend/tools/loyalty-google-wallet-demo
npm install
```

## Credenciales

Coloca en esta carpeta el archivo:

```text
backend/tools/loyalty-google-wallet-demo/service-account.json
```

Debe ser una cuenta de servicio de Google Cloud con la Google Wallet API habilitada.

## Autorizar la cuenta de servicio

1. En Google Wallet Console, crea o selecciona el issuer.
2. Agrega el `client_email` del `service-account.json` como usuario autorizado del issuer.
3. Copia el Issuer ID y reemplaza `ISSUER_ID` al inicio de `generate-google-wallet-link.js`.
4. Reemplaza tambien el nombre del programa, URL publica HTTPS del logo, color de marca y datos del socio demo.

## Generar enlace

```bash
npm run generate
```

El script imprime:

```text
https://pay.google.com/gp/v/save/<JWT>
```

## Convertir el enlace a QR

Puedes convertir el enlace impreso en un QR con cualquier generador local o herramienta CLI. Ejemplo:

```bash
npm run generate > wallet-link.txt
```

Luego usa el contenido de `wallet-link.txt` como texto del QR y escanealo desde Android.

No subas `service-account.json` al repo ni lo copies a otra carpeta.
