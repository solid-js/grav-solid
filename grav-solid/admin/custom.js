/**
 * Ici on va patcher le bug du CodeMirror dans les fieldset.
 * Lorsqu'on init la page de l'admin avec un fieldset fermé
 * et que ce fieldset contient un CodeMirror, ce dernier n'affiche
 * pas son contenu tant que l'utilisateur ne l'utilise pas.
 */

// Ecouter l'ouverture des fieldsets
$(document).on('click', '.form-fieldset--label', function ()
{
	// Cibler le fieldset parent
	var $fieldset = $(this).parents('.form-fieldset').eq(0);

	// Trouver les CodeMirror de ce fieldset
	$fieldset.find('.CodeMirror').each(function (i, el)
	{
		// Attendre que le panel s'ouvre
		window.setTimeout(function ()
		{
			// Actualiser ses dimensions, ça va forcer l'update
			el.CodeMirror.setSize(
				$(el).width(),
				$(el).height()
			);
		}, 10);
	});
});

// Bloquer l'envoi de formulaire avec la touche entrée depuis tous les inputs
$(document).on('keydown', 'input', function (pEvent)
{
	// Touche entrée
	if (pEvent.keyCode === 13)
	{
		console.log('Form submit prevented by custom.js.');

		pEvent.preventDefault();
		pEvent.stopImmediatePropagation();
		pEvent.stopPropagation();
	}
});