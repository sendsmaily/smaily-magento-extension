<?php

namespace Smaily\SmailyForMagento\Plugin;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Helper\Data;

class Captcha
{
    protected $captchaHelper;
    protected $captchaResolver;
    protected $messageManager;
    protected $redirect;
    protected $request;
    protected $response;
    protected $storeManager;

    protected $config;
    protected $dataHelper;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Captcha\Helper\Data $captchaHelper,
        \Magento\Captcha\Observer\CaptchaStringResolver $captchaResolver,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Response\RedirectInterface $redirect,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        Config $config,
        Data $dataHelper
    ) {
        $this->captchaHelper = $captchaHelper;
        $this->captchaResolver = $captchaResolver;
        $this->messageManager = $messageManager;
        $this->redirect = $redirect;
        $this->request = $request;
        $this->storeManager = $storeManager;

        $this->config = $config;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Run additional logic when Newsletter Subscriber is subscribed.
     *
     * @param \Magento\Newsletter\Controller\Subscriber\NewAction $context
     * @access public
     * @return void
     */
    public function aroundExecute(
        \Magento\Newsletter\Controller\Subscriber\NewAction $context,
        callable $proceed,
        ...$args
    ) {
        $website = $this->storeManager->getWebsite();

        // Validate CAPTCHA, if enabled.
        if ($this->config->isEnabled($website) === true &&
            $this->config->isSubscriberOptInEnabled($website) === true &&
            $this->config->isSubscriberOptInCaptchaEnabled($website) === true
        ) {
            $captchaType = $this->config->getSubscriberOptInCaptchaType($website);

            if ($captchaType === 'google_captcha') {
                $challenge = $this->request->getParam('g-recaptcha-response');

                if ($this->dataHelper->verifyGoogleCaptchaResponse($challenge) === false) {
                    return $this->redirectWithMessage($context, __('Incorrect CAPTCHA.'));
                }
            } elseif ($captchaType === 'magento_captcha') {
                $formId = 'smaily_captcha';
                $challenge = $this->captchaResolver->resolve($this->request, $formId);

                if ($this->captchaHelper->getCaptcha($formId)->isCorrect($challenge) === false) {
                    return $this->redirectWithMessage($context, __('Incorrect CAPTCHA.'));
                }
            }
        }

        // Execute wrapped callback.
        return $proceed(...$args);
    }

    /**
     * Helper method to redirect back to referer with an error message.
     *
     * @param \Magento\Newsletter\Controller\Subscriber\NewAction $context
     * @param string $message
     * @access protected
     * @return void
     */
    protected function redirectWithMessage(\Magento\Newsletter\Controller\Subscriber\NewAction $context, $message)
    {
        $actionFlag = $context->getActionFlag();
        $response = $context->getResponse();

        $this->messageManager->getMessages(true);
        $this->messageManager->addErrorMessage($message);
        $actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);
        $response->setRedirect($this->redirect->getRefererUrl());
    }
}
