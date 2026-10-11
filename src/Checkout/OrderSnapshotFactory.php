<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Checkout;

/** Reads a WC_Order into an OrderSnapshot. */
final class OrderSnapshotFactory
{
    public static function fromOrder(\WC_Order $order, string $returnUrl): OrderSnapshot
    {
        $items = [];
        $imageUrls = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $items[] = [
                'name' => wp_strip_all_tags($item->get_name()),
                'quantity' => (int) $item->get_quantity(),
                'total' => (string) $item->get_total(),
            ];
            $product = $item->get_product();
            $imageId = $product instanceof \WC_Product ? (int) $product->get_image_id() : 0;
            $url = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'large') : false;
            if (is_string($url)) {
                $imageUrls[] = $url;
            }
        }

        $shippingMethod = $order->get_shipping_method();
        $phone = $order->get_billing_phone();

        return new OrderSnapshot(
            id: $order->get_id(),
            number: (string) $order->get_order_number(),
            total: (string) $order->get_total(),
            currency: $order->get_currency(),
            storeName: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            billingEmail: $order->get_billing_email(),
            billingPhone: $phone !== '' ? $phone : null,
            items: $items,
            shippingMethod: $shippingMethod !== '' ? wp_strip_all_tags($shippingMethod) : null,
            shippingTotal: (string) $order->get_shipping_total(),
            imageUrls: $imageUrls,
            viewUrl: $order->get_view_order_url(),
            returnUrl: $returnUrl,
            cancelUrl: $order->get_cancel_order_url_raw(),
        );
    }
}
