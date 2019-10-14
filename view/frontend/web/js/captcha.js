require(["jquery", "mage/validation"], function($) {
  "use strict";
  $(document).ready(function($) {
    var newsletterForm = $("#newsletter-validate-detail");
    var submitButton = newsletterForm.find(":submit");
    newsletterForm.mage("validation", {});

    submitButton.click(function(event) {
      var validated = newsletterForm.validation("isValid");
      if (validated) {
        event.preventDefault();
        grecaptcha.execute();
      }
    });

    window.smailyCaptchaSubmit = function(response) {
      newsletterForm.submit();
    };
  });
  return;
});
