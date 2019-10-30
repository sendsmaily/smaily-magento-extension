<?php

namespace Smaily\SmailyForMagento\Observer;

use Magento\Captcha\Observer\CaptchaStringResolver;
use Magento\Captcha\Helper\Data as Helper;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Event\ObserverInterface;
use Smaily\SmailyForMagento\Helper\Data as SmailyHelper;

class NewsletterCaptchaObserver implements ObserverInterface
{

    protected $smailyHelper;
    protected $helper;
    protected $actionFlag;
    protected $messageManager;
    protected $redirect;
    protected $captchaStringResolver;
    private $dataPersistor;
    protected $request;

    public function __construct(
        Helper $helper,
        Http $request,
        ActionFlag $actionFlag,
        ManagerInterface $messageManager,
        RedirectInterface $redirect,
        CaptchaStringResolver $captchaStringResolver,
        SmailyHelper $smailyHelper
    ) {
        $this->smailyHelper = $smailyHelper;
        $this->helper = $helper;
        $this->actionFlag = $actionFlag;
        $this->messageManager = $messageManager;
        $this->redirect = $redirect;
        $this->request = $request;
        $this->captchaStringResolver = $captchaStringResolver;
    }

    /**
     * Check CAPTCHA on Newsletter signup form.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->smailyHelper->shouldCheckCaptcha()) {
            return;
        }

        $captchaType = $this->smailyHelper->getCaptchaType();
        $controller = $observer->getControllerAction();

        if ($captchaType === 'magento_captcha') {
            $formId = 'smaily_captcha';
            $captcha = $this->helper->getCaptcha($formId);
            if (!$captcha->isCorrect($this->captchaStringResolver->resolve($controller->getRequest(), $formId))) {
                $this->messageManager->addError(__('Incorrect CAPTCHA.'));
                $this->getDataPersistor()->set($formId, $controller->getRequest()->getPostValue());
                $this->actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);
                $this->redirect->redirect($controller->getResponse(), $this->redirect->getRedirectUrl());
            }
        } elseif ($captchaType === 'google_captcha') {
            $response = $this->request->getParam('g-recaptcha-response');
            $secretKey = $this->smailyHelper->getCaptchaApiSecretKey();
            $validated = $this->smailyHelper->isCaptchaValid($response, $secretKey);

            if (!$validated) {
                $this->messageManager->addError(__('Incorrect CAPTCHA.'));
                $this->actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);
                $this->redirect->redirect($controller->getResponse(), $this->redirect->getRedirectUrl());
            }
        }
    }

    /**
     * Get Data Persistor
     *
     * @return DataPersistorInterface
     */
    private function getDataPersistor()
    {
        if ($this->dataPersistor === null) {
            $this->dataPersistor = ObjectManager::getInstance()
                ->get(DataPersistorInterface::class);
        }

        return $this->dataPersistor;
    }
}
