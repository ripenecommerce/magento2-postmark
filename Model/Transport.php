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
namespace Ripen\Postmark\Model;

use Laminas\Mail\Message as LaminasMessage;
use Laminas\Mail\Headers as LaminasHeaders;

class Transport extends \Magento\Framework\Mail\Transport implements \Magento\Framework\Mail\TransportInterface
{
    /**
     * @var \Magento\Framework\Mail\MailMessageInterface
     */
    protected $message;

    /**
     * @var \Ripen\Postmark\Helper\Data
     */
    protected $helper;

    /**
     * @var \Ripen\Postmark\Model\Transport\Postmark
     */
    protected $transportPostmark;

    /**
     * @param \Ripen\Postmark\Helper\Data $helper
     * @param \Ripen\Postmark\Model\Transport\Postmark $transportPostmark
     * @param \Magento\Framework\Mail\MailMessageInterface $message
     * @param null $parameters
     */
    public function __construct(
        \Ripen\Postmark\Helper\Data $helper,
        \Ripen\Postmark\Model\Transport\Postmark $transportPostmark,
        \Magento\Framework\Mail\MailMessageInterface $message,
        $parameters = null
    ) {
        $this->helper  = $helper;
        $this->transportPostmark = $transportPostmark;

        if ($this->helper->canUse()) {
            $this->message = $message;
        } else {
            parent::__construct($message, $parameters);
        }
    }

    /**
     * Send a mail using this transport
     *
     * @return void
     * @throws \Magento\Framework\Exception\MailException
     */
    public function sendMessage()
    {
        if (! $this->helper->canUse()) {
            parent::sendMessage();
            return;
        }

        try {
            // Create a Laminas\Mail\Message object to pass to Postmark
            $headers = new LaminasHeaders();

            $headersArray = $this->message->getHeaders();
            if (isset($headersArray['To'])) {
                $to = $headersArray['To'];
                unset($headersArray['To']);
            }

            if (isset($headersArray['Subject'])) {
                $subject = $headersArray['Subject'];
                unset($headersArray['Subject']);
            }

            $headers->addHeaders($headersArray);

            $message = new LaminasMessage();
            $message->setHeaders($headers);
            $message->addTo($to);
            $message->setSubject($subject);
            $message->setBody($this->message->getBody());

            $this->transportPostmark->send($message);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\MailException(new \Magento\Framework\Phrase($e->getMessage()), $e);
        }
    }
}
