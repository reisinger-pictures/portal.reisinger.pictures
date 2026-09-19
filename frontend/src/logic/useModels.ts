import useSWR from 'swr';
import { apiMutate, fetcher } from '../api';
import {
    isCatalogOutdated,
    type ModelProfileAnswer,
    type PhotoVisibility,
} from './modelRegistration';

export type { ModelProfileAnswer };

export interface ManagedModelPhoto {
    id: string;
    visibility: PhotoVisibility;
    is_primary: boolean;
    original_name: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    download_url: string;
    created_at: string | null;
}

export interface ManagedModelAccessLink {
    url: string;
    expires_at: string | null;
}

export interface ManagedModel {
    id: string;
    customer_id: string;
    display_name: string | null;
    birthdate: string | null;
    age: number | null;
    gender: string | null;
    city: string | null;
    country: string | null;
    categories: string[];
    act_types: string[];
    catalog_version: string | null;
    age_proof_required: boolean;
    age_proof_uploaded_at: string | null;
    submitted_at: string | null;
    last_confirmed_at: string | null;
    lifecycle_status: string;
    answers: ModelProfileAnswer[];
    /** answer key → willingness code, e.g. `willingness_bikini: 'gerne'`. */
    willingness: Record<string, string>;
    photos: ManagedModelPhoto[];
    primary_photo_id: string | null;
    access_link: ManagedModelAccessLink | null;
}

export interface ModelFilters {
    q?: string;
    gender?: string;
    city?: string;
    country?: string;
    age_min?: string;
    age_max?: string;
    /** Multi-select, OR-linked. */
    category?: string[];
    act_type?: string;
    /**
     * Willingness filter/sort. Multiple categories are OR-linked; the single
     * `willingness_level` is a **minimum threshold** ("at least Gerne" includes
     * Gerne + Sehr gerne) applied to each selected category.
     */
    willingness_categories?: string[];
    willingness_level?: string;
    /**
     * Sorting. Empty = backend default "best match" (Lust×10 + Erfahrung, max
     * over categories, no `sort` param); `newest` = newest first; the two
     * category-bound sorts also use the max over all categories.
     */
    sort?: '' | 'newest' | 'willingness' | 'experience';
    /**
     * Lifecycle filter. Backend default (no param) = active only; `inactive`
     * and `all` are explicit.
     */
    lifecycle_status?: string;
}

export const EMPTY_MODEL_FILTERS: ModelFilters = {};

const STRING_FILTER_KEYS: Array<keyof Pick<ModelFilters, 'q' | 'gender' | 'city' | 'country' | 'age_min' | 'age_max' | 'act_type' | 'lifecycle_status'>> = [
    'q',
    'gender',
    'city',
    'country',
    'age_min',
    'age_max',
    'act_type',
    'lifecycle_status',
];

/**
 * Serialise the admin filter set into URL/query params. Empty values are
 * omitted; arrays become repeated `key[]` entries (shareable URL + cache key).
 */
export function serializeModelFilters(filters: ModelFilters): URLSearchParams {
    const params = new URLSearchParams();

    for (const key of STRING_FILTER_KEYS) {
        const value = filters[key];
        if (typeof value === 'string' && value.trim() !== '') {
            params.set(key, value.trim());
        }
    }

    for (const category of filters.category ?? []) {
        if (category.trim() !== '') params.append('category[]', category.trim());
    }

    // Threshold filters: one `willingness_<category>=<level>` per selected
    // category (backend OR-links them); no threshold = no restriction ("Egal").
    const threshold = (filters.willingness_level ?? '').trim();
    const willingnessCategories = (filters.willingness_categories ?? [])
        .map(category => category.trim())
        .filter(category => category !== '');
    if (threshold !== '') {
        for (const category of willingnessCategories) {
            params.append(`willingness_${category}`, threshold);
        }
    } else {
        // Without a level the per-category threshold params cannot carry the
        // selection, so persist the chosen categories as a meta list. The
        // backend ignores them without a level; the admin UI needs them so the
        // threshold slider stays enabled ("Egal" clears only the level).
        for (const category of willingnessCategories) {
            params.append('willingness_category[]', category);
        }
    }

    // Sorting: empty = "best match" default (no param). The backend scores the
    // category-bound sorts over the max of all categories.
    if (filters.sort === 'newest' || filters.sort === 'willingness' || filters.sort === 'experience') {
        params.set('sort', filters.sort);
    }

    return params;
}

