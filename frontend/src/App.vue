<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api, { loginSession } from './services/api'
import DashboardView from './views/DashboardView.vue'
import MenuItemsView from './views/MenuItemsView.vue'
import CategoriesView from './views/CategoriesView.vue'
import AreasTablesView from './views/AreasTablesView.vue'
import ReservationsView from './views/ReservationsView.vue'
import OrdersView from './views/OrdersView.vue'
import BepBarView from './views/BepBarView.vue'
import InventoryView from './views/InventoryView.vue'
import CashierView from './views/CashierView.vue'
import HumanResourcesView from './views/HumanResourcesView.vue'
import FinanceView from './views/FinanceView.vue'
import SystemView from './views/SystemView.vue'

const currentPage = ref('dashboard'), mobileMenuOpen = ref(false)
const user = ref(null), loading = ref(true), loginBusy = ref(false), loginError = ref('')
const loginForm = ref({ TenDangNhap: '', MatKhau: '', MaCS: '' })
const loginBranches = ref([])
const allNavigation = [
  { id:'dashboard', label:'Tổng quan', icon:'▦', uc:'UC1.1' },
  { id:'menu', label:'Quản lý thực đơn', icon:'▤', uc:'UC1.3' },
  { id:'categories', label:'Danh mục món', icon:'▥', uc:'UC1.2' },
  { id:'areas-tables', label:'Khu vực và bàn', icon:'▦', uc:'UC2.1' },
  { id:'reservations', label:'Đặt bàn', icon:'▣', uc:'UC2.2' },
  { id:'orders', label:'Order / POS', icon:'▤', uc:'UC2.4' },
  { id:'bep-bar', label:'Bếp / Bar', icon:'♨', uc:'UC3.2' },
  { id:'inventory', label:'Kho & mua hàng', icon:'▣', uc:'UC4.1' },
  { id:'cashier', label:'Thu ngân và hóa đơn', icon:'▣', uc:'UC5.4' },
  { id:'hr', label:'Nhân sự và tiền lương', icon:'♙', uc:'UC6.1' },
  { id:'finance', label:'Kế toán và báo cáo', icon:'▥', uc:'UC7.5' },
  { id:'system', label:'Quản trị và hỗ trợ', icon:'⚙', uc:null }
]
const has = uc => !uc || (user.value?.Quyen || []).includes('*') || (user.value?.Quyen || []).includes(uc)
const navigation = computed(() => allNavigation.filter(item => {
  if (item.id === 'system') return true
  if (item.id === 'finance') return ['UC7.1','UC7.2','UC7.3','UC7.4','UC7.5','UC7.6'].some(has)
  if (item.id === 'hr') return ['UC6.1','UC6.2','UC6.3','UC6.4'].some(has)
  if (item.id === 'inventory') return ['UC4.1','UC4.2','UC4.3','UC4.4','UC4.5','UC4.6','UC4.7'].some(has)
  return has(item.uc)
}))
const pageTitle = computed(() => allNavigation.find(item => item.id === currentPage.value)?.label || 'Tổng quan')
const today = new Intl.DateTimeFormat('vi-VN',{ weekday:'long',day:'2-digit',month:'2-digit',year:'numeric' }).format(new Date())
function navigate(page){if(!navigation.value.some(x=>x.id===page))return;currentPage.value=page;mobileMenuOpen.value=false}
async function loadLoginBranches() {
  try {
    const { data } = await api.get('/auth/branches')
    loginBranches.value = Array.isArray(data) ? data : []
    if (!loginForm.value.MaCS && loginBranches.value.length) {
      loginForm.value.MaCS = String(loginBranches.value[0].MaCS)
    }
  } catch (e) {
    loginError.value = 'Không thể tải danh sách cơ sở. Hãy kiểm tra MySQL/XAMPP.'
  }
}
async function loadMe() {
  try {
    user.value = (await api.get('/auth/me')).data
    if (!navigation.value.some(item => item.id === currentPage.value)) {
      currentPage.value = navigation.value[0]?.id || 'system'
    }
  } catch {
    user.value = null
  } finally {
    loading.value = false
  }
}
async function login() {
  if (loginBusy.value) return
  loginBusy.value = true
  loginError.value = ''

  try {
    // 1. Gửi thông tin đăng nhập và nhận cookie phiên từ Laravel.
    await loginSession({
      TenDangNhap: loginForm.value.TenDangNhap,
      MatKhau: loginForm.value.MatKhau,
      MaCS: Number(loginForm.value.MaCS),
    })

    // 2. Xác nhận phiên thực sự được lưu: không nuốt lỗi 401/403/419/500.
    const response = await api.get('/auth/me')
    const profile = response.data?.data ?? response.data

    if (!profile || !profile.MaTK) {
      throw new Error('API /auth/me không trả về thông tin tài khoản hợp lệ.')
    }

    // 3. Chỉ chuyển sang giao diện quản lý khi xác thực đã hoàn tất.
    user.value = profile
    loginForm.value.MatKhau = ''
    currentPage.value = navigation.value[0]?.id || 'system'
  } catch (e) {
    user.value = null

    const errors = e?.response?.data?.errors
    const fieldMessage = errors ? Object.values(errors).flat().join(' ') : ''
    const status = e?.response?.status
    const endpoint = String(e?.config?.url || '')
    const serverMessage = e?.response?.data?.message

    if (endpoint.includes('/auth/me')) {
      loginError.value =
        fieldMessage ||
        `Đã gửi yêu cầu đăng nhập nhưng không xác nhận được phiên (API /auth/me, HTTP ${status || 'không có phản hồi'}). ` +
        (serverMessage || 'Hãy mở frontend bằng http://127.0.0.1:5173 và kiểm tra cookie phiên Laravel.')
    } else {
      loginError.value = fieldMessage || serverMessage || e?.message || 'Không thể đăng nhập.'
    }
  } finally {
    loginBusy.value = false
  }
}
async function logout() {
  try { await api.post('/auth/logout') } catch { /* phiên có thể đã hết hạn */ }
  user.value = null
  currentPage.value = 'dashboard'
}
function expired() {
  user.value = null
  currentPage.value = 'dashboard'
  loadLoginBranches()
}
onMounted(async () => {
  window.addEventListener('qntd-session-expired', expired)
  await loadLoginBranches()
  await loadMe()
})
onUnmounted(() => window.removeEventListener('qntd-session-expired', expired))
</script>

