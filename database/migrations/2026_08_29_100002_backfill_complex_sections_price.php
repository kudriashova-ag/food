<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Бекфіл-міграція: для існуючих menu_sections/menu_template_sections типу 'complex'
     * без ціни — проставити price = SUM(dish.price) по поточному складу страв.
     *
     * Це зберігає теперішню фактичну вартість "комплекс з усіма стравами відмічено"
     * як стартову ціну, без сюрпризу для учнів. Постачальник потім коригує вручну.
     */
    public function up(): void
    {
        // menu_sections
        $complexSections = DB::table('menu_sections')
            ->where('type', 'complex')
            ->whereNull('price')
            ->orderBy('id')
            ->get();

        foreach ($complexSections as $section) {
            $sum = DB::table('menu_section_dishes')
                ->join('dishes', 'dishes.id', '=', 'menu_section_dishes.dish_id')
                ->where('menu_section_dishes.menu_section_id', $section->id)
                ->sum('dishes.price');

            DB::table('menu_sections')
                ->where('id', $section->id)
                ->update(['price' => max(0, (float) $sum)]);
        }

        // menu_template_sections
        $templateSections = DB::table('menu_template_sections')
            ->where('type', 'complex')
            ->whereNull('price')
            ->orderBy('id')
            ->get();

        foreach ($templateSections as $section) {
            $sum = DB::table('menu_template_section_dishes')
                ->join('dishes', 'dishes.id', '=', 'menu_template_section_dishes.dish_id')
                ->where('menu_template_section_dishes.menu_template_section_id', $section->id)
                ->sum('dishes.price');

            DB::table('menu_template_sections')
                ->where('id', $section->id)
                ->update(['price' => max(0, (float) $sum)]);
        }
    }

    public function down(): void
    {
        // На reversal — прибрати ціни, повернути NULL.
        DB::table('menu_sections')->where('type', 'complex')->update(['price' => null]);
        DB::table('menu_template_sections')->where('type', 'complex')->update(['price' => null]);
    }
};
