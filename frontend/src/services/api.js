import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  timeout: 20000,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

// Sanctum SPA: nhận cookie CSRF trước khi đăng nhập.
export async function initializeCsrf() {
  const origin = new URL(api.defaults.baseURL).origin
  await api.get(`${origin}/sanctum/csrf-cookie`)
}

export async function loginSession(payload) {
  const origin = new URL(api.defaults.baseURL).origin
  await initializeCsrf()
  return api.post(`${origin}/login`, payload)
}

api.interceptors.response.use(
  response => response,
  error => {
    const url = String(error.config?.url || '')
    if (
      [401, 419].includes(error.response?.status) &&
      !url.includes('/auth/me') && !url.endsWith('/login')
    ) {
      window.dispatchEvent(new Event('qntd-session-expired'))
    }
    return Promise.reject(error)
  }
)

export default api