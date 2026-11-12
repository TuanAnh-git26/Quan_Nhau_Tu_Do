
<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'

const branches = ref([])
const selectedBranch = ref('')

const orders = ref([])
const notifications = ref([])

const loading = ref(false)
const branchesLoading = ref(true)
const error = ref('')
const busyId = ref(null)

const keyword = ref('')
const selectedStation = ref('')
const selectedStatus = ref('')

const currentPage = ref(1)
const perPage = ref(10)

const pagination = ref({
  currentPage: 1,
  lastPage: 1,
  total: 0,
  perPage: 10
})

const waitingCount = computed(() =>
  orders.value.filter(
    item => item.TrangThaiMon === 'Cho che bien'
  ).length
)

const cookingCount = computed(() =>
  orders.value.filter(
    item => item.TrangThaiMon === 'Dang che bien'
  ).length
)

const unreadCount = computed(() =>
  notifications.value.filter(
    item => !item.DaDoc
  ).length
)

const pageNumbers = computed(() => {
  const start = Math.max(1, currentPage.value - 2)
  const end = Math.min(
    pagination.value.lastPage,
    currentPage.value + 2
  )

  return Array.from(
    { length: Math.max(0, end - start + 1) },
    (_, index) => start + index
  )
})

const firstRecord = computed(() => {
  if (!pagination.value.total) return 0

  return (
    (pagination.value.currentPage - 1) *
    pagination.value.perPage + 1
  )
})

const lastRecord = computed(() =>
  Math.min(
    pagination.value.currentPage *
      pagination.value.perPage,
    pagination.value.total
  )
)

function getErrorMessage(err, fallback) {
  return (
    err?.response?.data?.message ||
    err?.response?.data?.errors?.TrangThaiMon?.[0] ||
    fallback
  )
}

function statusLabel(status) {
  const labels = {
    'Cho che bien': 'Chờ chế biến',
    'Dang che bien': 'Đang chế biến',
    'Hoan tat': 'Hoàn tất',
    'Da huy': 'Đã hủy'
  }

  return labels[status] || status || 'Chưa xác định'
}

function statusClass(status) {
  const classes = {
    'Cho che bien': 'status-waiting',
    'Dang che bien': 'status-cooking',
    'Hoan tat': 'status-completed',
    'Da huy': 'status-cancelled'
  }

  return classes[status] || ''
}

function stationLabel(value) {
  const labels = {
    Bep: 'Bếp',
    Bar: 'Bar'
  }

  return labels[value] || value || 'Chưa phân bộ phận'
}

function formatDate(value) {
  if (!value) return 'Chưa ghi nhận'

  const date = new Date(
    String(value).replace(' ', 'T')
  )

  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  })
}

function nextStatus(item) {
  if (item.TrangThaiMon === 'Cho che bien') {
    return 'Dang che bien'
  }

  if (item.TrangThaiMon === 'Dang che bien') {
    return 'Hoan tat'
  }

  return null
}

function actionLabel(item) {
  if (item.TrangThaiMon === 'Cho che bien') {
    return 'Bắt đầu chế biến'
  }

  if (item.TrangThaiMon === 'Dang che bien') {
    return 'Hoàn tất món'
  }

  return 'Đã hoàn tất'
}

async function loadBranches() {
  branchesLoading.value = true

  try {
    const response = await api.get('/branches')

    branches.value = Array.isArray(response.data)
      ? response.data
      : response.data?.data || []

    if (
      !selectedBranch.value &&
      branches.value.length
    ) {
      selectedBranch.value = String(
        branches.value[0].MaCS
      )
    }
  } catch (err) {
    error.value = getErrorMessage(
      err,
      'Không thể tải danh sách cơ sở.'
    )
  } finally {
    branchesLoading.value = false
  }
}

