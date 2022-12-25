<?php

declare(strict_types=1);

namespace Makey\ExtraItems\Observer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

//use Magento\Sales\Model\Order\ItemFactory;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\Quote\ItemFactory;
use Magento\Sales\Model\Order\Item;

class ExtraItems implements ObserverInterface
{
    //************************************

    protected $_productRepository;
    protected $formKey;
    protected $quoteRepository;
    protected $toOrderItem;
    protected $criteriaBuilder;
    protected $orderRepository;
    protected $productRepository;
    protected $quoteItemFactory;
    protected $orderItemFactory;
    protected $orderResource;
    protected $quoteFactory;
    protected $productFactory;
    protected $cartItemFactory;
    /**
     * @var ItemFactory
     */
    protected $itemFactory;
    /**
     * @var \Magento\Framework\Logger\Monolog
     */
    protected $logger;

    public function __construct (
        \Magento\Sales\Api\OrderRepositoryInterface      $orderRepository,
        \Magento\Checkout\Model\Cart                     $cart,
        \Magento\Framework\Data\Form\FormKey             $formKey,
        \Magento\Quote\Api\CartRepositoryInterface       $quoteRepository,
        \Magento\Quote\Model\Quote\Item\ToOrderItem      $toOrderItem,
        \Magento\Quote\Model\Quote\ItemFactory           $quoteItemFactory,
        \Magento\Sales\Model\Order\ItemFactory           $orderItemFactory,
        \Magento\Sales\Model\ResourceModel\Order         $orderResource,
        LoggerInterface                                  $logger,
        ProductRepositoryInterface                       $productRepository,
        SearchCriteriaBuilder                            $criteriaBuilder,
        \Magento\Catalog\Model\ProductFactory            $productFactory,
        \Magento\Quote\Model\QuoteFactory                $quoteFactory,
        //    \Magento\Backend\App\Action\Context         $context,
        \Magento\Quote\Api\Data\CartItemInterfaceFactory $cartItemFactory
    )
    {
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->formKey = $formKey;
        $this->quoteRepository = $quoteRepository;
        $this->toOrderItem = $toOrderItem;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->orderItemFactory = $orderItemFactory;
        $this->logger = $logger;
        $this->quoteItemFactory = $quoteItemFactory;
        $this->orderResource = $orderResource;
        $this->quoteFactory = $quoteFactory;
        $this->productFactory = $productFactory;
        //parent::__construct($context);
        $this->cartItemFactory = $cartItemFactory;
    }

    /**
     * Execute observer
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute (\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        $stockChangeItems = [];

        foreach ($order->getItems() as $orderItem) {

            $this->logger->info(sprintf("Product sku: - %s", $orderItem->getSku()));

            $product = $orderItem->getProduct();
            $qty = $orderItem->getQtyOrdered();
//
//                // && $mainProductSku = $product->getMainproductsku()
//
            if ($product->getReplacemainproduct()) {
                $this->logger->info(sprintf("Replacing main product with [%s] to the order %s", $product->getMainproductsku(), $order->getIncrementId()));
                $stockChangeItems[] = $this->addOrderItem($product->getMainproductsku(), $orderItem, true, $qty, $order);
                $stockChangeItems[] = array("sku" => $orderItem->getSku(), "qty" => $qty);
                $orderItem->delete();
            }

            if ($product->getHasExtraItems()) {
                $skuList = $product->getExtraItemsList();
                $skus = explode(',', $skuList);
                if (!empty($skuList)) {
                    foreach ($skus as $newSKu) {
                        $this->logger->info(sprintf("Adding Extra product with sku [%s] x %s to the order %s", $newSKu, $qty, $order->getIncrementId()));
                        $stockChangeItems[] = $this->addOrderItem($newSKu, $product, false, $qty, $order);
                    }
                }
            }

//            this->updateStockLevels($stockChangeItems);  // update stocks....

        }
    }

    private function addOrderItem ($newProductSku, $parentProduct, $replace, $qty, $order): array
    {

        $quoteId = $order->getQuoteId();
        $quote = $this->quoteRepository->get($quoteId);
        $newProduct = $this->productRepository->get($newProductSku);

        if ($replace) {
            $price = $parentProduct->getPrice();
            $name = $parentProduct->getName();
            $priceInclTax = $parentProduct->getPriceInclTax();
            $basePriceInclTax = $parentProduct->getBasePriceInclTax();
            $originalPrice = $parentProduct->getOriginalPrice();
            $baseOriginalPrice = $parentProduct->getOriginalPrice();
            $rowTotal = $parentProduct->getRowTotal();
            $baseRowTotal = $parentProduct->getBaseRowTotal();
            $rowTotalInclTax = $parentProduct->getRowTotalInclTax();
            $baseRowTotalInclTax = $parentProduct->getBaseRowTotalInclTax(); //
            $taxPercent = $parentProduct->getTaxPercent();
            $taxAmount = $parentProduct->getTaxAmount();
            $baseTaxAmount = $parentProduct->getBaseTaxAmount();
            $discountPercent = $parentProduct->getDiscountPercent();
            $discountAmount = $parentProduct->getDiscountAmount();
            $baseDiscountAmount = $parentProduct->getBaseDiscountAmount();
            $discountTaxCompensationAmount = $parentProduct->getDiscountTaxCompensationAmount();
            $baseDiscountTaxCompensationAmount = $parentProduct->getBaseDiscountTaxCompensationAmount();
        } else {
            $price = 0;
            $name = (string)__('%1 (Extra for %2)', $newProduct->getName(), $parentProduct->getSku());
            $priceInclTax = 0;
            $basePriceInclTax = 0;
            $originalPrice = $newProduct->getPrice();
            $baseOriginalPrice = $newProduct->getPrice();
            $rowTotal = 0;
            $baseRowTotal = 0;
            $rowTotalInclTax = 0;
            $baseRowTotalInclTax = 0;
            $taxPercent = 0;
            $taxAmount = 0;
            $baseTaxAmount = 0;
            $discountPercent = 0;
            $discountAmount = 0;
            $baseDiscountAmount = 0;
            $discountTaxCompensationAmount = 0;
            $baseDiscountTaxCompensationAmount = 0;
        }

        /*
        [product_options] => Array (
            [info_buyRequest] => Array (
                [qty] => 1.0000
                [options] => Array ()
            )
        )
        */

