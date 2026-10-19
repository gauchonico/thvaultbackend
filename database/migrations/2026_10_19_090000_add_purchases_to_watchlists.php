<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Purchases made before titles were added to My List automatically.
    public function up(): void
    {
        DB::table('purchases')->select('user_id', 'show_id')->distinct()->orderBy('user_id')
            ->each(function ($p) {
                $exists = DB::table('watchlist')->where('user_id', $p->user_id)->where('show_id', $p->show_id)->exists();
                if (!$exists) {
                    DB::table('watchlist')->insert([
                        'user_id' => $p->user_id, 'show_id' => $p->show_id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Can't tell backfilled rows from ones users added themselves; leave them.
    }
};
