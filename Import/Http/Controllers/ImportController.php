<?php

namespace Modules\Import\Http\Controllers;

use App\Jobs\Import;
use Modules\Import\Entities\Media;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Entities\AttributeType;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportController extends Controller
{
    const FIELDS = [
        'product.code' => [
            'name' => 'sku',
            'headings' => ['sku', 'ean', 'barcode', 'ean-code', 'code', 'EAN', 'ean code', 'ean13'],
        ],
        'product.code-alt' => [
            'name' => 'sku (alternatief)',
            'headings' => [],
        ],
        'product.parent' => [
            'name' => 'groeperings code',
            'headings' => ['manufacturer sku', 'parent', 'groeperings code'],
        ],
        'product.text.name' => [
            'name' => 'naam',
            'headings' => ['naam', 'name', 'matrix description', 'matrix omschrijving'],
        ],
        'product.text.short' => [
            'name' => 'korte omschrijving',
            'headings' => ['korte omschrijving', 'short description', 'omschrijving kort'],
        ],
        'product.text.long' => [
            'name' => 'lange omschrijving',
            'headings' => ['lange omschrijving', 'long description', 'omschrijving lang'],
        ],
        'product.text.meta-title' => [
            'name' => 'meta titel',
            'headings' => ['meta title', 'meta titel'],
        ],
        'product.text.meta-description' => [
            'name' => 'meta omschrijving',
            'headings' => ['meta omschrijving', 'meta description'],
        ],
        'product.text.comment' => [
            'name' => 'commentaar',
            'headings' => ['commentaar', 'comment', 'comments'],
        ],
        'product.text.instructions' => [
            'name' => 'gebruiksaanwijzing',
            'headings' => ['gebruiksaanwijzing', 'instructions'],
        ],
        'product.text.supplier-sku' => [
            'name' => 'leverancier sku',
            'headings' => ['leverancier sku', 'supplier sku', 'artikelnummer', 'leveranciers sku'],
        ],
        'supplier.name' => [
            'name' => 'leverancier',
            'headings' => ['leverancier', 'supplier'],
        ],
        'product.text.manufacturer-sku' => [
            'name' => 'fabrikant sku',
            'headings' => ['fabrikant sku', 'manufacturer sku'],
        ],
        'product.price.cog' => [
            'name' => 'inkoopprijs',
            'headings' => ['inkoopprijs', 'cog', 'default cost', 'cost'],
        ],
        'product.price.default' => [
            'name' => 'prijs',
            'headings' => ['prijs', 'price', 'adviesprijs'],
        ],
        'product.price.default.taxrate' => [
            'name' => 'btw percentage',
            'headings' => ['btw', 'taxrate'],
        ],
        'product.price.advice-price' => [
            'name' => 'adviesprijs',
            'headings' => ['adviesprijs', 'advice-price'],
        ],
        'product.price.discount-price' => [
            'name' => 'kortingsprijs',
            'headings' => ['kortingsprijs', 'discount-price'],
        ],
        'product.status' => [
            'name' => 'status',
            'headings' => ['status'],
        ],
        'media' => [
            'name' => 'media',
            'headings' => ['media'],
        ],
        'product.image' => [
            'name' => 'afbeelding',
            'headings' => ['afbeelding', 'image', 'image url', 'images'],
        ],
        'attributes.weight' => [
            'name' => 'gewicht',
            'headings' => ['gewicht', 'weight'],
        ],
        'attributes.brand' => [
            'name' => 'merk',
            'headings' => ['brand', 'merk'],
        ],
        'attributes.size' => [
            'name' => 'maat',
            'headings' => ['size', 'maat'],
        ],
        'attributes.color' => [
            'name' => 'kleur',
            'headings' => ['color', 'kleur', 'colour'],
        ],
        'attributes.material' => [
            'name' => 'materiaal',
            'headings' => ['material', 'materiaal'],
        ],
        'attributes.seats' => [
            'name' => 'zitplaatsen',
            'headings' => ['seats', 'zitplaatsen'],
        ],
        'attributes.dimensions' => [
            'name' => 'afmetingen',
            'headings' => ['dimensions', 'afmetingen', 'afmeeting', 'dimension'],
        ],
        'attributes.serie' => [
            'name' => 'serie',
            'headings' => ['serie'],
        ],
        'attributes.occasion' => [
            'name' => 'gelegenheid',
            'headings' => ['occasion', 'gelegenheid'],
        ],
        'attributes.age' => [
            'name' => 'leeftijd',
            'headings' => ['age', 'leeftijd'],
        ],
        'selectable-attributes.name' => [
            'name' => 'naam (variabel)',
            'headings' => ['naam (variabel)', 'name (selectable)'],
        ],
        'selectable-attributes.size' => [
            'name' => 'maat (variabel)',
            'headings' => ['maat', 'size'],
        ],
        'selectable-attributes.color' => [
            'name' => 'kleur (variabel)',
            'headings' => ['color (variable)', 'kleur (variabel)'],
        ],
        'selectable-attributes.cup' => [
            'name' => 'cup (variabel)',
            'headings' => ['cup', 'cup'],
        ],
        'selectable-attributes.gender' => [
            'name' => 'geslacht (variabel)',
            'headings' => ['gender', 'geslacht'],
        ],
        'selectable-attributes.audience' => [
            'name' => 'doelgroep (variabel)',
            'headings' => ['audience', 'doelgroep'],
        ],
        'selectable-attributes.type' => [
            'name' => 'type (variabel)',
            'headings' => ['type'],
        ],
        'stock' => [
            'name' => 'voorraad',
            'headings' => ['stock', 'voorraad', 'availability'],
        ],
        'category' => [
            'name' => 'categorie',
            'headings' => ['categorie', 'category'],
        ],
        'subcategory1' => [
            'name' => 'subcategorie 1',
            'headings' => ['subcategorie 1', 'subcategory 1'],
        ],
        'subcategory2' => [
            'name' => 'subcategorie 2',
            'headings' => ['subcategorie 2', 'subcategory 2'],
        ],
        'subcategory3' => [
            'name' => 'subcategorie 3',
            'headings' => ['subcategorie 3', 'subcategory 3'],
        ],
        'subcategory4' => [
            'name' => 'subcategorie 4',
            'headings' => ['subcategorie 4', 'subcategory 4'],
        ],
        'subcategory5' => [
            'name' => 'subcategorie 5',
            'headings' => ['subcategorie 5', 'subcategory 5'],
        ],
        'subcategory6' => [
            'name' => 'subcategorie 6',
            'headings' => ['subcategorie 6', 'subcategory 6'],
        ],
        'subcategory7' => [
            'name' => 'subcategorie 7',
            'headings' => ['subcategorie 7', 'subcategory 7'],
        ],
        'subcategory8' => [
            'name' => 'subcategorie 8',
            'headings' => ['subcategorie 8', 'subcategory 8'],
        ],
        'subcategory9' => [
            'name' => 'subcategorie 9',
            'headings' => ['subcategorie 9', 'subcategory 9'],
        ],
        'private_tag' => [
            'name' => 'tag',
            'headings' => ['tags', 'add tags', 'tag'],
        ],
    ];

    const SIZES = [
        "XXS" => 0,
        "XS" => 1,
        "S" => 2,
        "M" => 3,
        "L" => 4,
        "XL" => 5,
        "XXL" => 6,
        "2XL" => 7,
        "3XL" => 8,
        "4XL" => 9,
    ];

    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('import::index')->with([
            'action_url' => route('import.import'),
            'fields' => collect(self::FIELDS)->sort(),
            'attribute_types' => AttributeType::where('code', '!=', 'merk')->where('siteid', 1)->with('attributes')->get(),
            'sizes' => self::SIZES,
        ]);
    }

    /**
     * @param Media $media
     * @return \Illuminate\Http\JsonResponse
     * @throws \PhpOffice\PhpSpreadsheet\Calculation\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function getExcelHeaders(Media $media)
    {
        $stamp = time();
        try {
            $mediaPath = $media->getPath();
            $mediaURL = $media->getTemporaryUrl(Carbon::now()->addMinutes(5));
            Storage::disk('local')->put('import/shop/' . $stamp . '.xlsx', file_get_contents($mediaURL));

            $filepath = storage_path() . '/app/import/shop/' . $stamp . '.xlsx';
            if ($media->disk === 's3') {
                if (Storage::disk($media->disk)->has($mediaPath)) {
                    $type = IOFactory::identify($filepath);
                    $reader = IOFactory::createReader($type);
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($filepath);

                    $sheetCount = $spreadsheet->getSheetCount();
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rowIterator = $worksheet->getRowIterator();
                    $highestRow = $worksheet->getHighestDataRow() - 1;
                    $rowHeadings = [];
                    $firstRow = 1;

                    foreach ($rowIterator as $row) {
                        if ($row->getRowIndex() === $firstRow) {
                            $cellIterator = $row->getCellIterator();
                            foreach ($cellIterator as $cell) {
                                $cellValue = $cell->getCalculatedValue() ?? false;
                                if ($cellValue) {
                                    $rowHeadings[$cell->getColumn()] = !preg_match('/import::t/', __(sprintf('import::t.%s', $cellValue))) && (gettype($cellValue) === 'string') ? __(sprintf('import::t.%s', $cellValue)) : strval($cellValue);
                                }
                            }

                            if (empty($rowHeadings)) {
                                $firstRow++;
                                continue;
                            }
                        } else {
                            break;
                        }
                    }

                    Storage::disk('local')->delete('import/shop/' . $stamp . '.xlsx');

                    return response()->json([
                        'rows' => $rowHeadings,
                        'highest_row' => $highestRow,
                        'sheet_count' => $sheetCount,
                    ]);
                } else {
                    throw new Exception('File not found on s3.');
                }
            }
        } catch (Exception $e) {
            throw new Exception('getExcelHeaders > ' . $e->getMessage(), $e->getCode());
        }
    }

    public function addFiles(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'model' => 'required',
            'id' => 'required|integer',
            'files' => 'required|array',
            'files.*' => 'file',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $requestModel = $request->input('model');
        $requestModelID = $request->input('id');
        $model = $requestModel::findOrFail($requestModelID);

        if ($request->input('name-prefix', false)) {
            $namePrefix = $request->input('name-prefix') . '-';
        } else {
            $namePrefix = '';
        }

        $properties = [];
        if ($request->input('isPublic', false)) {
            $properties['isPublic'] = '1';
        }
        $media = [];
        foreach ($request->file('files') as $file) {
            $media[] = $model->addMedia($file)
                ->usingName($namePrefix . $file->getClientOriginalName())
                ->withCustomProperties($properties)
                ->toMediaCollection($request->input('collection', 'files'), 's3');
        }

        /**
         * Sync Tags
         */
        if ($request->input('tags', false)) {
            foreach ($media as $m) {
                $m->syncTagsWithType($request->input('tags', []), 'media');
            }
        }

        return response()->json([
            'message' => ucfirst(__('import::t.file(s) saved successful')),
            'media' => $media,
        ]);
    }

    /**
     * @param Media $media
     * @param int $page
     * @param Request $request
     * @param int $limit
     * @return \Illuminate\Http\JsonResponse
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function import(Media $media, int $page, Request $request, int $limit = 10)
    {
        $stamp = time();
        $info = [
            'results' => [],
        ];
        $fileContents = [];
        $mapping = [
            'product' => [
                'status' => '2',
            ],
            'attributes' => [],
            'selectable-attributes' => [],
            'supplier' => [],
            'stock' => '',
            'catalog' => [],
            'media' => [],
        ];
        $rawMapping = $request->input('position');
        $log = Log::channel('import');

        if ($page === 0) {
            $log->info('Import filename: ' . $media->name);
            $log->info('force_update: ', $request->input('force_update') === 'on' ? ['true'] : ['false']);
            $log->info('supplier_sku_as_parent: ', $request->input('supplier_sku_as_parent') === 'on' ? ['true'] : ['false']);
            $log->info('mapping: ', $rawMapping);
        }
        $log->info('batch: ' . $page);

        $fileName = 'import/shop/' . $stamp . '.xlsx';

        Storage::disk('local')->put($fileName, file_get_contents(
            $media->getTemporaryUrl(Carbon::now()->addMinutes(5))
        ));

        if (!empty($fileName)) {
            if (!Storage::disk('local')->exists($fileName)) {
                $log->error('Import aborted because the file is missing');
                return Response()->json(['message' => 'Import aborted because the file is missing'], 500);
            }

            $import_file_extension = explode('.', $fileName);
            $import_file_extension = end($import_file_extension);

            if ($import_file_extension === 'xlsx') {
                $filepath = storage_path() . '/app/' . $fileName;

                $type = IOFactory::identify($filepath);
                $reader = IOFactory::createReader($type);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($filepath);

                $worksheet = $spreadsheet->getActiveSheet();
                $rowIterator = $worksheet->getRowIterator();
                $highestRow = $worksheet->getHighestDataRow() - 1;
                $rowHeadings = [];

                $limit = $page === 0 ? $limit + 1 : $limit;
                $startRow = $limit * $page;
                $endRow = $startRow + $limit;
                foreach ($rowIterator as $row) {
                    $cellIterator = $row->getCellIterator();
                    $rowIndex = $row->getRowIndex();
                    if ($rowIndex === 1) {
                        foreach ($cellIterator as $cell) {
                            $rowHeadings[$cell->getColumn()] = $cell->getCalculatedValue();
                        }
                    } else {
                        if ($rowIndex > $startRow && $rowIndex <= $endRow) {
                            $info['next_page'] = $page + 1;

                            foreach ($cellIterator as $cell) {
                                if ($cell->getCalculatedValue()) {
                                    if (!isset($rowHeadings[$cell->getColumn()])) {
                                        $log->error('Import aborted because header "' . $cell->getColumn() . '" is not set in import list');
                                        return Response()->json(['message' => 'Import aborted because header "' . $cell->getColumn() . '" is not set in import list']);
                                    }

                                    $fileContents[$rowIndex][$rowHeadings[$cell->getColumn()]] = str_replace('""', '', $cell->getCalculatedValue());
                                }
                            }
                        }
                    }
                }

                if ($page === 0) {
                    $log->info('rowHeadings: ', $rowHeadings);
                }

                if (!empty($fileContents)) {
                    /**
                     * Check if required fields are set
                     */
                    if (
                        !in_array('product.text.name', $rawMapping) ||
                        !in_array('product.code', $rawMapping)
                    ) {
                        $log->warning('Required fields are not set');
                        return Response()->json(['message' => __('import::t.Required fields are not set')], 500);
                    }

                    /**
                     * Map fields
                     */
                    foreach ($rawMapping as $key => $field) {
                        if (!empty($field)) {
                            if ($field === 'product.text.name') {
                                $mapping['product']['label'] = $rowHeadings[$key];
                            } else if ($field === 'product.text.supplier-sku' || $field === 'product.parent') {
                                $mapping['product']['text.supplier-sku'] = $rowHeadings[$key];
                                $mapping['product']['parent'] = $rowHeadings[$key];
                            } else if ($field === 'supplier.name') {
                                $mapping['supplier']['name'] = $rowHeadings[$key];
                            } else if ($field === 'category') {
                                $mapping['catalog'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory1') {
                                $mapping['subcategory1'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory2') {
                                $mapping['subcategory2'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory3') {
                                $mapping['subcategory3'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory4') {
                                $mapping['subcategory4'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory5') {
                                $mapping['subcategory5'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory6') {
                                $mapping['subcategory6'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory7') {
                                $mapping['subcategory7'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory8') {
                                $mapping['subcategory8'][] = $rowHeadings[$key];
                            } else if ($field === 'subcategory9') {
                                $mapping['subcategory9'][] = $rowHeadings[$key];
                            } else if ($field === 'tag') {
                                $mapping['tags'][] = $rowHeadings[$key];
                            } else if ($field === 'private_tag') {
                                $mapping['private_tags'][] = $rowHeadings[$key];
                            } else {
                                $fieldSplit = explode('.', $field, 2);
                                if (count($fieldSplit) === 2) {
                                    if ($fieldSplit[0] === 'selectable-attributes') {
                                        $mapping[$fieldSplit[0]][$fieldSplit[1]] = $fieldSplit[1];
                                    } else if ($fieldSplit[0] === 'attributes') {
                                        $mapping[$fieldSplit[0]][$fieldSplit[1]] = [$rowHeadings[$key]];
                                    } else {
                                        $mapping[$fieldSplit[0]][$fieldSplit[1]] = $rowHeadings[$key];
                                    }
                                } else {
                                    $mapping[$fieldSplit[0]] = $rowHeadings[$key];
                                }
                            }
                        }
                    }

                    $countFileContents = count($fileContents);
                    $info[] = __('import::t.Insertion of import lines started with :number records', ['number' => $countFileContents]);
                    $info['number'] = $countFileContents;

                    if ($countFileContents < ($limit - 1) || $countFileContents === $highestRow) {
                        unset($info['next_page']);
                    }

                    /**
                     * Trigger import
                     */
                    $info['results'] = dispatch_now(new Import(
                        $fileContents,
                        $mapping,
                        $startRow,
                        $request->input('force_update') === 'on',
                        $request->input('supplier_sku_as_parent') === 'on'
                    ));
                }
            } else {
                $log->error('The file extension of ' . $fileName . ' is not supported');
                return Response()->json(['message' => 'The file extension of ' . $fileName . ' is not supported'], 500);
            }
        }

        $log->info('results: ', $info);
        Storage::disk('local')->delete('import/shop/' . $stamp . '.xlsx');
        return Response()->json(['info' => $info]);
    }
}
