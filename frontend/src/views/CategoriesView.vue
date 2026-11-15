
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'
import PaginationBar from '../components/PaginationBar.vue'

// ================================
// TRẠNG THÁI
// ================================

const categories = ref([])
const loading = ref(false)
const saving = ref(false)

const error = ref('')
const success = ref('')

const modalOpen = ref(false)
const editingId = ref(null)

const search = ref('')
const statusFilter = ref('')

const currentPage = ref(1)
const pageSize = ref(10)

function emptyForm() {
  return {
    TenDM: '',
    NoiCheBien: 'Bep',
    MoTa: '',
    TrangThai: 'Dang hoat dong'
  }
}

const form = ref(emptyForm())

// ================================
// THỐNG KÊ
// ================================

const activeCount = computed(() =>
  categories.value.filter(
    item => item.TrangThai === 'Dang hoat dong'
  ).length
)

const inactiveCount = computed(() =>
  categories.value.filter(
    item => item.TrangThai !== 'Dang hoat dong'
  ).length
)

// ================================
// SẮP XẾP, TÌM KIẾM VÀ LỌC
// ================================

const filteredCategories = computed(() => {
  // Luôn sắp xếp mã danh mục tăng dần trước
  let result = [...categories.value].sort(
    (a, b) => Number(a.MaDM) - Number(b.MaDM)
  )

  const keyword = search.value
    .trim()
    .toLocaleLowerCase('vi-VN')

  if (keyword) {
    result = result.filter(item => {
      const values = [
        item.MaDM,
        item.TenDM,
        item.MoTa,
        item.NoiCheBien
      ]

      return values.some(value =>
        String(value || '')
          .toLocaleLowerCase('vi-VN')
          .includes(keyword)
      )
    })
  }

  if (statusFilter.value) {
    result = result.filter(
      item => item.TrangThai === statusFilter.value
    )
  }

  return result
})

// ================================
// PHÂN TRANG
// ================================

const paginatedCategories = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value

  return filteredCategories.value.slice(
    start,
    start + pageSize.value
  )
})

watch([search, statusFilter], () => {
  currentPage.value = 1
})

watch(filteredCategories, () => {
  const lastPage = Math.max(
    1,
    Math.ceil(filteredCategories.value.length / pageSize.value)
  )

  if (currentPage.value > lastPage) {
    currentPage.value = lastPage
  }
})

// ================================
// TẢI DANH MỤC
// ================================

async function load() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/categories')
    const data = response.data

    categories.value = Array.isArray(data)
      ? data
      : Array.isArray(data?.data)
        ? data.data
        : []

  } catch (e) {
    error.value =
      e.response?.data?.message ||
      'Không tải được danh mục. Vui lòng kiểm tra kết nối máy chủ.'
  } finally {
    loading.value = false
  }
}

// ================================
// THÊM DANH MỤC
// ================================

function openCreate() {
  editingId.value = null
  form.value = emptyForm()

  error.value = ''
  success.value = ''

  modalOpen.value = true
}

// ================================
// CHỈNH SỬA DANH MỤC
// ================================

function openEdit(item) {
  editingId.value = item.MaDM

  form.value = {
    TenDM: item.TenDM,
    NoiCheBien: item.NoiCheBien,
    MoTa: item.MoTa || '',
    TrangThai: item.TrangThai
  }

  error.value = ''
  success.value = ''

  modalOpen.value = true
}

// ================================
// LƯU DANH MỤC
// ================================

async function save() {
  if (!form.value.TenDM.trim()) {
    error.value = 'Vui lòng nhập tên danh mục.'
    return
  }

  saving.value = true
  error.value = ''
  success.value = ''

  try {
    const payload = {
      TenDM: form.value.TenDM.trim(),
      NoiCheBien: form.value.NoiCheBien,
      MoTa: form.value.MoTa.trim(),
      TrangThai: form.value.TrangThai
    }

    const isEditing = editingId.value !== null

    if (isEditing) {
      await api.put(
        `/categories/${editingId.value}`,
        payload
      )
    } else {
      await api.post('/categories', payload)
    }

    modalOpen.value = false
    currentPage.value = 1

    success.value = isEditing
      ? 'Đã cập nhật danh mục thành công.'
      : 'Đã thêm danh mục mới thành công.'

    await load()

  } catch (e) {
    const errors = e.response?.data?.errors

    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : (
          e.response?.data?.message ||
          'Không thể lưu danh mục.'
        )
  } finally {
    saving.value = false
  }
}

// ================================
// NGỪNG HOẠT ĐỘNG
// ================================

async function disable(item) {
  const confirmed = confirm(
    `Bạn có chắc muốn ngừng hoạt động danh mục "${item.TenDM}"?`
  )

  if (!confirmed) return

  error.value = ''
  success.value = ''

  try {
    await api.delete(`/categories/${item.MaDM}`)

    success.value = 'Đã ngừng hoạt động danh mục.'
    await load()

  } catch (e) {
    error.value =
      e.response?.data?.message ||
      'Không thể cập nhật trạng thái danh mục.'
  }
}

// ================================
// HIỂN THỊ
// ================================

function departmentName(value) {
  if (value === 'Bep') return 'Bếp'
  if (value === 'Bar') return 'Quầy pha chế'

  return 'Chưa xác định'
}

