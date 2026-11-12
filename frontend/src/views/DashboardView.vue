
<script setup>
import { computed, onMounted, ref } from 'vue'
import PaginationBar from '../components/PaginationBar.vue'
import api from '../services/api'

const emit = defineEmits(['navigate'])

// ================================
// TRẠNG THÁI DỮ LIỆU
// ================================

const loading = ref(true)
const error = ref('')

const branches = ref([])
const categories = ref([])
const items = ref([])

const selectedBranch = ref('')

// ================================
// PHÂN TRANG DANH SÁCH CƠ SỞ
// ================================

const currentPage = ref(1)
const pageSize = ref(10)

const paginatedBranches = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value

  return branches.value.slice(
    start,
    start + pageSize.value
  )
})

// ================================
// THỐNG KÊ TỔNG QUAN
// ================================

const activeCategoryCount = computed(() =>
  categories.value.filter(
    x => x.TrangThai === 'Dang hoat dong'
  ).length
)

const availableCount = computed(() =>
  items.value.filter(x => x.CoTheBan).length
)

const missingPriceCount = computed(() =>
  items.value.filter(x => x.DonGia === null).length
)

// ================================
// TẢI DỮ LIỆU HỆ THỐNG
// ================================

async function reload() {
  loading.value = true
  error.value = ''

  try {
    const [branchResponse, categoryResponse] = await Promise.all([
      api.get('/branches'),
      api.get('/categories')
    ])

    branches.value = branchResponse.data
    categories.value = categoryResponse.data

    // Quay về trang đầu tiên khi tải lại dữ liệu
    currentPage.value = 1

    // Giữ nguyên cơ sở đã chọn nếu vẫn tồn tại
    if (
      !branches.value.some(
        x => String(x.MaCS) === selectedBranch.value
      )
    ) {
      selectedBranch.value = branches.value.length
        ? String(branches.value[0].MaCS)
        : ''
    }

    await loadBranchItems()

  } catch (e) {
    error.value =
      'Không thể tải dữ liệu tổng quan. Hãy kiểm tra Laravel và MariaDB.'
  } finally {
    loading.value = false
  }
}

// ================================
// TẢI THỰC ĐƠN THEO CƠ SỞ
// ================================

async function loadBranchItems() {
  if (!selectedBranch.value) {
    items.value = []
    return
  }

  try {
    const { data } = await api.get('/menu-items', {
      params: {
        MaCS: selectedBranch.value
      }
    })

    items.value = data

  } catch (e) {
    items.value = []

    error.value =
      'Không thể tải thực đơn của cơ sở đã chọn.'
  }
}

// ================================
// KHỞI TẠO
// ================================

onMounted(reload)
</script>

