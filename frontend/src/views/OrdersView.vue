<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'

const branches = ref([])
const tables = ref([])
const employees = ref([])
const supervisors = ref([])
const supervisorModal = ref(false)
const supervisorSelected = ref('')
const supervisorPassword = ref('')
const pendingChange = ref(null)
const menuItems = ref([])
const orders = ref([])
const selectedBranch = ref('')
const selectedTable = ref('')
const selectedEmployee = ref('')
const selectedOrderId = ref(null)
const order = ref(null)
const menuSearch = ref('')
const categoryFilter = ref('')
const orderSearch = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const employeeModal = ref(false)
const employeeName = ref('')
const employeePhone = ref('')
const currentPage = ref(1)
const pageSize = ref(8)
const menuPage = ref(1)
const menuPageSize = ref(8)
const addQuantity = ref(1)
const addNote = ref('')

const availableMenu = computed(() => {
  const key = menuSearch.value.trim().toLocaleLowerCase('vi-VN')
  return menuItems.value.filter(x => {
    const available = x.CoTheBan === true || x.CoTheBan === 1
    const categoryMatch = !categoryFilter.value || String(x.MaDM) === String(categoryFilter.value)
    const searchMatch = !key || `${x.MaMon} ${x.TenMon} ${x.TenDM || ''}`.toLocaleLowerCase('vi-VN').includes(key)
    return available && categoryMatch && searchMatch
  }).sort((a, b) => Number(a.MaMon) - Number(b.MaMon))
})
const menuPageCount = computed(() => Math.max(1, Math.ceil(availableMenu.value.length / menuPageSize.value)))
const paginatedMenu = computed(() => availableMenu.value.slice((menuPage.value - 1) * menuPageSize.value, menuPage.value * menuPageSize.value))
const filteredOrders = computed(() => {
  const key = orderSearch.value.trim().toLocaleLowerCase('vi-VN')
  return orders.value.filter(x => !key || `${x.MaOrder} ${x.SoBan} ${x.TenKV} ${x.NhanVienPhuTrach}`.toLocaleLowerCase('vi-VN').includes(key))
    .sort((a, b) => Number(b.MaOrder) - Number(a.MaOrder))
})
const orderPageCount = computed(() => Math.max(1, Math.ceil(filteredOrders.value.length / pageSize.value)))
const paginatedOrders = computed(() => filteredOrders.value.slice((currentPage.value - 1) * pageSize.value, currentPage.value * pageSize.value))
const categories = computed(() => {
  const map = new Map()
  menuItems.value.forEach(x => map.set(String(x.MaDM), { MaDM: x.MaDM, TenDM: x.TenDM }))
  return [...map.values()].sort((a, b) => Number(a.MaDM) - Number(b.MaDM))
})
const orderTotal = computed(() => Number(order.value?.TamTinh || 0))
const orderCanEdit = computed(() => ['Dang mo', 'Da gui'].includes(order.value?.TrangThai))
const orderCanAdd = computed(() => ['Dang mo', 'Da gui'].includes(order.value?.TrangThai))

function money(value) {
  return new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + ' đ'
}
function dateText(value) {
  if (!value) return '—'
  return new Date(String(value).replace(' ', 'T')).toLocaleString('vi-VN')
}
function statusText(value) {
  return ({ 'Dang mo': 'Đang soạn', 'Da gui': 'Đã gửi Bếp/Bar', 'Dang thanh toan': 'Đang thanh toán', 'Da thanh toan': 'Đã thanh toán', 'Da huy': 'Đã hủy' })[value] || value
}
function tableStatusText(value) {
  return ({ Trong: 'Trống', 'Dang giu cho': 'Đang giữ chỗ', 'Dang phuc vu': 'Đang phục vụ', 'Ngung su dung': 'Ngừng sử dụng' })[value] || value
}
function statusClass(value) {
  return value === 'Dang mo' ? 'planned' : ['Da gui', 'Dang thanh toan'].includes(value) ? 'done' : 'off'
}
function readError(e, fallback) {
  const errors = e.response?.data?.errors
  return errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || fallback)
}

