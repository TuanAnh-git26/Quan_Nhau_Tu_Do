<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'

const props = defineProps({ user: { type: Object, default: null } })
const statuses = {
  'Moi tiep nhan': 'Mới tiếp nhận',
  'Dang xu ly': 'Đang xử lý',
  'Da xu ly': 'Đã xử lý',
  'Da dong': 'Đã đóng',
}
const transitions = {
  'Moi tiep nhan': 'Dang xu ly',
  'Dang xu ly': 'Da xu ly',
  'Da xu ly': 'Da dong',
}
const branches = ref([]), tables = ref([]), branchId = ref('')
const statusFilter = ref(''), q = ref(''), rows = ref([])
const page = ref(1), lastPage = ref(1), total = ref(0), perPage = ref(10)
const loading = ref(false), saving = ref(false), error = ref(''), success = ref('')
const createOpen = ref(false), editOpen = ref(false), current = ref(null)
const createForm = ref({ MaBan: '', MoTa: '' }), resolution = ref('')
const canResolve = computed(() => ['Giam sat', 'Quan tri he thong'].includes(props.user?.TenVaiTro))
const branchName = computed(() => branches.value.find(x => String(x.MaCS) === branchId.value)?.TenCS || 'Chưa chọn cơ sở')
const first = computed(() => total.value ? (page.value - 1) * perPage.value + 1 : 0)
const last = computed(() => Math.min(page.value * perPage.value, total.value))
const message = e => Object.values(e?.response?.data?.errors || {}).flat().join(' ') || e?.response?.data?.message || e?.message || 'Không thể xử lý yêu cầu.'
const dateTime = value => {
  if (!value) return '—'
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('vi-VN')
}
async function initialize() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get('/branches')
    branches.value = Array.isArray(response.data) ? response.data : response.data?.data || []
    branchId.value = String(branches.value.find(x => String(x.MaCS) === String(props.user?.MaCS))?.MaCS || branches.value[0]?.MaCS || '')
    await changeBranch()
  } catch (e) {
    error.value = message(e)
  } finally { loading.value = false }
}
async function changeBranch() {
  if (!branchId.value) return
  page.value = 1
  try {
    const response = await api.get('/tables', { params: { MaCS: Number(branchId.value) } })
    const payload = response.data?.data || response.data
    tables.value = Array.isArray(payload) ? payload : payload?.data || []
    await loadRows()
  } catch (e) { error.value = message(e) }
}
async function loadRows() {
  if (!branchId.value) return
  loading.value = true
  error.value = ''
  try {
    const response = await api.get('/complaints', { params: {
      MaCS: Number(branchId.value), TrangThai: statusFilter.value || undefined,
      q: q.value.trim() || undefined, page: page.value, per_page: perPage.value,
    } })
    const paginator = response.data?.data || response.data
    rows.value = Array.isArray(paginator) ? paginator : paginator?.data || []
    total.value = Number(paginator?.total ?? rows.value.length)
    lastPage.value = Math.max(1, Number(paginator?.last_page || 1))
  } catch (e) { error.value = message(e); rows.value = []; total.value = 0 }
  finally { loading.value = false }
}
function applyFilter() { page.value = 1; loadRows() }
function goPage(value) { if (value < 1 || value > lastPage.value) return; page.value = value; loadRows() }
function openCreate() { createForm.value = { MaBan: '', MoTa: '' }; createOpen.value = true; error.value = ''; success.value = '' }
async function createComplaint() {
  saving.value = true; error.value = ''
  try {
    await api.post('/complaints', { MaBan: Number(createForm.value.MaBan), MoTa: createForm.value.MoTa.trim() })
    createOpen.value = false; success.value = 'Đã lập phiếu khiếu nại.'; page.value = 1; await loadRows()
  } catch (e) { error.value = message(e) }
  finally { saving.value = false }
}
function openEdit(row) { current.value = row; resolution.value = row.HuongXuLy || ''; editOpen.value = true; error.value = ''; success.value = '' }
async function updateComplaint() {
  if (!current.value) return
  saving.value = true; error.value = ''
  try {
    await api.put(`/complaints/${current.value.MaKN}`, {
      TrangThai: transitions[current.value.TrangThai], HuongXuLy: resolution.value.trim() || null,
    })
    editOpen.value = false; success.value = 'Đã cập nhật trạng thái.'; await loadRows()
  } catch (e) { error.value = message(e) }
  finally { saving.value = false }
}
onMounted(initialize)
</script>

