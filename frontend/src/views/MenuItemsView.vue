
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'
import PaginationBar from '../components/PaginationBar.vue'

// ================================
// TRẠNG THÁI
// ================================

const items = ref([])
const categories = ref([])
const branches = ref([])

const selectedBranch = ref('')
const search = ref('')
const categoryFilter = ref('')
const statusFilter = ref('')

const loading = ref(false)
const saving = ref(false)

const error = ref('')
const success = ref('')

const modalOpen = ref(false)
const editingId = ref(null)

const currentPage = ref(1)
const pageSize = ref(10)

function emptyForm() {
  return {
    TenMon: '',
    DonViTinh: 'Đĩa',
    MaDM: '',
    TrangThai: 'Dang ban',
    MaCS: '',
    DonGia: 0
  }
}

const form = ref(emptyForm())

// ================================
// SẮP XẾP DANH MỤC
// ================================

const sortedCategories = computed(() =>
  [...categories.value].sort(
    (a, b) => Number(a.MaDM) - Number(b.MaDM)
  )
)

// ================================
// SẮP XẾP MÓN ĂN
// ================================

const filteredItems = computed(() =>
  [...items.value].sort(
    (a, b) => Number(a.MaMon) - Number(b.MaMon)
  )
)

// ================================
// THỐNG KÊ
// ================================

const activeCount = computed(() =>
  items.value.filter(
    item => item.TrangThai === 'Dang ban'
  ).length
)

// ================================
// PHÂN TRANG
// ================================

const paginatedItems = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value

  return filteredItems.value.slice(
    start,
    start + pageSize.value
  )
})

watch(filteredItems, () => {
  const lastPage = Math.max(
    1,
    Math.ceil(filteredItems.value.length / pageSize.value)
  )

  if (currentPage.value > lastPage) {
    currentPage.value = lastPage
  }
})

// ================================
// TẢI DANH MỤC VÀ CƠ SỞ
// ================================

function normalizeList(data) {
  if (Array.isArray(data)) return data
  if (Array.isArray(data?.data)) return data.data

  return []
}

async function loadBase() {
  try {
    const [categoryResponse, branchResponse] =
      await Promise.all([
        api.get('/categories'),
        api.get('/branches')
      ])

    categories.value = normalizeList(categoryResponse.data)
      .sort((a, b) => Number(a.MaDM) - Number(b.MaDM))

    branches.value = normalizeList(branchResponse.data)
      .sort((a, b) => Number(a.MaCS) - Number(b.MaCS))

    if (
      !selectedBranch.value &&
      branches.value.length
    ) {
      selectedBranch.value = String(branches.value[0].MaCS)
    }

  } catch (e) {
    error.value =
      'Không tải được danh mục hoặc cơ sở. Vui lòng kiểm tra kết nối máy chủ.'
  }
}

// ================================
// TẢI THỰC ĐƠN
// ================================

async function loadItems() {
  if (!selectedBranch.value) {
    items.value = []
    return
  }

  loading.value = true
  error.value = ''

  try {
    const params = {
      MaCS: selectedBranch.value
    }

    if (search.value.trim()) {
      params.search = search.value.trim()
    }

    if (categoryFilter.value) {
      params.MaDM = categoryFilter.value
    }

    if (statusFilter.value) {
      params.TrangThai = statusFilter.value
    }

    const response = await api.get('/menu-items', {
      params
    })

    items.value = normalizeList(response.data)
      .sort((a, b) => Number(a.MaMon) - Number(b.MaMon))

  } catch (e) {
    error.value =
      e.response?.data?.message ||
      'Không tải được danh sách món. Vui lòng thử lại.'
  } finally {
    loading.value = false
  }
}

// ================================
// THAY ĐỔI BỘ LỌC
// ================================

function handleBranchChange() {
  currentPage.value = 1
  loadItems()
}

watch(
  [search, categoryFilter, statusFilter],
  () => {
    currentPage.value = 1
    loadItems()
  }
)

// ================================
// THÊM MÓN
// ================================

