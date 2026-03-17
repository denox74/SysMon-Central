<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_settings', function (Blueprint $table) {
            $table->id();

            // ── Conexión SMTP ─────────────────────────────────────
            $table->string('smtp_host', 255)->default('');
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_username', 255)->default('');
            $table->text('smtp_password')->nullable();   // cifrado en modelo
            $table->string('smtp_encryption', 10)->default('tls'); // tls | ssl | none

            // ── Remitente ─────────────────────────────────────────
            $table->string('from_address', 255)->default('');
            $table->string('from_name', 100)->default('SysMon');

            // ── Destinatarios (array JSON) ────────────────────────
            $table->json('recipients')->default('[]');

            // ── Severidades que disparan email ────────────────────
            $table->json('notify_severities')->default('["warning","critical"]');

            $table->timestamps();
        });

        // Singleton: una sola fila vacía (el usuario la configura desde el panel)
        DB::table('email_settings')->insert([
            'smtp_host'         => '',
            'smtp_port'         => 587,
            'smtp_username'     => '',
            'smtp_password'     => null,
            'smtp_encryption'   => 'tls',
            'from_address'      => '',
            'from_name'         => 'SysMon',
            'recipients'        => json_encode([]),
            'notify_severities' => json_encode(['warning', 'critical']),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_settings');
    }
};
