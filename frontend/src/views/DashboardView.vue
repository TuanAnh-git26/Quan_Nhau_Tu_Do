<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api from '../services/api'
import '../styles/qntd-modules.css'

const props = defineProps({
  user: { type: Object, required: true },
  navigation: { type: Array, default: () => [] },
})
const emit = defineEmits(['navigate'])
const has = uc => (props.user?.Quyen || []).includes('*') || (props.user?.Quyen || []).includes(uc)
const maySeeMenu = computed(() => ['UC2.1', 'UC2.2', 'UC2.3'].some(has))
const maySeeCategories = computed(() => ['UC2.1', 'UC2.2', 'UC2.4'].some(has))
const maySeeFinance = computed(() => has('UC7.5'))
const maySeeInventory = computed(() => has('UC4.1'))
const canOpen = id => props.navigation.some(x => x.id === id)
const quickLinks = computed(() => props.navigation.filter(x => x.id !== 'dashboard'))

const branches = ref([])
const selectedBranch = ref(String(props.user?.MaCS || ''))
const currentDate = new Date().toLocaleDateString('sv-SE')
const from = ref(currentDate.slice(0, 7) + '-01')
const to = ref(currentDate)
const loadingBranches = ref(true)
const loadingMetrics = ref(false)
const error = ref('')
const categories = ref([])
const menuItems = ref([])
const finance = ref(null)
const inventory = ref(null)
let sequence = 0

const activeCategories = computed(() => categories.value.filter(x => x.TrangThai === 'Dang hoat dong').length)
const availableItems = computed(() => menuItems.value.filter(x => x.CoTheBan).length)
const missingPrices = computed(() => menuItems.value.filter(x => x.DonGia == null).length)
const money = value => new Intl.NumberFormat('vi-VN', {
  style: 'currency', currency: 'VND', maximumFractionDigits: 0,
}).format(Number(value || 0))
const failure = e => e?.response?.data?.message || e?.message || 'Không tải được dữ liệu.'

async function loadBranches() {
  loadingBranches.value = true
  error.value = ''
  try {
    const { data } = await api.get('/branches')
    branches.value = Array.isArray(data) ? data : []
    if (!branches.value.some(x => String(x.MaCS) === selectedBranch.value)) {
      selectedBranch.value = branches.value[0] ? String(branches.value[0].MaCS) : ''
    }
    await loadMetrics()
  } catch (e) {
    error.value = 'Không tải được cơ sở: ' + failure(e)
  } finally {
    loadingBranches.value = false
  }
}

async function loadMetrics() {
  const id = ++sequence
  error.value = ''
  categories.value = []
  menuItems.value = []
  finance.value = null
  inventory.value = null
  if (!selectedBranch.value) return

  if (from.value > to.value) {
    error.value = 'Ngày bắt đầu không được lớn hơn ngày kết thúc.'
    return
  }
  loadingMetrics.value = true
  const jobs = []
  if (maySeeCategories.value) jobs.push({ name: 'Danh mục', key: 'categories', task: api.get('/categories') })
  if (maySeeMenu.value) jobs.push({ name: 'Thực đơn', key: 'menu', task: api.get('/menu-items', { params: { MaCS: selectedBranch.value } }) })
  if (maySeeFinance.value) jobs.push({ name: 'Tài chính', key: 'finance', task: api.get('/finance/dashboard', {
    params: { MaCS: selectedBranch.value, from: from.value, to: to.value },
  }) })

  if (maySeeInventory.value) jobs.push({ name: 'Kho', key: 'inventory', task: api.get('/inventory/dashboard', { params: { MaCS: selectedBranch.value } }) })

  // Các nguồn dữ liệu độc lập được tải đồng thời. Một phần lỗi không làm mất tất cả dữ liệu.
  const results = await Promise.allSettled(jobs.map(x => x.task))
  if (id !== sequence) return // Bỏ kết quả cũ khi người dùng chọn cơ sở khác.
  const errors = []
  results.forEach((result, i) => {
    const key = jobs[i].key
    if (result.status === 'rejected') {
      errors.push(jobs[i].name + ': ' + failure(result.reason))
    } else if (key === 'categories') {
      categories.value = Array.isArray(result.value.data) ? result.value.data : []
    } else if (key === 'menu') {
      menuItems.value = Array.isArray(result.value.data) ? result.value.data : []
    } else if (key === 'inventory') {
      inventory.value = result.value.data || {}
    } else {
      finance.value = result.value.data || {}
    }
  })
  error.value = errors.join(' | ')
  loadingMetrics.value = false
}
function refresh() { void loadBranches() }
onMounted(refresh)
onUnmounted(() => { sequence++ })
</script>

