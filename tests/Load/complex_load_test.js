import http from 'k6/http';
import { check, sleep } from 'k6';
import { parseHTML } from 'k6/html';

// Progressive load stages
export const options = {
  insecureSkipTLSVerify: true,
  stages: [
    { duration: '10s', target: 5 },  // Ramp-up to 5 users
    { duration: '20s', target: 15 }, // Ramp-up to 15 users
    { duration: '30s', target: 50 }, // Ramp-up to 50 users
    { duration: '40s', target: 100 }, // Ramp-up to 100 users
    { duration: '50s', target: 100 }, // Stay at 100 users
    { duration: '10s', target: 0 },  // Ramp-down to 0 users
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],    // Errors should be < 1%
    http_req_duration: ['p(95)<1500'], // 95% of requests should be below 1.5s (authenticated routes are heavier)
  },
};

const BASE_URL = __ENV.TARGET_URL || 'http://localhost:8000';

export default function () {
  // Use a jar to store and send cookies for session persistence
  const jar = http.cookieJar();

  // 1. Visit Login Page to get CSRF token
  const loginPageRes = http.get(`${BASE_URL}/login`);
  check(loginPageRes, {
    'login page status is 200': (r) => r.status === 200,
  });

  const doc = parseHTML(loginPageRes.body);
  const csrfToken = doc.find('input[name="_csrf_token"]').attr('value');

  check(csrfToken, {
    'found CSRF token': (token) => token !== undefined && token !== '',
  });

  sleep(0.5);

  // 2. Perform Login POST
  const loginPostRes = http.post(`${BASE_URL}/login`, {
    _username: 'testuser',
    _password: 'testpassword',
    _csrf_token: csrfToken,
  });

  check(loginPostRes, {
    'login post successful (redirect or ok)': (r) => r.status === 200 || r.status === 302,
  });

  sleep(1);

  // 3. Visit General Channel
  const channelRes = http.get(`${BASE_URL}/channels/general`);
  check(channelRes, {
    'channel page loaded': (r) => r.status === 200,
  });

  sleep(1);

  // 4. Send Message POST (app_publish)
  const publishRes = http.post(`${BASE_URL}/channels/general/publish`, {
    message: `Message de test de montée en charge (VU: ${__VU}, Iter: ${__ITER}) 🚀`,
  });

  check(publishRes, {
    'message publication successful': (r) => r.status === 200,
  });

  // Human thinking time before next iteration
  sleep(Math.random() * 1.5 + 0.5);
}
