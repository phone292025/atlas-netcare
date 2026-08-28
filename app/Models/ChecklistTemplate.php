<?php

namespace App\Models;

use Database\Factories\ChecklistTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, versioned set of checks for inspecting a lab PC.
 *
 * VERSIONED, NEVER EDITED IN PLACE once used (Q2). See ChecklistService for
 * why, and for the copy-on-write that enforces it.
 *
 * `version` and `is_active` are absent from $fillable on purpose: both are
 * managed by ChecklistService, and a form that could set `is_active` would let
 * somebody activate two templates at once.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see docs/04-open-questions.md Q2
 */
class ChecklistTemplate extends Model
{
    /** @use HasFactory<ChecklistTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function items(): HasMany
    {
        // Ordered here rather than at each call site: an inspection form that
        // rendered its questions in insertion order would ask an assistant to
        // check the network before switching the machine on.
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<Inspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /** @param  Builder<ChecklistTemplate>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The template a new inspection should use.
     *
     * Null when nothing is active, which is a real state — a fresh install
     * before anyone has built a checklist. Callers must handle it rather than
     * assuming a template exists.
     */
    public static function current(): ?self
    {
        return static::query()->active()->latest('version')->first();
    }

    /**
     * Whether this template is now history.
     *
     * A template that has been used cannot be edited or deleted — an
     * inspection's answers point at its items, and rewriting them would
     * rewrite what somebody recorded standing at a machine.
     */
    public function isLocked(): bool
    {
        return $this->inspections()->exists();
    }

    /** "Standard Lab PC Check v3", for a heading or a history line. */
    public function label(): string
    {
        return "{$this->name} v{$this->version}";
    }
}
