<?php

require_once(__DIR__.'/../../../../../../lib/Clearpay/autoload.php');
require_once(__DIR__.'/AbstractController.php');

/**
 * Class Clearpay_Clearpay_NotifyController
 *
 * Now with orders
 */
class Clearpay_Clearpay_NotifyController extends AbstractController
{

    /** Concurrency tablename */
    const CONCURRENCY_TABLENAME = 'clearpay_cart_concurrency';

    /** Seconds to expire a locked request */
    const CONCURRENCY_TIMEOUT = 6;

    /**
     * @var string $merchantOrderId
     */
    protected $merchantOrderId;

    /**
     * @var Mage_Sales_Model_Order $merchantOrder
     */
    protected $merchantOrder;

    /**
     * @var string $clearpayOrderId
     */
    protected $clearpayOrderId;

    /**
     * @var ClearpayModelOrder $clearpayOrder
     */
    protected $clearpayOrder;

    /**
     * @var ClearpayClient $orderClient
     */
    protected $orderClient;

    /**
     * @var mixed $config
     */
    protected $config;

    /**
     * @var string $origin
     */
    protected $origin;

    /**
     * @var string $token
     */
    protected $token;

    /**
     * Cancel order
     *
     * @var bool
     */
    protected $toCancel = false;

    /**
     * Cancel action
     */
    public function cancelAction()
    {
        try {
            $this->toCancel = true;
            $this->prepareVariables();
            $this->getMerchantOrder();
            $this->restoreCart();
            return $this->redirect(true, null, $this->getRequest()->getParam('error_message'));
        } catch (Exception $exception) {
            return $this->redirect(true);
        }
    }

    /**
     * Main action of the controller. Dispatch the Notify process
     *
     * @return Mage_Core_Controller_Varien_Action|void
     * @throws Exception
     */
    public function indexAction()
    {
        $jsonResponse = array();
        try {
            $origin = Mage::app()->getRequest()->getParam('origin');
            if ($origin == 'notification' && $_SERVER['REQUEST_METHOD'] == 'GET') {
                $exception = new \Exception("GET notification is not allowed");
                return $this->cancelProcess($exception);
            }
            $this->checkConcurrency();
            $this->getMerchantOrder();
            $this->getClearpayOrderId();
            $this->getClearpayOrder();
            $checkAlreadyProcessed = $this->checkOrderStatus();
            if ($checkAlreadyProcessed) {
                $this->unblockConcurrency($this->merchantOrderId);
                return $this->redirect(false);
            }
            $this->checkMerchantOrderStatus();
            $this->validateAmount();
            $this->processMerchantOrder();
        } catch (Exception $exception) {
            $this->cancelProcess($exception->getMessage());
        }

        try {
            if (!isset($response)) {
                $this->confirmClearpayOrder();
            }
        } catch (Exception $exception) {
            $this->rollbackMerchantOrder();
            $this->cancelProcess($exception->getMessage());
        }

        try {
            $this->unblockConcurrency($this->merchantOrderId);
        } catch (Exception $exception) {
            // Do nothing
        }

        $error = (!isset($response)) ? false : true;
        return $this->redirect($error);
    }

    /**
     * Find and init variables needed to process payment
     *
     * @throws Exception
     */
    public function prepareVariables()
    {
        $this->merchantOrderId = Mage::app()->getRequest()->getParam('order');
        $this->token = Mage::app()->getRequest()->getParam('token');
        if ($this->merchantOrderId == '') {
            throw new \Exception('No quote found');
        }

        if ($this->token == '') {
            throw new \Exception('Unable to find token parameter on return url');
        }

        $this->origin = ($_SERVER['REQUEST_METHOD'] == 'POST') ? 'Notification' : 'Order';

        try {
            $config = Mage::getStoreConfig('payment/clearpay');
            $extraConfig = Mage::helper('clearpay/ExtraConfig')->getExtraConfig();
            $this->config = array(
                'urlOK' => $extraConfig['URL_OK'],
                'urlKO' => $extraConfig['URL_KO'],
                'publicKey' => $config['clearpay_merchant_id'],
                'privateKey' => $config['clearpay_secret_key'],
            );
        } catch (Exception $exception) {
            throw new \Exception('Unable to load module configuration');
        }
    }

    /**
     * Check the concurrency of the purchase
     *
     * @throws Exception
     */
    public function checkConcurrency()
    {
        $this->prepareVariables();
        $this->unblockConcurrency();
        $this->blockConcurrency($this->merchantOrderId);
    }

    /**
     * Retrieve the merchant order by id
     *
     * @throws Exception
     */
    public function getMerchantOrder()
    {
        try {
            /** @var Mage_Sales_Model_Order $order */
            $this->merchantOrder = Mage::getModel('sales/order')->loadByIncrementId($this->merchantOrderId);
        } catch (Exception $exception) {
            throw new \Exception('Merchant order not found');
        }
    }

