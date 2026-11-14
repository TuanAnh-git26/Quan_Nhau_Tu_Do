<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'
import '../styles/qntd-modules.css'
import PaginationBar from '../components/PaginationBar.vue'

const tabs = [
  { id: 'employees', name: 'Hồ sơ nhân viên' },
  { id: 'contracts', name: 'Hợp đồng' }, { id: 'shifts', name: 'Ca làm' },
  { id: 'attendance', name: 'Chấm công' }, { id: 'payrolls', name: 'Bảng lương' }
]
const tab = ref('employees'), branches = ref([]), employees = ref([]), positions = ref([]), shifts = ref([])
const branch = ref(''), month = ref(new Date().toLocaleDateString('sv-SE').slice(0, 7)), q = ref('')
const contextError = ref('')
const rows = ref([]), total = ref(0), page = ref(1), pageSize = ref(10), dashboard = ref({})
const busy = ref(false), error = ref(''), success = ref(''), editing = ref(false), form = ref({})
const attendanceTotals = ref({})
const payrollWarnings = ref([])
const tabName = computed(() => tabs.find(x => x.id === tab.value)?.name || 'Chức danh / vị trí')
const money = value => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(value || 0))
const status = value => ({ 'Dang lam':'Đang làm', 'Tam nghi':'Tạm nghỉ', 'Da nghi':'Đã nghỉ', 'Nhap':'Nháp', 'Cho ke toan':'Chờ kế toán', 'Can dieu chinh':'Cần điều chỉnh', 'Da chot':'Đã chốt', 'Hieu luc':'Hiệu lực', 'Het han':'Hết hạn', 'Da cham dut':'Đã chấm dứt', 'Dang hoat dong':'Đang hoạt động', 'Ngung hoat dong':'Ngừng hoạt động' })[value] || value || '—'
const columns = computed(() => ({
  employees: [['MaNV','Mã NV'], ['HoTen','Họ tên'], ['TenCS','Cơ sở'], ['TenVT','Vị trí'], ['SDT','Điện thoại'], ['TrangThai','Trạng thái']],
  positions: [['MaVT','Mã'], ['TenVT','Vị trí'], ['MoTa','Mô tả'], ['SoNhanVien','Nhân viên']],
  contracts: [['MaHDLD','Mã HĐ'], ['HoTen','Nhân viên'], ['LoaiHD','Loại hợp đồng'], ['NgayBD','Bắt đầu'], ['NgayKT','Kết thúc'], ['LuongCoBan','Lương cơ bản'], ['TrangThai','Trạng thái']],
  shifts: [['MaCa','Mã ca'], ['TenCa','Tên ca'], ['TenCS','Cơ sở'], ['GioVao','Giờ vào'], ['GioRa','Giờ ra'], ['TrangThai','Trạng thái']],
  attendance: [['MaCC','Mã CC'], ['HoTen','Nhân viên'], ['Ngay','Ngày'], ['TenCa','Ca'], ['GioVao','Check-in'], ['GioRa','Check-out'], ['TangCa','Tăng ca (giờ)'], ['LoaiNghi','Loại nghỉ']],
  payrolls: [['MaBL','Mã lương'], ['HoTen','Nhân viên'], ['KyLuong','Kỳ'], ['NgayCong','Ngày công'], ['GioTangCa','Tăng ca (giờ)'], ['LuongCoBan','Lương cơ bản'], ['PhuCap','Phụ cấp'], ['Thuong','Thưởng'], ['KhauTru','Khấu trừ'], ['ThucLinh','Thực lĩnh'], ['TrangThai','Trạng thái']]
})[tab.value])
const formFields = computed(() => ({
  employees: [['HoTen','Họ tên','text'], ['NgaySinh','Ngày sinh','date'], ['SDT','Số điện thoại','text'], ['Email','Email','email'], ['NgayVaoLam','Ngày vào làm','date'], ['MaCS','Cơ sở','branch'], ['MaVT','Vị trí','position']],
  positions: [['TenVT','Tên vị trí','text'], ['MoTa','Mô tả','text']],
  contracts: [['MaNV','Nhân viên','employee'], ['LoaiHD','Loại hợp đồng','text'], ['NgayBD','Ngày bắt đầu','date'], ['NgayKT','Ngày kết thúc','date'], ['LuongCoBan','Lương cơ bản','number']],
  shifts: [['TenCa','Tên ca','text'], ['GioVao','Giờ vào','time'], ['GioRa','Giờ ra','time'], ['MaCS','Cơ sở','branch']],
  attendance: [['MaNV','Nhân viên','employee'], ['MaCa','Ca làm','shift'], ['Ngay','Ngày làm','date'], ['LoaiNghi','Loại nghỉ (để trống nếu đi làm)','text']],
  payrolls: [['PhuCap','Phụ cấp','number'], ['Thuong','Thưởng','number'], ['KhauTru','Khấu trừ','number']]
})[tab.value])
function result(value) {
  const wrapper = value?.data ?? value
  const rows = Array.isArray(wrapper) ? wrapper :
    Array.isArray(wrapper?.data) ? wrapper.data :
    Array.isArray(wrapper?.data?.data) ? wrapper.data.data : []
  const total = wrapper?.total ?? wrapper?.data?.total ?? rows.length
  return { data: rows, total: Number(total) || 0 }
}
const err = e => Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || e?.message || 'Không thể thực hiện thao tác.'
const format = (key, value) => ['LuongCoBan','PhuCap','Thuong','KhauTru','ThucLinh'].includes(key) ? money(value) : (key === 'TrangThai' ? status(value) : (value ?? '—'))
function cellText(key, row) {
  if (key === 'NgayCong') return attendanceTotals.value[row.MaNV]?.days ?? '—'
  if (key === 'GioTangCa') return attendanceTotals.value[row.MaNV]?.overtime ?? '—'
  return format(key, row[key])
}
async function getPayrollAttendance() {
  attendanceTotals.value = {}
  if (tab.value !== 'payrolls' || !branch.value) return
  const [year, mm] = month.value.split('-').map(Number)
  const start = `${year}-${String(mm).padStart(2, '0')}-01`
  const end = `${year}-${String(mm).padStart(2, '0')}-${String(new Date(year, mm, 0).getDate()).padStart(2, '0')}`
  const result = {}
  // API trả dữ liệu phân trang: không lấy riêng trang đầu rồi báo ngày công sai.
  for (let p = 1; p <= 30; p++) {
    const { data } = await api.get('/hr/attendance', {
      params: { MaCS: branch.value, TuNgay: start, DenNgay: end, page: p, per_page: 100 }
    })
    for (const x of data.data || []) {
      const id = x.MaNV
      result[id] ||= { days: 0, overtime: 0 }
      if (x.GioVao && x.GioRa) result[id].days++
      result[id].overtime += Number(x.TangCa || 0)
    }
    if (p >= Number(data.last_page || 1)) break
    if (p === 30) {
      payrollWarnings.value.push('Có hơn 3.000 lượt chấm công; chỉ mới tổng hợp tối đa 30 trang. Vui lòng thu hẹp bộ lọc.')
    }
  }
  for (const x of Object.values(result)) x.overtime = Math.round(x.overtime * 100) / 100
  attendanceTotals.value = result
}
function emptyForm() {
  return tab.value === 'payrolls' ? { PhuCap: 0, Thuong: 0, KhauTru: 0 } :
    Object.fromEntries(formFields.value.map(([key]) => [key, ['MaCS','MaNV','MaCa','MaVT'].includes(key) ? '' : '']))
}
async function base() {
  contextError.value = ''
  try {
    const [branchResult, meResult] = await Promise.allSettled([
      api.get('/branches'),
      api.get('/auth/me')
    ])
    if (branchResult.status !== 'fulfilled') {
      contextError.value = 'Không lấy được danh sách cơ sở: ' + err(branchResult.reason)
      return
    }
    branches.value = result(branchResult.value.data).data
    const user = meResult.status === 'fulfilled'
      ? (meResult.value.data?.data ?? meResult.value.data) : {}
    if (!branch.value && user?.MaCS && branches.value.some(x => Number(x.MaCS) === Number(user.MaCS))) {
      branch.value = String(user.MaCS)
    }
    if (!branch.value && branches.value.length) branch.value = String(branches.value[0].MaCS)
    if (!branches.value.length) contextError.value = 'Không có cơ sở đang hoạt động hoặc cơ sở chưa được phân quyền.'
    const params = branch.value ? { MaCS: branch.value } : {}
    const [positionsResult, employeesResult, shiftsResult] = await Promise.allSettled([
      api.get('/hr/positions'),
      api.get('/hr/employees', { params: { ...params, per_page: 100 } }),
      api.get('/hr/shifts', { params })
    ])
    if (positionsResult.status === 'fulfilled') positions.value = result(positionsResult.value.data).data
    else contextError.value += ' Không tải được vị trí: ' + err(positionsResult.reason)
    if (employeesResult.status === 'fulfilled') employees.value = result(employeesResult.value.data).data
    else contextError.value += ' Không tải được nhân viên: ' + err(employeesResult.reason)
    if (shiftsResult.status === 'fulfilled') shifts.value = result(shiftsResult.value.data).data
    else contextError.value += ' Không tải được ca làm: ' + err(shiftsResult.reason)
  } catch (e) { contextError.value = 'Lỗi dữ liệu nền: ' + err(e) }
}
async function load() {
  busy.value = true
  error.value = ''
  payrollWarnings.value = []
  try {
    const params = { page: page.value, per_page: pageSize.value }
    if (branch.value && tab.value !== 'positions') params.MaCS = branch.value
    if (q.value && ['employees','contracts','payrolls'].includes(tab.value)) params.q = q.value
    if (tab.value === 'payrolls') params.KyLuong = month.value + '-01'
    const [listResult, dashboardResult] = await Promise.allSettled([
      api.get('/hr/' + tab.value, { params }),
      api.get('/hr/dashboard', { params: { MaCS: branch.value || undefined } })
    ])
    if (listResult.status === 'fulfilled') {
      const payload = listResult.value.data
      const paged = result(payload)
      rows.value = Array.isArray(payload)
        ? paged.data.slice((page.value - 1) * pageSize.value, page.value * pageSize.value)
        : paged.data
      total.value = paged.total
    } else {
      rows.value = []; total.value = 0
      error.value = 'Không tải được ' + tabName.value + ': ' + err(listResult.reason)
    }
    if (dashboardResult.status === 'fulfilled') dashboard.value = dashboardResult.value.data?.data ?? dashboardResult.value.data
    else error.value += (error.value ? ' | ' : '') + 'Không tải được tổng quan: ' + err(dashboardResult.reason)
    if (tab.value === 'payrolls' && listResult.status === 'fulfilled') {
      try { await getPayrollAttendance() }
      catch (e) { payrollWarnings.value.push('Không tổng hợp được chấm công: ' + err(e)) }
    }
  } catch (e) {
    error.value = err(e); rows.value = []; total.value = 0
  } finally { busy.value = false }
}

