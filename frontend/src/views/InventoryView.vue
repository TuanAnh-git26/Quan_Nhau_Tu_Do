<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'

const tabs = [
  { id: 'overview', label: 'Tổng quan kho' },
  { id: 'ingredients', label: 'Nguyên liệu' },
  { id: 'suppliers', label: 'Nhà cung cấp' },
  { id: 'purchase-orders', label: 'Đơn mua hàng' },
  { id: 'receipts', label: 'Nhập kho' },
  { id: 'issues', label: 'Xuất kho FIFO' },
  { id: 'stocktakes', label: 'Kiểm kê' },
  { id: 'recipes', label: 'Định mức món' }
]

const activeTab = ref('overview')
const branches = ref([])
const employees = ref([])
const ingredients = ref([])
const suppliers = ref([])
const stock = ref([])
const purchaseOrders = ref([])
const receipts = ref([])
const issues = ref([])
const stocktakes = ref([])
const recipes = ref([])
const dashboard = ref({})
const supplierHistory = ref(null)

const branchId = ref('')
const employeeId = ref('')
const search = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const modal = ref('')

const ingredientForm = ref(emptyIngredient())
const supplierForm = ref(emptySupplier())
const poForm = ref(emptyPO())
const receiptForm = ref(emptyReceipt())
const issueForm = ref(emptyIssue())
const stocktakeForm = ref(emptyStocktake())
const recipeForm = ref({ MaMon: '', items: [] })

function emptyIngredient() {
  return { TenNL: '', DonViTinh: 'Kg', TonToiThieu: 0, TrangThai: 'Dang su dung' }
}
function emptySupplier() {
  return { TenNCC: '', DiaChi: '', SDT: '', Email: '', TrangThai: 'Dang hop tac' }
}
function emptyPO() {
  return {
    MaPO: null, MaNCC: '', MaCS: branchId.value, MaNV: employeeId.value,
    DieuKienTT: '', NgayGiaoDuKien: '',
    items: [{ MaNL: '', SoLuong: 1, DonGia: 0 }]
  }
}
function emptyReceipt() {
  return {
    MaPO: '', MaNCC: '', MaCS: branchId.value, MaNV: employeeId.value,
    ChungTuNguon: '', items: []
  }
}
function emptyIssue() {
  return {
    MaCS: branchId.value, MaNV: employeeId.value,
    BoPhanNhan: 'Bep', items: [{ MaNL: '', SoLuongXuat: 1 }]
  }
}
function emptyStocktake() {
  return { MaCS: branchId.value, MaNV: employeeId.value, items: [] }
}

const activeEmployees = computed(() => employees.value.filter(item =>
  Number(item.MaCS) === Number(branchId.value)
))

const filteredIngredients = computed(() => {
  const keyword = search.value.trim().toLocaleLowerCase('vi-VN')
  if (!keyword) return ingredients.value
  return ingredients.value.filter(item =>
    String(item.TenNL || '').toLocaleLowerCase('vi-VN').includes(keyword) ||
    String(item.DonViTinh || '').toLocaleLowerCase('vi-VN').includes(keyword)
  )
})

const filteredSuppliers = computed(() => {
  const keyword = search.value.trim().toLocaleLowerCase('vi-VN')
  if (!keyword) return suppliers.value
  return suppliers.value.filter(item =>
    [item.TenNCC, item.SDT, item.Email, item.DiaChi]
      .some(value => String(value || '').toLocaleLowerCase('vi-VN').includes(keyword))
  )
})

const lowStockItems = computed(() => stock.value.filter(item =>
  item.TrangThai === 'Dang su dung' && Number(item.TonHienTai) < Number(item.TonToiThieu)
))

function money(value) {
  return new Intl.NumberFormat('vi-VN', {
    style: 'currency', currency: 'VND', maximumFractionDigits: 0
  }).format(Number(value || 0))
}
function quantity(value) {
  return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 3 }).format(Number(value || 0))
}
function dateTime(value) {
  if (!value) return '—'
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('vi-VN')
}
function poStatus(value) {
  return ({
    Nhap: 'Nháp',
    'Cho duyet': 'Chờ duyệt',
    'Da duyet': 'Đã duyệt',
    'Da gui': 'Đã gửi NCC',
    'Da nhan mot phan': 'Nhận một phần',
    'Da nhan du': 'Đã nhận đủ',
    'Tu choi': 'Từ chối'
  })[value] || value
}
function docStatus(value) {
  return ({
    'Nhap nhap': 'Nháp',
    'Da xac nhan': 'Đã xác nhận',
    'Da huy': 'Đã hủy',
    'Cho xac nhan': 'Chờ xác nhận',
    'Da xuat': 'Đã xuất',
    Nhap: 'Nháp'
  })[value] || value
}
function statusClass(value) {
  if (['Da xac nhan', 'Da xuat', 'Da nhan du', 'Da duyet', 'Dang hop tac', 'Dang su dung'].includes(value)) return 'tag done'
  if (['Da huy', 'Tu choi', 'Ngung hop tac', 'Ngung su dung'].includes(value)) return 'tag off'
  return 'tag planned'
}
function setMessage(message) {
  error.value = ''
  success.value = message || ''
}
function handleError(err, fallback) {
  const messages = err.response?.data?.errors
  error.value = messages
    ? Object.values(messages).flat().join(' ')
    : (err.response?.data?.message || fallback)
  success.value = ''
}

async function loadBase() {
  try {
    const branchResponse = await api.get('/branches')
    branches.value = branchResponse.data || []

    if (!branchId.value && branches.value.length) {
      branchId.value = String(branches.value[0].MaCS)
    }
  } catch (err) {
    handleError(err, 'Không tải được danh sách cơ sở.')
  }
}

async function loadAll() {
  if (!branchId.value) return
  loading.value = true
  error.value = ''
  try {
    const params = { MaCS: branchId.value }
    const [dash, ing, sup, stockRows, po, rec, iss, count, recipeRows, employeeRows] = await Promise.all([
      api.get('/inventory/dashboard', { params }),
      api.get('/ingredients', { params }),
      api.get('/suppliers'),
      api.get('/inventory/stock', { params }),
      api.get('/inventory/purchase-orders', { params }),
      api.get('/inventory/receipts', { params }),
      api.get('/inventory/issues', { params }),
      api.get('/inventory/stocktakes', { params }),
      api.get('/inventory/recipes'),
      api.get('/employees', { params })
    ])
    dashboard.value = dash.data || {}
    ingredients.value = ing.data || []
    suppliers.value = sup.data || []
    stock.value = stockRows.data || []
    purchaseOrders.value = po.data || []
    receipts.value = rec.data || []
    issues.value = iss.data || []
    stocktakes.value = count.data || []
    recipes.value = recipeRows.data || []
    employees.value = employeeRows.data || []
    if (!activeEmployees.value.some(item => Number(item.MaNV) === Number(employeeId.value))) {
      employeeId.value = activeEmployees.value.length ? String(activeEmployees.value[0].MaNV) : ''
    }
  } catch (err) {
    handleError(err, 'Không tải được dữ liệu kho. Kiểm tra API Đợt 4 và kết nối máy chủ.')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadBase()
  await loadAll()
})

