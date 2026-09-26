<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entschärft die Manager-Referenz auf Acts: `acts.manager_customer_id` wird
 * nullable und die FK von `cascadeOnDelete` auf `nullOnDelete` umgestellt.
 *
 * Die Nachfolge innerhalb eines Acts regelt der `ModelProfileEraser` app-seitig
 * (erstes Restmitglied wird Manager). Diese Migration ist Defense-in-depth für
 * den Fall, dass ein Customer außerhalb des Erasers gelöscht wird: Der Act und
 * seine Mitglieder bleiben dann erhalten, nur die Manager-Referenz wird geleert.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::table('acts', function (Blueprint $table) use ($sqlite) {
            if ($sqlite) {
                $table->dropForeign(['manager_customer_id']);
            } else {
                $table->dropForeign('acts_manager_customer_id_foreign');
            }
        });

        Schema::table('acts', function (Blueprint $table) {
            $table->uuid('manager_customer_id')->nullable()->change();
        });

        Schema::table('acts', function (Blueprint $table) {
            $table->foreign('manager_customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // down() wird nie ausgeführt (Policy).
    }
};
