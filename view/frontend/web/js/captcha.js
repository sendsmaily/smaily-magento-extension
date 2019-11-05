define(["jquery", "domReady!"], function($) {
  "use strict";

  $.widget("smaily.captcha", {
    _create: function() {
      var self = this,
        error = false,
        element = $(self.element[0]);
      var newsletterBlock = element.find(".block.newsletter");
      var newsletterForm = element.find("#newsletter-validate-detail");
      var actionSection = newsletterForm.find(".actions");

      if (element.find("#smaily-captcha-error").length > 0) {
        error = true;
      }

      if (error) {
        var captchaError = $("#smaily-captcha-error");
        var submitButton = newsletterForm.find(":submit");
        submitButton.prop("disabled", true);
        newsletterBlock.append(captchaError);
      } else {
        var captchaBlock = element.find(".field.captcha.required");
        if (captchaBlock.length > 0) {
          newsletterForm.removeClass("subscribe");
          newsletterBlock.removeClass("newsletter");
          actionSection.before(captchaBlock);
        }
      }
      this.show();
    },

    show: function() {
      var self = this;
      $(self.element[0]).css("visibility", "visible");
    }
  });

  return $.smaily.captcha;
});
