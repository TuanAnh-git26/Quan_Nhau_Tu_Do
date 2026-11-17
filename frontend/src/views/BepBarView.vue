<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api from '../services/api'

const props = defineProps({ user: { type: Object, required: true } })
const isAdmin = computed(() => props.user?.TenVaiTro === 'Quan tri he thong')
const canChangeDepartment = isAdmin
const branches = ref([])
const selectedBranch = ref(String(props.user?.MaCS || ''))
const department = ref(props.user?.TenVaiTro === 'Bar' ? 'Bar' : 'Bep')
const ingredients = ref([])
const stockRequests = ref([])
const requestForm = ref({ items: [{ MaNL: '', SoLuongDeNghi: 1 }] })
const requestBusy = ref(false)
const items = ref([])
const notifications = ref([])
const search = ref('')
const selectedStatus = ref('')
const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const loading = ref(false)
const refreshing = ref(false)
const savingIds = ref([])
const error = ref('')
const success = ref('')
const lastUpdated = ref(null)
let refreshTimer = null
let inFlight = false

const filteredItems = computed(() => {
  const key = search.value.trim().toLocaleLowerCase('vi-VN')
  return items.value.filter(item => !key ||
    `${item.SoBan} ${item.TenKV} ${item.TenMon} ${item.MaOrder} ${item.NhanVienPhuTrach}`
      .toLocaleLowerCase('vi-VN').includes(key)
  )
})
const newItems = computed(() => filteredItems.value.filter(x => ['Cho che bien', 'Da nhan'].includes(x.TrangThaiMon)))
const cookingItems = computed(() => filteredItems.value.filter(x => x.TrangThaiMon === 'Dang che bien'))
const completedItems = computed(() => filteredItems.value.filter(x => x.TrangThaiMon === 'Hoan tat'))
const pendingCount = computed(() => items.value.filter(x => ['Cho che bien', 'Da nhan'].includes(x.TrangThaiMon)).length)
const cookingCount = computed(() => items.value.filter(x => x.TrangThaiMon === 'Dang che bien').length)
const completedCount = computed(() => items.value.filter(x => x.TrangThaiMon === 'Hoan tat').length)
const unreadNotificationCount = computed(() => notifications.value.filter(x => !x.DaDoc).length)

function readError(e, fallback) {
  if (e.response?.status === 409) return e.response?.data?.message || 'Món vừa được thiết bị khác cập nhật. Vui lòng tải lại.'
  const errors = e.response?.data?.errors
  return errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || fallback)
}
function dateText(value) {
  if (!value) return '—'
  const normalized = String(value).replace(' ', 'T')
  const date = new Date(normalized)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('vi-VN')
}
function statusLabel(value) {
  return ({
    'Cho che bien': 'Chờ chế biến',
    'Da nhan': 'Đã nhận',
    'Dang che bien': 'Đang chế biến',
    'Hoan tat': 'Hoàn tất',
    'Da huy': 'Đã hủy'
  })[value] || value
}
function receivedText(item) {
  return item.ThoiGianNhan ? `Nhận lúc ${dateText(item.ThoiGianNhan)}` : `Order lúc ${dateText(item.ThoiGianTao)}`
}
function nextStatus(item) {
  return ({ 'Cho che bien': 'Da nhan', 'Da nhan': 'Dang che bien', 'Dang che bien': 'Hoan tat' })[item.TrangThaiMon] || null
}
function actionLabel(item) {
  return ({ 'Cho che bien': 'Nhận món', 'Da nhan': 'Bắt đầu chế biến', 'Dang che bien': 'Hoàn tất món' })[item.TrangThaiMon] || ''
}

async function loadItems(silent = false) {
  if (!selectedBranch.value) {
    items.value = []
    notifications.value = []
    return
  }
  if (silent && inFlight) return
  inFlight = true
  if (silent) refreshing.value = true
  else loading.value = true
  try {
    const [boardResponse, notificationResponse] = await Promise.all([
      api.get('/bep-bar/orders', { params: { MaCS: selectedBranch.value, BoPhan: department.value, TrangThaiMon: selectedStatus.value || undefined, page: page.value, per_page: perPage.value } }),
      api.get('/service-notifications', { params: { MaCS: selectedBranch.value } })
    ])
    items.value = Array.isArray(boardResponse.data) ? boardResponse.data : (boardResponse.data?.data || [])
    total.value = Number(boardResponse.data?.total ?? items.value.length)
    notifications.value = Array.isArray(notificationResponse.data) ? notificationResponse.data : []
    lastUpdated.value = new Date()
    if (error.value && silent) error.value = ''
  } catch (e) {
    if ([401, 419].includes(e?.response?.status)) stopPolling()
    if (!silent || !items.value.length) error.value = readError(e, 'Không thể tải danh sách món của Bếp/Bar.')
  } finally {
    inFlight = false
    loading.value = false
    refreshing.value = false
  }
}

