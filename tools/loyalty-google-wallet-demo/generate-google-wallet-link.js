#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const jwt = require('jsonwebtoken');
const { google } = require('googleapis');

// Constantes a rellenar para la demo.
const ISSUER_ID = 'REEMPLAZAR_ISSUER_ID';
const PROGRAM_NAME = 'Fidepuntos Demo';
const PROGRAM_LOGO_URL = 'https://example.com/logo-fidepuntos.png';
const BRAND_COLOR = '#1D4ED8';
const DEMO_MEMBER = {
  accountName: 'Cliente Demo',
  accountId: 'FID-DEMO-0001',
  pointsBalance: 1250
};
const ORIGINS = ['https://fidepuntos.tecnolts.com'];

const SERVICE_ACCOUNT_FILE = path.join(__dirname, 'service-account.json');

if (!fs.existsSync(SERVICE_ACCOUNT_FILE)) {
  console.error('No existe ./service-account.json. Coloca la cuenta de servicio en esta carpeta.');
  process.exit(1);
}

if (ISSUER_ID === 'REEMPLAZAR_ISSUER_ID') {
  console.error('Edita ISSUER_ID al inicio de generate-google-wallet-link.js antes de ejecutar.');
  process.exit(1);
}

const credentials = JSON.parse(fs.readFileSync(SERVICE_ACCOUNT_FILE, 'utf8'));
if (!credentials.client_email || !credentials.private_key) {
  console.error('service-account.json debe incluir client_email y private_key.');
  process.exit(1);
}

google.auth.fromJSON(credentials);

const classId = `${ISSUER_ID}.fidepuntos_demo_class`;
const objectId = `${ISSUER_ID}.${DEMO_MEMBER.accountId.toLowerCase().replace(/[^a-z0-9_]/g, '_')}`;

const loyaltyClass = {
  id: classId,
  issuerName: 'TECNOLTS',
  programName: PROGRAM_NAME,
  programLogo: {
    sourceUri: {
      uri: PROGRAM_LOGO_URL
    },
    contentDescription: {
      defaultValue: {
        language: 'es',
        value: PROGRAM_NAME
      }
    }
  },
  hexBackgroundColor: BRAND_COLOR
};

const loyaltyObject = {
  id: objectId,
  classId,
  state: 'active',
  accountName: DEMO_MEMBER.accountName,
  accountId: DEMO_MEMBER.accountId,
  loyaltyPoints: {
    balance: {
      int: DEMO_MEMBER.pointsBalance
    },
    label: 'Puntos'
  },
  barcode: {
    type: 'QR_CODE',
    value: DEMO_MEMBER.accountId,
    alternateText: DEMO_MEMBER.accountId
  }
};

const claims = {
  iss: credentials.client_email,
  aud: 'google',
  typ: 'savetowallet',
  origins: ORIGINS,
  payload: {
    loyaltyClasses: [loyaltyClass],
    loyaltyObjects: [loyaltyObject]
  }
};

const token = jwt.sign(claims, credentials.private_key, { algorithm: 'RS256' });
console.log(`https://pay.google.com/gp/v/save/${token}`);
