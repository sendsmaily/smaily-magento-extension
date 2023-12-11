<?php

namespace Smaily\SmailyForMagento\Helper;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Model\HTTP\ClientFactory as HTTPClientFactory;

class Data
{
    protected $customerGroupRegistry;
    protected $logger;
    protected $request;

    protected $config;
    protected $httpClientFactory;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Customer\Model\GroupRegistry $customerGroupRegistry,
        \Magento\Framework\App\Request\Http $request,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        HTTPClientFactory $httpClientFactory
    ) {
        $this->customerGroupRegistry = $customerGroupRegistry;
        $this->logger = $logger;
        $this->request = $request;

        $this->config = $config;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Verify Google reCAPTCHA response.
     *
     * @param string $challenge Response from Google reCAPTCHA
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function verifyGoogleCaptchaResponse($challenge, $websiteId = null)
    {
        $secret = $this->config->getSubscriberOptInCaptchaSecretKey($websiteId);

        try {
            $response = $this->httpClientFactory->create()
                ->setBaseUrl('https://www.google.com/recaptcha/api')
                ->post('/siteverify', [
                    'secret' => $secret,
                    'response' => $challenge,
                ], false);

            if (isset($response['success']) && $response['success'] === true) {
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return false;
    }

    /**
     * Get Customer Group by ID.
     *
     * @param int $groupId
     * @access public
     * @return string
     */
    public function getCustomerGroupName($groupId)
    {
        try {
            return $this->customerGroupRegistry->retrieve($groupId)->getCode();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return 'Customer';
        }
    }

    /**
     * Get admin store configuration page settings scope.
     *
     * @access public
     * @return int
     */
    public function getConfigurationCurrentWebsiteId()
    {
        return (int) $this->request->getParam('website', 0);
    }

    /**
     * Fetch list of automation workflows from Smaily.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getAutomationWorkflows($websiteId = null)
    {
        try {
            return $this->getSmailyApiClient($websiteId)->get('/api/workflows.php', [
                'trigger_type' => 'form_submitted',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Unable to fetch automation workflows: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Initialize and return an instance of Smaily API client.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return Smaily\SmailyForMagento\Model\HTTP\Client
     */
    public function getSmailyApiClient($websiteId = null)
    {
        $credentials = $this->config->getSmailyApiCredentials($websiteId);
        return $this->httpClientFactory->create()
            ->setBaseUrl("https://{$credentials['subdomain']}.sendsmaily.net")
            ->setCredentials($credentials['username'], $credentials['password']);
    }

    /**
     * Remove trailing zeroes from string.
     * For example 1.1030 -> 1.103 and 1.000 -> 1
     *
     * @param string $value
     * @return string
     */
    public function stripTrailingZeroes($value)
    {
        $trimmed = rtrim($value, '0');

        // Remove the trailing "." if quantity 1.
        if (substr($trimmed, -1) === '.') {
            $trimmed = substr($trimmed, 0, -1);
        }

        return $trimmed;
    }
}
