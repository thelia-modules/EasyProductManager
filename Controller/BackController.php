<?php

namespace EasyProductManager\Controller;

use EasyProductManager\EasyProductManager;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Controller\Admin\ProductController;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Thelia;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ProductI18nTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\TaxEngine\Calculator;
use Thelia\Tools\MoneyFormat;
use Thelia\Tools\URL;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/easy-product-manager/list", name="easy-product-manager")
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class BackController extends ProductController
{
    /**
     * @Route("/{productId}", name="_product", methods="GET")
     */
    public function productAction(RequestStack $requestStack, $productId)
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $response;
        }

        $request = $requestStack->getCurrentRequest();

        $product = ProductQuery::create()
            ->filterById($productId)
            ->findOne();

        return $this->render('EasyProductManager/product', [
            'form' => $this->hydrateObjectForm($product),
            'product_id' => $productId,
            'edit_currency_id' => $request->getSession()->getAdminEditionCurrency()->getId()
        ]);
    }

    /**
     * @Route("", name="_list", methods={"GET","POST"})
     */
    public function listAction(RequestStack $requestStack, EventDispatcherInterface $eventDispatcher)
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $response;
        }

        $request = $requestStack->getCurrentRequest();
        if ($request->isXmlHttpRequest()) {
            /** @var Lang $lang */
            $lang = $this->getLang($request);

            $query = ProductQuery::create();

            // Jointure i18n
            $query->useProductI18nQuery()
                ->filterByLocale($lang->getLocale())
                ->endUse()
                ->withColumn(ProductI18nTableMap::TITLE, 'product_i18n_TITLE');

            // Jointure product sale element
            $query->useProductSaleElementsQuery('pse_price')
                ->filterByIsDefault(true)
                ->useProductPriceQuery()
                ->endUse()
                ->endUse();

            $query->withColumn('product_price.PRICE', 'price');
            $query->withColumn('product_price.PROMO_PRICE', 'promo_price');

            // position
            $query->useProductCategoryQuery()
                ->withColumn('product_category.POSITION', 'productPosition')
                ->endUse();

            $newnessSubQuery = ProductSaleElementsQuery::create();
            $newnessSubQuery->setPrimaryTableName(ProductSaleElementsTableMap::TABLE_NAME);
            $newnessSubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::PRODUCT_ID);
            $newnessSubQuery->addAsColumn('newness', 'SUM(product_sale_elements.newness)');
            $newnessSubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($newnessSubQuery, 'newnessSubQuery', false)
                ->withColumn('newnessSubQuery.newness', 'newness')
                ->where('newnessSubQuery.product_id = ' . ProductTableMap::ID)
            ;

            $quantitySubQuery = new ProductSaleElementsQuery();
            $quantitySubQuery->setPrimaryTableName(ProductSaleElementsTableMap::TABLE_NAME);
            $quantitySubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::PRODUCT_ID);
            $quantitySubQuery->addAsColumn('quantity', 'SUM(product_sale_elements.quantity)');
            $quantitySubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($quantitySubQuery, 'quantitySubQuery', false)
                ->withColumn('quantitySubQuery.quantity', 'quantity')
                ->where('quantitySubQuery.product_id = ' . ProductTableMap::ID)
            ;

            // Jointure product sale element
            $query->useProductSaleElementsQuery()
                ->endUse();


            $query->groupBy(ProductTableMap::ID);

            $this->applyOrder($request, $query);

            $queryCount = clone $query;

            $this->filterByCategory($request, $query);
            $this->filterByBrand($request, $query);
            $this->filterByQuantity($request, $query);
            $this->filterByVisible($request, $query);
            $this->filterByPromotion($request, $query);
            $this->filterByNewness($request, $query);
            $this->filterByFeature($request, $query);
            $this->filterByAttribute($request, $query);

            $this->applySearch($request, $query);

            $querySearchCount = clone $query;

            $query->offset($this->getOffset($request));

            $products = $query->limit($this->getLength($request))->find();

            $json = [
                "draw"=> $this->getDraw($request),
                "recordsTotal"=> $queryCount->count(),
                "recordsFiltered"=> $querySearchCount->count(),
                "data" => []
            ];

            // Create image processing event
            $event = (new ImageEvent())
                ->setResizeMode(\Thelia\Action\Image::EXACT_RATIO_WITH_CROP)
                ->setWidth(50)
                ->setHeight(50)
                ->setQuality(80)
                ->setCacheSubdirectory('product');

            $baseSourceFilePath = THELIA_LOCAL_DIR . 'media' . DS . 'images';

            $country = $this->getCountry($request);
            $currency = $this->getCurrency($request);

            $moneyFormat = MoneyFormat::getInstance($request);
            $taxCalculator = new Calculator();

            /** @var Product $product */
            foreach ($products as $product) {
                $image = ProductImageQuery::create()
                    ->filterByVisible(true)
                    ->filterByProductId($product->getId())
                    ->orderByPosition(Criteria::ASC)
                    ->findOne();

                $imageUrl = '';
                if (null !== $image) {
                    try {
                        // Put source image file path
                        $sourceFilePath = sprintf(
                            '%s/%s/%s',
                            $baseSourceFilePath,
                            'product',
                            $image->getFile()
                        );

                        $event
                            ->setSourceFilepath($sourceFilePath);

                        // Dispatch image processing event
                        $eventDispatcher->dispatch($event, TheliaEvents::IMAGE_PROCESS);

                        $imageUrl = $event->getFileUrl();
                    } catch (\Exception $e) {
                        // on ignore l'erreur
                    }
                }

                $price = $product->getVirtualColumn('price');
                $taxedPrice = $taxCalculator->load($product, $country)->getTaxedPrice($product->getVirtualColumn('price'));
                $promoPrice = $product->getVirtualColumn('promo_price');
                $promoTaxedPrice = $taxCalculator->load($product, $country)->getTaxedPrice($product->getVirtualColumn('promo_price'));

                $price = $moneyFormat->formatByCurrency(
                    $price,
                    2,
                    '.',
                    ' ',
                    $currency->getId()
                );

                $taxedPrice = $moneyFormat->formatByCurrency(
                    $taxedPrice,
                    2,
                    '.',
                    ' ',
                    $currency->getId()
                );

                $promoPrice = $moneyFormat->formatByCurrency(
                    $promoPrice,
                    2,
                    '.',
                    ' ',
                    $currency->getId()
                );

                $promoTaxedPrice = $moneyFormat->formatByCurrency(
                    $promoTaxedPrice,
                    2,
                    '.',
                    ' ',
                    $currency->getId()
                );

                $json['data'][] = [
                    [
                        'product_ids' => $product->getId(),
                    ],
                    $product->getId(),
                    $imageUrl,
                    $product->getRef(),
                    $product->getVirtualColumn('product_i18n_TITLE'),
                    [$price, $taxedPrice],
                    [$promoPrice, $promoTaxedPrice, $product->hasVirtualColumn('is_promo') ? $product->getVirtualColumn('is_promo') : 0],
                    $product->getVirtualColumn('quantity'),
                    $product->hasVirtualColumn('productPosition') ? $product->getVirtualColumn('productPosition') : 0,
                    $product->getVisible(),
                    $this->getRoute('admin.products.update', [
                        'product_id' => $product->getId()
                    ])
                ];
            }

            return new JsonResponse($json);
        }

        return $this->render('EasyProductManager/list', [
            'columnsDefinition' => $this->defineColumnsDefinition(),
            'currencySymbol' => $request->getSession()->getAdminEditionCurrency()->getSymbol()
        ]);
    }

    protected function filterByCategory(Request $request, ProductQuery $query)
    {
        if (0 !== $categoryId = (int) $request->get('filter')['category']) {
            $query->where('product_category.CATEGORY_ID = ?', $categoryId, \PDO::PARAM_INT);
        }
    }

    protected function filterByBrand(Request $request, ProductQuery $query)
    {
        if (0 !== $brandId = (int) $request->get('filter')['brand']) {
            $query->filterByBrandId($brandId);
        }
    }

    protected function filterByVisible(Request $request, ProductQuery $query)
    {
        if (0 !== $visible = (int) $request->get('filter')['visible']) {
            $query->filterByVisible($visible === 1 ? 1 : 0);
        }
    }

    protected function getCountry(Request $request)
    {
        return CountryQuery::create()->findOneById($request->get('filter')['country']);
    }

    protected function getLang(Request $request)
    {
        return LangQuery::create()->findOneById($request->get('filter')['lang']);
    }

    protected function getCurrency(Request $request)
    {
        return CurrencyQuery::create()->findOneByByDefault(true);
    }

    protected function filterByPromotion(Request $request, ProductQuery $query)
    {
        if (0 !== $promotion = (int) $request->get('filter')['promotion']) {
            $promoSubQuery = ProductSaleElementsQuery::create();
            $promoSubQuery->setPrimaryTableName(ProductSaleElementsTableMap::TABLE_NAME);
            $promoSubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::PRODUCT_ID);
            $promoSubQuery->addAsColumn('promo', 'SUM(product_sale_elements.promo)');
            $promoSubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($promoSubQuery, 'promoSubQuery', false)
                ->withColumn('promoSubQuery.promo', 'is_promo')
                ->where('promoSubQuery.product_id = ' . ProductTableMap::ID)
            ;

            if ($promotion === 1) {
                $query->having('promo >= ?', 1, \PDO::PARAM_INT);
            } else {
                $query->having('promo = ?', 0, \PDO::PARAM_INT);
            }
        }
    }

    protected function filterByNewness(Request $request, ProductQuery $query)
    {
        if (0 !== $newness = (int) $request->get('filter')['newness']) {
            if ($newness === 1) {
                $query->having('newness >= ?', 1, \PDO::PARAM_INT);
            } else {
                $query->having('newness = ?', 0, \PDO::PARAM_INT);
            }
        }
    }

    protected function filterByQuantity(Request $request, ProductQuery $query)
    {
        $quantityMin = (int) $request->get('filter')['quantity']['min'];
        $quantityMax = (int) $request->get('filter')['quantity']['max'];

        if ('' !== $request->get('filter')['quantity']['min']) {
            $query->having('quantity >= ?', $quantityMin, \PDO::PARAM_INT);
        }

        if ('' !== $request->get('filter')['quantity']['max']) {
            $query->having('quantity <= ?', $quantityMax, \PDO::PARAM_INT);
        }
    }

    protected function filterByFeature(Request $request, ProductQuery $query)
    {
        if (is_array($request->get('filter')['features'])) {
            $features = array_map(function ($featureId) {
                return (int) $featureId;
            }, $request->get('filter')['features']);
        } else {
            $features = [];
        }

        if (count($features)) {
            $query->useFeatureProductQuery()
                ->filterByFeatureAvId($features, Criteria::IN)
                ->endUse();
        }
    }

    protected function filterByAttribute(Request $request, ProductQuery $query)
    {
        if (is_array($request->get('filter')['attributes'])) {
            $attributes = array_map(function ($attributeId) {
                return (int) $attributeId;
            }, $request->get('filter')['attributes']);
        } else {
            $attributes = [];
        }

        if (count($attributes)) {
            // Jointure product sale element
            $query->useProductSaleElementsQuery('pse_attribute')
                ->useAttributeCombinationQuery()
                ->filterByAttributeAvId($attributes, Criteria::IN)
                ->endUse()
                ->endUse();
        }
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getLength(Request $request)
    {
        return (int) $request->get('length');
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getOffset(Request $request)
    {
        return (int) $request->get('start');
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getDraw(Request $request)
    {
        return (int) $request->get('draw');
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderDir(Request $request)
    {
        return (string) $request->get('order')[0]['dir'] === 'asc' ? Criteria::ASC : Criteria::DESC;
    }

    /**
     * @param bool $withPrivateData
     * @return array
     */
    protected function defineColumnsDefinition($withPrivateData = false)
    {
        $i = -1;

        $definitions = [
            [
                'name' => 'checkbox',
                'targets' => ++$i,
                'title' => '<input type="checkbox" id="select-all" />',
                'orderable' => false,
                'searchable' => false,
            ],
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => ProductTableMap::ID,
                'title' => 'Id',
                'searchable' => false
            ],
            [
                'name' => 'images',
                'targets' => ++$i,
                'title' => 'Image',
                'orderable' => false,
                'searchable' => false
            ],
            [
                'name' => 'ref',
                'targets' => ++$i,
                'orm' => ProductTableMap::REF,
                'title' => 'Référence',
                'searchable' => true
            ],
            [
                'name' => 'title',
                'targets' => ++$i,
                'orm' => 'product_i18n_TITLE',
                'title' => 'Titre',
                'searchable' => true
            ],
            [
                'name' => 'price',
                'targets' => ++$i,
                'orm' => 'price',
                'title' => 'Prix',
                'searchable' => false
            ],
            [
                'name' => 'promo_price',
                'targets' => ++$i,
                'orm' => 'promo_price',
                'title' => 'Prix promo',
                'searchable' => false
            ],
            [
                'name' => 'quantity',
                'targets' => ++$i,
                'orm' => 'quantity',
                'title' => 'Quantité',
                'searchable' => false
            ],
            [
                'name' => 'position',
                'targets' => ++$i,
                'orm' => 'productPosition',
                'title' => 'Position',
                'searchable' => false
            ],
            [
                'name' => 'visible',
                'targets' => ++$i,
                'orm' => ProductTableMap::VISIBLE,
                'title' => 'En ligne',
                'searchable' => false
            ],
            [
                'name' => 'action',
                'targets' => ++$i,
                'title' => 'Action',
                'orderable' => false,
                'searchable' => false
            ]
        ];

        if (!$withPrivateData) {
            foreach ($definitions as &$definition) {
                unset($definition['orm']);
            }
        }

        return $definitions;
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderColumnName(Request $request)
    {
        $columnDefinition = $this->defineColumnsDefinition(true)[
        (int) $request->get('order')[0]['column']
        ];

        return $columnDefinition['orm'];
    }

    protected function applyOrder(Request $request, ProductQuery $query)
    {
        $query->orderBy(
            $this->getOrderColumnName($request),
            $this->getOrderDir($request)
        );
    }

    protected function applySearch(Request $request, ProductQuery $query)
    {
        $value = $this->getSearchValue($request);

        if (strlen($value) > 2) {
            // Jointure product sale element
            $query->useProductSaleElementsQuery('pse_search_ref')
                ->endUse();

            $query->where(ProductTableMap::REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(ProductI18nTableMap::TITLE . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(' pse_search_ref.ref LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
        }
    }

    protected function getSearchValue(Request $request)
    {
        return (string) $request->get('search')['value'];
    }

    /**
     * @Route("/delete-selected", name="_delete_selected", methods="POST")
     * @throws \JsonException
     */
    public function deleteSelectedAction(Request $request)
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::DELETE)) {
            return $response;
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $productIds = $data['product_ids'] ?? [];

        $deletedProducts = [];
        $notDeletedProducts = [];

        foreach ($productIds as $productId) {
            $product = ProductQuery::create()->findPk($productId);

            if ($product !== null) {
                try {
                    $product->delete();
                    $deletedProducts[] = $productId;
                } catch (\Exception $e) {
                    $notDeletedProducts[] = $productId;
                }
            } else {
                $notDeletedProducts[] = $productId;
            }
        }

        return new JsonResponse([
            'deleted_products' => $deletedProducts,
            'not_deleted_products' => $notDeletedProducts,
        ]);
    }

    /**
     * @Route("/change-visibility-selected", name="_change_visibility_selected", methods="POST")
     * @throws \JsonException
     */
    public function changeVisibilitySelectedAction(Request $request)
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $response;
        }

        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $productIds = $data['product_ids'] ?? [];
        $visibility = (int) ($data['visibility'] ?? 0);

        $updatedProducts = [];
        $notUpdatedProducts = [];

        foreach ($productIds as $productId) {
            $product = ProductQuery::create()->findPk($productId);

            if ($product !== null) {
                try {
                    $product->setVisible($visibility);
                    $product->save();
                    $updatedProducts[] = $productId;
                } catch (\Exception $e) {
                    $notUpdatedProducts[] = $productId;
                }
            } else {
                $notUpdatedProducts[] = $productId;
            }
        }

        return new JsonResponse([
            'updated_products' => $updatedProducts,
            'not_updated_products' => $notUpdatedProducts,
        ]);
    }
}