async function loadOrders() {
  if (!selectedBranch.value) {
    orders.value = []
    pagination.value.total = 0
    return
  }

  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/bep-bar/orders', {
      params: {
        MaCS: selectedBranch.value,
        q: keyword.value.trim() || undefined,
        NoiCheBien: selectedStation.value || undefined,
        TrangThaiMon: selectedStatus.value || undefined,
        page: currentPage.value,
        per_page: perPage.value
      }
    })

    const result = response.data

    orders.value = result.data || []

    pagination.value = {
      currentPage: result.current_page || 1,
      lastPage: result.last_page || 1,
      total: result.total || 0,
      perPage: result.per_page || perPage.value
    }
  } catch (err) {
    orders.value = []

    error.value = getErrorMessage(
      err,
      'Không thể tải danh sách món. Hãy kiểm tra kết nối API.'
    )
  } finally {
    loading.value = false
  }
}

async function loadNotifications() {
  if (!selectedBranch.value) {
    notifications.value = []
    return
  }

  try {
    const response = await api.get(
      '/service-notifications',
      {
        params: {
          MaCS: selectedBranch.value
        }
      }
    )

    notifications.value = Array.isArray(response.data)
      ? response.data
      : response.data?.data || []
  } catch {
    notifications.value = []
  }
}

async function reload() {
  await loadBranches()

  if (selectedBranch.value) {
    await Promise.all([
      loadOrders(),
      loadNotifications()
    ])
  }
}

async function changeBranch() {
  currentPage.value = 1

  await Promise.all([
    loadOrders(),
    loadNotifications()
  ])
}

async function applyFilters() {
  currentPage.value = 1
  await loadOrders()
}

async function changePage(page) {
  if (
    page < 1 ||
    page > pagination.value.lastPage ||
    page === currentPage.value
  ) {
    return
  }

  currentPage.value = page
  await loadOrders()
}

async function changePerPage() {
  currentPage.value = 1
  await loadOrders()
}

async function updateStatus(item) {
  const next = nextStatus(item)

  if (!next || busyId.value !== null) return

  const confirmation = window.confirm(
    next === 'Dang che bien'
      ? `Bắt đầu chế biến món "${item.TenMon}"?`
      : `Xác nhận món "${item.TenMon}" đã hoàn tất?`
  )

  if (!confirmation) return

  busyId.value = item.MaCTOrder
  error.value = ''

  try {
    await api.patch(
      `/bep-bar/items/${item.MaCTOrder}/status`,
      {
        TrangThaiMon: next
      }
    )

    await Promise.all([
      loadOrders(),
      loadNotifications()
    ])
  } catch (err) {
    error.value = getErrorMessage(
      err,
      'Không thể cập nhật trạng thái món.'
    )
  } finally {
    busyId.value = null
  }
}

async function markAsRead(item) {
  if (item.DaDoc) return

  try {
    await api.put(
      `/service-notifications/${item.MaCTOrder}/read`,
      {},
      {
        params: {
          MaCS: selectedBranch.value
        }
      }
    )

    item.DaDoc = true
  } catch (err) {
    error.value = getErrorMessage(
      err,
      'Không thể cập nhật thông báo.'
    )
  }
}

onMounted(reload)
</script>