function statusLabel(value) {
  return value === 'Dang hoat dong'
    ? 'Đang hoạt động'
    : 'Ngừng hoạt động'
}

onMounted(load)
</script>

<template>
  <div class="ui-view ui-view--categories">
  <div class="page-heading">
    <div>
      <p class="eyebrow">CẤU HÌNH THỰC ĐƠN</p>
      <h1>Danh mục món</h1>
      <p class="muted">
        Quản lý nhóm món ăn, đồ uống và bộ phận phụ trách chế biến.
      </p>
    </div>

    <div class="page-heading-actions">
      <button class="primary-btn" @click="openCreate">
        ＋ Thêm danh mục
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

  <section class="stat-grid">
    <article class="stat-card">
      <span class="stat-icon purple">▦</span>
      <div>
        <span class="stat-label">Tổng danh mục</span>
        <strong>{{ loading ? '…' : categories.length }}</strong>
        <small>Các nhóm thực đơn</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon green">✓</span>
      <div>
        <span class="stat-label">Đang hoạt động</span>
        <strong>{{ loading ? '…' : activeCount }}</strong>
        <small>Được phép sử dụng</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon blue">♨</span>
      <div>
        <span class="stat-label">Ngừng hoạt động</span>
        <strong>{{ loading ? '…' : inactiveCount }}</strong>
        <small>Danh mục đã ngừng sử dụng</small>
      </div>
    </article>
  </section>

  <!-- TÌM KIẾM VÀ LỌC -->

  <section class="panel filter-panel">
    <div class="filter-field">
      <label>Tìm kiếm danh mục</label>
      <input
        v-model="search"
        type="search"
        placeholder="Nhập mã hoặc tên danh mục..."
      />
    </div>

    <div class="filter-field">
      <label>Trạng thái</label>
      <select v-model="statusFilter">
        <option value="">Tất cả trạng thái</option>
        <option value="Dang hoat dong">Đang hoạt động</option>
        <option value="Ngung hoat dong">Ngừng hoạt động</option>
      </select>
    </div>
  </section>

  <!-- BẢNG DANH MỤC -->

  <section class="panel table-panel">
    <div class="panel-heading">
      <div>
        <h2>Danh sách danh mục thực đơn</h2>
        <p class="panel-subtitle">
          {{ filteredCategories.length }} danh mục phù hợp
        </p>
      </div>

      <button
        class="secondary-btn"
        @click="load"
        :disabled="loading"
      >
        ↻ {{ loading ? 'Đang tải...' : 'Làm mới' }}
      </button>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Mã danh mục</th>
            <th>Tên danh mục</th>
            <th>Bộ phận phụ trách</th>
            <th>Mô tả</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="empty-state">
              Đang tải danh mục...
            </td>
          </tr>

          <tr v-else-if="!filteredCategories.length">
            <td colspan="6" class="empty-state">
              Không tìm thấy danh mục phù hợp.
            </td>
          </tr>

          <tr
            v-for="item in paginatedCategories"
            :key="item.MaDM"
          >
            <td class="code-cell">
              #{{ item.MaDM }}
            </td>

            <td>
              <strong>{{ item.TenDM }}</strong>
            </td>

            <td>
              {{ departmentName(item.NoiCheBien) }}
            </td>

            <td>
              {{ item.MoTa || 'Chưa cập nhật' }}
            </td>

            <td>
              <span
                class="tag"
                :class="
                  item.TrangThai === 'Dang hoat dong'
                    ? 'done'
                    : 'off'
                "
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
                  v-if="item.TrangThai === 'Dang hoat dong'"
                  class="text-btn danger-text"
                  @click="disable(item)"
                >
                  Ngừng hoạt động
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
        v-if="filteredCategories.length"
        v-model:page="currentPage"
        v-model:page-size="pageSize"
        :total="filteredCategories.length"
      />
    </div>
  </section>

  <!-- FORM THÊM VÀ CHỈNH SỬA -->

  <div
    v-if="modalOpen"
    class="modal-backdrop"
    @click.self="modalOpen = false"
  >
    <form
      class="modal-card"
      @submit.prevent="save"
    >
      <div class="modal-heading">
        <div>
          <p class="eyebrow">CẤU HÌNH THỰC ĐƠN</p>
          <h2>
            {{
              editingId !== null
                ? 'Cập nhật danh mục'
                : 'Thêm danh mục mới'
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
        Tên danh mục <span>*</span>
        <input
          v-model.trim="form.TenDM"
          required
          maxlength="120"
          placeholder="Ví dụ: Món khai vị"
        />
      </label>

      <label>
        Bộ phận phụ trách <span>*</span>
        <select v-model="form.NoiCheBien" required>
          <option value="Bep">Bếp</option>
          <option value="Bar">Quầy pha chế</option>
        </select>
      </label>

      <label>
        Mô tả
        <textarea
          v-model.trim="form.MoTa"
          rows="3"
          placeholder="Mô tả ngắn về danh mục"
        ></textarea>
      </label>

      <label>
        Trạng thái
        <select v-model="form.TrangThai">
          <option value="Dang hoat dong">Đang hoạt động</option>
          <option value="Ngung hoat dong">Ngừng hoạt động</option>
        </select>
      </label>

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
          {{ saving ? 'Đang lưu...' : 'Lưu danh mục' }}
        </button>
      </div>
    </form>
  </div>

  </div>
</template>
