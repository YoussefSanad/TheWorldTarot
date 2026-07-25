<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * A static content page. Translatable fields are stored as jsonb keyed by locale,
 * one key per locale, so enabling a language never requires a migration.
 * See .claude/translation.md.
 */
#[Fillable(['slug', 'title', 'body'])]
class Page extends Model
{
    use HasFactory, HasTranslations, SoftDeletes;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['title', 'body'];
}
