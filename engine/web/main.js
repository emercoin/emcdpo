$(document).ready(function() {
	$('#public-code').keyup(function() {
		if ($('body').hasClass('yellow-bg')) {
			if ($(this).val().length > 0)
				$('#submit').removeClass('btn-black-inactive').addClass('btn-black-active').removeAttr('disabled')
			else
				$('#submit').removeClass('btn-black-active').addClass('btn-black-inactive').attr('disabled', 'disabled')
		} else {
			if ($(this).val().length > 0)
				$('#submit').removeClass('btn-pink-inactive').addClass('btn-pink-active').removeAttr('disabled')
			else
				$('#submit').removeClass('btn-pink-active').addClass('btn-pink-inactive').attr('disabled', 'disabled')
		}
	})

	$('#form-pubcode').submit(function(event) {
	        event.preventDefault()

		$.get('check_key/' + $('#public-code').val(), function(response){
			 if (response == 'false') {
				$('.error').show()
				$('body').addClass('yellow-bg')
				$('.logo').addClass('logo-black')
				$('h1').removeClass('pink').addClass('black')
				$('.txt-input').removeClass('txt-pink').addClass('txt-gray')
				$('#submit').removeClass('btn-pink-active').addClass('btn-black-inactive').attr('disabled', 'disabled')
				$('#public-code').val('')
			} else {
				var otp = $('input[name=otp]').val()
				window.location.href = 'key/' + $('#public-code').val() + (otp.length > 0 ? '?otp=' + otp : '')
			}
		}, 'text')
	})

	$('#activate-item').submit(function(event){
		if ($('input[name=password]').val().length == 0 && !$(this).data('activate')) {
		        event.preventDefault()
			$('#message').show(10)
		}
	})

	$('.hide-pwd').click(function(){
		if ($(this).html() == 'visibility_off') {
			$(this).html('visibility')
			$(this).parent('.txt-input').find('input').attr('type', 'text').focus()
		} else {
			$(this).html('visibility_off')
			$(this).parent('.txt-input').find('input').attr('type', 'password').focus()
		}
	})

	var h = $('.mobile-description').height()
	var w = $('.mobile-preview img').width()

	$('.mobile-green-bg').height((h - w * 0.3) + 'px')
	$('.mobile-blue-bg').height((h - w * 0.3) + 'px')

	$('.popup-link').click(function(){
		$($(this).data('popup')).show(10)
                var rowsHeight = 0

                $.each($($(this).data('popup')).find('.row'), function(i,row) {
                         rowsHeight += $(row).height()
                })

		var wh = $(window).height()
		var h2 = $($(this).data('popup')).find('h2').height()

                if (rowsHeight >= 0.6 * wh) {
                    $($(this).data('popup')).find('.popup-window').css('height', '60vh')
                } else {
		    $($(this).data('popup')).find('.popup-window').css('height', parseInt(100 * (rowsHeight + h2 + 105) / wh) + 'vh')
		}
	})

	$('.popup-window .close').click(function(){
		$(this).parents('.popup-overlay').hide(10)
	})

	$('#activate-anyway').click(function(){
		$('#activate-item').data('activate', true).submit()
	})
})