/** Inverse of `serializeModelFilters` (unknown params — e.g. `model` — ignored). */
export function parseModelFilters(params: URLSearchParams): ModelFilters {
    const filters: ModelFilters = {};
    for (const key of STRING_FILTER_KEYS) {
        const value = params.get(key);
        if (value !== null && value.trim() !== '') filters[key] = value.trim();
    }

    const categories = params.getAll('category[]').filter(value => value.trim() !== '');
    if (categories.length > 0) filters.category = categories;

    const willingnessCategories: string[] = [];
    const pushCategory = (value: string) => {
        const category = value.trim();
        if (category !== '' && !willingnessCategories.includes(category)) willingnessCategories.push(category);
    };
    // Meta selection (no threshold yet): repeatable `willingness_category[]`.
    for (const value of [...params.getAll('willingness_category[]'), ...params.getAll('willingness_category')]) {
        pushCategory(value);
    }

    let threshold = '';
    // Alias/meta keys must not be mistaken for a `<category>` suffix.
    const ALIAS_KEYS = new Set(['willingness_category', 'willingness_category[]', 'willingness_level', 'willingness_levels']);
    for (const [key, value] of params.entries()) {
        if (!key.startsWith('willingness_') || ALIAS_KEYS.has(key)) continue;
        const category = key.slice('willingness_'.length);
        if (category !== '' && value.trim() !== '') {
            pushCategory(category);
            threshold = value.trim();
        }
    }
    if (willingnessCategories.length > 0) filters.willingness_categories = willingnessCategories;
    if (threshold !== '') filters.willingness_level = threshold;

    const sort = params.get('sort');
    if (sort === 'newest' || sort === 'willingness' || sort === 'experience') filters.sort = sort;

    return filters;
}

/**
 * Serialise the admin filter set into a stable API query string. Empty values
 * are omitted so the SWR key stays clean (and cacheable).
 */
export function buildModelsQuery(filters: ModelFilters): string {
    const query = serializeModelFilters(filters).toString();
    return query ? `?${query}` : '';
}

export function useModels(filters: ModelFilters) {
    const query = buildModelsQuery(filters);
    const { data, error, isLoading, mutate } = useSWR<ManagedModel[]>(
        `/api/management/models${query}`,
        fetcher,
    );

    return { models: data, error, isLoading, mutate };
}

export function isModelOutdated(model: Pick<ManagedModel, 'catalog_version'>): boolean {
    return isCatalogOutdated(model.catalog_version);
}

export function ageProofDownloadUrl(modelId: string): string {
    return `/api/management/models/${modelId}/age-proof`;
}

export function modelPhotoDownloadUrl(modelId: string, photoId: string): string {
    return `/api/management/models/${modelId}/photos/${photoId}`;
}

export async function setPrimaryModelPhoto(modelId: string, photoId: string): Promise<void> {
    await apiMutate(`/api/management/models/${modelId}/photos/${photoId}/primary`, 'POST', {});
}

export async function deleteModelPhoto(modelId: string, photoId: string): Promise<void> {
    await apiMutate(`/api/management/models/${modelId}/photos/${photoId}`, 'DELETE');
}

/**
 * DSGVO deletion of a model (super-admin only, `super_admin` middleware).
 * Deletes the profile, all files and memberships; the portal account is only
 * unlinked. Endpoint takes the **customer id**.
 */
export async function deleteModel(customerId: string): Promise<void> {
    await apiMutate(`/api/management/models/${encodeURIComponent(customerId)}`, 'DELETE');
}

export interface CreateModelAccessLinkResponse {
    success: boolean;
    link: string;
    expires_at: string | null;
}

export async function createModelAccessLink(customerId: string): Promise<CreateModelAccessLinkResponse> {
    return apiMutate<CreateModelAccessLinkResponse>(`/api/management/models/${customerId}/access-link`, 'POST', {});
}

export async function revokeModelAccessLink(customerId: string): Promise<void> {
    await apiMutate(`/api/management/models/${customerId}/access-link`, 'DELETE');
}
