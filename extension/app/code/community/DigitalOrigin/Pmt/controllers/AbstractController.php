<?php

/**
 * Class AbstractController
 */
abstract class AbstractController extends Mage_Core_Controller_Front_Action
{
    /**
     * Concurrency Tablename
     */
    const CONCURRENCY_TABLE = 'pmt_cart_concurrency';

    /**
     * Pmt Orders Tablename
     */
    const PMT_ORDERS_TABLE = 'pmt_orders';

    /**
     * Pmt Orders Tablename
     */
    const PMT_LOGS_TABLE = 'pmt_logs';

    /**
     * PMT_CODE
     */
    const PMT_CODE = 'paylater';

    /**
     * EXCEPTION RESPONSES
     */
    const CC_ERR_MSG = 'Unable to block resource';
    const CC_NO_MERCHANT_ORDERID = 'Merchant Order Id not found';
    const CC_NO_CONFIG = 'Unable to load module configuration';

    const GMO_ERR_MSG = 'Unable to find merchant Order';

    const GPOI_ERR_MSG = 'Pmt Order Not Found';
    const GPOI_NO_ORDERID = 'We can not get the PagaMasTarde identification in database.';

    const GPO_ERR_MSG = 'Unable to get Order';
    const GPO_ERR_TYPEOF = 'The requested PMT Order is not a valid PMTOrder object';

    const COS_ERR_MSG = 'Order status is not authorized';
    const COS_WRONG_STATUS = 'Invalid Pmt status';

    const CMOS_ERR_MSG = 'Merchant Order status is invalid';
    const CMOS_WRONG_CURRENT_STATUS = 'The status of the merchant order is not correct';
    const CMOS_WRONG_PREVIOUS_STATUS = 'Previous merchant status order is not correct';
    const CMOS_PREVIOUSLY_PROCESSED = 'The merchant order has been already processed at least one';

    const VA_ERR_MSG = 'Amount conciliation error';
    const VA_WRONG_AMOUNT = 'Wrong order amount';

    const PMO_ERR_MSG = 'Unknown Error';

    const CPO_ERR_MSG = 'Order not confirmed';
    const CPO_OK_MSG = 'Order confirmed';

    const RMO_OK_MSG = 'Order process rollback successfully';

    /**
     * @var integer $statusCode
     */
    protected $statusCode = 200;

    /**
     * @var string $errorMessage
     */
    protected $errorMessage = '';

    /**
     * @var string $errorDetail
     */
    protected $errorDetail = '';

    /**
     * @var string $headers
     */
    protected $headers;

    /**
     * @var string $format
     */
    protected $format = 'json';

    /**
     * Return a printable response of the request
     *
     * @return Mage_Core_Controller_Response_Http
     */
    public function response($extraOutput = array())
    {
        $response = $this->getResponse();

        $output = array();
        if (!empty($this->errorMessage)) {
            $output['errorMessage'] = $this->errorMessage;
        }
        if (!empty($this->errorDetail)) {
            $output['errorDetail'] = $this->errorDetail;
        }
        if (count($extraOutput)) {
            $output = array_merge($output, $extraOutput);
        }
        if ($this->format == 'json') {
            $output = json_encode($output);
            $response->setHeader('Content-Type', 'application/json');
            $response->setHeader('Content-Length', strlen($output));
        }

        $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
        $response->setHeader($protocol, $this->statusCode, $this->getHttpStatusCode($this->statusCode));
        $response->setBody($output);

        foreach ($this->headers as $key => $value) {
            $response->setHeader($key, $value);
        }
        return $response;
    }

    /**
     * Configure redirection
     *
     * @param bool $error
     *
     * @return Mage_Core_Controller_Varien_Action
     */
    public function redirect($error = true)
    {
        if ($error) {
            return $this->_redirectUrl(Mage::getUrl($this->config['urlKO']));
        }
        return $this->_redirectUrl(Mage::getUrl($this->config['urlOK']));
    }

    /**
     * @param int $statusCode
     * @return string
     */
    public function getHttpStatusCode($statusCode = 200)
    {
        $httpStatusCodes = array(
            200 => "OK",
            201 => "Created",
            202 => "Accepted",
            400 => "Bad Request",
            401 => "Unauthorized",
            402 => "Payment Required",
            403 => "Forbidden",
            404 => "Not Found",
            405 => "Method Not Allowed",
            406 => "Not Acceptable",
            407 => "Proxy Authentication Required",
            408 => "Request Timeout",
            409 => "Conflict",
            429 => "Too Many Requests",
            500 => "Internal Server Error",
        );
        return isset($httpStatusCodes)? $httpStatusCodes[$statusCode] : $httpStatusCodes[200];
    }

    public function saveLog(Exception $exception = null , $data = array())
    {
        try {
            $data = array_merge($data, array(
                'timestamp' => time(),
                'date' => date("Y-m-d H:i:s"),
                'class' => get_class($this),
                'line' => $exception->getLine(),
            ));

            $sql = "INSERT INTO " . self::PMT_LOGS_TABLE . " (`log`) VALUE ('" . json_encode($data) . "')";
            $conn = Mage::getSingleton('core/resource')->getConnection('core_write');
            $conn->query($sql);

        } catch (\Exception $exception) {
            // Do nothing
        }
    }
}