async function loadBranchData() {
  if (!selectedBranch.value) {
    tables.value = []; employees.value = []; supervisors.value = []; menuItems.value = []; orders.value = []
    return
  }
  const [t, e, m, o] = await Promise.all([
    api.get('/tables', { params: { MaCS: selectedBranch.value } }),
    api.get('/employees', { params: { MaCS: selectedBranch.value } }),
    api.get('/menu-items', { params: { MaCS: selectedBranch.value } }),
    api.get('/orders', { params: { MaCS: selectedBranch.value } })
  ])
  tables.value = (Array.isArray(t.data) ? t.data : []).sort((a, b) => Number(a.MaBan) - Number(b.MaBan))
  employees.value = Array.isArray(e.data) ? e.data : []
  // Chỉ lấy tên/mã và chức danh Giám sát ở cơ sở đang chọn.
  const supervisorRes = await api.get('/orders/supervisors', {
    params: { MaCS: selectedBranch.value }
  })
  supervisors.value = Array.isArray(supervisorRes.data) ? supervisorRes.data : []
  menuItems.value = Array.isArray(m.data) ? m.data : []
  orders.value = Array.isArray(o.data) ? o.data : []
  if (selectedEmployee.value && !employees.value.some(x => String(x.MaNV) === String(selectedEmployee.value))) selectedEmployee.value = ''
  if (!selectedEmployee.value && employees.value.length) selectedEmployee.value = String(employees.value[0].MaNV)
}
async function loadInitial() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/branches')
    branches.value = Array.isArray(data) ? data : []
    if (branches.value.length) selectedBranch.value = String(branches.value[0].MaCS)
    else error.value = 'Chưa có cơ sở đang hoạt động trong hệ thống.'
  } catch (e) {
    error.value = readError(e, 'Không thể tải danh sách cơ sở.')
  } finally {
    loading.value = false
  }
}
async function refreshAll() {
  loading.value = true
  error.value = ''
  try {
    await loadBranchData()
    if (selectedOrderId.value) await loadOrder(selectedOrderId.value)
  } catch (e) {
    error.value = readError(e, 'Không thể tải dữ liệu Order/POS.')
  } finally {
    loading.value = false
  }
}
async function createOrOpenOrder() {
  error.value = ''; success.value = ''
  if (!selectedTable.value) { error.value = 'Vui lòng chọn bàn trước.'; return }
  if (!selectedEmployee.value) { employeeModal.value = true; error.value = 'Cơ sở chưa có nhân viên phụ trách. Hãy tạo hồ sơ nhân viên demo để tiếp tục.'; return }
  saving.value = true
  try {
    const { data } = await api.post('/orders', { MaBan: Number(selectedTable.value), MaNV: Number(selectedEmployee.value) })
    selectedOrderId.value = data.MaOrder
    await loadOrder(data.MaOrder)
    await loadBranchData()
    success.value = data.DaTonTai ? 'Đã mở order đang hoạt động của bàn.' : 'Đã tạo order mới cho bàn.'
  } catch (e) {
    error.value = readError(e, 'Không thể tạo order cho bàn.')
  } finally {
    saving.value = false
  }
}
async function loadOrder(id) {
  const { data } = await api.get(`/orders/${id}`)
  order.value = data
  selectedOrderId.value = data.MaOrder
  selectedTable.value = String(data.MaBan)
}
async function openOrder(item) {
  error.value = ''
  try { await loadOrder(item.MaOrder) } catch (e) { error.value = readError(e, 'Không thể mở chi tiết order.') }
}
async function addMenuItem(item) {
  if (!order.value || !orderCanAdd.value) { error.value = 'Order đã kết thúc hoặc đang thanh toán, không thể thêm món.'; return }
  const quantity = Number(addQuantity.value)
  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 999) { error.value = 'Số lượng phải từ 1 đến 999.'; return }
  saving.value = true
  error.value = ''
  try {
    const requestKey = globalThis.crypto?.randomUUID?.() || `pos-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
    await api.post(`/orders/${order.value.MaOrder}/items`, {
      MaMon: Number(item.MaMon), SoLuong: quantity, GhiChu: addNote.value.trim() || null, MaYeuCau: requestKey
    })
    addQuantity.value = 1
    addNote.value = ''
    await loadOrder(order.value.MaOrder)
    await loadBranchData()
    success.value = `Đã thêm ${item.TenMon} vào order.`
  } catch (e) {
    error.value = readError(e, 'Không thể thêm món vào order.')
  } finally {
    saving.value = false
  }
}
function canChangeLine(detail) {
  return orderCanEdit.value && ['Cho che bien', 'Da nhan', 'Dang che bien'].includes(detail.TrangThaiMon)
}
function needsApproval(detail) {
  return ['Da nhan', 'Dang che bien'].includes(detail.TrangThaiMon)
}
function requestApproval(action) {
  if (!supervisors.value.length) {
    error.value = 'Cơ sở chưa có Giám sát/Quản lý đang làm. Hãy cập nhật chức danh trong phân hệ Nhân sự.'
    return
  }
  pendingChange.value = action
  supervisorSelected.value = ''
  supervisorPassword.value = ''
  supervisorModal.value = true
}
async function submitSupervisorApproval() {
  if (!supervisorSelected.value || !pendingChange.value) return
  const action = { ...pendingChange.value, MaNVDuyet: Number(supervisorSelected.value), MatKhauNVDuyet: supervisorPassword.value }
  supervisorModal.value = false
  pendingChange.value = null
  supervisorPassword.value = ''
  await executeLineAction(action)
}
async function executeLineAction(action) {
  const { type, detail, quantity, MaNVDuyet, MatKhauNVDuyet } = action
  saving.value = true; error.value = ''; success.value = ''
  try {
    if (type === 'update') {
      await api.put(`/orders/${order.value.MaOrder}/items/${detail.MaCTOrder}`, {
        SoLuong: quantity, GhiChu: detail.GhiChu || null, MaNVDuyet: MaNVDuyet || null, MatKhauNVDuyet: MatKhauNVDuyet || null
      })
      success.value = 'Đã cập nhật món.'
    } else {
      await api.delete(`/orders/${order.value.MaOrder}/items/${detail.MaCTOrder}`, {
        data: { MaNVDuyet: MaNVDuyet || null, MatKhauNVDuyet: MatKhauNVDuyet || null }
      })
      success.value = 'Đã hủy món khỏi order.'
    }
    await loadOrder(order.value.MaOrder)
    await loadBranchData()
  } catch (e) {
    error.value = readError(e, 'Không thể cập nhật/hủy món.')
  } finally { saving.value = false }
}
async function changeLine(detail, delta) {
  if (!canChangeLine(detail) || saving.value) return
  const quantity = Number(detail.SoLuong) + delta
  if (quantity < 1 || quantity > 999) return
  const action = { type: 'update', detail, quantity }
  if (needsApproval(detail)) requestApproval(action)
  else await executeLineAction(action)
}
async function cancelLine(detail) {
  if (!canChangeLine(detail) || saving.value) return
  if (!confirm(`Hủy món “${detail.TenMon}” khỏi order #${order.value.MaOrder}?`)) return
  const action = { type: 'cancel', detail }
  if (needsApproval(detail)) requestApproval(action)
  else await executeLineAction(action)
}
async function sendOrder() {
  if (!order.value || !confirm(`Gửi order #${order.value.MaOrder} đến Bếp/Bar?`)) return
  saving.value = true
  try {
    await api.post(`/orders/${order.value.MaOrder}/send`)
    await loadOrder(order.value.MaOrder)
    await loadBranchData()
    success.value = 'Đã gửi order. Các món đã được ghi nhận để Bếp/Bar tiếp nhận.'
  } catch (e) { error.value = readError(e, 'Không thể gửi order.') }
  finally { saving.value = false }
}
async function cancelOrder() {
  if (!order.value || !confirm(`Hủy toàn bộ order nháp #${order.value.MaOrder}?`)) return
  try {
    await api.post(`/orders/${order.value.MaOrder}/cancel`)
    success.value = 'Đã hủy order nháp.'
    order.value = null
    selectedOrderId.value = null
    await loadBranchData()
  } catch (e) { error.value = readError(e, 'Không thể hủy order.') }
}
async function createQuickEmployee() {
  if (!employeeName.value.trim()) { error.value = 'Vui lòng nhập họ tên nhân viên.'; return }
  saving.value = true
  try {
    const { data } = await api.post('/employees/quick', { HoTen: employeeName.value.trim(), SDT: employeePhone.value.trim() || null, MaCS: Number(selectedBranch.value) })
    employees.value.push(data)
    employees.value.sort((a, b) => Number(a.MaNV) - Number(b.MaNV))
    selectedEmployee.value = String(data.MaNV)
    employeeModal.value = false
    employeeName.value = ''; employeePhone.value = ''
    success.value = 'Đã tạo nhân viên phụ trách order.'
  } catch (e) { error.value = readError(e, 'Không thể tạo nhân viên.') }
  finally { saving.value = false }
}