async function loadInitial() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/branches')
    branches.value = Array.isArray(data) ? data : []
    if (branches.value.length) {
      if (!isAdmin.value && props.user?.MaCS) {
        branches.value = branches.value.filter(x => Number(x.MaCS) === Number(props.user.MaCS))
      }
      selectedBranch.value = String(props.user?.MaCS || branches.value[0].MaCS)
      await Promise.all([loadItems(true), loadStockRequests()])
    } else {
      error.value = 'Chưa có cơ sở đang hoạt động. Vui lòng tạo cơ sở trước.'
    }
  } catch (e) {
    error.value = readError(e, 'Không thể tải danh sách cơ sở.')
  } finally {
    loading.value = false
  }
}

async function markNotificationRead(item) {
  try {
    await api.put(`/service-notifications/${item.MaTB || item.MaCTOrder}/read`, { MaCS: Number(selectedBranch.value) })
    item.DaDoc = true
    notifications.value = [...notifications.value]
  } catch (e) {
    error.value = readError(e, 'Không thể cập nhật thông báo.')
  }
}

async function changeDepartment(value) {
  if (!isAdmin.value || department.value === value) return
  department.value = value
  search.value = ''
  selectedStatus.value = ''
  page.value = 1
  success.value = ''
  await Promise.all([loadItems(), loadStockRequests()])
}

async function loadStockRequests() {
  if (!selectedBranch.value) return
  try {
    const [requestResponse, ingredientResponse] = await Promise.all([
      api.get('/bep-bar/stock-requests', { params: { MaCS: Number(selectedBranch.value), BoPhan: department.value } }),
      api.get('/ingredients', { params: { MaCS: Number(selectedBranch.value) } }),
    ])
    stockRequests.value = Array.isArray(requestResponse.data) ? requestResponse.data : []
    ingredients.value = (Array.isArray(ingredientResponse.data) ? ingredientResponse.data : []).filter(x => x.TrangThai === 'Dang su dung')
  } catch (e) {
    error.value = readError(e, 'Không tải được danh sách nguyên liệu hoặc đề nghị xuất kho.')
  }
}

function addRequestLine() { requestForm.value.items.push({ MaNL: '', SoLuongDeNghi: 1 }) }
function removeRequestLine(index) { if (requestForm.value.items.length > 1) requestForm.value.items.splice(index, 1) }
async function submitStockRequest() {
  if (requestBusy.value || !selectedBranch.value || !requestForm.value.items.length) return
  if (requestForm.value.items.some(x => !x.MaNL || Number(x.SoLuongDeNghi) <= 0)) {
    error.value = 'Vui lòng chọn nguyên liệu và nhập số lượng đề nghị lớn hơn 0.'
    return
  }
  requestBusy.value = true; error.value = ''; success.value = ''
  try {
    const { data } = await api.post('/bep-bar/stock-requests', {
      MaCS: Number(selectedBranch.value), BoPhanNhan: department.value,
      items: requestForm.value.items.map(x => ({ MaNL: Number(x.MaNL), SoLuongDeNghi: Number(x.SoLuongDeNghi) })),
    })
    success.value = data.message || 'Đã gửi đề nghị xuất kho.'
    requestForm.value = { items: [{ MaNL: '', SoLuongDeNghi: 1 }] }
    await loadStockRequests()
  } catch (e) { error.value = readError(e, 'Không thể gửi đề nghị xuất kho.') }
  finally { requestBusy.value = false }
}

async function updateItem(item) {
  const target = nextStatus(item)
  if (!target || savingIds.value.includes(item.MaCTOrder)) return
  savingIds.value = [...savingIds.value, item.MaCTOrder]
  error.value = ''
  success.value = ''
  try {
    const { data } = await api.patch(`/bep-bar/items/${item.MaCTOrder}/status`, {
      MaCS: Number(selectedBranch.value),
      BoPhan: department.value,
      TrangThaiHienTai: item.TrangThaiMon,
      TrangThaiMon: target
    })
    if (data.ThongBaoPhucVu) {
      success.value = `${data.message} Người nhận: ${data.ThongBaoPhucVu.NhanVienPhuTrach} – bàn ${data.ThongBaoPhucVu.SoBan}.`
    } else {
      success.value = data.message || 'Đã cập nhật trạng thái món.'
    }
    await loadItems(true)
  } catch (e) {
    error.value = readError(e, 'Không thể cập nhật trạng thái món.')
    await loadItems(true)
  } finally {
    savingIds.value = savingIds.value.filter(id => id !== item.MaCTOrder)
  }
}