watch(branchId, async (next, previous) => {
  if (!previous) return
  employeeId.value = ''
  await loadAll()
})

function openIngredient(item = null) {
  ingredientForm.value = item
    ? { MaNL: item.MaNL, TenNL: item.TenNL, DonViTinh: item.DonViTinh, TonToiThieu: item.TonToiThieu, TrangThai: item.TrangThai }
    : emptyIngredient()
  modal.value = 'ingredient'
}
async function saveIngredient() {
  saving.value = true
  try {
    const payload = { ...ingredientForm.value }
    if (payload.MaNL) await api.put(`/ingredients/${payload.MaNL}`, payload)
    else await api.post('/ingredients', payload)
    modal.value = ''
    setMessage('Đã lưu thông tin nguyên liệu.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể lưu nguyên liệu.') }
  finally { saving.value = false }
}
async function disableIngredient(item) {
  if (!confirm(`Ngừng sử dụng nguyên liệu “${item.TenNL}”? Lịch sử nhập xuất sẽ được giữ lại.`)) return
  try {
    await api.delete(`/ingredients/${item.MaNL}`)
    setMessage('Đã ngừng sử dụng nguyên liệu.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể cập nhật nguyên liệu.') }
}

function openSupplier(item = null) {
  supplierForm.value = item
    ? { MaNCC: item.MaNCC, TenNCC: item.TenNCC, DiaChi: item.DiaChi || '', SDT: item.SDT || '', Email: item.Email || '', TrangThai: item.TrangThai }
    : emptySupplier()
  modal.value = 'supplier'
}
async function saveSupplier() {
  saving.value = true
  try {
    const payload = { ...supplierForm.value }
    if (payload.MaNCC) await api.put(`/suppliers/${payload.MaNCC}`, payload)
    else await api.post('/suppliers', payload)
    modal.value = ''
    setMessage('Đã lưu thông tin nhà cung cấp.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể lưu nhà cung cấp.') }
  finally { saving.value = false }
}
async function disableSupplier(item) {
  if (!confirm(`Ngừng hợp tác với nhà cung cấp “${item.TenNCC}”?`)) return
  try {
    await api.delete(`/suppliers/${item.MaNCC}`)
    setMessage('Đã cập nhật trạng thái nhà cung cấp.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể cập nhật nhà cung cấp.') }
}

async function openSupplierHistory(item) {
  try {
    const { data } = await api.get(`/suppliers/${item.MaNCC}/history`)
    supplierHistory.value = data
    modal.value = 'supplier-history'
  } catch (err) { handleError(err, 'Không tải được lịch sử giao dịch nhà cung cấp.') }
}

function addPOLine() { poForm.value.items.push({ MaNL: '', SoLuong: 1, DonGia: 0 }) }
function removePOLine(index) { if (poForm.value.items.length > 1) poForm.value.items.splice(index, 1) }
function openPO() {
  poForm.value = emptyPO()
  poForm.value.MaCS = branchId.value
  poForm.value.MaNV = employeeId.value
  modal.value = 'purchase-order'
}
function openEditPO(item) {
  poForm.value = {
    MaPO: item.MaPO,
    MaNCC: String(item.MaNCC),
    MaCS: String(branchId.value),
    MaNV: String(item.MaNV),
    DieuKienTT: item.DieuKienTT || '',
    NgayGiaoDuKien: item.NgayGiaoDuKien || '',
    items: (item.items || []).map(line => ({
      MaNL: String(line.MaNL), SoLuong: Number(line.SoLuong), DonGia: Number(line.DonGia)
    }))
  }
  modal.value = 'purchase-order'
}
async function savePO() {
  saving.value = true
  try {
    const payload = {
      ...poForm.value,
      MaCS: Number(branchId.value),
      MaNV: Number(poForm.value.MaNV || employeeId.value),
      MaNCC: Number(poForm.value.MaNCC),
      items: poForm.value.items.map(item => ({
        MaNL: Number(item.MaNL), SoLuong: Number(item.SoLuong), DonGia: Number(item.DonGia)
      }))
    }
    if (payload.MaPO) await api.put(`/inventory/purchase-orders/${payload.MaPO}`, payload)
    else await api.post('/inventory/purchase-orders', payload)
    modal.value = ''
    setMessage(payload.MaPO ? 'Đã cập nhật đơn đặt hàng.' : 'Đã tạo đơn đặt hàng ở trạng thái Nháp.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể tạo đơn đặt hàng.') }
  finally { saving.value = false }
}
async function submitPO(item) {
  if (!confirm(`Trình duyệt đơn đặt hàng #${item.MaPO}?`)) return
  try {
    await api.post(`/inventory/purchase-orders/${item.MaPO}/submit`)
    setMessage('Đã chuyển PO sang trạng thái chờ duyệt.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể trình duyệt PO.') }
}
async function approvePO(item) {
  const approver = employeeId.value
  if (!approver) { error.value = 'Cơ sở chưa có nhân viên đang làm việc để ghi nhận người duyệt.'; return }
  if (!confirm(`Phê duyệt đơn đặt hàng #${item.MaPO}?`)) return
  try {
    await api.post(`/inventory/purchase-orders/${item.MaPO}/approve`, { MaNVDuyet: Number(approver) })
    setMessage('Đã phê duyệt đơn đặt hàng.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể phê duyệt PO.') }
}
async function rejectPO(item) {
  const reason = prompt(`Nhập lý do từ chối PO #${item.MaPO}:`)
  if (!reason) return
  if (!employeeId.value) { error.value = 'Cơ sở chưa có nhân viên để ghi nhận người duyệt.'; return }
  try {
    await api.post(`/inventory/purchase-orders/${item.MaPO}/reject`, {
      MaNVDuyet: Number(employeeId.value), LyDoTuChoi: reason
    })
    setMessage('Đã từ chối đơn đặt hàng.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể từ chối PO.') }
}
async function sendPO(item) {
  if (!confirm(`Ghi nhận đã gửi PO #${item.MaPO} cho nhà cung cấp?`)) return
  try {
    await api.post(`/inventory/purchase-orders/${item.MaPO}/send`)
    setMessage('Đã ghi nhận PO được gửi cho nhà cung cấp.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể cập nhật PO.') }
}

function openReceiptFromPO(item) {
  receiptForm.value = {
    MaPO: String(item.MaPO),
    MaNCC: String(item.MaNCC),
    MaCS: String(branchId.value),
    MaNV: String(employeeId.value),
    ChungTuNguon: '',
    items: (item.items || []).filter(line => Number(line.ConLai) > 0).map(line => ({
      MaNL: String(line.MaNL),
      SoLuongNhap: Number(line.ConLai),
      DonGia: Number(line.DonGia),
      HanSuDung: '',
      DatChatLuong: true
    }))
  }
  modal.value = 'receipt'
}

