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
namespace Ripen\Postmark\Model\Transport;

use Psr\Log\LogLevel;
use Ripen\Postmark\Model\Transport\Exception as PostmarkTransportException;
use Zend\Mime\Mime;

class Postmark implements \Zend\Mail\Transport\TransportInterface
{
    /**
     * Postmark API Uri
     */
    const API_URI = 'https://api.postmarkapp.com/';

    /**
     * Limit of recipients per message in total.
     */
    const RECIPIENTS_LIMIT = 20;

    /**
     * Postmark API key
     *
     * @var string
     */
    protected $apiKey = null;

    /**
     * @var \Ripen\Postmark\Helper\Data
     */
    protected $helper;

    /**
     * @param \Ripen\Postmark\Helper\Data $helper
     * @throws \Ripen\Postmark\Model\Transport\Exception
     */
    public function __construct(
        \Ripen\Postmark\Helper\Data $helper
    ) {
        $this->helper = $helper;

        $apiKey = $this->helper->getApiKey();
        if (empty($apiKey)) {
            throw new PostmarkTransportException(__CLASS__ . ' requires API key');
        }
        $this->apiKey = $apiKey;
    }

    /**
     * Send request to Postmark service
     *
     * @link http://developer.postmarkapp.com/developer-build.html
     * @param \Zend\Mail\Message $message
     * @return void
     * @throws \Ripen\Postmark\Model\Transport\Exception
     */
    public function send(\Zend\Mail\Message $message)
    {
        $recipients = $this->getRecipients($message);
        $bodyVersions = $this->getBody($message);

        $data = $recipients + [
            'From' => $this->getFrom($message),
            'Subject' => $this->getSubject($message),
            'ReplyTo' => $this->getReplyTo($message),
            'HtmlBody' => $bodyVersions[Mime::TYPE_HTML],
            'TextBody' => $bodyVersions[Mime::TYPE_TEXT],
            'Attachments' => $this->getAttachments($message),
            'Tag' => $this->getTags($message),
        ];

        $errorMessage = null;
        try {
            $response = $this->prepareHttpClient('/email')
                ->setMethod(\Zend\Http\Request::METHOD_POST)
                ->setRawBody(json_encode($data))
                ->send();
            $this->parseResponse($response);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            throw $e;
        } finally {
            if ($this->helper->isDebugMode()) {
                $debugData = json_encode(array_intersect_key($data, array_flip(['From', 'Subject', 'ReplyTo', 'Tag'])));
                $debugStatus = $errorMessage ? "failed to send with error '$errorMessage'" : 'sent';
                $this->helper->log("Postmark email $debugStatus: $debugData", LogLevel::DEBUG);
            }
        }
    }

    /**
     * Get a HTTP client instance
     *
     * @param string $path
     * @return \Zend\Http\Client
     */
    protected function prepareHttpClient($path)
    {
        return $this->getHttpClient()->setUri(self::API_URI . $path);
    }

    /**
     * Returns a HTTP client object
     *
     * @return \Zend\Http\Client
     */
    public function getHttpClient()
    {
        $client = new \Zend\Http\Client();
        $client->setHeaders([
            'Accept' => 'application/json',
            'X-Postmark-Server-Token' => $this->apiKey
        ]);

        return $client;
    }

    /**
     * Parse response object and check for errors
     *
     * @param \Zend\Http\Response $response
     * @return array
     * @throws \Ripen\Postmark\Model\Transport\Exception
     */
    protected function parseResponse(\Zend\Http\Response $response)
    {
        $result = json_decode($response->getBody(), true);

        if ($response->isClientError()) {
            switch ($response->getStatusCode()) {
                case 401:
                    throw new PostmarkTransportException('Postmark request error: Unauthorized - Missing or incorrect API Key header.');
                    break;
                case 422:
                    $errorCode = isset($result['ErrorCode']) ? $result['ErrorCode'] : 'Unknown';
                    $errorMessage = isset($result['Message']) ? $result['Message'] : 'Unknown';
                    throw new PostmarkTransportException(sprintf('Postmark request error: Unprocessable Entity - API error code %s, message: %s', $errorCode, $errorMessage));
                    break;
                case 500:
                    throw new PostmarkTransportException('Postmark request error: Postmark Internal Server Error');
                    break;
                default:
                    throw new PostmarkTransportException('Unknown error during request to Postmark server');
            }
        }

        if (! is_array($result)) {
            throw new PostmarkTransportException('Unexpected value returned from server');
        }
        return $result;
    }

    /**
     * Get mail From
     *
     * @param \Zend\Mail\Message $message
     * @return string|null
     */
    public function getFrom(\Zend\Mail\Message $message)
    {
        $sender = $message->getSender();
        if ($sender instanceof \Zend\Mail\Address\AddressInterface) {
            $name = $sender->getName();
            $address = $sender->getEmail();
        } else {
            $from = $message->getFrom();
            if (count($from)) {
                $name = $from->rewind()->getName();
                $address = $from->rewind()->getEmail();
            }
        }

        if (empty($address)) throw new PostmarkTransportException('No from address specified');

        return empty($name) ? $address : "$name <$address>";
    }