<template>
  <div v-if="loading" class="grid min-h-screen place-items-center bg-slate-100 text-slate-600">Đang kiểm tra phiên đăng nhập...</div>
  <div v-else-if="!user" class="grid min-h-screen place-items-center bg-slate-100 p-4">
    <form @submit.prevent="login" class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-xl">
      <div class="mb-6 flex items-center gap-3"><div class="grid size-12 place-items-center rounded-xl bg-amber-600 text-2xl font-bold text-white">T</div><div><h1 class="text-2xl font-extrabold tracking-widest text-slate-800">TỰ DO</h1><p class="text-xs tracking-wider text-slate-500">HỆ THỐNG QUẢN LÝ NHÀ HÀNG</p></div></div>
      <h2 class="mb-2 text-xl font-bold text-slate-800">Đăng nhập quản trị</h2><p class="mb-5 text-sm text-slate-500">Sử dụng tài khoản do quản trị hệ thống cấp.</p>
      <div v-if="loginError" class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ loginError }}</div>
      <label class="mb-4 block text-sm font-semibold text-slate-600">Tên đăng nhập<input v-model.trim="loginForm.TenDangNhap" autocomplete="username" required class="mt-1 w-full rounded-lg border border-slate-300 p-3" /></label>
      <label class="mb-4 block text-sm font-semibold text-slate-600">Cơ sở đăng nhập
        <select v-model="loginForm.MaCS" required class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-3">
          <option value="">Chọn cơ sở</option>
          <option v-for="branch in loginBranches" :key="branch.MaCS" :value="String(branch.MaCS)">{{ branch.TenCS }}</option>
        </select>
      </label>
      <label class="mb-5 block text-sm font-semibold text-slate-600">Mật khẩu<input v-model="loginForm.MatKhau" type="password" autocomplete="current-password" required class="mt-1 w-full rounded-lg border border-slate-300 p-3" /></label>
      <button :disabled="loginBusy" type="submit" class="w-full rounded-lg bg-slate-800 p-3 text-sm font-bold text-white disabled:opacity-50">{{ loginBusy ? 'Đang đăng nhập...' : 'Đăng nhập' }}</button>
    </form>
  </div>
  <div v-else class="app-shell">
    <aside class="sidebar" :class="{'sidebar-open':mobileMenuOpen}">
      <div class="sidebar-brand"><div class="brand-mark">T</div><div class="brand-copy"><strong>TỰ DO</strong><small>QUẢN LÝ NHÀ HÀNG</small></div><button class="mobile-close" @click="mobileMenuOpen=false">×</button></div>
      <nav class="side-navigation"><div class="nav-group-label">QUẢN LÝ HỆ THỐNG</div>
        <button v-for="item in navigation" :key="item.id" class="nav-link" :class="{active:currentPage===item.id}" @click="navigate(item.id)"><span class="nav-symbol">{{ item.icon }}</span><span>{{ item.label }}</span><span v-if="currentPage===item.id" class="nav-active-dot"></span></button>
      </nav>
      <div class="sidebar-spacer"></div><div class="sidebar-bottom">QUÁN NHẬU TỰ DO · QUẢN LÝ NỘI BỘ</div>
    </aside>
    <div v-if="mobileMenuOpen" class="mobile-scrim" @click="mobileMenuOpen=false"></div>
    <div class="app-main"><header class="topbar"><div class="topbar-left"><button class="mobile-menu-button" @click="mobileMenuOpen=true">☰</button><div class="breadcrumb"><span>Hệ thống quản lý</span><b>/</b><strong>{{ pageTitle }}</strong></div></div>
      <div class="topbar-right"><div class="topbar-date">{{ today }}</div><div class="topbar-divider"></div><div class="user-profile"><div class="user-avatar">{{ (user.HoTen||user.TenDangNhap||'Q8').slice(0,2).toUpperCase() }}</div><div class="user-meta"><strong>{{ user.HoTen||user.TenDangNhap }}</strong><small>{{ user.TenVaiTro }}</small></div><button title="Đăng xuất" class="rounded-lg border px-3 py-2 text-xs" @click="logout">Đăng xuất</button></div></div>
    </header>
    <main class="main-content">
      <DashboardView v-if="currentPage==='dashboard'" @navigate="navigate" />
      <MenuItemsView v-else-if="currentPage==='menu'" />
      <CategoriesView v-else-if="currentPage==='categories'" />
      <AreasTablesView v-else-if="currentPage==='areas-tables'" />
      <ReservationsView v-else-if="currentPage==='reservations'" />
      <OrdersView v-else-if="currentPage==='orders'" />
      <BepBarView v-else-if="currentPage==='bep-bar'" />
      <InventoryView v-else-if="currentPage==='inventory'" />
      <CashierView v-else-if="currentPage==='cashier'" />
      <HumanResourcesView v-else-if="currentPage==='hr'" />
      <FinanceView v-else-if="currentPage==='finance'" />
      <SystemView v-else-if="currentPage==='system'" :user="user" @account-changed="loadMe" />
    </main>
    <footer class="footer"><span>© {{ new Date().getFullYear() }} Quán Nhậu Tự Do</span><span>Hệ thống quản lý vận hành nhà hàng</span></footer>
    </div>
  </div>
