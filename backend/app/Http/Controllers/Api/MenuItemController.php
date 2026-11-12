<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MenuItemController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'MaCS' => ['nullable', 'integer', 'exists:CO_SO,MaCS'],
            'MaDM' => ['nullable', 'integer', 'exists:DANH_MUC_MON,MaDM'],
            'TrangThai' => [
                'nullable',
                Rule::in(['Dang ban', 'Ngung ban'])
            ],
            'search' => ['nullable', 'string', 'max:180'],
        ]);

        $branchId = $data['MaCS'] ?? DB::table('CO_SO')
            ->where('TrangThai', 'Dang hoat dong')
            ->orderBy('MaCS')
            ->value('MaCS');

        $branchActive = $branchId && DB::table('CO_SO')
            ->where('MaCS', $branchId)
            ->where('TrangThai', 'Dang hoat dong')
            ->exists();

        $query = DB::table('MON as m')
            ->join(
                'DANH_MUC_MON as dm',
                'dm.MaDM',
                '=',
                'm.MaDM'
            )
            ->leftJoin(
                'GIA_MON_CO_SO as g',
                function ($join) use ($branchId) {
                    $join->on(
                        'g.MaMon',
                        '=',
                        'm.MaMon'
                    )->where(
                        'g.MaCS',
                        '=',
                        $branchId ?: 0
                    );
                }
            )
            ->select(
                'm.MaMon',
                'm.TenMon',
                'm.DonViTinh',
                'm.MaDM',
                'm.TrangThai',
                'dm.TenDM',
                'dm.NoiCheBien',
                'dm.TrangThai as TrangThaiDanhMuc',
                'g.MaCS',
                'g.DonGia',
                'g.TrangThaiBan'
            );

        if (!empty($data['search'])) {
            $query->where(
                'm.TenMon',
                'like',
                '%' . trim($data['search']) . '%'
            );
        }

        if (!empty($data['MaDM'])) {
            $query->where('m.MaDM', $data['MaDM']);
        }

        if (!empty($data['TrangThai'])) {
            $query->where(
                'm.TrangThai',
                $data['TrangThai']
            );
        }

        $items = $query
            ->orderBy('dm.TenDM')
            ->orderBy('m.TenMon')
            ->get();

        foreach ($items as $item) {
            $item->CoTheBan = (bool) (
                $branchActive
                && $item->TrangThai === 'Dang ban'
                && $item->TrangThaiDanhMuc === 'Dang hoat dong'
                && $item->DonGia !== null
                && $item->TrangThaiBan === 'Dang ban'
            );
        }

        return response()->json($items);
    }

    private function rules(): array
    {
        return [
            'TenMon' => [
                'required', 'string', 'max:180'
            ],
            'DonViTinh' => [
                'required', 'string', 'max:30'
            ],
            'MaDM' => [
                'required',
                'integer',
                'exists:DANH_MUC_MON,MaDM'
            ],
            'TrangThai' => [
                'required',
                Rule::in(['Dang ban', 'Ngung ban'])
            ],
            'MaCS' => [
                'required',
                'integer',
                'exists:CO_SO,MaCS'
            ],
            'DonGia' => [
                'nullable',
                'numeric',
                'min:0'
            ],
            'TrangThaiBan' => [
                'required',
                Rule::in(['Dang ban', 'Ngung ban'])
            ],
        ];
    }

    private function checkPriceAndBranch(array $data): void
    {
        if ($data['TrangThaiBan'] !== 'Dang ban') {
            return;
        }

        $branchActive = DB::table('CO_SO')
            ->where('MaCS', $data['MaCS'])
            ->where('TrangThai', 'Dang hoat dong')
            ->exists();

        if (!$branchActive) {
            throw ValidationException::withMessages([
                'MaCS' => 'Cơ sở đang ngừng hoạt động, không thể bật bán.',
            ]);
        }

        $categoryActive = DB::table('DANH_MUC_MON')
            ->where('MaDM', $data['MaDM'])
            ->where('TrangThai', 'Dang hoat dong')
            ->exists();

        if (
            !$categoryActive ||
            $data['TrangThai'] !== 'Dang ban'
        ) {
            throw ValidationException::withMessages([
                'TrangThaiBan' =>
                    'Món và danh mục phải đang hoạt động để bật bán tại cơ sở.',
            ]);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $this->checkPriceAndBranch($data);

        if (
            $data['TrangThaiBan'] === 'Dang ban' &&
            !isset($data['DonGia'])
        ) {
            throw ValidationException::withMessages([
                'DonGia' =>
                    'Cần nhập giá đã xác minh trước khi bật bán tại cơ sở.',
            ]);
        }

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('MON')->insertGetId([
                'TenMon' => $data['TenMon'],
                'DonViTinh' => $data['DonViTinh'],
                'MaDM' => $data['MaDM'],
                'TrangThai' => $data['TrangThai'],
            ], 'MaMon');

            if (isset($data['DonGia'])) {
                DB::table('GIA_MON_CO_SO')->insert([
                    'MaCS' => $data['MaCS'],
                    'MaMon' => $id,
                    'DonGia' => $data['DonGia'],
                    'TrangThaiBan' => $data['TrangThaiBan'],
                ]);
            }

            return $id;
        });

        return response()->json([
            'message' => 'Đã tạo món.',
            'MaMon' => $id,
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        abort_unless(
            DB::table('MON')->where('MaMon', $id)->exists(),
            404,
            'Không tìm thấy món.'
        );

        $data = $request->validate($this->rules());

        $this->checkPriceAndBranch($data);

        $existingPrice = DB::table('GIA_MON_CO_SO')
            ->where('MaCS', $data['MaCS'])
            ->where('MaMon', $id)
            ->first();

        if (
            $data['TrangThaiBan'] === 'Dang ban' &&
            !isset($data['DonGia']) &&
            !$existingPrice
        ) {
            throw ValidationException::withMessages([
                'DonGia' =>
                    'Món chưa có giá tại cơ sở này. Vui lòng nhập giá trước.',
            ]);
        }

        DB::transaction(function () use (
            $id,
            $data,
            $existingPrice
        ) {
            DB::table('MON')
                ->where('MaMon', $id)
                ->update([
                    'TenMon' => $data['TenMon'],
                    'DonViTinh' => $data['DonViTinh'],
                    'MaDM' => $data['MaDM'],
                    'TrangThai' => $data['TrangThai'],
                ]);

            if (isset($data['DonGia'])) {
                DB::table('GIA_MON_CO_SO')
                    ->updateOrInsert(
                        [
                            'MaCS' => $data['MaCS'],
                            'MaMon' => $id,
                        ],
                        [
                            'DonGia' => $data['DonGia'],
                            'TrangThaiBan' => $data['TrangThaiBan'],
                        ]
                    );
            } elseif ($existingPrice) {
                DB::table('GIA_MON_CO_SO')
                    ->where('MaCS', $data['MaCS'])
                    ->where('MaMon', $id)
                    ->update([
                        'TrangThaiBan' => $data['TrangThaiBan'],
                    ]);
            }
        });

        return response()->json([
            'message' => 'Đã cập nhật món và thông tin cơ sở.',
        ]);
    }

    public function updateBranchStatus(
        Request $request,
        int $id
    ) {
        abort_unless(
            DB::table('MON')->where('MaMon', $id)->exists(),
            404,
            'Không tìm thấy món.'
        );

        $data = $request->validate([
            'MaCS' => [
                'required',
                'integer',
                'exists:CO_SO,MaCS'
            ],
            'TrangThaiBan' => [
                'required',
                Rule::in(['Dang ban', 'Ngung ban'])
            ],
        ]);

        $price = DB::table('GIA_MON_CO_SO')
            ->where('MaCS', $data['MaCS'])
            ->where('MaMon', $id);

        abort_unless(
            $price->exists(),
            422,
            'Món chưa có giá tại cơ sở này.'
        );

        if ($data['TrangThaiBan'] === 'Dang ban') {
            $item = DB::table('MON as m')
                ->join(
                    'DANH_MUC_MON as d',
                    'd.MaDM',
                    '=',
                    'm.MaDM'
                )
                ->where('m.MaMon', $id)
                ->select(
                    'm.TrangThai',
                    'd.TrangThai as TrangThaiDanhMuc'
                )
                ->first();

            $branchActive = DB::table('CO_SO')
                ->where('MaCS', $data['MaCS'])
                ->where('TrangThai', 'Dang hoat dong')
                ->exists();

            if (
                !$branchActive ||
                $item->TrangThai !== 'Dang ban' ||
                $item->TrangThaiDanhMuc !== 'Dang hoat dong'
            ) {
                throw ValidationException::withMessages([
                    'TrangThaiBan' =>
                        'Không thể bật bán khi món, danh mục hoặc cơ sở ngừng hoạt động.',
                ]);
            }
        }

        $price->update([
            'TrangThaiBan' => $data['TrangThaiBan'],
        ]);

        return response()->json([
            'message' => 'Đã cập nhật trạng thái tại cơ sở.',
        ]);
    }

    public function destroy(int $id)
    {
        abort_unless(
            DB::table('MON')->where('MaMon', $id)->exists(),
            404,
            'Không tìm thấy món.'
        );

        DB::table('MON')
            ->where('MaMon', $id)
            ->update([
                'TrangThai' => 'Ngung ban',
            ]);

        return response()->json([
            'message' => 'Đã ngừng bán món trên toàn hệ thống.',
        ]);
    }
}
