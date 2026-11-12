<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'

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

function emptyForm() {
  return {
    TenMon: '',
    DonViTinh: 'Phần',
    MaDM: '',
    TrangThai: 'Dang ban',
    MaCS: '',
    DonGia: '',
    TrangThaiBan: 'Ngung ban'
  }
}

const form = ref(emptyForm())

const availableCount = computed(() =>
  items.value.filter(x => x.CoTheBan).length
)

const missingPriceCount = computed(() =>
  items.value.filter(x => x.DonGia === null).length
)

const currentBranchName = computed(() =>
  branches.value.find(
    x => String(x.MaCS) === selectedBranch.value
  )?.TenCS || 'cơ sở đã chọn'
)

function money(price) {
  return price === null || price === undefined
    ? 'Chưa cập nhật giá'
    : new Intl.NumberFormat('vi-VN').format(Number(price)) + ' ₫'
}

function statusLabel(item) {
  if (item.TrangThai === 'Ngung ban') {
    return 'Ngừng toàn hệ thống'
  }

  if (item.TrangThaiDanhMuc !== 'Dang hoat dong') {
    return 'Danh mục ngừng hoạt động'
  }

  if (item.DonGia === null) {
    return 'Chưa cập nhật giá'
  }

  return item.CoTheBan
    ? 'Đang phục vụ'
    : 'Ngừng tại cơ sở'
}

function categoryName(name) {
  return {
    'Mon nhau': 'Món nhậu',
    'Mon khai vi': 'Món khai vị',
    'Do uong': 'Đồ uống'
  }[name] || name
}

async function loadBase() {
  try {
    const [b, c] = await Promise.all([
      api.get('/branches'),
      api.get('/categories')
    ])

    branches.value = b.data
    categories.value = c.data

    if (branches.value.length) {
      selectedBranch.value = String(branches.value[0].MaCS)
    }
  } catch {
    error.value = 'Không tải được danh sách cơ sở hoặc danh mục.'
  }
}

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

    const { data } = await api.get('/menu-items', {
      params
    })

    items.value = data
  } catch (e) {
    error.value = e.response?.data?.message ||
      'Không thể tải dữ liệu thực đơn.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null

  form.value = {
    ...emptyForm(),
    MaCS: selectedBranch.value,
    MaDM: categories.value.find(
      c => c.TrangThai === 'Dang hoat dong'
    )?.MaDM || ''
  }

  error.value = ''
  success.value = ''
  modalOpen.value = true
}

function openEdit(item) {
  editingId.value = item.MaMon

  form.value = {
    TenMon: item.TenMon,
    DonViTinh: item.DonViTinh,
    MaDM: item.MaDM,
    TrangThai: item.TrangThai,
    MaCS: selectedBranch.value,
    DonGia: item.DonGia === null
      ? ''
      : Number(item.DonGia),
    TrangThaiBan: item.TrangThaiBan || 'Ngung ban'
  }

  error.value = ''
  success.value = ''
  modalOpen.value = true
}

async function saveItem() {
  error.value = ''
  success.value = ''

  if (
    form.value.DonGia === '' &&
    form.value.TrangThaiBan === 'Dang ban'
  ) {
    error.value =
      'Hãy nhập giá đã xác minh trước khi bật bán tại cơ sở.'
    return
  }

  saving.value = true

  const payload = {
    ...form.value,
    MaCS: Number(form.value.MaCS),
    MaDM: Number(form.value.MaDM),
    DonGia: form.value.DonGia === ''
      ? null
      : Number(form.value.DonGia)
  }

  try {
    if (editingId.value === null) {
      await api.post('/menu-items', payload)
    } else {
      await api.put(
        `/menu-items/${editingId.value}`,
        payload
      )
    }

    modalOpen.value = false
    success.value = 'Đã lưu thông tin món thành công.'
    await loadItems()
  } catch (e) {
    const fields = e.response?.data?.errors

    error.value = fields
      ? Object.values(fields).flat().join(' ')
      : e.response?.data?.message ||
        'Lưu thông tin món thất bại.'
  } finally {
    saving.value = false
  }
}

