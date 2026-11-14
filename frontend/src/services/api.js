
import axios from 'axios'

// API dùng Vite proxy khi chạy npm run dev.
// frontend/.env cần có VITE_API_URL=/api

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

// Lấy CSRF cookie của Laravel Sanctum.
export async function initializeCsrf() {
  await api.get('/sanctum/csrf-cookie', {
    baseURL: '/',
    timeout: 8000,
  })
}

// Đăng nhập và nhận thông tin tài khoản.
export async function loginSession(payload) {
  await initializeCsrf()

  return api.post('/login', payload, {
    baseURL: '/',
    timeout: 10000,
  })
}

// Xử lý lỗi hết phiên đăng nhập.
api.interceptors.response.use(
  response => response,

  error => {
    const path = String(error.config?.url || '')
    const status = error.response?.status

    if (
      [401, 419].includes(status) &&
      !path.includes('/auth/me') &&
      !path.endsWith('/login') &&
      !path.includes('/sanctum/csrf-cookie')
    ) {
      window.dispatchEvent(
        new Event('qntd-session-expired')
      )
    }

    return Promise.reject(error)
  }
)

export default api