function openReceipt() {
  receiptForm.value = emptyReceipt()
  receiptForm.value.MaCS = branchId.value
  receiptForm.value.MaNV = employeeId.value
  receiptForm.value.items = [{ MaNL: '', SoLuongNhap: 1, DonGia: 0, HanSuDung: '', DatChatLuong: true }]
  modal.value = 'receipt'
}
function onReceiptPOChange() {
  const po = purchaseOrders.value.find(item => Number(item.MaPO) === Number(receiptForm.value.MaPO))
  if (!po) {
    receiptForm.value.MaNCC = ''
    receiptForm.value.items = [{ MaNL: '', SoLuongNhap: 1, DonGia: 0, HanSuDung: '', DatChatLuong: true }]
    return
  }
  receiptForm.value.MaNCC = String(po.MaNCC)
  receiptForm.value.items = (po.items || []).filter(item => Number(item.ConLai) > 0).map(item => ({
    MaNL: String(item.MaNL), SoLuongNhap: Number(item.ConLai),
    DonGia: Number(item.DonGia), HanSuDung: '', DatChatLuong: true
  }))
}
function addReceiptLine() { receiptForm.value.items.push({ MaNL: '', SoLuongNhap: 1, DonGia: 0, HanSuDung: '', DatChatLuong: true }) }
function removeReceiptLine(index) { if (receiptForm.value.items.length > 1) receiptForm.value.items.splice(index, 1) }
async function saveReceipt() {
  saving.value = true
  try {
    await api.post('/inventory/receipts', {
      ...receiptForm.value,
      MaPO: receiptForm.value.MaPO ? Number(receiptForm.value.MaPO) : null,
      MaNCC: Number(receiptForm.value.MaNCC),
      MaCS: Number(branchId.value),
      MaNV: Number(receiptForm.value.MaNV || employeeId.value),
      items: receiptForm.value.items.map(item => ({
        MaNL: Number(item.MaNL), SoLuongNhap: Number(item.SoLuongNhap),
        DonGia: Number(item.DonGia), HanSuDung: item.HanSuDung || null,
        DatChatLuong: Boolean(item.DatChatLuong)
      }))
    })
    modal.value = ''
    setMessage('Đã lập phiếu nhập nháp. Hãy xác nhận phiếu trong danh sách để cộng tồn kho.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể lập phiếu nhập kho.') }
  finally { saving.value = false }
}
async function confirmReceipt(item) {
  if (!confirm(`Xác nhận phiếu nhập #${item.MaPN}? Tồn kho sẽ được cập nhật.`)) return
  try {
    await api.post(`/inventory/receipts/${item.MaPN}/confirm`)
    setMessage('Đã xác nhận nhập kho và cập nhật tồn.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể xác nhận phiếu nhập.') }
}
async function cancelReceipt(item) {
  if (!confirm(`Hủy phiếu nhập nháp #${item.MaPN}?`)) return
  try {
    await api.post(`/inventory/receipts/${item.MaPN}/cancel`)
    setMessage('Đã hủy phiếu nhập nháp.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể hủy phiếu nhập.') }
}

function addIssueLine() { issueForm.value.items.push({ MaNL: '', SoLuongXuat: 1 }) }
function removeIssueLine(index) { if (issueForm.value.items.length > 1) issueForm.value.items.splice(index, 1) }
function openIssue() {
  issueForm.value = emptyIssue()
  issueForm.value.MaCS = branchId.value
  issueForm.value.MaNV = employeeId.value
  modal.value = 'issue'
}
async function saveIssue() {
  if (!confirm('Xác nhận xuất kho? Hệ thống sẽ tự động chọn lô theo FIFO và trừ tồn ngay.')) return
  saving.value = true
  try {
    await api.post('/inventory/issues', {
      ...issueForm.value,
      MaCS: Number(branchId.value),
      MaNV: Number(issueForm.value.MaNV || employeeId.value),
      items: issueForm.value.items.map(item => ({ MaNL: Number(item.MaNL), SoLuongXuat: Number(item.SoLuongXuat) }))
    })
    modal.value = ''
    setMessage('Đã xuất kho theo FIFO.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể xuất kho.') }
  finally { saving.value = false }
}

function openStocktake() {
  stocktakeForm.value = {
    MaCS: branchId.value,
    MaNV: employeeId.value,
    items: ingredients.value.filter(item => item.TrangThai === 'Dang su dung').map(item => ({
      MaNL: String(item.MaNL), SoLuongThucTe: Number(item.TonHienTai || 0),
      TenNL: item.TenNL, DonViTinh: item.DonViTinh, SoLuongSoSach: Number(item.TonHienTai || 0)
    }))
  }
  modal.value = 'stocktake'
}
async function saveStocktake() {
  saving.value = true
  try {
    await api.post('/inventory/stocktakes', {
      MaCS: Number(branchId.value),
      MaNV: Number(stocktakeForm.value.MaNV || employeeId.value),
      items: stocktakeForm.value.items.map(item => ({
        MaNL: Number(item.MaNL), SoLuongThucTe: Number(item.SoLuongThucTe)
      }))
    })
    modal.value = ''
    setMessage('Đã tạo phiếu kiểm kê nháp. Kiểm tra lại rồi xác nhận để điều chỉnh tồn.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể lập phiếu kiểm kê.') }
  finally { saving.value = false }
}
async function confirmStocktake(item) {
  if (!confirm(`Xác nhận phiếu kiểm kê #${item.MaKK}? Tồn kho sẽ được điều chỉnh theo số thực tế.`)) return
  try {
    await api.post(`/inventory/stocktakes/${item.MaKK}/confirm`)
    setMessage('Đã xác nhận kiểm kê và điều chỉnh tồn kho.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể xác nhận kiểm kê.') }
}

function openRecipe(item = null) {
  const selected = item || recipes.value[0]
  if (!selected) { error.value = 'Chưa có món ăn/đồ uống để thiết lập định mức.'; return }
  recipeForm.value = {
    MaMon: String(selected.MaMon),
    items: (selected.ingredients || []).map(line => ({
      MaNL: String(line.MaNL), DinhLuong: Number(line.DinhLuong), DonViTinh: line.DonViTinh
    }))
  }
  if (!recipeForm.value.items.length) recipeForm.value.items = [{ MaNL: '', DinhLuong: 1, DonViTinh: 'g' }]
  modal.value = 'recipe'
}
function addRecipeLine() { recipeForm.value.items.push({ MaNL: '', DinhLuong: 1, DonViTinh: 'g' }) }
function removeRecipeLine(index) { if (recipeForm.value.items.length > 1) recipeForm.value.items.splice(index, 1) }
function onRecipeIngredientChange(line) {
  const item = ingredients.value.find(row => Number(row.MaNL) === Number(line.MaNL))
  if (item) line.DonViTinh = item.DonViTinh
}
async function saveRecipe() {
  saving.value = true
  try {
    await api.put(`/inventory/recipes/${recipeForm.value.MaMon}`, {
      items: recipeForm.value.items.filter(item => item.MaNL).map(item => ({
        MaNL: Number(item.MaNL), DinhLuong: Number(item.DinhLuong), DonViTinh: item.DonViTinh
      }))
    })
    modal.value = ''
    setMessage('Đã lưu định mức nguyên liệu cho món.')
    await loadAll()
  } catch (err) { handleError(err, 'Không thể lưu định mức.') }
  finally { saving.value = false }
}
</script>

