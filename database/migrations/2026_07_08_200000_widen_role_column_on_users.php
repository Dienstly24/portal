<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original enum('role', ['admin','employee','customer']) does not
 * include 'manager' and 'support', although the application assigns and
 * filters both roles (EmployeeController, route middleware, queries).
 * On MySQL strict mode / SQLite check constraints, promoting a user to
 * manager therefore failed. A plain string column keeps all existing
 * values valid and supports every role the code uses. (Audit C4)
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('customer')->change();
        });
    }

    public function down(): void {
        // Intentionally left as string: converting back to the narrow enum
        // would destroy manager/support values.
    }
};
