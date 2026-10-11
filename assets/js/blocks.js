// Registers "Pay with KetaPay escrow" in the block-based Checkout. Plain script, no build step:
// everything comes from the globals WooCommerce and WordPress already load on the checkout page.
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'ketapay_data', {} );
	const title = decodeEntities( settings.title || '' ) || 'Pay with KetaPay escrow';
	const Description = () => createElement( 'p', null, decodeEntities( settings.description || '' ) );

	registerPaymentMethod( {
		name: 'ketapay',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Description ),
		edit: createElement( Description ),
		canMakePayment: () => true,
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
