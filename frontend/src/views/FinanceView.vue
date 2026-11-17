<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import '../styles/qntd-modules.css'

const props = defineProps({ user: { type: Object, required: true } })
const can = uc => (props.user.Quyen || []).includes('*') || (props.user.Quyen || []).includes(uc)
const isAdmin = computed(() => props.user?.TenVaiTro === 'Quan tri he thong')
const isBoard = computed(() => isAdmin.value || props.user?.TenVaiTro === 'Ban giam doc')
const allTabs = [
  { id: 'overview', label: 'Tổng quan tài chính', uc: 'UC7.5' },
  { id: 'vouchers', label: 'Phiếu thu / chi', uc: 'UC7.1' },
  { id: 'promotions', label: 'Khuyến mãi', uc: 'UC7.3' },
  { id: 'performance', label: 'Hiệu quả ưu đãi', uc: 'UC7.4' },
  { id: 'budgets', label: 'Kế hoạch ngân sách', uc: 'UC7.6' },
  { id: 'reconciliation', label: 'Đối soát ca', uc: 'UC7.2' },
]
const tabs = computed(() => allTabs.filter(t => can(t.uc)))
const tab = ref(tabs.value[0]?.id || 'overview')
const branches = ref([])
const employees = ref([])
const branch = ref(String(props.user.MaCS || ''))
const employee = ref(String(props.user.MaNV || ''))
const currentDate = new Date().toLocaleDateString('sv-SE')
const from = ref(currentDate.slice(0, 7) + '-01')
const to = ref(currentDate)
const rows = ref([])
const stats = ref({})
const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const modal = ref(false)
const editId = ref(null)
const form = ref({})
const busy = ref(false)
const error = ref('')
const notice = ref('')
let requestSequence = 0

const cash = n => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(n || 0))
const labels = { 'Phan tram': 'Phần trăm', 'So tien': 'Số tiền', 'Cho duyet': 'Chờ duyệt', 'Da duyet': 'Đã duyệt', 'Tu choi': 'Từ chối', Nhap: 'Nháp', 'Dang ap dung': 'Đang áp dụng', 'Ket thuc': 'Kết thúc', 'Da huy': 'Đã hủy' }
const label = v => labels[v] || v || '—'
const err = e => Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || e?.message || 'Không thể thực hiện thao tác.'
const columns = computed(() => ({
  vouchers: [['MaPhieuThuChi', 'Mã phiếu'], ['Loai', 'Loại'], ['NgayLap', 'Ngày lập'], ['NoiDung', 'Nội dung'], ['SoTien', 'Số tiền'], ['NguoiLap', 'Người lập'], ['TrangThai', 'Trạng thái']],
  promotions: [['MaKM', 'Mã'], ['TenKM', 'Chương trình'], ['HinhThuc', 'Hình thức'], ['GiaTri', 'Giá trị'], ['NgayBD', 'Bắt đầu'], ['NgayKT', 'Kết thúc'], ['NganSach', 'Ngân sách'], ['TrangThai', 'Trạng thái']],
  performance: [['MaKM', 'Mã'], ['TenKM', 'Chương trình'], ['SoHoaDon', 'Hóa đơn'], ['DoanhThuHoaDonApDung', 'Doanh thu hóa đơn áp dụng'], ['TongDaGiam', 'Đã giảm'], ['NganSach', 'Ngân sách']],
  budgets: [['MaKHNS', 'Mã'], ['TenKH', 'Kế hoạch'], ['KyKeHoach', 'Kỳ'], ['SoTien', 'Ngân sách'], ['TrangThai', 'Trạng thái']],
  reconciliation: [['MaBC', 'Báo cáo'], ['NgayCa', 'Ngày'], ['TenCa', 'Ca'], ['TienMat', 'Tiền mặt'], ['KhongTienMat', 'Không tiền mặt'], ['ChenhLech', 'Chênh lệch'], ['TrangThai', 'Trạng thái']],
})[tab.value] || [])
const fields = computed(() => ({
  vouchers: [['Loai', 'Loại', 'list', ['Thu', 'Chi']], ['NoiDung', 'Nội dung', 'text'], ['SoTien', 'Số tiền', 'number']],
  promotions: [['TenKM', 'Tên chương trình', 'text'], ['HinhThuc', 'Hình thức', 'list', ['Phan tram', 'So tien']], ['GiaTri', 'Giá trị', 'number'], ['NgayBD', 'Bắt đầu', 'date'], ['NgayKT', 'Kết thúc', 'date'], ['NganSach', 'Ngân sách', 'number']],
  budgets: [['TenKH', 'Kế hoạch', 'text'], ['KyKeHoach', 'Kỳ kế hoạch', 'text'], ['SoTien', 'Số tiền', 'number']],
})[tab.value] || [])
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize.value)))
const needsEmployee = computed(() => ['vouchers', 'budgets'].includes(tab.value))
const dailyRevenue = computed(() => stats.value.DoanhThuTheoNgay || [])
const overviewMetrics = computed(() => [
  ['DoanhThuThanhToan', 'Doanh thu đã thu'],
  ['ThuKhac', 'Thu khác đã duyệt'],
  ['ChiKhac', 'Chi phí qua phiếu chi đã duyệt'],
  ['TongThu', 'Tổng thu'],
  ['ChenhLechThuChi', 'Chênh lệch thu – chi'],
  ['GiaoDichThanhCong', 'Giao dịch thành công'],
  ['GiaTriTonKho', 'Giá trị tồn kho hiện tại'],
  ['POChoDuyet', 'PO chờ duyệt'],
  ['KeHoachChoDuyet', 'Kế hoạch ngân sách chờ duyệt'],
].filter(([key]) => !['POChoDuyet','KeHoachChoDuyet'].includes(key) || isBoard.value))

