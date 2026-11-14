
<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api, { loginSession } from './services/api'
import DashboardView from './views/DashboardView.vue'
import MenuItemsView from './views/MenuItemsView.vue'
import CategoriesView from './views/CategoriesView.vue'
import AreasTablesView from './views/AreasTablesView.vue'
import ReservationsView from './views/ReservationsView.vue'
import OrdersView from './views/OrdersView.vue'
import ComplaintsView from './views/ComplaintsView.vue'
import BepBarView from './views/BepBarView.vue'
import InventoryView from './views/InventoryView.vue'
import CashierView from './views/CashierView.vue'
import HumanResourcesView from './views/HumanResourcesView.vue'
import FinanceView from './views/FinanceView.vue'
import SystemView from './views/SystemView.vue'

const currentPage = ref('dashboard')
const mobileMenuOpen = ref(false)
const user = ref(null)
const loginBusy = ref(false)
const loginError = ref('')
const logoutError = ref('')
const sessionChecking = ref(true)
const loginBranches = ref([])
const branchesBusy = ref(false)
const loginForm = ref({
  TenDangNhap: '',
  MatKhau: '',
  MaCS: '',
})

const allNavigation = [
  { id: 'dashboard', label: 'Tổng quan', icon: '▦', uc: null },
  { id: 'menu', label: 'Quản lý thực đơn', icon: '▤', uc: 'UC2.1' },
  { id: 'categories', label: 'Danh mục món', icon: '▥', uc: 'UC2.1' },
  { id: 'areas-tables', label: 'Khu vực và bàn', icon: '▦', uc: 'UC1.2' },
  { id: 'reservations', label: 'Đặt bàn', icon: '▣', uc: 'UC1.1' },
  { id: 'orders', label: 'Order / POS', icon: '▤', uc: 'UC2.2' },
  { id: 'bep-bar', label: 'Bếp / Bar', icon: '♨', uc: 'UC3.1' },
  { id: 'complaints', label: 'Khiếu nại / sự cố', icon: '⚑', uc: 'UC2.5' },
  { id: 'inventory', label: 'Kho & mua hàng', icon: '▣', uc: 'UC4.1' },
  { id: 'cashier', label: 'Thu ngân và hóa đơn', icon: '▣', uc: 'UC5.4' },
  { id: 'hr', label: 'Nhân sự và tiền lương', icon: '♙', uc: 'UC6.1' },
  { id: 'finance', label: 'Kế toán và báo cáo', icon: '▥', uc: 'UC7.5' },
  { id: 'system', label: 'Quản trị và hỗ trợ', icon: '⚙', uc: null },
]

const has = uc =>
  !uc ||
  (user.value?.Quyen || []).includes('*') ||
  (user.value?.Quyen || []).includes(uc)

const navigation = computed(() =>
  allNavigation.filter(item => {
    if (item.id === 'system')
      return ['UC8.2', 'UC8.3', 'UC8.4', 'UC8.5'].some(has)

    if (item.id === 'orders')
      return ['UC2.2', 'UC2.3'].some(has)

    if (item.id === 'bep-bar')
      return ['UC3.1', 'UC3.2'].some(has)

    if (item.id === 'finance')
      return ['UC7.1', 'UC7.2', 'UC7.3', 'UC7.4', 'UC7.5', 'UC7.6'].some(has)

    if (item.id === 'hr')
      return ['UC6.1', 'UC6.2', 'UC6.3', 'UC6.4'].some(has)

    if (item.id === 'inventory')
      return ['UC4.1', 'UC4.2', 'UC4.3', 'UC4.4', 'UC4.5', 'UC4.6', 'UC4.7'].some(has)

    return has(item.uc)
  })
)

const pageTitle = computed(
  () => allNavigation.find(x => x.id === currentPage.value)?.label || 'Tổng quan'
)

const today = new Intl.DateTimeFormat('vi-VN', {
  weekday: 'long',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
}).format(new Date())

