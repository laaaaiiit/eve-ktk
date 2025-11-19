// vim: syntax=javascript tabstop=4 softtabstop=0 noexpandtab laststatus=1 ruler

/**
 * html/themes/default/js/messages_ru.js
 *
 * Russian locale placeholder that falls back to the existing English strings.
 * This keeps the legacy UI functional even when LANG=ru is requested.
 */

if (typeof MESSAGES === 'undefined') {
	var MESSAGES = [];
}

// The English bundle is already loaded, so simply reuse it.
if (typeof window !== 'undefined' && window.MESSAGES) {
	MESSAGES = window.MESSAGES.slice();
}

// Provide a flag to let the UI know we loaded the RU bundle.
window.MESSAGES_LANG = 'ru';
