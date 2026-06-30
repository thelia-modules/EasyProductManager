<?php

namespace EasyProductManager\Controller;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\ProductController;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Domain\Taxation\TaxEngine\Calculator;
use Thelia\Model\CountryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\ProductI18nTableMap;
use Thelia\Model\Map\ProductImageI18nTableMap;
use Thelia\Model\Map\ProductImageTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Product;
use Thelia\Model\ProductImageQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\TaxEngine\Calculator as LegacyCalculator;
use Thelia\Tools\MoneyFormat;
use Thelia\Tools\TokenProvider;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Model\AttributeQuery;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\FeatureQuery;
use Thelia\Model\AttributeCombinationQuery;
use Twig\Environment;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
#[Route('/admin/easy-product-manager/list', name: 'easy-product-manager')]
class BackController extends ProductController
{
    public string $productImageColFile = "";

    #[Route('/{productId}', name: '_product', methods: ['GET'])]
    public function productAction(RequestStack $requestStack, $productId, ParserContext $parserContext, Environment $twig): ?Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $response;
        }

        $request = $requestStack->getCurrentRequest();
        $editCurrencyId = $request->getSession()->getAdminEditionCurrency()->getId();

        $product = ProductQuery::create()
            ->filterById($productId)
            ->findOne();

        $theliaForm = $this->hydrateObjectForm($parserContext, $product);

        // Determine whether the product has at least one attribute combination
        $hasAtLeastOneCombination = false;
        $defaultProductSaleElementId = 0;

        $saleElements = ProductSaleElementsQuery::create()
            ->filterByProductId((int) $productId)
            ->find();

        /** @var \Thelia\Model\ProductSaleElements $pse */
        foreach ($saleElements as $pse) {
            $combinationCount = AttributeCombinationQuery::create()
                ->filterByProductSaleElementsId($pse->getId())
                ->count();

            if ($combinationCount > 0) {
                $hasAtLeastOneCombination = true;
            } else {
                $defaultProductSaleElementId = $pse->getId();
            }
        }

        $currency = CurrencyQuery::create()->findPk($editCurrencyId);
        $currencySymbol = null !== $currency ? $currency->getSymbol() : '';
        $currentCurrencyIsDefault = null !== $currency && $currency->getByDefault();

        return new Response($twig->render('@EasyProductManagerModule/backOffice/default-twig/EasyProductManager/product.html.twig', [
            'form' => $theliaForm->getForm()->createView(),
            'product_id' => $productId,
            'edit_currency_id' => $editCurrencyId,
            'has_at_least_one_combination' => $hasAtLeastOneCombination,
            'default_product_sale_element_id' => $defaultProductSaleElementId,
            'currency_symbol' => $currencySymbol,
            'current_currency_is_default' => $currentCurrencyIsDefault,
        ]));
    }

    #[Route('', name: '_list', methods: ['GET', 'POST'])]
    public function listAction(RequestStack $requestStack, EventDispatcherInterface $eventDispatcher, Environment $twig)
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
                ->withColumn(ProductI18nTableMap::COL_TITLE, 'product_i18n_TITLE');

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
            $newnessSubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::COL_PRODUCT_ID);
            $newnessSubQuery->addAsColumn('newness', 'SUM(product_sale_elements.newness)');
            $newnessSubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($newnessSubQuery, 'newnessSubQuery', false)
                ->withColumn('newnessSubQuery.newness', 'newness')
                ->where('newnessSubQuery.product_id = ' . ProductTableMap::COL_ID)
            ;

            $quantitySubQuery = new ProductSaleElementsQuery();
            $quantitySubQuery->setPrimaryTableName(ProductSaleElementsTableMap::TABLE_NAME);
            $quantitySubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::COL_PRODUCT_ID);
            $quantitySubQuery->addAsColumn('quantity', 'SUM(product_sale_elements.quantity)');
            $quantitySubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($quantitySubQuery, 'quantitySubQuery', false)
                ->withColumn('quantitySubQuery.quantity', 'quantity')
                ->where('quantitySubQuery.product_id = ' . ProductTableMap::COL_ID)
            ;

            // Jointure product sale element
            $query->useProductSaleElementsQuery()
                ->endUse();

            $pseUpdatedAtSubQuery = ProductSaleElementsQuery::create();
            $pseUpdatedAtSubQuery->setPrimaryTableName(ProductSaleElementsTableMap::TABLE_NAME);
            $pseUpdatedAtSubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::COL_PRODUCT_ID);
            $pseUpdatedAtSubQuery->addAsColumn('last_pse_updated_at', 'MAX(product_sale_elements.updated_at)');
            $pseUpdatedAtSubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($pseUpdatedAtSubQuery, 'pseUpdatedAtSubQuery', false)
                ->withColumn('pseUpdatedAtSubQuery.last_pse_updated_at', 'last_pse_updated_at')
                ->where('pseUpdatedAtSubQuery.product_id = ' . ProductTableMap::COL_ID);

            $query->groupBy(ProductTableMap::COL_ID);

            if (defined(ProductImageI18nTableMap::class.'::COL_FILE')) {
                $this->productImageColFile = ProductImageI18nTableMap::COL_FILE;
            }

            if (defined(ProductImageTableMap::class.'::COL_FILE')) {
                $this->productImageColFile = ProductImageTableMap::COL_FILE;
            }

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

            $taxCalculator = class_exists(LegacyCalculator::class)
                ? new LegacyCalculator()
                : new Calculator();

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
                        $sourceFilePath = sprintf(
                            '%s/%s/%s',
                            $baseSourceFilePath,
                            'product',
                            $image->setLocale($lang->getLocale())->getFile()
                        );

                        if (file_exists($sourceFilePath)) {
                            $event->setSourceFilepath($sourceFilePath);
                            $eventDispatcher->dispatch($event, TheliaEvents::IMAGE_PROCESS);
                            $imageUrl = $event->getFileUrl();
                        }
                    } catch (\Exception $e) {
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

        $locale = $request->getSession()->getAdminEditionLang()->getLocale();

        return new Response($twig->render('@EasyProductManagerModule/backOffice/default-twig/EasyProductManager/list.html.twig', [
            'columnsDefinition' => $this->defineColumnsDefinition(),
            'currencySymbol' => $request->getSession()->getAdminEditionCurrency()->getSymbol(),
            'categories' => $this->buildCategoryTree($locale),
            'brands' => $this->buildBrandList($locale),
            'langs' => $this->buildLangList(),
            'countries' => $this->buildCountryList($locale),
            'features' => $this->buildFeatureList($locale),
            'attributes' => $this->buildAttributeList($locale),
        ]));
    }

    /**
     * Flat category tree, ordered hierarchically, with a level column for indentation.
     * Replaces the Smarty {loop type="category-tree" category="0"}.
     *
     * @return array<int, array{id: int, title: string, level: int}>
     */
    protected function buildCategoryTree(string $locale, int $parentId = 0, int $level = 0): array
    {
        $result = [];

        $categories = CategoryQuery::create()
            ->filterByParent($parentId)
            ->orderByPosition(Criteria::ASC)
            ->find();

        /** @var \Thelia\Model\Category $category */
        foreach ($categories as $category) {
            $category->setLocale($locale);
            $result[] = [
                'id' => $category->getId(),
                'title' => (string) $category->getTitle(),
                'level' => $level,
            ];
            $result = array_merge($result, $this->buildCategoryTree($locale, $category->getId(), $level + 1));
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    protected function buildBrandList(string $locale): array
    {
        $result = [];
        $brands = BrandQuery::create()->orderByPosition(Criteria::ASC)->find();

        /** @var \Thelia\Model\Brand $brand */
        foreach ($brands as $brand) {
            $brand->setLocale($locale);
            $result[] = ['id' => $brand->getId(), 'title' => (string) $brand->getTitle()];
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string, is_default: bool}>
     */
    protected function buildLangList(): array
    {
        $result = [];
        $langs = LangQuery::create()->orderByPosition(Criteria::ASC)->find();

        /** @var Lang $lang */
        foreach ($langs as $lang) {
            $result[] = [
                'id' => $lang->getId(),
                'title' => (string) $lang->getTitle(),
                'is_default' => (bool) $lang->getByDefault(),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string, is_default: bool}>
     */
    protected function buildCountryList(string $locale): array
    {
        $result = [];
        $countries = CountryQuery::create()->filterByVisible(1)->find();

        /** @var \Thelia\Model\Country $country */
        foreach ($countries as $country) {
            $country->setLocale($locale);
            $result[] = [
                'id' => $country->getId(),
                'title' => (string) $country->getTitle(),
                'is_default' => (bool) $country->getByDefault(),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string, availabilities: array<int, array{id: int, title: string}>}>
     */
    protected function buildFeatureList(string $locale): array
    {
        $result = [];
        $features = FeatureQuery::create()->orderByPosition(Criteria::ASC)->find();

        /** @var \Thelia\Model\Feature $feature */
        foreach ($features as $feature) {
            $feature->setLocale($locale);

            $availabilities = [];
            foreach ($feature->getFeatureAvs() as $av) {
                $av->setLocale($locale);
                $availabilities[] = ['id' => $av->getId(), 'title' => (string) $av->getTitle()];
            }

            $result[] = [
                'id' => $feature->getId(),
                'title' => (string) $feature->getTitle(),
                'availabilities' => $availabilities,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string, availabilities: array<int, array{id: int, title: string}>}>
     */
    protected function buildAttributeList(string $locale): array
    {
        $result = [];
        $attributes = AttributeQuery::create()->orderByPosition(Criteria::ASC)->find();

        /** @var \Thelia\Model\Attribute $attribute */
        foreach ($attributes as $attribute) {
            $attribute->setLocale($locale);

            $availabilities = [];
            foreach ($attribute->getAttributeAvs() as $av) {
                $av->setLocale($locale);
                $availabilities[] = ['id' => $av->getId(), 'title' => (string) $av->getTitle()];
            }

            $result[] = [
                'id' => $attribute->getId(),
                'title' => (string) $attribute->getTitle(),
                'availabilities' => $availabilities,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestFilter(Request $request): array
    {
        $filter = $request->request->all('filter');
        if (empty($filter)) {
            $filter = $request->query->all('filter');
        }

        return is_array($filter) ? $filter : [];
    }

    protected function filterByCategory(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        if (0 !== $categoryId = (int) ($filter['category'] ?? 0)) {
            $query->where('product_category.CATEGORY_ID = ?', $categoryId, \PDO::PARAM_INT);
        }
    }

    protected function filterByBrand(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        if (0 !== $brandId = (int) ($filter['brand'] ?? 0)) {
            $query->filterByBrandId($brandId);
        }
    }

    protected function filterByVisible(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        if (0 !== $visible = (int) ($filter['visible'] ?? 0)) {
            $query->filterByVisible($visible === 1 ? 1 : 0);
        }
    }

    protected function getCountry(Request $request)
    {
        $filter = $this->getRequestFilter($request);
        return CountryQuery::create()->findOneById($filter['country'] ?? null);
    }

    protected function getLang(Request $request)
    {
        $filter = $this->getRequestFilter($request);
        return LangQuery::create()->findOneById($filter['lang'] ?? null);
    }

    protected function getCurrency(Request $request)
    {
        return CurrencyQuery::create()->findOneByByDefault(true);
    }

    protected function filterByPromotion(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        if (0 !== $promotion = (int) ($filter['promotion'] ?? 0)) {
            $promoSubQuery = ProductSaleElementsQuery::create();
            $promoSubQuery->setPrimaryTableName(ProductSaleElementsTableMap::TABLE_NAME);
            $promoSubQuery->addAsColumn('product_id', ProductSaleElementsTableMap::COL_PRODUCT_ID);
            $promoSubQuery->addAsColumn('promo', 'SUM(product_sale_elements.promo)');
            $promoSubQuery->addGroupByColumn('product_id');

            $query
                ->addSelectQuery($promoSubQuery, 'promoSubQuery', false)
                ->withColumn('promoSubQuery.promo', 'is_promo')
                ->where('promoSubQuery.product_id = ' . ProductTableMap::COL_ID)
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
        $filter = $this->getRequestFilter($request);
        if (0 !== $newness = (int) ($filter['newness'] ?? 0)) {
            if ($newness === 1) {
                $query->having('newness >= ?', 1, \PDO::PARAM_INT);
            } else {
                $query->having('newness = ?', 0, \PDO::PARAM_INT);
            }
        }
    }

    protected function filterByQuantity(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        $quantityFilter = isset($filter['quantity']) && is_array($filter['quantity']) ? $filter['quantity'] : [];
        $quantityMinRaw = $quantityFilter['min'] ?? '';
        $quantityMaxRaw = $quantityFilter['max'] ?? '';

        if ('' !== $quantityMinRaw) {
            $query->having('quantity >= ?', (int) $quantityMinRaw, \PDO::PARAM_INT);
        }

        if ('' !== $quantityMaxRaw) {
            $query->having('quantity <= ?', (int) $quantityMaxRaw, \PDO::PARAM_INT);
        }
    }

    protected function filterByFeature(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        $rawFeatures = $filter['features'] ?? null;
        if (is_array($rawFeatures)) {
            $features = array_map(function ($featureId) {
                return (int) $featureId;
            }, $rawFeatures);
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
        $filter = $this->getRequestFilter($request);
        $rawAttributes = $filter['attributes'] ?? null;
        if (is_array($rawAttributes)) {
            $attributes = array_map(function ($attributeId) {
                return (int) $attributeId;
            }, $rawAttributes);
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
        return (int) ($request->request->get('length') ?? $request->query->get('length'));
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getOffset(Request $request)
    {
        return (int) ($request->request->get('start') ?? $request->query->get('start'));
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getDraw(Request $request)
    {
        return (int) ($request->request->get('draw') ?? $request->query->get('draw'));
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderDir(Request $request)
    {
        $order = $request->request->all('order') ?: $request->query->all('order');
        return (string) ($order[0]['dir'] ?? 'desc') === 'asc' ? Criteria::ASC : Criteria::DESC;
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
                'orm' => ProductTableMap::COL_ID,
                'title' => 'Id',
                'searchable' => false
            ],
            [
                'name' => 'images',
                'targets' => ++$i,
                'orm' => $this->productImageColFile,
                'title' => 'Image',
                'orderable' => true,
                'searchable' => false
            ],
            [
                'name' => 'ref',
                'targets' => ++$i,
                'orm' => ProductTableMap::COL_REF,
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
                'orm' => ProductTableMap::COL_VISIBLE,
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
        $order = $request->request->all('order') ?: $request->query->all('order');
        $columnDefinition = $this->defineColumnsDefinition(true)[
        (int) ($order[0]['column'] ?? 1)
        ];

        return $columnDefinition['orm'];
    }

    protected function applyOrder(Request $request, ProductQuery $query)
    {
        $filter = $this->getRequestFilter($request);
        $sort = $filter['sort'] ?? '';

        if ($sort === 'pse_updated_desc') {
            $query->orderBy('last_pse_updated_at', Criteria::DESC);
            $query->orderBy(ProductTableMap::COL_ID, Criteria::DESC); // tie-breaker stable
            return;
        }

        if ($this->getOrderColumnName($request) === $this->productImageColFile) {
            $query->leftJoinProductImage('product_image')
                ->withColumn('product_image.file', 'image_file')
                ->withColumn('product_image.position', 'image_position');

            $query->withColumn('IF(product_image.file IS NOT NULL AND product_image.file != "", 1, 0)', 'has_image');
            $query->orderBy('has_image', Criteria::DESC);
            $query->orderBy('image_position', Criteria::ASC);
            $query->orderBy('image_file', $this->getOrderDir($request));
        } else {
            $query->orderBy(
                $this->getOrderColumnName($request),
                $this->getOrderDir($request)
            );
        }
    }

    protected function applySearch(Request $request, ProductQuery $query)
    {
        $value = $this->getSearchValue($request);

        if (strlen($value) > 2) {
            // Jointure product sale element
            $query->useProductSaleElementsQuery('pse_search_ref')
                ->endUse();

            $query->where(ProductTableMap::COL_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(ProductI18nTableMap::COL_TITLE . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(' pse_search_ref.ref LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
        }
    }

    protected function getSearchValue(Request $request)
    {
        $search = $request->request->all('search') ?: $request->query->all('search');
        return (string) ($search['value'] ?? '');
    }

    /**
     * @throws \JsonException
     */
    #[Route('/delete-selected', name: '_delete_selected', methods: ['POST'])]
    public function deleteSelectedAction(Request $request, TokenProvider $tokenProvider): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::DELETE)) {
            return $response;
        }

        // Check CSRF token
        $tokenProvider->checkToken(
            (string) $request->query->get('_token')
        );

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
     * @throws \JsonException
     */
    #[Route('/change-visibility-selected', name: '_change_visibility_selected', methods: ['POST'])]
    public function changeVisibilitySelectedAction(Request $request, TokenProvider $tokenProvider): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::PRODUCT, [], AccessManager::UPDATE)) {
            return $response;
        }

        // Check CSRF token
        $tokenProvider->checkToken(
            (string) $request->query->get('_token')
        );

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
