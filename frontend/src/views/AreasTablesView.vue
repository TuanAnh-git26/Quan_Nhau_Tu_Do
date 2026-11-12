<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'

const branches = ref([])
const areas = ref([])
const tables = ref([])
const selectedBranch = ref('')
const selectedArea = ref('')
const search = ref('')
const statusFilter = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const areaModal = ref(false)
const tableModal = ref(false)
const editingAreaId = ref(null)
const editingTableId = ref(null)
const areaPage = ref(1)
const tablePage = ref(1)
const pageSize = ref(8)

const emptyArea = () => ({ TenKV: '', MaCS: '', TrangThai: 'Dang hoat dong' })
const emptyTable = () => ({ SoBan: '', SoCho: 4, MaKV: '', TrangThai: 'Trong' })
const areaForm = ref(emptyArea())
const tableForm = ref(emptyTable())

const activeAreas = computed(() => areas.value.filter(x => x.TrangThai === 'Dang hoat dong'))
const filteredTables = computed(() => tables.value.filter(x => {
  const key = search.value.trim().toLocaleLowerCase('vi-VN')
  const matchesSearch = !key || `${x.MaBan} ${x.SoBan} ${x.TenKV}`.toLocaleLowerCase('vi-VN').includes(key)
  const matchesArea = !selectedArea.value || String(x.MaKV) === String(selectedArea.value)
  const matchesStatus = !statusFilter.value || x.TrangThai === statusFilter.value
  return matchesSearch && matchesArea && matchesStatus
}).sort((a, b) => Number(a.MaBan) - Number(b.MaBan)))
const areaPageCount = computed(() => Math.max(1, Math.ceil(areas.value.length / pageSize.value)))
const tablePageCount = computed(() => Math.max(1, Math.ceil(filteredTables.value.length / pageSize.value)))
const paginatedAreas = computed(() => areas.value.slice((areaPage.value - 1) * pageSize.value, areaPage.value * pageSize.value))
const paginatedTables = computed(() => filteredTables.value.slice((tablePage.value - 1) * pageSize.value, tablePage.value * pageSize.value))

function statusLabel(value) {
  return ({
    'Dang hoat dong': 'Đang hoạt động',
    'Ngung hoat dong': 'Ngừng hoạt động',
    'Trong': 'Trống',
    'Dang giu cho': 'Đang giữ chỗ',
    'Dang phuc vu': 'Đang phục vụ',
    'Ngung su dung': 'Ngừng sử dụng'
  })[value] || value
}

function statusClass(value) {
  if (['Dang hoat dong', 'Trong'].includes(value)) return 'done'
  if (['Dang phuc vu', 'Dang giu cho'].includes(value)) return 'planned'
  return 'off'
}

function readError(e, fallback) {
  const errors = e.response?.data?.errors
  return errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || fallback)
}

async function loadAll() {
  loading.value = true
  error.value = ''
  try {
    const b = await api.get('/branches')
    branches.value = Array.isArray(b.data) ? b.data : []
    if (!selectedBranch.value && branches.value.length) selectedBranch.value = String(branches.value[0].MaCS)
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể tải dữ liệu khu vực và bàn.')
  } finally {
    loading.value = false
  }
}

async function loadBranchData() {
  if (!selectedBranch.value) {
    areas.value = []
    tables.value = []
    return
  }
  const [a, t] = await Promise.all([
    api.get('/areas', { params: { MaCS: selectedBranch.value } }),
    api.get('/tables', { params: { MaCS: selectedBranch.value } })
  ])
  areas.value = (Array.isArray(a.data) ? a.data : []).sort((x, y) => Number(x.MaKV) - Number(y.MaKV))
  tables.value = (Array.isArray(t.data) ? t.data : []).sort((x, y) => Number(x.MaBan) - Number(y.MaBan))
  if (selectedArea.value && !areas.value.some(x => String(x.MaKV) === String(selectedArea.value))) selectedArea.value = ''
}

