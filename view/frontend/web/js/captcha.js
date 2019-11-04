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

      if (this.options.reCaptcha) {
        this.showNewsletter();
        return;
      }

      if ($(self.element[0]).find("#smaily-captcha-error").length > 0) {
        error = true;
      }

      if (error) {
        var captchaError = $("#smaily-captcha-error");
        actionSection.before(captchaError);
        this.showNewsletter();
      } else {
        var captchaBlock = element.find(".field.captcha.required");
        newsletterForm.removeClass("subscribe");
        newsletterBlock.removeClass("newsletter");
        actionSection.before(captchaBlock);
        this.showNewsletter();
      }
    },

    showNewsletter: function() {
      var self = this;
      $(self.element[0]).css("visibility", "visible");
    }
  });

  return $.smaily.captcha;
});