    /**
     * Get mail recipients (To, Cc, and Bcc)
     *
     * @param \Zend\Mail\Message $message
     * @return array
     * @throws \Ripen\Postmark\Model\Transport\Exception
     */
    public function getRecipients(\Zend\Mail\Message $message)
    {
        $recipients = [
            'To' => $this->addressListToArray($message->getTo()),
            'Cc' => $this->addressListToArray($message->getCc()),
            'Bcc' => $this->addressListToArray($message->getBcc())
        ];

        $totalRecipients = array_sum(array_map('count', $recipients));

        if ($totalRecipients === 0) {
            throw new PostmarkTransportException(
                'Invalid email: must contain at least one of "To", "Cc", and "Bcc" headers'
            );
        }

        if ($totalRecipients > self::RECIPIENTS_LIMIT) {
            throw new PostmarkTransportException(
                'Exceeded Postmark recipients limit per message'
            );
        }

        return array_map(function ($addresses) { return implode(',', $addresses); }, $recipients);
    }

    /**
     * Convert address list to simple array
     *
     * @param \Zend\Mail\AddressList $addressList
     * @return array
     */
    protected function addressListToArray(\Zend\Mail\AddressList $addressList)
    {
        $addresses = [];
        foreach ($addressList as $address) {
            $addresses[] = $address->getEmail();
        }
        return $addresses;
    }

    /**
     * Get mail Reply To
     *
     * @param \Zend\Mail\Message $message
     * @return string
     */
    public function getReplyTo(\Zend\Mail\Message $message)
    {
        $addresses = $message->getReplyTo();

        $replyTo = [];
        foreach ($addresses as $address) {
            $replyTo[] = $address->getEmail();
        }

        return implode(',', $replyTo);
    }

    /**
     * Get mail subject
     *
     * @param \Zend\Mail\Message $message
     * @return string
     */
    public function getSubject(\Zend\Mail\Message $message)
    {
        /** @var \Zend\Mail\Header\Subject $subjectHeader */
        $subjectHeader = $message->getHeaders()->get('Subject');

        if (! $subjectHeader) {
            return '';
        }

        return $subjectHeader->getFieldValue();
    }

    /**
     * @param \Zend\Mail\Message $message
     * @return array
     * @throws \Ripen\Postmark\Model\Transport\Exception
     */
    public function getBody(\Zend\Mail\Message $message)
    {
        $bodyVersions = [
            Mime::TYPE_HTML => '',
            Mime::TYPE_TEXT => ''
        ];

        $body = $message->getBody();
        if ($body instanceof \Zend\Mime\Message) {
            $parts = $message->getBody()->getParts();
            foreach ($parts as $part) {
                if ($part->getType() == Mime::TYPE_HTML || $part->getType() == Mime::TYPE_TEXT) {
                    $bodyVersions[$part->getType()] = $part->getRawContent();
                }
            }
        } else {
            /** @var \Zend\Mail\Header\ContentType $contentTypeHeader */
            $contentTypeHeader = $message->getHeaders()->get('ContentType');
            $contentType = $contentTypeHeader ? $contentTypeHeader->getType() : Mime::TYPE_TEXT;
            $bodyVersions[$contentType] = (string) $body;
        }

        if (empty($bodyVersions[Mime::TYPE_HTML]) && empty($bodyVersions[Mime::TYPE_TEXT])) {
            throw new PostmarkTransportException('No body specified');
        }

        return $bodyVersions;
    }

    /**
     * Get mail Tag
     *
     * @return string
     */
    public function getTags(\Zend\Mail\Message $message)
    {
        $headers = $message->getHeaders();

        $tagsHeaders = $headers->get('Postmark-Tag');

        if (! is_array($tagsHeaders)) $tagsHeaders = [];

        $tags = [];
        /** @var \Zend\Mail\Header\GenericHeader $tagsHeader */
        foreach ($tagsHeaders as $tagsHeader) {
            $tags[] = $tagsHeader->getFieldValue();
        }
        return implode(',', $tags);
    }

    /**
     * Get mail Attachments
     *
     * @param \Zend\Mail\Message $message
     * @return array
     */
    public function getAttachments(\Zend\Mail\Message $message)
    {
        $body = $message->getBody();
        if (! $body instanceof \Zend\Mime\Message) return [];

        $attachments = [];
        $parts = $message->getBody()->getParts();
        foreach ($parts as $part) {
            if ($part->getType() !== Mime::TYPE_TEXT && $part->getType() !== Mime::TYPE_HTML) {
                $part->setEncoding(\Laminas\Mime\Mime::ENCODING_BASE64);
                $attachments[] = [
                    'ContentType' => $part->getType(),
                    'Name' => $part->getFileName(),
                    'Content' => $part->getContent()
                ];
            }
        }
        return $attachments;
    }
}