function openCreate() {
  editingId.value = null

  const firstActiveCategory = sortedCategories.value.find(
    item => item.TrangThai === 'Dang hoat dong'
  )

  form.value = {
    ...emptyForm(),
    MaDM: firstActiveCategory?.MaDM || '',
    MaCS: selectedBranch.value
  }

  error.value = ''
  success.value = ''

  modalOpen.value = true
}

// ================================
// CHỈNH SỬA MÓN
// ================================

function openEdit(item) {
  editingId.value = item.MaMon

  form.value = {
    TenMon: item.TenMon,
    DonViTinh: item.DonViTinh,
    MaDM: item.MaDM,
    TrangThai: item.TrangThai,
    MaCS: selectedBranch.value,
    DonGia: Number(item.DonGia ?? 0)
  }

  error.value = ''
  success.value = ''

  modalOpen.value = true
}

// ================================
// LƯU MÓN
// ================================

async function saveItem() {
  if (!form.value.TenMon.trim()) {
    error.value = 'Vui lòng nhập tên món ăn.'
    return
  }

  if (!form.value.MaDM || !form.value.MaCS) {
    error.value = 'Vui lòng chọn danh mục và cơ sở.'
    return
  }

  saving.value = true
  error.value = ''
  success.value = ''

  try {
    const payload = {
      ...form.value,
      TenMon: form.value.TenMon.trim(),
      DonViTinh: form.value.DonViTinh.trim(),
      MaDM: Number(form.value.MaDM),
      MaCS: Number(form.value.MaCS),
      DonGia: Number(form.value.DonGia)
    }

    const isEditing = editingId.value !== null

    if (isEditing) {
      await api.put(
        `/menu-items/${editingId.value}`,
        payload
      )
    } else {
      await api.post('/menu-items', payload)
    }

    modalOpen.value = false
    currentPage.value = 1

    success.value = isEditing
      ? 'Đã cập nhật thông tin món ăn.'
      : 'Đã thêm món mới vào thực đơn.'

    await loadItems()

  } catch (e) {
    const errors = e.response?.data?.errors

    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : (
          e.response?.data?.message ||
          'Không thể lưu thông tin món ăn.'
        )
  } finally {
    saving.value = false
  }
}

// ================================
// NGỪNG PHỤC VỤ
// ================================

async function stopSelling(item) {
  const confirmed = confirm(
    `Bạn có chắc muốn ngừng phục vụ món "${item.TenMon}"? Dữ liệu món vẫn được lưu trong hệ thống.`
  )

  if (!confirmed) return

  error.value = ''
  success.value = ''

  try {
    await api.delete(`/menu-items/${item.MaMon}`)

    success.value =
      'Đã chuyển món sang trạng thái ngừng phục vụ.'

    await loadItems()

  } catch (e) {
    error.value =
      e.response?.data?.message ||
      'Không thể cập nhật trạng thái món.'
  }
}

// ================================
// HIỂN THỊ
// ================================

function formatMoney(value) {
  if (value === null || value === undefined) {
    return 'Chưa thiết lập'
  }

  return (
    new Intl.NumberFormat('vi-VN').format(Number(value)) +
    ' đ'
  )
}

function statusLabel(value) {
  return value === 'Dang ban'
    ? 'Đang phục vụ'
    : 'Ngừng phục vụ'
}

function categoryName(item) {
  return (
    item.TenDM ||
    sortedCategories.value.find(
      category =>
        Number(category.MaDM) === Number(item.MaDM)
    )?.TenDM ||
    'Chưa phân loại'
  )
}

function departmentName(value) {
  if (value === 'Bep') return 'Bếp'
  if (value === 'Bar') return 'Quầy pha chế'

  return 'Chưa xác định'
}

// ================================
// KHỞI TẠO
// ================================

onMounted(async () => {
  await loadBase()
  await loadItems()
})
</script>