watch(selectedBranch, async () => {
  selectedTable.value = ''
  selectedOrderId.value = null
  order.value = null
  menuPage.value = 1
  currentPage.value = 1
  try { await loadBranchData() } catch (e) { error.value = readError(e, 'Không thể tải dữ liệu cơ sở.') }
})
watch([menuSearch, categoryFilter, menuPageSize], () => { menuPage.value = 1 })
watch([orderSearch, pageSize], () => { currentPage.value = 1 })
watch([availableMenu, menuPageSize], () => { menuPage.value = Math.min(menuPage.value, menuPageCount.value) })
watch([filteredOrders, pageSize], () => { currentPage.value = Math.min(currentPage.value, orderPageCount.value) })
onMounted(loadInitial)
</script>

<template>
  <div class="ui-view ui-view--orders">
  <div class="page-heading">
    <div><p class="eyebrow">ĐIỀU HÀNH PHỤC VỤ</p><h1>Order / POS</h1><p class="muted">Lập order theo bàn, ghi nhận món gọi và chuyển yêu cầu đến Bếp hoặc quầy pha chế.</p></div>
    <div class="page-heading-actions"><button class="secondary-btn" :disabled="loading" @click="refreshAll">↻ Làm mới dữ liệu</button></div>
  </div>
  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <section class="panel pos-filters">
    <div class="filter-field"><label>Cơ sở</label><select v-model="selectedBranch"><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></div>
    <div class="filter-field"><label>Nhân viên lập order</label><select v-model="selectedEmployee"><option value="">Chọn nhân viên</option><option v-for="e in employees" :key="e.MaNV" :value="String(e.MaNV)">{{ e.HoTen }} · #{{ e.MaNV }}</option></select></div>
    <div class="filter-field"><label>Bàn đang thao tác</label><select v-model="selectedTable"><option value="">Chọn bàn</option><option v-for="t in tables.filter(x => x.TrangThai !== 'Ngung su dung')" :key="t.MaBan" :value="String(t.MaBan)">{{ t.SoBan }} · {{ t.TenKV }}</option></select></div>
    <div class="filter-field"><label>&nbsp;</label><button class="primary-btn" :disabled="saving || !selectedTable || !selectedEmployee" @click="createOrOpenOrder">＋ Mở order cho bàn</button></div>
  </section>
  <div v-if="!employees.length && selectedBranch" class="alert error employee-warning">Cơ sở chưa có nhân viên phụ trách order. <button class="secondary-btn" @click="employeeModal = true">＋ Tạo nhân viên phụ trách</button></div>

  <section class="panel table-selector-panel">
    <div class="panel-heading"><div><h2>Sơ đồ bàn</h2><p class="panel-subtitle">Chọn bàn cần lập order. Bàn đang phục vụ có thể mở order đang hoạt động.</p></div><span class="result-count">{{ tables.length }} bàn</span></div>
    <div v-if="!tables.length" class="empty-state">Cơ sở chưa có bàn. Hãy tạo khu vực và bàn trước.</div>
    <div v-else class="pos-table-grid">
      <button v-for="t in tables.filter(x => x.TrangThai !== 'Ngung su dung')" :key="t.MaBan" class="pos-table-card" :class="[{ selected: selectedTable === String(t.MaBan) }, t.TrangThai === 'Dang phuc vu' ? 'serving' : t.TrangThai === 'Dang giu cho' ? 'reserved' : 'free']" @click="selectedTable = String(t.MaBan)">
        <span class="pos-table-number">{{ t.SoBan }}</span><span class="pos-table-area">{{ t.TenKV }} · {{ t.SoCho }} chỗ</span><span class="pos-table-status">{{ tableStatusText(t.TrangThai) }}</span>
      </button>
    </div>
  </section>

  <div class="pos-workspace">
    <section class="panel menu-picker-panel">
      <div class="panel-heading"><div><h2>Chọn món</h2><p class="panel-subtitle">Chỉ hiển thị món đang bán và đã có giá tại cơ sở.</p></div><span class="result-count">{{ availableMenu.length }} món</span></div>
      <div class="menu-picker-tools"><input v-model="menuSearch" placeholder="Tìm mã món hoặc tên món..." /><select v-model="categoryFilter"><option value="">Tất cả danh mục</option><option v-for="c in categories" :key="c.MaDM" :value="String(c.MaDM)">{{ c.TenDM }}</option></select></div>
      <div v-if="!paginatedMenu.length" class="empty-state">Không có món phù hợp hoặc cơ sở chưa được thiết lập giá bán.</div>
      <div v-else class="pos-menu-grid">
        <article v-for="m in paginatedMenu" :key="m.MaMon" class="pos-menu-card"><div class="pos-menu-icon">{{ (m.TenMon || 'M').slice(0,1) }}</div><div class="pos-menu-info"><small>#{{ m.MaMon }} · {{ m.TenDM }}</small><strong>{{ m.TenMon }}</strong><span>{{ money(m.DonGia) }} / {{ m.DonViTinh }}</span></div><button class="add-menu-btn" :disabled="!orderCanAdd || saving" @click="addMenuItem(m)">＋</button></article>
      </div>
      <div v-if="availableMenu.length" class="pos-pagination"><span>Trang {{ menuPage }} / {{ menuPageCount }}</span><div><select v-model.number="menuPageSize"><option :value="8">8 / trang</option><option :value="16">16 / trang</option><option :value="24">24 / trang</option></select><button :disabled="menuPage <= 1" @click="menuPage--">Trước</button><button :disabled="menuPage >= menuPageCount" @click="menuPage++">Sau</button></div></div>
      <div class="add-note-row"><label>Số lượng mặc định<input v-model.number="addQuantity" type="number" min="1" max="999" /></label><label>Ghi chú món<input v-model="addNote" placeholder="Ví dụ: không cay, ít đá..." /></label></div>
      <p class="form-hint">Chọn dấu ＋ trên món để thêm vào order đang mở. Giá món được lấy từ cơ sở dữ liệu, không nhập từ trình duyệt.</p>
    </section>

    <section class="panel current-order-panel">
      <div class="panel-heading"><div><h2>Chi tiết order</h2><p class="panel-subtitle">{{ order ? `Order #${order.MaOrder} · Bàn ${order.SoBan} · ${order.TenKV}` : 'Chưa mở order' }}</p></div><span v-if="order" class="tag" :class="statusClass(order.TrangThai)">{{ statusText(order.TrangThai) }}</span></div>
      <div v-if="!order" class="order-empty"><div class="order-empty-icon">▤</div><strong>Chưa có order được chọn</strong><span>Chọn bàn rồi nhấn “Mở order cho bàn”.</span></div>
      <template v-else>
        <div class="order-meta"><span>Nhân viên: <strong>{{ order.NhanVienPhuTrach }}</strong></span><span>Thời gian: <strong>{{ dateText(order.ThoiGianTao) }}</strong></span></div>
        <div class="order-lines">
          <article v-for="d in order.ChiTiet" :key="d.MaCTOrder" class="order-line" :class="{ cancelled: d.TrangThaiMon === 'Da huy' }">
            <div class="order-line-main"><strong>{{ d.TenMon }}</strong><small>#{{ d.MaMon }} · {{ d.TenDM }} · {{ d.NoiCheBien === 'Bep' ? 'Bếp' : 'Quầy pha chế' }}</small><small v-if="d.GhiChu">Ghi chú: {{ d.GhiChu }}</small><small v-if="d.TrangThaiMon === 'Da huy'" class="line-cancelled">Đã hủy</small></div>
            <div v-if="canChangeLine(d)" class="order-line-qty"><button @click="changeLine(d,-1)">−</button><strong>{{ d.SoLuong }}</strong><button @click="changeLine(d,1)">＋</button></div><div v-else class="order-line-qty"><strong>{{ d.SoLuong }}</strong></div>
            <div class="order-line-price">{{ money(d.ThanhTien) }}<button v-if="canChangeLine(d)" class="text-btn danger-text" @click="cancelLine(d)">Hủy món</button></div>
          </article>
          <div v-if="!order.ChiTiet.length" class="empty-state">Order chưa có món. Chọn món từ danh sách bên trái.</div>
        </div>
        <div class="order-total"><span>Tạm tính</span><strong>{{ money(orderTotal) }}</strong></div>
        <div v-if="orderCanEdit" class="order-actions"><button v-if="order.TrangThai === 'Dang mo'" class="secondary-btn danger-outline" @click="cancelOrder">Hủy order nháp</button><button class="primary-btn" :disabled="saving || !order.ChiTiet.some(x => x.TrangThaiMon !== 'Da huy')" @click="sendOrder">{{ saving ? 'Đang gửi...' : order.TrangThai === 'Da gui' ? 'Gửi món bổ sung →' : 'Gửi Bếp / Bar →' }}</button></div>
        <div v-else class="sent-note">Order đã gửi. Bạn có thể thêm món gọi thêm; món mới được ghi nhận riêng để Bếp/Bar tiếp nhận.</div>
      </template>
    </section>
  </div>

  <section class="panel active-orders-panel">
    <div class="panel-heading"><div><h2>Order đang hoạt động</h2><p class="panel-subtitle">Các phiếu đang soạn, đã gửi hoặc đang chờ thanh toán.</p></div><span class="result-count">{{ filteredOrders.length }} order</span></div>
    <div class="order-search-row"><input v-model="orderSearch" placeholder="Tìm theo mã order, số bàn, khu vực..." /></div>
    <div class="table-wrap"><table><thead><tr><th>Mã order</th><th>Bàn</th><th>Khu vực</th><th>Nhân viên</th><th>Thời gian</th><th>Số lượng</th><th>Tạm tính</th><th>Trạng thái</th><th></th></tr></thead><tbody>
      <tr v-if="!paginatedOrders.length"><td colspan="9" class="empty-state">Chưa có order đang hoạt động.</td></tr>
      <tr v-for="o in paginatedOrders" :key="o.MaOrder"><td class="code-cell">#{{ o.MaOrder }}</td><td><strong>{{ o.SoBan }}</strong></td><td>{{ o.TenKV }}</td><td>{{ o.NhanVienPhuTrach }}</td><td>{{ dateText(o.ThoiGianTao) }}</td><td>{{ o.TongSoLuong || 0 }}</td><td class="price-cell">{{ money(o.TamTinh) }}</td><td><span class="tag" :class="statusClass(o.TrangThai)">{{ statusText(o.TrangThai) }}</span></td><td><button class="text-btn" @click="openOrder(o)">Chi tiết</button></td></tr>
    </tbody></table></div>
    <div v-if="filteredOrders.length" class="pos-pagination"><span>Trang {{ currentPage }} / {{ orderPageCount }}</span><div><select v-model.number="pageSize"><option :value="5">5 / trang</option><option :value="8">8 / trang</option><option :value="15">15 / trang</option></select><button :disabled="currentPage <= 1" @click="currentPage--">Trước</button><button :disabled="currentPage >= orderPageCount" @click="currentPage++">Sau</button></div></div>
  </section>

  <div v-if="supervisorModal" class="modal-backdrop" @click.self="supervisorModal = false">
    <form class="modal-card" @submit.prevent="submitSupervisorApproval">
      <div class="modal-heading">
        <div><p class="eyebrow">PHÊ DUYỆT THAY ĐỔI MÓN</p><h2>Chọn Giám sát / Quản lý</h2></div>
        <button type="button" class="close-btn" @click="supervisorModal = false">×</button>
      </div>
      <p class="form-hint">Món đã nhận hoặc đang chế biến cần Giám sát/Quản lý cùng cơ sở phê duyệt trước khi sửa hoặc hủy.</p>
      <label>Người phê duyệt <span>*</span>
        <select v-model="supervisorSelected" required>
          <option value="">Chọn người phê duyệt</option>
          <option v-for="person in supervisors" :key="person.MaNV" :value="String(person.MaNV)">
            {{ person.HoTen }} — {{ person.TenVT }}
          </option>
        </select>
      </label>
      <label>Mật khẩu Giám sát xác nhận <span>*</span>
        <input v-model="supervisorPassword" type="password" autocomplete="off" required placeholder="Giám sát nhập mật khẩu để duyệt" />
      </label>
      <div class="modal-actions">
        <button type="button" class="secondary-btn" @click="supervisorModal = false">Đóng</button>
        <button type="submit" :disabled="!supervisorSelected || !supervisorPassword || saving" class="primary-btn">Xác nhận duyệt</button>
      </div>
    </form>
  </div>

  <div v-if="employeeModal" class="modal-backdrop" @click.self="employeeModal = false"><form class="modal-card" @submit.prevent="createQuickEmployee"><div class="modal-heading"><div><p class="eyebrow">NHÂN SỰ PHỤ TRÁCH ORDER</p><h2>Tạo nhân viên dùng cho demo</h2></div><button type="button" class="close-btn" @click="employeeModal = false">×</button></div><p class="form-hint">Tạo hồ sơ tối thiểu để ghi nhận nhân viên lập order. Hồ sơ nhân sự đầy đủ thuộc phân hệ Nhân sự ở đợt sau.</p><label>Họ tên nhân viên <span>*</span><input v-model.trim="employeeName" required maxlength="150" placeholder="Nhập họ tên" /></label><label>Số điện thoại<input v-model.trim="employeePhone" maxlength="20" placeholder="Không bắt buộc" /></label><div class="modal-actions"><button type="button" class="secondary-btn" @click="employeeModal = false">Hủy</button><button type="submit" class="primary-btn" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Tạo nhân viên' }}</button></div></form></div>

  </div>
