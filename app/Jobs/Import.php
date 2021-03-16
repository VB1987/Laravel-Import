<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Import\Entities\Attribute;
use Modules\Import\Entities\Catalog;
use Modules\Import\Entities\CatalogList;
use Modules\Import\Entities\Media;
use Modules\Import\Entities\Price;
use Modules\Import\Entities\Product;
use Modules\Import\Entities\ProductList;
use Modules\Import\Entities\Stock;
use Modules\Import\Entities\Supplier;
use Modules\Import\Entities\SupplierList;
use Modules\Import\Entities\Text;

class Import implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $attemptsLimit = 5;

    /**
     * Vars used for import
     */
    protected $data = [];
    protected $domain;
    protected $site_id = 1;
    protected $mapping;
    protected $batch = 0;

    /**
     * @var [type]
     */
    protected $importedRowsCount = 0;

    /**
     * ImportShopProducts constructor.
     * @param array $data
     * @param array $mapping
     * @param int $batch
     * @param bool $forceUpdate
     * @param bool $supplierSkuAsParent
     */
    public function __construct(array $data, array $mapping, int $batch, bool $forceUpdate = false, bool $supplierSkuAsParent = true)
    {
        $this->data = $data;
        $this->mapping = $mapping;
        $this->forceUpdate = $forceUpdate;
        $this->supplierSkuAsParent = $supplierSkuAsParent;
        $this->batch = $batch;
    }

    /**
     * @return array[]
     */
    public function handle()
    {
        DB::connection()->disableQueryLog();
        $mapping = $this->mapping;
        $results = [
            'success' => [],
            'failed' => [],
            'message' => [],
        ];
        $log = Log::channel('import');

        if (isset($mapping['product']['text.supplier-sku'])) {
            $multipleSkuCheck = array_count_values(
                array_column($this->data, $mapping['product']['text.supplier-sku'])
            );
        }

        foreach ($this->data as $key => $productData) {
            try {
                $this->importedRowsCount++;
                $status = isset($productData[$mapping['product']['status']]) ? $productData[$mapping['product']['status']] : 2;
                $productCode = isset($productData[$mapping['product']['code']]) && $this->codeValidator($productData[$mapping['product']['code']]) ? $productData[$mapping['product']['code']] : (
                isset($mapping['product']['code-alt']) && isset($productData[$mapping['product']['code-alt']]) && $this->codeValidator($productData[$mapping['product']['code-alt']]) ? $productData[$mapping['product']['code-alt']] : false
                );

                if (!$productCode) {
                    $results['failed'][] = __('ecommerce::t.Product skipped because sku is invalid at row :row', ['row' => ($this->batch + $this->importedRowsCount)]);
                    $log->error('Product import: Product skipped because sku is invalid at row ' . ($this->batch + $this->importedRowsCount));
                    continue;
                }

                $productExists = Product::where('code', $productCode)->exists();

                if ($productExists && !$this->forceUpdate) {
                    $results['failed'][] = __('ecommerce::t.Product exists :code', ['code' => $productCode]);
                    continue;
                }

                /**
                 * Create Catalogs
                 */
                if (!empty($mapping['catalog'])) {
                    $mapping = $this->createCatalog($productData, $mapping);
                }

                if (isset($mapping['product']['text.supplier-sku']) && isset($productData[$mapping['product']['text.supplier-sku']])) {
                    $productSupplierSku = $productData[$mapping['product']['text.supplier-sku']];

                    if ($this->supplierSkuAsParent) {
                        if (!$productExists && $multipleSkuCheck[$productSupplierSku] > 1) {
                            // make the first instance the variable product, after that create the seperate variations
                            $selectProduct = Product::firstOrCreate([
                                'siteid' => $this->site_id,
                                'type' => 'select',
                                'code' => $productSupplierSku,
                            ], [
                                'status' => ($status == 1 || strtolower($status) === 'yes' ? 1 : 2),
                                'label' => $productData[$mapping['product']['label']],
                                'config' => '',
                                'editor' => 'Import',
                            ]);
                        } else {
                            $selectProduct = Product::select('id')->where('code', $productSupplierSku)->first();
                        }
                    }
                }

                $variantLabel = isset($mapping['product']['label']) ? $productData[$mapping['product']['label']] : false;

                $attributes = [];
                if (!empty($mapping['attributes'])) {
                    foreach ($mapping['attributes'] as $attr => $fields) {
                        if (is_array($fields)) {
                            foreach ($fields as $field) {
                                if (isset($productData[$field])) {
                                    $explodedData = collect(explode(',', $productData[$field]));
                                    if ($explodedData->count() > 1) {
                                        foreach ($explodedData as $data) {
                                            if ($data !== '') {
                                                $attributes[] = Attribute::firstOrCreate([
                                                    'domain' => 'product',
                                                    'siteid' => $this->site_id,
                                                    'type' => array_key_exists($attr, self::TRANSLATE_ATTRIBUTE) ? self::TRANSLATE_ATTRIBUTE[$attr] : $attr,
                                                    'code' => Str::slug($data, '-'),
                                                ], [
                                                    'label' => $data,
                                                    'status' => 1,
                                                    'editor' => 'Import',
                                                ]);
                                            }
                                        }
                                    } else {
                                        $attributes[] = Attribute::firstOrCreate([
                                            'domain' => 'product',
                                            'siteid' => $this->site_id,
                                            'type' => array_key_exists($attr, self::TRANSLATE_ATTRIBUTE) ? self::TRANSLATE_ATTRIBUTE[$attr] : $attr,
                                            'code' => Str::slug($productData[$field], '-'),
                                        ], [
                                            'label' => $productData[$field],
                                            'status' => 1,
                                            'editor' => 'Import',
                                        ]);
                                    }

                                    if (isset($selectProduct) && in_array($attr, $mapping['selectable-attributes'])) {
                                        $variantLabel .= ' - ' . $productData[$field];
                                    }
                                }
                            }
                        }
                    }
                }

                if (!$productExists && isset($selectProduct)) {
                    $product = Product::firstOrCreate([
                        'siteid' => $this->site_id,
                        'type' => 'default',
                        'code' => $productCode,
                    ], [
                        'status' => ($status == 1 || strtolower($status) === 'yes' ? 1 : 2),
                        'label' => $variantLabel,
                        'config' => '',
                        'editor' => 'Import',
                    ]);

                    ProductList::firstOrCreate([
                        'parentid' => $selectProduct->id,
                        'siteid' => $this->site_id,
                        'type' => 'default',
                        'domain' => 'product',
                        'refid' => $product->id,
                        'status' => 1,
                    ], [
                        'editor' => 'Import',
                    ]);

                    $results['success'][] = __('ecommerce::t.Product updated :code', ['code' => $productCode]);
                } else {
                    $product = Product::updateOrCreate([
                        'siteid' => $this->site_id,
                        'type' => 'default',
                        'code' => $productCode,
                    ], [
                        'status' => ($status == 1 || strtolower($status) === 'yes' ? 1 : 2),
                        'label' => $productData[$mapping['product']['label']],
                        'config' => '',
                        'editor' => 'Import',
                    ]);

                    $results['success'][] = __('ecommerce::t.Product updated :code', ['code' => $productCode]);
                }

                if (isset($mapping['stock']) && isset($productData[$mapping['stock']])) {
                    Stock::updateOrCreate([
                        'productcode' => $product->code,
                        'siteid' => $this->site_id,
                        'type' => 'default',
                    ], [
                        'stocklevel' => $productData[$mapping['stock']] !== '0' ? $productData[$mapping['stock']] : null,
                        'editor' => 'Import',
                    ]);
                }

                /**
                 * Get all current prices from the current product in the database to do a match on them to update existing prices and create new ones
                 */
                $priceListFound = ProductList::where([
                    'parentid' => $product->id,
                    'siteid' => $this->site_id,
                    'type' => 'default',
                    'domain' => 'price',
                ])->select('refid')->get();
                $priceCollection = Price::whereIn('id', $priceListFound->pluck('refid')->toArray())->doesntHave('priceList')->get();

                /**
                 * Add price to product
                 * Always create a new price in case of deleting another product with the same price
                 */
                if (isset($mapping['product']['price.default']) && isset($productData[$mapping['product']['price.default']])) {
                    $price = $this->formatPrice($productData[$mapping['product']['price.default']]);

                    if (!empty($priceCollection) && $priceCollection->where('type', 'default')->count()) {
                        Price::whereIn(
                            'id', $priceCollection->where('type', 'default')->pluck('id')->toArray()
                        )->update([
                            'label' => 'EUR ' . $price,
                            'value' => $price,
                            'editor' => 'Import',
                        ]);
                    } else {
                        $defaultPrice = Price::create([
                            'siteid' => $this->site_id,
                            'type' => 'default',
                            'domain' => 'product',
                            'label' => 'EUR ' . $price,
                            'currencyid' => 'EUR',
                            'quantity' => 1,
                            'value' => $price,
                            'costs' => '0.00',
                            'rebate' => isset($mapping['product']['price.discount-price']) && isset($productData[$mapping['product']['price.discount-price']]) ? $this->formatPrice($productData[$mapping['product']['price.discount-price']]) : '0.00',
                            'taxrate' => (!empty($mapping['product']['price.default.taxrate']) && !empty($productData[$mapping['product']['price.default.taxrate']]) ? $productData[$mapping['product']['price.default.taxrate']] : '21.00'),
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);

                        ProductList::firstOrCreate([
                            'parentid' => $product->id,
                            'siteid' => $this->site_id,
                            'type' => 'default',
                            'domain' => 'price',
                            'refid' => $defaultPrice->id,
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);
                    }
                }

                /**
                 * Add cog price to product
                 * Always create a new price in case of deleting another product with the same price
                 */
                if (isset($mapping['product']['price.cog']) && isset($productData[$mapping['product']['price.cog']])) {
                    $price = $this->formatPrice($productData[$mapping['product']['price.cog']]);

                    if (!empty($priceCollection) && $priceCollection->where('type', 'cog')->count()) {
                        Price::whereIn(
                            'id', $priceCollection->where('type', 'cog')->pluck('id')->toArray()
                        )->update([
                            'label' => 'EUR ' . $price,
                            'value' => $price,
                            'editor' => 'Import',
                        ]);
                    } else {
                        $cogPrice = Price::create([
                            'siteid' => $this->site_id,
                            'type' => 'cog',
                            'domain' => 'product',
                            'label' => 'EUR ' . $price,
                            'currencyid' => 'EUR',
                            'quantity' => 1,
                            'value' => $price,
                            'costs' => '0.00',
                            'rebate' => isset($mapping['product']['price.discount-price']) && isset($productData[$mapping['product']['price.discount-price']]) ? $this->formatPrice($productData[$mapping['product']['price.discount-price']]) : '0.00',
                            'taxrate' => '0.00',
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);

                        ProductList::firstOrCreate([
                            'parentid' => $product->id,
                            'siteid' => $this->site_id,
                            'type' => 'default',
                            'domain' => 'price',
                            'refid' => $cogPrice->id,
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);
                    }
                }

                /**
                 * Add advice price to product
                 */
                if (isset($mapping['product']['price.advice-price']) && isset($productData[$mapping['product']['price.advice-price']])) {
                    $price = $this->formatPrice($productData[$mapping['product']['price.advice-price']]);

                    if (!empty($priceCollection) && $priceCollection->where('type', 'advice-price')->count()) {
                        Price::whereIn(
                            'id', $priceCollection->where('type', 'advice-price')->pluck('id')->toArray()
                        )->update([
                            'label' => 'EUR ' . $price,
                            'value' => $price,
                            'editor' => 'Import',
                        ]);
                    } else {
                        $advicePrice = Price::create([
                            'siteid' => $this->site_id,
                            'type' => 'advice-price',
                            'domain' => 'product',
                            'label' => 'EUR ' . $price,
                            'currencyid' => 'EUR',
                            'quantity' => 1,
                            'value' => $price,
                            'costs' => '0.00',
                            'rebate' => isset($mapping['product']['price.discount-price']) && isset($productData[$mapping['product']['price.discount-price']]) ? $this->formatPrice($productData[$mapping['product']['price.discount-price']]) : '0.00',
                            'taxrate' => '0.00',
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);

                        ProductList::firstOrCreate([
                            'parentid' => $product->id,
                            'siteid' => $this->site_id,
                            'type' => 'default',
                            'domain' => 'price',
                            'refid' => $advicePrice->id,
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);
                    }
                }

                /**
                 * Product texts
                 */
                $queryProduct = $selectProduct ?? $product;

                // Name
                if (isset($mapping['product']['text.name']) && isset($productData[$mapping['product']['text.name']])) {
                    $textName = Text::where('type', 'name')->whereHas('productlist', function ($query) use ($queryProduct) {
                        $query->where('parentid', '=', $queryProduct->id);
                    })->first();

                    if (!$textName) {
                        $this->createText('name', $productData[$mapping['product']['text.name']], $queryProduct);
                    } else {
                        $textName->update(['content' => $productData[$mapping['product']['text.name']]]);
                    }
                }

                // Short
                if (isset($mapping['product']['text.short']) && isset($productData[$mapping['product']['text.short']])) {
                    $textShort = Text::where('type', 'short')->whereHas('productlist', function ($query) use ($queryProduct) {
                        $query->where('parentid', '=', $queryProduct->id);
                    })->first();

                    if (!$textShort) {
                        $this->createText('short', $productData[$mapping['product']['text.short']], $queryProduct);
                    } else {
                        $textShort->update(['content' => $productData[$mapping['product']['text.short']]]);
                    }
                }

                // Long
                if (isset($mapping['product']['text.long']) && isset($productData[$mapping['product']['text.long']])) {
                    $textLong = Text::where('type', 'long')->whereHas('productlist', function ($query) use ($queryProduct) {
                        $query->where('parentid', '=', $queryProduct->id);
                    })->first();

                    if (!$textLong) {
                        $this->createText('long', $productData[$mapping['product']['text.long']], $queryProduct);
                    } else {
                        $textLong->update(['content' => $productData[$mapping['product']['text.long']]]);
                    }
                }

                // Meta-title
                if (isset($mapping['product']['text.meta-title']) && isset($productData[$mapping['product']['text.meta-title']])) {
                    $metaTitle = Text::where('type', 'meta-title')->whereHas('productlist', function ($query) use ($queryProduct) {
                        $query->where('parentid', '=', $queryProduct->id);
                    })->first();

                    if (!$metaTitle) {
                        $this->createText('meta-title', $productData[$mapping['product']['text.meta-title']], $queryProduct);
                    } else {
                        $metaTitle->update(['content' => $productData[$mapping['product']['text.meta-title']]]);
                    }
                }

                // Meta-description
                if (isset($mapping['product']['text.meta-description']) && isset($productData[$mapping['product']['text.meta-description']])) {
                    $metaDescription = Text::where('type', 'meta-description')->whereHas('productlist', function ($query) use ($queryProduct) {
                        $query->where('parentid', '=', $queryProduct->id);
                    })->first();

                    if (!$metaDescription) {
                        $this->createText('meta-description', $productData[$mapping['product']['text.meta-description']], $queryProduct);
                    } else {
                        $metaDescription->update(['content' => $productData[$mapping['product']['text.meta-description']]]);
                    }
                }

                // Supplier-sku
                if (isset($productSupplierSku)) {
                    if ($this->supplierSkuAsParent) {
                        $textSupplierSku = Text::where('type', 'supplier-sku')->whereHas('productlist', function ($query) use ($queryProduct) {
                            $query->where('parentid', $queryProduct->id);
                        })->first();

                        if (!$textSupplierSku) {
                            $this->createText('supplier-sku', $productSupplierSku, $queryProduct);
                        }
                    } else {
                        $productList = ProductList::updateOrCreate([
                            'parentid' => $product->id,
                            'siteid' => $this->site_id,
                            'type' => 'default',
                            'domain' => 'text',
                            'status' => 1,
                        ], [
                            'editor' => 'Import',
                        ]);

                        $text = Text::where('id', $productList->refid)->where('type', 'supplier-sku')->first();

                        if ($text) {
                            $text->update(['content' => $productSupplierSku]);
                            $productList->update(['refid' => $text->id]);
                        } else {
                            $text = Text::create([
                                'id' => $productList->refid,
                                'siteid' => $this->site_id,
                                'type' => 'supplier-sku',
                                'langid' => 'nl',
                                'domain' => 'product',
                                'label' => '',
                                'status' => 1,
                                'content' => $productSupplierSku,
                                'editor' => 'Import',
                            ]);

                            $productList->update(['refid' => $text->id]);
                        }
                    }
                }

                /**
                 * Product supplier
                 */
                if (isset($mapping['supplier']['name']) && isset($productData[$mapping['supplier']['name']])) {
                    $supplier = Supplier::firstOrCreate([
                        'siteid' => $this->site_id,
                        'code' => Str::slug($productData[$mapping['supplier']['name']], '-'),
                    ], [
                        'label' => $productData[$mapping['supplier']['name']],
                        'status' => 1,
                        'editor' => 'Import',
                    ]);

                    if (isset($selectProduct)) {
                        SupplierList::firstOrCreate([
                            'parentid' => $supplier->id,
                            'siteid' => $this->site_id,
                            'type' => 'default',
                            'domain' => 'product',
                            'refid' => $selectProduct->id,
                            'status' => 1,

                        ], [
                            'editor' => 'Import',
                        ]);
                    }

                    SupplierList::firstOrCreate([
                        'parentid' => $supplier->id,
                        'siteid' => $this->site_id,
                        'type' => 'default',
                        'domain' => 'product',
                        'refid' => $product->id,
                        'status' => 1,
                    ], [
                        'editor' => 'Import',
                    ]);
                }

                /**
                 * Product attributes
                 */
                if (!empty($attributes)) {
                    foreach ($attributes as $attribute) {
                        if (in_array(array_search($attribute->type, self::TRANSLATE_ATTRIBUTE), $mapping['selectable-attributes'])) {
                            ProductList::firstOrCreate([
                                'parentid' => $product->id,
                                'siteid' => $this->site_id,
                                'type' => 'variant',
                                'domain' => 'attribute',
                                'refid' => $attribute->id,
                                'status' => 1,
                            ], [
                                'editor' => 'Import',
                            ]);
                        } else {
                            ProductList::firstOrCreate([
                                'parentid' => $queryProduct->id,
                                'siteid' => $this->site_id,
                                'type' => 'default',
                                'domain' => 'attribute',
                                'refid' => $attribute->id,
                                'status' => 1,

                            ], [
                                'editor' => 'Import',
                            ]);
                        }
                    }
                }

                /**
                 * Media
                 */
                if (isset($mapping['media'])) {
                    foreach ($mapping['media'] as $key => $fieldName) {
                        if (isset($productData[$fieldName])) {
                            $fileName = $productData[$fieldName];

                            $media = Media::firstOrCreate([
                                'type' => 'default',
                                'domain' => 'product',
                                'siteid' => $this->site_id,
                                'label' => $fileName,
                            ], [
                                'langid' => null,
                                'link' => 'files/' . $fileName,
                                'preview' => 'preview/' . $fileName,
                                'mimetype' => \mime_type_by_file_extension($fileName),
                                'status' => 1,
                                'editor' => 'Import',
                                'order' => $key,
                            ]);

                            ProductList::firstOrCreate([
                                'parentid' => $queryProduct->id,
                                'siteid' => $this->site_id,
                                'type' => 'default',
                                'domain' => 'media',
                                'refid' => $media->id,
                                'status' => 1,
                            ], [
                                'editor' => 'Import',
                            ]);
                        }
                    }
                }

                /**
                 * Categories / Catalogs
                 */
                if (isset($mapping['catalogCodes']) && !empty($mapping['catalogCodes'])) {
                    $catalogCollection = Catalog::select(['id', 'code'])->where('siteid', $this->site_id)->get()->keyBy('code');

                    foreach ($mapping['catalogCodes'] as $catField) {
                        if (isset($catalogCollection[$catField])) {
                            CatalogList::firstOrCreate([
                                'parentid' => $catalogCollection[$catField]->id,
                                'siteid' => $this->site_id,
                                'type' => 'default',
                                'domain' => 'product',
                                'refid' => $queryProduct->id,
                                'status' => 1,
                            ], [
                                'editor' => 'Import',
                            ]);
                        }
                    }
                }

                /**
                 * Tags
                 */
                if (isset($mapping['tags'])) {
                    $tags = [];
                    foreach ($mapping['tags'] as $fieldName) {
                        if (isset($productData[$fieldName])) {
                            $tags[] = $productData[$fieldName];
                        }
                    }

                    if (isset($selectProduct)) {
                        $selectProduct->syncTagsWithType($tags, 'public');
                    }

                    $product->syncTagsWithType($tags, 'public');
                }

                /**
                 * Private tags
                 */
                if (isset($mapping['private_tag'])) {
                    $privateTags = [];
                    foreach ($mapping['private_tag'] as $fieldName) {
                        $privateTags[] = $productData[$fieldName];
                    }

                    if (isset($selectProduct)) {
                        $selectProduct->syncTagsWithType($privateTags, 'public');
                    }

                    $product->syncTagsWithType($privateTags, 'public');
                }

                /**
                 * Product image
                 */
                if (isset($mapping['product']['image']) && isset($productData[$mapping['product']['image']])) {
                    $url = $productData[$mapping['product']['image']];
                    $info = pathinfo($url);
                    $contents = file_get_contents($url);
                    $file = 'files/' . $info['basename'];
                    Storage::disk('s3')->put($file, $contents, 'public');

                    $media = Media::firstOrCreate([
                        'type' => 'default',
                        'domain' => 'product',
                        'siteid' => $this->site_id,
                        'label' => $info['basename'],
                    ], [
                        'langid' => null,
                        'link' => $file,
                        'preview' => 'preview/' . $info['basename'],
                        'mimetype' => \mime_type_by_file_extension($file),
                        'status' => 1,
                        'editor' => 'Import',
                    ]);

                    ProductList::firstOrCreate([
                        'parentid' => $queryProduct->id,
                        'siteid' => $this->site_id,
                        'type' => 'default',
                        'domain' => 'media',
                        'refid' => $media->id,
                        'status' => 1,
                    ], [
                        'editor' => 'Import',
                    ]);
                }
            } catch (Exception $e) {
                $log->error('Product import Exception: ', [$e->getMessage(), $e->getLine()]);
                $results['message'][] = 'ImportShopProduct Exception: ' . $e->getMessage() . ' on line ' . $e->getLine() . '. Row ' . ($this->batch + $this->importedRowsCount) . ' of Excel file';
            }
        }

        $results['importedRowsCount'] = $this->importedRowsCount;

        return $results;
    }

    /**
     * @param array $productData
     * @param array $mapping
     * @return array
     */
    public function createCatalog(array $productData, array $mapping)
    {
        try {
            $mainCategories = [];
            $mapping['catalogCodes'] = [];
            $catalogCollection = Catalog::select(['id', 'code', 'label', 'target', 'nleft', 'nright'])->where('siteid', $this->site_id)->get()->keyBy('code');
            foreach ($mapping['catalog'] as $fieldName) {
                if (!empty($productData[$fieldName])) {
                    $label = Str::ucfirst(preg_replace([
                        '/-/',
                        '/Dameskleding/',
                        '/Herenkleding/',
                    ], [
                        ' ',
                        'Dames',
                        'Heren',
                    ], $productData[$fieldName]));
                    $slug = Str::slug(substr($label, 0, 32));
                    $target = Str::slug(substr($label, 0, 255));
                    $mapping['catalogCodes'][] = $slug;

                    if (!isset($catalogCollection[$slug])) {
                        $left = $catalogCollection->max('nright');
                        $right = $left + 1;
                        $newCat = Catalog::create([
                            'parentid' => 1,
                            'siteid' => $this->site_id,
                            'level' => 1,
                            'code' => $slug,
                            'label' => $label,
                            'config' => '[]',
                            'nleft' => $left,
                            'nright' => $right,
                            'target' => $target,
                            'editor' => 'import',
                        ]);

                        $catalogCollection->put($slug, [
                            'id' => $newCat->id,
                            'code' => $slug,
                            'label' => $label,
                            'nright' => $right,
                        ]);

                        $mainCategories[] = ['id' => $newCat->id, 'slug' => $slug];
                    } else {
                        $mainCategories[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                    }
                }
            }

            // sub1
            if (isset($mapping['subcategory1']) && !empty($mainCategories)) {
                $subcategories1 = [];
                $subcategory1Iterator = 0;

                foreach ($mapping['subcategory1'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $mainCategories[$subcategory1Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 2,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories1[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories1[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory1Iterator++;
                }
            }

            // sub2
            if (isset($mapping['subcategory2']) && !empty($subcategories1)) {
                $subcategories2 = [];
                $subcategory2Iterator = 0;

                foreach ($mapping['subcategory2'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories1[$subcategory2Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 3,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories2[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories2[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory2Iterator++;
                }
            }

            // sub3
            if (isset($mapping['subcategory3']) && !empty($subcategories2)) {
                $subcategories3 = [];
                $subcategory3Iterator = 0;

                foreach ($mapping['subcategory3'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories2[$subcategory3Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 4,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories3[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories3[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory3Iterator++;
                }
            }

            // sub4
            if (isset($mapping['subcategory4']) && !empty($subcategories3)) {
                $subcategories4 = [];
                $subcategory4Iterator = 0;

                foreach ($mapping['subcategory4'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories3[$subcategory4Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 5,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories4[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories4[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory4Iterator++;
                }
            }

            // sub5
            if (isset($mapping['subcategory5']) && !empty($subcategories4)) {
                $subcategories5 = [];
                $subcategory5Iterator = 0;

                foreach ($mapping['subcategory5'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories4[$subcategory5Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 6,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories5[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories5[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory5Iterator++;
                }
            }

            // sub6
            if (isset($mapping['subcategory6']) && !empty($subcategories5)) {
                $subcategories6 = [];
                $subcategory6Iterator = 0;

                foreach ($mapping['subcategory6'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories5[$subcategory6Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 7,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories6[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories6[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory6Iterator++;
                }
            }

            // sub7
            if (isset($mapping['subcategory7']) && !empty($subcategories6)) {
                $subcategories7 = [];
                $subcategory7Iterator = 0;

                foreach ($mapping['subcategory7'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories6[$subcategory7Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 8,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories7[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories7[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory7Iterator++;
                }
            }

            // sub8
            if (isset($mapping['subcategory8']) && !empty($subcategories7)) {
                $subcategories8 = [];
                $subcategory8Iterator = 0;

                foreach ($mapping['subcategory8'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories7[$subcategory8Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 9,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories8[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories8[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory8Iterator++;
                }
            }

            // sub9
            if (isset($mapping['subcategory9']) && !empty($subcategories8)) {
                $subcategories9 = [];
                $subcategory9Iterator = 0;

                foreach ($mapping['subcategory9'] as $fieldName) {
                    if (isset($productData[$fieldName])) {
                        $label = Str::ucfirst(preg_replace([
                            '/-/',
                        ], [
                            ' ',
                        ], $productData[$fieldName]));
                        $slug = Str::slug(substr($label, 0, 32));
                        $target = '/' . Str::slug(substr($label, 0, 255));
                        $mapping['catalogCodes'][] = $slug;

                        if (!isset($catalogCollection[$slug])) {
                            $left = $catalogCollection->max('nright');
                            $right = $left + 1;
                            $newCat = Catalog::create([
                                'parentid' => $subcategories8[$subcategory9Iterator]['id'],
                                'siteid' => $this->site_id,
                                'level' => 10,
                                'code' => $slug,
                                'label' => $label,
                                'config' => '[]',
                                'nleft' => $left,
                                'nright' => $right,
                                'target' => $target,
                                'editor' => 'import',
                            ]);

                            $catalogCollection->put($slug, [
                                'id' => $newCat->id,
                                'code' => $slug,
                                'label' => $label,
                                'nright' => $right,
                            ]);

                            $subcategories9[] = ['id' => $newCat->id, 'slug' => $slug];
                        } else {
                            $subcategories9[] = ['id' => $catalogCollection[$slug]['id'], 'slug' => $slug];
                        }
                    }
                    $subcategory9Iterator++;
                }
            }

            if (isset($right)) {
                $catalogArtikelen = Catalog::where('code', 'artikelen')->first();
                $catalogArtikelen->nright = $catalogCollection->max('nright') + 1;
                $catalogArtikelen->save();
            }
        } catch (Exception $e) {
            $results['message'][] = 'ImportShopProduct create catalog Exception: ' . $e->getMessage() . ' on line: ' . $e->getLine();
        }

        return $mapping;
    }

    /**
     * @param string $type
     * @param string $content
     * @param Product $product
     * @return Text
     */
    public function createText(string $type, string $content, Product $product)
    {
        $text = Text::create([
            'siteid' => $this->site_id,
            'type' => $type,
            'langid' => 'nl',
            'domain' => 'product',
            'label' => '',
            'content' => $content,
            'status' => 1,
            'editor' => 'Import',
        ]);

        ProductList::create([
            'parentid' => $product->id,
            'siteid' => $this->site_id,
            'type' => 'default',
            'domain' => 'text',
            'refid' => $text->id,
            'status' => 1,
            'editor' => 'Import',
        ]);

        return $text;
    }

    /**
     * @param string $price
     * @return string
     */
    public function formatPrice(string $price)
    {
        $price = preg_replace(['/€/', '/,/', '/[a-zA-Z]{1,}/'], ['', '.', ''], $price);

        $priceArray = explode('.', $price);
        if (count($priceArray) > 2) {
            $last = end($priceArray);
            $price = '';
            for ($i = 0; count($priceArray) - 1 > $i; $i++) {
                $price .= $priceArray[$i];
            }
            $price .= '.' . $last;
        }

        $price = trim($price);
        $price = number_format($price, '2', '.', '');

        return $price;
    }
}