<template>
  <div class="ui-view ui-view--dashboard">
  <div class="qntd-module space-y-5 text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-bold tracking-wider text-amber-600">HỆ THỐNG QUẢN LÝ QUÁN NHẬU TỰ DO</p>
        <h1 class="mt-1 text-2xl font-bold">Tổng quan hoạt động</h1>
        <p class="mt-1 text-sm text-slate-500">Hiển thị theo cơ sở và quyền được cấp cho {{ user.HoTen || user.TenDangNhap }}.</p>
      </div>
      <button class="rounded-lg border bg-white px-4 py-2 text-sm disabled:opacity-50" :disabled="loadingBranches || loadingMetrics" @click="refresh">↻ Làm mới</button>
    </header>

    <div v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ error }}</div>
    <section class="flex flex-wrap items-end gap-3 rounded-xl border bg-white p-4">
      <label class="min-w-52 flex-1 text-sm font-semibold">Cơ sở
        <select v-model="selectedBranch" class="mt-1 block w-full rounded-lg border p-2" :disabled="loadingBranches" @change="loadMetrics">
          <option v-for="branch in branches" :key="branch.MaCS" :value="String(branch.MaCS)">{{ branch.TenCS }}</option>
        </select>
      </label>
      <template v-if="maySeeFinance">
        <label class="text-sm font-semibold">Từ ngày<input v-model="from" type="date" class="mt-1 block rounded-lg border p-2" /></label>
        <label class="text-sm font-semibold">Đến ngày<input v-model="to" type="date" class="mt-1 block rounded-lg border p-2" /></label>
        <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white" @click="loadMetrics">Xem báo cáo</button>
      </template>
    </section>

    <div v-if="loadingBranches || loadingMetrics" class="text-sm text-slate-500">Đang tải dữ liệu được phép truy cập...</div>

    <div v-if="maySeeFinance" class="dash-block">
      <h2 class="text-lg font-bold">Doanh thu – thu chi theo kỳ</h2>
      <div v-if="finance" class="dash-grid">
        <article v-for="[key, label] in [
          ['DoanhThuThanhToan', 'Doanh thu đã thanh toán'],
          ['ThuKhac', 'Thu khác đã duyệt'],
          ['ChiKhac', 'Chi khác đã duyệt'],
          ['TongThu', 'Tổng thu'],
          ['ChenhLechThuChi', 'Chênh lệch thu – chi (chưa phải lợi nhuận)'],
        ]" :key="key" class="dash-card rounded-xl border bg-white p-5">
          <p class="text-sm text-slate-500">{{ label }}</p>
          <strong class="mt-2 block text-xl">{{ money(finance[key]) }}</strong>
        </article>
        <article class="dash-card rounded-xl border bg-white p-5"><p class="text-sm text-slate-500">Giao dịch thành công</p><strong class="mt-2 block text-xl">{{ finance.GiaoDichThanhCong || 0 }}</strong></article>
      </div>
      <p v-else-if="!loadingMetrics" class="text-sm text-slate-500">Chưa có số liệu tài chính cho bộ lọc đã chọn.</p>
      <p class="text-xs text-slate-500">Số liệu theo API báo cáo tài chính; chưa bao gồm đầy đủ giá vốn, chi phí lương và tất cả chi phí vận hành.</p>
    </div>

    <div v-if="(maySeeInventory && inventory) || (maySeeFinance && finance)" class="dash-block">
      <h2 class="text-lg font-bold">Cần xử lý</h2>
      <div class="dash-grid">
        <article v-if="maySeeInventory && inventory" class="dash-card rounded-xl border bg-white p-5">
          <p class="text-sm text-slate-500">Đơn đặt hàng (PO) chờ duyệt</p>
          <strong class="mt-2 block text-2xl" :class="inventory.POChoDuyet ? 'text-amber-600' : ''">{{ inventory.POChoDuyet || 0 }}</strong>
          <button v-if="canOpen('inventory')" class="mt-3 text-xs font-semibold text-amber-700 hover:underline" @click="emit('navigate', 'inventory')">Mở Kho &amp; mua hàng →</button>
        </article>
        <article v-if="maySeeInventory && inventory" class="dash-card rounded-xl border bg-white p-5">
          <p class="text-sm text-slate-500">Nguyên liệu dưới mức tồn tối thiểu</p>
          <strong class="mt-2 block text-2xl" :class="inventory.SoNguyenLieuCanNhap ? 'text-rose-600' : ''">{{ inventory.SoNguyenLieuCanNhap || 0 }}</strong>
          <button v-if="canOpen('inventory')" class="mt-3 text-xs font-semibold text-amber-700 hover:underline" @click="emit('navigate', 'inventory')">Xem tồn kho →</button>
        </article>
        <article v-if="maySeeFinance && finance" class="dash-card rounded-xl border bg-white p-5">
          <p class="text-sm text-slate-500">Phiếu thu/chi chờ duyệt</p>
          <strong class="mt-2 block text-2xl" :class="finance.PhieuChoDuyet ? 'text-amber-600' : ''">{{ finance.PhieuChoDuyet || 0 }}</strong>
          <button v-if="canOpen('finance')" class="mt-3 text-xs font-semibold text-amber-700 hover:underline" @click="emit('navigate', 'finance')">Mở Tài chính →</button>
        </article>
      </div>
    </div>

    <div v-if="maySeeMenu || maySeeCategories" class="dash-block">
      <h2 class="text-lg font-bold">Thông tin thực đơn</h2>
      <div class="dash-grid">
        <article v-if="maySeeCategories" class="dash-card rounded-xl border bg-white p-5"><p class="text-sm text-slate-500">Danh mục đang hoạt động</p><strong class="mt-2 block text-2xl">{{ activeCategories }}</strong></article>
        <article v-if="maySeeMenu" class="dash-card rounded-xl border bg-white p-5"><p class="text-sm text-slate-500">Món đang có thể bán</p><strong class="mt-2 block text-2xl">{{ availableItems }}</strong></article>
        <article v-if="maySeeMenu" class="dash-card rounded-xl border bg-white p-5"><p class="text-sm text-slate-500">Món chưa có giá tại cơ sở</p><strong class="mt-2 block text-2xl">{{ missingPrices }}</strong></article>
      </div>
    </div>

    <section class="dash-card rounded-xl border bg-white p-5">
      <h2 class="mb-3 text-lg font-bold">Truy cập nhanh</h2>
      <div class="dash-grid">
        <button v-for="item in quickLinks" :key="item.id" class="rounded-lg border p-4 text-left hover:bg-slate-50" @click="emit('navigate', item.id)"><component :is="item.icon" :size="18" :stroke-width="1.9" aria-hidden="true" class="quicklink-icon" /><span class="font-semibold">{{ item.label }}</span></button>
      </div>
      <p v-if="!quickLinks.length" class="text-sm text-slate-500">Tài khoản chưa được cấp thêm chức năng. Liên hệ quản trị viên để phân quyền.</p>
    </section>
  </div>

  </div>
</template>

<style scoped>
.dash-block { display: flex; flex-direction: column; gap: 12px; }
.dash-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 14px;
}
.dash-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 112px;
  padding: 18px 20px;
}
</style>
