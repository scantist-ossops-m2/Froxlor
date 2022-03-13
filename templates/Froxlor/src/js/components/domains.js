$(document).ready(function () {

	// disable unusable php-configuration by customer settings
	$('#customerid').change(function () {
		var cid = $(this).val();
		$.ajax({
			url: "admin_domains.php?s=" + window.$session + "&page=domains&action=jqGetCustomerPHPConfigs",
			type: "POST",
			data: {
				customerid: cid
			},
			dataType: "json",
			success: function (json) {
				if (json.length > 0) {
					$('#phpsettingid option').each(function () {
						var pid = $(this).val();
						$(this).attr("disabled", "disabled");
						for (var i in json) {
							if (pid == json[i]) {
								$(this).removeAttr("disabled");
							}
						}
					});
				}
			},
			error: function (a, b) {
				console.log(a, b);
			}
		});
	});

	// show warning if speciallogfile option is toggled
	if ($('input[name=speciallogverified]')) {
		$('input[name=speciallogfile]').click(function () {
			$('#speciallogfilenote').remove();
			$('#speciallogfile').removeClass('is-invalid');
			$('#speciallogverified').val(0);
			$.ajax({
				url: "admin_domains.php?s=" + window.$session + "&page=overview&action=jqSpeciallogfileNote",
				type: "POST",
				data: {
					id: $('input[name=id]').val(), newval: +$('#speciallogfile').is(':checked')
				},
				dataType: "json",
				success: function (json) {
					if (json.changed) {
						$('#speciallogfile').addClass('is-invalid');
						$('#speciallogfile').parent().append(json.info);
						$('#speciallogverified').val(1);
					}
				},
				error: function (a, b) {
					console.log(a, b);
				}
			});
		});
	}

});
