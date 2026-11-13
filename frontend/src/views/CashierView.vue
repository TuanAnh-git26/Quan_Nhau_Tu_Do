<template>
  <div class="qntd-module min-h-screen bg-slate-50 p-4 text-slate-800 md:p-6">
    <div class="mx-auto max-w-7xl space-y-5">
      <header class="flex flex-col gap-4 rounded-2xl bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Quán Nhậu Tự Do </p>
          <h1 class="mt-1 text-2xl font-bold">Thu ngân & thanh toán</h1>
          <p class="mt-1 text-sm text-slate-500">Kiểm tra order, lập hóa đơn, thu tiền và bàn giao ca.</p>
        </div>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 md:min-w-[620px]">
          <label class="text-xs font-semibold text-slate-600">Cơ sở
            <select v-model="MaCS" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" @change="loadContext">
              <option value="">Chọn cơ sở</option>
              <option v-for="branch in branches" :key="branch.MaCS" :value="String(branch.MaCS)">{{ branch.TenCS || branch.TenCoSo || `Cơ sở #${branch.MaCS}` }}</option>
            </select>
          </label>
          <label class="text-xs font-semibold text-slate-600">Nhân viên thu ngân
            <select v-model="MaNV" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
              <option value="">Chọn nhân viên</option>
              <option v-for="employee in employees" :key="employee.MaNV" :value="String(employee.MaNV)">{{ employee.HoTen }} · #{{ employee.MaNV }}</option>
            </select>
          </label>
          <label class="text-xs font-semibold text-slate-600">Ca làm
            <select v-model="MaCa" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
              <option value="">Tự xác định ca</option>
              <option v-for="shift in shifts" :key="shift.MaCa" :value="String(shift.MaCa)">{{ shift.TenCa }} ({{ shift.GioVao }}–{{ shift.GioRa }})</option>
            </select>
          </label>
        </div>
      </header>

      <div v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</div>
      <div v-if="notice" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ notice }}</div>
      <div v-if="!MaCS || !MaNV" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        Vui lòng chọn cơ sở và nhân viên thu ngân trước khi thao tác.
      </div>

      <nav class="flex flex-wrap gap-2 rounded-xl bg-white p-2 shadow-sm">
        <button v-for="item in tabs" :key="item.key" type="button" @click="activeTab = item.key; clearMessages()"
          class="rounded-lg px-4 py-2 text-sm font-semibold transition"
          :class="activeTab === item.key ? 'bg-emerald-700 text-white' : 'text-slate-600 hover:bg-slate-100'">
          {{ item.label }}
        </button>
      </nav>

      <!-- Order chờ thanh toán -->
      <section v-if="activeTab === 'orders'" class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
        <div class="rounded-2xl bg-white p-4 shadow-sm md:p-5">
          <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="text-lg font-bold">Order chờ thanh toán</h2>
              <p class="text-sm text-slate-500">Chỉ hiển thị order đã gửi Bếp/Bar hoặc đang thanh toán.</p>
            </div>
            <div class="flex gap-2">
              <input v-model="orderSearch" @keyup.enter="loadOrders(1)" placeholder="Tìm bàn / mã order" class="w-48 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <button type="button" @click="loadOrders(1)" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white">Tìm</button>
            </div>
          </div>
          <div v-if="loadingOrders" class="py-8 text-center text-sm text-slate-500">Đang tải order...</div>
          <div v-else-if="!orders.data?.length" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">Không có order chờ thanh toán.</div>
          <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[650px] text-left text-sm">
              <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                <tr><th class="py-3 pr-3">Order</th><th class="py-3 pr-3">Bàn / Khu vực</th><th class="py-3 pr-3">Nhân viên</th><th class="py-3 pr-3 text-right">Tạm tính</th><th class="py-3">Trạng thái</th></tr>
              </thead>
              <tbody>
                <tr v-for="row in orders.data" :key="row.MaOrder" @click="selectOrder(row.MaOrder)" class="cursor-pointer border-b border-slate-100 hover:bg-emerald-50" :class="selectedOrder?.MaOrder === row.MaOrder ? 'bg-emerald-50' : ''">
                  <td class="py-3 pr-3 font-semibold">#{{ row.MaOrder }}<div class="text-xs font-normal text-slate-400">{{ dateTime(row.ThoiGianTao) }}</div></td>
                  <td class="py-3 pr-3">{{ row.SoBan }}<div class="text-xs text-slate-400">{{ row.TenKV }}</div></td>
                  <td class="py-3 pr-3">{{ row.NhanVienPhuTrach }}</td>
                  <td class="py-3 pr-3 text-right font-semibold">{{ money(row.TamTinh) }}</td>
                  <td class="py-3"><span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">{{ statusText(row.TrangThai) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mt-4 flex items-center justify-between text-sm text-slate-500">
            <span>Trang {{ orders.current_page || 1 }} / {{ orders.last_page || 1 }} · {{ orders.total || 0 }} order</span>
            <div class="flex gap-2">
              <button :disabled="(orders.current_page || 1) <= 1" @click="loadOrders(orders.current_page - 1)" class="rounded-lg border px-3 py-1.5 disabled:opacity-40">Trước</button>
              <button :disabled="(orders.current_page || 1) >= (orders.last_page || 1)" @click="loadOrders(orders.current_page + 1)" class="rounded-lg border px-3 py-1.5 disabled:opacity-40">Sau</button>
            </div>
          </div>
        </div>

        <div class="rounded-2xl bg-white p-4 shadow-sm md:p-5">
          <div class="flex items-start justify-between gap-3">
            <div><h2 class="text-lg font-bold">Chi tiết order</h2><p class="text-sm text-slate-500">Kiểm tra món trước khi lập hóa đơn.</p></div>
            <span v-if="selectedOrder" class="rounded-lg bg-slate-100 px-3 py-1 text-sm font-bold">#{{ selectedOrder.MaOrder }}</span>
          </div>
          <div v-if="!selectedOrder" class="py-12 text-center text-sm text-slate-400">Chọn một order ở danh sách bên trái.</div>
          <template v-else>
            <div class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm">
              <div><span class="text-slate-500">Bàn</span><p class="font-semibold">{{ selectedOrder.SoBan }} · {{ selectedOrder.TenKV }}</p></div>
              <div><span class="text-slate-500">Nhân viên</span><p class="font-semibold">{{ selectedOrder.NhanVienPhuTrach }}</p></div>
            </div>
            <div class="mt-4 space-y-3">
              <div v-for="line in selectedOrder.ChiTiet" :key="line.MaCTOrder" class="flex justify-between gap-3 border-b border-slate-100 pb-3 text-sm">
                <div class="min-w-0"><p class="font-semibold">{{ line.TenMon }} × {{ line.SoLuong }}</p><p class="text-xs text-slate-500">{{ line.GhiChu || 'Không ghi chú' }} · {{ statusText(line.TrangThaiMon) }}</p><p class="text-xs text-slate-400">Còn {{ line.SoLuongConLai }} chưa lập hóa đơn</p></div>
                <div class="shrink-0 text-right font-semibold">{{ money(line.ThanhTien) }}</div>
              </div>
            </div>
            <div class="mt-4 flex items-center justify-between"><span class="font-semibold">Tạm tính còn có thể lập</span><span class="text-xl font-bold text-emerald-700">{{ money(selectedOrder.ChiTiet?.reduce((sum, line) => sum + (line.SoLuongConLai * line.DonGia), 0) || 0) }}</span></div>
            <button type="button" :disabled="busy || !selectedOrder.ChiTiet?.some(line => line.SoLuongConLai > 0) || !MaNV" @click="createInvoice" class="mt-5 w-full rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-40">Lập hóa đơn từ order này</button>
          </template>
        </div>
      </section>

      <!-- Hóa đơn và thanh toán -->
      <section v-else-if="activeTab === 'invoices'" class="grid gap-5 xl:grid-cols-[0.95fr_1.05fr]">
        <div class="rounded-2xl bg-white p-4 shadow-sm md:p-5">
          <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="text-lg font-bold">Danh sách hóa đơn</h2><p class="text-sm text-slate-500">Chọn hóa đơn để thu tiền, tách hoặc gộp.</p></div>
            <button type="button" @click="loadInvoices(1)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">Làm mới</button>
          </div>
          <div class="mb-3 flex flex-wrap gap-2">
            <select v-model="invoiceStatus" @change="loadInvoices(1)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Tất cả trạng thái</option><option value="Dang mo">Đang mở</option><option value="Da thanh toan">Đã thanh toán</option><option value="Da gop">Đã gộp</option></select>
            <input v-model="invoiceSearch" @keyup.enter="loadInvoices(1)" placeholder="Mã hóa đơn / thu ngân" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <button type="button" @click="loadInvoices(1)" class="rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white">Tìm</button>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
              <thead class="border-b border-slate-200 text-xs uppercase text-slate-500"><tr><th class="py-3 pr-2">Chọn gộp</th><th class="py-3 pr-2">Hóa đơn</th><th class="py-3 pr-2">Ngày lập</th><th class="py-3 pr-2 text-right">Còn thu</th><th class="py-3">Trạng thái</th></tr></thead>
              <tbody>
                <tr v-for="row in invoices.data" :key="row.MaHD" class="border-b border-slate-100" :class="selectedInvoice?.MaHD === row.MaHD ? 'bg-emerald-50' : 'hover:bg-slate-50'">
                  <td class="py-3 pr-2"><input type="checkbox" :value="row.MaHD" v-model="mergeSources" :disabled="row.TrangThai !== 'Dang mo' || selectedInvoice?.MaHD === row.MaHD" /></td>
                  <td class="py-3 pr-2"><button type="button" @click="selectInvoice(row.MaHD)" class="font-semibold text-emerald-800 hover:underline">#{{ row.MaHD }}</button><div class="text-xs text-slate-400">{{ row.ThuNgan || '—' }}</div></td>
                  <td class="py-3 pr-2 text-xs">{{ dateTime(row.NgayLap) }}</td>
                  <td class="py-3 pr-2 text-right font-semibold">{{ money(Math.max(0, Number(row.TongTien) - Number(row.GiamGia) - Number(row.SoDaThu || 0))) }}</td>
                  <td class="py-3"><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="row.TrangThai === 'Da thanh toan' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">{{ statusText(row.TrangThai) }}</span></td>
                </tr>
                <tr v-if="!invoices.data?.length"><td colspan="5" class="py-8 text-center text-slate-400">Chưa có hóa đơn.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="mt-4 flex items-center justify-between text-sm text-slate-500">
            <span>Trang {{ invoices.current_page || 1 }} / {{ invoices.last_page || 1 }} · {{ invoices.total || 0 }} hóa đơn</span>
            <div class="flex gap-2"><button :disabled="(invoices.current_page || 1) <= 1" @click="loadInvoices(invoices.current_page - 1)" class="rounded-lg border px-3 py-1.5 disabled:opacity-40">Trước</button><button :disabled="(invoices.current_page || 1) >= (invoices.last_page || 1)" @click="loadInvoices(invoices.current_page + 1)" class="rounded-lg border px-3 py-1.5 disabled:opacity-40">Sau</button></div>
          </div>
          <button v-if="selectedInvoice && mergeSources.length" type="button" :disabled="busy" @click="mergeSelectedInvoices" class="mt-4 w-full rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-40">Gộp {{ mergeSources.length }} hóa đơn đã chọn vào #{{ selectedInvoice.MaHD }}</button>
        </div>

        <div class="rounded-2xl bg-white p-4 shadow-sm md:p-5">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-bold">Chi tiết & thanh toán</h2><p class="text-sm text-slate-500">Hóa đơn chưa chốt có thể tiếp tục thu nhiều lần.</p></div><button v-if="selectedInvoice" type="button" @click="printReceipt" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">In / In lại biên lai</button></div>
          <div v-if="!selectedInvoice" class="py-12 text-center text-sm text-slate-400">Chọn hóa đơn ở danh sách bên trái.</div>
          <template v-else>
            <div id="cashier-receipt" class="mt-4">
              <div class="flex items-center justify-between rounded-xl bg-slate-50 p-3"><div><p class="text-xs text-slate-500">HÓA ĐƠN</p><p class="text-xl font-bold">#{{ selectedInvoice.MaHD }}</p></div><span class="rounded-full bg-white px-3 py-1 text-xs font-semibold">{{ statusText(selectedInvoice.TrangThai) }}</span></div>
              <div class="mt-3 space-y-2">
                <div v-for="line in selectedInvoice.ChiTiet" :key="line.MaCTHD" class="flex justify-between gap-3 border-b border-slate-100 py-2 text-sm"><div><p class="font-semibold">{{ line.TenMon }} × {{ line.SoLuong }}</p><p class="text-xs text-slate-500">Order #{{ line.MaOrder }} · Bàn {{ line.SoBan }}</p></div><p class="shrink-0 font-semibold">{{ money(line.ThanhTien) }}</p></div>
              </div>
              <div class="mt-4 space-y-2 rounded-xl bg-slate-50 p-3 text-sm"><div class="flex justify-between"><span>Tổng tiền</span><b>{{ money(selectedInvoice.TongTien) }}</b></div><div class="flex justify-between"><span>Giảm giá</span><b class="text-rose-600">−{{ money(selectedInvoice.GiamGia) }}</b></div><div class="flex justify-between border-t border-slate-200 pt-2 text-base"><span class="font-bold">Phải trả</span><b>{{ money(selectedInvoice.SoPhaiTra) }}</b></div><div class="flex justify-between text-emerald-700"><span>Đã thu thành công</span><b>{{ money(selectedInvoice.SoDaThu) }}</b></div><div class="flex justify-between text-amber-700"><span>Chờ đối soát</span><b>{{ money(selectedInvoice.SoChoDoiSoat) }}</b></div><div class="flex justify-between border-t border-slate-200 pt-2 text-lg"><span class="font-bold">Còn phải thu</span><b class="text-emerald-700">{{ money(selectedInvoice.ConPhaiTra) }}</b></div></div>
            </div>

            <div v-if="selectedInvoice.TrangThai === 'Dang mo'" class="mt-5 space-y-4 border-t border-slate-200 pt-4">
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">Khuyến mãi
                  <select v-model="promotionId" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Chọn chương trình</option><option v-for="promotion in promotions" :key="promotion.MaKM" :value="String(promotion.MaKM)">{{ promotion.TenKM }} · {{ promotion.HinhThuc }} {{ promotion.GiaTri }}</option></select>
                </label>
                <div class="flex items-end"><button type="button" :disabled="busy || !promotionId" @click="applyPromotion" class="w-full rounded-lg border border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-40">Áp dụng khuyến mãi</button></div>
              </div>
              <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                <label class="text-xs font-semibold text-slate-600">Giảm giá thủ công (VNĐ)<input v-model.number="manualDiscount" type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" /></label>
                <label class="text-xs font-semibold text-slate-600">Giám sát phê duyệt<select v-model="MaNVDuyet" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Chọn người duyệt</option><option v-for="employee in employees" :key="employee.MaNV" :value="String(employee.MaNV)">{{ employee.HoTen }} · #{{ employee.MaNV }}</option></select></label>
                <div class="flex items-end"><button type="button" :disabled="busy" @click="saveManualDiscount" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Lưu giảm giá</button></div>
              </div>
              <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                <label class="text-xs font-semibold text-slate-600">Hình thức thanh toán<select v-model="paymentMethod" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="Tien mat">Tiền mặt</option><option value="The">Thẻ ngân hàng</option><option value="Chuyen khoan/QR">Chuyển khoản / QR</option></select></label>
                <label class="text-xs font-semibold text-slate-600">Số tiền thu (VNĐ)<input v-model.number="paymentAmount" type="number" min="1" :max="selectedInvoice.ConCoTheTaoGiaoDich" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" /></label>
                <div class="flex items-end"><button type="button" :disabled="busy || !paymentAmount || paymentAmount <= 0 || paymentAmount > selectedInvoice.ConCoTheTaoGiaoDich" @click="createPayment" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Ghi nhận thu</button></div>
              </div>
              <p class="text-xs text-slate-500">Tiền mặt được ghi nhận thành công ngay. Thẻ/QR chỉ chuyển thành công sau khi nhân viên xác minh giao dịch thực tế.</p>
              <div class="flex flex-wrap gap-2"><button type="button" @click="splitOpen = !splitOpen" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold hover:bg-slate-50">{{ splitOpen ? 'Đóng tách hóa đơn' : 'Tách hóa đơn' }}</button></div>
              <div v-if="splitOpen" class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-sm font-semibold">Nhập số lượng chuyển sang hóa đơn mới</p>
                <div v-for="line in selectedInvoice.ChiTiet" :key="line.MaCTHD" class="grid grid-cols-[1fr_100px] items-center gap-3 border-b border-slate-100 py-2 text-sm"><span>{{ line.TenMon }} · hiện có {{ line.SoLuong }}</span><input v-model.number="splitQuantities[line.MaCTHD]" type="number" min="0" :max="line.SoLuong" class="rounded-lg border border-slate-300 px-2 py-1.5" /></div>
                <button type="button" :disabled="busy || !hasSplitQuantity" @click="splitInvoice" class="mt-3 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Tạo hóa đơn tách</button>
              </div>
            </div>

            <div v-if="selectedInvoice.ThanhToan?.length" class="mt-5 border-t border-slate-200 pt-4">
              <h3 class="mb-2 font-semibold">Lịch sử giao dịch</h3>
              <div v-for="payment in selectedInvoice.ThanhToan" :key="payment.MaTT" class="mb-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 text-sm">
                <div><p class="font-semibold">{{ payment.HinhThuc }} · {{ money(payment.SoTien) }}</p><p class="text-xs text-slate-500">{{ dateTime(payment.ThoiGian) }} · {{ payment.MaGiaoDichCong || payment.MaYeuCau }}</p></div>
                <div class="flex items-center gap-2"><span class="rounded-full px-2 py-1 text-xs font-semibold" :class="payment.TrangThaiGD === 'Thanh cong' ? 'bg-emerald-100 text-emerald-800' : payment.TrangThaiGD === 'Cho doi soat' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800'">{{ statusText(payment.TrangThaiGD) }}</span><button v-if="payment.TrangThaiGD === 'Cho doi soat'" type="button" :disabled="busy" @click="confirmPayment(payment.MaTT)" class="rounded-lg border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700">Đã xác minh thành công</button></div>
              </div>
            </div>
          </template>
        </div>
      </section>

      <!-- Đối soát cuối ca -->
      <section v-else class="grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
        <div class="rounded-2xl bg-white p-4 shadow-sm md:p-5">
          <h2 class="text-lg font-bold">Đối soát & bàn giao ca</h2>
          <p class="mt-1 text-sm text-slate-500">Số tiền mặt nhập vào là số kiểm đếm thực tế; hệ thống tự so sánh với giao dịch tiền mặt thành công.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <label class="text-xs font-semibold text-slate-600">Ngày ca<input v-model="shiftDate" type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" /></label>
            <div class="flex items-end"><button type="button" :disabled="!MaCS || !MaCa" @click="loadShiftSummary" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Tải số liệu ca</button></div>
          </div>
          <div v-if="shiftSummary" class="mt-4 space-y-3">
            <div class="grid grid-cols-2 gap-3"><div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Tiền mặt theo hệ thống</p><p class="mt-1 text-lg font-bold">{{ money(shiftSummary.TienMatHeThong) }}</p></div><div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Không tiền mặt</p><p class="mt-1 text-lg font-bold">{{ money(shiftSummary.KhongTienMatHeThong) }}</p></div><div class="rounded-xl bg-amber-50 p-3"><p class="text-xs text-amber-700">Giao dịch chờ đối soát</p><p class="mt-1 text-lg font-bold text-amber-800">{{ money(shiftSummary.GiaoDichChoDoiSoat) }}</p></div><div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">Tổng thu thành công</p><p class="mt-1 text-lg font-bold">{{ money(shiftSummary.TongThuThanhCong) }}</p></div></div>
            <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="border-b text-left text-xs text-slate-500"><tr><th class="py-2">Hình thức</th><th class="py-2 text-right">Thành công</th><th class="py-2 text-right">Chờ</th><th class="py-2 text-right">Thất bại</th></tr></thead><tbody><tr v-for="row in shiftSummary.TheoHinhThuc" :key="row.HinhThuc" class="border-b border-slate-100"><td class="py-2">{{ row.HinhThuc }}</td><td class="py-2 text-right">{{ money(row.ThanhCong) }}</td><td class="py-2 text-right">{{ money(row.ChoDoiSoat) }}</td><td class="py-2 text-right">{{ money(row.ThatBai) }}</td></tr></tbody></table></div>
          </div>
          <div class="mt-5 border-t border-slate-200 pt-4">
            <label class="text-xs font-semibold text-slate-600">Tiền mặt kiểm đếm thực tế (VNĐ)<input v-model.number="cashCounted" type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" /></label>
            <div v-if="shiftSummary" class="mt-2 flex justify-between rounded-lg bg-slate-50 p-3 text-sm"><span>Chênh lệch dự kiến</span><b :class="Number(cashCounted || 0) - Number(shiftSummary.TienMatHeThong) === 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(Number(cashCounted || 0) - Number(shiftSummary.TienMatHeThong)) }}</b></div>
            <button type="button" :disabled="busy || !shiftSummary || !MaNV || !MaCa || cashCounted === '' || Number(cashCounted) < 0" @click="closeShift" class="mt-4 w-full rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white disabled:opacity-40">Lập báo cáo & bàn giao ca</button>
          </div>
        </div>

        <div class="rounded-2xl bg-white p-4 shadow-sm md:p-5">
          <div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-bold">Lịch sử bàn giao</h2><p class="text-sm text-slate-500">Theo dõi trạng thái đối soát báo cáo ca.</p></div><button type="button" @click="loadReports(1)" class="rounded-lg border px-3 py-2 text-sm font-semibold">Làm mới</button></div>
          <div class="mt-4 space-y-3">
            <div v-for="report in reports.data || []" :key="report.MaBC" class="rounded-xl border border-slate-200 p-4">
              <div class="flex flex-wrap items-center justify-between gap-2"><div><p class="font-bold">{{ report.TenCa }} · {{ report.NgayCa }}</p><p class="text-xs text-slate-500">Báo cáo #{{ report.MaBC }} · {{ report.NhanVienBanGiao || `NV #${report.MaNVBanGiao}` }}</p></div><span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">{{ statusText(report.TrangThai) }}</span></div>
              <div class="mt-3 grid grid-cols-3 gap-2 text-sm"><div><p class="text-xs text-slate-500">Tiền mặt kiểm đếm</p><b>{{ money(report.TienMat) }}</b></div><div><p class="text-xs text-slate-500">Không tiền mặt</p><b>{{ money(report.KhongTienMat) }}</b></div><div><p class="text-xs text-slate-500">Chênh lệch</p><b :class="Number(report.ChenhLech) === 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(report.ChenhLech) }}</b></div></div>
              <button v-if="report.TrangThai !== 'Da doi soat'" type="button" :disabled="busy" @click="confirmReport(report.MaBC)" class="mt-3 rounded-lg border border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-700">Xác nhận đã đối soát</button>
            </div>
            <div v-if="!reports.data?.length" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-400">Chưa có báo cáo bàn giao.</div>
          </div>
          <div class="mt-4 flex items-center justify-between text-sm text-slate-500"><span>Trang {{ reports.current_page || 1 }} / {{ reports.last_page || 1 }}</span><div class="flex gap-2"><button :disabled="(reports.current_page || 1) <= 1" @click="loadReports(reports.current_page - 1)" class="rounded-lg border px-3 py-1.5 disabled:opacity-40">Trước</button><button :disabled="(reports.current_page || 1) >= (reports.last_page || 1)" @click="loadReports(reports.current_page + 1)" class="rounded-lg border px-3 py-1.5 disabled:opacity-40">Sau</button></div></div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../services/api'