function open(row = null) {
  editing.value = true
  form.value = row ? Object.fromEntries(formFields.value.map(([key,type,kind]) => {
    let v = row[key] ?? ''
    if (kind === 'time') v = String(v).slice(0,5)
    if (kind === 'date') v = String(v).slice(0,10)
    return [key,v]
  })) : emptyForm()
  if (!row && tab.value === 'employees') form.value.MaCS = branch.value
  if (!row && tab.value === 'shifts') form.value.MaCS = branch.value
  if (!row && tab.value === 'attendance') form.value.Ngay = new Date().toLocaleDateString('sv-SE')
  if (row) form.value.id = row[{ employees:'MaNV', positions:'MaVT', contracts:'MaHDLD', shifts:'MaCa', attendance:'MaCC', payrolls:'MaBL' }[tab.value]]
}
async function save() {
  busy.value = true; error.value = ''; success.value = ''
  try {
    const baseUrl = '/hr/' + tab.value
    const payload = { ...form.value }; delete payload.id
    for (const k of ['MaVT','NgaySinh','NgayVaoLam','NgayKT','LoaiNghi']) if (payload[k] === '') payload[k] = null
    if (tab.value === 'contracts' && form.value.id) {
      const original = rows.value.find(row => Number(row.MaHDLD) === Number(form.value.id))
      if (original) payload.TrangThai = original.TrangThai
    }
    if (tab.value === 'contracts' && payload.MaNV === undefined) throw new Error('Thiếu mã nhân viên.')
    if (form.value.id) {
      await (tab.value === 'attendance' ? api.patch(`${baseUrl}/${form.value.id}`, payload) : api.put(`${baseUrl}/${form.value.id}`, payload))
    } else {
      if (tab.value === 'payrolls') throw new Error('Vui lòng tạo bảng lương theo kỳ.')
      await api.post(baseUrl, payload)
    }
    editing.value = false; success.value = 'Đã lưu dữ liệu thành công.'; await base(); await load()
  } catch(e) { error.value = err(e) || e.message } finally { busy.value = false }
}
const inputModal = ref({ open: false, row: null, kind: '', value: '', error: '' })
function openInput(row, kind) {
  inputModal.value = { open: true, row, kind, value: kind === 'status' ? (row.TrangThai || 'Dang lam') : '', error: '' }
}
async function submitInput() {
  if (busy.value) return
  const m = inputModal.value
  const value = String(m.value || '').trim()
  if (m.kind === 'return' && !value) { m.error = 'Vui lòng nhập lý do trả lại.'; return }
  if (m.kind === 'return' && value.length > 500) { m.error = 'Lý do tối đa 500 ký tự.'; return }
  if (m.kind === 'status' && !['Dang lam', 'Tam nghi', 'Da nghi'].includes(value)) { m.error = 'Trạng thái không hợp lệ.'; return }
  const row = m.row, kind = m.kind
  inputModal.value = { open: false, row: null, kind: '', value: '', error: '' }
  await action(row, kind, value)
}
async function action(row, actionName, inputValue) {
  const ids = { employees:'MaNV', positions:'MaVT', contracts:'MaHDLD', shifts:'MaCa', attendance:'MaCC', payrolls:'MaBL' }
  const id = row[ids[tab.value]]; let url = `/hr/${tab.value}/${id}`; let payload = {}
  if (busy.value) return
  if ((actionName === 'status' || actionName === 'return') && inputValue === undefined) { openInput(row, actionName); return }
  if (actionName === 'status') { payload.TrangThai = inputValue; url += '/status' }
  else if (actionName === 'return') { payload.LyDo = inputValue }
  else if (!confirm('Xác nhận thao tác này?')) return
  busy.value = true; error.value = ''
  try {
    if (actionName === 'delete') await api.delete(url)
    else if (actionName === 'status') await api.patch(url, payload)
    else await api.post(url + '/' + ({ end:'end', disable:'disable', in:'clock-in', out:'clock-out', submit:'submit', return:'return', approve:'approve' })[actionName], payload)
    success.value = 'Thao tác thành công.'; await load()
  } catch(e) { error.value = err(e) } finally { busy.value = false }
}
async function generatePayroll() {
  if (!confirm('Tạo bảng lương nháp cho các nhân viên trong kỳ đã chọn?')) return
  busy.value = true; error.value = ''
  try {
    const { data } = await api.post('/hr/payrolls/generate', { KyLuong: month.value + '-01', MaCS: branch.value || null })
    success.value = `Đã tạo ${data.SoLuongTaoMoi} bảng lương; bỏ qua ${data.SoLuongBoQua}.`; payrollWarnings.value = (data.DanhSachBoQua || []).map(x => `${x.HoTen}: ${x.LyDo}`); await load()
  } catch(e) { error.value = err(e) } finally { busy.value = false }
}
watch([tab, branch, month, pageSize], async () => {
  editing.value = false
  if (page.value !== 1) page.value = 1
  else await load()
})
watch(page, load)
onMounted(async () => { await base(); await load() })
</script>

