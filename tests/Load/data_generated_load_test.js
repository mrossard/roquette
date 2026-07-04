/*
 * =============================================================================
 *  Test de charge — Roquette (données générées)
 * =============================================================================
 *
 * Prérequis :
 *   1. Générer les données de test :
 *        bin/console app:seed-load-test-data --force
 *      (optionnel : --users 2000 --dms 1600 --dm-messages 40000 --channel-messages 30000)
 *
 *   2. Login throttling — IMPORTANT
 *      La config sécurité limite à 5 tentatives/min/IP.
 *      En local (même machine que le serveur), cette limite sera atteinte.
 *      Pour le test de charge, désactivez-la temporairement dans
 *      config/packages/security.yaml :
 *        # login_throttling: ~
 *      Ou passez le serveur en APP_ENV=test.
 *      En conditions réelles (k6 sur une machine distante), ce n'est pas un problème.
 *
 *   3. Lancer le serveur (mode non-debug recommandé) :
 *        APP_DEBUG=0 symfony server:start -d
 *
 *   4. Lancer le test k6 :
 *        k6 run tests/Load/data_generated_load_test.js
 *      Options :
 *        TARGET_URL=http://app.local  (défaut: http://localhost:8000)
 *        NUM_USERS=500                (défaut: 2000)
 *        PASSWORD=secret              (défaut: loadtest_pass)
 *
 * =============================================================================
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { parseHTML } from 'k6/html';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export const options = {
  insecureSkipTLSVerify: true,
  stages: [
    { duration: '2m', target: 20 },   // warmup — quelques utilisateurs
    { duration: '3m', target: 50 },   // montée lente
    { duration: '3m', target: 100 },  // charge modérée
    { duration: '3m', target: 200 },  // charge soutenue
    { duration: '3m', target: 300 },  // pic de charge
    { duration: '3m', target: 300 },  // palier
    { duration: '3m', target: 0 },    // descente
  ],
  thresholds: {
    http_req_failed: ['rate<0.02'],    // < 2% d'erreurs (tolère les échecs de login throttling)
    http_req_duration: ['p(95)<2000'], // 95% des requêtes < 2s
    http_req_duration: ['p(99)<5000'], // 99% des requêtes < 5s
  },
};

const BASE_URL      = __ENV.TARGET_URL || 'http://localhost:8000';
const NUM_USERS     = parseInt(__ENV.NUM_USERS || '2000');
const PASSWORD      = __ENV.PASSWORD || 'loadtest_pass';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getUserId() {
  return ((__VU - 1) % NUM_USERS) + 1;
}

function getPrivateChannelSlug(userId) {
  return 'private-channel-' + (Math.floor((userId - 1) / 20) + 1);
}

// Tente de se connecter ; retourne true si la session est active.
function login(userId) {
  const loginPage = http.get(`${BASE_URL}/login`, {
    tags: { name: 'login_page' },
  });

  const doc = parseHTML(loginPage.body);
  const csrfToken = doc.find('input[name="_csrf_token"]').attr('value');

  if (!csrfToken) {
    return false;
  }

  const res = http.post(`${BASE_URL}/login`, {
    _username: `loadtest_${userId}`,
    _password: PASSWORD,
    _csrf_token: csrfToken,
  }, {
    tags: { name: 'login_post' },
  });

  // k6 suit les redirects automatiquement.
  // Après un login réussi : redirigé vers / (dashboard) → status 200.
  // Après un échec : reste sur /login → status 200 ou 422.
  const loggedIn = res.status === 200 && !res.url.includes('/login');

  return loggedIn;
}

// ---------------------------------------------------------------------------
// Scénario principal (exécuté par chaque VU à chaque itération)
// ---------------------------------------------------------------------------

export default function () {
  const userId = getUserId();
  const username = `loadtest_${userId}`;
  const privateSlug = getPrivateChannelSlug(userId);

  // ---- 1. Vérifier session / login ----
  let dashRes = http.get(`${BASE_URL}/`, {
    tags: { name: 'dashboard' },
  });

  // Si redirigé vers /login, la session est invalide → s'authentifier
  if (dashRes.url.includes('/login')) {
    login(userId);
    dashRes = http.get(`${BASE_URL}/`, {
      tags: { name: 'dashboard_retry' },
    });
  }

  check(dashRes, {
    'dashboard accessible': (r) => r.status === 200 && !r.url.includes('/login'),
  });

  sleep(0.5 + Math.random() * 1.5);

  // ---- 2. Charger le canal général ----
  const genRes = http.get(`${BASE_URL}/channels/general`, {
    tags: { name: 'channel_general' },
  });
  check(genRes, { 'canal général chargé': (r) => r.status === 200 });

  sleep(0.3 + Math.random() * 0.7);

  // ---- 3. Publier un message dans le général ----
  const msgText = `Test de charge #${userId}-${__ITER} [VU ${__VU}]`;
  const pubRes = http.post(`${BASE_URL}/channels/general/publish`, {
    message: msgText,
  }, {
    tags: { name: 'publish_general' },
  });
  check(pubRes, { 'message publié (général)': (r) => r.status === 200 });

  sleep(0.5 + Math.random() * 1);

  // ---- 4. Charger le canal privé de l'utilisateur ----
  const privRes = http.get(`${BASE_URL}/channels/${privateSlug}`, {
    tags: { name: 'channel_private' },
  });
  check(privRes, { 'canal privé chargé': (r) => r.status === 200 });

  sleep(0.3 + Math.random() * 0.5);

  // ---- 5. Publier un message dans le canal privé (50% des cas) ----
  if (Math.random() > 0.5) {
    http.post(`${BASE_URL}/channels/${privateSlug}/publish`, {
      message: `Privé #${userId}-${__ITER}`,
    }, {
      tags: { name: 'publish_private' },
    });
  }

  // ---- 6. Pause avant la prochaine itération (comportement utilisateur réaliste) ----
  sleep(1.5 + Math.random() * 3.5);
}