<template>
  <div class="page-heading">
    <div>
      <p class="eyebrow">THỰC ĐƠN NHÀ HÀNG</p>

      <h1>Quản lý thực đơn</h1>

      <p class="muted">
        Theo dõi món ăn, đồ uống, giá bán và tình trạng phục vụ theo từng cơ sở.
      </p>
    </div>

    <div class="page-heading-actions">
      <button
        class="primary-btn"
        @click="openCreate"
      >
        ＋ Thêm món mới
      </button>
    </div>
  </div>

  <div v-if="error" class="alert error">
    {{ error }}
  </div>

  <div v-if="success" class="alert success">
    {{ success }}
  </div>

  <!-- THỐNG KÊ -->

  <section class="stat-grid menu-stat-grid">
    <article class="stat-card">
      <span class="stat-icon blue">▤</span>
      <div>
        <span class="stat-label">Tổng số món</span>
        <strong>{{ loading ? '…' : filteredItems.length }}</strong>
        <small>Theo bộ lọc hiện tại</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon green">✓</span>
      <div>
        <span class="stat-label">Đang phục vụ</span>
        <strong>{{ loading ? '…' : activeCount }}</strong>
        <small>Sẵn sàng để gọi món</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon purple">▦</span>
      <div>
        <span class="stat-label">Danh mục</span>
        <strong>{{ categories.length }}</strong>
        <small>Nhóm món trong hệ thống</small>
      </div>
    </article>
  </section>

  <!-- BỘ LỌC -->

  <section class="panel filter-panel">
    <div class="filter-field">
      <label>Tìm kiếm món ăn</label>

      <input
        v-model="search"
        type="search"
        placeholder="Nhập mã hoặc tên món cần tìm..."
      />
    </div>

    <div class="filter-field">
      <label>Cơ sở phục vụ</label>

      <select
        v-model="selectedBranch"
        @change="handleBranchChange"
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

    <div class="filter-field">
      <label>Danh mục món</label>

      <select v-model="categoryFilter">
        <option value="">Tất cả danh mục</option>

        <option
          v-for="category in sortedCategories"
          :key="category.MaDM"
          :value="String(category.MaDM)"
        >
          {{ category.TenDM }}
        </option>
      </select>
    </div>

    <div class="filter-field">
      <label>Tình trạng phục vụ</label>

      <select v-model="statusFilter">
        <option value="">Tất cả trạng thái</option>
        <option value="Dang ban">Đang phục vụ</option>
        <option value="Ngung ban">Ngừng phục vụ</option>
      </select>
    </div>
  </section>

  <!-- DANH SÁCH MÓN -->

  <section class="panel table-panel">
    <div class="panel-heading">
      <div>
        <h2>Danh sách món ăn và đồ uống</h2>

        <p class="panel-subtitle">
          {{ filteredItems.length }} món phù hợp với điều kiện tìm kiếm
        </p>
      </div>

      <button
        class="secondary-btn"
        @click="loadItems"
        :disabled="loading"
      >
        ↻ {{ loading ? 'Đang tải...' : 'Làm mới dữ liệu' }}
      </button>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Mã món</th>
            <th>Tên món</th>
            <th>Danh mục</th>
            <th>Bộ phận</th>
            <th>Đơn vị</th>
            <th>Giá bán</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td colspan="8" class="empty-state">
              Đang tải danh sách thực đơn...
            </td>
          </tr>

          <tr v-else-if="!filteredItems.length">
            <td colspan="8" class="empty-state">
              Không tìm thấy món phù hợp. Hãy thay đổi bộ lọc hoặc thêm món mới.
            </td>
          </tr>

          <tr
            v-for="item in paginatedItems"
            :key="item.MaMon"
          >
            <td class="code-cell">
              #{{ item.MaMon }}
            </td>

            <td>
              <div class="menu-item-name">
                <span class="menu-item-thumb">
                  {{
                    (item.TenMon || 'M')
                      .slice(0, 1)
                      .toLocaleUpperCase('vi-VN')
                  }}
                </span>

                <strong>{{ item.TenMon }}</strong>
              </div>
            </td>

            <td>
              <span class="category-chip">
                {{ categoryName(item) }}
              </span>
            </td>

            <td>
              {{ departmentName(item.NoiCheBien) }}
            </td>

            <td>
              {{ item.DonViTinh }}
            </td>

            <td class="price-cell">
              {{ formatMoney(item.DonGia) }}
            </td>

            <td>
              <span
                class="tag"
                :class="item.TrangThai === 'Dang ban' ? 'done' : 'off'"
              >
                {{ statusLabel(item.TrangThai) }}
              </span>
            </td>

            <td>
              <div class="actions">
                <button
                  class="text-btn"
                  @click="openEdit(item)"
                >
                  Chỉnh sửa
                </button>

                <button
                  v-if="item.TrangThai === 'Dang ban'"
                  class="text-btn danger-text"
                  @click="stopSelling(item)"
                >
                  Ngừng phục vụ
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- PHÂN TRANG -->

    <div class="px-5 pb-5">
      <PaginationBar
        v-if="filteredItems.length"
        v-model:page="currentPage"
        v-model:page-size="pageSize"
        :total="filteredItems.length"
      />
    </div>
  </section>

  <!-- FORM THÊM VÀ CHỈNH SỬA MÓN -->

  <div
    v-if="modalOpen"
    class="modal-backdrop"
    @click.self="modalOpen = false"
  >
    <form
      class="modal-card"
      @submit.prevent="saveItem"
    >
      <div class="modal-heading">
        <div>
          <p class="eyebrow">THÔNG TIN THỰC ĐƠN</p>

          <h2>
            {{
              editingId !== null
                ? 'Cập nhật món ăn'
                : 'Thêm món mới'
            }}
          </h2>
        </div>

        <button
          type="button"
          class="close-btn"
          aria-label="Đóng cửa sổ"
          @click="modalOpen = false"
        >
          ×
        </button>
      </div>

      <label>
        Tên món ăn / đồ uống <span>*</span>

        <input
          v-model.trim="form.TenMon"
          required
          maxlength="180"
          placeholder="Ví dụ: Bê thui"
        />
      </label>

      <div class="form-grid">
        <label>
          Danh mục <span>*</span>

          <select v-model="form.MaDM" required>
            <option value="" disabled>
              Chọn danh mục
            </option>

            <option
              v-for="category in sortedCategories.filter(
                item => item.TrangThai === 'Dang hoat dong'
              )"
              :key="category.MaDM"
              :value="category.MaDM"
            >
              {{ category.TenDM }}
            </option>
          </select>
        </label>

        <label>
          Đơn vị tính <span>*</span>

          <input
            v-model.trim="form.DonViTinh"
            required
            placeholder="Đĩa, phần, chai..."
          />
        </label>
      </div>

      <div class="form-grid">
        <label>
          Cơ sở áp dụng <span>*</span>

          <select v-model="form.MaCS" required>
            <option
              v-for="branch in branches"
              :key="branch.MaCS"
              :value="String(branch.MaCS)"
            >
              {{ branch.TenCS }}
            </option>
          </select>
        </label>

        <label>
          Giá bán (VNĐ) <span>*</span>

          <input
            v-model.number="form.DonGia"
            type="number"
            min="0"
            step="1000"
            required
          />
        </label>
      </div>

      <label>
        Tình trạng phục vụ

        <select v-model="form.TrangThai">
          <option value="Dang ban">Đang phục vụ</option>
          <option value="Ngung ban">Ngừng phục vụ</option>
        </select>
      </label>

      <p class="form-hint">
        Giá bán được ghi nhận theo cơ sở đã chọn.
        Khi ngừng phục vụ, thông tin món vẫn được lưu để tra cứu.
      </p>

      <div class="modal-actions">
        <button
          type="button"
          class="secondary-btn"
          @click="modalOpen = false"
        >
          Hủy bỏ
        </button>

        <button
          type="submit"
          class="primary-btn"
          :disabled="saving"
        >
          {{ saving ? 'Đang lưu...' : 'Lưu thông tin' }}
        </button>
      </div>
    </form>
  </div>
</template>
