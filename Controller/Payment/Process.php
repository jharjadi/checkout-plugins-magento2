<?php

namespace Latitude\Checkout\Controller\Payment;

use \Magento\Framework\Exception\LocalizedException as LocalizedException;

use \Latitude\Checkout\Model\Util\Constants as LatitudeConstants;
use \Latitude\Checkout\Model\Util\Helper as LatitudeHelper;

/**
 * Class Process
 * @package Latitude\Checkout\Controller\Payment
 */
class Process extends \Magento\Framework\App\Action\Action
{
    protected $_checkoutSession;
    protected $_cartRepository;
    protected $_quoteIdMaskFactory;
    protected $_jsonResultFactory;
    protected $_quoteValidator;

    protected $_latitudeHelper;
    protected $_purchaseAdapter;
    protected $_logger;

    const ERROR = "error";
    const MESSAGE = "message";
    const BODY = "body";
    const REDIRECT_URL = "redirectUrl";

    /**
     * Process constructor.
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Quote\Model\QuoteValidator $quoteValidator,
        LatitudeHelper $latitudeHelper,
        \Latitude\Checkout\Model\Adapter\Purchase $purchaseAdapter,
        \Latitude\Checkout\Logger\Logger $logger
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_cartRepository = $cartRepository;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_quoteValidator = $quoteValidator;

        $this->_latitudeHelper = $latitudeHelper;
        $this->_purchaseAdapter = $purchaseAdapter;
        $this->_logger = $logger;

        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $purchaseResponse = $this->_processPurchase();

            if ($purchaseResponse[self::ERROR]) {
                throw new LocalizedException(__($purchaseResponse[self::MESSAGE]));
            }

            $result = $this->_jsonResultFactory->create()->setData([
                "success" => true,
                "url" => $purchaseResponse[self::BODY][self::REDIRECT_URL],
                "platformType" => LatitudeConstants::PLATFORM_TYPE,
                "platformVersion" => $this->_latitudeHelper->getPlatformVersion(),
                "pluginVersion" => $this->_latitudeHelper->getVersion(),
            ]);

            return $result;
        } catch (LocalizedException $le) {
            return $this->_processError($le->getRawMessage());
        } catch (\Exception $e) {
            return $this->_processError($e->getMessage());
        }
    }

    private function _processError($message)
    {
        $this->_logger->error(__METHOD__. $message);

        $result = $this->_jsonResultFactory->create()->setData([
            "success" => false,
            "message" =>  $message,
            "platformType" => LatitudeConstants::PLATFORM_TYPE,
            "platformVersion" => $this->_latitudeHelper->getPlatformVersion(),
            "pluginVersion" => $this->_latitudeHelper->getVersion(),
        ]);

        return $result;
    }

    public function _processPurchase()
    {
        $this->_logger->debug(__METHOD__. " Preparing purchase");
        
        $post = $this->getRequest()->getPostValue();
        $cartId = htmlspecialchars($post['cartId'], ENT_QUOTES);

        if (empty($cartId)) {
            throw new LocalizedException(__("Invalid request"));
        }

        $data = $this->_checkoutSession->getData();
        $quote = $this->_checkoutSession->getQuote();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $customerSession = $objectManager->get('Magento\Customer\Model\Session');
        $customerRepository = $objectManager->get('Magento\Customer\Api\CustomerRepositoryInterface');

        if ($customerSession->isLoggedIn()) {
            $this->_logger->debug(__METHOD__. " Customer checkout");
            $quoteId = $quote->getId();

            $this->_logger->debug(__METHOD__. " cartId:{$cartId}  quoteId:{$quoteId}");

            $customerId = $customerSession->getCustomer()->getId();
            $customer = $customerRepository->getById($customerId);

            // logged in customer
            $quote->assignCustomer($customer);

            $billingAddress  = $quote->getBillingAddress();
            $shippingAddress = $quote->getShippingAddress();

            // validate shipping and billing address
            if ((empty($shippingAddress) || empty($shippingAddress->getStreetLine(1))) && (empty($billingAddress) || empty($billingAddress->getStreetLine(1)))) {

              // virtual products
                if ($quote->isVirtual()) {
                    $billingID =  $customerSession->getCustomer()->getDefaultBilling();
                    $this->_logger->debug("No billing address for the virtual product. Adding the Customer's default billing address.");
                    $address = $objectManager->create('Magento\Customer\Model\Address')->load($billingID);
                    $billingAddress->addData($address->getData());
                } else {
                    throw new LocalizedException(__("Invalid billing address"));
                }
            } elseif (empty($billingAddress) || empty($billingAddress->getStreetLine(1)) || empty($billingAddress->getFirstname())) {
                $billingAddress = $quote->getShippingAddress();
                $quote->setBillingAddress($quote->getShippingAddress());
                $this->_logger->debug("Invalid billing address. Using shipping address instead");

                $billingAddress->addData(array('address_type'=>'billing'));
            }
        } else {
            $this->_logger->debug(__METHOD__. " Guest checkout");

            $quoteIdMask = $this->_quoteIdMaskFactory->create()->load($cartId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();

            $this->_logger->debug(__METHOD__. " cartId:{$cartId}  quoteId:{$quoteId}");

            $quote = $this->_cartRepository->get($quoteId);
            $quote->setCheckoutMethod(LatitudeConstants::METHOD_GUEST);

            if (!empty($post['email'])) {
                $email = htmlspecialchars($post['email'], ENT_QUOTES);
                $email = filter_var($email, FILTER_SANITIZE_EMAIL);

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $quote->setCustomerId(null)
                        ->setCustomerEmail($email)
                        ->setCustomerIsGuest(true)
                        ->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
                }
            }
        }

        $quote->reserveOrderId();

        $quote->getPayment()->setMethod(LatitudeConstants::METHOD_CODE);

        $this->_quoteValidator->validateBeforeSubmit($quote);
        $this->_cartRepository->save($quote);
        $this->_checkoutSession->replaceQuote($quote);

        $this->_logger->info(__METHOD__. " Quote saved. Quote id: {$quoteId}");

        return $this->_purchaseAdapter->process($quote, $quoteId);
    }
}
