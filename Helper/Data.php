<?php
/**
 * Postmark integration
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to opensource@ripen.com so we can send you a copy immediately.
 *
 * @category    Ripen
 * @package     Ripen_Postmark
 * @copyright   Copyright (c) SUMO Heavy Industries, LLC
 * @copyright   Copyright (c) Ripen, LLC
 * @notice      The Postmark logo and name are trademarks of Wildbit, LLC
 * @license     http://www.opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */
namespace Ripen\Postmark\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const XML_PATH_ENABLED = 'postmark/settings/enabled';
    const XML_PATH_DEBUG_MODE = 'postmark/settings/debug_mode';
    const XML_PATH_APIKEY = 'postmark/settings/apikey';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $_objectManager;

    /**
     * @var array
     */
    protected $_subscribed;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\ObjectManagerInterface
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
        $this->_logger = $logger;
        $this->_objectManager = $objectManager;
        $this->_subscribed = array();
        parent::__construct($context);
    }

    /**
     * @param null $store
     * @return mixed
     */
    public function isEnabled($store = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * @param null $store
     * @return mixed
     */
    public function getApiKey($store = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_APIKEY,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * @param null $store
     * @return bool
     */
    public function isDebugMode($store = null)
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_DEBUG_MODE,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * @param null $store
     * @return boolean
     */
    public function canUse($store = null)
    {
        return $this->isEnabled($store) && $this->getApiKey($store);
    }

    /**
     * @param $msg
     */
    public function log($msg, $level = \Psr\Log\LogLevel::INFO)
    {
        $this->_logger->log($level, $msg);
    }
}