<template>
  <div class="page-heading inventory-heading">
    <div>
      <p class="eyebrow">QUẢN LÝ KHO & MUA HÀNG</p>
      <h1>Kho và nguyên liệu</h1>
      <p class="muted">Theo dõi nhập – xuất – tồn, nhà cung cấp, đơn mua hàng và định mức món.</p>
    </div>
    <div class="inventory-heading-tools">
      <label class="inventory-branch-select">
        <span>Cơ sở</span>
        <select v-model="branchId">
          <option v-for="branch in branches" :key="branch.MaCS" :value="String(branch.MaCS)">
            {{ branch.TenCS }}
          </option>
        </select>
      </label>
      <label class="inventory-branch-select">
        <span>Nhân viên thao tác</span>
        <select v-model="employeeId" :disabled="!activeEmployees.length">
          <option value="">Chọn nhân viên</option>
          <option v-for="employee in activeEmployees" :key="employee.MaNV" :value="String(employee.MaNV)">
            {{ employee.HoTen }}
          </option>
        </select>
      </label>
      <button class="secondary-btn" @click="loadAll" :disabled="loading">↻ Làm mới</button>
    </div>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <section class="inventory-tabs">
    <button
      v-for="tab in tabs"
      :key="tab.id"
      type="button"
      :class="['inventory-tab', { active: activeTab === tab.id }]"
      @click="activeTab = tab.id; search = ''"
    >{{ tab.label }}</button>
  </section>

  <template v-if="activeTab === 'overview'">
    <section class="stat-grid inventory-stats">
      <article class="stat-card"><span class="stat-icon blue">▤</span><div><span class="stat-label">Nguyên liệu đang dùng</span><strong>{{ dashboard.TongNguyenLieu || 0 }}</strong><small>Danh mục nguyên liệu</small></div></article>
      <article class="stat-card"><span class="stat-icon green">▦</span><div><span class="stat-label">Nguyên liệu có tồn</span><strong>{{ dashboard.SoNguyenLieuCoTon || 0 }}</strong><small>Số mặt hàng đang còn tồn</small></div></article>
      <article class="stat-card"><span class="stat-icon purple">₫</span><div><span class="stat-label">Giá trị tồn ước tính</span><strong>{{ money(dashboard.GiaTriTonKho) }}</strong><small>Theo giá nhập các lô còn lại</small></div></article>
      <article class="stat-card"><span class="stat-icon purple">!</span><div><span class="stat-label">Nguyên liệu cần nhập</span><strong>{{ dashboard.SoNguyenLieuCanNhap || 0 }}</strong><small>Dưới mức tồn tối thiểu</small></div></article>
      <article class="stat-card"><span class="stat-icon blue">♧</span><div><span class="stat-label">Nhà cung cấp</span><strong>{{ dashboard.SoNhaCungCap || 0 }}</strong><small>Đang hợp tác</small></div></article>
      <article class="stat-card"><span class="stat-icon purple">◷</span><div><span class="stat-label">PO chờ duyệt</span><strong>{{ dashboard.POChoDuyet || 0 }}</strong><small>Đang chờ phê duyệt</small></div></article>
      <article class="stat-card"><span class="stat-icon green">↓</span><div><span class="stat-label">Phiếu nhập tháng này</span><strong>{{ dashboard.PhieuNhapThang || 0 }}</strong><small>Đã xác nhận</small></div></article>
      <article class="stat-card"><span class="stat-icon blue">↑</span><div><span class="stat-label">Phiếu xuất tháng này</span><strong>{{ dashboard.PhieuXuatThang || 0 }}</strong><small>Đã xuất kho</small></div></article>
    </section>

    <section class="panel">
      <div class="panel-heading">
        <div><h2>Cảnh báo tồn kho thấp</h2><p class="panel-subtitle">Nguyên liệu có tồn thấp hơn mức tối thiểu</p></div>
        <button class="secondary-btn" @click="activeTab = 'ingredients'">Xem nguyên liệu</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Nguyên liệu</th><th>Đơn vị</th><th>Tồn hiện tại</th><th>Mức tối thiểu</th><th>Trạng thái</th></tr></thead>
          <tbody>
            <tr v-if="loading"><td colspan="5" class="empty-state">Đang tải dữ liệu…</td></tr>
            <tr v-else-if="!lowStockItems.length"><td colspan="5" class="empty-state">Không có nguyên liệu nào dưới mức tồn tối thiểu.</td></tr>
            <tr v-for="item in lowStockItems" :key="item.MaNL">
              <td><strong>{{ item.TenNL }}</strong></td><td>{{ item.DonViTinh }}</td>
              <td class="price-cell">{{ quantity(item.TonHienTai) }}</td><td>{{ quantity(item.TonToiThieu) }}</td>
              <td><span class="tag planned">Cần bổ sung</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="quick-grid inventory-quick-grid">
      <button class="quick-card" @click="openPO"><span class="quick-icon">＋</span><span><strong>Tạo đơn mua hàng</strong><small>Lập PO và trình duyệt nhà cung cấp</small></span><span class="quick-arrow">›</span></button>
      <button class="quick-card" @click="openReceipt"><span class="quick-icon">↓</span><span><strong>Lập phiếu nhập kho</strong><small>Ghi nhận hàng nhận và lô nguyên liệu</small></span><span class="quick-arrow">›</span></button>
      <button class="quick-card" @click="openIssue"><span class="quick-icon">↑</span><span><strong>Xuất kho FIFO</strong><small>Xuất lô cũ trước, loại lô hết hạn</small></span><span class="quick-arrow">›</span></button>
      <button class="quick-card" @click="openStocktake"><span class="quick-icon">✓</span><span><strong>Lập phiếu kiểm kê</strong><small>Đối chiếu tồn sổ sách và thực tế</small></span><span class="quick-arrow">›</span></button>
    </section>
  </template>

  <template v-else-if="activeTab === 'ingredients'">
    <section class="panel table-panel">
      <div class="panel-heading">
        <div><h2>Danh mục nguyên liệu</h2><p class="panel-subtitle">{{ ingredients.length }} nguyên liệu được ghi nhận</p></div>
        <div class="inventory-toolbar"><input v-model="search" placeholder="Tìm nguyên liệu…" /><button class="primary-btn" @click="openIngredient">＋ Thêm nguyên liệu</button></div>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>Mã</th><th>Tên nguyên liệu</th><th>Đơn vị</th><th>Tồn hiện tại</th><th>Tồn tối thiểu</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="7" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!filteredIngredients.length"><td colspan="7" class="empty-state">Chưa có nguyên liệu phù hợp.</td></tr>
          <tr v-for="item in filteredIngredients" :key="item.MaNL">
            <td class="code-cell">#{{ item.MaNL }}</td><td><strong>{{ item.TenNL }}</strong></td><td>{{ item.DonViTinh }}</td>
            <td :class="item.CanhBao ? 'price-cell inventory-low' : ''">{{ quantity(item.TonHienTai) }}</td>
            <td>{{ quantity(item.TonToiThieu) }}</td><td><span :class="statusClass(item.TrangThai)">{{ item.TrangThai === 'Dang su dung' ? 'Đang sử dụng' : 'Ngừng sử dụng' }}</span></td>
            <td><div class="actions"><button class="text-btn" @click="openIngredient(item)">Sửa</button><button v-if="item.TrangThai === 'Dang su dung'" class="text-btn danger-text" @click="disableIngredient(item)">Ngừng</button></div></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <template v-else-if="activeTab === 'suppliers'">
    <section class="panel table-panel">
      <div class="panel-heading">
        <div><h2>Nhà cung cấp</h2><p class="panel-subtitle">Thông tin liên hệ và trạng thái hợp tác</p></div>
        <div class="inventory-toolbar"><input v-model="search" placeholder="Tìm nhà cung cấp…" /><button class="primary-btn" @click="openSupplier">＋ Thêm nhà cung cấp</button></div>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>Mã</th><th>Nhà cung cấp</th><th>Số điện thoại</th><th>Email</th><th>Địa chỉ</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="7" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!filteredSuppliers.length"><td colspan="7" class="empty-state">Chưa có nhà cung cấp phù hợp.</td></tr>
          <tr v-for="item in filteredSuppliers" :key="item.MaNCC">
            <td class="code-cell">#{{ item.MaNCC }}</td><td><strong>{{ item.TenNCC }}</strong></td><td>{{ item.SDT || '—' }}</td><td>{{ item.Email || '—' }}</td><td>{{ item.DiaChi || '—' }}</td>
            <td><span :class="statusClass(item.TrangThai)">{{ item.TrangThai === 'Dang hop tac' ? 'Đang hợp tác' : 'Ngừng hợp tác' }}</span></td>
            <td><div class="actions"><button class="text-btn" @click="openSupplierHistory(item)">Lịch sử</button><button class="text-btn" @click="openSupplier(item)">Sửa</button><button v-if="item.TrangThai === 'Dang hop tac'" class="text-btn danger-text" @click="disableSupplier(item)">Ngừng</button></div></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <template v-else-if="activeTab === 'purchase-orders'">
    <section class="panel table-panel">
      <div class="panel-heading">
        <div><h2>Đơn đặt hàng (PO)</h2><p class="panel-subtitle">Nháp → Chờ duyệt → Đã duyệt → Đã gửi → Nhận một phần/đủ</p></div>
        <button class="primary-btn" @click="openPO">＋ Tạo đơn mua hàng</button>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>Mã PO</th><th>Ngày lập</th><th>Nhà cung cấp</th><th>Cơ sở</th><th>Tổng tiền</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="7" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!purchaseOrders.length"><td colspan="7" class="empty-state">Chưa có đơn đặt hàng.</td></tr>
          <tr v-for="item in purchaseOrders" :key="item.MaPO">
            <td class="code-cell">#PO-{{ item.MaPO }}</td><td>{{ dateTime(item.NgayLap) }}</td><td><strong>{{ item.TenNCC }}</strong></td><td>{{ item.TenCS }}</td><td class="price-cell">{{ money(item.TongTien) }}</td>
            <td><span :class="statusClass(item.TrangThai)">{{ poStatus(item.TrangThai) }}</span><small v-if="item.LyDoTuChoi" class="inventory-rejection">{{ item.LyDoTuChoi }}</small></td>
            <td><div class="actions inventory-actions">
              <button v-if="['Nhap','Tu choi'].includes(item.TrangThai)" class="text-btn" @click="openEditPO(item)">Sửa</button><button v-if="['Nhap','Tu choi'].includes(item.TrangThai)" class="text-btn" @click="submitPO(item)">Trình duyệt</button>
              <button v-if="item.TrangThai === 'Cho duyet'" class="text-btn" @click="approvePO(item)">Duyệt</button>
              <button v-if="item.TrangThai === 'Cho duyet'" class="text-btn danger-text" @click="rejectPO(item)">Từ chối</button>
              <button v-if="item.TrangThai === 'Da duyet'" class="text-btn" @click="sendPO(item)">Đã gửi NCC</button>
              <button v-if="['Da gui','Da nhan mot phan'].includes(item.TrangThai)" class="text-btn" @click="openReceiptFromPO(item)">Nhập hàng</button>
            </div></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <template v-else-if="activeTab === 'receipts'">
    <section class="panel table-panel">
      <div class="panel-heading"><div><h2>Phiếu nhập kho</h2><p class="panel-subtitle">Mỗi phiếu nhập đã xác nhận tạo các lô tồn kho</p></div><button class="primary-btn" @click="openReceipt">＋ Lập phiếu nhập</button></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Mã phiếu</th><th>Ngày nhập</th><th>Nhà cung cấp</th><th>PO</th><th>Người nhập</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="7" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!receipts.length"><td colspan="7" class="empty-state">Chưa có phiếu nhập kho.</td></tr>
          <tr v-for="item in receipts" :key="item.MaPN">
            <td class="code-cell">#PN-{{ item.MaPN }}</td><td>{{ dateTime(item.NgayNhap) }}</td><td><strong>{{ item.TenNCC }}</strong></td><td>{{ item.MaPO ? `#PO-${item.MaPO}` : 'Không có PO' }}</td><td>{{ item.NguoiNhap }}</td>
            <td><span :class="statusClass(item.TrangThai)">{{ docStatus(item.TrangThai) }}</span></td>
            <td><div class="actions"><button v-if="item.TrangThai === 'Nhap nhap'" class="text-btn" @click="confirmReceipt(item)">Xác nhận</button><button v-if="item.TrangThai === 'Nhap nhap'" class="text-btn danger-text" @click="cancelReceipt(item)">Hủy</button></div></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <template v-else-if="activeTab === 'issues'">
    <section class="panel table-panel">
      <div class="panel-heading"><div><h2>Phiếu xuất kho FIFO</h2><p class="panel-subtitle">Ưu tiên lô nhập trước; cùng ngày ưu tiên hạn sử dụng gần hơn; không xuất lô quá hạn</p></div><button class="primary-btn" @click="openIssue">＋ Tạo phiếu xuất</button></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Mã phiếu</th><th>Ngày xuất</th><th>Bộ phận nhận</th><th>Người lập</th><th>Người xác nhận</th><th>Trạng thái</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="6" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!issues.length"><td colspan="6" class="empty-state">Chưa có phiếu xuất kho.</td></tr>
          <tr v-for="item in issues" :key="item.MaPX">
            <td class="code-cell">#PX-{{ item.MaPX }}</td><td>{{ dateTime(item.NgayXuat) }}</td><td>{{ item.BoPhanNhan === 'Bep' ? 'Bếp' : 'Bar' }}</td><td>{{ item.NguoiLap }}</td><td>{{ item.NguoiDuyet || '—' }}</td><td><span :class="statusClass(item.TrangThai)">{{ docStatus(item.TrangThai) }}</span></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <template v-else-if="activeTab === 'stocktakes'">
    <section class="panel table-panel">
      <div class="panel-heading"><div><h2>Kiểm kê kho</h2><p class="panel-subtitle">So sánh số lượng sổ sách với số lượng thực tế và ghi nhận chênh lệch</p></div><button class="primary-btn" @click="openStocktake">＋ Lập phiếu kiểm kê</button></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Mã phiếu</th><th>Ngày kiểm kê</th><th>Cơ sở</th><th>Người lập</th><th>Số mặt hàng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="7" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!stocktakes.length"><td colspan="7" class="empty-state">Chưa có phiếu kiểm kê.</td></tr>
          <tr v-for="item in stocktakes" :key="item.MaKK">
            <td class="code-cell">#KK-{{ item.MaKK }}</td><td>{{ dateTime(item.NgayKK) }}</td><td>{{ item.TenCS }}</td><td>{{ item.NguoiLap }}</td><td>{{ item.items?.length || 0 }}</td>
            <td><span :class="statusClass(item.TrangThai)">{{ docStatus(item.TrangThai) }}</span></td>
            <td><button v-if="item.TrangThai === 'Nhap'" class="text-btn" @click="confirmStocktake(item)">Xác nhận & điều chỉnh</button></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <template v-else-if="activeTab === 'recipes'">
    <section class="panel table-panel">
      <div class="panel-heading"><div><h2>Công thức và định mức nguyên liệu</h2><p class="panel-subtitle">Định lượng nguyên liệu cần dùng cho một đơn vị món</p></div></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Món</th><th>Danh mục</th><th>Bộ phận</th><th>Số nguyên liệu</th><th>Định mức</th><th>Thao tác</th></tr></thead>
        <tbody>
          <tr v-if="loading"><td colspan="6" class="empty-state">Đang tải dữ liệu…</td></tr>
          <tr v-else-if="!recipes.length"><td colspan="6" class="empty-state">Chưa có món đang bán để thiết lập định mức.</td></tr>
          <tr v-for="item in recipes" :key="item.MaMon">
            <td><strong>{{ item.TenMon }}</strong></td><td>{{ item.TenDM }}</td><td>{{ item.NoiCheBien === 'Bep' ? 'Bếp' : 'Bar' }}</td><td>{{ item.ingredients?.length || 0 }}</td>
            <td>{{ item.ingredients?.map(line => `${line.TenNL}: ${quantity(line.DinhLuong)} ${line.DonViTinh}`).join(' · ') || 'Chưa thiết lập' }}</td>
            <td><button class="text-btn" @click="openRecipe(item)">Thiết lập</button></td>
          </tr>
        </tbody>
      </table></div>
    </section>
  </template>

  <div v-if="modal" class="modal-backdrop" @click.self="modal = ''">
    <form v-if="modal === 'ingredient'" class="modal-card inventory-modal" @submit.prevent="saveIngredient">
      <div class="modal-heading"><div><p class="eyebrow">DANH MỤC KHO</p><h2>{{ ingredientForm.MaNL ? 'Cập nhật nguyên liệu' : 'Thêm nguyên liệu' }}</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <label>Tên nguyên liệu <span>*</span><input v-model.trim="ingredientForm.TenNL" required maxlength="150" placeholder="Ví dụ: Thịt bò" /></label>
      <div class="form-grid"><label>Đơn vị tính <span>*</span><input v-model.trim="ingredientForm.DonViTinh" required maxlength="30" placeholder="Kg, g, lít, chai…" /></label><label>Mức tồn tối thiểu <span>*</span><input v-model.number="ingredientForm.TonToiThieu" type="number" min="0" step="0.001" required /></label></div>
      <label>Trạng thái<select v-model="ingredientForm.TrangThai"><option value="Dang su dung">Đang sử dụng</option><option value="Ngung su dung">Ngừng sử dụng</option></select></label>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving">{{ saving ? 'Đang lưu…' : 'Lưu nguyên liệu' }}</button></div>
    </form>

    <form v-else-if="modal === 'supplier'" class="modal-card inventory-modal" @submit.prevent="saveSupplier">
      <div class="modal-heading"><div><p class="eyebrow">ĐỐI TÁC MUA HÀNG</p><h2>{{ supplierForm.MaNCC ? 'Cập nhật nhà cung cấp' : 'Thêm nhà cung cấp' }}</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <label>Tên nhà cung cấp <span>*</span><input v-model.trim="supplierForm.TenNCC" required maxlength="180" /></label>
      <div class="form-grid"><label>Số điện thoại<input v-model.trim="supplierForm.SDT" maxlength="20" /></label><label>Email<input v-model.trim="supplierForm.Email" type="email" maxlength="150" /></label></div>
      <label>Địa chỉ<input v-model.trim="supplierForm.DiaChi" maxlength="255" /></label>
      <label>Trạng thái<select v-model="supplierForm.TrangThai"><option value="Dang hop tac">Đang hợp tác</option><option value="Ngung hop tac">Ngừng hợp tác</option></select></label>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving">{{ saving ? 'Đang lưu…' : 'Lưu nhà cung cấp' }}</button></div>
    </form>

    <form v-else-if="modal === 'purchase-order'" class="modal-card inventory-modal wide-modal" @submit.prevent="savePO">
      <div class="modal-heading"><div><p class="eyebrow">MUA HÀNG</p><h2>{{ poForm.MaPO ? `Cập nhật PO #${poForm.MaPO}` : 'Tạo đơn đặt hàng (PO)' }}</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <div class="form-grid"><label>Nhà cung cấp <span>*</span><select v-model="poForm.MaNCC" required><option value="">Chọn nhà cung cấp</option><option v-for="item in suppliers.filter(x => x.TrangThai === 'Dang hop tac')" :key="item.MaNCC" :value="String(item.MaNCC)">{{ item.TenNCC }}</option></select></label><label>Người lập <span>*</span><select v-model="poForm.MaNV" required><option value="">Chọn nhân viên</option><option v-for="item in activeEmployees" :key="item.MaNV" :value="String(item.MaNV)">{{ item.HoTen }}</option></select></label></div>
      <div class="form-grid"><label>Ngày giao dự kiến<input v-model="poForm.NgayGiaoDuKien" type="date" /></label><label>Điều kiện thanh toán<input v-model.trim="poForm.DieuKienTT" maxlength="180" placeholder="Ví dụ: Thanh toán khi nhận hàng" /></label></div>
      <div class="inventory-line-heading"><strong>Chi tiết nguyên liệu</strong><button type="button" class="text-btn" @click="addPOLine">＋ Thêm dòng</button></div>
      <div v-for="(line, index) in poForm.items" :key="index" class="inventory-form-line three-col">
        <label>Nguyên liệu<select v-model="line.MaNL" required><option value="">Chọn nguyên liệu</option><option v-for="item in ingredients.filter(x => x.TrangThai === 'Dang su dung')" :key="item.MaNL" :value="String(item.MaNL)">{{ item.TenNL }} ({{ item.DonViTinh }})</option></select></label>
        <label>Số lượng<input v-model.number="line.SoLuong" type="number" min="0.001" step="0.001" required /></label>
        <label>Đơn giá<input v-model.number="line.DonGia" type="number" min="0" step="100" required /></label>
        <button type="button" class="line-remove" @click="removePOLine(index)">×</button>
      </div>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving || !activeEmployees.length">{{ saving ? 'Đang lưu…' : (poForm.MaPO ? 'Lưu thay đổi' : 'Tạo PO nháp') }}</button></div>
      <p v-if="!activeEmployees.length" class="form-hint">Cơ sở chưa có nhân viên đang làm việc; cần bổ sung nhân viên trước khi lập chứng từ.</p>
    </form>

    <form v-else-if="modal === 'receipt'" class="modal-card inventory-modal wide-modal" @submit.prevent="saveReceipt">
      <div class="modal-heading"><div><p class="eyebrow">NHẬP KHO</p><h2>Lập phiếu nhập kho</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <label>Nhận hàng từ PO<select v-model="receiptForm.MaPO" @change="onReceiptPOChange"><option value="">Không có PO – nhập theo chứng từ</option><option v-for="item in purchaseOrders.filter(x => ['Da gui','Da nhan mot phan'].includes(x.TrangThai))" :key="item.MaPO" :value="String(item.MaPO)">#PO-{{ item.MaPO }} · {{ item.TenNCC }}</option></select></label>
      <div class="form-grid"><label>Nhà cung cấp <span>*</span><select v-model="receiptForm.MaNCC" required><option value="">Chọn nhà cung cấp</option><option v-for="item in suppliers.filter(x => x.TrangThai === 'Dang hop tac')" :key="item.MaNCC" :value="String(item.MaNCC)">{{ item.TenNCC }}</option></select></label><label>Người nhập <span>*</span><select v-model="receiptForm.MaNV" required><option value="">Chọn nhân viên</option><option v-for="item in activeEmployees" :key="item.MaNV" :value="String(item.MaNV)">{{ item.HoTen }}</option></select></label></div>
      <label>Chứng từ giao hàng<input v-model.trim="receiptForm.ChungTuNguon" maxlength="255" placeholder="Số hóa đơn / phiếu giao hàng (bắt buộc nếu không có PO)" /></label>
      <div class="inventory-line-heading"><strong>Hàng thực nhận</strong><button type="button" class="text-btn" @click="addReceiptLine">＋ Thêm dòng</button></div>
      <div v-for="(line, index) in receiptForm.items" :key="index" class="inventory-form-line receipt-line">
        <label>Nguyên liệu<select v-model="line.MaNL" required><option value="">Chọn nguyên liệu</option><option v-for="item in ingredients.filter(x => x.TrangThai === 'Dang su dung')" :key="item.MaNL" :value="String(item.MaNL)">{{ item.TenNL }}</option></select></label>
        <label>Số lượng nhận<input v-model.number="line.SoLuongNhap" type="number" min="0.001" step="0.001" required /></label>
        <label>Đơn giá<input v-model.number="line.DonGia" type="number" min="0" step="100" required /></label>
        <label>Hạn sử dụng<input v-model="line.HanSuDung" type="date" /></label>
        <label class="quality-check"><input v-model="line.DatChatLuong" type="checkbox" /> Đạt chất lượng</label>
        <button type="button" class="line-remove" @click="removeReceiptLine(index)">×</button>
      </div>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving || !activeEmployees.length">{{ saving ? 'Đang lưu…' : 'Lập phiếu nhập nháp' }}</button></div>
    </form>

    <form v-else-if="modal === 'issue'" class="modal-card inventory-modal wide-modal" @submit.prevent="saveIssue">
      <div class="modal-heading"><div><p class="eyebrow">XUẤT KHO</p><h2>Xuất nguyên liệu theo FIFO</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <div class="form-grid"><label>Bộ phận nhận <select v-model="issueForm.BoPhanNhan"><option value="Bep">Bếp</option><option value="Bar">Bar</option></select></label><label>Người lập <select v-model="issueForm.MaNV" required><option value="">Chọn nhân viên</option><option v-for="item in activeEmployees" :key="item.MaNV" :value="String(item.MaNV)">{{ item.HoTen }}</option></select></label></div>
      <div class="inventory-line-heading"><strong>Nguyên liệu cần xuất</strong><button type="button" class="text-btn" @click="addIssueLine">＋ Thêm dòng</button></div>
      <div v-for="(line, index) in issueForm.items" :key="index" class="inventory-form-line two-col">
        <label>Nguyên liệu<select v-model="line.MaNL" required><option value="">Chọn nguyên liệu</option><option v-for="item in stock.filter(x => x.TrangThai === 'Dang su dung')" :key="item.MaNL" :value="String(item.MaNL)">{{ item.TenNL }} · tồn {{ quantity(item.TonHienTai) }} {{ item.DonViTinh }}</option></select></label>
        <label>Số lượng xuất<input v-model.number="line.SoLuongXuat" type="number" min="0.001" step="0.001" required /></label>
        <button type="button" class="line-remove" @click="removeIssueLine(index)">×</button>
      </div>
      <p class="form-hint">Phiếu xuất được xác nhận ngay khi lưu. Hệ thống khóa các lô, loại lô quá hạn và phân bổ số lượng theo FIFO.</p>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving || !activeEmployees.length">{{ saving ? 'Đang xử lý…' : 'Xác nhận xuất kho' }}</button></div>
    </form>

    <form v-else-if="modal === 'stocktake'" class="modal-card inventory-modal wide-modal" @submit.prevent="saveStocktake">
      <div class="modal-heading"><div><p class="eyebrow">KIỂM KÊ KHO</p><h2>Lập phiếu kiểm kê</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <label>Người kiểm kê <select v-model="stocktakeForm.MaNV" required><option value="">Chọn nhân viên</option><option v-for="item in activeEmployees" :key="item.MaNV" :value="String(item.MaNV)">{{ item.HoTen }}</option></select></label>
      <div class="table-wrap stocktake-table"><table><thead><tr><th>Nguyên liệu</th><th>Tồn sổ sách</th><th>Thực tế kiểm kê</th><th>Đơn vị</th></tr></thead><tbody>
        <tr v-for="line in stocktakeForm.items" :key="line.MaNL"><td><strong>{{ line.TenNL }}</strong></td><td>{{ quantity(line.SoLuongSoSach) }}</td><td><input v-model.number="line.SoLuongThucTe" type="number" min="0" step="0.001" /></td><td>{{ line.DonViTinh }}</td></tr>
      </tbody></table></div>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving || !activeEmployees.length">{{ saving ? 'Đang lưu…' : 'Tạo phiếu kiểm kê nháp' }}</button></div>
    </form>

    <section v-else-if="modal === 'supplier-history'" class="modal-card inventory-modal wide-modal">
      <div class="modal-heading"><div><p class="eyebrow">LỊCH SỬ GIAO DỊCH</p><h2>{{ supplierHistory?.supplier?.TenNCC || 'Nhà cung cấp' }}</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <h3 class="history-title">Đơn đặt hàng</h3>
      <div class="table-wrap"><table><thead><tr><th>Mã PO</th><th>Ngày lập</th><th>Cơ sở</th><th>Trạng thái</th></tr></thead><tbody>
        <tr v-if="!supplierHistory?.purchaseOrders?.length"><td colspan="4" class="empty-state">Chưa có đơn đặt hàng.</td></tr>
        <tr v-for="po in supplierHistory?.purchaseOrders || []" :key="po.MaPO"><td>#PO-{{ po.MaPO }}</td><td>{{ dateTime(po.NgayLap) }}</td><td>{{ po.TenCS }}</td><td>{{ poStatus(po.TrangThai) }}</td></tr>
      </tbody></table></div>
      <h3 class="history-title">Phiếu nhập kho</h3>
      <div class="table-wrap"><table><thead><tr><th>Mã phiếu</th><th>Ngày nhập</th><th>PO</th><th>Cơ sở</th><th>Trạng thái</th></tr></thead><tbody>
        <tr v-if="!supplierHistory?.receipts?.length"><td colspan="5" class="empty-state">Chưa có phiếu nhập.</td></tr>
        <tr v-for="receipt in supplierHistory?.receipts || []" :key="receipt.MaPN"><td>#PN-{{ receipt.MaPN }}</td><td>{{ dateTime(receipt.NgayNhap) }}</td><td>{{ receipt.MaPO ? `#PO-${receipt.MaPO}` : 'Không có PO' }}</td><td>{{ receipt.TenCS }}</td><td>{{ docStatus(receipt.TrangThai) }}</td></tr>
      </tbody></table></div>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Đóng</button></div>
    </section>

    <form v-else-if="modal === 'recipe'" class="modal-card inventory-modal wide-modal" @submit.prevent="saveRecipe">
      <div class="modal-heading"><div><p class="eyebrow">CÔNG THỨC MÓN</p><h2>Thiết lập định mức nguyên liệu</h2></div><button type="button" class="close-btn" @click="modal = ''">×</button></div>
      <label>Chọn món <select v-model="recipeForm.MaMon" @change="openRecipe(recipes.find(x => Number(x.MaMon) === Number(recipeForm.MaMon)))"><option v-for="item in recipes" :key="item.MaMon" :value="String(item.MaMon)">{{ item.TenMon }}</option></select></label>
      <div class="inventory-line-heading"><strong>Nguyên liệu cho một đơn vị món</strong><button type="button" class="text-btn" @click="addRecipeLine">＋ Thêm dòng</button></div>
      <div v-for="(line, index) in recipeForm.items" :key="index" class="inventory-form-line recipe-line">
        <label>Nguyên liệu<select v-model="line.MaNL" @change="onRecipeIngredientChange(line)" required><option value="">Chọn nguyên liệu</option><option v-for="item in ingredients.filter(x => x.TrangThai === 'Dang su dung')" :key="item.MaNL" :value="String(item.MaNL)">{{ item.TenNL }}</option></select></label>
        <label>Định lượng<input v-model.number="line.DinhLuong" type="number" min="0.001" step="0.001" required /></label>
        <label>Đơn vị<input v-model.trim="line.DonViTinh" maxlength="30" required /></label>
        <button type="button" class="line-remove" @click="removeRecipeLine(index)">×</button>
      </div>
      <div class="modal-actions"><button type="button" class="secondary-btn" @click="modal = ''">Hủy</button><button class="primary-btn" type="submit" :disabled="saving">{{ saving ? 'Đang lưu…' : 'Lưu định mức' }}</button></div>
    </form>
  </div>
