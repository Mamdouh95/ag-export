<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('exports.table', 'export_jobs');

        Schema::create($table, function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->nullableMorphs('user');
            $t->string('label')->nullable();
            $t->string('filename');
            $t->string('disk');
            $t->string('path')->nullable();
            $t->string('status', 32)->default('pending')->index();
            $t->unsignedBigInteger('total_rows')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('exports.table', 'export_jobs'));
    }
};
