<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Models\Retail\ProfessionalSelection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class FeaturedProductsController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    private ?bool $commissionOverrideSupported = null;
    private ?bool $selectionsTableAvailable = null;

    /**
     * GET /store/featured-products
     * Returns the professional's selected Shopify product IDs with commission overrides.
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);
        $supportsCommissionOverride = $this->supportsCommissionOverride() && $this->hasSelectionsTable();

        if (! $this->hasSelectionsTable()) {
            return $this->success([
                'selected_products' => $this->getLegacySelectedProducts($site),
                'default_commission_rate' => $defaultRate,
                'max_featured_products' => $maxFeatured,
            ]);
        }

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }

        try {
            $selections = ProfessionalSelection::where('professional_id', $professional->id)
                ->orderBy('sort_order')
                ->get($columns);
        } catch (Throwable $e) {
            Log::warning('Featured products falling back to legacy site settings (index).', [
                'professional_id' => (string) $professional->id,
                'site_id' => (string) $site->id,
                'error' => $e->getMessage(),
            ]);

            return $this->success([
                'selected_products' => $this->getLegacySelectedProducts($site),
                'default_commission_rate' => $defaultRate,
                'max_featured_products' => $maxFeatured,
            ]);
        }

        return $this->success([
            'selected_products'       => $this->toSelectionResponse($selections, $supportsCommissionOverride),
            'default_commission_rate' => $defaultRate,
            'max_featured_products'   => $maxFeatured,
        ]);
    }

    /**
     * PUT /store/featured-products
     * Replaces the full list of selected Shopify product IDs with optional commission overrides (max 10).
     * Expects: { products: [ { shopify_product_id, sort_order?, commission_override? }, ... ] }
     */
    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $supportsCommissionOverride = $this->supportsCommissionOverride() && $this->hasSelectionsTable();

        $validator = Validator::make($request->all(), [
            'products'                            => ['required', 'array', 'max:' . $maxFeatured],
            'products.*.shopify_product_id'       => ['required', 'string', 'max:255'],
            'products.*.sort_order'               => ['sometimes', 'integer', 'min:0'],
            'products.*.commission_override'      => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! $this->hasSelectionsTable()) {
            Log::error('Featured products update blocked: retail.professional_selections table is unavailable.', [
                'professional_id' => (string) $professional->id,
                'site_id' => (string) $site->id,
            ]);

            return $this->error(
                'Featured products table is unavailable. Run retail schema migrations and try again.',
                503
            );
        }

        try {
            DB::transaction(function () use ($professional, $validated, $supportsCommissionOverride) {
                // Remove all current selections and replace
                ProfessionalSelection::where('professional_id', $professional->id)->delete();

                foreach ($validated['products'] as $index => $product) {
                    $attributes = [
                        'professional_id'      => $professional->id,
                        'shopify_product_id'   => $product['shopify_product_id'],
                        'sort_order'           => $product['sort_order'] ?? $index,
                    ];
                    if ($supportsCommissionOverride) {
                        $attributes['commission_override'] = $product['commission_override'] ?? null;
                    }

                    ProfessionalSelection::create($attributes);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Featured products retail write failed (update).', [
                'professional_id' => (string) $professional->id,
                'site_id' => (string) $site->id,
                'error' => $e->getMessage(),
            ]);

            $status = 500;
            $message = 'Failed to save featured products to retail.professional_selections.';

            if ($e instanceof QueryException) {
                $sqlState = (string) $e->getCode();
                if ($sqlState === '23505') {
                    $status = 422;
                    $message = 'Duplicate featured products are not allowed.';
                } elseif ($sqlState === '23514') {
                    $status = 422;
                    $message = 'Too many featured products selected.';
                }
            }

            if (config('app.debug')) {
                $message .= ' ' . $e->getMessage();
            }

            return $this->error($message, $status);
        }

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }

        $selections = ProfessionalSelection::where('professional_id', $professional->id)
            ->orderBy('sort_order')
            ->get($columns);

        return $this->success([
            'selected_products'       => $this->toSelectionResponse($selections, $supportsCommissionOverride),
            'default_commission_rate' => $defaultRate,
            'max_featured_products'   => $maxFeatured,
        ]);
    }

    private function hasSelectionsTable(): bool
    {
        if ($this->selectionsTableAvailable !== null) {
            return $this->selectionsTableAvailable;
        }

        try {
            $result = DB::selectOne("select to_regclass('retail.professional_selections') as table_name");
            $this->selectionsTableAvailable = isset($result->table_name) && $result->table_name !== null;
        } catch (Throwable $e) {
            Log::warning('Could not verify retail.professional_selections availability.', [
                'error' => $e->getMessage(),
            ]);
            $this->selectionsTableAvailable = false;
        }

        return $this->selectionsTableAvailable;
    }

    private function supportsCommissionOverride(): bool
    {
        if ($this->commissionOverrideSupported !== null) {
            return $this->commissionOverrideSupported;
        }

        try {
            $this->commissionOverrideSupported = DB::table('information_schema.columns')
                ->where('table_schema', 'retail')
                ->where('table_name', 'professional_selections')
                ->where('column_name', 'commission_override')
                ->exists();
        } catch (Throwable $e) {
            Log::warning('Could not verify commission_override column on retail.professional_selections.', [
                'error' => $e->getMessage(),
            ]);
            // Fail-safe: if metadata lookup is blocked, behave as if column is unavailable.
            $this->commissionOverrideSupported = false;
        }

        return $this->commissionOverrideSupported;
    }

    private function toSelectionResponse(Collection $rows, bool $supportsCommissionOverride): Collection
    {
        return $rows->map(function ($row) use ($supportsCommissionOverride) {
            return [
                'id' => $row->id,
                'shopify_product_id' => $row->shopify_product_id,
                'sort_order' => $row->sort_order,
                'commission_override' => $supportsCommissionOverride ? $row->commission_override : null,
            ];
        })->values();
    }

    private function getLegacySelectedProducts($site): array
    {
        $settings = is_array($site->settings) ? $site->settings : [];
        $selectedProducts = $settings['selected_products'] ?? [];
        return is_array($selectedProducts) ? array_values($selectedProducts) : [];
    }

    private function saveLegacySelectedProducts($site, array $products): array
    {
        $normalized = [];
        foreach (array_values($products) as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $id = isset($product['shopify_product_id']) ? (string) $product['shopify_product_id'] : '';
            if ($id === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'shopify_product_id' => $id,
                'sort_order' => isset($product['sort_order']) ? (int) $product['sort_order'] : $index,
                'commission_override' => isset($product['commission_override']) ? $product['commission_override'] : null,
            ];
        }

        $settings = is_array($site->settings) ? $site->settings : [];
        $settings['selected_products'] = $normalized;
        $site->settings = $settings;
        $site->save();

        return $normalized;
    }
}