function navigate(page) {
  if (!navigation.value.some(x => x.id === page)) return

  currentPage.value = page
  mobileMenuOpen.value = false
}

function messageFrom(e, fallback) {
  return (
    Object.values(e?.response?.data?.errors || {}).flat().join(' ') ||
    e?.response?.data?.message ||
    e?.message ||
    fallback
  )
}

// CACHE DANH SÁCH CƠ SỞ
// Chỉ lưu mã và tên cơ sở, không lưu mật khẩu.
const BRANCH_CACHE_KEY = 'qntd:login-branches:v1'
const BRANCH_CACHE_TTL = 5 * 60 * 1000

let branchesPromise = null

function applyBranches(items) {
  loginBranches.value = items

  if (!items.some(x => String(x.MaCS) === loginForm.value.MaCS)) {
    loginForm.value.MaCS = items.length
      ? String(items[0].MaCS)
      : ''
  }
}

function readCachedBranches() {
  try {
    const saved = JSON.parse(
      localStorage.getItem(BRANCH_CACHE_KEY) || 'null'
    )

    if (
      !saved ||
      Date.now() - saved.savedAt > BRANCH_CACHE_TTL ||
      !Array.isArray(saved.items)
    ) {
      return false
    }

    const items = saved.items.filter(
      x => x && x.MaCS != null && x.TenCS
    )

    if (!items.length) return false

    applyBranches(items)
    return true
  } catch {
    return false
  }
}

// LẤY DANH SÁCH CƠ SỞ TỪ BACKEND
async function loadLoginBranches() {
  // Tránh gọi API nhiều lần cùng lúc.
  if (branchesPromise) return branchesPromise

  branchesBusy.value = true

  branchesPromise = (async () => {
    try {
      const { data } = await api.get('/auth/branches', {
        timeout: 6000,
      })

      const raw = Array.isArray(data)
        ? data
        : Array.isArray(data?.data)
          ? data.data
          : []

      const items = raw.filter(
        x => x && x.MaCS != null && x.TenCS
      )

      if (!items.length) {
        if (!loginBranches.value.length) {
          loginError.value =
            'Backend chưa trả về cơ sở hợp lệ. Kiểm tra bảng CO_SO.'
        }
        return
      }

      applyBranches(items)

      // Lưu danh sách cơ sở để lần sau hiển thị nhanh.
      try {
        localStorage.setItem(
          BRANCH_CACHE_KEY,
          JSON.stringify({
            savedAt: Date.now(),
            items,
          })
        )
      } catch {
        // Trình duyệt có thể chặn localStorage.
      }

      if (!user.value) loginError.value = ''
    } catch (e) {
      // Nếu có cache, vẫn cho người dùng chọn cơ sở.
      if (!loginBranches.value.length && !user.value) {
        loginError.value = messageFrom(
          e,
          'Không tải được danh sách cơ sở. Kiểm tra Laravel và MySQL.'
        )
      }
    } finally {
      branchesBusy.value = false
      branchesPromise = null
    }
  })()

  return branchesPromise
}

// CẬP NHẬT TÀI KHOẢN
function acceptProfile(data) {
  if (!data?.MaTK || !Array.isArray(data.Quyen)) {
    throw new Error(
      'API không trả về đầy đủ thông tin tài khoản và quyền.'
    )
  }

  user.value = data

  if (!navigation.value.some(x => x.id === currentPage.value)) {
    currentPage.value = 'dashboard'
  }
}

// KHÔNG GỌI HÀM NÀY KHI MỚI MỞ WEBSITE
async function loadMe() {
  try {
    const { data } = await api.get('/auth/me', {
      timeout: 6000,
    })

    if (user.value) acceptProfile(data)
  } catch (e) {
    if ([401, 419].includes(e?.response?.status)) {
      expired()
    }
  }
}

