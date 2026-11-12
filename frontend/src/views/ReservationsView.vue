<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'

const branches = ref([])
const employees = ref([])
const reservations = ref([])
const availableTables = ref([])
const selectedBranch = ref('')
const search = ref('')
const statusFilter = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const modalOpen = ref(false)
const editingId = ref(null)
const currentPage = ref(1)
const pageSize = ref(8)

function localDateTime(date) {
  const pad = n => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
function defaultForm() {
  const start = new Date(Date.now() + 60 * 60 * 1000)
  start.setMinutes(0, 0, 0)
  const end = new Date(start.getTime() + 2 * 60 * 60 * 1000)
  return {
    HoTen: '', SDT: '', MaCS: selectedBranch.value, MaBan: [], MaNV: '',
    ThoiGianDen: localDateTime(start),
    ThoiGianKetThucDuKien: localDateTime(end),
    SoNguoi: 2, YeuCauDacBiet: '', GhiChu: ''
  }
}
const form = ref(defaultForm())

const filteredReservations = computed(() => reservations.value.filter(x => {
  const key = search.value.trim().toLocaleLowerCase('vi-VN')
  const matchesSearch = !key || `${x.MaDatBan} ${x.HoTen || ''} ${x.SDT || ''} ${x.DanhSachBanText || ''}`.toLocaleLowerCase('vi-VN').includes(key)
  const matchesStatus = !statusFilter.value || x.TrangThai === statusFilter.value
  return matchesSearch && matchesStatus
}).sort((a, b) => Number(b.MaDatBan) - Number(a.MaDatBan)))
const pageCount = computed(() => Math.max(1, Math.ceil(filteredReservations.value.length / pageSize.value)))
const paginatedReservations = computed(() => filteredReservations.value.slice((currentPage.value - 1) * pageSize.value, currentPage.value * pageSize.value))
const selectedTableRows = computed(() => availableTables.value.filter(t => form.value.MaBan.map(String).includes(String(t.MaBan))))

function statusLabel(value) {
  return ({
    'Cho xac nhan': 'Chờ xác nhận',
    'Da xac nhan': 'Đã xác nhận',
    'Khach da den': 'Khách đã đến',
    'Da huy': 'Đã hủy',
    'Hoan tat': 'Hoàn tất'
  })[value] || value
}
function statusClass(value) {
  if (value === 'Da xac nhan' || value === 'Hoan tat') return 'done'
  if (value === 'Khach da den') return 'planned'
  if (value === 'Da huy') return 'off'
  return 'pending'
}
function dateText(value) {
  if (!value) return '—'
  return new Date(String(value).replace(' ', 'T')).toLocaleString('vi-VN')
}
function readError(e, fallback) {
  const errors = e.response?.data?.errors
  return errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || fallback)
}
function toInputDate(value) {
  if (!value) return ''
  return String(value).replace(' ', 'T').slice(0, 16)
}

