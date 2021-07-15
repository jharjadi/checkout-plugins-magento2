/*browser:true*/
/*global define*/
define([
  "jquery",
  "Magento_Checkout/js/model/quote",
  "Magento_Checkout/js/view/payment/default",
  "mage/url",
  "Magento_Ui/js/model/messageList",
  "Magento_Customer/js/customer-data",
], function ($, quote, Component, mageUrl, globalMessageList, customerData) {
  "use strict";

  return Component.extend({
    defaults: {
      template: "Latitude_Checkout/payment/form",
      transactionResult: "",
    },

    initObservable: function () {
      this._super().observe(["transactionResult"]);

      this.setOptions();

      return this;
    },

    setOptions: function () {
      window.LatitudeCheckout = window.checkoutConfig.payment.latitude.options;

      window.LatitudeCheckout.container = {
        main: "latitude-payment--main",
        footer: "latitude-payment--footer",
      };

      var totals = quote.getTotals()();

      window.LatitudeCheckout.checkout = {
        shippingAmount: totals.base_shipping_amount,
        discount: totals.base_discount_amount,
        taxAmount: totals.base_tax_amount,
        subTotal: totals.base_subtotal,
        total: totals.base_grand_total,
      };

      $.ajax({
        url: window.checkoutConfig.payment.latitude.scriptURL,
        dataType: "script",
        cache: true,
      }).fail(function (xhr, status) {
        console.error("Could not Load latitude content. Failed with " + status);
      });
    },

    getCode: function () {
      return "latitude";
    },

    getContent: function () {
      return window.checkoutConfig.payment.latitude.content;
    },

    getData: function () {
      return {
        method: this.item.method,
        additional_data: {
          transaction_result: this.transactionResult(),
        },
      };
    },

    getTransactionResults: function () {
      return _.map(
        window.checkoutConfig.payment.latitude.transactionResults,
        function (value, key) {
          return {
            value: key,
            transaction_result: value,
          };
        }
      );
    },

    completeOrder: function () {
      var url = mageUrl.build("latitude/payment/process");
      var data = $("#co-shipping-form").serialize();
      var email = window.checkoutConfig.customerData.email;

      var ajaxRedirected = false;

      if (!window.checkoutConfig.quoteData.customer_id) {
        email = document.getElementById("customer-email").value;
      }

      data = data + "&cartId=" + quote.getQuoteId() + "&email=" + email;

      $.ajax({
        url: url,
        method: "post",
        data: data,
        beforeSend: function () {
          $("body").trigger("processStart");
        },
      })
        .done(function (response) {
          var data = response;

          var redirectToPortal = function (paymentRequest) {
            $("body").trigger("processStart");
            window.location.href = paymentRequest["url"]
          };

          if (
            typeof data.success !== "undefined" &&
            typeof data.message !== "undefined" &&
            !data.success
          ) {
            console.error("request failed with " + data.message);
            globalMessageList.addErrorMessage({
              message:
                "We could not process this request at this time, please try again or select other payment method",
            });
            return;
          }

          if (
            typeof data.success !== "undefined" &&
            typeof data.url !== "undefined" &&
            data.success
          ) {
            $("body").ajaxStop(function () {
              ajaxRedirected = true;
              redirectToPortal(data);
            });
            setTimeout(function () {
              if (!ajaxRedirected) {
                redirectToPortal(data);
              }
            }, 5000);

            return;
          }
        })
        .fail(function (xhr, status) {
          console.error("request failed with " + status);
          globalMessageList.addErrorMessage({
            message:
              "We could not process this request at this time, please try again or select other payment method",
          });
        })
        .always(function () {
          customerData.invalidate(["cart"]);
          $("body").trigger("processStop");
        });
    },
  });
});
