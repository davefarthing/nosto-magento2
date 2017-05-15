<?php
/**
 * Copyright (c) 2017, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2017 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Tagging\Observer\Stock;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableProduct;
use Magento\Framework\Module\Manager as ModuleManager;
use Nosto\Operation\UpsertProduct;
use Nosto\Tagging\Helper\Account as NostoHelperAccount;
use Nosto\Tagging\Helper\Data as NostoHelperData;
use Nosto\Tagging\Helper\Scope as NostoHelperScope;
use Nosto\Tagging\Model\Product\Builder as NostoProductBuilder;
use Psr\Log\LoggerInterface;


/**
 * Upsert event observer model.
 * Used to interact with Magento events.
 *
 * @category Nosto
 * @package  Nosto_Tagging
 * @author   Nosto Solutions Ltd <magento@nosto.com>
 */
class Update implements ObserverInterface
{

    private $nostoHelperData;
    private $nostoHelperAccount;
    private $nostoProductBuilder;
    private $logger;
    private $moduleManager;
    private $productFactory;
    private $configurableProduct;
    private $nostoHelperScope;

    /**
     * Constructor.
     *
     * @param NostoHelperData $nostoHelperData
     * @param NostoHelperAccount $nostoHelperAccount
     * @param NostoProductBuilder $nostoProductBuilder
     * @param NostoHelperScope $nostoHelperScope
     * @param LoggerInterface $logger
     * @param ModuleManager $moduleManager
     * @param ProductFactory $productFactory
     * @param ConfigurableProduct $configurableProduct
     */
    public function __construct(
        NostoHelperData $nostoHelperData,
        NostoHelperAccount $nostoHelperAccount,
        NostoProductBuilder $nostoProductBuilder,
        NostoHelperScope $nostoHelperScope,
        LoggerInterface $logger,
        ModuleManager $moduleManager,
        ProductFactory $productFactory,
        ConfigurableProduct $configurableProduct
    ) {
        $this->nostoHelperData = $nostoHelperData;
        $this->nostoHelperAccount = $nostoHelperAccount;
        $this->nostoProductBuilder = $nostoProductBuilder;
        $this->logger = $logger;
        $this->moduleManager = $moduleManager;
        $this->productFactory = $productFactory;
        $this->configurableProduct = $configurableProduct;

        $this->nostoHelperScope = $nostoHelperScope;
    }

    public function execute(Observer $observer)
    {
        if ($this->moduleManager->isEnabled(NostoHelperData::MODULE_NAME)) {
            /* @var Magento\CatalogInventory\Model\Stock\Item $stockItem */
            $stockItem = $observer->getItem();
            $product = $this->productFactory->create()->load(
                (int)$stockItem->getProductId()
            );
            $mismatch = false;
            if (!$product->isAvailable() && $stockItem->getIsInStock()) {
                $product->setData('is_salable', 1);
                $mismatch = true;
            } elseif ($product->isAvailable() && !$stockItem->getIsInStock()) {
                $product->setData('is_salable', 0);
                $mismatch = true;
            }
            if ($mismatch) {
                foreach ($product->getStoreIds() as $storeId) {
                    $store = $this->nostoHelperScope->getStore($storeId);
                    $account = $this->nostoHelperAccount->findAccount($store);
                    if ($account === null) {
                        continue;
                    }
                    if (!$this->nostoHelperData->isProductUpdatesEnabled(
                        $store
                    )) {
                        continue;
                    }
                    // Load the product model for this particular store view.
                    $metaProduct = $this->nostoProductBuilder->build(
                        $product,
                        $store
                    );
                    if ($metaProduct === null) {
                        continue;
                    }
                    try {
                        $op = new UpsertProduct($account);
                        $op->addProduct($metaProduct);
                        $op->upsert();
                    } catch (NostoException $e) {
                        $this->logger->error($e->__toString());
                    }
                }
            }
        }
    }
}