<template>
  <div class="page-heading">
    <div>

      <h1>Tổng quan hoạt động</h1>

      <p class="muted">
        Dữ liệu từ hệ thống quản lý Quán Nhậu Tự Do.
      </p>
    </div>

    <div class="page-heading-actions">
      <button
        class="secondary-btn"
        @click="reload"
        :disabled="loading"
      >
        ↻ {{ loading ? 'Đang tải...' : 'Làm mới' }}
      </button>
    </div>
  </div>

  <!-- THÔNG BÁO LỖI -->

  <div
    v-if="error"
    class="alert error"
  >
    {{ error }}
  </div>

  <!-- CHỌN CƠ SỞ -->

  <section class="panel filter-panel">
    <div class="filter-field">
      <label>Cơ sở xem thực đơn</label>

      <select
        v-model="selectedBranch"
        @change="loadBranchItems"
      >
        <option
          v-for="b in branches"
          :key="b.MaCS"
          :value="String(b.MaCS)"
        >
          {{ b.TenCS }}
        </option>
      </select>
    </div>
  </section>

  <!-- THỐNG KÊ TỔNG QUAN -->

  <section class="stat-grid">

    <article class="stat-card">
      <span class="stat-icon blue">⌂</span>

      <div>
        <span class="stat-label">
          Cơ sở đang quản lý
        </span>

        <strong>
          {{ loading ? '…' : branches.length }}
        </strong>

        <small>
          Đang hoạt động trong cơ sở dữ liệu
        </small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon purple">▦</span>

      <div>
        <span class="stat-label">
          Danh mục đang hoạt động
        </span>

        <strong>
          {{ loading ? '…' : activeCategoryCount }}
        </strong>

        <small>
          Nhóm món và đồ uống
        </small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon green">✓</span>

      <div>
        <span class="stat-label">
          Món có thể phục vụ
        </span>

        <strong>
          {{ loading ? '…' : availableCount }}
        </strong>

        <small>
          Tại cơ sở đã chọn, có giá và đang bật bán
        </small>
      </div>
    </article>

  </section>

  <!-- GIỚI THIỆU HỆ THỐNG -->

  <section class="panel welcome-panel">
    <div>
      <p class="eyebrow">
        QUẢN LÝ THỰC ĐƠN THEO CƠ SỞ
      </p>

      <h2>Quán Nhậu Tự Do</h2>

      <p class="muted">
        Hiện có {{ items.length }} món trong danh sách;
        {{ missingPriceCount }} món chưa được cập nhật
        giá tại cơ sở đã chọn.
      </p>

      <button
        class="primary-btn"
        @click="emit('navigate', 'menu')"
      >
        Mở quản lý thực đơn →
      </button>
    </div>

    <div class="welcome-illustration">
      🍲
    </div>
  </section>

  <!-- TRUY CẬP NHANH -->

  <section class="panel">
    <div class="panel-heading">
      <div>
        <h2>Truy cập nhanh</h2>

        <p class="panel-subtitle">
          Các chức năng quản lý đang triển khai
        </p>
      </div>
    </div>

    <div class="quick-grid">

      <button
        class="quick-card"
        @click="emit('navigate', 'menu')"
      >
        <span class="quick-icon">▤</span>

        <span>
          <strong>Quản lý thực đơn</strong>

          <small>
            Cập nhật món và giá bán theo cơ sở
          </small>
        </span>

        <span class="quick-arrow">→</span>
      </button>

      <button
        class="quick-card"
        @click="emit('navigate', 'categories')"
      >
        <span class="quick-icon">▦</span>

        <span>
          <strong>Danh mục món</strong>

          <small>
            Quản lý các nhóm món và nơi chế biến
          </small>
        </span>

        <span class="quick-arrow">→</span>
      </button>

    </div>
  </section>

  <!-- DANH SÁCH CƠ SỞ -->

  <section class="panel table-panel">

    <div class="panel-heading">
      <div>
        <h2>Danh sách cơ sở hoạt động</h2>

        <p class="panel-subtitle">
          Danh sách các cơ sở đang được quản lý trong hệ thống.
        </p>
      </div>

      <span class="tag done">
        {{ branches.length }} cơ sở
      </span>
    </div>

    <div class="table-wrap">
      <table>

        <thead>
          <tr>
            <th>Mã cơ sở</th>
            <th>Tên cơ sở</th>
            <th>Địa chỉ</th>
            <th>Trạng thái</th>
          </tr>
        </thead>

        <tbody>

          <tr v-if="!branches.length">
            <td
              colspan="4"
              class="empty-state"
            >
              Chưa có cơ sở đang hoạt động.
            </td>
          </tr>

          <tr
            v-for="b in paginatedBranches"
            :key="b.MaCS"
          >
            <td class="code-cell">
              #{{ b.MaCS }}
            </td>

            <td>
              <strong>{{ b.TenCS }}</strong>
            </td>

            <td>
              {{ b.DiaChi || 'Chưa cập nhật' }}
            </td>

            <td>
              <span class="tag done">
                {{ b.TrangThai || 'Đang hoạt động' }}
              </span>
            </td>
          </tr>

        </tbody>

      </table>
    </div>

    <!-- THANH PHÂN TRANG -->

    <PaginationBar
      v-if="branches.length"
      v-model:page="currentPage"
      v-model:page-size="pageSize"
      :total="branches.length"
    />

  </section>
</template>