        try {
            /* Add Quote Item Start */
            $quoteItem = $this->quoteItemFactory->create();
            $quoteItem
                ->setProduct($newProduct)
                ->setCustomPrice($price)
                ->setQty($qty)
                ->setOriginalCustomPrice($originalPrice)
                ->setName($name)
                ->getProduct()->setIsSuperMode(true);

            //$this->logger->info(var_dump($quoteItem, true));

            $quote->addItem($quoteItem);
            $quote->collectTotals()->save();
            /* Add Quote Item End */

            /* Add Order Item Start */
            $orderItem = $this->orderItemFactory->create();
            $orderItem
                ->setStoreId($order->getStoreId())
                ->setQuoteItemId($quoteItem->getId())
                ->setProductId($newProduct->getId())
                ->setProductType($newProduct->getTypeId())
                ->setSku($newProduct->getSku())
                ->setQtyOrdered($qty)
                ->setWeight($newProduct->getWeight())
                ->setRowWeight($newProduct->getWeight() * $qty)
                ->setIsVirtual(0)
                ->setName($name)
                ->setPrice($price)
                ->setBasePrice($price)
                ->setOriginalPrice($originalPrice)
                ->setBaseOriginalPrice($baseOriginalPrice)
                ->setPriceInclTax($priceInclTax)
                ->setBasePriceInclTax($basePriceInclTax)
                ->setRowTotal($rowTotal)
                ->setBaseRowTotal($baseRowTotal)
                ->setRowTotalInclTax($rowTotalInclTax)
                ->setBaseRowTotalInclTax($baseRowTotalInclTax)
                ->setTaxPercent($taxPercent)
                ->setTaxAmount($taxAmount)
                ->setBaseTaxAmount($baseTaxAmount)
                ->setDiscountPercent($discountPercent)
                ->setDiscountAmount($discountAmount)
                ->setBaseDiscountAmount($baseDiscountAmount)
                ->setDiscountTaxCompensationAmount($discountTaxCompensationAmount)
                ->setBaseDiscountTaxCompensationAmount($baseDiscountTaxCompensationAmount);

            $order->addItem($orderItem);
            /* Add Order Item End */

            /* Update relavant order totals Start */
            if (!$replace) {
                $order->setTotalItemCount($order->getTotalItemCount() + $qty);
                $order->setTotalQtyOrdered($order->getTotalQtyOrdered() + $qty);
            }
            $this->orderRepository->save($order);
            /* Update relavant order totals End */
            return array("sku" => $newProduct->getSku(), "qty" => $qty * -1);

        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __($e->getMessage() . " for order {$order->getIncrementId()}")
            );
        }
    }
}