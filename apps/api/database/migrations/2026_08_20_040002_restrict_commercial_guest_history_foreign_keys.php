<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['voucher_redemptions', 'commercial_promotion_usages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $orphanExists = DB::table($table)
                ->whereNotNull("{$table}.guest_id")
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('guests')->whereColumn('guests.id', "{$table}.guest_id"))
                ->exists();
            if ($orphanExists) {
                throw new RuntimeException("Cannot protect immutable guest history: {$table} contains an orphaned guest_id. Repair the source identity before retrying this migration; no foreign keys were changed.");
            }
        }

        foreach (self::TABLES as $table) {
            $this->replaceGuestForeignKey($table, restrict: true);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->replaceGuestForeignKey($table, restrict: false);
        }
    }

    private function replaceGuestForeignKey(string $tableName, bool $restrict): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName, $restrict): void {
            $constraint = "{$tableName}_guest_id_foreign";
            $table->dropForeign(['guest_id']);
            $foreign = $table->foreign('guest_id', $constraint)->references('id')->on('guests');
            if ($restrict) {
                $foreign->restrictOnDelete();
            } else {
                $foreign->nullOnDelete();
            }
        });
    }
};
