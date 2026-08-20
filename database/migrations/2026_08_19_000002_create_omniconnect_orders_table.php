<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omniconnect_orders', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('delivery_hero');
            $table->string('tenant_id')->nullable()->index();
            $table->string('order_token');
            // Our POS-side order id, returned in the dispatch ack; Delivery Hero
            // uses it to address later status callbacks (cancellation, etc.).
            $table->string('remote_order_id')->nullable()->index();
            $table->string('order_code')->nullable();
            $table->string('vendor_id')->nullable()->index();
            $table->string('status')->default('received'); // received|accepted|rejected|prepared|picked_up|cancelled
            $table->string('expedition_type')->nullable();
            $table->unsignedBigInteger('total')->default(0); // minor units (paisa)
            $table->string('currency', 8)->default('PKR');
            $table->boolean('paid_online')->default(false);
            $table->string('reject_reason')->nullable();
            $table->json('payload')->nullable(); // raw incoming payload (audit)
            $table->timestamp('received_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Idempotency: one row per platform order.
            $table->unique(['platform', 'order_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omniconnect_orders');
    }
};
