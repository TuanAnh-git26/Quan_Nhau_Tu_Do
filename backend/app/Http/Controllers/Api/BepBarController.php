<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BepBarController extends Controller
{
    private const NEXT = [
        'Cho che bien' => 'Da nhan',
        'Da nhan' => 'Dang che bien',
        'Dang che bien' => 'Hoan tat',
    ];

    private function departmentFor(Request $request, ?string $requested): ?string
    {
        $role = (string) ($request->user()?->TenVaiTro ?? '');
        if (in_array($role, ['Bep', 'Bar'], true)) {
            abort_if($requested && $requested !== $role, 403, 'Tài khoản chỉ được thao tác tại bộ phận được phân công.');
            return $role;
        }
        return $requested;
    }

    private function itemsQuery(int $branchId)
    {
        return DB::table('CHI_TIET_ORDER as ct')
            ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'ct.MaOrder')
            ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
            ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
            ->join('MON as m', 'm.MaMon', '=', 'ct.MaMon')
            ->join('DANH_MUC_MON as dm', 'dm.MaDM', '=', 'm.MaDM')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'po.MaNV')
            ->where('kv.MaCS', $branchId)
            ->whereIn('po.TrangThai', ['Da gui', 'Dang thanh toan'])
            ->whereNotNull('ct.ThoiGianNhan')
            ->where('ct.TrangThaiMon', '<>', 'Da huy')
            ->select([
                'ct.MaCTOrder', 'ct.MaOrder', 'ct.MaMon', 'ct.SoLuong',
                'ct.GhiChu', 'ct.TrangThaiMon', 'ct.ThoiGianNhan',
                'ct.ThoiGianBatDauCheBien', 'ct.ThoiGianHoanTat',
                'po.ThoiGianTao', 'po.MaNV', 'b.SoBan', 'kv.TenKV', 'kv.MaCS',
                'm.TenMon', 'm.DonViTinh', 'dm.TenDM', 'dm.NoiCheBien',
                'nv.HoTen as NhanVienPhuTrach',
            ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'BoPhan' => ['nullable', Rule::in(['Bep', 'Bar'])],
            'NoiCheBien' => ['nullable', Rule::in(['Bep', 'Bar'])],
            'TrangThaiMon' => ['nullable', Rule::in([
                'Cho che bien', 'Da nhan', 'Dang che bien', 'Hoan tat', 'Tat ca',
            ])],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = $this->itemsQuery((int) $data['MaCS']);

        $department = $this->departmentFor($request, $data['BoPhan'] ?? $data['NoiCheBien'] ?? null);
        if ($department) $query->where('dm.NoiCheBien', $department);

        $status = $data['TrangThaiMon'] ?? null;

        if ($status && $status !== 'Tat ca') {
            $query->where('ct.TrangThaiMon', $status);
        } elseif (!$status) {
            $query->where(function ($q) {
                $q->whereIn('ct.TrangThaiMon', [
                    'Cho che bien', 'Da nhan', 'Dang che bien',
                ])->orWhere(function ($done) {
                    $done->where('ct.TrangThaiMon', 'Hoan tat')
                        ->whereDate('ct.ThoiGianHoanTat', now()->toDateString());
                });
            });
        }

        if (!empty($data['q'])) {
            $word = trim($data['q']);

            $query->where(function ($q) use ($word) {
                $q->where('m.TenMon', 'like', "%{$word}%")
                    ->orWhere('b.SoBan', 'like', "%{$word}%")
                    ->orWhere('kv.TenKV', 'like', "%{$word}%");

                if (ctype_digit($word)) {
                    $q->orWhere('ct.MaCTOrder', (int) $word)
                        ->orWhere('po.MaOrder', (int) $word);
                }
            });
        }

        $query->orderByRaw(
            "CASE ct.TrangThaiMon
                WHEN 'Cho che bien' THEN 0
                WHEN 'Da nhan' THEN 1
                WHEN 'Dang che bien' THEN 2
                ELSE 3
            END"
        )
        ->orderBy('ct.ThoiGianNhan')
        ->orderBy('ct.MaCTOrder');

        return response()->json(
            $query->paginate((int) ($data['per_page'] ?? 50))
        );
    }

    public function updateStatus(Request $request, int $detailId)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'BoPhan' => ['nullable', Rule::in(['Bep', 'Bar'])],
            'TrangThaiHienTai' => [
                'nullable',
                Rule::in(array_keys(self::NEXT))
            ],
            'TrangThaiMon' => [
                'required',
                'in:Da nhan,Dang che bien,Hoan tat'
            ],
        ]);

        $actorRole = (string) ($request->user()?->TenVaiTro ?? '');
        $result = DB::transaction(function () use ($request, $detailId, $data, $actorRole) {
            $line = DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)
                ->lockForUpdate()
                ->first();

            abort_unless($line, 404, 'Không tìm thấy món trong order.');

            $info = DB::table('CHI_TIET_ORDER as ct')
                ->join('PHIEU_ORDER as po', 'po.MaOrder', '=', 'ct.MaOrder')
                ->join('BAN as b', 'b.MaBan', '=', 'po.MaBan')
                ->join('KHU_VUC as kv', 'kv.MaKV', '=', 'b.MaKV')
                ->join('MON as m', 'm.MaMon', '=', 'ct.MaMon')
                ->join('DANH_MUC_MON as dm', 'dm.MaDM', '=', 'm.MaDM')
                ->where('ct.MaCTOrder', $detailId)
                ->first([
                    'kv.MaCS',
                    'dm.NoiCheBien',
                    'po.TrangThai as TrangThaiOrder'
                ]);

            abort_unless(
                $info,
                404,
                'Không tìm thấy cơ sở hoặc bộ phận chế biến.'
            );

            if (
                isset($data['MaCS']) &&
                (int) $data['MaCS'] !== (int) $info->MaCS
            ) {
                abort(403, 'Món không thuộc cơ sở đã chọn.');
            }

            if (
                isset($data['BoPhan']) &&
                $data['BoPhan'] !== $info->NoiCheBien
            ) {
                abort(403, 'Món thuộc bộ phận khác.');
            }
            if (in_array($actorRole, ['Bep', 'Bar'], true) && $actorRole !== $info->NoiCheBien) {
                abort(403, 'Tài khoản không được cập nhật món của bộ phận khác.');
            }

            if (!in_array(
                $info->TrangThaiOrder,
                ['Da gui', 'Dang thanh toan'],
                true
            )) {
                throw ValidationException::withMessages([
                    'TrangThaiMon' => 'Order chưa được gửi hoặc đã kết thúc.',
                ]);
            }

            if ($line->TrangThaiMon === 'Da huy') {
                throw ValidationException::withMessages([
                    'TrangThaiMon' => 'Món đã bị hủy, không thể cập nhật.',
                ]);
            }

            if (!$line->ThoiGianNhan) {
                throw ValidationException::withMessages([
                    'TrangThaiMon' => 'Món chưa được gửi đến Bếp/Bar.',
                ]);
            }

            if (
                isset($data['TrangThaiHienTai']) &&
                $line->TrangThaiMon !== $data['TrangThaiHienTai']
            ) {
                abort(
                    409,
                    'Món vừa được thiết bị khác cập nhật. Hãy làm mới dữ liệu.'
                );
            }

            if (
                (self::NEXT[$line->TrangThaiMon] ?? null)
                !== $data['TrangThaiMon']
            ) {
                throw ValidationException::withMessages([
                    'TrangThaiMon' =>
                        'Không thể chuyển trạng thái món theo quy trình hiện tại.',
                ]);
            }

            $update = [
                'TrangThaiMon' => $data['TrangThaiMon']
            ];

            if ($data['TrangThaiMon'] === 'Da nhan') {
                $update['ThoiGianNhan'] =
                    $line->ThoiGianNhan ?: now();
            } elseif ($data['TrangThaiMon'] === 'Dang che bien') {
                $update['ThoiGianBatDauCheBien'] =
                    $line->ThoiGianBatDauCheBien ?: now();
            } else {
                $update['ThoiGianHoanTat'] =
                    $line->ThoiGianHoanTat ?: now();
            }

            DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)
                ->where('TrangThaiMon', $line->TrangThaiMon)
                ->update($update);

            DB::table('NHAT_KY')->insert([
                'ThoiGian' => now(), 'HanhDong' => 'Cap nhat trang thai che bien',
                'DoiTuong' => 'CHI_TIET_ORDER',
                'NoiDungThayDoi' => "Món #{$detailId}: {$line->TrangThaiMon} -> {$data['TrangThaiMon']}.",
                'MaTK' => $request->user()?->getAuthIdentifier(),
            ]);

            return DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)
                ->first();
        });

        return response()->json([
            'message' => 'Đã cập nhật trạng thái chế biến.',
            'data' => $result,
        ]);
    }

    public function notifications(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branch = (int) $data['MaCS'];
        $read = Cache::store('file')->get('bepbar_read_' . $branch, []);

        $query = $this->itemsQuery($branch)->where('ct.ThoiGianNhan', '>=', now()->subHours(24));
        $role = (string) ($request->user()?->TenVaiTro ?? '');
        if (in_array($role, ['Bep', 'Bar'], true)) $query->where('dm.NoiCheBien', $role);
        $rows = $query->orderByDesc('ct.ThoiGianNhan')->limit(30)->get();

        foreach ($rows as $row) {
            $row->MaTB = $row->MaCTOrder;
            $row->ThoiGianTao = $row->ThoiGianNhan;
            $row->NhanVienNhan = $row->NhanVienPhuTrach;

            $row->NoiDung =
                "Món {$row->TenMon} tại bàn {$row->SoBan}: " .
                (
                    $row->TrangThaiMon === 'Hoan tat'
                        ? 'đã hoàn tất.'
                        : 'đã gửi Bếp/Bar.'
                );

            $row->DaDoc = in_array(
                (int) $row->MaCTOrder,
                (array) $read,
                true
            );
        }

        return response()->json($rows);
    }

    public function stockRequests(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'BoPhan' => ['nullable', Rule::in(['Bep', 'Bar'])],
        ]);
        $department = $this->departmentFor($request, $data['BoPhan'] ?? null);
        abort_unless($department, 422, 'Vui lòng xác định bộ phận nhận nguyên liệu.');

        $rows = DB::table('PHIEU_XUAT_KHO as px')
            ->join('NHAN_VIEN as nv', 'nv.MaNV', '=', 'px.MaNV')
            ->leftJoin('NHAN_VIEN as duyet', 'duyet.MaNV', '=', 'px.MaNVDuyet')
            ->where('px.MaCS', $data['MaCS'])
            ->where('px.BoPhanNhan', $department)
            ->orderByDesc('px.MaPX')
            ->limit(30)
            ->get([
                'px.MaPX', 'px.NgayXuat', 'px.TrangThai', 'px.BoPhanNhan',
                'nv.HoTen as NguoiDeNghi', 'duyet.HoTen as NguoiXuat',
            ]);

        foreach ($rows as $row) {
            $row->items = DB::table('CT_PHIEU_XUAT as cx')
                ->join('NGUYEN_LIEU as nl', 'nl.MaNL', '=', 'cx.MaNL')
                ->where('cx.MaPX', $row->MaPX)
                ->groupBy('cx.MaNL', 'nl.TenNL', 'nl.DonViTinh')
                ->get([
                    'cx.MaNL', 'nl.TenNL', 'nl.DonViTinh',
                    DB::raw('SUM(COALESCE(cx.SoLuongDeNghi, 0)) as SoLuongDeNghi'),
                    DB::raw('SUM(cx.SoLuongXuat) as SoLuongXuat'),
                ]);
        }
        return response()->json($rows);
    }

    public function createStockRequest(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'BoPhanNhan' => ['nullable', Rule::in(['Bep', 'Bar'])],
            'MaNV' => ['nullable', 'integer', 'exists:NHAN_VIEN,MaNV'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.MaNL' => ['required', 'integer', 'distinct', 'exists:NGUYEN_LIEU,MaNL'],
            'items.*.SoLuongDeNghi' => ['required', 'numeric', 'gt:0'],
        ]);

        $department = $this->departmentFor($request, $data['BoPhanNhan'] ?? null);
        abort_unless($department, 422, 'Vui lòng chọn Bếp hoặc Bar.');
        $actor = $request->user();
        $employeeId = (int) ($actor?->MaNV ?? $data['MaNV'] ?? 0);
        if (!$employeeId) {
            throw ValidationException::withMessages([
                'MaNV' => 'Tài khoản cần được liên kết với nhân viên để lập đề nghị xuất kho.',
            ]);
        }
        if ($actor?->MaNV && (int) $actor->MaNV !== $employeeId) {
            abort(403, 'Không được lập đề nghị thay nhân viên khác.');
        }
        $employee = DB::table('NHAN_VIEN')->where('MaNV', $employeeId)
            ->where('MaCS', $data['MaCS'])->where('TrangThai', 'Dang lam')->exists();
        abort_unless($employee, 403, 'Nhân viên không thuộc cơ sở đang thao tác hoặc không còn làm việc.');

        $activeIds = DB::table('NGUYEN_LIEU')
            ->whereIn('MaNL', collect($data['items'])->pluck('MaNL'))
            ->where('TrangThai', 'Dang su dung')
            ->pluck('MaNL')->map(fn ($id) => (int) $id)->all();
        foreach ($data['items'] as $index => $item) {
            if (!in_array((int) $item['MaNL'], $activeIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.MaNL" => 'Nguyên liệu đã ngừng sử dụng.',
                ]);
            }
        }

        $id = DB::transaction(function () use ($request, $data, $department, $employeeId) {
            $issueId = DB::table('PHIEU_XUAT_KHO')->insertGetId([
                'NgayXuat' => now(), 'BoPhanNhan' => $department,
                'TrangThai' => 'Cho xac nhan', 'MaCS' => $data['MaCS'],
                'MaNVDuyet' => null, 'MaNV' => $employeeId,
            ], 'MaPX');

            foreach ($data['items'] as $item) {
                $quantity = (float) $item['SoLuongDeNghi'];
                DB::table('CT_PHIEU_XUAT')->insert([
                    'MaPX' => $issueId, 'MaNL' => $item['MaNL'],
                    'MaCTPN' => null, 'SoLuongDeNghi' => $quantity,
                    // Giá trị tạm giữ để đáp ứng CHECK > 0; không làm giảm tồn kho.
                    'SoLuongXuat' => $quantity,
                ]);
            }

            DB::table('NHAT_KY')->insert([
                'ThoiGian' => now(), 'HanhDong' => 'De nghi xuat kho',
                'DoiTuong' => 'PHIEU_XUAT_KHO',
                'NoiDungThayDoi' => "Bộ phận {$department} lập đề nghị xuất kho #{$issueId}.",
                'MaTK' => $request->user()?->getAuthIdentifier(),
            ]);
            return $issueId;
        });

        return response()->json([
            'message' => 'Đã gửi đề nghị xuất kho. Tồn kho chỉ thay đổi khi Kho xác nhận.',
            'MaPX' => $id,
        ], 201);
    }

    public function markNotificationRead(
        Request $request,
        int $notificationId
    ) {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branch = (int) $data['MaCS'];

        $query = $this->itemsQuery($branch)->where('ct.MaCTOrder', $notificationId);
        $role = (string) ($request->user()?->TenVaiTro ?? '');
        if (in_array($role, ['Bep', 'Bar'], true)) $query->where('dm.NoiCheBien', $role);
        abort_unless(
            $query->exists(),
            404,
            'Không tìm thấy thông báo ở cơ sở đang chọn.'
        );

        $key = 'bepbar_read_' . $branch;
        $read = Cache::store('file')->get($key, []);
        $read[] = $notificationId;

        Cache::store('file')->put(
            $key,
            array_values(array_unique(array_map('intval', $read))),
            now()->addDays(30)
        );

        return response()->json([
            'message' => 'Đã đánh dấu thông báo là đã đọc.'
        ]);
    }
}