<template>
  <div class="bep-page">

    <!-- Tiêu đề -->
    <header class="bep-heading">
      <div>
        <div class="bep-eyebrow">
          QUẢN LÝ VẬN HÀNH
        </div>

        <h1>Bếp / Bar</h1>

        <p>
          Tiếp nhận đơn hàng và theo dõi tiến độ chế biến
          tại từng cơ sở.
        </p>
      </div>

      <button
        class="bep-refresh"
        :disabled="loading"
        @click="reload"
      >
        ↻ Làm mới dữ liệu
      </button>
    </header>

    <!-- Lỗi -->
    <div v-if="error" class="bep-error">
      {{ error }}

      <button @click="error = ''">×</button>
    </div>

    <!-- Bộ lọc -->
    <section class="bep-card bep-filters">
      <div class="bep-field">
        <label>Cơ sở</label>

        <select
          v-model="selectedBranch"
          :disabled="branchesLoading"
          @change="changeBranch"
        >
          <option
            v-for="branch in branches"
            :key="branch.MaCS"
            :value="String(branch.MaCS)"
          >
            {{ branch.TenCS }}
          </option>
        </select>
      </div>

      <div class="bep-field bep-search">
        <label>Tìm kiếm</label>

        <input
          v-model="keyword"
          placeholder="Tên món, mã món, order hoặc số bàn..."
          @keyup.enter="applyFilters"
        >
      </div>

      <div class="bep-field">
        <label>Bộ phận</label>

        <select
          v-model="selectedStation"
          @change="applyFilters"
        >
          <option value="">Tất cả bộ phận</option>
          <option value="Bep">Bếp</option>
          <option value="Bar">Bar</option>
        </select>
      </div>

      <div class="bep-field">
        <label>Trạng thái</label>

        <select
          v-model="selectedStatus"
          @change="applyFilters"
        >
          <option value="">Đang xử lý</option>
          <option value="Cho che bien">Chờ chế biến</option>
          <option value="Dang che bien">Đang chế biến</option>
          <option value="Hoan tat">Hoàn tất</option>
          <option value="Tat ca">Tất cả món</option>
        </select>
      </div>

      <button
        class="bep-search-btn"
        @click="applyFilters"
      >
        Tìm kiếm
      </button>
    </section>

    <!-- Thống kê -->
    <section class="bep-stats">
      <article class="bep-stat">
        <div class="bep-stat-icon orange">⌛</div>

        <div>
          <span>Chờ chế biến</span>
          <strong>{{ waitingCount }}</strong>
          <small>Trong trang hiện tại</small>
        </div>
      </article>

      <article class="bep-stat">
        <div class="bep-stat-icon blue">♨</div>

        <div>
          <span>Đang chế biến</span>
          <strong>{{ cookingCount }}</strong>
          <small>Trong trang hiện tại</small>
        </div>
      </article>

      <article class="bep-stat">
        <div class="bep-stat-icon green">✓</div>

        <div>
          <span>Tổng món phù hợp</span>
          <strong>{{ pagination.total }}</strong>
          <small>Theo bộ lọc hiện tại</small>
        </div>
      </article>

      <article class="bep-stat">
        <div class="bep-stat-icon purple">♢</div>

        <div>
          <span>Thông báo chưa đọc</span>
          <strong>{{ unreadCount }}</strong>
          <small>Trong 24 giờ gần nhất</small>
        </div>
      </article>
    </section>

    <!-- Danh sách món -->
    <section class="bep-card bep-table-card">
      <div class="bep-card-heading">
        <div>
          <h2>Danh sách món cần xử lý</h2>

          <p>
            Theo dõi món ăn và đồ uống được gửi từ Order/POS.
          </p>
        </div>

        <span class="bep-total">
          {{ pagination.total }} món
        </span>
      </div>

      <div class="bep-table-wrap">
        <table class="bep-table">
          <thead>
            <tr>
              <th>Mã món</th>
              <th>Tên món</th>
              <th>Bàn / Khu vực</th>
              <th>Số lượng</th>
              <th>Bộ phận</th>
              <th>Ghi chú</th>
              <th>Trạng thái</th>
              <th>Thời gian nhận</th>
              <th>Thao tác</th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="loading">
              <td colspan="9" class="bep-empty">
                Đang tải dữ liệu...
              </td>
            </tr>

            <tr
              v-else-if="!orders.length"
            >
              <td colspan="9" class="bep-empty">
                <div class="bep-empty-icon">🍽</div>

                <strong>Chưa có món phù hợp</strong>

                <p>
                  Các món được gửi từ Order/POS sẽ xuất hiện tại đây.
                </p>
              </td>
            </tr>

            <tr
              v-for="item in orders"
              :key="item.MaCTOrder"
            >
              <td class="bep-code">
                #{{ item.MaCTOrder }}
              </td>

              <td>
                <strong>{{ item.TenMon }}</strong>

                <small>
                  Order #{{ item.MaOrder }}
                </small>
              </td>

              <td>
                <strong>Bàn {{ item.SoBan }}</strong>

                <small>{{ item.TenKV }}</small>
              </td>

              <td class="bep-quantity">
                {{ item.SoLuong }}
              </td>

              <td>
                <span class="bep-station">
                  {{ stationLabel(item.NoiCheBien) }}
                </span>
              </td>

              <td class="bep-note">
                {{ item.GhiChu || 'Không có' }}
              </td>

              <td>
                <span
                  class="bep-status"
                  :class="statusClass(item.TrangThaiMon)"
                >
                  {{ statusLabel(item.TrangThaiMon) }}
                </span>
              </td>

              <td class="bep-time">
                {{ formatDate(item.ThoiGianNhan) }}
              </td>

              <td>
                <button
                  v-if="nextStatus(item)"
                  class="bep-action"
                  :class="{
                    'bep-action-finish':
                      item.TrangThaiMon === 'Dang che bien'
                  }"
                  :disabled="busyId !== null"
                  @click="updateStatus(item)"
                >
                  {{
                    busyId === item.MaCTOrder
                      ? 'Đang xử lý...'
                      : actionLabel(item)
                  }}
                </button>

                <span
                  v-else
                  class="bep-done"
                >
                  Đã xong
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Phân trang -->
      <div class="bep-pagination">
        <div class="bep-pagination-info">
          Hiển thị
          <strong>{{ firstRecord }}–{{ lastRecord }}</strong>
          trong
          <strong>{{ pagination.total }}</strong>
          kết quả
        </div>

        <div class="bep-pagination-controls">
          <label>Hiển thị</label>

          <select
            v-model.number="perPage"
            @change="changePerPage"
          >
            <option :value="10">10 dòng</option>
            <option :value="20">20 dòng</option>
            <option :value="50">50 dòng</option>
          </select>

          <button
            :disabled="currentPage <= 1 || loading"
            @click="changePage(currentPage - 1)"
          >
            ‹
          </button>

          <button
            v-for="page in pageNumbers"
            :key="page"
            :class="{ active: currentPage === page }"
            :disabled="loading"
            @click="changePage(page)"
          >
            {{ page }}
          </button>

          <button
            :disabled="
              currentPage >= pagination.lastPage || loading
            "
            @click="changePage(currentPage + 1)"
          >
            ›
          </button>
        </div>
      </div>
    </section>

    <!-- Thông báo -->
    <section class="bep-card bep-notifications">
      <div class="bep-card-heading">
        <div>
          <h2>Thông báo Bếp/Bar</h2>

          <p>
            Các món mới được gửi đến bộ phận chế biến trong 24 giờ gần nhất.
          </p>
        </div>

        <span class="bep-notification-count">
          {{ unreadCount }} chưa đọc
        </span>
      </div>

      <div
        v-if="!notifications.length"
        class="bep-empty-notification"
      >
        Chưa có thông báo mới.
      </div>

      <article
        v-for="notification in notifications"
        :key="notification.MaCTOrder"
        class="bep-notification-item"
        :class="{ unread: !notification.DaDoc }"
      >
        <div class="bep-notification-icon">
          {{ notification.NoiCheBien === 'Bar' ? '🥤' : '🍳' }}
        </div>

        <div class="bep-notification-content">
          <strong>{{ notification.TenMon }}</strong>

          <p>
            Bàn {{ notification.SoBan }}
            · Số lượng {{ notification.SoLuong }}
            · {{ stationLabel(notification.NoiCheBien) }}
          </p>

          <small>
            {{ formatDate(notification.ThoiGianNhan) }}
          </small>
        </div>

        <button
          v-if="!notification.DaDoc"
          class="bep-read-btn"
          @click="markAsRead(notification)"
        >
          Đánh dấu đã đọc
        </button>

        <span v-else class="bep-read-label">
          Đã đọc
        </span>
      </article>
    </section>

  </div>