<template>
  <div class="qntd-module space-y-5 text-slate-800">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><p class="text-xs font-bold tracking-widest text-amber-600">PHÂN HỆ NHÂN SỰ </p><h1 class="mt-1 text-2xl font-bold">Nhân sự, chấm công và lương</h1></div>
      <button class="rounded-lg border bg-white px-4 py-2 text-sm" @click="base().then(load)">↻ Làm mới</button>
    </header>
    <div v-if="contextError" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-700">{{ contextError }}</div>
    <div v-if="error" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</div>
    <div v-if="success" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ success }}</div>
    <p v-for="warning in payrollWarnings" :key="warning" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-700">{{ warning }}</p>
    <div class="grid gap-3 md:grid-cols-4">
      <div v-for="[key,label] in [['TongNhanVien','Tổng nhân viên'], ['DangLam','Đang làm'], ['DaCheckIn','Đã check-in hôm nay'], ['BangLuongChoKeToan','Lương chờ kế toán']]" :key="key" class="rounded-xl border bg-white p-4">
        <p class="text-sm text-slate-500">{{ label }}</p><strong class="mt-1 block text-2xl">{{ dashboard[key] ?? '—' }}</strong>
      </div>
    </div>
    <div class="flex flex-wrap gap-2 rounded-xl border bg-white p-2">
      <button v-for="t in tabs" :key="t.id" @click="tab=t.id" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="tab===t.id ? 'bg-slate-800 text-white' : 'hover:bg-slate-100'">{{ t.name }}</button>
    </div>
    <section class="rounded-xl border bg-white p-4">
      <div class="mb-4 flex flex-wrap gap-2" v-if="tab === 'employees' || tab === 'positions'">
        <button v-if="tab === 'employees'" class="rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800" @click="tab = 'positions'">Quản lý chức danh / vị trí</button>
        <button v-else class="rounded-lg border px-3 py-2 text-xs font-semibold" @click="tab = 'employees'">← Quay lại nhân viên</button>
      </div>
      <div class="mb-4 flex flex-wrap items-end gap-3">
        <label v-if="tab!=='positions'" class="min-w-48 flex-1 text-xs font-semibold">Cơ sở
          <select v-model="branch" class="mt-1 w-full rounded-lg border p-2"><option value="">Toàn hệ thống</option><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select>
        </label>
        <label v-if="['employees','contracts','payrolls'].includes(tab)" class="min-w-44 flex-1 text-xs font-semibold">Tìm kiếm
          <input v-model="q" @keyup.enter="page=1;load()" class="mt-1 w-full rounded-lg border p-2" placeholder="Tên hoặc mã..." />
        </label>
        <label v-if="tab==='payrolls'" class="text-xs font-semibold">Kỳ lương<input v-model="month" type="month" class="mt-1 rounded-lg border p-2" /></label>
        <button class="rounded-lg border px-4 py-2 text-sm" @click="page=1;load()">Lọc</button>
        <button v-if="tab==='payrolls'" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white" @click="generatePayroll">Tính bảng lương</button>
        <button v-else class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white" @click="open()">+ Thêm mới</button>
      </div>
      <div class="overflow-x-auto"><table class="w-full min-w-[820px] border-collapse text-left text-xs">
        <thead class="bg-slate-50"><tr><th v-for="[key,label] in columns" :key="key" class="p-3">{{ label }}</th><th class="p-3">Thao tác</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="JSON.stringify(row)" class="border-t"><td v-for="[key] in columns" :key="key" class="p-3">{{ cellText(key,row) }}</td>
          <td class="min-w-[175px] p-3"><div class="flex flex-wrap gap-1">
            <button v-if="tab!=='payrolls'" class="rounded border px-2 py-1" @click="open(row)">Sửa</button>
            <button v-if="tab==='employees'" class="rounded border px-2 py-1" @click="action(row,'status')">Trạng thái</button>
            <button v-if="tab==='positions'" class="rounded border px-2 py-1 text-rose-600" @click="action(row,'delete')">Xóa</button>
            <button v-if="tab==='contracts' && row.TrangThai==='Hieu luc'" class="rounded border px-2 py-1" @click="action(row,'end')">Kết thúc</button>
            <button v-if="tab==='shifts'" class="rounded border px-2 py-1" @click="action(row,'disable')">Ngừng ca</button>
            <template v-if="tab==='attendance'"><button v-if="!row.GioVao && !row.LoaiNghi" class="rounded border px-2 py-1" @click="action(row,'in')">Vào ca</button><button v-if="row.GioVao && !row.GioRa" class="rounded border px-2 py-1" @click="action(row,'out')">Ra ca</button></template>
            <template v-if="tab==='payrolls'"><button v-if="['Nhap','Can dieu chinh'].includes(row.TrangThai)" class="rounded border px-2 py-1" @click="open(row)">Sửa tiền</button><button v-if="['Nhap','Can dieu chinh'].includes(row.TrangThai)" class="rounded border px-2 py-1" @click="action(row,'submit')">Trình</button><button v-if="row.TrangThai==='Cho ke toan'" class="rounded border px-2 py-1" @click="action(row,'return')">Trả lại</button><button v-if="row.TrangThai==='Cho ke toan'" class="rounded border px-2 py-1" @click="action(row,'approve')">Chốt</button></template>
          </div></td>
        </tr><tr v-if="!rows.length"><td :colspan="columns.length+1" class="p-8 text-center text-slate-500">{{ busy ? 'Đang tải...' : 'Không có dữ liệu.' }}</td></tr></tbody>
      </table></div>
      <PaginationBar v-if="tab !== 'contracts'" v-model:page="page" v-model:page-size="pageSize" :total="total" />
      <div v-else class="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500">
        <span>Hợp đồng phân trang cố định 15 dòng/trang theo API hiện tại · tổng {{ total }} kết quả</span>
        <div class="flex items-center gap-2">
          <button :disabled="page <= 1" class="rounded border px-3 py-2 disabled:opacity-30" @click="page--">Trước</button>
          <span>{{ page }}/{{ Math.max(1, Math.ceil(total / 15)) }}</span>
          <button :disabled="page >= Math.ceil(total / 15)" class="rounded border px-3 py-2 disabled:opacity-30" @click="page++">Sau</button>
        </div>
      </div>
    </section>
    <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="editing=false">
      <form @submit.prevent="save" class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-5 shadow-2xl">
        <div class="mb-4 flex items-center justify-between"><h2 class="text-lg font-bold">{{ form.id ? 'Cập nhật' : 'Thêm mới' }} · {{ tabName }}</h2><button type="button" @click="editing=false">✕</button></div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label v-for="[key,label,kind] in formFields" :key="key" class="text-xs font-semibold">{{ label }}
            <select v-if="['branch','employee','position','shift'].includes(kind)" v-model="form[key]" class="mt-1 w-full rounded-lg border p-2">
              <option value="">Chọn...</option><option v-for="v in kind==='branch'?branches:kind==='employee'?employees:kind==='position'?positions:shifts" :key="v.MaCS||v.MaNV||v.MaVT||v.MaCa" :value="String(v[{branch:'MaCS',employee:'MaNV',position:'MaVT',shift:'MaCa'}[kind]])">{{ v[{branch:'TenCS',employee:'HoTen',position:'TenVT',shift:'TenCa'}[kind]] }}</option>
            </select>
            <input v-else v-model="form[key]" :type="kind" :step="kind==='number'?'0.01':undefined" class="mt-1 w-full rounded-lg border p-2" />
          </label>
        </div><div class="mt-5 flex justify-end gap-2"><button type="button" class="rounded-lg border px-4 py-2" @click="editing=false">Hủy</button><button :disabled="busy" type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-white disabled:opacity-50">Lưu dữ liệu</button></div>
      </form>
    </div>
    <div v-if="inputModal.open" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="inputModal.open=false">
      <form @submit.prevent="submitInput" class="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-2xl">
        <h2 class="text-lg font-bold">{{ inputModal.kind === 'status' ? 'Đổi trạng thái nhân viên' : 'Trả lại bảng lương' }}</h2>
        <p v-if="inputModal.error" class="rounded bg-rose-50 p-2 text-xs text-rose-700">{{ inputModal.error }}</p>
        <label v-if="inputModal.kind === 'status'" class="block text-xs font-semibold">Trạng thái mới
          <select v-model="inputModal.value" class="mt-1 w-full rounded-lg border p-2">
            <option value="Dang lam">Đang làm</option>
            <option value="Tam nghi">Tạm nghỉ</option>
            <option value="Da nghi">Đã nghỉ</option>
          </select>
        </label>
        <label v-else class="block text-xs font-semibold">Lý do trả lại (bắt buộc)
          <textarea v-model="inputModal.value" rows="4" maxlength="500" class="mt-1 w-full rounded-lg border p-2"></textarea>
        </label>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border px-4 py-2" @click="inputModal.open=false">Hủy</button>
          <button :disabled="busy" type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-white disabled:opacity-50">Xác nhận</button>
        </div>
      </form>
    </div>
  </div>
</template>