</template>


<style scoped>
/* Đăng nhập có style độc lập, không cần Tailwind. */
.grid.min-h-screen{min-height:100vh;display:grid;place-items:center;background:#eef3f8;padding:16px}
.grid.min-h-screen>form{width:min(440px,100%);padding:30px;border-radius:16px;background:white;border:1px solid #d9e3ef;box-shadow:0 18px 60px #172f4a1c}
.grid.min-h-screen>form h1{font-size:25px;margin:0;font-weight:800;color:#213d54}
.grid.min-h-screen>form h2{font-size:20px;margin:18px 0 8px;color:#243d54}
.grid.min-h-screen>form p{color:#677f92;font-size:13px;line-height:1.5}
.grid.min-h-screen>form label{display:block;font-size:13px;font-weight:600;color:#455d72;margin:14px 0}
.grid.min-h-screen>form input,.grid.min-h-screen>form select{display:block;width:100%;padding:11px;margin-top:6px;border:1px solid #bfcdda;border-radius:9px;background:#fff;font-size:14px}
.grid.min-h-screen>form button{display:block;width:100%;padding:13px;margin-top:15px;border:0;border-radius:9px;background:#234963;color:white;font-weight:800;cursor:pointer}
.grid.min-h-screen>form button:disabled{opacity:.5}
.grid.min-h-screen>form .bg-rose-50{background:#fff0f1;color:#a02939;border-radius:8px;padding:10px}
.grid.min-h-screen>form .flex{display:flex;align-items:center;gap:12px}
.grid.min-h-screen>form .size-12{width:48px;height:48px;display:grid;place-items:center;background:#b96d29;color:white;border-radius:12px;font-weight:900;font-size:26px}
</style>
