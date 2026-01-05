<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('integration_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->string('operation');
            $table->string('key');
            $table->string('request_hash');
            $table->longText('response_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['operation', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_idempotency');
    }
};