// KHÔI PHỤC PHIÊN SAU KHI F5 (chạy nền, không chặn form đăng nhập)
async function restoreSession() {
  const timer = window.setTimeout(() => {
    sessionChecking.value = false
  }, 1500)

  try {
    const { data } = await api.get('/auth/me', { timeout: 6000 })
    if (!user.value) {
      acceptProfile(data)
      currentPage.value = 'dashboard'
    }
  } catch {
    // 401/419 hoặc lỗi mạng: giữ form đăng nhập
  } finally {
    window.clearTimeout(timer)
    sessionChecking.value = false
  }
}

// ĐĂNG NHẬP
async function login() {
  if (loginBusy.value) return

  loginBusy.value = true
  loginError.value = ''

  try {
    const response = await loginSession({
      TenDangNhap: loginForm.value.TenDangNhap.trim(),
      MatKhau: loginForm.value.MatKhau,
      MaCS: Number(loginForm.value.MaCS),
    })

    // API đăng nhập trả luôn thông tin tài khoản.
    // Không gọi /auth/me lần nữa.
    acceptProfile(response.data?.data)

    loginForm.value.MatKhau = ''
    currentPage.value = 'dashboard'
  } catch (e) {
    loginError.value = messageFrom(
      e,
      'Đăng nhập không thành công. Kiểm tra tài khoản, cơ sở và kết nối.'
    )
  } finally {
    loginBusy.value = false
  }
}

// ĐĂNG XUẤT
async function logout() {
  logoutError.value = ''
  try {
    await api.post('/auth/logout')

    user.value = null
    currentPage.value = 'dashboard'
  } catch (e) {
    if ([401, 419].includes(e?.response?.status)) {
      user.value = null
      currentPage.value = 'dashboard'
    } else {
      logoutError.value = messageFrom(e, 'Không thể đăng xuất. Vui lòng thử lại.')
    }
  }
}

// PHIÊN ĐĂNG NHẬP HẾT HẠN
function expired() {
  user.value = null
  currentPage.value = 'dashboard'

  loadLoginBranches()
}

// KHI MỞ WEBSITE:
// 1. Hiện form đăng nhập ngay.
// 2. Hiện danh sách cơ sở đã lưu (nếu có).
// 3. Cập nhật cơ sở từ backend.
onMounted(() => {
  window.addEventListener('qntd-session-expired', expired)

  readCachedBranches()
  void loadLoginBranches()
  void restoreSession()
})

onUnmounted(() => {
  window.removeEventListener('qntd-session-expired', expired)
})
</script>

