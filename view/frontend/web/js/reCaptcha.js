define(["jquery", "mage/validation"], function($) {
  "use strict";

  $.widget("smaily.recaptcha", {
    captchaID: null,
    _create: function() {
      var self = this;
      var newsletterForm = $(this.element[0]);
      var submitButton = newsletterForm.find(":submit");

      var captchaDiv = $('<div class="g-recaptcha"></div>');
      captchaDiv.attr("id", this.options.id);
      newsletterForm.append(captchaDiv);

      self.captchaID = grecaptcha.render(this.options.id, {
        sitekey: this.options.key,
        size: "invisible",
        callback: "smailyCaptchaSubmit"
      });

      submitButton.click(function(event) {
        var validated = newsletterForm.validation("isValid");
        if (validated) {
          event.preventDefault();
          grecaptcha.execute(self.captchaID);
        }
      });

      window.smailyCaptchaSubmit = function(token) {
        if (token) {
          newsletterForm.submit();
        } else {
          grecaptcha.reset(self.captchaID);
        }
      };
    }
  });

  return $.smaily.recaptcha;
});
