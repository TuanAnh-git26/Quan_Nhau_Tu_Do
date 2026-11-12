<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('schema_mysql.sql')));
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            DB::statement('DROP TABLE IF EXISTS `TICKET_HO_TRO`');
            DB::statement('DROP TABLE IF EXISTS `NHAT_KY`');
            DB::statement('DROP TABLE IF EXISTS `PHAN_QUYEN`');
            DB::statement('DROP TABLE IF EXISTS `TAI_KHOAN`');
            DB::statement('DROP TABLE IF EXISTS `KE_HOACH_NGAN_SACH`');
            DB::statement('DROP TABLE IF EXISTS `PHIEU_THU_CHI`');
            DB::statement('DROP TABLE IF EXISTS `BANG_LUONG`');
            DB::statement('DROP TABLE IF EXISTS `CHAM_CONG`');
            DB::statement('DROP TABLE IF EXISTS `HOP_DONG`');
            DB::statement('DROP TABLE IF EXISTS `AP_DUNG_KM`');
            DB::statement('DROP TABLE IF EXISTS `KHUYEN_MAI`');
            DB::statement('DROP TABLE IF EXISTS `THANH_TOAN`');
            DB::statement('DROP TABLE IF EXISTS `CT_HOA_DON`');
            DB::statement('DROP TABLE IF EXISTS `HOA_DON`');
            DB::statement('DROP TABLE IF EXISTS `BAO_CAO_CUOI_CA`');
            DB::statement('DROP TABLE IF EXISTS `CT_PHIEU_KIEM_KE`');
            DB::statement('DROP TABLE IF EXISTS `PHIEU_KIEM_KE`');
            DB::statement('DROP TABLE IF EXISTS `CT_PHIEU_XUAT`');
            DB::statement('DROP TABLE IF EXISTS `PHIEU_XUAT_KHO`');
            DB::statement('DROP TABLE IF EXISTS `CT_PHIEU_NHAP`');
            DB::statement('DROP TABLE IF EXISTS `PHIEU_NHAP_KHO`');
            DB::statement('DROP TABLE IF EXISTS `CT_DON_DAT_HANG`');
            DB::statement('DROP TABLE IF EXISTS `DON_DAT_HANG`');
            DB::statement('DROP TABLE IF EXISTS `PHIEU_KHIEU_NAI`');
            DB::statement('DROP TABLE IF EXISTS `CHI_TIET_ORDER`');
            DB::statement('DROP TABLE IF EXISTS `PHIEU_ORDER`');
            DB::statement('DROP TABLE IF EXISTS `DINH_MUC`');
            DB::statement('DROP TABLE IF EXISTS `GIA_MON_CO_SO`');
            DB::statement('DROP TABLE IF EXISTS `MON`');
            DB::statement('DROP TABLE IF EXISTS `DAT_BAN_BAN`');
            DB::statement('DROP TABLE IF EXISTS `DAT_BAN`');
            DB::statement('DROP TABLE IF EXISTS `BAN`');
            DB::statement('DROP TABLE IF EXISTS `KHU_VUC`');
            DB::statement('DROP TABLE IF EXISTS `NHAN_VIEN`');
            DB::statement('DROP TABLE IF EXISTS `CA_LAM`');
            DB::statement('DROP TABLE IF EXISTS `NGUYEN_LIEU`');
            DB::statement('DROP TABLE IF EXISTS `DANH_MUC_MON`');
            DB::statement('DROP TABLE IF EXISTS `NHA_CUNG_CAP`');
            DB::statement('DROP TABLE IF EXISTS `KHACH_HANG`');
            DB::statement('DROP TABLE IF EXISTS `VAI_TRO`');
            DB::statement('DROP TABLE IF EXISTS `VI_TRI`');
            DB::statement('DROP TABLE IF EXISTS `CO_SO`');
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