<template>
  <!-- ĐANG KIỂM TRA PHIÊN -->
  <div
    v-if="!user && sessionChecking"
    class="grid min-h-screen place-items-center bg-slate-100 text-sm text-slate-500"
  >
    Đang kiểm tra phiên…
  </div>

  <!-- GIAO DIỆN ĐĂNG NHẬP -->
  <div
    v-else-if="!user"
    class="grid min-h-screen place-items-center bg-slate-100 p-4"
  >
    <form
      @submit.prevent="login"
      class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-xl"
    >
      <div class="mb-6 flex items-center gap-3">
        <div
          class="grid size-12 place-items-center rounded-xl bg-amber-600 text-2xl font-bold text-white"
        >
          T
        </div>

        <div>
          <h1 class="text-2xl font-extrabold tracking-widest text-slate-800">
            TỰ DO
          </h1>
          <p class="text-xs tracking-wider text-slate-500">
            HỆ THỐNG QUẢN LÝ NHÀ HÀNG
          </p>
        </div>
      </div>

      <h2 class="mb-2 text-xl font-bold text-slate-800">
        Đăng nhập hệ thống
      </h2>

      <p class="mb-5 text-sm text-slate-500">
        Sử dụng tài khoản do quản trị viên cấp.
      </p>

      <div
        v-if="loginError"
        class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700"
        role="alert"
      >
        {{ loginError }}
      </div>

      <button
        type="button"
        class="retry-branch-button"
        :disabled="branchesBusy"
        @click="loadLoginBranches"
      >
        {{
          branchesBusy
            ? 'Đang cập nhật cơ sở...'
            : 'Tải lại danh sách cơ sở'
        }}
      </button>

      <label class="mb-4 block text-sm font-semibold text-slate-600">
        Tên đăng nhập

        <input
          v-model.trim="loginForm.TenDangNhap"
          autocomplete="username"
          required
          class="mt-1 w-full rounded-lg border border-slate-300 p-3"
        />
      </label>

      <label class="mb-4 block text-sm font-semibold text-slate-600">
        Cơ sở đăng nhập

        <select
          v-model="loginForm.MaCS"
          required
          class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-3"
        >
          <option value="">Chọn cơ sở</option>

          <option
            v-for="branch in loginBranches"
            :key="branch.MaCS"
            :value="String(branch.MaCS)"
          >
            {{ branch.TenCS }}
          </option>
        </select>
      </label>

      <label class="mb-5 block text-sm font-semibold text-slate-600">
        Mật khẩu

        <input
          v-model="loginForm.MatKhau"
          type="password"
          autocomplete="current-password"
          required
          class="mt-1 w-full rounded-lg border border-slate-300 p-3"
        />
      </label>

      <button
        :disabled="loginBusy || !loginBranches.length"
        type="submit"
        class="w-full rounded-lg bg-slate-800 p-3 text-sm font-bold text-white disabled:opacity-50"
      >
        {{ loginBusy ? 'Đang đăng nhập...' : 'Đăng nhập' }}
      </button>
    </form>
  </div>

  <!-- GIAO DIỆN QUẢN LÝ SAU ĐĂNG NHẬP -->
  <div v-else class="app-shell">
    <aside
      class="sidebar"
      :class="{ 'sidebar-open': mobileMenuOpen }"
    >
      <div class="sidebar-brand">
        <div class="brand-mark">T</div>

        <div class="brand-copy">
          <strong>TỰ DO</strong>
          <small>QUẢN LÝ NHÀ HÀNG</small>
        </div>

        <button
          class="mobile-close"
          @click="mobileMenuOpen = false"
        >
          ×
        </button>
      </div>

      <nav class="side-navigation">
        <div class="nav-group-label">
          QUẢN LÝ HỆ THỐNG
        </div>

        <button
          v-for="item in navigation"
          :key="item.id"
          class="nav-link"
          :class="{ active: currentPage === item.id }"
          @click="navigate(item.id)"
        >
          <span class="nav-symbol">{{ item.icon }}</span>
          <span>{{ item.label }}</span>

          <span
            v-if="currentPage === item.id"
            class="nav-active-dot"
          ></span>
        </button>
      </nav>

      <div class="sidebar-spacer"></div>

      <div class="sidebar-bottom">
        QUÁN NHẬU TỰ DO · QUẢN LÝ NỘI BỘ
      </div>
    </aside>

    <div
      v-if="mobileMenuOpen"
      class="mobile-scrim"
      @click="mobileMenuOpen = false"
    ></div>

    <div class="app-main">
      <header class="topbar">
        <div class="topbar-left">
          <button
            class="mobile-menu-button"
            @click="mobileMenuOpen = true"
          >
            ☰
          </button>

          <div class="breadcrumb">
            <span>Hệ thống quản lý</span>
            <b>/</b>
            <strong>{{ pageTitle }}</strong>
          </div>
        </div>

        <div class="topbar-right">
          <div class="topbar-date">{{ today }}</div>
          <div class="topbar-divider"></div>

          <div class="user-profile">
            <div class="user-avatar">
              {{
                (user.HoTen || user.TenDangNhap || 'Q8')
                  .slice(0, 2)
                  .toUpperCase()
              }}
            </div>

            <div class="user-meta">
              <strong>
                {{ user.HoTen || user.TenDangNhap }}
              </strong>
              <small>{{ user.TenVaiTro }}</small>
            </div>

            <button
              title="Đăng xuất"
              class="rounded-lg border px-3 py-2 text-xs"
              @click="logout"
            >
              Đăng xuất
            </button>
            <p v-if="logoutError" class="mt-1 text-xs text-red-600">{{ logoutError }}</p>
          </div>
        </div>
      </header>

      <main class="main-content">
        <DashboardView
          v-if="currentPage === 'dashboard'"
          :user="user"
          :navigation="navigation"
          @navigate="navigate"
        />

        <MenuItemsView
          v-else-if="currentPage === 'menu'"
        />

        <CategoriesView
          v-else-if="currentPage === 'categories'"
        />

        <AreasTablesView
          v-else-if="currentPage === 'areas-tables'"
        />

        <ReservationsView
          v-else-if="currentPage === 'reservations'"
        />

        <OrdersView
          v-else-if="currentPage === 'orders'"
        />

        <BepBarView
          v-else-if="currentPage === 'bep-bar'"
        />

        <ComplaintsView
          v-else-if="currentPage === 'complaints'"
          :user="user"
        />

        <InventoryView
          v-else-if="currentPage === 'inventory'"
        />

        <CashierView
          v-else-if="currentPage === 'cashier'"
        />

        <HumanResourcesView
          v-else-if="currentPage === 'hr'"
        />

        <FinanceView
          v-else-if="currentPage === 'finance'"
          :user="user"
        />

        <SystemView
          v-else-if="currentPage === 'system'"
          :user="user"
          @account-changed="loadMe"
        />
      </main>

      <footer class="footer">
        <span>
          © {{ new Date().getFullYear() }} Quán Nhậu Tự Do
        </span>

        <span>
          Hệ thống quản lý vận hành nhà hàng
        </span>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.grid.min-h-screen {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #eef3f8;
  padding: 16px;
}

