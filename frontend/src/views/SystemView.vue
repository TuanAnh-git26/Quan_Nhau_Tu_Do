<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'
import '../styles/qntd-modules.css'
const props = defineProps({ user: { type: Object, required: true } })
const emit = defineEmits(['account-changed'])
const has = uc => (props.user.Quyen || []).includes('*') || (props.user.Quyen || []).includes(uc)
const admin = computed(() => has('UC8.3'))
const tabs = computed(() => [
  ...(has('UC8.3') ? [{id:'accounts',name:'Tài khoản'}, {id:'roles',name:'Vai trò & quyền'}] : []),
  ...(has('UC8.4') ? [{id:'audit',name:'Nhật ký'}] : []),
  ...(has('UC8.5') ? [{id:'tickets',name:'Hỗ trợ'}] : []),
  {id:'password',name:'Đổi mật khẩu'}
])
const tab=ref('password'), roles=ref([]), rows=ref([]), branches=ref([]), employees=ref([])
const page=ref(1), perPage=ref(10), total=ref(0), query=ref(''), error=ref(''), notice=ref(''), busy=ref(false)
const chosenRole=ref(''), roleRights=ref([]), modal=ref(false), form=ref({})
const allUcs = [
  ['UC1.1','Quản lý đặt bàn trước'],['UC1.2','Quản lý sơ đồ khu vực và bàn'],['UC1.3','Theo dõi và cập nhật trạng thái bàn'],
  ['UC2.1','Quản lý thực đơn và giá bán'],['UC2.2','Lập order theo bàn'],['UC2.3','Chỉnh sửa, hủy order'],['UC2.4','Chuyển order và theo dõi trạng thái món'],['UC2.5','Xử lý khiếu nại, sự cố'],
  ['UC3.1','Tiếp nhận order chế biến'],['UC3.2','Cập nhật trạng thái chế biến'],['UC3.3','Lập phiếu đề nghị xuất kho'],
  ['UC4.1','Quản lý danh mục nguyên liệu'],['UC4.2','Nhập kho'],['UC4.3','Xuất kho theo FIFO'],['UC4.4','Kiểm kê và cảnh báo tồn tối thiểu'],['UC4.5','Quản lý công thức và định mức'],['UC4.6','Quản lý nhà cung cấp'],['UC4.7','Lập và theo dõi đơn đặt hàng (PO)'],
  ['UC5.1','Tiếp nhận yêu cầu thanh toán'],['UC5.2','Xuất hóa đơn'],['UC5.3','Áp dụng giảm giá, khuyến mãi'],['UC5.4','Thanh toán'],['UC5.5','Tách, gộp hóa đơn'],['UC5.6','Đối soát doanh thu và bàn giao ca'],
  ['UC6.1','Quản lý hồ sơ và hợp đồng lao động'],['UC6.2','Quản lý ca làm và chấm công'],['UC6.3','Điều phối nhân sự trong ca'],['UC6.4','Tính lương, thưởng, phụ cấp'],
  ['UC7.1','Lập phiếu thu, phiếu chi'],['UC7.2','Đối chiếu doanh thu với sổ sách'],['UC7.3','Quản lý chương trình khuyến mãi'],['UC7.4','Báo cáo hiệu quả khuyến mãi'],['UC7.5','Xem báo cáo và dashboard'],['UC7.6','Phê duyệt kế hoạch, ngân sách'],
  ['UC8.1','Đăng nhập'],['UC8.2','Đổi mật khẩu'],['UC8.3','Quản lý người dùng và phân quyền'],['UC8.4','Xem nhật ký thao tác'],['UC8.5','Quản lý yêu cầu hỗ trợ kỹ thuật']
]
const columns = computed(() => ({
  accounts:[['MaTK','Mã'],['TenDangNhap','Tên đăng nhập'],['HoTen','Nhân viên'],['TenVaiTro','Vai trò'],['TrangThai','Trạng thái']],
  audit:[['MaNK','Mã'],['ThoiGian','Thời gian'],['TenDangNhap','Tài khoản'],['HanhDong','Hành động'],['DoiTuong','Đối tượng'],['NoiDungThayDoi','Nội dung']],
  tickets:[['MaTicket','Mã'],['MoTaSuCo','Mô tả'],['MucUuTien','Ưu tiên'],['NguoiGui','Người gửi'],['NguoiXuLy','Người xử lý'],['TrangThai','Trạng thái']]
})[tab.value] || [])
const pages=computed(()=>Math.max(1,Math.ceil(total.value/perPage.value)))
const err=e=>Object.values(e?.response?.data?.errors||{}).flat().join(' ')||e?.response?.data?.message||e?.message||'Không thực hiện được thao tác.'
const unwrap = value => Array.isArray(value) ? value : Array.isArray(value?.data) ? value.data : Array.isArray(value?.data?.data) ? value.data.data : []
const rowsCount = value => Number(value?.total ?? value?.data?.total ?? unwrap(value).length)
const apiNote = ref('')
const status=v=>({ 'Hoat dong':'Hoạt động', 'Khoa':'Khóa', 'Moi':'Mới', 'Dang xu ly':'Đang xử lý', 'Cho phan hoi':'Chờ phản hồi', 'Da dong':'Đã đóng', 'Trung binh':'Trung bình', 'Thap':'Thấp', 'Cao':'Cao', 'Khan cap':'Khẩn cấp' })[v]||v||'—'
async function init(){
  tab.value = tabs.value[0]?.id || 'password'
  if (admin.value) {
    const [b, e] = await Promise.allSettled([
      api.get('/branches'),
      api.get('/hr/employees',{params:{per_page:100,MaCS:props.user.MaCS||undefined}})
    ])
    if (b.status === 'fulfilled') branches.value=unwrap(b.value.data)
    else notice.value='Cơ sở: '+err(b.reason)
    if (e.status === 'fulfilled') employees.value=unwrap(e.value.data)
    else notice.value += (notice.value?' | ':'')+'Nhân viên: '+err(e.reason)
    await loadRoles()
  } else if (props.user.MaCS) {
    branches.value=[{MaCS:props.user.MaCS,TenCS:`Cơ sở #${props.user.MaCS}`}]
  }
  await load()
}
async function loadRoles(){try{roles.value=(await api.get('/system/roles')).data||[];if(!chosenRole.value&&roles.value.length)chosenRole.value=String(roles.value[0].MaVaiTro);await loadRights()}catch(e){error.value=err(e)}}
async function loadRights(){if(!chosenRole.value)return;try{roleRights.value=(await api.get(`/system/roles/${chosenRole.value}/permissions`)).data||[]}catch(e){error.value=err(e)}}
async function saveRights(){busy.value=true;try{await api.put(`/system/roles/${chosenRole.value}/permissions`,{Quyen:roleRights.value});notice.value='Đã lưu phân quyền.'}catch(e){error.value=err(e)}finally{busy.value=false}}
async function load() {
  busy.value=true; error.value=''; apiNote.value=''
  try {
    if (tab.value === 'password' || tab.value === 'roles') {
      rows.value=[]; total.value=0; return
    }
    const url={accounts:'/system/accounts',audit:'/system/audit',tickets:'/system/tickets'}[tab.value]
    const response=await api.get(url,{params:{page:page.value,per_page:perPage.value,q:query.value||undefined}})
    const payload=response.data
    rows.value=unwrap(payload)
    total.value=rowsCount(payload)
    apiNote.value=`${url}: ${total.value} bản ghi`
    if (tab.value==='accounts' && total.value===0 && props.user?.MaTK) {
      apiNote.value+=' · API trả về danh sách rỗng dù phiên admin đã tồn tại; cần kiểm tra query backend.'
    }
  } catch (e) {
    error.value=`Không tải được ${tab.value}: `+err(e)
    rows.value=[];total.value=0
  } finally { busy.value=false }
}
function open(row=null){
  modal.value=true
  if(tab.value==='accounts')form.value=row?{id:row.MaTK,TrangThai:row.TrangThai,MaVaiTro:String(row.MaVaiTro)}:{TenDangNhap:'',MatKhau:'',MaVaiTro:String(roles.value[0]?.MaVaiTro||''),MaNV:''}
  if(tab.value==='tickets')form.value=row?{id:row.MaTicket,TrangThai:row.TrangThai,MaNVXuLy:row.MaNVXuLy||''}:{MoTaSuCo:'',MucUuTien:'Trung binh',MaCS:String(props.user.MaCS||branches.value[0]?.MaCS||'')}
  if(tab.value==='password')form.value={MatKhauCu:'',MatKhauMoi:'',MatKhauMoi_confirmation:''}
}
async function save(){busy.value=true;error.value='';try{
  if(tab.value==='accounts'){
    if(form.value.id)await api.put(`/system/accounts/${form.value.id}`,{MaVaiTro:Number(form.value.MaVaiTro),TrangThai:form.value.TrangThai})
    else await api.post('/system/accounts',{...form.value,MaVaiTro:Number(form.value.MaVaiTro),MaNV:form.value.MaNV?Number(form.value.MaNV):null})
  }else if(tab.value==='tickets'){
    if(form.value.id)await api.put(`/system/tickets/${form.value.id}`,{TrangThai:form.value.TrangThai,MaNVXuLy:form.value.MaNVXuLy?Number(form.value.MaNVXuLy):null})
    else await api.post('/system/tickets',{...form.value,MaCS:Number(form.value.MaCS)})
  }else if(tab.value==='password'){
    await api.post('/auth/change-password',form.value);emit('account-changed')
  }
  modal.value=false;notice.value='Đã lưu thành công.';await load()
}catch(e){error.value=err(e)}finally{busy.value=false}}
const resetModal = ref(false)
const resetForm = ref({ row: null, pass: '', confirm: '', error: '' })
function openReset(row) {
  resetForm.value = { row, pass: '', confirm: '', error: '' }
  resetModal.value = true
}
async function submitReset() {
  if (busy.value) return
  const f = resetForm.value
  if (f.pass.length < 12) { f.error = 'Mật khẩu mới phải có ít nhất 12 ký tự.'; return }
  if (f.pass !== f.confirm) { f.error = 'Hai ô mật khẩu không khớp.'; return }
  busy.value = true
  f.error = ''
  try {
    await api.post(`/system/accounts/${f.row.MaTK}/reset-password`, { MatKhauMoi: f.pass })
    notice.value = `Đã đặt lại mật khẩu cho ${f.row.TenDangNhap}.`
    resetModal.value = false
  } catch (e) {
    f.error = err(e)
  } finally {
    f.pass = ''
    f.confirm = ''
    busy.value = false
  }
}

