<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('CT_PHIEU_XUAT', 'MaNL')) {
            Schema::table('CT_PHIEU_XUAT', function (Blueprint $table) {
                $table->unsignedBigInteger('MaNL')->nullable()->after('MaPX');
            });
        }
        if (!Schema::hasColumn('CT_PHIEU_XUAT', 'SoLuongDeNghi')) {
            Schema::table('CT_PHIEU_XUAT', function (Blueprint $table) {
                $table->decimal('SoLuongDeNghi', 14, 3)->nullable()->after('MaCTPN');
            });
        }

        DB::statement(
            'UPDATE CT_PHIEU_XUAT cx
             INNER JOIN CT_PHIEU_NHAP cn ON cn.MaCTPN = cx.MaCTPN
             SET cx.MaNL = cn.MaNL
             WHERE cx.MaNL IS NULL'
        );
        DB::statement('ALTER TABLE CT_PHIEU_XUAT MODIFY MaNL BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE CT_PHIEU_XUAT MODIFY MaCTPN BIGINT UNSIGNED NULL');

        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'CT_PHIEU_XUAT')
            ->where('CONSTRAINT_NAME', 'fk_ct_phieu_xuat_manl')->exists();
        if (!$exists) {
            Schema::table('CT_PHIEU_XUAT', function (Blueprint $table) {
                $table->foreign('MaNL', 'fk_ct_phieu_xuat_manl')
                    ->references('MaNL')->on('NGUYEN_LIEU')
                    ->cascadeOnUpdate()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('CT_PHIEU_XUAT')->whereNull('MaCTPN')->exists()) {
            throw new RuntimeException('Không thể rollback khi còn đề nghị xuất kho chưa xác nhận.');
        }
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'CT_PHIEU_XUAT')
            ->where('CONSTRAINT_NAME', 'fk_ct_phieu_xuat_manl')->exists();
        if ($exists) DB::statement('ALTER TABLE CT_PHIEU_XUAT DROP FOREIGN KEY fk_ct_phieu_xuat_manl');
        DB::statement('ALTER TABLE CT_PHIEU_XUAT MODIFY MaCTPN BIGINT UNSIGNED NOT NULL');
        Schema::table('CT_PHIEU_XUAT', function (Blueprint $table) {
            $table->dropColumn(['SoLuongDeNghi', 'MaNL']);
        });
    }
};