async function changeBranchStatus(item, value) {
  const verb = value === 'Dang ban'
    ? 'bật bán'
    : 'ngừng bán'

  if (!confirm(
    `Bạn muốn ${verb} "${item.TenMon}" tại ${currentBranchName.value}?`
  )) {
    return
  }

  error.value = ''
  success.value = ''

  try {
    await api.put(
      `/menu-items/${item.MaMon}/branch-status`,
      {
        MaCS: Number(selectedBranch.value),
        TrangThaiBan: value
      }
    )

    success.value =
      `Đã cập nhật trạng thái món tại ${currentBranchName.value}.`

    await loadItems()
  } catch (e) {
    const fields = e.response?.data?.errors

    error.value = fields
      ? Object.values(fields).flat().join(' ')
      : e.response?.data?.message ||
        'Không thể cập nhật trạng thái.'
  }
}

watch(
  [
    selectedBranch,
    search,
    categoryFilter,
    statusFilter
  ],
  loadItems
)

onMounted(loadBase)
</script>

<template>
  <div class="page-heading">
    <div>
      <p class="eyebrow">QUẢN LÝ THỰC ĐƠN</p>
      <h1>Món ăn và đồ uống</h1>
      <p class="muted">
        Giá và tình trạng phục vụ được quản lý riêng
        tại từng cơ sở.
      </p>
    </div>

    <div class="page-heading-actions">
      <button
        class="primary-btn"
        :disabled="!selectedBranch"
        @click="openCreate"
      >
        ＋ Thêm món mới
      </button>
    </div>
  </div>

  <div v-if="success" class="alert success">
    {{ success }}
  </div>

  <div v-if="error && !modalOpen" class="alert error">
    {{ error }}
  </div>

  <section class="stat-grid">
    <article class="stat-card">
      <span class="stat-icon blue">▤</span>
      <div>
        <span class="stat-label">Tổng số món</span>
        <strong>{{ items.length }}</strong>
        <small>Theo bộ lọc hiện tại</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon green">✓</span>
      <div>
        <span class="stat-label">
          Có thể phục vụ tại cơ sở
        </span>
        <strong>{{ availableCount }}</strong>
        <small>Đã có giá và được bật bán</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon purple">▦</span>
      <div>
        <span class="stat-label">
          Chưa có giá tại cơ sở
        </span>
        <strong>{{ missingPriceCount }}</strong>
        <small>
          Cần cập nhật trước khi nhận order
        </small>
      </div>
    </article>
  </section>

  <section class="panel filter-panel">
    <div class="filter-field">
      <label>Tìm kiếm món</label>
      <input
        v-model="search"
        placeholder="Nhập tên món…"
      />
    </div>

    <div class="filter-field">
      <label>Cơ sở</label>
      <select v-model="selectedBranch">
        <option
          v-for="b in branches"
          :key="b.MaCS"
          :value="String(b.MaCS)"
        >
          {{ b.TenCS }}
        </option>
      </select>
    </div>

    <div class="filter-field">
      <label>Danh mục</label>
      <select v-model="categoryFilter">
        <option value="">
          Tất cả danh mục
        </option>
        <option
          v-for="c in categories"
          :key="c.MaDM"
          :value="String(c.MaDM)"
        >
          {{ categoryName(c.TenDM) }}
        </option>
      </select>
    </div>

    <div class="filter-field">
      <label>Trạng thái chung</label>
      <select v-model="statusFilter">
        <option value="">Tất cả</option>
        <option value="Dang ban">
          Đang bật bán toàn hệ thống
        </option>
        <option value="Ngung ban">
          Ngừng bán toàn hệ thống
        </option>
      </select>
    </div>
  </section>

  <section class="panel table-panel">
    <div class="panel-heading">
      <div>
        <h2>Danh sách thực đơn</h2>
        <p class="panel-subtitle">
          {{ currentBranchName }} · {{ items.length }} món
        </p>
      </div>
      <button
        class="secondary-btn"
        @click="loadItems"
      >
        ↻ Làm mới
      </button>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Mã món</th>
            <th>Tên món</th>
            <th>Danh mục</th>
            <th>Đơn vị</th>
            <th>Giá tại cơ sở</th>
            <th>Tình trạng</th>
            <th>Thao tác</th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td colspan="7" class="empty-state">
              Đang tải dữ liệu…
            </td>
          </tr>

          <tr v-else-if="!items.length">
            <td colspan="7" class="empty-state">
              Không có món phù hợp.
            </td>
          </tr>

          <tr
            v-for="item in items"
            :key="item.MaMon"
          >
            <td class="code-cell">
              #{{ item.MaMon }}
            </td>

            <td>
              <div class="menu-item-name">
                <span class="menu-item-thumb">
                  {{ item.TenMon.charAt(0) }}
                </span>
                <strong>{{ item.TenMon }}</strong>
              </div>
            </td>

            <td>
              <span class="category-chip">
                {{ categoryName(item.TenDM) }}
              </span>
            </td>

            <td>{{ item.DonViTinh }}</td>

            <td class="price-cell">
              {{ money(item.DonGia) }}
            </td>

            <td>
              <span
                class="tag"
                :class="item.CoTheBan ? 'done' : 'off'"
              >
                {{ statusLabel(item) }}
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
                  v-if="
                    item.TrangThaiBan === 'Dang ban' &&
                    item.DonGia !== null
                  "
                  class="text-btn danger-text"
                  @click="
                    changeBranchStatus(
                      item,
                      'Ngung ban'
                    )
                  "
                >
                  Ngừng tại cơ sở
                </button>

                <button
                  v-else-if="
                    item.DonGia !== null &&
                    item.TrangThai === 'Dang ban' &&
                    item.TrangThaiDanhMuc === 'Dang hoat dong'
                  "
                  class="text-btn"
                  @click="
                    changeBranchStatus(
                      item,
                      'Dang ban'
                    )
                  "
                >
                  Bật tại cơ sở
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

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
          <p class="eyebrow">
            THỰC ĐƠN VÀ GIÁ THEO CƠ SỞ
          </p>
          <h2>
            {{
              editingId === null
                ? 'Thêm món mới'
                : 'Chỉnh sửa món'
            }}
          </h2>
        </div>

        <button
          type="button"
          class="close-btn"
          @click="modalOpen = false"
        >
          ×
        </button>
      </div>

      <div v-if="error" class="alert error">
        {{ error }}
      </div>

      <label>
        Tên món <span>*</span>
        <input
          v-model.trim="form.TenMon"
          maxlength="180"
          required
        />
      </label>

      <div class="form-grid">
        <label>
          Danh mục <span>*</span>
          <select
            v-model="form.MaDM"
            required
          >
            <option
              value=""
              disabled
            >
              Chọn danh mục
            </option>

            <option
              v-for="c in categories"
              :key="c.MaDM"
              :value="c.MaDM"
            >
              {{ categoryName(c.TenDM) }}
            </option>
          </select>
        </label>

        <label>
          Đơn vị tính <span>*</span>
          <input
            v-model.trim="form.DonViTinh"
            maxlength="30"
            required
          />
        </label>
      </div>

      <label>
        Trạng thái món trên toàn hệ thống
        <select v-model="form.TrangThai">
          <option value="Dang ban">
            Đang bật bán
          </option>
          <option value="Ngung ban">
            Ngừng bán toàn hệ thống
          </option>
        </select>
      </label>

      <div class="form-grid">
        <label>
          Cơ sở cập nhật giá
          <select
            v-model="form.MaCS"
            required
          >
            <option
              v-for="b in branches"
              :key="b.MaCS"
              :value="String(b.MaCS)"
            >
              {{ b.TenCS }}
            </option>
          </select>
        </label>

        <label>
          Giá đã xác minh (đồng)
          <input
            v-model="form.DonGia"
            type="number"
            min="0"
            step="1"
            placeholder="Để trống khi chưa có giá"
          />
        </label>
      </div>

      <label>
        Trạng thái bán tại cơ sở
        <select v-model="form.TrangThaiBan">
          <option value="Ngung ban">
            Chưa bán / Ngừng bán
          </option>
          <option value="Dang ban">
            Cho phép phục vụ
          </option>
        </select>
      </label>

      <p class="form-hint">
        Nếu chưa xác minh giá, để ô giá trống và chọn
        “Chưa bán / Ngừng bán”. Nếu món đã có giá,
        việc để trống khi sửa sẽ không xóa giá cũ.
        Thay đổi tại một cơ sở không ảnh hưởng đến
        giá của các cơ sở khác.
      </p>

      <div class="modal-actions">
        <button
          type="button"
          class="secondary-btn"
          @click="modalOpen = false"
        >
          Hủy
        </button>

        <button
          type="submit"
          class="primary-btn"
          :disabled="saving"
        >
          {{ saving ? 'Đang lưu…' : 'Lưu thông tin' }}
        </button>
      </div>
    </form>
  </div>
</template>
