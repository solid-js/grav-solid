$( function ()
{
	// Type selector of input radio
	var radioSelector = '.TypeSelector > input[type=radio]';

	// All registered remote selectors
	var remoteSelectors = [];

	/**
	 * Set visibility of a fieldset.
	 * @param element Element of the fieldset to apply visibility
	 * @param selected If the element is selected (visible)
	 */
	function setFieldsetVisibility ( element, selected )
	{
		// Hide all fieldset element
		$( element ).css({
			display: selected ? 'block' : 'none',
			// We reset height and visibility
			visibility: 'visible',
			height: 'auto'
		})
		// Disable all inputs into this fieldset to omit useless data
		.find('input, textarea, button, select').attr(
			'disabled', selected ? null : 'disabled'
		);
	}

	/**
	 * Update selected type state for a toggle element.
	 * @param $toggleElement
	 */
	function udpateStateForToggleElement ($toggleElement)
	{
		// Target first parent in which fieldsets are siblings
		var $typeSelectorParent = $toggleElement.parents('.form-field').first().parent();

		// Target all fieldsets of this type selector
		var $fieldsets = $typeSelectorParent.find('> .form-fieldset');

		// Browse all inputs to get input index
		// (there are labels and inputs in the same parent so direct .index() is not working)
		var $allInputs = $toggleElement.parent().find('input');

		// Get selected type index
		var currentSelectedIndex = $allInputs.index( $toggleElement );

		// Serialize form and browse values
		$('form#blueprints').serializeArray().map( serializedField =>
		{
			// Convert "data[name][other]" into "name.other"
			var varParts = serializedField.name.split('[').map( part => {
				return part.substring(0, part.length-1);
			});
			varParts.shift();
			var key = varParts.join('.').toLowerCase();

			// Saved already enabled remote elements
			var alreadyEnabledRemotes = {};

			// Browse every remote
			remoteSelectors.map( remote =>
			{
				// Update this remote visibility if the key / value is corresponding
				if (remote.key !== key) return;
				var state = ( serializedField.value == remote.value );

				// If this remote just got enabled, do not disable it
				// This allow us to add multiple key=value in remotes to do OR operations
				if ( remote.id in alreadyEnabledRemotes && alreadyEnabledRemotes[ remote.id ] ) return;
				alreadyEnabledRemotes[ remote.id ] = state;
				setFieldsetVisibility( remote.el, state );
			})
		});

		// Browse all fieldsets and enable only the selected one
		$fieldsets.each( function (index, element)
		{
			// If this fieldset type is selected by index
			setFieldsetVisibility( element, index === currentSelectedIndex )
		});
	}

	// Browse every type select remotes to register them
	$('.TypeSelectorRemote').each( (i, el) =>
	{
		// Browse every classes of this remote
		el.className.split(' ').map( className =>
		{
			// We get every classNames with an "="
			if ( className.indexOf('=') === -1 ) return;

			// Give this remote a uniq id if it does not have one already
			if (el.id == null || el.id == '')
				el.id = 'remote-' + Math.floor(Math.random() * 500 + new Date().getTime()).toString(16);

			// Register key, value and element to enable / disable
			var splitted = className.split('=');
			remoteSelectors.push({
				key: splitted[0].toLowerCase(),
				value: splitted[1].toLowerCase(),
				el: el,
				id: el.id
			});
		})
	});

	// First init, enable already selected inputs
	// We browse every element in the TypeSelector list
	$( radioSelector + ':checked' ).each(function (index, element)
	{
		// Update state
		udpateStateForToggleElement( $(element) );
	});

	// Listen clicks on all type toggles of all enabled type selectors
	$('.admin-block').on('change', radioSelector, function ( event )
	{
		// Update state
		// Current target is the toggle
		udpateStateForToggleElement( $( event.currentTarget ) );
	});
});