function startPolling() {
  stopPolling()
  refreshTimer = window.setInterval(() => {
    if (!document.hidden) void loadItems(true)
  }, 5000)
}
function stopPolling() {
  if (refreshTimer) window.clearInterval(refreshTimer)
  refreshTimer = null
}
function onVisibility() {
  if (!document.hidden) void loadItems(true)
}

onMounted(async () => {
  await loadInitial()
  startPolling()
  document.addEventListener('visibilitychange', onVisibility)
})
onUnmounted(() => {
  stopPolling()
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<template>
  <div class="ui-view ui-view--bep-bar">
  <section class="kitchen-page">
    <div class="kitchen-heading">
      <div>
        <div class="kitchen-eyebrow">PHÂN HỆ 3 · VẬN HÀNH CHẾ BIẾN</div>
        <h1>Bếp &amp; Bar</h1>
        <p>Theo dõi món đã gửi, tiếp nhận và cập nhật tiến độ chế biến theo từng bộ phận.</p>
      </div>
      <div class="kitchen-heading-actions">
        <label class="branch-field">
          <span>Cơ sở</span>
          <select v-model="selectedBranch" :disabled="!isAdmin" @change="Promise.all([loadItems(), loadStockRequests()])">
            <option v-for="branch in branches" :key="branch.MaCS" :value="String(branch.MaCS)">
              {{ branch.TenCS }}
            </option>
          </select>
        </label>
        <button class="refresh-button" :disabled="loading || refreshing" @click="loadItems()">
          <span :class="{ spinning: refreshing || loading }">↻</span>
          Làm mới
        </button>
      </div>
    </div>

    <div v-if="canChangeDepartment" class="department-switch" role="tablist" aria-label="Chọn bộ phận chế biến">
      <button :class="{ active: department === 'Bep' }" @click="changeDepartment('Bep')">
        <span class="department-icon">♨</span>
        Bếp
        <span class="department-count">{{ pendingCount + cookingCount }}</span>
      </button>
      <button :class="{ active: department === 'Bar' }" @click="changeDepartment('Bar')">
        <span class="department-icon">◈</span>
        Bar
        <span class="department-count">{{ pendingCount + cookingCount }}</span>
      </button>
    </div>

    <div class="kitchen-stats">
      <div class="kitchen-stat">
        <span class="stat-icon stat-new">◷</span>
        <div><strong>{{ pendingCount }}</strong><small>Món mới</small></div>
      </div>
      <div class="kitchen-stat">
        <span class="stat-icon stat-cooking">♨</span>
        <div><strong>{{ cookingCount }}</strong><small>Đang chế biến</small></div>
      </div>
      <div class="kitchen-stat">
        <span class="stat-icon stat-complete">✓</span>
        <div><strong>{{ completedCount }}</strong><small>Hoàn tất hôm nay</small></div>
      </div>
      <div class="kitchen-stat kitchen-refresh-stat">
        <span class="live-dot"></span>
        <div><strong>Tự làm mới</strong><small>5 giây / lần</small></div>
      </div>
    </div>

    <div v-if="error" class="kitchen-alert error-alert">{{ error }}</div>
    <div v-if="success" class="kitchen-alert success-alert">
      <span>✓</span>{{ success }}
      <button aria-label="Đóng thông báo" @click="success = ''">×</button>
    </div>

    <div class="kitchen-toolbar">
      <div class="board-title">
        <h2>Bảng chế biến {{ department === 'Bep' ? 'Bếp' : 'Bar' }}</h2>
        <span v-if="lastUpdated">Cập nhật {{ lastUpdated.toLocaleTimeString('vi-VN') }}</span>
      </div>
      <div class="kitchen-filters">
        <select v-model="selectedStatus" aria-label="Lọc trạng thái món" @change="page = 1; loadItems()">
          <option value="">Đang xử lý và hoàn tất hôm nay</option>
          <option value="Cho che bien">Chờ chế biến</option>
          <option value="Da nhan">Đã nhận</option>
          <option value="Dang che bien">Đang chế biến</option>
          <option value="Hoan tat">Hoàn tất</option>
          <option value="Tat ca">Tất cả trạng thái</option>
        </select>
        <label class="kitchen-search">
          <span>⌕</span>
          <input v-model="search" type="search" placeholder="Tìm theo bàn, món, order..." />
        </label>
      </div>
    </div>

    <div v-if="loading && !items.length" class="kitchen-loading">
      <span class="loading-spinner"></span>
      Đang tải danh sách món...
    </div>

    <div v-else class="kitchen-board">
      <section class="board-column column-new">
        <header class="column-header">
          <div><span class="column-dot"></span><h3>Mới</h3></div>
          <span class="column-count">{{ newItems.length }}</span>
        </header>
        <div v-if="newItems.length" class="ticket-list">
          <article v-for="item in newItems" :key="item.MaCTOrder" class="kitchen-ticket">
            <div class="ticket-topline">
              <strong>Bàn {{ item.SoBan }}</strong>
              <span>Order #{{ item.MaOrder }}</span>
            </div>
            <div class="ticket-area">{{ item.TenKV }}</div>
            <h4>{{ item.TenMon }}</h4>
            <div class="ticket-quantity">× {{ item.SoLuong }} <small>{{ item.DonViTinh }}</small></div>
            <div v-if="item.GhiChu" class="ticket-note"><b>Ghi chú:</b> {{ item.GhiChu }}</div>
            <div class="ticket-details">
              <span>Phục vụ: {{ item.NhanVienPhuTrach }}</span>
              <span>{{ receivedText(item) }}</span>
            </div>
            <div class="ticket-bottom">
              <span class="ticket-status" :class="item.TrangThaiMon === 'Da nhan' ? 'status-accepted' : 'status-waiting'">
                {{ statusLabel(item.TrangThaiMon) }}
              </span>
              <button class="ticket-action action-new" :disabled="savingIds.includes(item.MaCTOrder)" @click="updateItem(item)">
                {{ savingIds.includes(item.MaCTOrder) ? 'Đang lưu...' : actionLabel(item) }}
              </button>
            </div>
          </article>
        </div>
        <div v-else class="column-empty"><span>✓</span><strong>Không có món mới</strong><small>Món được gửi đến sẽ xuất hiện tại đây.</small></div>
      </section>

      <section class="board-column column-cooking">
        <header class="column-header">
          <div><span class="column-dot"></span><h3>Đang chế biến</h3></div>
          <span class="column-count">{{ cookingItems.length }}</span>
        </header>
        <div v-if="cookingItems.length" class="ticket-list">
          <article v-for="item in cookingItems" :key="item.MaCTOrder" class="kitchen-ticket">
            <div class="ticket-topline">
              <strong>Bàn {{ item.SoBan }}</strong>
              <span>Order #{{ item.MaOrder }}</span>
            </div>
            <div class="ticket-area">{{ item.TenKV }}</div>
            <h4>{{ item.TenMon }}</h4>
            <div class="ticket-quantity">× {{ item.SoLuong }} <small>{{ item.DonViTinh }}</small></div>
            <div v-if="item.GhiChu" class="ticket-note"><b>Ghi chú:</b> {{ item.GhiChu }}</div>
            <div class="ticket-details">
              <span>Phục vụ: {{ item.NhanVienPhuTrach }}</span>
              <span>Bắt đầu: {{ dateText(item.ThoiGianBatDauCheBien) }}</span>
            </div>
            <div class="ticket-bottom">
              <span class="ticket-status status-cooking">Đang chế biến</span>
              <button class="ticket-action action-cooking" :disabled="savingIds.includes(item.MaCTOrder)" @click="updateItem(item)">
                {{ savingIds.includes(item.MaCTOrder) ? 'Đang lưu...' : 'Hoàn tất món' }}
              </button>
            </div>
          </article>
        </div>
        <div v-else class="column-empty"><span>♨</span><strong>Chưa có món đang chế biến</strong><small>Các món đã bắt đầu sẽ xuất hiện tại đây.</small></div>
      </section>

      <section class="board-column column-completed">
        <header class="column-header">
          <div><span class="column-dot"></span><h3>Hoàn tất</h3></div>
          <span class="column-count">{{ completedItems.length }}</span>
        </header>
        <div v-if="completedItems.length" class="ticket-list">
          <article v-for="item in completedItems" :key="item.MaCTOrder" class="kitchen-ticket ticket-finished">
            <div class="ticket-topline">
              <strong>Bàn {{ item.SoBan }}</strong>
              <span>Order #{{ item.MaOrder }}</span>
            </div>
            <div class="ticket-area">{{ item.TenKV }}</div>
            <h4>{{ item.TenMon }}</h4>
            <div class="ticket-quantity">× {{ item.SoLuong }} <small>{{ item.DonViTinh }}</small></div>
            <div v-if="item.GhiChu" class="ticket-note"><b>Ghi chú:</b> {{ item.GhiChu }}</div>
            <div class="ticket-details">
              <span>Phục vụ: {{ item.NhanVienPhuTrach }}</span>
              <span>Hoàn tất: {{ dateText(item.ThoiGianHoanTat) }}</span>
            </div>
            <div class="ticket-bottom">
              <span class="ticket-status status-complete">✓ Hoàn tất</span>
              <span class="notified-label">Đã báo Phục vụ</span>
            </div>
          </article>
        </div>
        <div v-else class="column-empty"><span>✓</span><strong>Chưa có món hoàn tất</strong><small>Món hoàn tất trong hôm nay sẽ hiển thị tại đây.</small></div>
      </section>
    </div>

    <section class="service-notifications">
      <header class="notifications-heading">
        <div>
          <h2>Thông báo Phục vụ</h2>
          <p>Món hoàn tất được gửi thông báo đến nhân viên phụ trách order.</p>
        </div>
        <span class="notification-unread">{{ unreadNotificationCount }} chưa đọc</span>
      </header>
      <div v-if="notifications.length" class="notification-list">
        <article v-for="notice in notifications" :key="notice.MaTB" class="notification-row" :class="{ unread: !notice.DaDoc }">
          <span class="notification-check">✓</span>
          <div class="notification-copy">
            <strong>{{ notice.NoiDung }}</strong>
            <small>Bàn {{ notice.SoBan }} · Order #{{ notice.MaOrder }} · Người nhận: {{ notice.NhanVienNhan }} · {{ dateText(notice.ThoiGianTao) }}</small>
          </div>
          <button v-if="!notice.DaDoc" class="read-notification" @click="markNotificationRead(notice)">Đánh dấu đã đọc</button>
          <span v-else class="read-label">Đã đọc</span>
        </article>
      </div>
      <div v-else class="notifications-empty">Chưa có thông báo món hoàn tất tại cơ sở này.</div>
    </section>

    <div class="kitchen-pagination" v-if="total > perPage">
      <span>{{ items.length }} món trên trang · {{ total }} món phù hợp</span>
      <div>
        <button :disabled="page <= 1" @click="page--; loadItems()">Trang trước</button>
        <strong>Trang {{ page }} / {{ Math.max(1, Math.ceil(total / perPage)) }}</strong>
        <button :disabled="page >= Math.ceil(total / perPage)" @click="page++; loadItems()">Trang sau</button>
      </div>
    </div>

    <section class="stock-request-panel">
      <header class="notifications-heading">
        <div>
          <h2>Đề nghị xuất kho nguyên liệu</h2>
          <p>Bộ phận {{ department === 'Bep' ? 'Bếp' : 'Bar' }} gửi nhu cầu; Kho kiểm tra tồn và xác nhận xuất theo FIFO</p>
        </div>
        <span class="notification-unread">{{ stockRequests.filter(x => x.TrangThai === 'Cho xac nhan').length }} chờ Kho</span>
      </header>
      <form class="stock-request-form" @submit.prevent="submitStockRequest">
        <div v-for="(line, index) in requestForm.items" :key="index" class="stock-request-line">
          <label><span>Nguyên liệu</span>
            <select v-model="line.MaNL" required>
              <option value="">Chọn nguyên liệu</option>
              <option v-for="ingredient in ingredients" :key="ingredient.MaNL" :value="String(ingredient.MaNL)">{{ ingredient.TenNL }} · {{ ingredient.DonViTinh }}</option>
            </select>
          </label>
          <label><span>Số lượng đề nghị</span><input v-model.number="line.SoLuongDeNghi" type="number" min="0.001" step="0.001" required /></label>
          <button type="button" class="read-notification" :disabled="requestForm.items.length === 1" @click="removeRequestLine(index)">Xóa</button>
        </div>
        <div class="stock-request-actions">
          <button type="button" class="read-notification" @click="addRequestLine">+ Thêm nguyên liệu</button>
          <button type="submit" class="ticket-action action-new" :disabled="requestBusy || !ingredients.length">{{ requestBusy ? 'Đang gửi...' : 'Gửi đề nghị xuất kho' }}</button>
        </div>
        <p class="stock-request-note">Gửi đề nghị không làm giảm tồn kho. Số lượng chỉ được trừ sau khi Kho xác nhận phiếu xuất.</p>
      </form>
      <div class="stock-request-history">
        <h3>Lịch sử đề nghị</h3>
        <article v-for="request in stockRequests" :key="request.MaPX" class="notification-row">
          <span class="notification-check">#{{ request.MaPX }}</span>
          <div class="notification-copy">
            <strong>{{ request.TrangThai === 'Cho xac nhan' ? 'Đang chờ Kho xác nhận' : request.TrangThai === 'Da xuat' ? 'Kho đã xuất nguyên liệu' : 'Đã hủy' }}</strong>
            <small>{{ dateText(request.NgayXuat) }} · Người đề nghị: {{ request.NguoiDeNghi || '—' }} · Người xuất: {{ request.NguoiXuat || '—' }}</small>
            <small v-for="(item, index) in request.items || []" :key="index">{{ item.TenNL }}: đề nghị {{ Number(item.SoLuongDeNghi || 0).toLocaleString('vi-VN') }} {{ item.DonViTinh }} · xuất {{ Number(item.SoLuongXuat || 0).toLocaleString('vi-VN') }} {{ item.DonViTinh }}</small>
          </div>
        </article>
        <div v-if="!stockRequests.length" class="notifications-empty">Chưa có đề nghị xuất kho nào cho bộ phận này.</div>
      </div>
    </section>

    <div class="kitchen-footnote">
      <span>Quy trình: Chờ chế biến → Đã nhận → Đang chế biến → Hoàn tất.</span>
      <span>Chỉ bộ phận phụ trách mới được cập nhật trạng thái món.</span>
    </div>
  </section>

  </div>
</template>

<style scoped>
.kitchen-filters { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.kitchen-filters > select { width:auto; min-width:175px; min-height:36px; }
.kitchen-pagination {display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin:15px 0; font-size:11px; color:#718194;}
.kitchen-pagination > div {display:flex; align-items:center; gap:9px;}
.kitchen-pagination button {width:auto; padding:8px 12px; border:1px solid #dce5ec; border-radius:7px; background:white; color:#29445d; font-size:11px;}
.kitchen-pagination button:disabled {opacity:.4; cursor:not-allowed;}

.kitchen-page { color: #203348; }
.kitchen-heading { display:flex; justify-content:space-between; align-items:flex-end; gap:24px; margin-bottom:24px; }
.kitchen-eyebrow { color:#ad7941; font-size:10px; font-weight:800; letter-spacing:1.5px; margin-bottom:9px; }
.kitchen-heading h1 { margin:0; font-size:27px; line-height:1.2; color:#172c40; letter-spacing:-.6px; }
.kitchen-heading p { margin:9px 0 0; color:#8190a0; font-size:12px; }
.kitchen-heading-actions { display:flex; align-items:flex-end; gap:10px; }
.branch-field { display:flex; flex-direction:column; gap:6px; min-width:190px; color:#718194; font-size:10px; font-weight:700; }
.branch-field select { height:39px; min-width:190px; border:1px solid #dfe6ed; border-radius:8px; padding:0 12px; color:#34495e; background:#fff; outline:none; font-size:12px; }
.refresh-button { height:39px; border:1px solid #dfe6ed; border-radius:8px; padding:0 14px; display:flex; align-items:center; gap:7px; color:#314a60; background:#fff; font-size:11px; font-weight:700; }
.refresh-button:disabled { opacity:.55; cursor:wait; }
.refresh-button span { font-size:19px; line-height:1; }
.spinning { display:inline-block; animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.department-switch { display:inline-flex; gap:5px; padding:5px; background:#e9eef3; border-radius:11px; margin-bottom:18px; }
.department-switch button { display:flex; align-items:center; gap:8px; min-width:126px; justify-content:center; border:0; border-radius:8px; padding:10px 16px; color:#6f8091; background:transparent; font-size:12px; font-weight:700; }
.department-switch button.active { color:#fff; background:#1e3a52; box-shadow:0 3px 9px rgba(23,44,64,.16); }
.department-icon { font-size:16px; color:#d4a66a; }
.department-count { min-width:20px; padding:2px 6px; border-radius:20px; background:rgba(255,255,255,.17); font-size:10px; }
.kitchen-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:22px; }
.kitchen-stat { min-height:76px; display:flex; align-items:center; gap:13px; padding:13px 16px; background:#fff; border:1px solid #e6ebf0; border-radius:11px; box-shadow:0 3px 12px rgba(28,45,64,.025); }
.stat-icon { width:36px; height:36px; flex:0 0 36px; display:grid; place-items:center; border-radius:10px; font-size:18px; font-weight:800; }
.stat-new { color:#b07b3d; background:#fbf1e4; }
.stat-cooking { color:#397ca9; background:#e9f3fa; }
.stat-complete { color:#27865f; background:#e8f6ef; }
.kitchen-stat strong { display:block; color:#203348; font-size:20px; line-height:1.1; }
.kitchen-stat small { display:block; margin-top:5px; color:#8492a1; font-size:10px; }
.kitchen-refresh-stat { justify-content:center; }
.kitchen-refresh-stat strong { font-size:12px; }
.live-dot { width:9px; height:9px; border-radius:50%; background:#2ca875; box-shadow:0 0 0 4px #e5f5ed; }
.kitchen-alert { display:flex; align-items:center; gap:9px; padding:11px 14px; border-radius:8px; margin-bottom:12px; font-size:11px; }
.error-alert { color:#a83f3a; background:#fff0ef; border:1px solid #f4d5d2; }
.success-alert { color:#246d4f; background:#edf8f2; border:1px solid #d4ecdf; }
.success-alert button { margin-left:auto; border:0; background:transparent; color:inherit; font-size:18px; }
.kitchen-toolbar { display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin:0 0 13px; }
.board-title h2 { margin:0; color:#203348; font-size:15px; }
.board-title span { display:block; margin-top:5px; color:#94a0ad; font-size:10px; }
.kitchen-search { display:flex; align-items:center; gap:8px; width:260px; height:36px; padding:0 11px; border:1px solid #e1e7ed; border-radius:8px; background:#fff; color:#9aa7b3; }
.kitchen-search span { font-size:20px; }
.kitchen-search input { width:100%; border:0; outline:0; color:#33485c; font-size:11px; background:transparent; }
.kitchen-board { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:13px; align-items:start; }
.board-column { min-height:390px; padding:12px; border:1px solid #e4eaf0; border-radius:11px; background:#f8fafc; }
.column-header { display:flex; align-items:center; justify-content:space-between; padding:2px 2px 12px; border-bottom:1px solid #e6ebf0; margin-bottom:11px; }
.column-header > div { display:flex; align-items:center; gap:8px; }
.column-header h3 { margin:0; color:#34495e; font-size:12px; }
.column-dot { width:8px; height:8px; border-radius:50%; background:#d5a15c; }
.column-cooking .column-dot { background:#5293bd; }
.column-completed .column-dot { background:#36a477; }
.column-count { min-width:23px; padding:3px 7px; text-align:center; border-radius:20px; background:#edf1f5; color:#627386; font-size:10px; font-weight:800; }
.ticket-list { display:flex; flex-direction:column; gap:10px; }
.kitchen-ticket { padding:13px; border:1px solid #e6ebf0; border-radius:9px; background:#fff; box-shadow:0 3px 10px rgba(30,51,72,.025); }
.ticket-topline { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.ticket-topline strong { color:#1f3a52; font-size:13px; }
.ticket-topline span { color:#9aa6b1; font-size:9px; }
.ticket-area { margin-top:4px; color:#a0aab5; font-size:9px; }
.kitchen-ticket h4 { margin:10px 0 3px; color:#263e54; font-size:13px; line-height:1.4; }
.ticket-quantity { color:#b17d43; font-size:13px; font-weight:800; }
.ticket-quantity small { color:#96a2ae; font-size:9px; font-weight:500; }
.ticket-note { margin-top:9px; padding:7px 8px; border-radius:6px; color:#7d5b32; background:#fbf5ec; font-size:10px; line-height:1.5; overflow-wrap:anywhere; }
.ticket-details { display:flex; flex-direction:column; gap:4px; margin-top:10px; color:#97a3ae; font-size:9px; }
.ticket-bottom { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:12px; padding-top:10px; border-top:1px solid #eef1f4; }
.ticket-status { display:inline-flex; align-items:center; padding:5px 8px; border-radius:20px; font-size:9px; font-weight:800; white-space:nowrap; }
.status-waiting { color:#9b6b2f; background:#fbf1e4; }
.status-accepted { color:#52738d; background:#edf3f8; }
.status-cooking { color:#2c75a4; background:#eaf4fb; }
.status-complete { color:#26825c; background:#e8f6ef; }
.ticket-action { min-height:30px; padding:0 10px; border:0; border-radius:6px; font-size:9px; font-weight:800; white-space:nowrap; }
.ticket-action:disabled { opacity:.55; cursor:wait; }
.action-new { color:#fff; background:#ad7941; }
.action-cooking { color:#fff; background:#397da7; }
.ticket-finished { border-color:#dceee4; }
.notified-label { color:#27865f; font-size:9px; font-weight:700; white-space:nowrap; }
.column-empty { min-height:230px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:22px; text-align:center; color:#a3afb9; }
.column-empty > span { width:34px; height:34px; display:grid; place-items:center; margin-bottom:10px; border-radius:50%; color:#8a9aa8; background:#edf1f5; font-size:17px; }
.column-empty strong { color:#718294; font-size:11px; }
.column-empty small { max-width:180px; margin-top:6px; color:#a0acb7; font-size:9px; line-height:1.5; }
.kitchen-loading { min-height:300px; display:flex; justify-content:center; align-items:center; gap:10px; color:#8190a0; font-size:12px; }
.loading-spinner { width:18px; height:18px; border:2px solid #e5ebf0; border-top-color:#b17d43; border-radius:50%; animation:spin .8s linear infinite; }
.kitchen-footnote { display:flex; justify-content:space-between; gap:15px; margin-top:13px; color:#9aa6b2; font-size:9px; }
.service-notifications { margin-top:18px; padding:15px; border:1px solid #e5eaf0; border-radius:11px; background:#fff; }
.notifications-heading { display:flex; align-items:center; justify-content:space-between; gap:15px; padding-bottom:12px; border-bottom:1px solid #edf0f3; }
.notifications-heading h2 { margin:0; color:#203348; font-size:13px; }
.notifications-heading p { margin:5px 0 0; color:#97a3ae; font-size:9px; }
.notification-unread { padding:5px 9px; border-radius:20px; color:#94672f; background:#fbf2e7; font-size:9px; font-weight:800; white-space:nowrap; }
.notification-list { display:flex; flex-direction:column; }
.notification-row { display:flex; align-items:center; gap:11px; padding:11px 2px; border-bottom:1px solid #f0f2f5; }
.notification-row:last-child { border-bottom:0; }
.notification-row.unread { background:#fffdf9; }
.notification-check { width:28px; height:28px; flex:0 0 28px; display:grid; place-items:center; border-radius:50%; color:#27865f; background:#e8f6ef; font-size:13px; font-weight:800; }
.notification-copy { min-width:0; flex:1; }
.notification-copy strong { display:block; color:#34495e; font-size:10px; font-weight:700; }
.notification-copy small { display:block; margin-top:5px; color:#9aa6b1; font-size:9px; }
.read-notification { padding:6px 9px; border:1px solid #dce5ec; border-radius:6px; color:#597185; background:#fff; font-size:9px; white-space:nowrap; }
.read-label { color:#9aa6b1; font-size:9px; white-space:nowrap; }
.notifications-empty { padding:22px 10px 12px; text-align:center; color:#9aa6b1; font-size:10px; }
@media (max-width: 1100px) { .kitchen-board { grid-template-columns:1fr; } .board-column { min-height:220px; } .column-empty { min-height:140px; } .kitchen-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width: 680px) { .kitchen-heading { align-items:stretch; flex-direction:column; } .kitchen-heading-actions { align-items:stretch; } .branch-field, .branch-field select { min-width:0; width:100%; } .kitchen-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } .kitchen-toolbar { align-items:stretch; flex-direction:column; } .kitchen-search { width:100%; } .kitchen-footnote { flex-direction:column; } }

.stock-request-panel { margin-top: 22px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 18px; background: #fff; }
.stock-request-form { display: flex; flex-direction: column; gap: 12px; padding: 16px 0; }
.stock-request-line { display: grid; grid-template-columns: minmax(0, 1fr) minmax(140px, 220px) auto; gap: 12px; align-items: end; }
.stock-request-line label { display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 700; color: #475569; }
.stock-request-line select, .stock-request-line input { width: 100%; min-height: 40px; border: 1px solid #cbd5e1; border-radius: 9px; padding: 8px 10px; background: white; }
.stock-request-actions { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px; }
.stock-request-note { color: #64748b; font-size: 12px; }
.stock-request-history { display: flex; flex-direction: column; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 16px; }
.stock-request-history h3 { font-weight: 700; }
@media (max-width: 640px) { .stock-request-line { grid-template-columns: 1fr; } }
</style>