<?php

namespace App\Models;

use App\Enums\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        'brand'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_free_download' => 'boolean',
        'is_editorial_only' => 'boolean',
        'is_hidden' => 'boolean',
        'restricted_photographers' => 'boolean',
        'brand' => \App\Casts\AsBrand::class,
    ];

    protected static function booted()
    {
        // R-03: Zyklus-Schutz beim Speichern. Eine parent_id, die einen Zyklus bilden würde
        // (Selbstreferenz A→A oder Kette A→…→A), wird abgelehnt. Der defensive Runtime-Schutz
        // in walkParentChain() fängt zusätzlich bereits vorhandene fehlerhafte Daten ab.
        static::saving(function (GalleryGroup $group) {
            $parentId = $group->parent_id;

            // Selbstreferenz.
            if ($parentId !== null && $parentId === $group->id) {
                throw new \InvalidArgumentException(
                    'GalleryGroup#parent_id darf nicht auf sich selbst verweisen (Zyklus).'
                );
            }

            // Zyklus über die bestehende Eltern-Kette des Ziel-Parents aufbauen.
            if ($parentId !== null) {
                $visited = [$group->id => true];
                $cursor = GalleryGroup::find($parentId);

                while ($cursor !== null) {
                    if (isset($visited[$cursor->id])) {
                        throw new \InvalidArgumentException(
                            'GalleryGroup#parent_id würde einen Zyklus erzeugen.'
                        );
                    }
                    $visited[$cursor->id] = true;

                    // Wurzel erreicht.
                    if ($cursor->parent_id === null) {
                        break;
                    }
                    $cursor = GalleryGroup::find($cursor->parent_id);
                }
            }
        });

        static::saved(function (GalleryGroup $group) {
            \Illuminate\Support\Facades\DB::afterCommit(function() use ($group) {
                app(\App\Services\GalleryTreeService::class)->clearCache();
            });

            if ($group->wasChanged('brand') && $group->brand !== null) {
                $groupIds = DB::select("
                    WITH RECURSIVE descendants AS (
                        SELECT id FROM gallery_groups WHERE parent_id = ?
                        UNION ALL
                        SELECT g.id FROM gallery_groups g
                        INNER JOIN descendants d ON g.parent_id = d.id
                    )
                    SELECT id FROM descendants
                ", [$group->id]);

                $groupIds = array_column($groupIds, 'id');

                if (!empty($groupIds)) {
                    GalleryGroup::whereIn('id', $groupIds)
                        ->where(function ($q) use ($group) {
                        $q->where('brand', '!=', $group->brand)
                          ->orWhereNull('brand');
                        })
                        ->update(['brand' => $group->brand]);

                    Gallery::whereIn('gallery_group_id', $groupIds)
                        ->where(function ($q) use ($group) {
                            $q->where('brand', '!=', $group->brand)
                              ->orWhereNull('brand');
                        })
                        ->update(['brand' => $group->brand]);
                }

                Gallery::where('gallery_group_id', $group->id)
                    ->where(function ($q) use ($group) {
                        $q->where('brand', '!=', $group->brand)
                          ->orWhereNull('brand');
                    })
                    ->update(['brand' => $group->brand]);
            }
        });
        static::deleted(function () {
            \Illuminate\Support\Facades\DB::afterCommit(function() {
                app(\App\Services\GalleryTreeService::class)->clearCache();
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

    public function children()
    {
        return $this->hasMany(GalleryGroup::class, 'parent_id')->with(['children', 'galleries', 'orgs']);
    }

    public function galleries()
    {
        return $this->hasMany(Gallery::class);
    }

    public function orgs()
    {
        return $this->belongsToMany(Org::class);
    }
}