async function loadInitial() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/branches')
    branches.value = Array.isArray(data) ? data : []
    if (!selectedBranch.value && branches.value.length) selectedBranch.value = String(branches.value[0].MaCS)
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể tải dữ liệu đặt bàn.')
  } finally {
    loading.value = false
  }
}
async function loadBranchData() {
  if (!selectedBranch.value) return
  const [emp, list] = await Promise.all([
    api.get('/employees', { params: { MaCS: selectedBranch.value } }),
    api.get('/reservations', { params: { MaCS: selectedBranch.value } })
  ])
  employees.value = Array.isArray(emp.data) ? emp.data : []
  reservations.value = Array.isArray(list.data) ? list.data : []
}
async function loadAvailableTables() {
  if (!form.value.MaCS || !form.value.ThoiGianDen || !form.value.ThoiGianKetThucDuKien || !form.value.SoNguoi) {
    availableTables.value = []
    return
  }
  try {
    const { data } = await api.get('/available-tables', {
      params: {
        MaCS: form.value.MaCS,
        ThoiGianDen: form.value.ThoiGianDen,
        ThoiGianKetThucDuKien: form.value.ThoiGianKetThucDuKien,
        SoNguoi: form.value.SoNguoi,
        ...(editingId.value ? { MaDatBan: editingId.value } : {})
      }
    })
    availableTables.value = Array.isArray(data) ? data : []
    const valid = new Set(availableTables.value.map(x => String(x.MaBan)))
    form.value.MaBan = form.value.MaBan.filter(id => valid.has(String(id)))
  } catch (e) {
    availableTables.value = []
    error.value = readError(e, 'Không thể kiểm tra bàn còn trống.')
  }
}
function openCreate() {
  editingId.value = null
  form.value = defaultForm()
  form.value.MaCS = selectedBranch.value
  modalOpen.value = true
  error.value = ''
  success.value = ''
  loadAvailableTables()
}
async function openEdit(item) {
  editingId.value = item.MaDatBan
  form.value = {
    HoTen: item.HoTen || '', SDT: item.SDT || '', MaCS: selectedBranch.value,
    MaBan: (item.DanhSachMaBan || []).map(Number), MaNV: item.MaNV ? String(item.MaNV) : '',
    ThoiGianDen: toInputDate(item.ThoiGianDen),
    ThoiGianKetThucDuKien: toInputDate(item.ThoiGianKetThucDuKien),
    SoNguoi: Number(item.SoNguoi), YeuCauDacBiet: item.YeuCauDacBiet || '', GhiChu: item.GhiChu || ''
  }
  modalOpen.value = true
  error.value = ''
  await loadAvailableTables()
}
async function saveReservation() {
  if (!form.value.MaBan.length) {
    error.value = 'Vui lòng chọn ít nhất một bàn còn trống.'
    return
  }
  saving.value = true
  error.value = ''
  try {
    const payload = {
      ...form.value,
      MaCS: Number(form.value.MaCS),
      MaBan: form.value.MaBan.map(Number),
      MaNV: form.value.MaNV ? Number(form.value.MaNV) : null,
      SoNguoi: Number(form.value.SoNguoi)
    }
    if (editingId.value) await api.put(`/reservations/${editingId.value}`, payload)
    else await api.post('/reservations', payload)
    modalOpen.value = false
    success.value = editingId.value ? 'Đã cập nhật phiếu đặt bàn.' : 'Đã tạo phiếu đặt bàn.'
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể lưu phiếu đặt bàn.')
  } finally {
    saving.value = false
  }
}
async function changeStatus(item, status) {
  const label = statusLabel(status)
  if (!confirm(`Chuyển phiếu #${item.MaDatBan} sang trạng thái “${label}”?`)) return
  try {
    await api.put(`/reservations/${item.MaDatBan}/status`, { TrangThai: status })
    success.value = `Đã cập nhật phiếu #${item.MaDatBan}.`
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể cập nhật trạng thái phiếu.')
  }
}
async function cancelReservation(item) {
  if (!confirm(`Hủy phiếu đặt bàn #${item.MaDatBan}?`)) return
  try {
    await api.delete(`/reservations/${item.MaDatBan}`)
    success.value = 'Đã hủy phiếu đặt bàn.'
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể hủy phiếu đặt bàn.')
  }
}

watch(selectedBranch, async () => {
  if (!selectedBranch.value) return
  try { await loadBranchData() } catch (e) { error.value = readError(e, 'Không thể tải dữ liệu cơ sở.') }
})
watch(() => [form.value.MaCS, form.value.ThoiGianDen, form.value.ThoiGianKetThucDuKien, form.value.SoNguoi], () => {
  if (modalOpen.value) loadAvailableTables()
})
watch([search, statusFilter], () => { currentPage.value = 1 })
watch([filteredReservations, pageSize], () => { currentPage.value = Math.min(currentPage.value, pageCount.value) })
onMounted(loadInitial)
</script>

