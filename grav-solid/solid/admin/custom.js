/**
 * Ici on va patcher le bug du CodeMirror dans les fieldset.
 * Lorsqu'on init la page de l'admin avec un fieldset fermé
 * et que ce fieldset contient un CodeMirror, ce dernier n'affiche
 * pas son contenu tant que l'utilisateur ne l'utilise pas.
 */

// Ecouter l'ouverture des fieldsets
$(document).on('click', '.form-fieldset--label', function () {
	// Cibler le fieldset parent
	var $fieldset = $(this).parents('.form-fieldset').eq(0);

	// Trouver les CodeMirror de ce fieldset
	$fieldset.find('.CodeMirror').each(function (i, el) {
		// Attendre que le panel s'ouvre
		window.setTimeout(function () {
			// Actualiser ses dimensions, ça va forcer l'update
			el.CodeMirror.setSize(
				$(el).width(),
				$(el).height()
			);
		}, 10);
	});
});

// Bloquer l'envoi de formulaire avec la touche entrée depuis tous les inputs
$(document).on('keydown', 'input', function (event) {
	if (
		// Bloquer la touche entrer, sauf si touche CMD est pressée
		event.keyCode === 13 && !event.metaKey

		// Bloquer uniquement sur l'admin page (non les popin ou le login)
		&& $(event.currentTarget).parents('.admin-pages').length === 1
	) {
		console.log('Form submit prevented by custom.js.');
		event.preventDefault();
		event.stopPropagation();
	}
});

/**
 * Here we patch saving empty lists when saving theme config in admin.
 * https://github.com/getgrav/grav/issues/2789
 */
$(document).ready(function ()
{
	// Target theme blueprints
	// TODO : Maybe check for any blueprint that is not a page
	var $blueprintForm = $('.gpm.gpm-themes form#blueprints').eq(0);

	// Get list of list fields that are not empty
	var listToEmpty = {};
	var serializedBlueprint = $blueprintForm.serializeArray();
	serializedBlueprint.map( function (field)
	{
		// A list field that is not empty can be identified by its name
		// containing a '[0]'
		var indexOf0 = field.name.indexOf('[0]');
		if (indexOf0 >= 0)
		{
			var fieldID = field.name.substr(0, indexOf0);
			listToEmpty[fieldID] = true;
		}
	});

	// Hook submit even on form
	// Only once because we will resubmit it after patching fields
	$blueprintForm.one('submit', function (event)
	{
		// Browse all list fields that were not empty
		var serializedBlueprint = $blueprintForm.serializeArray();
		Object.keys( listToEmpty ).map( function (fieldToCheck)
		{
			// Check if this list field has been emptied
			var found = false;
			serializedBlueprint.map( function (formField)
			{
				if (formField.name.indexOf(fieldToCheck) === 0) found = true;
			});

			// If it has been emptied, we add a input representing the empty list
			if (found) return;
			$blueprintForm.append($("<input />").attr({
				type: 'hidden',
				name: fieldToCheck,
				value: ''
			}));
		});
	});
});
