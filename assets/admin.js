(function ($) {
	'use strict';
	function post(action, data, $msg) {
		data = data || {};
		data.action = action;
		data.nonce = (window.orltAdmin && orltAdmin.nonce) || '';
		return $.post((window.orltAdmin && orltAdmin.ajax) || ajaxurl, data).fail(function () {
			if ($msg) $msg.text((orltAdmin.i18n && orltAdmin.i18n.failed) || 'Failed');
		});
	}

	// Settings: show only the selected SMS provider's fields (all stay in the form).
	function syncProvider() {
		var $select = $('#orlt_sms_provider');
		if (!$select.length) return;
		var id = $select.val();
		$('.orlt-provider-fields').each(function () {
			this.hidden = $(this).attr('data-provider') !== id;
		});
	}
	$(document).on('change', '#orlt_sms_provider', syncProvider);
	$(syncProvider);

	$(document).on('click', '#orlt-test-connection', function () {
		var $btn = $(this);
		var $msg = $('#orlt-test-msg');
		$btn.prop('disabled', true);
		$msg.text(orltAdmin.i18n.testing);
		post('orlt_test_connection', {}).done(function (res) {
			if (res && res.success) {
				$msg.text(res.data.friendly_name || 'OK');
			} else {
				$msg.text((res && res.data && (res.data.error || res.data.message)) || 'Failed');
			}
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#orlt-test-sms', function () {
		var $btn = $(this);
		var $msg = $('#orlt-test-sms-msg');
		$btn.prop('disabled', true);
		$msg.text(orltAdmin.i18n.sending);
		post('orlt_test_sms', { phone: $('#orlt-test-sms-phone').val() }, $msg).done(function (res) {
			$msg.text((res && res.data && res.data.message) || (res && res.success ? 'OK' : 'Failed'));
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '.orlt-use-tpl', function () {
		var $box = $(this).closest('.orlt-order-box');
		// Restore the saved Ready for Pickup template (rendered into data-tpl). It used to be captured
		// on the first click, i.e. after the owner had already edited the box, so it restored their text.
		var $ta = $box.find('.orlt-sms-body');
		var tpl = $ta.attr('data-tpl');
		if (typeof tpl === 'string' && tpl !== '') {
			$ta.val(tpl).trigger('input');
		}
	});

	$(document).on('click', '.orlt-send-sms', function () {
		var $btn = $(this);
		var $box = $btn.closest('.orlt-order-box');
		var $msg = $box.find('.orlt-sms-msg');
		var body = $box.find('.orlt-sms-body').val();
		var orderId = $box.data('order');
		function send(force) {
			$btn.prop('disabled', true);
			$msg.text(orltAdmin.i18n.sending);
			post('orlt_send_sms', { order_id: orderId, body: body, force: force ? 1 : 0 }).done(function (res) {
				if (res && res.success) {
					$msg.text(res.data.message || 'Sent');
					return;
				}
				var code = res && res.data && res.data.code;
				var err = (res && res.data && res.data.message) || 'Failed';
				if (code === 'needs_force' && window.confirm(orltAdmin.i18n.force)) {
					send(true);
					return;
				}
				$msg.text(err);
			}).always(function () {
				$btn.prop('disabled', false);
			});
		}
		send(false);
	});
})(jQuery);
