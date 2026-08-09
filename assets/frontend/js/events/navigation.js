window.CX = window.CX || {};

(function ($) {

	"use strict";

	const $conf =
		$("#cx-conf-form");


	/**
	 * Navigate between configurator steps.
	 *
	 * Each step contains a hidden checkbox controlling whether its content
	 * is expanded.
	 */
	$conf.on(
		"click",
		".cx-conf-nav a",
		function (event) {

			event.preventDefault();


			const $button =
				$(this);


			const $currentStep =
				$button
					.closest(
						".cx-conf-step"
					);


			if (!$currentStep.length) {
				return;
			}


			let $target;


			if (
				$button.hasClass(
					"cx-conf-prev"
				)
			) {

				$target =
					$currentStep
						.prev(
							".cx-conf-step"
						);

			} else {

				$target =
					$currentStep
						.next(
							".cx-conf-step"
						);
			}


			if (!$target.length) {
				return;
			}


			/*
			|--------------------------------------------------------------------------
			| Open Target
			|--------------------------------------------------------------------------
			*/

			$target
				.children(
					'input[name="cx-conf-step"]'
				)
				.prop(
					"checked",
					true
				);


			/*
			|--------------------------------------------------------------------------
			| Scroll
			|--------------------------------------------------------------------------
			*/

			$target
				.get(
					0
				)
				?.scrollIntoView(
					{
						behavior:
							"smooth",

						block:
							"center"
					}
				);
		}
	);

})(jQuery);