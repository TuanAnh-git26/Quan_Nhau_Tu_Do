
<script setup>
import { computed, watch } from 'vue'

const props = defineProps({
  page: {
    type: Number,
    default: 1
  },
  pageSize: {
    type: Number,
    default: 10
  },
  total: {
    type: Number,
    default: 0
  },
  pageSizeOptions: {
    type: Array,
    default: () => [10, 20, 50, 100]
  }
})

const emit = defineEmits([
  'update:page',
  'update:pageSize'
])

const totalCount = computed(() =>
  Math.max(0, Number(props.total) || 0)
)

const safePageSize = computed(() =>
  Math.max(1, Number(props.pageSize) || 10)
)

const totalPages = computed(() =>
  Math.max(1, Math.ceil(totalCount.value / safePageSize.value))
)

const currentPage = computed(() =>
  Math.min(
    Math.max(1, Number(props.page) || 1),
    totalPages.value
  )
)

const startItem = computed(() =>
  totalCount.value === 0
    ? 0
    : (currentPage.value - 1) * safePageSize.value + 1
)

const endItem = computed(() =>
  Math.min(
    currentPage.value * safePageSize.value,
    totalCount.value
  )
)

const pageSizes = computed(() => {
  const options = props.pageSizeOptions
    .map(Number)
    .filter(value => Number.isFinite(value) && value > 0)

  if (!options.includes(safePageSize.value)) {
    options.push(safePageSize.value)
  }

  return [...new Set(options)].sort((a, b) => a - b)
})

const visiblePages = computed(() => {
  const total = totalPages.value
  const current = currentPage.value

  if (total <= 5) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  let start = Math.max(1, current - 2)
  let end = Math.min(total, start + 4)

  start = Math.max(1, end - 4)

  return Array.from(
    { length: end - start + 1 },
    (_, i) => start + i
  )
})

function goToPage(page) {
  const nextPage = Math.min(
    Math.max(1, Number(page)),
    totalPages.value
  )

  if (nextPage !== props.page) {
    emit('update:page', nextPage)
  }
}

function changePageSize(event) {
  const size = Number(event.target.value)

  emit('update:pageSize', size)
  emit('update:page', 1)
}

watch(
  [totalCount, safePageSize],
  () => {
    if (props.page > totalPages.value) {
      emit('update:page', totalPages.value)
    } else if (props.page < 1) {
      emit('update:page', 1)
    }
  }
)
</script>

<template>
  <div
    v-if="totalCount > 0"
    class="pagination-bar"
  >
    <!-- Thông tin kết quả -->
    <div class="pagination-summary">
      <span>Hiển thị</span>
      <strong>{{ startItem }}–{{ endItem }}</strong>
      <span>trong</span>
      <strong>{{ totalCount }}</strong>
      <span>kết quả</span>
    </div>

    <!-- Bộ điều khiển -->
    <div class="pagination-actions">
      <!-- Các nút phân trang -->
      <nav
        class="pagination-nav"
        aria-label="Điều hướng trang"
      >
        <button
          type="button"
          class="pagination-button"
          :disabled="currentPage <= 1"
          aria-label="Trang trước"
          @click="goToPage(currentPage - 1)"
        >
          ‹
        </button>

        <button
          v-for="number in visiblePages"
          :key="number"
          type="button"
          class="pagination-button"
          :class="{
            'is-active': currentPage === number
          }"
          :aria-current="currentPage === number ? 'page' : undefined"
          @click="goToPage(number)"
        >
          {{ number }}
        </button>

        <button
          type="button"
          class="pagination-button"
          :disabled="currentPage >= totalPages"
          aria-label="Trang tiếp theo"
          @click="goToPage(currentPage + 1)"
        >
          ›
        </button>
      </nav>

    </div>
  </div>
</template>

<style scoped>
.pagination-bar {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px 20px;
  padding: 17px 0 2px;
  border-top: 1px solid #edf0f4;
}

.pagination-summary {
  display: flex;
  align-items: center;
  gap: 5px;
  color: #8492a1;
  font-size: 12px;
  white-space: nowrap;
}

.pagination-summary strong {
  color: #304b63;
  font-weight: 700;
}

.pagination-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 17px;
  margin-left: auto;
}

.pagination-size {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 8px;
  color: #718397;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
}

.pagination-size select.pagination-select {
  width: auto;
  min-width: 100px;
  max-width: 125px;
  min-height: 36px;
  height: 36px;
  padding: 6px 28px 6px 10px;
  border: 1px solid #dce4ec;
  border-radius: 7px;
  background: #fff;
  color: #38536a;
  font-size: 12px;
  outline: none;
  box-shadow: none;
}

.pagination-size select.pagination-select:focus {
  border-color: #8ba9c0;
  box-shadow: 0 0 0 3px rgba(75,133,173,.09);
}

.pagination-nav {
  display: flex;
  align-items: center;
  gap: 5px;
}

.pagination-button {
  min-width: 35px;
  height: 35px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 9px;
  border: 1px solid #dce4ec;
  border-radius: 7px;
  background: #fff;
  color: #526c82;
  font-size: 13px;
  font-weight: 600;
  line-height: 1;
  transition: .2s;
}

.pagination-button:hover:not(:disabled):not(.is-active) {
  background: #f2f6f9;
  border-color: #b9cad8;
}

.pagination-button.is-active {
  color: #fff;
  background: #234866;
  border-color: #234866;
}

.pagination-button:disabled {
  color: #b8c2cc;
  background: #f7f9fb;
  border-color: #e7ebef;
  opacity: 1;
  cursor: not-allowed;
}

@media (max-width: 650px) {
  .pagination-bar {
    align-items: flex-start;
    flex-direction: column;
  }

  .pagination-actions {
    width: 100%;
    margin-left: 0;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
  }

  .pagination-summary {
    flex-wrap: wrap;
  }

  .pagination-size {
    flex-wrap: wrap;
  }
}
</style>
