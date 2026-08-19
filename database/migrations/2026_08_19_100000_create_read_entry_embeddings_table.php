<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| One embedding vector per reading entry, for the "ask your library" search.
|
| .NET counterpart: none. readlog-dotnet has no AI feature; this is the first
| thing in the port that the source does not have.
|
| The vector is stored as a JSON array of floats in a text column rather than a
| pgvector column, deliberately: a reader's library is at most a few hundred
| entries, brute-force cosine similarity in PHP over that is milliseconds, and a
| text column works identically on SQLite and Postgres, which keeps the
| portability the rest of the schema has. content_hash is the sha256 of the text
| that was embedded, so a changed entry is re-embedded and an unchanged one is
| not; model records what produced the vector, so a model change invalidates it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('read_entry_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('read_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('model', 100);
            $table->unsignedSmallInteger('dimensions');
            $table->string('content_hash', 64);
            $table->text('vector');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('read_entry_embeddings');
    }
};
