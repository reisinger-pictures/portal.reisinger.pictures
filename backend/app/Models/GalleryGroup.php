<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Services\GalleryTreeService;
use App\Support\GalleryGroupSubtree;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GalleryGroup extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'is_public',
        'is_free_download',
        'is_editorial_only',
        'is_hidden',
        'restricted_photographers',
        'brand',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_free_download' => 'boolean',
        'is_editorial_only' => 'boolean',
        'is_hidden' => 'boolean',
        'restricted_photographers' => 'boolean',
        'brand' => AsBrand::class,
    ];

    protected static function booted()
    {
        // R-03: Zyklus-Schutz beim Speichern. Eine parent_id, die einen Zyklus bilden würde
        // (Selbstreferenz A→A oder Kette A→…→A), wird abgelehnt. Der defensive Runtime-Schutz
        // in walkParentChain() fängt zusätzlich bereits vorhandene fehlerhafte Daten ab.
        static::saving(function (GalleryGroup $group) {
            $parentId = $group->parent_id;

            if ($parentId === null) {
                return;
            }

            // Selbstreferenz.
            if ($parentId === $group->id) {
                throw new \InvalidArgumentException(
                    'GalleryGroup#parent_id darf nicht auf sich selbst verweisen (Zyklus).'
                );
            }

            // Zyklus über die bestehende Eltern-Kette des Ziel-Parents. Eine
            // einzelne, selbstterminierende Abfrage statt eines Lookups pro
            // Ancestor-Level (Query-Budget unabhängig von der Tiefe).
            if (GalleryGroupSubtree::parentChainContains($parentId, self::stringKey($group->id))) {
                throw new \InvalidArgumentException(
                    'GalleryGroup#parent_id würde einen Zyklus erzeugen.'
                );
            }
        });

        static::saved(function (GalleryGroup $group) {
            DB::afterCommit(function () {
                app(GalleryTreeService::class)->clearCache();
            });

            if ($group->wasChanged('brand') && $group->brand !== null) {
                // Bounded, cycle-safe descendant walk (depth/node budgets,
                // chunked `whereIn`). The previous recursive CTE used
                // `UNION ALL`, which never terminates on corrupt A→B→A data.
                $descendantIds = GalleryGroupSubtree::descendantIds([$group->id]);
                $brand = $group->brand;

                foreach (array_chunk($descendantIds, GalleryGroupSubtree::PARENT_ID_CHUNK) as $chunk) {
                    GalleryGroup::whereIn('id', $chunk)
                        ->where(function ($q) use ($brand) {
                            $q->where('brand', '!=', $brand)
                                ->orWhereNull('brand');
                        })
                        ->update(['brand' => $brand]);

                    Gallery::whereIn('gallery_group_id', $chunk)
                        ->where(function ($q) use ($brand) {
                            $q->where('brand', '!=', $brand)
                                ->orWhereNull('brand');
                        })
                        ->update(['brand' => $brand]);
                }

                Gallery::where('gallery_group_id', $group->id)
                    ->where(function ($q) use ($brand) {
                        $q->where('brand', '!=', $brand)
                            ->orWhereNull('brand');
                    })
                    ->update(['brand' => $brand]);
            }
        });
        static::deleted(function () {
            DB::afterCommit(function () {
                app(GalleryTreeService::class)->clearCache();
            });
        });
    }

    protected $appends = ['effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'effective_restricted_photographers'];

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        // R-03: ||-Kaskade, iterativ mit Zyklus-Schutz (walkParentChain). Eigener Wert true
        // gewinnt, sonst Parent-Kette durchlaufen, bis ein true gefunden wird oder Wurzel/Zyklus.
        foreach ($this->walkParentChain() as $node) {
            if ((bool) $node->is_editorial_only) {
                return true;
            }
        }

        return false;
    }

    public function getEffectiveIsFreeDownloadAttribute(): bool
    {
        foreach ($this->walkParentChain() as $node) {
            if ((bool) $node->is_free_download) {
                return true;
            }
        }

        return false;
    }

    public function getEffectiveRestrictedPhotographersAttribute(): bool
    {
        // Null = erben; ein expliziter Wert (auch false) bricht die Kaskade.
        foreach ($this->walkParentChain() as $node) {
            if ($node->restricted_photographers !== null) {
                return (bool) $node->restricted_photographers;
            }
        }

        return false;
    }

    public function getEffectiveIsHiddenAttribute(): bool
    {
        foreach ($this->walkParentChain() as $node) {
            if ((bool) $node->is_hidden) {
                return true;
            }
        }

        return false;
    }

    /**
     * Iterativer Aufstieg durch die parent-Kette inkl. des aktuellen Knotens.
     * Zyklus-Schutz (R-03): ein Visited-Set aus IDs verhindert Endlosrekursion bei zirkulärer
     * oder selbstreferenzieller parent_id. Defensiv — schützt auch vor bereits vorhandenen
     * fehlerhaften Daten in der DB.
     *
     * @return \Generator<int, self>
     */
    private function walkParentChain(): \Generator
    {
        $visited = [];
        $node = $this;

        while ($node !== null) {
            if (isset($visited[$node->id])) {
                // Zyklus erkannt: Abbruch, kein Stack-Overflow.
                break;
            }
            $visited[$node->id] = true;

            yield $node;

            $node = $node->parent;
        }
    }

    public function parent()
    {
        return $this->belongsTo(GalleryGroup::class, 'parent_id');
    }

    /**
     * Direct children only.
     *
     * This relation intentionally carries no nested eager loads: a
     * self-referential `with(['children'])` re-applies itself on every level
     * and therefore walks the full hierarchy — unbounded in depth, one query
     * per node, and an infinite loop on corrupt cyclic data. Use
     * {@see GalleryGroupSubtree::loadSubtree()} /
     * {@see GalleryGroupSubtree::loadForest()} for a bounded, cycle-safe
     * subtree.
     */
    public function children()
    {
        return $this->hasMany(GalleryGroup::class, 'parent_id');
    }

    public function galleries()
    {
        return $this->hasMany(Gallery::class);
    }

    public function orgs()
    {
        return $this->belongsToMany(Org::class);
    }

    private static function stringKey(mixed $key): ?string
    {
        return is_string($key) || is_int($key) ? (string) $key : null;
    }
}