import '../styles/qntd-modules.css'

const tabs = [
  { key: 'orders', label: 'Order chờ thanh toán' },
  { key: 'invoices', label: 'Hóa đơn & thanh toán' },
  { key: 'shift', label: 'Đối soát cuối ca' },
]
const activeTab = ref('orders')
const branches = ref([])
const employees = ref([])
const shifts = ref([])
const MaCS = ref('')
const MaNV = ref('')
const MaCa = ref('')
const error = ref('')
const notice = ref('')
const busy = ref(false)
const loadingOrders = ref(false)
const orders = ref({ data: [], current_page: 1, last_page: 1, total: 0 })
const invoices = ref({ data: [], current_page: 1, last_page: 1, total: 0 })
const reports = ref({ data: [], current_page: 1, last_page: 1, total: 0 })
const selectedOrder = ref(null)
const selectedInvoice = ref(null)
const orderSearch = ref('')
const invoiceSearch = ref('')
const invoiceStatus = ref('')
const promotions = ref([])
const promotionId = ref('')
const manualDiscount = ref(0)
const MaNVDuyet = ref('')
const paymentMethod = ref('Tien mat')
const paymentAmount = ref(0)
const splitOpen = ref(false)
const splitQuantities = ref({})
const mergeSources = ref([])
const shiftDate = ref(new Date().toLocaleDateString('sv-SE'))
const shiftSummary = ref(null)
const cashCounted = ref('')