const maxDailyRevenue = computed(() => Math.max(1, ...dailyRevenue.value.map(x => Number(x.DoanhThu || 0))))
const mayCreate = computed(() => ['vouchers', 'promotions', 'budgets'].includes(tab.value))
function format(k, v) {
  if (['SoTien', 'TienMat', 'KhongTienMat', 'ChenhLech', 'TongDaGiam', 'NganSach', 'DoanhThuHoaDonApDung'].includes(k)) return cash(v)
  if (['TrangThai', 'HinhThuc'].includes(k)) return label(v)
  return v ?? '—'
}

async function loadEmployees() {
  if (!needsEmployee.value || !branch.value) return
  if (!isAdmin.value) {
    employees.value = props.user?.MaNV
      ? [{ MaNV: props.user.MaNV, HoTen: props.user.HoTen, MaCS: props.user.MaCS }]
      : []
    employee.value = String(props.user?.MaNV || '')
    return
  }
  try {
    const r = await api.get('/hr/employees', { params: { MaCS: branch.value, per_page: 100 } })
    employees.value = r.data?.data || []
    if (!employees.value.some(x => String(x.MaNV) === employee.value)) {
      employee.value = String(employees.value[0]?.MaNV || '')
    }
  } catch (e) {
    employees.value = []
    error.value = 'Không tải được nhân viên: ' + err(e)
  }
}
async function load() {
  if (!branch.value || !tabs.value.some(x => x.id === tab.value)) return
  const id = ++requestSequence
  busy.value = true
  error.value = ''
  try {
    if (tab.value === 'overview') {
      const { data } = await api.get('/finance/dashboard', { params: { MaCS: branch.value, from: from.value, to: to.value } })
      if (id === requestSequence) { stats.value = data; rows.value = []; total.value = 0 }
    } else {
      const endpoints = {
        vouchers: '/finance/vouchers', promotions: '/finance/promotions', budgets: '/finance/budgets',
        performance: '/finance/promotion-performance', reconciliation: '/finance/reconciliation',
      }
      const params = { page: page.value, per_page: pageSize.value, MaCS: branch.value }
      if (['reconciliation', 'vouchers'].includes(tab.value)) { params.from = from.value; params.to = to.value }
      const { data } = await api.get(endpoints[tab.value], { params })
      if (id === requestSequence) {
        rows.value = Array.isArray(data) ? data.slice((page.value - 1) * pageSize.value, page.value * pageSize.value) : data?.data || []
        total.value = Array.isArray(data) ? data.length : data?.total || 0
      }
    }
  } catch (e) {
    if (id === requestSequence) { error.value = err(e); rows.value = []; total.value = 0 }
  } finally {
    if (id === requestSequence) busy.value = false
  }
}
async function selectTab(value) {
  if (!tabs.value.some(t => t.id === value)) return
  tab.value = value
  page.value = 1
  await Promise.all([loadEmployees(), load()])
}
async function selectBranch() {
  page.value = 1
  await Promise.all([loadEmployees(), load()])
}
async function init() {
  try {
    const { data } = await api.get('/branches')
    const allBranches = Array.isArray(data) ? data : data?.data || []
    branches.value = isAdmin.value ? allBranches : allBranches.filter(x => Number(x.MaCS) === Number(props.user?.MaCS))
    if (!branches.value.some(x => String(x.MaCS) === branch.value)) branch.value = String(branches.value[0]?.MaCS || '')
    // Chỉ tải một lần khi mở màn hình, không sử dụng watcher gây request lặp.
    await Promise.all([loadEmployees(), load()])
  } catch (e) { error.value = err(e) }
}
function open(row = null) {
  editId.value = tab.value === 'promotions' ? row?.MaKM || null : null
  form.value = Object.fromEntries(fields.value.map(([key, , kind, options]) => [key, row?.[key] ?? (kind === 'list' ? options[0] : '')]))
  if (!row && tab.value === 'promotions') { form.value.NgayBD = from.value; form.value.NgayKT = to.value; form.value.NganSach = 0 }
  if (!row && tab.value === 'budgets') form.value.KyKeHoach = currentDate.slice(0, 7)
  modal.value = true
}
async function save() {
  busy.value = true
  error.value = ''; notice.value = ''
  try {
    const endpoint = { vouchers: '/finance/vouchers', promotions: '/finance/promotions', budgets: '/finance/budgets' }[tab.value]
    const payload = { ...form.value }
    if (tab.value === 'vouchers') Object.assign(payload, { MaCS: Number(branch.value), MaNV: Number(employee.value) })
    if (tab.value === 'budgets') Object.assign(payload, { MaCS: Number(branch.value), MaNVLap: Number(employee.value) })
    if (tab.value === 'promotions') payload.MaNV = employee.value ? Number(employee.value) : null
    if (editId.value) await api.put(`${endpoint}/${editId.value}`, payload)
    else await api.post(endpoint, payload)
    modal.value = false
    notice.value = 'Đã lưu dữ liệu.'
    await load()
  } catch (e) { error.value = err(e) } finally { busy.value = false }
}
async function transition(row, state) {
  if (!confirm('Xác nhận thay đổi trạng thái?')) return
  busy.value = true
  error.value = ''; notice.value = ''
  try {
    if (tab.value === 'vouchers') await api.post(`/finance/vouchers/${row.MaPhieuThuChi}/review`, { TrangThai: state, MaNVDuyet: Number(employee.value) })
    if (tab.value === 'promotions') await api.post(`/finance/promotions/${row.MaKM}/status`, { TrangThai: state })
    if (tab.value === 'budgets') await api.post(`/finance/budgets/${row.MaKHNS}/status`, { TrangThai: state, MaNVDuyet: state === 'Cho duyet' ? null : Number(employee.value) })
    notice.value = 'Đã cập nhật trạng thái.'
    await load()
  } catch (e) { error.value = err(e) } finally { busy.value = false }
}
function changePage(next) { page.value = next; void load() }
function changeSize() { page.value = 1; void load() }
onMounted(init)
</script>

