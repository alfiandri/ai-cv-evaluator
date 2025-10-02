<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id')->index();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id')->index();
        });
        Schema::table('evaluations', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id')->index();
            $table->uuid('user_id')->nullable()->after('tenant_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('uploaded_files', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        Schema::table('documents', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        Schema::table('evaluations', fn(Blueprint $t) => $t->dropColumn('tenant_id'));
        Schema::table('evaluations', fn(Blueprint $t) => $t->dropColumn('user_id'));
    }
};