const canLoad = computed(() => Boolean(MaCS.value))

function clearMessages() { error.value = ''; notice.value = '' }
function messageFromException(err) {
  const data = err?.response?.data
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  return data?.message || err?.message || 'Có lỗi xảy ra. Vui lòng thử lại.'
}
function money(value) {
  return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(Number(value || 0))
}
function dateTime(value) {
  if (!value) return '—'
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('vi-VN')
}
function statusText(value) {
  const map = {
    'Dang mo': 'Đang mở', 'Da gui': 'Đã gửi', 'Dang thanh toan': 'Đang thanh toán',
    'Da thanh toan': 'Đã thanh toán', 'Da huy': 'Đã hủy', 'Da gop': 'Đã gộp',
    'Cho che bien': 'Chờ chế biến', 'Da nhan': 'Đã nhận', 'Dang che bien': 'Đang chế biến',
    'Hoan tat': 'Hoàn tất', 'Thanh cong': 'Thành công', 'Cho doi soat': 'Chờ đối soát',
    'That bai': 'Thất bại', 'Da doi soat': 'Đã đối soát',
  }
  return map[value] || value || '—'
}

async function loadBranches() {
  try {
    const { data } = await api.get('/branches')
    branches.value = Array.isArray(data) ? data : (data.data || [])
    const profile = await api.get('/auth/me').then(r => r.data).catch(() => null)
    const sessionBranch = profile?.MaCS ?? profile?.data?.MaCS
    if (!MaCS.value && sessionBranch && branches.value.some(b => Number(b.MaCS) === Number(sessionBranch))) {
      MaCS.value = String(sessionBranch)
    }
    if (!MaCS.value && branches.value.length) MaCS.value = String(branches.value[0].MaCS)
    if (!branches.value.length) {
      error.value = 'Không có cơ sở khả dụng. Hãy kiểm tra GET /api/branches.'
      return
    }
    await loadContext()
  } catch (err) { error.value = messageFromException(err) }
}
async function loadContext() {
  clearMessages()
  if (!MaCS.value) return
  try {
    const [employeeRes, shiftRes] = await Promise.all([
      api.get('/employees', { params: { MaCS: MaCS.value } }),
      api.get('/cashier/shifts', { params: { MaCS: MaCS.value } }),
    ])
    employees.value = Array.isArray(employeeRes.data) ? employeeRes.data : (employeeRes.data.data || [])
    shifts.value = Array.isArray(shiftRes.data) ? shiftRes.data : []
    if (!employees.value.some(item => String(item.MaNV) === MaNV.value)) {
      MaNV.value = String(employees.value.find(item => item.TrangThai === 'Dang lam')?.MaNV || employees.value[0]?.MaNV || '')
    }
    if (!shifts.value.some(shift => String(shift.MaCa) === MaCa.value)) MaCa.value = ''
    if (!MaCa.value && shifts.value.length) MaCa.value = String(shifts.value[0].MaCa)
    if (!employees.value.length) error.value = 'Cơ sở chưa có nhân viên hoạt động. Kiểm tra dữ liệu nhân viên trong phân hệ Nhân sự.'
    await Promise.all([loadOrders(1), loadInvoices(1), loadPromotions(), loadReports(1)])
  } catch (err) { error.value = messageFromException(err) }
}
async function loadOrders(page = 1) {
  if (!canLoad.value) return
  loadingOrders.value = true
  try {
    const { data } = await api.get('/cashier/orders', { params: { MaCS: MaCS.value, q: orderSearch.value || undefined, page, per_page: 10 } })
    orders.value = data
  } catch (err) { error.value = messageFromException(err) }
  finally { loadingOrders.value = false }
}
async function selectOrder(id) {
  clearMessages()
  try { const { data } = await api.get(`/cashier/orders/${id}`); selectedOrder.value = data }
  catch (err) { error.value = messageFromException(err) }
}
async function createInvoice() {
  if (!MaNV.value || !selectedOrder.value) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.post('/cashier/invoices', { MaOrders: [selectedOrder.value.MaOrder], MaNV: Number(MaNV.value), MaCa: MaCa.value ? Number(MaCa.value) : null })
    selectedInvoice.value = data
    activeTab.value = 'invoices'
    notice.value = `Đã lập hóa đơn #${data.MaHD}.`
    await Promise.all([loadInvoices(1), loadOrders(1)])
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
async function loadInvoices(page = 1) {
  if (!canLoad.value) return
  try {
    const { data } = await api.get('/cashier/invoices', { params: { MaCS: MaCS.value, TrangThai: invoiceStatus.value || undefined, q: invoiceSearch.value || undefined, page, per_page: 10 } })
    invoices.value = data
  } catch (err) { error.value = messageFromException(err) }
}
async function selectInvoice(id) {
  clearMessages(); splitOpen.value = false; mergeSources.value = []
  try {
    const { data } = await api.get(`/cashier/invoices/${id}`)
    selectedInvoice.value = data
    paymentAmount.value = Number(data.ConCoTheTaoGiaoDich || 0)
    manualDiscount.value = Math.max(0, Number(data.GiamGia || 0) - (data.KhuyenMai || []).reduce((sum, item) => sum + Number(item.SoTienGiam || 0), 0))
    splitQuantities.value = {}
  } catch (err) { error.value = messageFromException(err) }
}
async function loadPromotions() {
  try { const { data } = await api.get('/cashier/promotions'); promotions.value = Array.isArray(data) ? data : [] }
  catch (err) { error.value = messageFromException(err) }
}
async function applyPromotion() {
  if (!selectedInvoice.value || !promotionId.value) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.post(`/cashier/invoices/${selectedInvoice.value.MaHD}/promotions`, { MaKM: Number(promotionId.value) })
    selectedInvoice.value = data; notice.value = 'Đã áp dụng khuyến mãi.'; await loadInvoices(invoices.value.current_page || 1)
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
async function saveManualDiscount() {
  if (!selectedInvoice.value || !MaNV.value) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.put(`/cashier/invoices/${selectedInvoice.value.MaHD}/discount`, { SoTienGiam: Number(manualDiscount.value || 0), MaNV: Number(MaNV.value), MaNVDuyet: MaNVDuyet.value ? Number(MaNVDuyet.value) : null })
    selectedInvoice.value = data; notice.value = 'Đã cập nhật giảm giá.'; await loadInvoices(invoices.value.current_page || 1)
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
async function createPayment() {
  if (!selectedInvoice.value || !MaNV.value) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.post(`/cashier/invoices/${selectedInvoice.value.MaHD}/payments`, { HinhThuc: paymentMethod.value, SoTien: Number(paymentAmount.value), MaNV: Number(MaNV.value) })
    selectedInvoice.value = data.HoaDon
    notice.value = data.message
    paymentAmount.value = Number(selectedInvoice.value.ConCoTheTaoGiaoDich || 0)
    await Promise.all([loadInvoices(invoices.value.current_page || 1), loadOrders(1)])
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
async function confirmPayment(paymentId) {
  if (!MaNV.value || !window.confirm('Bạn đã kiểm tra giao dịch thành công trên thiết bị/cổng thanh toán?')) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.patch(`/cashier/payments/${paymentId}/confirm`, { TrangThaiGD: 'Thanh cong', DaXacMinh: true, MaNV: Number(MaNV.value) })
    selectedInvoice.value = data.HoaDon; notice.value = data.message
    await Promise.all([loadInvoices(invoices.value.current_page || 1), loadOrders(1)])
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
const hasSplitQuantity = computed(() => Object.values(splitQuantities.value).some(value => Number(value) > 0))
async function splitInvoice() {
  if (!selectedInvoice.value || !MaNV.value) return
  const items = Object.entries(splitQuantities.value).filter(([, qty]) => Number(qty) > 0).map(([MaCTHD, SoLuongTach]) => ({ MaCTHD: Number(MaCTHD), SoLuongTach: Number(SoLuongTach) }))
  if (!items.length) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.post(`/cashier/invoices/${selectedInvoice.value.MaHD}/split`, { MaNV: Number(MaNV.value), items })
    notice.value = `Đã tạo hóa đơn tách #${data.MaHD}.`
    selectedInvoice.value = data; splitOpen.value = false
    await loadInvoices(1)
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
async function mergeSelectedInvoices() {
  if (!selectedInvoice.value || !mergeSources.value.length) return
  if (!window.confirm(`Gộp ${mergeSources.value.length} hóa đơn vào hóa đơn #${selectedInvoice.value.MaHD}?`)) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.post(`/cashier/invoices/${selectedInvoice.value.MaHD}/merge`, { MaHDNguon: mergeSources.value.map(Number) })
    selectedInvoice.value = data; mergeSources.value = []; notice.value = 'Đã gộp hóa đơn.'; await loadInvoices(1)
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
function printReceipt() { window.print() }
async function loadShiftSummary() {
  if (!MaCS.value || !MaCa.value) return
  clearMessages()
  try { const { data } = await api.get('/cashier/shift-summary', { params: { MaCS: MaCS.value, MaCa: MaCa.value, NgayCa: shiftDate.value } }); shiftSummary.value = data }
  catch (err) { error.value = messageFromException(err) }
}
async function loadReports(page = 1) {
  if (!MaCS.value) return
  try { const { data } = await api.get('/cashier/shift-reports', { params: { MaCS: MaCS.value, page, per_page: 10 } }); reports.value = data }
  catch (err) { error.value = messageFromException(err) }
}
async function closeShift() {
  if (!shiftSummary.value || !MaCS.value || !MaCa.value || !MaNV.value) return
  if (!window.confirm('Xác nhận lập báo cáo và bàn giao ca?')) return
  busy.value = true; clearMessages()
  try {
    const { data } = await api.post('/cashier/shift-reports', { MaCS: Number(MaCS.value), MaCa: Number(MaCa.value), NgayCa: shiftDate.value, TienMatThucTe: Number(cashCounted.value), MaNVBanGiao: Number(MaNV.value) })
    notice.value = data.message; cashCounted.value = ''; await loadReports(1)
  } catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}
async function confirmReport(id) {
  if (!MaNV.value || !window.confirm('Xác nhận báo cáo này đã được kiểm tra và đối soát?')) return
  busy.value = true; clearMessages()
  try { const { data } = await api.post(`/cashier/shift-reports/${id}/confirm`, { MaNV: Number(MaNV.value) }); notice.value = data.message; await loadReports(reports.value.current_page || 1) }
  catch (err) { error.value = messageFromException(err) }
  finally { busy.value = false }
}

watch([MaCS, MaNV], () => { selectedOrder.value = null; selectedInvoice.value = null })
onMounted(loadBranches)
</script>

<style scoped>
@media print {
  body * { visibility: hidden !important; }
  #cashier-receipt, #cashier-receipt * { visibility: visible !important; }
  #cashier-receipt { position: absolute; inset: 0; width: 100%; padding: 16px; background: white; color: black; }
}
</style>
