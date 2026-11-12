
<script setup>
import { computed, onMounted, ref } from 'vue'
import api from './services/api'
import DashboardView from './views/DashboardView.vue'
import MenuItemsView from './views/MenuItemsView.vue'
import CategoriesView from './views/CategoriesView.vue'

const currentPage = ref('dashboard')
const mobileMenuOpen = ref(false)
const connected = ref(false)
const checked = ref(false)

const navigation = [
  { id: 'dashboard', label: 'Tổng quan', icon: '▦' },
  { id: 'menu', label: 'Quản lý thực đơn', icon: '▤' },
  { id: 'categories', label: 'Danh mục món', icon: '▥' }
]

const pageTitle = computed(() =>
  navigation.find(item => item.id === currentPage.value)?.label || 'Tổng quan'
)

const today = new Intl.DateTimeFormat('vi-VN', {
  weekday: 'long',
  day: '2-digit',
  month: '2-digit',
  year: 'numeric'
}).format(new Date())

function navigate(page) {
  currentPage.value = page
  mobileMenuOpen.value = false
}

onMounted(async () => {
  try {
    const { data } = await api.get('/health')
    connected.value = data.database === 'connected'
  } catch {
    connected.value = false
  } finally {
    checked.value = true
  }
})
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar" :class="{ 'sidebar-open': mobileMenuOpen }">
      <div class="sidebar-brand">
        <div class="brand-mark">T</div>
        <div class="brand-copy">
          <strong>TỰ DO</strong>
          <small>QUẢN LÝ NHÀ HÀNG</small>
        </div>
        <button class="mobile-close" @click="mobileMenuOpen = false">
          ×
        </button>
      </div>

      <div class="sidebar-caption">TRUNG TÂM ĐIỀU HÀNH</div>

      <nav class="side-navigation">
        <div class="nav-group-label">QUẢN LÝ VẬN HÀNH</div>

        <button
          v-for="item in navigation"
          :key="item.id"
          class="nav-link"
          :class="{ active: currentPage === item.id }"
          @click="navigate(item.id)"
        >
          <span class="nav-symbol">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
          <span v-if="currentPage === item.id" class="nav-active-dot"></span>
        </button>
      </nav>

      <div class="sidebar-spacer"></div>

      <div class="sidebar-status">
        <span
          class="status-orb"
          :class="{ offline: checked && !connected }"
        ></span>
        <div>
          <strong>
            {{ !checked ? 'Đang kiểm tra kết nối' :
               connected ? 'Hệ thống đã kết nối' : 'Mất kết nối máy chủ' }}
          </strong>
          <small>
            {{ connected ? 'Máy chủ và cơ sở dữ liệu' : 'Kiểm tra Laravel và MariaDB' }}
          </small>
        </div>
      </div>

      <div class="sidebar-bottom">
        QUÁN NHẬU TỰ DO · QUẢN LÝ NỘI BỘ
      </div>
    </aside>

    <div
      v-if="mobileMenuOpen"
      class="mobile-scrim"
      @click="mobileMenuOpen = false"
    ></div>

    <div class="app-main">
      <header class="topbar">
        <div class="topbar-left">
          <button class="mobile-menu-button" @click="mobileMenuOpen = true">
            ☰
          </button>
          <div class="breadcrumb">
            <span>Hệ thống quản lý</span>
            <b>/</b>
            <strong>{{ pageTitle }}</strong>
          </div>
        </div>

        <div class="topbar-right">
          <div class="topbar-date">{{ today }}</div>
          <div class="topbar-divider"></div>
          <div class="user-profile">
            <div class="user-avatar">Q8</div>
            <div class="user-meta">
              <strong>Khu vực quản lý</strong>
              <small>Quán Nhậu Tự Do</small>
            </div>
          </div>
        </div>
      </header>

      <main class="main-content">
        <DashboardView
          v-if="currentPage === 'dashboard'"
          @navigate="navigate"
        />
        <MenuItemsView v-else-if="currentPage === 'menu'" />
        <CategoriesView v-else-if="currentPage === 'categories'" />
      </main>

      <footer class="footer">
        <span>© {{ new Date().getFullYear() }} Quán Nhậu Tự Do</span>
        <span>Hệ thống quản lý vận hành nhà hàng</span>
      </footer>
    </div>
  </div>
</template>
