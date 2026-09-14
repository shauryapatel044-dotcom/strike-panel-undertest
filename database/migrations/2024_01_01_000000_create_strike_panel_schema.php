<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Roles & Users (RBAC)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('permissions'); // Array of string permissions
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
        });

        // 2. Hybrid Nodes (Inbuilt Localnode & TCP Remote Nodes)
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location');
            $table->enum('connection_type', ['unix', 'tcp'])->default('tcp');
            $table->string('socket_path')->nullable(); // e.g. /var/run/strikepanel.sock
            $table->string('fqdn')->nullable();
            $table->integer('daemon_port')->nullable();
            $table->string('daemon_token')->nullable();
            $table->integer('memory_overallocate')->default(0);
            $table->integer('disk_overallocate')->default(0);
            $table->timestamps();
        });

        // 3. Servers (Docker Cgroups Mapping)
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            
            // Cgroup Resource Constraints
            $table->integer('memory_limit'); // MB
            $table->integer('cpu_limit');    // % (e.g. 200 = 2 cores)
            $table->integer('disk_limit');   // MB
            $table->integer('swap_limit')->default(0);
            $table->integer('io_weight')->default(500);

            // Container Blueprint
            $table->string('docker_image');
            $table->text('startup_command');
            $table->json('environment_variables'); // Stores templated envs (e.g., SERVER_MEMORY)
            
            $table->timestamps();
        });

        // 4. Subusers (Granular Access)
        Schema::create('server_subusers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('permissions'); // Server-specific RBAC (console, power, files)
            $table->timestamps();
        });

        // 5. Cron Scheduler Ecosystem
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->string('name');
            $table->string('cron_minute');
            $table->string('cron_hour');
            $table->string('cron_day_of_month');
            $table->string('cron_month');
            $table->string('cron_day_of_week');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('schedule_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->integer('sequence_id');
            $table->enum('action', ['command', 'power', 'backup']);
            $table->text('payload');
            $table->integer('time_offset')->default(0); // Delay execution in seconds
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_tasks');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('server_subusers');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('nodes');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};