function openCreateArea() {
  editingAreaId.value = null
  areaForm.value = { ...emptyArea(), MaCS: selectedBranch.value }
  areaModal.value = true
  error.value = ''
}

function openEditArea(item) {
  editingAreaId.value = item.MaKV
  areaForm.value = { TenKV: item.TenKV, MaCS: String(item.MaCS), TrangThai: item.TrangThai }
  areaModal.value = true
  error.value = ''
}

async function saveArea() {
  saving.value = true
  error.value = ''
  try {
    const payload = { ...areaForm.value, MaCS: Number(areaForm.value.MaCS) }
    if (editingAreaId.value) await api.put(`/areas/${editingAreaId.value}`, payload)
    else await api.post('/areas', payload)
    areaModal.value = false
    success.value = editingAreaId.value ? 'Đã cập nhật khu vực.' : 'Đã thêm khu vực.'
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể lưu khu vực.')
  } finally {
    saving.value = false
  }
}

async function disableArea(item) {
  if (!confirm(`Ngừng hoạt động khu vực “${item.TenKV}”?`)) return
  try {
    await api.delete(`/areas/${item.MaKV}`)
    success.value = 'Đã cập nhật trạng thái khu vực.'
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể cập nhật khu vực.')
  }
}

function openCreateTable() {
  editingTableId.value = null
  tableForm.value = { ...emptyTable(), MaKV: selectedArea.value || activeAreas.value[0]?.MaKV || '' }
  tableModal.value = true
  error.value = ''
}

function openEditTable(item) {
  editingTableId.value = item.MaBan
  tableForm.value = { SoBan: item.SoBan, SoCho: item.SoCho, MaKV: String(item.MaKV), TrangThai: item.TrangThai }
  tableModal.value = true
  error.value = ''
}

async function saveTable() {
  saving.value = true
  error.value = ''
  try {
    const payload = { ...tableForm.value, SoCho: Number(tableForm.value.SoCho), MaKV: Number(tableForm.value.MaKV) }
    if (editingTableId.value) await api.put(`/tables/${editingTableId.value}`, payload)
    else await api.post('/tables', payload)
    tableModal.value = false
    success.value = editingTableId.value ? 'Đã cập nhật bàn.' : 'Đã thêm bàn.'
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể lưu bàn.')
  } finally {
    saving.value = false
  }
}

async function disableTable(item) {
  if (!confirm(`Chuyển bàn ${item.SoBan} sang trạng thái ngừng sử dụng?`)) return
  try {
    await api.delete(`/tables/${item.MaBan}`)
    success.value = 'Đã ngừng sử dụng bàn.'
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể cập nhật bàn.')
  }
}

watch(selectedBranch, async () => {
  selectedArea.value = ''
  areaPage.value = 1
  tablePage.value = 1
  if (selectedBranch.value) {
    try { await loadBranchData() } catch (e) { error.value = readError(e, 'Không thể tải dữ liệu cơ sở.') }
  }
})
watch([search, statusFilter, selectedArea], () => { tablePage.value = 1 })
watch([areas, pageSize], () => { areaPage.value = Math.min(areaPage.value, areaPageCount.value) })
watch([filteredTables, pageSize], () => { tablePage.value = Math.min(tablePage.value, tablePageCount.value) })
onMounted(loadAll)
</script>

