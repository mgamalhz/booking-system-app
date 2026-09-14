<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('telescope.storage.database.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('telescope_entries', function (Blueprint $table): void {
            $table->bigIncrements('sequence');
            $table->uuid('uuid')->unique();
            $table->uuid('batch_id')->index();
            $table->string('family_hash')->nullable()->index();
            $table->boolean('should_display_on_index')->default(true);
            $table->string('type', 20);
            $table->longText('content');
            $table->dateTime('created_at')->nullable()->index();
            $table->index(['type', 'should_display_on_index']);
        });
        $schema->create('telescope_entries_tags', function (Blueprint $table): void {
            $table->uuid('entry_uuid');
            $table->string('tag')->index();
            $table->primary(['entry_uuid', 'tag']);
            $table->foreign('entry_uuid')->references('uuid')->on('telescope_entries')->cascadeOnDelete();
        });
        $schema->create('telescope_monitoring', fn (Blueprint $table) => $table->string('tag')->primary());
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('telescope_entries_tags');
        $schema->dropIfExists('telescope_entries');
        $schema->dropIfExists('telescope_monitoring');
    }
};
