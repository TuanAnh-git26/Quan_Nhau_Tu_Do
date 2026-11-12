<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'

const emit = defineEmits(['navigate'])

const loading = ref(true)
const error = ref('')
const branches = ref([])
const categories = ref([])
const items = ref([])
const selectedBranch = ref('')

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

async function reload() {
  loading.value = true
  error.value = ''

  try {
    const [b, c] = await Promise.all([
      api.get('/branches'),
      api.get('/categories')
    ])

    branches.value = b.data
    categories.value = c.data

    if (!selectedBranch.value && branches.value.length) {
      selectedBranch.value = String(
        branches.value[0].MaCS
      )
    }

    await loadBranchItems()
  } catch {
    error.value =
      'Không thể tải dữ liệu tổng quan. Hãy kiểm tra Laravel và MariaDB.'
  } finally {
    loading.value = false
  }
}

async function loadBranchItems() {
  if (!selectedBranch.value) {
    items.value = []
    return
  }

  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/menu-items', {
      params: {
        MaCS: selectedBranch.value
      }
    })

    items.value = data
  } catch {
    items.value = []
    error.value =
      'Không thể tải thực đơn của cơ sở đã chọn.'
  } finally {
    loading.value = false
  }
}

onMounted(reload)
</script>

<template>
  <div class="page-heading">
    <div>
      <p class="eyebrow">
        TRUNG TÂM ĐIỀU HÀNH
      </p>

      <h1>Tổng quan hoạt động</h1>

      <p class="muted">
        Dữ liệu từ hệ thống quản lý Quán Nhậu Tự Do.
      </p>
    </div>

    <div class="page-heading-actions">
      <button
        class="secondary-btn"
        @click="reload"
      >
        ↻ Làm mới
      </button>
    </div>
  </div>

  <div v-if="error" class="alert error">
    {{ error }}
  </div>

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

  <section class="panel table-panel">
    <div class="panel-heading">
      <div>
        <h2>Danh sách cơ sở hoạt động</h2>

        <p class="panel-subtitle">
          Chỉ hiển thị các cơ sở đang hoạt động trong CSDL
        </p>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Mã</th>
            <th>Cơ sở</th>
            <th>Địa chỉ</th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="!branches.length">
            <td colspan="3" class="empty-state">
              Chưa có cơ sở đang hoạt động.
            </td>
          </tr>

          <tr
            v-for="b in branches"
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
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