<template>
  <div class="page-heading">
    <div><p class="eyebrow">TIẾP ĐÓN KHÁCH HÀNG</p><h1>Quản lý đặt bàn</h1><p class="muted">Tiếp nhận yêu cầu, kiểm tra bàn trống và theo dõi tình trạng đặt chỗ.</p></div>
    <div class="page-heading-actions"><button class="primary-btn" @click="openCreate">＋ Tạo phiếu đặt bàn</button></div>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <section class="panel reservation-toolbar">
    <div class="filter-field"><label>Cơ sở</label><select v-model="selectedBranch"><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></div>
    <div class="filter-field"><label>Tìm khách / mã phiếu</label><input v-model="search" placeholder="Tên khách, số điện thoại, mã phiếu..." /></div>
    <div class="filter-field"><label>Trạng thái</label><select v-model="statusFilter"><option value="">Tất cả trạng thái</option><option value="Cho xac nhan">Chờ xác nhận</option><option value="Da xac nhan">Đã xác nhận</option><option value="Khach da den">Khách đã đến</option><option value="Hoan tat">Hoàn tất</option><option value="Da huy">Đã hủy</option></select></div>
    <div class="filter-field toolbar-action"><label>&nbsp;</label><button class="secondary-btn" :disabled="loading" @click="loadBranchData">↻ Làm mới</button></div>
  </section>

  <section class="stat-grid reservation-stats">
    <article class="stat-card"><span class="stat-icon blue">▣</span><div><span class="stat-label">Tổng phiếu</span><strong>{{ reservations.length }}</strong><small>Tại cơ sở đã chọn</small></div></article>
    <article class="stat-card"><span class="stat-icon purple">◷</span><div><span class="stat-label">Chờ xác nhận</span><strong>{{ reservations.filter(x => x.TrangThai === 'Cho xac nhan').length }}</strong><small>Yêu cầu mới</small></div></article>
    <article class="stat-card"><span class="stat-icon green">✓</span><div><span class="stat-label">Đã xác nhận</span><strong>{{ reservations.filter(x => x.TrangThai === 'Da xac nhan' || x.TrangThai === 'Khach da den').length }}</strong><small>Đã tiếp nhận khách</small></div></article>
  </section>

  <section class="panel table-panel">
    <div class="panel-heading"><div><h2>Danh sách phiếu đặt bàn</h2><p class="panel-subtitle">{{ filteredReservations.length }} phiếu phù hợp · Mã phiếu giảm dần</p></div></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Mã phiếu</th><th>Khách hàng</th><th>Thời gian đến</th><th>Số khách</th><th>Bàn</th><th>Nhân viên</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="8" class="empty-state">Đang tải phiếu đặt bàn...</td></tr>
          <tr v-else-if="!paginatedReservations.length"><td colspan="8" class="empty-state">Chưa có phiếu đặt bàn phù hợp.</td></tr>
          <tr v-for="item in paginatedReservations" :key="item.MaDatBan">
            <td class="code-cell">#{{ item.MaDatBan }}</td>
            <td><strong>{{ item.HoTen || 'Khách lẻ' }}</strong><small class="sub-cell">{{ item.SDT || 'Chưa có số điện thoại' }}</small></td>
            <td>{{ dateText(item.ThoiGianDen) }}<small class="sub-cell">Đến {{ dateText(item.ThoiGianKetThucDuKien) }}</small></td>
            <td>{{ item.SoNguoi }} người</td><td>{{ item.DanhSachBanText || '—' }}<small class="sub-cell">{{ item.DanhSachKhuVucText || '' }}</small></td>
            <td>{{ item.NhanVienPhuTrach || 'Chưa phân công' }}</td>
            <td><span class="tag" :class="statusClass(item.TrangThai)">{{ statusLabel(item.TrangThai) }}</span></td>
            <td><div class="actions"><button v-if="!['Da huy','Hoan tat'].includes(item.TrangThai)" class="text-btn" @click="openEdit(item)">Sửa</button><button v-if="item.TrangThai === 'Cho xac nhan'" class="text-btn" @click="changeStatus(item, 'Da xac nhan')">Xác nhận</button><button v-if="item.TrangThai === 'Da xac nhan'" class="text-btn" @click="changeStatus(item, 'Khach da den')">Khách đến</button><button v-if="item.TrangThai === 'Khach da den'" class="text-btn" @click="changeStatus(item, 'Hoan tat')">Hoàn tất</button><button v-if="!['Da huy','Hoan tat'].includes(item.TrangThai)" class="text-btn danger-text" @click="cancelReservation(item)">Hủy</button></div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="filteredReservations.length" class="reservation-pagination"><span>Trang {{ currentPage }} / {{ pageCount }}</span><div><select v-model.number="pageSize"><option :value="5">5 / trang</option><option :value="8">8 / trang</option><option :value="15">15 / trang</option></select><button :disabled="currentPage <= 1" @click="currentPage--">Trước</button><button :disabled="currentPage >= pageCount" @click="currentPage++">Sau</button></div></div>
  </section>

  <div v-if="modalOpen" class="modal-backdrop" @click.self="modalOpen = false">
    <form class="modal-card reservation-modal" @submit.prevent="saveReservation">
      <div class="modal-heading"><div><p class="eyebrow">ĐẶT CHỖ NHÀ HÀNG</p><h2>{{ editingId ? `Cập nhật phiếu #${editingId}` : 'Tạo phiếu đặt bàn' }}</h2></div><button type="button" class="close-btn" @click="modalOpen = false">×</button></div>
      <div class="reservation-form-grid">
        <label>Họ tên khách <span>*</span><input v-model.trim="form.HoTen" required maxlength="150" placeholder="Nhập tên khách hàng" /></label>
        <label>Số điện thoại<input v-model.trim="form.SDT" maxlength="20" placeholder="Số điện thoại liên hệ" /></label>
        <label>Cơ sở<select v-model="form.MaCS" required><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></label>
        <label>Nhân viên phụ trách<select v-model="form.MaNV"><option value="">Chưa phân công</option><option v-for="e in employees" :key="e.MaNV" :value="String(e.MaNV)">{{ e.HoTen }}</option></select></label>
        <label>Thời gian khách đến <span>*</span><input v-model="form.ThoiGianDen" type="datetime-local" required /></label>
        <label>Thời gian kết thúc dự kiến <span>*</span><input v-model="form.ThoiGianKetThucDuKien" type="datetime-local" required /></label>
        <label>Số lượng khách <span>*</span><input v-model.number="form.SoNguoi" type="number" min="1" max="1000" required /></label>
        <label>Yêu cầu đặc biệt<input v-model.trim="form.YeuCauDacBiet" maxlength="2000" placeholder="Ví dụ: ghế trẻ em, ăn chay..." /></label>
      </div>
      <div class="available-table-picker">
        <div class="picker-heading"><div><strong>Chọn bàn còn trống</strong><small>Danh sách được kiểm tra theo thời gian và số lượng khách.</small></div><span>{{ availableTables.length }} bàn phù hợp</span></div>
        <div v-if="!availableTables.length" class="empty-state compact-empty">Không tìm thấy bàn phù hợp. Hãy đổi giờ, số khách hoặc cơ sở.</div>
        <div v-else class="available-table-grid">
          <label v-for="t in availableTables" :key="t.MaBan" class="available-table-option" :class="{ chosen: form.MaBan.map(String).includes(String(t.MaBan)) }">
            <input v-model="form.MaBan" type="checkbox" :value="t.MaBan" />
            <span><strong>{{ t.SoBan }}</strong><small>{{ t.TenKV }} · {{ t.SoCho }} chỗ</small></span>
          </label>
        </div>
        <div class="selected-table-summary">Đã chọn {{ form.MaBan.length }} bàn · Tổng sức chứa {{ selectedTableRows.reduce((sum, x) => sum + Number(x.SoCho), 0) }} khách</div>
      </div>
      <label>Ghi chú nội bộ<textarea v-model.trim="form.GhiChu" rows="2" maxlength="2000" placeholder="Ghi chú cho nhân viên tiếp nhận..."></textarea></label>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modalOpen = false">Đóng</button><button type="submit" class="primary-btn" :disabled="saving || !form.MaBan.length">{{ saving ? 'Đang lưu...' : 'Lưu phiếu đặt bàn' }}</button></div>
    </form>
  </div>
