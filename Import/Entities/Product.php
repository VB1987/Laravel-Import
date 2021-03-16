<?php

namespace Modules\Import\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'product';

    protected $fillable = [
        'siteid',
        'type',
        'code',
        'label',
        'config',
        'status',
        'target',
        'editor',
        'sales_category_id',
        'stock_revenue_group_id',
        'returnable',
        'keep_disabled',
        'deleted_at',
    ];

    protected $appends = [
        'single_category',
        'default_price',
        'default_price_excl_vat',
        'default_price_tax_rate',
        'cog_price',
        'advice_price',
    ];

    /**
     * Product constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    /**
     * @param int $textId
     */
    public function addText(int $textId)
    {
        $product_list = new ProductList;
        $product_list->parentid = $this->id;
        $product_list->siteid = Session::get('webshop_selected') != null ? Session::get('webshop_selected')[0]->id : '1';
        $product_list->type = "default";
        $product_list->domain = "text";
        $product_list->refid = $textId;
        $product_list->status = 1;
        $product_list->editor = Auth::user()->name;
        $product_list->save();
    }

    /**
     * @param string $name
     * @param string $lang
     */
    public function setName(string $name, string $lang)
    {
        $foundProductName = false;

        //Search for the name text of the product when found set it
        foreach ($this->text as $text) {
            if ($text->type == 'name' && $text->langid == $lang) {
                $text->label = $name;
                $text->content = $name;
                $text->save();
                $foundProductName = true;
                break;
            }
        }

        //Text name not found , time to create the text
        if (!$foundProductName) {

            //First create the text
            $text = new Text;
            $text->siteid = Session::get('webshop_selected') != null ? Session::get('webshop_selected')[0]->id : '1';
            $text->type = "name";
            $text->langid = $lang;
            $text->domain = "product";
            $text->label = $name;
            $text->content = $name;
            $text->status = 1;
            $text->editor = Auth::user()->name;
            $text->save();

            //And now add the text to the product
            $this->addText($text->id);
        }

        //Set own label
        $this->label = $name;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function getList()
    {
        return $this->hasMany(ProductList::class, 'parentid', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function catalogList()
    {
        return $this->hasMany(CatalogList::class, 'refid', 'id')->where('domain', 'product');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function categories()
    {
        return $this->belongsToMany(
            Catalog::class,
            'mshop_catalog_list',
            'refid',
            'parentid'
        )->where('mshop_catalog_list.domain', 'product');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function media()
    {
        return $this->belongsToMany(
            Media::class,
            'mshop_product_list',
            'parentid',
            'refid'
        )->withPivot('type')->where('mshop_product_list.domain', 'media')->where('mshop_product_list.status', 1);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function text()
    {
        return $this->belongsToMany(
            Text::class,
            'mshop_product_list',
            'parentid',
            'refid'
        )->where('mshop_product_list.domain', 'text')->where('mshop_product_list.status', 1);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function supplier()
    {
        return $this->belongsToMany(
            Supplier::class,
            'mshop_supplier_list',
            'refid',
            'parentid'
        )->withPivot('id')->where('domain', 'product')->where('mshop_supplier_list.type', 'default');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function supplierList()
    {
        return $this->hasMany(SupplierList::class, 'refid', 'id')->where('domain', 'product');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function variations()
    {
        return $this->belongsToMany(
            \Modules\Ecommerce\Http\Product::class,
            'mshop_product_list',
            'parentid',
            'refid'
        )->where('domain', 'product')->where('mshop_product_list.type', 'default');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function attributes()
    {
        return $this->belongsToMany(
            Attribute::class,
            'mshop_product_list',
            'parentid',
            'refid'
        )->withPivot('id')->where('mshop_product_list.domain', 'attribute');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function prices()
    {
        return $this->belongsToMany(
            Price::class,
            'mshop_product_list',
            'parentid',
            'refid'
        )->where('mshop_product_list.domain', 'price')
            ->where('mshop_product_list.type', 'default');
    }

    /**
     * @return null
     */
    function getDefaultPriceAttribute()
    {
        if (!empty($this->prices)) {
            foreach ($this->prices as $price) {
                if ($price->type == 'default' && $price->priceList == null) {
                    return $price->value;
                }
            }
        }
        return null;
    }

    /**
     * @return string|null
     */
    function getDefaultPriceExclVatAttribute()
    {
        if (!empty($this->prices)) {
            foreach ($this->prices as $price) {
                if ($price->type == 'default' && $price->priceList == null) {
                    $setting = $this->sales_order_including_vat;
                    if (isset($setting) && $setting !== null) {
                        if ($setting->value == 1) {
                            return number_format(($price->value / (100 + $price->taxrate)) * 100, 3, '.', '');
                        }
                    } else {
                        return number_format($price->value, 3, '.', '');
                    }
                }
            }
        }
        return null;
    }

    /**
     * @return null
     */
    function getDefaultPriceTaxRateAttribute()
    {
        if (!empty($this->prices)) {
            foreach ($this->prices as $price) {
                if ($price->type == 'default') {
                    return $price->taxrate;
                }
            }
        }
        return null;
    }

    /**
     * @return null
     */
    function getCogPriceAttribute()
    {
        if (!empty($this->prices)) {
            foreach ($this->prices as $price) {
                if ($price->type == 'cog') {
                    return $price->value;
                }
            }
        }
        return null;
    }

    /**
     * @return null
     */
    function getAdvicePriceAttribute()
    {
        if (!empty($this->prices)) {
            foreach ($this->prices as $price) {
                if ($price->type == 'advice_price') {
                    return $price->value;
                }
            }
        }
        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    function stock()
    {
        return $this->hasOne(Stock::class, 'productcode', 'code');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function mainProduct()
    {
        return $this->belongsToMany(
            Product::class,
            'mshop_product_list',
            'refid',
            'parentid'
        )->where('domain', 'product')->where('mshop_product_list.type', 'default');
    }
}