    /**
     * Find Clearpay Order Id in AbstractController::CLEARPAY_ORDERS_TABLE
     *
     * @throws Exception
     */
    private function getClearpayOrderId()
    {
        try {
            $model = Mage::getModel('clearpay/order');
            $model->load($this->token, 'token');

            $this->clearpayOrderId = $model->getClearpayOrderId();
            if (is_null($this->clearpayOrderId)) {
                throw new \Exception(
                    'Clearpay Order not found on database table clearpay_order with token'. $this->token
                );
            }
        } catch (Exception $exception) {
            throw new \Exception(
                'Clearpay Order not found on database table clearpay_order with token'. $this->token
            );
        }
    }

    /**
     * Find Clearpay Order in Orders Server using Clearpay\OrdersApiClient
     *
     * @throws Exception
     */
    private function getClearpayOrder()
    {
        $this->orderClient = new ClearpayClient($this->config['publicKey'], $this->config['privateKey']);
        $this->clearpayOrder = $this->orderClient->getOrder($this->clearpayOrderId);
        if (!($this->clearpayOrder instanceof ClearpayModelOrder)) {
            throw new \Exception('Can`t retrieve clearpay order '. $this->clearpayOrderId);
        }
    }

    /**
     * Compare statuses of merchant order and Clearpay order, witch have to be the same.
     *
     * @throws Exception
     */
    public function checkOrderStatus()
    {
        if ($this->clearpayOrder->getStatus() === ClearpayModelOrder::STATUS_CONFIRMED) {
            $jsonResponse = new JsonSuccessResponse();
            $jsonResponse->setMerchantOrderId($this->merchantOrderId);
            $jsonResponse->setClearpayOrderId($this->clearpayOrder->getId());
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $jsonResponse->printResponse();
            } else {
                return true;
            }
        }
        if ($this->clearpayOrder->getStatus() !== ClearpayModelOrder::STATUS_AUTHORIZED) {
            $status = "-";
            if ($this->clearpayOrder instanceof ClearpayModelOrder) {
                $status = $this->clearpayOrder->getStatus();
            }
            throw new \Exception('Wrong status ' . $status);
        }
    }

    /**
     * Check that the merchant order was not previously processes and is ready to be paid
     *
     * @throws Exception
     */
    public function checkMerchantOrderStatus()
    {
        // Check previous status is 'pending_payment'
        $statusHistory = $this->merchantOrder->getAllStatusHistory();
        if (!(is_array($statusHistory) &&  is_object($statusHistory[0]) &&
            $statusHistory[0]->getStatus() == Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)) {
            throw new \Exception('Wrong status on magento order status: '. $statusHistory[0]->getStatus());
        }

        // Order has been processed at least once in the past.
        foreach ($this->merchantOrder->getAllStatusHistory() as $oStatus) {
            if (in_array(
                $oStatus->getStatus(),
                array(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    Mage_Sales_Model_Order::STATE_COMPLETE,
                )
            )
            ) {
                throw new \Exception('Wrong status on magento order history status: '. $oStatus->getStatus());
            }
        }

        // Check current state
        $status = $this->merchantOrder->getStatus();
        if ($status == Mage_Sales_Model_Order::STATE_PROCESSING ||
            $this->merchantOrder->getPayment()->getMethodInstance()->getCode() != self::CLEARPAY_CODE
        ) {
            throw new \Exception('Wrong status: ' . $status);
        }
    }

    /**
     * Check that the merchant order and the order in Clearpay have the same amount to prevent hacking
     *
     * @throws Exception
     */
    public function validateAmount()
    {
        $clearpayAmount = $this->clearpayOrder->getShoppingCart()->getTotalAmount();
        $merchantAmount = (string) floor(100 * $this->merchantOrder->getGrandTotal());
        if ($clearpayAmount != $merchantAmount) {
            throw new \Exception(
                'Mismatch amouunt Clearpay: ' . $clearpayAmount . ' Magento: ' . $merchantAmount
            );
        }
    }

    /**
     * Process the merchant order and notify client
     *
     * @throws Exception
     */
    public function processMerchantOrder()
    {
        try {
            if ($this->merchantOrder->canInvoice()) {
                $invoice = $this->merchantOrder->prepareInvoice();
                if ($invoice->getGrandTotal() > 0) {
                    $invoice
                        ->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE)
                        ->register();
                    $this->merchantOrder->addRelatedObject($invoice);
                    $payment = $this->merchantOrder->getPayment();
                    $payment->setCreatedInvoice($invoice);
                    Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
                }
            }
            $this->merchantOrder->setState(
                Mage_Sales_Model_Order::STATE_PROCESSING,
                Mage_Sales_Model_Order::STATE_PROCESSING,
                'clearpayOrderId: ' . $this->clearpayOrder->getId(). ' ' .
                'clearpayOrderStatus: '. $this->clearpayOrder->getStatus(). ' ' .
                'via: '. $this->origin,
                true
            );
            $this->merchantOrder->save();

            try {
                $this->merchantOrder->sendNewOrderEmail();
            } catch (Exception $exception) {
                // Do nothing
            }
        } catch (Exception $exception) {
            throw new \Exception($exception->getMessage());
        }
    }

    /**
     * Confirm the order in Clearpay
     *
     * @throws Exception
     */
    private function confirmClearpayOrder()
    {
//        try {
//            $this->orderClient->confirmOrder($this->clearpayOrderId);
//        } catch (Exception $exception) {
//            $this->clearpayOrder = $this->orderClient->getOrder($this->clearpayOrderId);
//            if ($this->clearpayOrder->getStatus() !== \Clearpay\OrdersApiClient\Model\Order::STATUS_CONFIRMED) {
//                $this->saveLog($exception);
//                throw new \Exception($exception->getMessage());
//            } else {
//                $logEntry= new \Clearpay\ModuleUtils\Model\Log\LogEntry();
//                $logEntry->info(
//                    'Concurrency issue: Order_id '.$this->clearpayOrderId.' was confirmed by other process'
//                );
//                $model = Mage::getModel('clearpay/log');
//                $model->setData(array('log' => $logEntry->toJson()));
//                $model->save();
//            }
//        }
    }

    /**
     * Leave the merchant order as it was peviously
     *
     * @throws Exception
     */
    public function rollbackMerchantOrder()
    {
            $this->merchantOrder->setState(
                Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
                Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
                'clearpayOrderId: ' . $this->clearpayOrder->getId(). ' ' .
                'clearpayOrderStatus: '. $this->clearpayOrder->getStatus(). ' ' .
                'via: '. $this->origin,
                false
            );
            $this->merchantOrder->save();
    }

    /**
     *  Do all the necessary actions to cancel the confirmation process in case of error
     * 1. Unblock concurrency
     * 2. Restore the cart if possible
     * 3. Save log
     *
     * @param Exception $exception
     * @throws Exception
     */
    public function cancelProcess(Exception $exception)
    {
        $this->unblockConcurrency($this->merchantOrderId);
        $this->restoreCart();
        $this->saveLog($exception);
    }

    /**
     * Restore the cart of the order
     */
    private function restoreCart()
    {
        try {
            if ($this->merchantOrder) {
                $cart = Mage::getSingleton('checkout/cart');
                $items = $this->merchantOrder->getItemsCollection();
                if ($cart->getItemsCount() <= 0) {
                    foreach ($items as $item) {
                        $cart->addOrderItem($item);
                    }
                    $cart->save();
                }
            }
        } catch (Exception $exception) {
            // Do nothing
        }
    }

    /**
     * Lock the concurrency to prevent duplicated inputs
     *
     * @param $orderId
     *
     * @return bool
     * @throws ConcurrencyException
     */
    protected function blockConcurrency($orderId)
    {
        try {
            $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
            $tableName = Mage::getSingleton('core/resource')->getTableName(self::CONCURRENCY_TABLENAME);
            $sql = "INSERT INTO  " . $tableName . "  VALUE ('" . $orderId. "'," . time() . ")";
            $conn->query($sql);
        } catch (Exception $e) {
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                throw new \Exception('Concurrency Exception');
            } else {
                $dbObject = Mage::getSingleton('core/resource')->getConnection('core_write');
                $tableName = Mage::getSingleton('core/resource')->getTableName(self::CONCURRENCY_TABLENAME);
                $query = sprintf(
                    "SELECT TIMESTAMPDIFF(SECOND,NOW()-INTERVAL %s SECOND, FROM_UNIXTIME(timestamp)) as rest FROM %s WHERE %s",
                    self::CONCURRENCY_TIMEOUT,
                    $tableName,
                    "id='$orderId'"
                );
                $resultSeconds = $dbObject->fetchOne($query);
                $restSeconds = isset($resultSeconds) ? ($resultSeconds) : 0;
                $secondsToExpire = ($restSeconds>self::CONCURRENCY_TIMEOUT) ? self::CONCURRENCY_TIMEOUT : $restSeconds;
                sleep($secondsToExpire+1);
                $logMessage = sprintf(
                    "User waiting %s seconds, default seconds %s, bd time to expire %s seconds",
                    $secondsToExpire,
                    self::CONCURRENCY_TIMEOUT,
                    $restSeconds
                );
                $logEntry= new \Clearpay\ModuleUtils\Model\Log\LogEntry();
                $logEntry->info($logMessage);
                $model = Mage::getModel('clearpay/log');
                $model->setData(array('log' => $logEntry->toJson()));
                $model->save();
            }
        }

        return true;
    }

    /**
     * Unlock the concurrency
     *
     * @param null $orderId
     *
     * @return bool
     * @throws ConcurrencyException
     */
    protected function unblockConcurrency($orderId = null)
    {

        try {
            $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
            $tableName = Mage::getSingleton('core/resource')->getTableName(self::CONCURRENCY_TABLENAME);
            if ($orderId == null) {
                $sql = "DELETE FROM " . $tableName . " WHERE timestamp <" .
                    (time() - self::CONCURRENCY_TIMEOUT);
            } else {
                $sql = "DELETE FROM " . $tableName . " WHERE id  ='" . $orderId."'";
            }
            $conn->query($sql);
        } catch (Exception $e) {
            throw new \Exception('Concurrency exception');
        }
        return true;
    }
}
