<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('omniconnect_orders', function (Blueprint $table) {
            // The {remoteId} the platform addressed the order to (our POS vendor
            // id). `vendor_id` holds the platform's own restaurant id, which is
            // not what status callbacks are routed by.
            $table->string('remote_id')->nullable()->index()->after('vendor_id');

            // Set once the app's IncomingOrderHandler has taken the order. Null
            // means the handoff has not succeeded yet, so a re-delivered
            // dispatch runs the handler again instead of acking an order the
            // app never received.
            $table->timestamp('handled_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('omniconnect_orders', function (Blueprint $table) {
            $table->dropIndex(['remote_id']);
            $table->dropColumn(['remote_id', 'handled_at']);
        });
    }
};
