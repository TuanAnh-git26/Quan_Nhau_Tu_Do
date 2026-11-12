import { createRouter, createWebHistory } from 'vue-router'

import DashboardView from '../views/DashboardView.vue'
import MenuItemsView from '../views/MenuItemsView.vue'
import CategoriesView from '../views/CategoriesView.vue'

const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: DashboardView,
    meta: {
      title: 'Tổng quan',
      description: 'Tổng quan hoạt động hệ thống'
    }
  },
  {
    path: '/thuc-don',
    name: 'menu-items',
    component: MenuItemsView,
    meta: {
      title: 'Quản lý thực đơn',
      description: 'Quản lý món ăn, đồ uống và giá bán'
    }
  },
  {
    path: '/danh-muc-mon',
    name: 'categories',
    component: CategoriesView,
    meta: {
      title: 'Danh mục món',
      description: 'Quản lý nhóm món ăn và đồ uống'
    }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes,

  scrollBehavior() {
    return { top: 0 }
  }
})

export default router
