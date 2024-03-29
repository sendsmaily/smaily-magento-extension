define(["jquery", "mage/validation"], function ($) {
    "use strict";

    $.widget('smaily_smailyformagento.google_recaptcha', {
        _create: function () {
            var $form = this.element,
                $container = $form.find('#smaily-smailyformagento-captcha'),
                $submit = $form.find(':submit');

            // Initialize reCAPTCHA.
            var $recaptcha = $('<div>');
            $container.append($recaptcha);

            var widget = grecaptcha.render($recaptcha[0], {
                'sitekey': this.options.key,
                'size': 'invisible',
                'callback': function (token) {
                    if (token) {
                        $form.submit();
                    }
                    else {
                        grecaptcha.reset(widget);
                    }
                }
            });

            // Intercept submit button click event.
            $submit.click(function (ev) {
                ev.preventDefault();

                if ($form.validation('isValid')) {
                    grecaptcha.execute(widget);
                }
            });
        }
    });

    return $.smaily_smailyformagento.google_recaptcha;
});
