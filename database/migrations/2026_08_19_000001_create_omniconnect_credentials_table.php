<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omniconnect_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('platform')->default('delivery_hero');
            $table->boolean('sandbox')->default(true);
            $table->string('username')->nullable();
            $table->text('password')->nullable();        // encrypted
            $table->string('chain_code')->nullable();
            $table->json('vendor_ids')->nullable();
            $table->text('webhook_secret')->nullable();   // encrypted
            $table->timestamps();

            $table->unique(['tenant_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omniconnect_credentials');
    }
};