<template>
  <div class="ui-view ui-view--finance">
  <div class="qntd-module space-y-5 text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-3"><div>
      <p class="text-xs font-bold tracking-wider text-amber-600">PHÂN HỆ KẾ TOÁN VÀ MARKETING</p>
      <h1 class="mt-1 text-2xl font-bold">Kế toán, khuyến mãi và báo cáo</h1>
      <p class="mt-1 text-sm text-slate-500">Tải đúng dữ liệu theo quyền và cơ sở, tránh request dư thừa.</p>
    </div><button class="rounded-lg border bg-white px-4 py-2 text-sm" :disabled="busy" @click="load">↻ Làm mới</button></header>
    <div v-if="error" role="alert" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</div>
    <div v-if="notice" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ notice }}</div>
    <div class="flex flex-wrap gap-2 rounded-xl border bg-white p-2">
      <button v-for="t in tabs" :key="t.id" class="rounded-lg px-3 py-2 text-xs font-semibold" :class="tab === t.id ? 'bg-slate-800 text-white' : 'hover:bg-slate-100'" @click="selectTab(t.id)">{{ t.label }}</button>
    </div>
    <section class="rounded-xl border bg-white p-4">
      <div class="mb-4 flex flex-wrap items-end gap-3">
        <label class="min-w-48 flex-1 text-xs font-semibold">Cơ sở<select v-model="branch" :disabled="!isAdmin" class="mt-1 w-full rounded-lg border p-2" @change="selectBranch"><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></label>
        <label v-if="needsEmployee && isAdmin" class="min-w-48 flex-1 text-xs font-semibold">Nhân viên thực hiện<select v-model="employee" class="mt-1 w-full rounded-lg border p-2"><option v-for="v in employees" :key="v.MaNV" :value="String(v.MaNV)">{{ v.HoTen }}</option></select></label>
        <div v-else-if="needsEmployee" class="min-w-48 flex-1 text-xs font-semibold">Nhân viên thực hiện<p class="mt-1 rounded-lg border bg-slate-50 p-2">{{ props.user?.HoTen || 'Chưa gán nhân viên' }}</p></div>
        <label v-if="['overview', 'vouchers', 'reconciliation'].includes(tab)" class="text-xs font-semibold">Từ ngày<input v-model="from" type="date" class="mt-1 rounded-lg border p-2" /></label>
        <label v-if="['overview', 'vouchers', 'reconciliation'].includes(tab)" class="text-xs font-semibold">Đến ngày<input v-model="to" type="date" class="mt-1 rounded-lg border p-2" /></label>
        <button class="rounded-lg border px-3 py-2 text-sm" @click="page = 1; load()">Lọc</button>
        <button v-if="mayCreate" class="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white" @click="open()">+ Tạo mới</button>
      </div>
      <div v-if="tab === 'overview'" class="space-y-5">
        <div class="grid gap-3 md:grid-cols-3">
          <div v-for="[key, title] in overviewMetrics" :key="key" class="rounded-xl bg-slate-50 p-5">
            <p class="text-sm text-slate-500">{{ title }}</p>
            <strong class="mt-2 block text-2xl">{{ ['GiaoDichThanhCong','POChoDuyet','KeHoachChoDuyet'].includes(key) ? (stats[key] || 0) : cash(stats[key]) }}</strong>
          </div>
          <p class="text-xs text-slate-500 md:col-span-3">Chênh lệch thu – chi chưa phải lợi nhuận vì chưa trừ đầy đủ giá vốn, lương và chi phí vận hành. Giá trị tồn kho tính theo số lượng còn lại của các lô nhập đã xác nhận.</p>
        </div>

        <section class="rounded-xl border p-4">
          <h3 class="font-bold">Doanh thu theo ngày</h3>
          <p class="mt-1 text-xs text-slate-500">Tổng giao dịch thanh toán thành công trong khoảng thời gian đã chọn</p>
          <div v-if="dailyRevenue.length" class="mt-4 flex min-h-48 items-end gap-2 overflow-x-auto border-b border-slate-200 pb-2">
            <div v-for="day in dailyRevenue" :key="day.Ngay" class="flex min-w-12 flex-1 flex-col items-center justify-end gap-2">
              <span class="text-[10px] text-slate-500">{{ cash(day.DoanhThu) }}</span>
              <div class="w-full max-w-12 rounded-t bg-emerald-600" :style="{ height: Math.max(8, Number(day.DoanhThu || 0) / maxDailyRevenue * 130) + 'px' }"></div>
              <span class="text-[10px] text-slate-500">{{ String(day.Ngay).slice(5) }}</span>
            </div>
          </div>
          <p v-else class="mt-4 text-sm text-slate-500">Chưa có giao dịch thành công trong khoảng thời gian này.</p>
        </section>

        <section v-if="isBoard" class="grid gap-4 lg:grid-cols-2">
          <div class="rounded-xl border p-4">
            <h3 class="font-bold">Đơn mua hàng chờ Ban Giám đốc duyệt</h3>
            <div v-for="po in stats.POCanDuyet || []" :key="po.MaPO" class="flex justify-between gap-3 border-b py-2 text-sm">
              <span>PO #{{ po.MaPO }} · {{ po.TenNCC }} · {{ po.NguoiLap }}</span><span class="text-xs text-amber-700">Chờ duyệt tại phân hệ Kho</span>
            </div>
            <p v-if="!(stats.POCanDuyet || []).length" class="pt-3 text-sm text-slate-500">Không có PO chờ duyệt.</p>
          </div>
          <div class="rounded-xl border p-4">
            <h3 class="font-bold">Kế hoạch ngân sách chờ duyệt</h3>
            <div v-for="budget in stats.KeHoachCanDuyet || []" :key="budget.MaKHNS" class="flex justify-between gap-3 border-b py-2 text-sm">
              <span>{{ budget.TenKH }} · {{ budget.KyKeHoach }} · {{ cash(budget.SoTien) }}</span><button class="text-xs font-semibold text-amber-700" @click="selectTab('budgets')">Mở duyệt →</button>
            </div>
            <p v-if="!(stats.KeHoachCanDuyet || []).length" class="pt-3 text-sm text-slate-500">Không có kế hoạch chờ duyệt.</p>
          </div>
        </section>
      </div>
      <template v-else>
        <div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-xs"><thead class="bg-slate-50"><tr><th v-for="[key, name] in columns" :key="key" class="p-3">{{ name }}</th><th class="p-3">Thao tác</th></tr></thead>
          <tbody><tr v-for="row in rows" :key="row.MaPhieuThuChi || row.MaKM || row.MaKHNS || row.MaBC" class="border-t">
            <td v-for="[key] in columns" :key="key" class="p-3">{{ format(key, row[key]) }}</td><td class="min-w-44 p-3"><div class="flex flex-wrap gap-1">
              <template v-if="tab === 'vouchers' && row.TrangThai === 'Cho duyet'"><button class="rounded border px-2 py-1" @click="transition(row, 'Da duyet')">Duyệt</button><button class="rounded border px-2 py-1" @click="transition(row, 'Tu choi')">Từ chối</button></template>
              <template v-if="tab === 'promotions'"><button v-if="row.TrangThai === 'Nhap'" class="rounded border px-2 py-1" @click="open(row)">Sửa</button><button v-if="row.TrangThai === 'Nhap'" class="rounded border px-2 py-1" @click="transition(row, 'Cho duyet')">Trình</button><button v-if="row.TrangThai === 'Cho duyet'" class="rounded border px-2 py-1" @click="transition(row, 'Dang ap dung')">Duyệt</button><button v-if="row.TrangThai === 'Dang ap dung'" class="rounded border px-2 py-1" @click="transition(row, 'Ket thuc')">Kết thúc</button></template>
              <template v-if="tab === 'budgets'"><button v-if="row.TrangThai === 'Nhap'" class="rounded border px-2 py-1" @click="transition(row, 'Cho duyet')">Trình</button><button v-if="row.TrangThai === 'Cho duyet'" class="rounded border px-2 py-1" @click="transition(row, 'Da duyet')">Duyệt</button><button v-if="row.TrangThai === 'Cho duyet'" class="rounded border px-2 py-1" @click="transition(row, 'Tu choi')">Từ chối</button></template>
            </div></td></tr><tr v-if="!rows.length"><td :colspan="columns.length + 1" class="p-8 text-center text-slate-500">{{ busy ? 'Đang tải...' : 'Không có dữ liệu.' }}</td></tr></tbody>
        </table></div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs"><span>Hiển thị {{ total ? (page - 1) * pageSize + 1 : 0 }}–{{ Math.min(page * pageSize, total) }} trong {{ total }} kết quả</span>
          <div class="flex items-center gap-2"><select v-model.number="pageSize" class="w-auto rounded border p-2" @change="changeSize"><option :value="10">10 dòng</option><option :value="20">20 dòng</option><option :value="50">50 dòng</option></select><button :disabled="page <= 1" class="rounded border px-3 py-2 disabled:opacity-30" @click="changePage(page - 1)">‹</button><span>{{ page }}/{{ pageCount }}</span><button :disabled="page >= pageCount" class="rounded border px-3 py-2 disabled:opacity-30" @click="changePage(page + 1)">›</button></div>
        </div>
      </template>
    </section>
    <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="modal = false">
      <form class="w-full max-w-xl space-y-3 rounded-xl bg-white p-5 shadow-xl" @submit.prevent="save"><div class="flex justify-between"><h2 class="text-lg font-bold">{{ editId ? 'Cập nhật' : 'Tạo mới' }} · {{ tabs.find(t => t.id === tab)?.label }}</h2><button type="button" @click="modal = false">✕</button></div>
        <label v-for="[key, title, kind, options] in fields" :key="key" class="block text-xs font-semibold">{{ title }}<select v-if="kind === 'list'" v-model="form[key]" class="mt-1 w-full rounded border p-2"><option v-for="o in options" :key="o" :value="o">{{ o }}</option></select><input v-else v-model="form[key]" :type="kind" :step="kind === 'number' ? '0.01' : undefined" class="mt-1 w-full rounded border p-2" /></label>
        <div class="flex justify-end gap-2"><button type="button" class="rounded border px-4 py-2" @click="modal = false">Hủy</button><button :disabled="busy" type="submit" class="rounded bg-slate-800 px-4 py-2 text-white">Lưu</button></div>
      </form>
    </div>
  </div>

  </div>
</template>