<template>
  <div class="ui-view ui-view--complaints">
  <div class="complaints-page">
    <header class="heading">
      <div><p class="eyebrow">UC2.5 · PHÂN HỆ ORDER / PHỤC VỤ</p><h1>Phiếu khiếu nại & sự cố</h1><p>Tiếp nhận theo bàn, theo dõi xử lý và nhật ký tại từng cơ sở.</p></div>
      <button class="btn primary" @click="openCreate">+ Tạo phiếu khiếu nại</button>
    </header>
    <div v-if="error" class="alert error" role="alert">{{ error }}</div>
    <div v-if="success" class="alert success" role="status">{{ success }}</div>
    <section class="panel">
      <div class="filters">
        <label>Cơ sở<select v-model="branchId" @change="changeBranch"><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></label>
        <label>Trạng thái<select v-model="statusFilter" @change="applyFilter"><option value="">Tất cả</option><option v-for="(name,id) in statuses" :key="id" :value="id">{{ name }}</option></select></label>
        <label class="wide">Tìm kiếm<input v-model="q" placeholder="Bàn, nội dung phản ánh..." @keyup.enter="applyFilter"></label>
        <button class="btn" :disabled="loading" @click="applyFilter">Lọc</button>
        <button class="btn" :disabled="loading" @click="loadRows">↻ Làm mới</button>
      </div>
      <p class="hint">Đang xem: {{ branchName }}</p>
      <div class="scroll">
        <table><thead><tr><th>Phiếu</th><th>Bàn</th><th>Phản ánh / hướng xử lý</th><th>Tiếp nhận</th><th>Nhân viên</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.MaKN">
              <td><strong>#{{ row.MaKN }}</strong></td><td>{{ row.SoBan }}<small>{{ row.TenKV }}</small></td>
              <td class="desc">{{ row.MoTa }}<small v-if="row.HuongXuLy">Đã ghi hướng xử lý: {{ row.HuongXuLy }}</small></td>
              <td>{{ dateTime(row.ThoiGianTiepNhan) }}</td><td>{{ row.NguoiTiepNhan || '—' }}</td>
              <td><span class="tag">{{ statuses[row.TrangThai] || row.TrangThai }}</span></td>
              <td><button v-if="canResolve && transitions[row.TrangThai]" class="btn small" @click="openEdit(row)">Xử lý</button><span v-else>—</span></td>
            </tr>
            <tr v-if="!rows.length"><td colspan="7" class="empty">{{ loading ? 'Đang tải...' : 'Chưa có phiếu khiếu nại.' }}</td></tr>
          </tbody>
        </table>
      </div>
      <footer class="paging"><span>Hiển thị {{ first }}–{{ last }} / {{ total }} phiếu</span><div>
        <select v-model.number="perPage" @change="applyFilter"><option :value="10">10 dòng</option><option :value="20">20 dòng</option><option :value="50">50 dòng</option></select>
        <button class="btn small" :disabled="page <= 1" @click="goPage(page-1)">Trước</button><strong>{{ page }}/{{ lastPage }}</strong>
        <button class="btn small" :disabled="page >= lastPage" @click="goPage(page+1)">Sau</button>
      </div></footer>
    </section>
    <div v-if="createOpen" class="overlay" @click.self="createOpen = false">
      <form class="modal" @submit.prevent="createComplaint"><h2>Tạo phiếu khiếu nại</h2><p>Nhân viên tiếp nhận được lấy từ tài khoản đăng nhập.</p>
        <label>Bàn liên quan<select v-model="createForm.MaBan" required><option value="">Chọn bàn</option><option v-for="b in tables" :key="b.MaBan" :value="String(b.MaBan)">Bàn {{ b.SoBan }} · {{ b.TenKV }}</option></select></label>
        <label>Nội dung phản ánh<textarea v-model="createForm.MoTa" required minlength="5" maxlength="5000" rows="5"></textarea></label>
        <div class="actions"><button type="button" class="btn" @click="createOpen=false">Hủy</button><button class="btn primary" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Tạo phiếu' }}</button></div>
      </form>
    </div>
    <div v-if="editOpen && current" class="overlay" @click.self="editOpen = false">
      <form class="modal" @submit.prevent="updateComplaint"><h2>Cập nhật phiếu #{{ current.MaKN }}</h2>
        <p>{{ statuses[current.TrangThai] }} → <strong>{{ statuses[transitions[current.TrangThai]] }}</strong></p>
        <label>Hướng xử lý<textarea v-model="resolution" maxlength="5000" rows="5" placeholder="Ghi cách giải quyết, kết quả..." :required="['Da xu ly','Da dong'].includes(transitions[current.TrangThai])"></textarea></label>
        <div class="actions"><button type="button" class="btn" @click="editOpen=false">Hủy</button><button class="btn primary" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Xác nhận' }}</button></div>
      </form>
    </div>
  </div>

  </div>