const roleModal = ref(false)
const roleForm = ref({ name: '', error: '' })
function openRole() {
  roleForm.value = { name: '', error: '' }
  roleModal.value = true
}
async function submitRole() {
  if (busy.value) return
  const name = roleForm.value.name.trim()
  if (!name) { roleForm.value.error = 'Tên vai trò là bắt buộc.'; return }
  if (name.length > 100) { roleForm.value.error = 'Tên vai trò tối đa 100 ký tự.'; return }
  busy.value = true
  try {
    await api.post('/system/roles', { TenVaiTro: name })
    roleModal.value = false
    await loadRoles()
    notice.value = 'Đã tạo vai trò.'
  } catch (e) {
    roleForm.value.error = err(e)
  } finally {
    busy.value = false
  }
}
watch([tab,perPage],async()=>{page.value=1;await load()})
onMounted(init)
</script>

<template>
  <div class="ui-view ui-view--system">
  <div class="qntd-module space-y-5 text-slate-800">
    <header class="flex flex-wrap items-center justify-between"><div><p class="text-xs font-bold tracking-wider text-amber-600">PHÂN HỆ QUẢN TRỊ </p><h1 class="mt-1 text-2xl font-bold">Tài khoản, phân quyền và hỗ trợ</h1></div><p class="text-sm text-slate-500">Đăng nhập: {{ user.TenDangNhap }}</p></header>
    <div v-if="error" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</div><div v-if="notice" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ notice }}</div>
    <p v-if="apiNote" class="text-xs text-slate-500">{{ apiNote }}</p>
    <div class="flex flex-wrap gap-2 rounded-xl border bg-white p-2"><button v-for="t in tabs" :key="t.id" class="rounded-lg px-4 py-2 text-sm font-semibold" :class="tab===t.id?'bg-slate-800 text-white':'hover:bg-slate-100'" @click="tab=t.id">{{ t.name }}</button></div>
    <section class="rounded-xl border bg-white p-4">
      <template v-if="tab==='roles'"><div class="mb-4 flex flex-wrap items-center gap-2"><label class="text-sm">Chọn vai trò<select v-model="chosenRole" class="ml-2 w-auto rounded border p-2" @change="loadRights"><option v-for="role in roles" :key="role.MaVaiTro" :value="String(role.MaVaiTro)">{{ role.TenVaiTro }}</option></select></label><button class="rounded border px-4 py-2 text-sm disabled:opacity-50" :disabled="busy" @click="openRole">Thêm vai trò</button><button class="rounded bg-slate-800 px-4 py-2 text-sm text-white disabled:opacity-50" :disabled="busy" @click="saveRights">Lưu quyền</button></div><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"><label v-for="[code,name] in allUcs" :key="code" class="flex items-start gap-2 rounded-lg border p-2 text-xs"><input v-model="roleRights" :value="code" type="checkbox" class="mt-0.5 w-auto" /><span>{{ code }} – {{ name }}</span></label></div></template>
      <template v-else-if="tab==='password'"><p class="mb-4 text-sm text-slate-500">Nên sử dụng mật khẩu dài, không dùng chung mật khẩu tài khoản cá nhân.</p><button class="rounded bg-slate-800 px-4 py-2 text-sm text-white" @click="open()">Đổi mật khẩu</button></template>
      <template v-else><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><input v-if="['accounts','audit'].includes(tab)" v-model="query" @keyup.enter="page=1;load()" placeholder="Tìm kiếm..." class="max-w-xs rounded-lg border p-2 text-sm"/><div class="flex gap-2"><button class="rounded border px-4 py-2 text-sm" @click="page=1;load()">Làm mới</button><button v-if="['accounts','tickets'].includes(tab)" class="rounded bg-slate-800 px-4 py-2 text-sm text-white" @click="open()">+ Tạo mới</button></div></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-xs"><thead class="bg-slate-50"><tr><th v-for="[key,name] in columns" :key="key" class="p-3">{{ name }}</th><th class="p-3">Thao tác</th></tr></thead><tbody><tr v-for="row in rows" :key="row.MaTK||row.MaNK||row.MaTicket" class="border-t"><td v-for="[key] in columns" :key="key" class="max-w-xs p-3 break-words">{{ ['TrangThai','MucUuTien'].includes(key)?status(row[key]):(row[key]??'—') }}</td><td class="p-3"><div class="flex gap-2"><button v-if="tab==='accounts'" @click="open(row)" class="rounded border px-2 py-1">Sửa</button><button v-if="tab==='accounts'" @click="openReset(row)" class="rounded border px-2 py-1">Đặt lại mật khẩu</button><button v-if="tab==='tickets'&&admin" @click="open(row)" class="rounded border px-2 py-1">Xử lý</button></div></td></tr><tr v-if="!rows.length"><td :colspan="columns.length+1" class="p-8 text-center text-slate-500">Không có dữ liệu.</td></tr></tbody></table></div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs"><span>Hiển thị {{ total?(page-1)*perPage+1:0 }}–{{ Math.min(page*perPage,total) }} trong {{ total }} kết quả</span><div class="flex items-center gap-2"><select v-model.number="perPage" class="w-auto rounded border p-2"><option :value="10">10 dòng</option><option :value="20">20 dòng</option><option :value="50">50 dòng</option></select><button class="rounded border px-3 py-2 disabled:opacity-30" :disabled="page<=1" @click="page--;load()">‹</button>{{ page }}/{{ pages }}<button class="rounded border px-3 py-2 disabled:opacity-30" :disabled="page>=pages" @click="page++;load()">›</button></div></div>
      </template>
    </section>
    <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="modal=false"><form @submit.prevent="save" class="w-full max-w-lg space-y-3 rounded-xl bg-white p-5 shadow-xl"><h2 class="text-lg font-bold">{{ tab==='accounts'?'Tài khoản':tab==='tickets'?'Yêu cầu hỗ trợ':'Đổi mật khẩu' }}</h2>
      <template v-if="tab==='accounts'"><label v-if="!form.id" class="block text-xs">Tên đăng nhập<input v-model="form.TenDangNhap" class="mt-1 w-full rounded border p-2" /></label><label v-if="!form.id" class="block text-xs">Mật khẩu ban đầu<input v-model="form.MatKhau" type="password" class="mt-1 w-full rounded border p-2" /></label><label v-if="!form.id" class="block text-xs">Nhân viên<select v-model="form.MaNV" class="mt-1 w-full rounded border p-2"><option value="">Chọn nhân viên</option><option v-for="e in employees" :key="e.MaNV" :value="String(e.MaNV)">{{ e.HoTen }}</option></select></label><label class="block text-xs">Vai trò<select v-model="form.MaVaiTro" class="mt-1 w-full rounded border p-2"><option v-for="role in roles" :key="role.MaVaiTro" :value="String(role.MaVaiTro)">{{ role.TenVaiTro }}</option></select></label><label v-if="form.id" class="block text-xs">Trạng thái<select v-model="form.TrangThai" class="mt-1 w-full rounded border p-2"><option value="Hoat dong">Hoạt động</option><option value="Khoa">Khóa</option></select></label></template>
      <template v-if="tab==='tickets'"><template v-if="!form.id"><label class="block text-xs">Cơ sở<select v-model="form.MaCS" class="mt-1 w-full rounded border p-2"><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></label><label class="block text-xs">Mô tả sự cố<textarea v-model="form.MoTaSuCo" rows="4" class="mt-1 w-full rounded border p-2"></textarea></label><label class="block text-xs">Mức ưu tiên<select v-model="form.MucUuTien" class="mt-1 w-full rounded border p-2"><option v-for="p in ['Thap','Trung binh','Cao','Khan cap']" :key="p" :value="p">{{ status(p) }}</option></select></label></template><template v-else><label class="block text-xs">Trạng thái<select v-model="form.TrangThai" class="mt-1 w-full rounded border p-2"><option v-for="x in ['Moi','Dang xu ly','Cho phan hoi','Da dong']" :key="x" :value="x">{{ status(x) }}</option></select></label><label class="block text-xs">Nhân viên xử lý<select v-model="form.MaNVXuLy" class="mt-1 w-full rounded border p-2"><option value="">Chưa phân công</option><option v-for="e in employees" :key="e.MaNV" :value="String(e.MaNV)">{{ e.HoTen }}</option></select></label></template></template>
      <template v-if="tab==='password'"><label class="block text-xs">Mật khẩu cũ<input v-model="form.MatKhauCu" type="password" class="mt-1 w-full rounded border p-2" /></label><label class="block text-xs">Mật khẩu mới<input v-model="form.MatKhauMoi" type="password" class="mt-1 w-full rounded border p-2" /></label><label class="block text-xs">Nhập lại mật khẩu mới<input v-model="form.MatKhauMoi_confirmation" type="password" class="mt-1 w-full rounded border p-2" /></label></template>
      <div class="flex justify-end gap-2"><button type="button" class="rounded border px-4 py-2" @click="modal=false">Hủy</button><button type="submit" class="rounded bg-slate-800 px-4 py-2 text-white disabled:opacity-50" :disabled="busy">Lưu</button></div>
    </form></div>

    <div v-if="resetModal" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="resetModal=false">
      <form @submit.prevent="submitReset" class="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
        <h2 class="text-lg font-bold">Đặt lại mật khẩu: {{ resetForm.row?.TenDangNhap }}</h2>
        <p v-if="resetForm.error" class="rounded bg-rose-50 p-2 text-xs text-rose-700">{{ resetForm.error }}</p>
        <label class="block text-xs">Mật khẩu mới (ít nhất 12 ký tự)
          <input v-model="resetForm.pass" type="password" autocomplete="new-password" class="mt-1 w-full rounded border p-2" />
        </label>
        <label class="block text-xs">Nhập lại mật khẩu mới
          <input v-model="resetForm.confirm" type="password" autocomplete="new-password" class="mt-1 w-full rounded border p-2" />
        </label>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded border px-4 py-2" @click="resetModal=false">Hủy</button>
          <button type="submit" class="rounded bg-slate-800 px-4 py-2 text-white disabled:opacity-50" :disabled="busy">Đặt lại</button>
        </div>
      </form>
    </div>

    <div v-if="roleModal" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="roleModal=false">
      <form @submit.prevent="submitRole" class="w-full max-w-md space-y-3 rounded-xl bg-white p-5 shadow-xl">
        <h2 class="text-lg font-bold">Thêm vai trò</h2>
        <p v-if="roleForm.error" class="rounded bg-rose-50 p-2 text-xs text-rose-700">{{ roleForm.error }}</p>
        <label class="block text-xs">Tên vai trò
          <input v-model="roleForm.name" maxlength="100" class="mt-1 w-full rounded border p-2" />
        </label>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded border px-4 py-2" @click="roleModal=false">Hủy</button>
          <button type="submit" class="rounded bg-slate-800 px-4 py-2 text-white disabled:opacity-50" :disabled="busy">Tạo</button>
        </div>
      </form>
    </div>
  </div>

  </div>
</template>
