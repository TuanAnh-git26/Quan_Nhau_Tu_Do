import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',

  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json'
  },

  timeout: 20000
})

api.interceptors.response.use(
  response => response,

  error => {
    const status = error.response?.status

    if (!error.response) {
      error.userMessage =
        'Không thể kết nối máy chủ. Vui lòng kiểm tra Backend.'
    } else if (status === 401) {
      error.userMessage =
        'Phiên đăng nhập không hợp lệ hoặc đã hết hạn.'
    } else if (status === 403) {
      error.userMessage =
        'Bạn không có quyền thực hiện thao tác này.'
    } else if (status === 404) {
      error.userMessage =
        'Không tìm thấy dữ liệu yêu cầu.'
    } else if (status === 422) {
      error.userMessage =
        'Thông tin nhập chưa hợp lệ. Vui lòng kiểm tra lại.'
    } else if (status >= 500) {
      error.userMessage =
        'Máy chủ gặp lỗi. Vui lòng thử lại sau.'
    }

    return Promise.reject(error)
  }
)

export default api
