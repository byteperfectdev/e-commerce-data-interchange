/* global ediAdmin */
jQuery(document).ready($ => {
	const $status = $('#wp-admin-bar-edi-status')
	const $interrupt = $('#wp-admin-bar-edi-interrupt')

	$interrupt.on('click', () => {
		$interrupt.hide()
		$.get(ediAdmin.ajaxUrl, { 'action': 'edi_interrupt', '_wpnonce': ediAdmin.nonce }, responce => {
			if (!responce.success) {
				alert('An error occurred interrupting the synchronization process.')
				$interrupt.show()
			}
		})
	})

	const get_status = () => {
		$.get(ediAdmin.ajaxUrl, { action: 'edi_get_status' }, responce => {
			console.log('status', responce)

			$interrupt.toggle(!responce.data.interrupting)

			if (responce.data.status) {
				$status.show()

				$('div', $status).text(responce.data.status)
			} else {
				$status.hide()
				$interrupt.hide()
			}

			setTimeout(get_status, 30000)
		})
	}

	get_status()
})