.grid.min-h-screen > form {
  width: min(440px, 100%);
  padding: 30px;
  border-radius: 16px;
  background: white;
  border: 1px solid #d9e3ef;
  box-shadow: 0 18px 60px #172f4a1c;
}

.grid.min-h-screen > form h1 {
  font-size: 25px;
  margin: 0;
  font-weight: 800;
  color: #213d54;
}

.grid.min-h-screen > form h2 {
  font-size: 20px;
  margin: 18px 0 8px;
  color: #243d54;
}

.grid.min-h-screen > form p {
  color: #677f92;
  font-size: 13px;
  line-height: 1.5;
}

.grid.min-h-screen > form label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #455d72;
  margin: 14px 0;
}

.grid.min-h-screen > form input,
.grid.min-h-screen > form select {
  display: block;
  width: 100%;
  padding: 11px;
  margin-top: 6px;
  border: 1px solid #bfcdda;
  border-radius: 9px;
  background: #fff;
  font-size: 14px;
}

.grid.min-h-screen > form button {
  display: block;
  width: 100%;
  padding: 13px;
  margin-top: 15px;
  border: 0;
  border-radius: 9px;
  background: #234963;
  color: white;
  font-weight: 800;
  cursor: pointer;
}

.grid.min-h-screen > form button:disabled {
  opacity: 0.5;
}

.grid.min-h-screen > form .bg-rose-50 {
  background: #fff0f1;
  color: #a02939;
  border-radius: 8px;
  padding: 10px;
}

.grid.min-h-screen > form .flex {
  display: flex;
  align-items: center;
  gap: 12px;
}

.grid.min-h-screen > form .size-12 {
  width: 48px;
  height: 48px;
  display: grid;
  place-items: center;
  background: #b96d29;
  color: white;
  border-radius: 12px;
  font-weight: 900;
  font-size: 26px;
}

.grid.min-h-screen > form .retry-branch-button {
  margin: 4px 0 12px;
  padding: 10px;
  background: #e8f1fa;
  color: #234963;
  border: 1px solid #a7c1d5;
}
</style>
