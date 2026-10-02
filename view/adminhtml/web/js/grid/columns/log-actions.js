/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

/**
 * Actions column for the unified log grid: the Details action loads the
 * row's drill-down HTML (redacted payload, attempt history, last response)
 * into a slide-out modal instead of navigating away — the grid selection
 * and mass-retry state stay untouched. Every other action of the column
 * (Send again) keeps the stock behaviour.
 *
 * The modal is the design pack's narrow Details panel (PRO-3565): the
 * loaded header (event id, type, status pill) becomes the modal title and
 * the loaded footer (Send again, Copy payload) is pinned below the
 * scrolling body. Magento's modal keeps the keyboard behaviour: focus moves
 * to the close button, Escape closes, focus returns to the row's link.
 */
define([
    'jquery',
    'Magento_Ui/js/grid/columns/actions',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/modal'
], function ($, Actions, $t, confirm) {
    'use strict';

    var $container = null;

    /**
     * The panel's frame: the modal's inner wrapper around header and body.
     *
     * @returns {jQuery}
     */
    function frame() {
        return $container.closest('.modal-inner-wrap');
    }

    /**
     * Show the status line beside the footer buttons.
     *
     * @param {jQuery} $status
     * @param {Boolean} ok
     * @param {String} text
     */
    function showStatus($status, ok, text) {
        $status.removeClass('is-idle is-saved is-error')
            .addClass(ok ? 'is-saved' : 'is-error')
            .text(text);
    }

    /**
     * Copy the redacted payload block's text — exactly what the panel shows.
     *
     * @param {jQuery} $button
     */
    function copyPayload($button) {
        var $panel = $button.closest('.modal-inner-wrap'),
            text = $panel.find('[data-smaily-payload]').first().text(),
            $status = $panel.find('[data-smaily-copy-status]'),
            done = function (ok) {
                showStatus($status, ok, String($button.data(ok ? 'copied' : 'failed')));
            };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }, function () {
                done(fallbackCopy(text));
            });
        } else {
            done(fallbackCopy(text));
        }
    }

    /**
     * execCommand fallback for non-secure contexts (plain-http admin), where
     * navigator.clipboard is unavailable.
     *
     * @param {String} text
     * @returns {Boolean}
     */
    function fallbackCopy(text) {
        var $area = $('<textarea readonly></textarea>').val(text)
                .css({position: 'fixed', top: 0, left: '-9999px'}).appendTo('body'),
            ok;

        $area[0].select();
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        $area.remove();

        return ok;
    }

    /**
     * Ask before sending again, as the grid's own Send again does; the form
     * posts once — its button is disabled the moment it is confirmed.
     *
     * @param {jQuery} $form
     */
    function confirmResend($form) {
        confirm({
            title: String($form.data('confirm-title')),
            content: String($form.data('confirm-message')),
            actions: {
                /** Post the form. */
                confirm: function () {
                    $form.find('button[type="submit"]').prop('disabled', true);
                    $form[0].submit();
                }
            }
        });
    }

    /**
     * Lazily build the single reusable slide-out modal container.
     *
     * @returns {jQuery}
     */
    function modalContainer() {
        if (!$container) {
            $container = $('<div class="smaily-log-details-modal-content"></div>');
            $container.modal({
                type: 'slide',
                title: $t('Delivery details'),
                modalClass: 'smaily-log-details-modal',
                innerScroll: true,
                buttons: []
            });
            frame()
                .on('click', '[data-smaily-copy]', function () {
                    copyPayload($(this));
                })
                .on('submit', '[data-smaily-resend]', function (event) {
                    event.preventDefault();
                    confirmResend($(this));
                });
        }

        return $container;
    }

    /**
     * Put the loaded panel's header and footer into the modal's own header
     * and foot, or reset them while a row loads.
     *
     * @param {jQuery|null} $panel
     */
    function placeChrome($panel) {
        var $title = frame().find('[data-role="title"]').first(),
            $head = $panel ? $panel.find('[data-smaily-details-head]').first() : $(),
            $foot = $panel ? $panel.find('[data-smaily-details-foot]').first() : $();

        frame().children('[data-smaily-details-foot]').remove();
        if ($head.length) {
            $title.empty().append($head);
        } else {
            $title.text($t('Delivery details'));
        }
        if ($foot.length) {
            frame().append($foot);
        }
    }

    return Actions.extend({
        /**
         * Always bind the click handler: the stock column skips it for
         * plain-href actions (native navigation), but Details must open in
         * the modal. The href stays on the anchor for open-in-new-tab.
         *
         * @returns {Boolean}
         */
        isHandlerRequired: function () {
            return true;
        },

        /**
         * Open the Details href in the slide-out modal; leave every other
         * action to the stock callback (confirmation, POST, navigation).
         *
         * @param {String} actionIndex
         * @param {Number} recordId
         * @param {Object} action
         */
        defaultCallback: function (actionIndex, recordId, action) {
            var $modal;

            if (actionIndex !== 'view') {
                return this._super(actionIndex, recordId, action);
            }

            $modal = modalContainer();

            placeChrome(null);
            $modal.html($('<p></p>').text($t('Loading…')));
            $modal.modal('openModal');
            $.get(action.href).always(function (data, textStatus, jqXHR) {
                var html = textStatus === 'success' ? data : (data.responseText || '');

                if (html) {
                    $modal.html(html);
                    placeChrome($modal);
                } else {
                    $modal.html($('<p></p>').text($t('Loading the delivery details failed — please try again.')));
                }
            });
        }
    });
});
