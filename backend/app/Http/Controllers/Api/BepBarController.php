<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BepBarController extends Controller
{
    private const CHO_CHE_BIEN = 'Cho che bien';
    private const DANG_CHE_BIEN = 'Dang che bien';
    private const HOAN_TAT = 'Hoan tat';
    private const DA_HUY = 'Da huy';

    /**
     * Danh sách món cần Bếp/Bar xử lý.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
            'NoiCheBien' => ['nullable', 'string', 'max:50'],
            'TrangThaiMon' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = $this->orderItemsQuery((int) $data['MaCS']);

        if (!empty($data['NoiCheBien'])) {
            $query->where(
                'dm.NoiCheBien',
                $data['NoiCheBien']
            );
        }

        $status = $data['TrangThaiMon'] ?? null;

        if ($status === 'Tat ca') {
            // Hiển thị tất cả món, trừ món đã hủy.
        } elseif (!empty($status)) {
            $query->where('ct.TrangThaiMon', $status);
        } else {
            // Mặc định chỉ hiển thị món đang cần xử lý.
            $query->whereIn('ct.TrangThaiMon', [
                self::CHO_CHE_BIEN,
                self::DANG_CHE_BIEN,
            ]);
        }

        if (!empty($data['q'])) {
            $keyword = trim($data['q']);

            $query->where(function ($sub) use ($keyword) {
                $sub->where('m.TenMon', 'like', "%{$keyword}%")
                    ->orWhere('dm.TenDM', 'like', "%{$keyword}%")
                    ->orWhere('b.SoBan', 'like', "%{$keyword}%");

                if (ctype_digit($keyword)) {
                    $sub->orWhere(
                        'ct.MaCTOrder',
                        (int) $keyword
                    )->orWhere(
                        'po.MaOrder',
                        (int) $keyword
                    );
                }
            });
        }

        $query->orderByRaw("
            CASE
                WHEN ct.TrangThaiMon = 'Dang che bien' THEN 0
                WHEN ct.TrangThaiMon = 'Cho che bien' THEN 1
                ELSE 2
            END
        ")
        ->orderBy('ct.ThoiGianNhan')
        ->orderBy('ct.MaCTOrder');

        return response()->json(
            $query->paginate($data['per_page'] ?? 10)
        );
    }

    /**
     * Truy vấn dữ liệu món theo cơ sở.
     */
    private function orderItemsQuery(int $branchId)
    {
        return DB::table('CHI_TIET_ORDER as ct')
            ->join(
                'PHIEU_ORDER as po',
                'po.MaOrder',
                '=',
                'ct.MaOrder'
            )
            ->join(
                'MON as m',
                'm.MaMon',
                '=',
                'ct.MaMon'
            )
            ->join(
                'DANH_MUC_MON as dm',
                'dm.MaDM',
                '=',
                'm.MaDM'
            )
            ->join(
                'BAN as b',
                'b.MaBan',
                '=',
                'po.MaBan'
            )
            ->join(
                'KHU_VUC as kv',
                'kv.MaKV',
                '=',
                'b.MaKV'
            )
            ->where('kv.MaCS', $branchId)
            ->whereNotNull('ct.ThoiGianNhan')
            ->where('ct.TrangThaiMon', '<>', self::DA_HUY)
            ->select([
                'ct.MaCTOrder',
                'ct.MaOrder',
                'ct.MaMon',
                'm.TenMon',
                'dm.TenDM',
                'dm.NoiCheBien',
                'ct.SoLuong',
                'ct.DonGia',
                'ct.GhiChu',
                'ct.TrangThaiMon',
                'ct.ThoiGianNhan',
                'ct.ThoiGianBatDauCheBien',
                'ct.ThoiGianHoanTat',
                'po.TrangThai as TrangThaiOrder',
                'b.SoBan',
                'kv.TenKV',
                'kv.MaCS',
            ]);
    }

    /**
     * Cập nhật trạng thái chế biến.
     *
     * Chờ chế biến -> Đang chế biến -> Hoàn tất.
     */
    public function updateStatus(
        Request $request,
        int $detailId
    ) {
        $data = $request->validate([
            'TrangThaiMon' => [
                'required',
                'string',
                'in:Dang che bien,Hoan tat',
            ],
        ]);

        $result = DB::transaction(function () use (
            $detailId,
            $data
        ) {
            $detail = DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $detail,
                404,
                'Không tìm thấy món trong order.'
            );

            if ($detail->TrangThaiMon === self::DA_HUY) {
                throw ValidationException::withMessages([
                    'TrangThaiMon' => 'Món đã bị hủy, không thể cập nhật.',
                ]);
            }

            if (empty($detail->ThoiGianNhan)) {
                throw ValidationException::withMessages([
                    'TrangThaiMon' => 'Món chưa được gửi đến Bếp/Bar.',
                ]);
            }

            $currentStatus = $detail->TrangThaiMon;
            $newStatus = $data['TrangThaiMon'];

            // Không thực hiện lại thao tác đã hoàn thành.
            if ($currentStatus === $newStatus) {
                return $detail;
            }

            if (
                $currentStatus === self::CHO_CHE_BIEN
                && $newStatus === self::DANG_CHE_BIEN
            ) {
                DB::table('CHI_TIET_ORDER')
                    ->where('MaCTOrder', $detailId)
                    ->update([
                        'TrangThaiMon' => self::DANG_CHE_BIEN,
                        'ThoiGianBatDauCheBien' =>
                            $detail->ThoiGianBatDauCheBien
                                ?: now(),
                    ]);
            } elseif (
                $currentStatus === self::DANG_CHE_BIEN
                && $newStatus === self::HOAN_TAT
            ) {
                DB::table('CHI_TIET_ORDER')
                    ->where('MaCTOrder', $detailId)
                    ->update([
                        'TrangThaiMon' => self::HOAN_TAT,
                        'ThoiGianBatDauCheBien' =>
                            $detail->ThoiGianBatDauCheBien
                                ?: now(),
                        'ThoiGianHoanTat' => now(),
                    ]);
            } else {
                throw ValidationException::withMessages([
                    'TrangThaiMon' =>
                        'Không thể chuyển trạng thái món theo quy trình hiện tại.',
                ]);
            }

            return DB::table('CHI_TIET_ORDER')
                ->where('MaCTOrder', $detailId)
                ->first();
        });

        return response()->json([
            'message' => 'Cập nhật trạng thái chế biến thành công.',
            'data' => $result,
        ]);
    }

    /**
     * Danh sách thông báo món mới trong 24 giờ gần nhất.
     */
    public function notifications(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branchId = (int) $data['MaCS'];

        $cacheKey = 'bepbar_read_notifications_' . $branchId;

        $readIds = Cache::store('file')->get($cacheKey, []);

        if (!is_array($readIds)) {
            $readIds = [];
        }

        $items = $this->orderItemsQuery($branchId)
            ->where(
                'ct.ThoiGianNhan',
                '>=',
                now()->subHours(24)
            )
            ->orderByDesc('ct.ThoiGianNhan')
            ->limit(30)
            ->get();

        $items->transform(function ($item) use ($readIds) {
            $item->DaDoc = in_array(
                (int) $item->MaCTOrder,
                array_map('intval', $readIds),
                true
            );

            return $item;
        });

        return response()->json($items);
    }

    /**
     * Đánh dấu thông báo đã đọc.
     *
     * notificationId chính là MaCTOrder.
     */
    public function markNotificationRead(
        Request $request,
        int $notificationId
    ) {
        $data = $request->validate([
            'MaCS' => ['required', 'integer', 'exists:CO_SO,MaCS'],
        ]);

        $branchId = (int) $data['MaCS'];

        $exists = $this->orderItemsQuery($branchId)
            ->where('ct.MaCTOrder', $notificationId)
            ->exists();

        abort_unless(
            $exists,
            404,
            'Không tìm thấy thông báo.'
        );

        $cacheKey = 'bepbar_read_notifications_' . $branchId;

        $readIds = Cache::store('file')->get($cacheKey, []);

        if (!is_array($readIds)) {
            $readIds = [];
        }

        $readIds[] = $notificationId;

        $readIds = array_values(
            array_unique(array_map('intval', $readIds))
        );

        Cache::store('file')->put(
            $cacheKey,
            $readIds,
            now()->addDays(30)
        );

        return response()->json([
            'message' => 'Đã đánh dấu thông báo là đã đọc.',
        ]);
    }
}