</template>

<style scoped>
.bep-page {
  display: flex;
  flex-direction: column;
  gap: 20px;
  width: 100%;
  color: #193047;
}

.bep-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}

.bep-eyebrow {
  color: #c27b32;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1.3px;
  margin-bottom: 8px;
}

.bep-heading h1 {
  margin: 0;
  font-size: 28px;
  font-weight: 800;
}

.bep-heading p,
.bep-card-heading p {
  color: #8191a5;
  font-size: 13px;
  margin: 7px 0 0;
}

.bep-card {
  background: #fff;
  border: 1px solid #e1e8f0;
  border-radius: 13px;
  padding: 20px;
  box-shadow: 0 5px 18px rgba(28, 53, 78, 0.035);
}

.bep-refresh,
.bep-search-btn {
  border: 1px solid #dce5ef;
  background: #fff;
  color: #244763;
  border-radius: 8px;
  padding: 11px 16px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.bep-refresh:hover {
  background: #f5f8fc;
}

.bep-refresh:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.bep-filters {
  display: grid;
  grid-template-columns: 1.2fr 1.7fr 1fr 1fr auto;
  align-items: end;
  gap: 13px;
}

.bep-field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}

.bep-field label,
.bep-pagination-controls label {
  font-size: 11px;
  color: #62788e;
  font-weight: 700;
}

