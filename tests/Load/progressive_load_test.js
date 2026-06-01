import http from 'k6/http';
import { check, sleep } from 'k6';

// Define the progressive ramp-up/down stages
export const options = {
  insecureSkipTLSVerify: true,
  stages: [
    { duration: '10s', target: 5 },  // Ramp-up: from 0 to 5 users in 10s
    { duration: '20s', target: 15 }, // Ramp-up: from 5 to 15 users in 20s
    { duration: '20s', target: 15 }, // Stay: 15 users for 20s
    { duration: '10s', target: 30 }, // Ramp-up: from 15 to 30 users in 10s
    { duration: '20s', target: 30 }, // Stay: 30 users for 20s
    { duration: '10s', target: 0 },  // Ramp-down: from 30 to 0 users in 10s
  ],
  thresholds: {
    // Assertions: fail rate must be less than 1%, and 95% of requests must complete under 500ms
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<500'],
  },
};

// Target URL: Defaulting to local dev environment on port 8000
const BASE_URL = __ENV.TARGET_URL || 'http://localhost:8000';

export default function () {
  // 1. Visit Login Page (GET)
  const loginRes = http.get(`${BASE_URL}/login`);
  check(loginRes, {
    'login status is 200': (r) => r.status === 200,
    'login page has CSRF or form': (r) => r.body && r.body.includes('_csrf_token'),
  });
  sleep(1);

  // 2. Visit Register Page (GET)
  const registerRes = http.get(`${BASE_URL}/register`);
  check(registerRes, {
    'register status is 200': (r) => r.status === 200,
  });
  sleep(1);
}
