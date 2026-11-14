<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────
        // pdf_templates: System + custom templates per tenant
        // ────────────────────────────────────────────────
        if (!Schema::connection('tenant')->hasTable('pdf_templates')) {
            Schema::connection('tenant')->create('pdf_templates', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->string('description', 255)->nullable();
                $t->boolean('is_system')->default(false);
                $t->boolean('is_default')->default(false);
                $t->string('preview_emoji', 8)->default('📄');
                $t->string('accent_color', 16)->default('#1a2744');
                $t->json('settings');
                $t->longText('html_template')->nullable();
                $t->string('created_by', 255)->nullable();
                $t->timestamps();
                $t->index('is_system');
                $t->index('is_default');
            });
        }

        // ────────────────────────────────────────────────
        // bestellung_pdfs: saved/exported PDFs (with cascade-delete)
        // ────────────────────────────────────────────────
        if (!Schema::connection('tenant')->hasTable('bestellung_pdfs')) {
            Schema::connection('tenant')->create('bestellung_pdfs', function (Blueprint $t) {
                $t->id();
                $t->string('bestell_id', 50);
                $t->string('name', 255);
                $t->string('file_path', 500)->nullable();
                $t->unsignedBigInteger('file_size')->default(0);
                $t->unsignedBigInteger('template_id')->nullable();
                $t->longText('html_snapshot')->nullable();
                $t->json('settings_snapshot')->nullable();
                $t->boolean('anonymize_workers')->default(false);
                $t->string('generated_by', 255)->nullable();
                $t->timestamp('generated_at')->useCurrent();
                $t->index('bestell_id');
                $t->index('template_id');
            });

            // FK with cascade delete on parent bestellungen
            try {
                DB::connection('tenant')->statement(
                    'ALTER TABLE bestellung_pdfs ADD CONSTRAINT fk_bestellung_pdfs_bestellung '
                    . 'FOREIGN KEY (bestell_id) REFERENCES bestellungen(bestell_id) ON DELETE CASCADE'
                );
            } catch (\Throwable $e) {
                // FK creation may fail in MySQL if charsets differ — controller-level cascade handles it
            }
        }

        // ────────────────────────────────────────────────
        // pdf_share_links: public share tokens (no auth)
        // ────────────────────────────────────────────────
        if (!Schema::connection('tenant')->hasTable('pdf_share_links')) {
            Schema::connection('tenant')->create('pdf_share_links', function (Blueprint $t) {
                $t->id();
                $t->char('token', 64)->unique();
                $t->unsignedBigInteger('bestellung_pdf_id');
                $t->timestamp('expires_at')->nullable();
                $t->unsignedInteger('access_count')->default(0);
                $t->timestamp('last_accessed_at')->nullable();
                $t->string('created_by', 255)->nullable();
                $t->timestamps();
                $t->index('expires_at');
                $t->index('bestellung_pdf_id');
            });

            try {
                DB::connection('tenant')->statement(
                    'ALTER TABLE pdf_share_links ADD CONSTRAINT fk_pdf_share_links_pdf '
                    . 'FOREIGN KEY (bestellung_pdf_id) REFERENCES bestellung_pdfs(id) ON DELETE CASCADE'
                );
            } catch (\Throwable $e) {
                // controller-level handles it
            }
        }

        // ────────────────────────────────────────────────
        // Seed the 4 default system templates (idempotent)
        // ────────────────────────────────────────────────
        $this->seedSystemTemplates();
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('pdf_share_links');
        Schema::connection('tenant')->dropIfExists('bestellung_pdfs');
        Schema::connection('tenant')->dropIfExists('pdf_templates');
    }

    protected function seedSystemTemplates(): void
    {
        $existing = DB::connection('tenant')->table('pdf_templates')
            ->where('is_system', 1)->count();
        if ($existing > 0) return;

        $now = now();
        $templates = [
            [
                'name'          => 'Klassisch',
                'description'   => 'Klassisches dunkelblaues Corporate-Design — perfekt für formelle Aufträge',
                'is_system'     => 1,
                'is_default'    => 1,
                'preview_emoji' => '🏢',
                'accent_color'  => '#1a2744',
                'settings'      => json_encode([
                    'primary_color'  => '#1a2744',
                    'accent_color'   => '#3b82f6',
                    'font_family'    => "'Segoe UI', system-ui, sans-serif",
                    'font_size'      => 'medium',
                    'layout_variant' => 'classic',
                    'header_style'   => 'border-bottom-thick',
                    'table_style'    => 'striped',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'name'          => 'Modern',
                'description'   => 'Minimalistisch, türkis-akzentuiert, viel Weißraum',
                'is_system'     => 1,
                'is_default'    => 0,
                'preview_emoji' => '✨',
                'accent_color'  => '#0f766e',
                'settings'      => json_encode([
                    'primary_color'  => '#0f172a',
                    'accent_color'   => '#14b8a6',
                    'font_family'    => "'Helvetica Neue', Arial, sans-serif",
                    'font_size'      => 'medium',
                    'layout_variant' => 'minimal',
                    'header_style'   => 'underline-thin',
                    'table_style'    => 'lines-only',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'name'          => 'Elegant',
                'description'   => 'Serifenschrift in warmen Erdtönen — gehobener Look',
                'is_system'     => 1,
                'is_default'    => 0,
                'preview_emoji' => '🎩',
                'accent_color'  => '#78350f',
                'settings'      => json_encode([
                    'primary_color'  => '#78350f',
                    'accent_color'   => '#d97706',
                    'font_family'    => "Georgia, 'Times New Roman', serif",
                    'font_size'      => 'medium',
                    'layout_variant' => 'elegant',
                    'header_style'   => 'double-line',
                    'table_style'    => 'bordered',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'name'          => 'Kompakt',
                'description'   => 'Dichte Darstellung — ideal bei vielen Mitarbeitern',
                'is_system'     => 1,
                'is_default'    => 0,
                'preview_emoji' => '📊',
                'accent_color'  => '#064e3b',
                'settings'      => json_encode([
                    'primary_color'  => '#064e3b',
                    'accent_color'   => '#10b981',
                    'font_family'    => "Arial, Helvetica, sans-serif",
                    'font_size'      => 'small',
                    'layout_variant' => 'compact',
                    'header_style'   => 'flat',
                    'table_style'    => 'dense',
                ]),
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ];

        DB::connection('tenant')->table('pdf_templates')->insert($templates);
    }
};
