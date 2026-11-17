<?php

namespace Tests\Feature;

use App\Auth\TaiKhoanUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Kiểm thử hồi quy theo Báo cáo Nhóm 8 v4.
 * Tuyệt đối chỉ chạy trên cơ sở dữ liệu riêng có tên kết thúc bằng "_test".
 * Schema phải được nạp từ backend/database/schema_mysql.sql trước khi chạy.
 */
class ReportBusinessRulesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (!is_string($database) || !str_ends_with($database, '_test')) {
            throw new RuntimeException(
                'CHẶN AN TOÀN: không chạy kiểm thử ghi dữ liệu trên database khác *_test. Database hiện tại: ' . $database
            );
        }
        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Accept' => 'application/json',
        ]);
    }

    private function branch(): int
    {
        return DB::table('CO_SO')->insertGetId([
            'TenCS' => 'Test integration ' . Str::random(12),
            'DiaChi' => 'Địa chỉ kiểm thử',
            'SDT' => null,
            'TrangThai' => 'Dang hoat dong',
        ], 'MaCS');
    }

    private function employee(int $branch, string $positionName = 'Phuc vu'): int
    {
        $title = $positionName . ' test ' . Str::random(10);
        $position = DB::table('VI_TRI')->insertGetId([
            'TenVT' => $title,
            'MoTa' => 'Dữ liệu kiểm thử',
        ], 'MaVT');
        return DB::table('NHAN_VIEN')->insertGetId([
            'HoTen' => 'Nhân viên kiểm thử ' . Str::random(7),
            'TrangThai' => 'Dang lam',
            'MaCS' => $branch,
            'MaVT' => $position,
            'NgayVaoLam' => now()->toDateString(),
        ], 'MaNV');
    }

    private function identity(int $branch, array $permissions, string $roleName = 'Phuc vu', ?int $employeeId = null): array
    {
        DB::table('VAI_TRO')->insertOrIgnore([
            'TenVaiTro' => $roleName,
            'MoTaQuyen' => 'Vai trò chạy kiểm thử',
        ]);
        $role = (int) DB::table('VAI_TRO')->where('TenVaiTro', $roleName)->value('MaVaiTro');
        foreach ($permissions as $uc) {
            DB::table('PHAN_QUYEN')->insertOrIgnore(['MaVaiTro' => $role, 'MaUC' => $uc]);
        }
        $employeeId ??= $this->employee($branch, $roleName);
        $password = 'Only-for-tests-!2345678';
        $hash = Hash::make($password);
        $account = DB::table('TAI_KHOAN')->insertGetId([
            'TenDangNhap' => 'test_' . Str::random(16),
            'MatKhauHash' => $hash,
            'MaVaiTro' => $role,
            'MaNV' => $employeeId,
            'TrangThai' => 'Hoat dong',
        ], 'MaTK');
        $record = DB::table('TAI_KHOAN as tk')
            ->join('VAI_TRO as vt', 'vt.MaVaiTro', '=', 'tk.MaVaiTro')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'tk.MaNV')
            ->where('tk.MaTK', $account)
            ->first(['tk.*', 'vt.TenVaiTro', 'nv.MaCS', 'nv.HoTen']);

        return [
            'user' => new TaiKhoanUser((array) $record),
            'account' => $account,
            'employee' => $employeeId,
            'password' => $password,
            'hash' => $hash,
        ];
    }

    private function signIn(array $identity, int $branch): void
    {
        $this->actingAs($identity['user'], 'web');
        $this->withSession([
            'qntd_branch_id' => $branch,
            'qntd_password_fingerprint' => hash('sha256', $identity['hash']),
        ]);
    }

    private function table(int $branch): int
    {
        $area = DB::table('KHU_VUC')->insertGetId([
            'TenKV' => 'Test ' . Str::random(10),
            'MaCS' => $branch,
            'TrangThai' => 'Dang hoat dong',
        ], 'MaKV');
        return DB::table('BAN')->insertGetId([
            'SoBan' => 'T' . Str::random(7),
            'SoCho' => 10,
            'MaKV' => $area,
            'TrangThai' => 'Trong',
        ], 'MaBan');
    }

    private function order(int $table, int $employee, string $status = 'Da gui'): int
    {
        return DB::table('PHIEU_ORDER')->insertGetId([
            'MaBan' => $table,
            'MaNV' => $employee,
            'ThoiGianTao' => now(),
            'TrangThai' => $status,
        ], 'MaOrder');
    }

    private function orderLine(int $order, string $status = 'Cho che bien'): int
    {
        $category = DB::table('DANH_MUC_MON')->insertGetId([
            'TenDM' => 'Danh mục test ' . Str::random(12),
            'NoiCheBien' => 'Bep',
            'TrangThai' => 'Dang hoat dong',
        ], 'MaDM');
        $item = DB::table('MON')->insertGetId([
            'TenMon' => 'Món thử',
            'DonViTinh' => 'Đĩa',
            'MaDM' => $category,
            'TrangThai' => 'Dang ban',
        ], 'MaMon');
        return DB::table('CHI_TIET_ORDER')->insertGetId([
            'MaOrder' => $order,
            'MaMon' => $item,
            'SoLuong' => 1,
            'DonGia' => 10000,
            'TrangThaiMon' => $status,
            'ThoiGianNhan' => now(),
            'ThoiGianBatDauCheBien' => $status === 'Dang che bien' ? now() : null,
        ], 'MaCTOrder');
    }

    public function test_duplicate_reservation_time_is_rejected(): void
    {
        $branch = $this->branch();
        $identity = $this->identity($branch, ['UC1.1']);
        $this->signIn($identity, $branch);
        $table = $this->table($branch);
        $start = now()->addDays(2)->setTime(18, 0);
        $request = [
            'HoTen' => 'Khách kiểm thử', 'MaCS' => $branch,
            'MaBan' => [$table], 'MaNV' => $identity['employee'],
            'ThoiGianDen' => $start->format('Y-m-d H:i:s'),
            'ThoiGianKetThucDuKien' => $start->copy()->addHours(2)->format('Y-m-d H:i:s'),
            'SoNguoi' => 2,
        ];
        $this->postJson('/api/reservations', $request)->assertCreated();
        $request['ThoiGianDen'] = $start->copy()->addMinutes(30)->format('Y-m-d H:i:s');
        $request['ThoiGianKetThucDuKien'] = $start->copy()->addHours(2)->format('Y-m-d H:i:s');
        $this->postJson('/api/reservations', $request)->assertUnprocessable();
        $this->assertSame(1, DB::table('DAT_BAN_BAN')->where('MaBan', $table)->count());
        $this->assertSame(1, DB::table('NHAT_KY')->where('HanhDong', 'Tao dat ban')
            ->where('MaTK', $identity['account'])->count());
    }

    public function test_only_one_active_order_can_exist_per_table(): void
    {
        $branch = $this->branch();
        $identity = $this->identity($branch, ['UC2.2']);
        $this->signIn($identity, $branch);
        $table = $this->table($branch);
        $payload = ['MaBan' => $table, 'MaNV' => $identity['employee']];
        $this->postJson('/api/orders', $payload)->assertCreated();
        $this->postJson('/api/orders', $payload)->assertOk()->assertJsonPath('DaTonTai', true);
        $this->assertSame(1, DB::table('PHIEU_ORDER')->where('MaBan', $table)
            ->whereIn('TrangThai', ['Dang mo', 'Da gui', 'Dang thanh toan'])->count());
    }

    public function test_duplicate_payment_request_does_not_create_two_transactions(): void
    {
        $branch = $this->branch();
        $identity = $this->identity($branch, ['UC5.2', 'UC5.4']);
        $this->signIn($identity, $branch);
        $table = $this->table($branch);
        $order = $this->order($table, $identity['employee']);
        $this->orderLine($order);
        $invoiceResponse = $this->postJson('/api/cashier/invoices', [
            'MaOrders' => [$order], 'MaNV' => $identity['employee'],
        ])->assertOk();
        $invoiceId = (int) $invoiceResponse->json('MaHD');
        $this->assertGreaterThan(0, $invoiceId);

        $code = 'test-' . Str::uuid();
        $payload = [
            'MaNV' => $identity['employee'], 'MaYeuCau' => $code,
            'HinhThuc' => 'QR', 'SoTien' => 10000,
        ];
        $this->postJson("/api/cashier/invoices/{$invoiceId}/payments", $payload)->assertCreated();
        $this->postJson("/api/cashier/invoices/{$invoiceId}/payments", $payload)->assertCreated();
        $this->assertSame(1, DB::table('THANH_TOAN')->where('MaYeuCau', $code)->count());
    }

    public function test_bep_bar_request_is_not_issued_until_warehouse_confirms_fifo(): void
    {
        $branch = $this->branch();
        $bep = $this->identity($branch, ['UC3.3'], 'Bep');
        $supplier = DB::table('NHA_CUNG_CAP')->insertGetId([
            'TenNCC' => 'NCC kiểm thử ' . Str::random(7),
            'TrangThai' => 'Dang hop tac',
        ], 'MaNCC');
        $ingredient = DB::table('NGUYEN_LIEU')->insertGetId([
            'TenNL' => 'Nguyên liệu thử ' . Str::random(7),
            'DonViTinh' => 'kg', 'TonToiThieu' => 0, 'TrangThai' => 'Dang su dung',
        ], 'MaNL');
        $lots = [];
        foreach ([['2026-10-01 08:00:00', 3], ['2026-10-06 08:00:00', 5]] as [$date, $quantity]) {
            $receipt = DB::table('PHIEU_NHAP_KHO')->insertGetId([
                'NgayNhap' => $date, 'TrangThai' => 'Da xac nhan',
                'MaNCC' => $supplier, 'MaNV' => $bep['employee'],
                'MaCS' => $branch, 'ChungTuNguon' => 'Chứng từ thử',
            ], 'MaPN');
            $lots[] = DB::table('CT_PHIEU_NHAP')->insertGetId([
                'MaPN' => $receipt, 'MaNL' => $ingredient,
                'SoLuongNhap' => $quantity, 'SoLuongCon' => $quantity,
                'DonGia' => 50000, 'HanSuDung' => '2027-12-31',
            ], 'MaCTPN');
        }

        $this->signIn($bep, $branch);
        $requestResponse = $this->postJson('/api/bep-bar/stock-requests', [
            'MaCS' => $branch,
            'MaNV' => $bep['employee'],
            'BoPhanNhan' => 'Bep',
            'items' => [['MaNL' => $ingredient, 'SoLuongDeNghi' => 4]],
        ])->assertCreated();
        $issueId = (int) $requestResponse->json('MaPX');
        $this->assertGreaterThan(0, $issueId);
        $this->assertEquals(3, DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $lots[0])->value('SoLuongCon'));
        $this->assertEquals(5, DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $lots[1])->value('SoLuongCon'));
        $this->assertDatabaseHas('PHIEU_XUAT_KHO', ['MaPX' => $issueId, 'TrangThai' => 'Cho xac nhan']);

        $warehouse = $this->identity($branch, ['UC4.3'], 'Kho');
        $this->signIn($warehouse, $branch);
        $this->postJson("/api/inventory/issues/{$issueId}/confirm", [
            'MaCS' => $branch, 'MaNV' => $warehouse['employee'],
        ])->assertOk();

        $this->assertEquals(0, DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $lots[0])->value('SoLuongCon'));
        $this->assertEquals(4, DB::table('CT_PHIEU_NHAP')->where('MaCTPN', $lots[1])->value('SoLuongCon'));
        $this->assertDatabaseHas('PHIEU_XUAT_KHO', ['MaPX' => $issueId, 'TrangThai' => 'Da xuat']);
        $this->assertSame(2, DB::table('CT_PHIEU_XUAT')->where('MaPX', $issueId)->count());
    }

    public function test_payroll_preparation_and_approval_are_separated_by_role(): void
    {
        $branch = $this->branch();
        $hr = $this->identity($branch, ['UC6.4'], 'Nhan su');
        $accountant = $this->identity($branch, ['UC6.4'], 'Ke toan');

        $this->signIn($accountant, $branch);
        $this->postJson('/api/hr/payrolls/generate', [
            'MaCS' => $branch, 'KyLuong' => '2026-11-01',
        ])->assertForbidden();

        $payroll = DB::table('BANG_LUONG')->insertGetId([
            'KyLuong' => '2026-11-01', 'LuongCoBan' => 10000000,
            'PhuCap' => 0, 'Thuong' => 0, 'KhauTru' => 0,
            'ThucLinh' => 10000000, 'TrangThai' => 'Cho ke toan',
            'MaNV' => $hr['employee'],
        ], 'MaBL');

        $this->postJson("/api/hr/payrolls/{$payroll}/approve")->assertOk();
        $this->assertDatabaseHas('BANG_LUONG', ['MaBL' => $payroll, 'TrangThai' => 'Da chot']);

        $this->signIn($hr, $branch);
        $this->postJson("/api/hr/payrolls/{$payroll}/approve")->assertForbidden();
    }

    public function test_cooking_item_cancellation_requires_supervisor_and_records_audit(): void
    {
        $branch = $this->branch();
        $waiter = $this->identity($branch, ['UC2.3']);
        $this->signIn($waiter, $branch);
        $table = $this->table($branch);
        $order = $this->order($table, $waiter['employee']);
        $line = $this->orderLine($order, 'Dang che bien');
        $url = "/api/orders/{$order}/items/{$line}";
        $this->deleteJson($url)->assertUnprocessable();
        $supervisorEmployee = $this->employee($branch, 'Giam sat');
        $supervisor = $this->identity($branch, ['UC2.3'], 'Giam sat', $supervisorEmployee);
        $this->deleteJson($url, ['MaNVDuyet' => $supervisorEmployee])->assertUnprocessable();
        $this->deleteJson($url, [
            'MaNVDuyet' => $supervisorEmployee,
            'MatKhauNVDuyet' => $supervisor['password'],
        ])->assertOk();
        $this->assertDatabaseHas('CHI_TIET_ORDER', [
            'MaCTOrder' => $line, 'TrangThaiMon' => 'Da huy', 'MaNVDuyet' => $supervisorEmployee,
        ]);
        $this->assertSame(1, DB::table('NHAT_KY')->where('HanhDong', 'Huy mon')
            ->where('DoiTuong', 'CHI_TIET_ORDER')->where('MaTK', $waiter['account'])->count());
    }

    public function test_complaint_is_scoped_by_branch_and_supervisor_follows_workflow(): void
    {
        $branch = $this->branch();
        $otherBranch = $this->branch();
        $waiter = $this->identity($branch, ['UC2.5'], 'Phuc vu');
        $this->signIn($waiter, $branch);
        $table = $this->table($branch);
        $foreignTable = $this->table($otherBranch);

        $this->postJson('/api/complaints', [
            'MaBan' => $foreignTable, 'MoTa' => 'Khách phản ánh lâu nhận món',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/complaints', [
            'MaBan' => $table, 'MoTa' => 'Khách phản ánh lâu nhận món',
        ])->assertCreated();
        $complaintId = (int) $response->json('data.MaKN');
        $this->assertGreaterThan(0, $complaintId);
        $this->putJson("/api/complaints/{$complaintId}", [
            'TrangThai' => 'Dang xu ly',
        ])->assertForbidden();

        $supervisor = $this->identity($branch, ['UC2.5'], 'Giam sat');
        $this->signIn($supervisor, $branch);
        $url = "/api/complaints/{$complaintId}";
        $this->putJson($url, ['TrangThai' => 'Da dong'])->assertUnprocessable();
        $this->putJson($url, [
            'TrangThai' => 'Dang xu ly', 'HuongXuLy' => 'Đã trao đổi với khách',
        ])->assertOk();
        $this->putJson($url, [
            'TrangThai' => 'Da xu ly', 'HuongXuLy' => 'Đã điều chỉnh quy trình phục vụ',
        ])->assertOk();
        $this->putJson($url, [
            'TrangThai' => 'Da dong', 'HuongXuLy' => 'Khách xác nhận đã giải quyết',
        ])->assertOk();
        $this->assertDatabaseHas('PHIEU_KHIEU_NAI', [
            'MaKN' => $complaintId, 'MaBan' => $table, 'TrangThai' => 'Da dong',
        ]);
        $this->assertSame(4, DB::table('NHAT_KY')
            ->where('DoiTuong', 'PHIEU_KHIEU_NAI')->count());
    }

    public function test_uc61_cannot_read_payroll_but_uc64_can(): void
    {
        $branch = $this->branch();
        $role = 'Only UC6.1 ' . Str::random(8);
        $hr = $this->identity($branch, ['UC6.1'], $role);
        $this->signIn($hr, $branch);
        $this->getJson('/api/hr/positions')->assertOk();
        $this->getJson('/api/hr/payrolls?MaCS=' . $branch)->assertForbidden();
        $finance = $this->identity($branch, ['UC6.4'], 'Only UC6.4 ' . Str::random(8));
        $this->signIn($finance, $branch);
        $this->getJson('/api/hr/payrolls?MaCS=' . $branch)->assertOk();
    }
}
