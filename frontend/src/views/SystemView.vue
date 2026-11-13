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
const chosenRole=ref(''), permissionList=ref([]), roleRights=ref([]), modal=ref(false), form=ref({})
const allUcs=[
  ['UC1.1','Quản lý cơ sở'],['UC1.2','Danh mục món'],['UC1.3','Thực đơn'],
  ['UC2.1','Bàn/khu vực'],['UC2.2','Đặt bàn'],['UC2.3','Hủy món'],['UC2.4','Order/POS'],['UC2.5','Khách hàng'],
  ['UC3.1','Tiếp nhận món'],['UC3.2','Chế biến'],['UC3.3','Thông báo phục vụ'],
  ['UC4.1','Nguyên liệu'],['UC4.2','Nhập kho'],['UC4.3','Xuất kho'],['UC4.4','Kiểm kê'],['UC4.5','Định mức'],['UC4.6','Nhà cung cấp'],['UC4.7','Mua hàng'],
  ['UC5.1','Yêu cầu thanh toán'],['UC5.2','Hóa đơn'],['UC5.3','Giảm giá'],['UC5.4','Thanh toán'],['UC5.5','Tách/gộp'],['UC5.6','Đối soát ca'],
  ['UC6.1','Hồ sơ'],['UC6.2','Chấm công'],['UC6.3','Điều phối ca'],['UC6.4','Lương'],
  ['UC7.1','Thu/chi'],['UC7.2','Đối soát'],['UC7.3','Khuyến mãi'],['UC7.4','Hiệu quả'],['UC7.5','Dashboard'],['UC7.6','Ngân sách'],
  ['UC8.1','Đăng nhập'],['UC8.2','Đổi mật khẩu'],['UC8.3','Tài khoản & quyền'],['UC8.4','Nhật ký'],['UC8.5','Hỗ trợ kỹ thuật']
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
async function resetPassword(row){const pass=prompt(`Nhập mật khẩu mới (ít nhất 12 ký tự) cho ${row.TenDangNhap}:`);if(!pass)return;busy.value=true;try{await api.post(`/system/accounts/${row.MaTK}/reset-password`,{MatKhauMoi:pass});notice.value='Đã đặt lại mật khẩu.'}catch(e){error.value=err(e)}finally{busy.value=false}}
async function createRole(){const name=prompt('Tên vai trò mới:');if(!name)return;try{await api.post('/system/roles',{TenVaiTro:name});await loadRoles();notice.value='Đã tạo vai trò.'}catch(e){error.value=err(e)}}
watch([tab,perPage],async()=>{page.value=1;await load()})
onMounted(init)
</script>

<template>
  <div class="qntd-module space-y-5 text-slate-800">
    <header class="flex flex-wrap items-center justify-between"><div><p class="text-xs font-bold tracking-wider text-amber-600">PHÂN HỆ QUẢN TRỊ </p><h1 class="mt-1 text-2xl font-bold">Tài khoản, phân quyền và hỗ trợ</h1></div><p class="text-sm text-slate-500">Đăng nhập: {{ user.TenDangNhap }}</p></header>
    <div v-if="error" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</div><div v-if="notice" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ notice }}</div>
    <p v-if="apiNote" class="text-xs text-slate-500">{{ apiNote }}</p>
    <div class="flex flex-wrap gap-2 rounded-xl border bg-white p-2"><button v-for="t in tabs" :key="t.id" class="rounded-lg px-4 py-2 text-sm font-semibold" :class="tab===t.id?'bg-slate-800 text-white':'hover:bg-slate-100'" @click="tab=t.id">{{ t.name }}</button></div>
    <section class="rounded-xl border bg-white p-4">
      <template v-if="tab==='roles'"><div class="mb-4 flex flex-wrap items-center gap-2"><label class="text-sm">Chọn vai trò<select v-model="chosenRole" class="ml-2 w-auto rounded border p-2" @change="loadRights"><option v-for="role in roles" :key="role.MaVaiTro" :value="String(role.MaVaiTro)">{{ role.TenVaiTro }}</option></select></label><button class="rounded border px-4 py-2 text-sm" @click="createRole">Thêm vai trò</button><button class="rounded bg-slate-800 px-4 py-2 text-sm text-white" @click="saveRights">Lưu quyền</button></div><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"><label v-for="[code,name] in allUcs" :key="code" class="flex items-start gap-2 rounded-lg border p-2 text-xs"><input v-model="roleRights" :value="code" type="checkbox" class="mt-0.5 w-auto" /><span>{{ code }} – {{ name }}</span></label></div></template>
      <template v-else-if="tab==='password'"><p class="mb-4 text-sm text-slate-500">Nên sử dụng mật khẩu dài, không dùng chung mật khẩu tài khoản cá nhân.</p><button class="rounded bg-slate-800 px-4 py-2 text-sm text-white" @click="open()">Đổi mật khẩu</button></template>
      <template v-else><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><input v-if="['accounts','audit'].includes(tab)" v-model="query" @keyup.enter="page=1;load()" placeholder="Tìm kiếm..." class="max-w-xs rounded-lg border p-2 text-sm"/><div class="flex gap-2"><button class="rounded border px-4 py-2 text-sm" @click="page=1;load()">Làm mới</button><button v-if="['accounts','tickets'].includes(tab)" class="rounded bg-slate-800 px-4 py-2 text-sm text-white" @click="open()">+ Tạo mới</button></div></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-xs"><thead class="bg-slate-50"><tr><th v-for="[key,name] in columns" :key="key" class="p-3">{{ name }}</th><th class="p-3">Thao tác</th></tr></thead><tbody><tr v-for="row in rows" :key="row.MaTK||row.MaNK||row.MaTicket" class="border-t"><td v-for="[key] in columns" :key="key" class="max-w-xs p-3 break-words">{{ ['TrangThai','MucUuTien'].includes(key)?status(row[key]):(row[key]??'—') }}</td><td class="p-3"><div class="flex gap-2"><button v-if="tab==='accounts'" @click="open(row)" class="rounded border px-2 py-1">Sửa</button><button v-if="tab==='accounts'" @click="resetPassword(row)" class="rounded border px-2 py-1">Đặt lại mật khẩu</button><button v-if="tab==='tickets'&&has('UC8.5')" @click="open(row)" class="rounded border px-2 py-1">Xử lý</button></div></td></tr><tr v-if="!rows.length"><td :colspan="columns.length+1" class="p-8 text-center text-slate-500">Không có dữ liệu.</td></tr></tbody></table></div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs"><span>Hiển thị {{ total?(page-1)*perPage+1:0 }}–{{ Math.min(page*perPage,total) }} trong {{ total }} kết quả</span><div class="flex items-center gap-2"><select v-model.number="perPage" class="w-auto rounded border p-2"><option :value="10">10 dòng</option><option :value="20">20 dòng</option><option :value="50">50 dòng</option></select><button class="rounded border px-3 py-2 disabled:opacity-30" :disabled="page<=1" @click="page--;load()">‹</button>{{ page }}/{{ pages }}<button class="rounded border px-3 py-2 disabled:opacity-30" :disabled="page>=pages" @click="page++;load()">›</button></div></div>
      </template>
    </section>
    <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/50 p-4" @click.self="modal=false"><form @submit.prevent="save" class="w-full max-w-lg space-y-3 rounded-xl bg-white p-5 shadow-xl"><h2 class="text-lg font-bold">{{ tab==='accounts'?'Tài khoản':tab==='tickets'?'Yêu cầu hỗ trợ':'Đổi mật khẩu' }}</h2>
      <template v-if="tab==='accounts'"><label v-if="!form.id" class="block text-xs">Tên đăng nhập<input v-model="form.TenDangNhap" class="mt-1 w-full rounded border p-2" /></label><label v-if="!form.id" class="block text-xs">Mật khẩu ban đầu<input v-model="form.MatKhau" type="password" class="mt-1 w-full rounded border p-2" /></label><label v-if="!form.id" class="block text-xs">Nhân viên<select v-model="form.MaNV" class="mt-1 w-full rounded border p-2"><option value="">Chọn nhân viên</option><option v-for="e in employees" :key="e.MaNV" :value="String(e.MaNV)">{{ e.HoTen }}</option></select></label><label class="block text-xs">Vai trò<select v-model="form.MaVaiTro" class="mt-1 w-full rounded border p-2"><option v-for="role in roles" :key="role.MaVaiTro" :value="String(role.MaVaiTro)">{{ role.TenVaiTro }}</option></select></label><label v-if="form.id" class="block text-xs">Trạng thái<select v-model="form.TrangThai" class="mt-1 w-full rounded border p-2"><option value="Hoat dong">Hoạt động</option><option value="Khoa">Khóa</option></select></label></template>
      <template v-if="tab==='tickets'"><template v-if="!form.id"><label class="block text-xs">Cơ sở<select v-model="form.MaCS" class="mt-1 w-full rounded border p-2"><option v-for="b in branches" :key="b.MaCS" :value="String(b.MaCS)">{{ b.TenCS }}</option></select></label><label class="block text-xs">Mô tả sự cố<textarea v-model="form.MoTaSuCo" rows="4" class="mt-1 w-full rounded border p-2"></textarea></label><label class="block text-xs">Mức ưu tiên<select v-model="form.MucUuTien" class="mt-1 w-full rounded border p-2"><option v-for="p in ['Thap','Trung binh','Cao','Khan cap']" :key="p" :value="p">{{ status(p) }}</option></select></label></template><template v-else><label class="block text-xs">Trạng thái<select v-model="form.TrangThai" class="mt-1 w-full rounded border p-2"><option v-for="x in ['Moi','Dang xu ly','Cho phan hoi','Da dong']" :key="x" :value="x">{{ status(x) }}</option></select></label><label class="block text-xs">Nhân viên xử lý<select v-model="form.MaNVXuLy" class="mt-1 w-full rounded border p-2"><option value="">Chưa phân công</option><option v-for="e in employees" :key="e.MaNV" :value="String(e.MaNV)">{{ e.HoTen }}</option></select></label></template></template>
      <template v-if="tab==='password'"><label class="block text-xs">Mật khẩu cũ<input v-model="form.MatKhauCu" type="password" class="mt-1 w-full rounded border p-2" /></label><label class="block text-xs">Mật khẩu mới<input v-model="form.MatKhauMoi" type="password" class="mt-1 w-full rounded border p-2" /></label><label class="block text-xs">Nhập lại mật khẩu mới<input v-model="form.MatKhauMoi_confirmation" type="password" class="mt-1 w-full rounded border p-2" /></label></template>
      <div class="flex justify-end gap-2"><button type="button" class="rounded border px-4 py-2" @click="modal=false">Hủy</button><button type="submit" class="rounded bg-slate-800 px-4 py-2 text-white" :disabled="busy">Lưu</button></div>
    </form></div>
  </div>
</template>