</template>

<style scoped>
.pos-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;padding:18px;margin-bottom:18px}
.pos-filters .primary-btn{width:100%}
.employee-warning{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.result-count{color:#8593a0;font-size:11px}
.pos-table-grid{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:10px}
.pos-table-card{display:flex;flex-direction:column;align-items:flex-start;gap:5px;min-width:0;padding:13px;border:1px solid #e4eaf0;border-radius:10px;background:#fff;text-align:left}
.pos-table-card.free{border-top:3px solid #57a77c}.pos-table-card.serving{border-top:3px solid #6194bc}.pos-table-card.reserved{border-top:3px solid #d7a04c}
.pos-table-card.selected{outline:2px solid #d58b39;background:#fff9f0}
.pos-table-number{color:#2d465c;font-size:16px;font-weight:800}.pos-table-area,.pos-table-status{color:#8998a6;font-size:9px}.pos-table-status{font-weight:700}
.pos-workspace{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr);gap:18px;align-items:start}
.menu-picker-panel,.current-order-panel{min-width:0}
.menu-picker-tools{display:grid;grid-template-columns:1.5fr 1fr;gap:10px;margin-bottom:13px}
.pos-menu-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
.pos-menu-card{display:flex;align-items:center;gap:10px;border:1px solid #e8edf2;border-radius:10px;padding:10px;min-width:0}
.pos-menu-icon{display:grid;place-items:center;flex:0 0 36px;height:36px;border-radius:9px;background:#f7efe3;color:#9c6b36;font-weight:800}
.pos-menu-info{display:flex;flex-direction:column;min-width:0;flex:1;gap:3px}.pos-menu-info small{color:#9aa6b1;font-size:9px}.pos-menu-info strong{color:#344b60;font-size:11px;line-height:1.4}.pos-menu-info span{color:#ad762f;font-size:10px;font-weight:700}
.add-menu-btn{width:29px;height:29px;flex:0 0 29px;border:0;border-radius:8px;background:#234866;color:#fff;font-size:17px}.add-menu-btn:disabled{opacity:.35;cursor:not-allowed}
.order-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;min-height:260px;color:#91a0ad;text-align:center}.order-empty-icon{display:grid;place-items:center;width:55px;height:55px;border-radius:15px;background:#eff4f8;color:#6d8ca4;font-size:27px}.order-empty strong{color:#526a7e;font-size:13px}.order-empty span{font-size:10px}
.order-meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:11px;border-radius:8px;background:#f7f9fb;color:#8b99a6;font-size:10px}.order-meta strong{color:#526b80}
.order-lines{max-height:440px;overflow:auto}.order-line{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:10px;padding:13px 0;border-bottom:1px solid #edf1f4}.order-line.cancelled{opacity:.5}.order-line-main{display:flex;flex-direction:column;gap:4px;min-width:0}.order-line-main strong{color:#354d62;font-size:11px}.order-line-main small{color:#95a2ae;font-size:9px}.order-line-main .line-cancelled{color:#bb625a;font-weight:700}
.order-line-qty{display:flex;align-items:center;gap:7px;color:#425a70;font-size:11px}.order-line-qty button{width:23px;height:23px;border:1px solid #e0e7ed;border-radius:6px;background:#fff;color:#506b80}.order-line-price{display:flex;flex-direction:column;align-items:flex-end;gap:5px;color:#a06d2d;font-size:10px;font-weight:700;white-space:nowrap}
.order-total{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-top:1px solid #e9eef2;color:#62788a;font-size:12px}.order-total strong{color:#213e56;font-size:20px}.order-actions{display:flex;justify-content:space-between;gap:10px}.danger-outline{color:#b95d56;border-color:#efd8d5}.sent-note{padding:12px;background:#eff8f2;color:#397b57;border-radius:8px;font-size:10px;line-height:1.7}
.order-search-row{margin-bottom:12px;max-width:430px}.pos-pagination{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:15px;color:#8997a4;font-size:10px}.pos-pagination>div{display:flex;align-items:center;gap:8px}.pos-pagination select,.pos-pagination button{min-height:32px;width:auto;padding:5px 8px;border:1px solid #dce4ec;border-radius:7px;background:#fff;color:#526b80;font-size:10px}.pos-pagination button:disabled{opacity:.4;cursor:not-allowed}
.add-note-row{display:grid;grid-template-columns:100px 1fr;gap:10px;margin-top:15px}.add-note-row label{display:flex;flex-direction:column;gap:6px;color:#728395;font-size:10px;font-weight:700}.add-note-row input{min-height:36px}
@media(max-width:1250px){.pos-table-grid{grid-template-columns:repeat(6,minmax(0,1fr))}.pos-workspace{grid-template-columns:1fr}}
@media(max-width:900px){.pos-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.pos-table-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:600px){.pos-filters,.menu-picker-tools{grid-template-columns:1fr}.pos-table-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.pos-menu-grid{grid-template-columns:1fr}.add-note-row{grid-template-columns:1fr}.order-line{grid-template-columns:minmax(0,1fr) auto}.order-line-price{grid-column:2}.order-actions{flex-direction:column}}
</style>