.bep-field input,
.bep-field select,
.bep-pagination-controls select {
  height: 41px;
  width: 100%;
  min-width: 0;
  border: 1px solid #dce5ef;
  border-radius: 7px;
  background: #fff;
  padding: 0 11px;
  color: #29445d;
  font-size: 12px;
  outline: none;
}

.bep-field input:focus,
.bep-field select:focus {
  border-color: #4b84ad;
  box-shadow: 0 0 0 3px rgba(75, 132, 173, 0.1);
}

.bep-search-btn {
  height: 41px;
  background: #244763;
  color: #fff;
  border-color: #244763;
}

.bep-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
}

.bep-stat {
  display: flex;
  align-items: center;
  gap: 15px;
  background: #fff;
  border: 1px solid #e1e8f0;
  border-radius: 12px;
  padding: 19px;
  min-width: 0;
}

.bep-stat-icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: 11px;
  font-size: 22px;
}

.bep-stat-icon.orange {
  background: #fff4e7;
  color: #c77c27;
}

.bep-stat-icon.blue {
  background: #eaf2fb;
  color: #3978bd;
}

.bep-stat-icon.green {
  background: #e8f6ef;
  color: #25936c;
}

.bep-stat-icon.purple {
  background: #f2edfc;
  color: #8a62ca;
}

.bep-stat span,
.bep-stat small {
  display: block;
  color: #8091a5;
  font-size: 11px;
}

.bep-stat strong {
  display: block;
  font-size: 25px;
  margin: 3px 0;
}

.bep-card-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
  margin-bottom: 17px;
}

.bep-card-heading h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 800;
}

.bep-total,
.bep-notification-count {
  color: #59748e;
  background: #f0f5fa;
  padding: 7px 11px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}

.bep-table-wrap {
  overflow-x: auto;
  width: 100%;
}

.bep-table {
  width: 100%;
  min-width: 1050px;
  border-collapse: collapse;
  font-size: 12px;
}

.bep-table th {
  background: #f5f8fb;
  color: #647c92;
  font-size: 11px;
  font-weight: 700;
  text-align: left;
  padding: 14px 12px;
  white-space: nowrap;
}

.bep-table td {
  padding: 15px 12px;
  border-bottom: 1px solid #edf1f5;
  vertical-align: middle;
}

.bep-table tbody tr:hover {
  background: #fafcff;
}

.bep-code {
  color: #6584a2;
  font-weight: 700;
}

.bep-table td strong {
  display: block;
  color: #29445d;
}

.bep-table td small {
  display: block;
  color: #91a0b0;
  margin-top: 5px;
  font-size: 10px;
}

.bep-quantity {
  font-size: 16px;
  font-weight: 800;
}

.bep-station {
  display: inline-block;
  background: #f0f4f8;
  padding: 6px 10px;
  border-radius: 6px;
  font-weight: 700;
  color: #536e86;
  white-space: nowrap;
}

.bep-note {
  max-width: 150px;
  color: #718398;
  overflow-wrap: anywhere;
}