</template>

<style scoped>
.reservation-toolbar{display:grid;grid-template-columns:1.1fr 1.5fr 1fr auto;gap:14px;padding:18px;margin-bottom:18px}
.toolbar-action .secondary-btn{width:100%}
.reservation-stats{margin-bottom:18px}
.sub-cell{display:block;margin-top:4px;color:#93a0ac;font-size:9px}
.reservation-pagination{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:15px 4px 0;color:#8997a4;font-size:11px}
.reservation-pagination>div{display:flex;align-items:center;gap:8px}
.reservation-pagination select,.reservation-pagination button{min-height:33px;border:1px solid #dfe6ed;border-radius:7px;background:#fff;padding:6px 10px;color:#526b80;font-size:11px}
.reservation-pagination button:disabled{opacity:.4;cursor:not-allowed}
.reservation-modal{width:min(760px,calc(100vw - 30px));max-height:90vh;overflow:auto}
.reservation-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.available-table-picker{margin:16px 0;padding:14px;border:1px solid #e6ebf0;border-radius:10px;background:#fafbfd}
.picker-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
.picker-heading strong,.picker-heading small{display:block}
.picker-heading strong{font-size:12px;color:#314b60}
.picker-heading small{margin-top:4px;color:#8b99a6;font-size:10px}
.picker-heading>span{font-size:10px;color:#6b8d7c}
.available-table-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.available-table-option{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #e1e8ed;border-radius:8px;background:#fff;cursor:pointer}
.available-table-option.chosen{border-color:#c9985a;background:#fff8ef}
.available-table-option input{width:auto}
.available-table-option strong,.available-table-option small{display:block}
.available-table-option strong{font-size:11px;color:#354d62}
.available-table-option small{margin-top:3px;font-size:9px;color:#8b99a6}
.selected-table-summary{margin-top:10px;color:#657b8e;font-size:10px}
.compact-empty{padding:12px;text-align:center;color:#8b99a6;font-size:10px}
@media(max-width:950px){.reservation-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.reservation-toolbar,.reservation-form-grid{grid-template-columns:1fr}.available-table-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