</template>


<style scoped>
.inventory-heading-tools,
.inventory-toolbar,
.inventory-tabs,
.inventory-line-heading,
.inventory-form-line,
.inventory-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.inventory-heading-tools { justify-content: flex-end; }
.inventory-branch-select { display: flex; align-items: center; gap: 9px; color: #718397; font-size: 11px; white-space: nowrap; }
.inventory-branch-select select { min-width: 190px; width: auto; }
.inventory-tabs { flex-wrap: wrap; margin: 0 0 19px; padding: 5px; gap: 5px; border: 1px solid #e5eaf0; border-radius: 10px; background: #fff; }
.inventory-tab { min-height: 36px; padding: 8px 13px; border: 0; border-radius: 7px; background: transparent; color: #718397; font-size: 11px; font-weight: 700; white-space: nowrap; }
.inventory-tab:hover { background: #f4f7fa; color: #36536b; }
.inventory-tab.active { background: #234866; color: #fff; }
.inventory-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.inventory-quick-grid { margin-top: 18px; }
.inventory-toolbar input { width: 210px; }
.inventory-low { color: #c6534c !important; }
.inventory-rejection { display: block; max-width: 220px; margin-top: 5px; color: #c6534c; font-size: 10px; white-space: normal; }
.danger-text { color: #bd514a !important; }
.text-btn { padding: 4px 3px; border: 0; background: transparent; color: #315d7c; font-size: 11px; font-weight: 700; white-space: nowrap; }
.text-btn:hover { text-decoration: underline; }
.inventory-modal { width: min(560px, calc(100vw - 28px)); max-height: calc(100vh - 40px); overflow-y: auto; }
.wide-modal { width: min(850px, calc(100vw - 28px)); }
.inventory-modal label { display: block; margin: 0 0 13px; color: #61778b; font-size: 11px; font-weight: 700; }
.inventory-modal label > span { color: #c6534c; }
.inventory-modal label input,
.inventory-modal label select { display: block; margin-top: 7px; }
.inventory-modal .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px; }
.history-title { margin: 17px 0 8px; color: #324b61; font-size: 12px; }
.inventory-line-heading { justify-content: space-between; margin: 18px 0 10px; color: #324b61; font-size: 12px; }
.inventory-form-line { align-items: flex-end; margin-bottom: 10px; padding: 11px; border: 1px solid #e8edf2; border-radius: 9px; background: #fafbfd; }
.inventory-form-line label { flex: 1; min-width: 0; margin: 0; }
.inventory-form-line.three-col label { width: 30%; }
.inventory-form-line.two-col label { width: 46%; }
.inventory-form-line.receipt-line label { width: 18%; }
.inventory-form-line.recipe-line label { width: 28%; }
.line-remove { width: 30px; height: 35px; flex: 0 0 30px; border: 1px solid #efd8d6; border-radius: 7px; background: #fff; color: #bd514a; font-size: 19px; }
.quality-check { display: flex !important; align-items: center; gap: 7px; padding-bottom: 8px; white-space: nowrap; }
.quality-check input { width: 15px; min-height: 15px; margin: 0 !important; }
.form-hint { margin: 10px 0 0; color: #8998a7; font-size: 10px; line-height: 1.6; }
.stocktake-table { max-height: 360px; overflow: auto; }
.stocktake-table input { min-width: 110px; }
@media (max-width: 1000px) {
  .inventory-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .inventory-heading-tools { flex-wrap: wrap; justify-content: flex-start; }
}
@media (max-width: 650px) {
  .inventory-heading-tools, .inventory-toolbar { width: 100%; flex-wrap: wrap; }
  .inventory-branch-select { width: 100%; align-items: flex-start; flex-direction: column; }
  .inventory-branch-select select, .inventory-toolbar input { width: 100%; }
  .inventory-stats { grid-template-columns: 1fr; }
  .inventory-modal .form-grid { grid-template-columns: 1fr; gap: 0; }
  .inventory-form-line { align-items: stretch; flex-direction: column; }
  .inventory-form-line.three-col label,
  .inventory-form-line.two-col label,
  .inventory-form-line.receipt-line label,
  .inventory-form-line.recipe-line label { width: 100%; }
  .line-remove { align-self: flex-end; }
}
</style>