.bep-time {
  color: #718398;
  white-space: nowrap;
  font-size: 11px;
}

.bep-status {
  display: inline-block;
  border-radius: 20px;
  padding: 6px 10px;
  font-size: 10px;
  font-weight: 800;
  white-space: nowrap;
}

.status-waiting {
  background: #fff3df;
  color: #b97920;
}

.status-cooking {
  background: #eaf2ff;
  color: #3976bd;
}

.status-completed {
  background: #e5f5ec;
  color: #27865e;
}

.status-cancelled {
  background: #fceaea;
  color: #c04c4c;
}

.bep-action {
  border: 0;
  border-radius: 7px;
  background: #244763;
  color: #fff;
  padding: 9px 11px;
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
  cursor: pointer;
}

.bep-action-finish {
  background: #25845f;
}

.bep-action:disabled {
  opacity: 0.5;
  cursor: wait;
}

.bep-done,
.bep-read-label {
  color: #27865e;
  font-size: 11px;
  font-weight: 700;
}

.bep-empty {
  text-align: center;
  padding: 45px 15px !important;
  color: #8b9bad;
}

.bep-empty-icon {
  font-size: 30px;
  margin-bottom: 10px;
}

.bep-empty p {
  font-size: 11px;
  margin-bottom: 0;
}

.bep-pagination {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 15px;
  padding-top: 18px;
}

.bep-pagination-info {
  color: #7c8fa3;
  font-size: 11px;
}

.bep-pagination-info strong {
  color: #29445d;
}

.bep-pagination-controls {
  display: flex;
  align-items: center;
  gap: 5px;
}

.bep-pagination-controls select {
  width: 100px;
  height: 34px;
  margin-right: 8px;
}

.bep-pagination-controls button {
  min-width: 32px;
  height: 32px;
  border: 1px solid #dce5ef;
  background: #fff;
  color: #506b83;
  border-radius: 6px;
  cursor: pointer;
}

.bep-pagination-controls button.active {
  background: #244763;
  color: #fff;
  border-color: #244763;
}

.bep-pagination-controls button:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.bep-notification-item {
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 14px 5px;
  border-bottom: 1px solid #edf1f5;
}

.bep-notification-item:last-child {
  border-bottom: 0;
}

.bep-notification-item.unread {
  background: #f8fbff;
}

.bep-notification-icon {
  width: 40px;
  height: 40px;
  display: grid;
  place-items: center;
  background: #f1f5f9;
  border-radius: 10px;
  flex-shrink: 0;
  font-size: 20px;
}

.bep-notification-content {
  flex: 1;
  min-width: 0;
}

.bep-notification-content strong {
  font-size: 12px;
}

.bep-notification-content p {
  font-size: 11px;
  color: #75899d;
  margin: 5px 0;
}

.bep-notification-content small {
  color: #98a6b4;
  font-size: 10px;
}

.bep-read-btn {
  border: 1px solid #dce5ef;
  background: #fff;
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 10px;
  color: #466782;
  cursor: pointer;
}

.bep-empty-notification {
  text-align: center;
  color: #8b9bad;
  font-size: 12px;
  padding: 25px;
}

.bep-error {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 12px 15px;
  background: #fff0f0;
  border: 1px solid #ffd4d4;
  color: #b53d3d;
  border-radius: 8px;
  font-size: 12px;
}

.bep-error button {
  border: 0;
  background: transparent;
  font-size: 18px;
  color: inherit;
  cursor: pointer;
}

@media (max-width: 1100px) {
  .bep-filters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .bep-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 650px) {
  .bep-heading {
    align-items: flex-start;
    flex-direction: column;
  }

  .bep-heading h1 {
    font-size: 23px;
  }

  .bep-filters,
  .bep-stats {
    grid-template-columns: 1fr;
  }

  .bep-card {
    padding: 14px;
  }

  .bep-pagination {
    align-items: flex-start;
    flex-direction: column;
  }

  .bep-pagination-controls {
    flex-wrap: wrap;
  }
}
</style>
