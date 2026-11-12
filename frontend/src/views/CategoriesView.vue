
<script setup>
import { onMounted, ref } from 'vue'
import api from '../services/api'

const categories = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const modalOpen = ref(false)
const editingId = ref(null)

function emptyForm() {
  return {
    TenDM: '',
    NoiCheBien: 'Bep',
    MoTa: '',
    TrangThai: 'Dang hoat dong'
  }
}

const form = ref(emptyForm())

async function loadCategories() {
  loading.value = true
  error.value = ''

  try {
    const response = await api.get('/categories')
    categories.value = response.data
  } catch {
    error.value = 'Không thể tải danh mục món ăn.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null
  form.value = emptyForm()
  modalOpen.value = true
  error.value = ''
  success.value = ''
}

function openEdit(item) {
  editingId.value = item.MaDM
  form.value = {
    TenDM: item.TenDM,
    NoiCheBien: item.NoiCheBien,
    MoTa: item.MoTa || '',
    TrangThai: item.TrangThai
  }
  modalOpen.value = true
  error.value = ''
  success.value = ''
}

async function saveCategory() {
  saving.value = true
  error.value = ''

  try {
    if (editingId.value !== null) {
      await api.put(`/categories/${editingId.value}`, form.value)
    } else {
      await api.post('/categories', form.value)
    }

    modalOpen.value = false
    success.value = 'Đã lưu danh mục thành công.'
    await loadCategories()
  } catch (e) {
    const errors = e.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : e.response?.data?.message || 'Không thể lưu danh mục.'
  } finally {
    saving.value = false
  }
}

async function disableCategory(item) {
  if (!confirm(`Ngừng hoạt động danh mục "${item.TenDM}"?`)) return

  try {
    await api.delete(`/categories/${item.MaDM}`)
    success.value = 'Đã ngừng hoạt động danh mục.'
    await loadCategories()
  } catch (e) {
    error.value = e.response?.data?.message ||
      'Không thể cập nhật trạng thái.'
  }
}

onMounted(loadCategories)
</script>

<template>
  <div class="page-heading">
    <div>
      <p class="eyebrow">CẤU HÌNH THỰC ĐƠN</p>
      <h1>Danh mục món</h1>
      <p class="muted">
        Phân nhóm món ăn, đồ uống và bộ phận chế biến.
      </p>
    </div>
    <div class="page-heading-actions">
      <button class="primary-btn" @click="openCreate">
        ＋ Thêm danh mục
      </button>
    </div>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="success" class="alert success">{{ success }}</div>

  <section class="stat-grid">
    <article class="stat-card">
      <span class="stat-icon purple">▦</span>
      <div>
        <span class="stat-label">Tổng danh mục</span>
        <strong>{{ categories.length }}</strong>
        <small>Các nhóm thực đơn</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon green">✓</span>
      <div>
        <span class="stat-label">Đang hoạt động</span>
        <strong>
          {{ categories.filter(
            c => c.TrangThai === 'Dang hoat dong'
          ).length }}
        </strong>
        <small>Danh mục được phép sử dụng</small>
      </div>
    </article>

    <article class="stat-card">
      <span class="stat-icon blue">♨</span>
      <div>
        <span class="stat-label">Bộ phận chế biến</span>
        <strong>2</strong>
        <small>Bếp và quầy pha chế</small>
      </div>
    </article>
  </section>

  <section class="panel table-panel">
    <div class="panel-heading">
      <div>
        <h2>Danh sách danh mục</h2>
        <p class="panel-subtitle">
          {{ categories.length }} danh mục trong hệ thống
        </p>
      </div>
      <button class="secondary-btn" @click="loadCategories">
        ↻ Làm mới
      </button>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Mã</th>
            <th>Tên danh mục</th>
            <th>Bộ phận</th>
            <th>Mô tả</th>
            <th>Trạng thái</th>
            <th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="empty-state">
              Đang tải dữ liệu…
            </td>
          </tr>
          <tr v-else-if="!categories.length">
            <td colspan="6" class="empty-state">
              Chưa có danh mục.
            </td>
          </tr>

          <tr v-for="item in categories" :key="item.MaDM">
            <td class="code-cell">#{{ item.MaDM }}</td>
            <td><strong>{{ item.TenDM }}</strong></td>
            <td>
              {{ item.NoiCheBien === 'Bep' ? 'Bếp' : 'Quầy pha chế' }}
            </td>
            <td>{{ item.MoTa || 'Chưa cập nhật' }}</td>
            <td>
              <span
                class="tag"
                :class="item.TrangThai === 'Dang hoat dong' ? 'done' : 'off'"
              >
                {{ item.TrangThai === 'Dang hoat dong'
                  ? 'Đang hoạt động' : 'Ngừng hoạt động' }}
              </span>
            </td>
            <td>
              <div class="actions">
                <button class="text-btn" @click="openEdit(item)">
                  Chỉnh sửa
                </button>
                <button
                  v-if="item.TrangThai === 'Dang hoat dong'"
                  class="text-btn danger-text"
                  @click="disableCategory(item)"
                >
                  Ngừng hoạt động
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
    <form class="modal-card" @submit.prevent="saveCategory">
      <div class="modal-heading">
        <div>
          <p class="eyebrow">CẤU HÌNH DANH MỤC</p>
          <h2>
            {{ editingId !== null ? 'Chỉnh sửa danh mục' : 'Thêm danh mục mới' }}
          </h2>
        </div>
        <button
          type="button"
          class="close-btn"
          @click="modalOpen = false"
        >×</button>
      </div>

      <label>
        Tên danh mục <span>*</span>
        <input
          v-model.trim="form.TenDM"
          required
          maxlength="120"
          placeholder="Ví dụ: Món nướng"
        />
      </label>

      <label>
        Bộ phận phụ trách
        <select v-model="form.NoiCheBien">
          <option value="Bep">Bếp</option>
          <option value="Bar">Quầy pha chế</option>
        </select>
      </label>

      <label>
        Mô tả
        <textarea
          v-model="form.MoTa"
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
        >Hủy</button>
        <button type="submit" class="primary-btn" :disabled="saving">
          {{ saving ? 'Đang lưu…' : 'Lưu danh mục' }}
        </button>
      </div>
    </form>
  </div>
</template>