</template>

<style scoped>
.complaints-page{color:#243e53;width:100%;font-size:14px}.heading{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:20px}.eyebrow{font-size:12px;letter-spacing:.06em;color:#b2702e;font-weight:750}
h1{font-size:25px;margin:4px 0 8px;color:#1e3f56}h2{font-size:20px;margin:0 0 8px}p{color:#73899c;margin:0 0 8px}.panel{background:#fff;border:1px solid #dce5ee;border-radius:13px;padding:18px;box-shadow:0 5px 22px #152f480d}.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}.filters label{flex:1 1 155px}.filters .wide{flex:2 1 240px}
label{display:block;color:#4c657b;font-size:12px;font-weight:700}input,select,textarea{display:block;width:100%;min-height:39px;background:#fff;color:#233e56;padding:9px;border:1px solid #c7d5e1;border-radius:8px;margin-top:5px;font:inherit}textarea{resize:vertical}.btn{border:1px solid #cbd7e2;color:#25526f;background:#fff;border-radius:8px;padding:10px 13px;cursor:pointer;font-weight:700;font:inherit}.btn.primary{background:#255775;color:white;border-color:#255775}.btn.small{padding:5px 9px;font-size:12px}.btn:disabled{opacity:.5;cursor:default}.hint{font-size:12px;margin:15px 0}.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:900px;font-size:13px}th{background:#f3f7fa;text-align:left;padding:11px;color:#537086;font-size:12px}td{padding:11px;border-bottom:1px solid #e6eef5;vertical-align:top}.desc{max-width:360px;overflow-wrap:anywhere}small{display:block;color:#7891a3;margin-top:5px}.empty{text-align:center;padding:25px}.tag{display:inline-block;background:#eaf1f9;color:#315678;border-radius:99px;padding:4px 8px;font-size:12px}.paging{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:18px}.paging>div{display:flex;gap:8px;align-items:center}.paging select{width:auto;margin:0}.alert{padding:11px;border-radius:8px;margin-bottom:13px}.alert.error{background:#ffebee;color:#a22436}.alert.success{background:#ecf9f2;color:#216b43}.overlay{position:fixed;inset:0;z-index:200;background:#12243988;display:flex;align-items:center;justify-content:center;padding:15px}.modal{background:white;border-radius:14px;border:1px solid #dce5ee;padding:24px;width:min(540px,100%);max-height:90vh;overflow:auto;box-shadow:0 25px 70px #0003}.modal label{margin:18px 0}.actions{display:flex;gap:8px;justify-content:flex-end}
</style>