<template>
  <div class="page-heading">
    <div>
      <p class="eyebrow">SƠ ĐỒ PHỤC VỤ</p>
      <h1>Quản lý khu vực và bàn</h1>
      <p class="muted">Thiết lập khu vực, sức chứa và tình trạng bàn tại từng cơ sở.</p>
    </div>
    <div class="page-heading-actions"><button class="secondary-btn" :disabled="loading" @click="loadAll">↻ Làm mới</button></div>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <section class="panel dining-filter">
    <div class="filter-field">
      <label>Cơ sở</label>
      <select v-model="selectedBranch">
        <option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option>
      </select>
    </div>
    <div class="filter-field">
      <label>Lọc khu vực</label>
      <select v-model="selectedArea">
        <option value="">Tất cả khu vực</option>
        <option v-for="a in areas" :key="a.MaKV" :value="String(a.MaKV)">{{ a.TenKV }}</option>
      </select>
    </div>
    <div class="filter-field">
      <label>Tìm bàn</label>
      <input v-model="search" placeholder="Nhập mã hoặc số bàn..." />
    </div>
    <div class="filter-field">
      <label>Trạng thái bàn</label>
      <select v-model="statusFilter">
        <option value="">Tất cả trạng thái</option>
        <option value="Trong">Trống</option>
        <option value="Dang giu cho">Đang giữ chỗ</option>
        <option value="Dang phuc vu">Đang phục vụ</option>
        <option value="Ngung su dung">Ngừng sử dụng</option>
      </select>
    </div>
  </section>

  <section class="panel table-panel">
    <div class="panel-heading">
      <div><h2>Danh sách khu vực</h2><p class="panel-subtitle">{{ areas.length }} khu vực · Mã khu vực tăng dần</p></div>
      <button class="primary-btn" @click="openCreateArea">＋ Thêm khu vực</button>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Mã khu vực</th><th>Tên khu vực</th><th>Cơ sở</th><th>Số bàn</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="6" class="empty-state">Đang tải dữ liệu...</td></tr>
          <tr v-else-if="!paginatedAreas.length"><td colspan="6" class="empty-state">Chưa có khu vực tại cơ sở này.</td></tr>
          <tr v-for="a in paginatedAreas" :key="a.MaKV">
            <td class="code-cell">#{{ a.MaKV }}</td><td><strong>{{ a.TenKV }}</strong></td><td>{{ a.TenCS }}</td><td>{{ a.SoLuongBan }}</td>
            <td><span class="tag" :class="statusClass(a.TrangThai)">{{ statusLabel(a.TrangThai) }}</span></td>
            <td><div class="actions"><button class="text-btn" @click="openEditArea(a)">Chỉnh sửa</button><button v-if="a.TrangThai === 'Dang hoat dong'" class="text-btn danger-text" @click="disableArea(a)">Ngừng hoạt động</button></div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="dining-pagination" v-if="areas.length">
      <span>Trang {{ areaPage }} / {{ areaPageCount }}</span>
      <div><select v-model.number="pageSize"><option :value="5">5 / trang</option><option :value="8">8 / trang</option><option :value="15">15 / trang</option></select><button :disabled="areaPage <= 1" @click="areaPage--">Trước</button><button :disabled="areaPage >= areaPageCount" @click="areaPage++">Sau</button></div>
    </div>
  </section>

  <section class="panel table-panel">
    <div class="panel-heading">
      <div><h2>Danh sách bàn</h2><p class="panel-subtitle">{{ filteredTables.length }} bàn phù hợp · Mã bàn tăng dần</p></div>
      <button class="primary-btn" :disabled="!activeAreas.length" @click="openCreateTable">＋ Thêm bàn</button>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Mã bàn</th><th>Số bàn</th><th>Khu vực</th><th>Sức chứa</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="6" class="empty-state">Đang tải dữ liệu...</td></tr>
          <tr v-else-if="!paginatedTables.length"><td colspan="6" class="empty-state">Không có bàn phù hợp.</td></tr>
          <tr v-for="t in paginatedTables" :key="t.MaBan">
            <td class="code-cell">#{{ t.MaBan }}</td><td><strong>{{ t.SoBan }}</strong></td><td>{{ t.TenKV }}</td><td>{{ t.SoCho }} khách</td>
            <td><span class="tag" :class="statusClass(t.TrangThai)">{{ statusLabel(t.TrangThai) }}</span></td>
            <td><div class="actions"><button class="text-btn" @click="openEditTable(t)">Chỉnh sửa</button><button v-if="t.TrangThai !== 'Ngung su dung'" class="text-btn danger-text" @click="disableTable(t)">Ngừng sử dụng</button></div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="dining-pagination" v-if="filteredTables.length">
      <span>Trang {{ tablePage }} / {{ tablePageCount }}</span>
      <div><select v-model.number="pageSize"><option :value="5">5 / trang</option><option :value="8">8 / trang</option><option :value="15">15 / trang</option></select><button :disabled="tablePage <= 1" @click="tablePage--">Trước</button><button :disabled="tablePage >= tablePageCount" @click="tablePage++">Sau</button></div>
    </div>
  </section>

  <div v-if="areaModal" class="modal-backdrop" @click.self="areaModal = false">
    <form class="modal-card" @submit.prevent="saveArea">
      <div class="modal-heading"><div><p class="eyebrow">THIẾT LẬP SƠ ĐỒ</p><h2>{{ editingAreaId ? 'Cập nhật khu vực' : 'Thêm khu vực' }}</h2></div><button type="button" class="close-btn" @click="areaModal = false">×</button></div>
      <label>Tên khu vực <span>*</span><input v-model.trim="areaForm.TenKV" required maxlength="120" placeholder="Ví dụ: Tầng 1, Sân vườn..." /></label>
      <label>Cơ sở <select v-model="areaForm.MaCS" required><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></label>
      <label>Trạng thái <select v-model="areaForm.TrangThai"><option value="Dang hoat dong">Đang hoạt động</option><option value="Ngung hoat dong">Ngừng hoạt động</option></select></label>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="areaModal = false">Đóng</button><button class="primary-btn" type="submit" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Lưu khu vực' }}</button></div>
    </form>
  </div>

  <div v-if="tableModal" class="modal-backdrop" @click.self="tableModal = false">
    <form class="modal-card" @submit.prevent="saveTable">
      <div class="modal-heading"><div><p class="eyebrow">THIẾT LẬP SƠ ĐỒ</p><h2>{{ editingTableId ? 'Cập nhật bàn' : 'Thêm bàn mới' }}</h2></div><button type="button" class="close-btn" @click="tableModal = false">×</button></div>
      <label>Số bàn <span>*</span><input v-model.trim="tableForm.SoBan" required maxlength="30" placeholder="Ví dụ: B01" /></label>
      <label>Khu vực <select v-model="tableForm.MaKV" required><option value="" disabled>Chọn khu vực</option><option v-for="a in activeAreas" :key="a.MaKV" :value="String(a.MaKV)">{{ a.TenKV }}</option></select></label>
      <label>Sức chứa (khách) <input v-model.number="tableForm.SoCho" type="number" min="1" max="1000" required /></label>
      <label>Trạng thái <select v-model="tableForm.TrangThai"><option value="Trong">Trống</option><option value="Dang giu cho">Đang giữ chỗ</option><option value="Dang phuc vu">Đang phục vụ</option><option value="Ngung su dung">Ngừng sử dụng</option></select></label>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="tableModal = false">Đóng</button><button class="primary-btn" type="submit" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Lưu bàn' }}</button></div>
    </form>
  </div>
</template>

<style scoped>
.dining-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px;padding:18px}
.dining-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:15px 4px 0;color:#8997a4;font-size:11px}
.dining-pagination>div{display:flex;align-items:center;gap:8px}
.dining-pagination select,.dining-pagination button{min-height:33px;border:1px solid #dfe6ed;border-radius:7px;background:#fff;padding:6px 10px;color:#526b80;font-size:11px}
.dining-pagination button:disabled{opacity:.4;cursor:not-allowed}
@media(max-width:900px){.dining-filter{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.dining-filter{grid-template-columns:1fr}}
</style>
