<?php

declare(strict_types=1);

namespace KetaPay\WooCommerce\Tests\Unit;

use KetaPay\WooCommerce\Checkout\SessionParams;
use KetaPay\WooCommerce\Tests\Unit\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class SessionParamsTest extends TestCase
{
    public function testBuildsTheSessionForAnOrder(): void
    {
        $params = SessionParams::build(Fixtures::order(), Fixtures::settings(), 'site-1');

        self::assertSame(4_700_000, $params['amount']);
        self::assertSame('NGN', $params['currency'] ?? null);
        self::assertSame('goods', $params['category'] ?? null);
        self::assertSame('Order #1042 from Ada Fabrics', $params['title']);
        self::assertSame(['email' => 'buyer@example.com', 'phone' => '+2348012345678'], $params['buyer']);
        self::assertSame(['email' => 'shop@example.com'], $params['seller']);
        self::assertSame(['method' => 'buyer_confirms', 'auto_release_hours' => 72], $params['release'] ?? null);
        self::assertSame('https://shop.example.com/checkout/order-received/1042/?key=wc_order_abc', $params['return_url'] ?? null);
        self::assertSame('https://shop.example.com/my-account/view-order/1042/', $params['url'] ?? null);
        self::assertSame('1042', $params['external_reference'] ?? null);
        self::assertSame(['wc_order_id' => '1042', 'wc_site' => 'site-1'], $params['metadata'] ?? null);
    }

    public function testDescriptionListsWhatWasBought(): void
    {
        $params = SessionParams::build(Fixtures::order(), Fixtures::settings(), 'site-1');

        self::assertSame(
            "2 × Ankara shirt (L): ₦30,000.00\n1 × Head tie: ₦15,000.00\nDelivery (Flat rate): ₦2,000.00\nTotal: ₦47,000.00",
            $params['description'],
        );
    }

    public function testShortDescriptionsReachTheApiMinimum(): void
    {
        // A fee-only order with no line items: "Total: ₦1.00" alone is under the API's 20 characters.
        $order = Fixtures::order(total: '1', items: [], shippingMethod: null, storeName: 'Y');

        $description = SessionParams::build($order, Fixtures::settings(), 'site-1')['description'];

        self::assertGreaterThanOrEqual(20, mb_strlen($description));
        self::assertSame("Order #1042 from Y\nTotal: ₦1.00", $description);
    }

    public function testLongTextIsCutToTheApiLimits(): void
    {
        $items = array_fill(0, 200, ['name' => str_repeat('Very long product name ', 3), 'quantity' => 1, 'total' => '100']);
        $params = SessionParams::build(Fixtures::order(items: $items, storeName: str_repeat('Big Store ', 20)), Fixtures::settings(), 'site-1');

        self::assertSame(120, mb_strlen($params['title']));
        self::assertStringEndsWith('…', $params['title']);
        self::assertSame(2000, mb_strlen($params['description']));
    }

    public function testBuyerWithoutAUsablePhoneIsIdentifiedByEmail(): void
    {
        $params = SessionParams::build(Fixtures::order(phone: '+44 7700 900123'), Fixtures::settings(), 'site-1');

        self::assertSame(['email' => 'buyer@example.com'], $params['buyer']);
    }

    public function testSellerPhoneIsSentWhenSet(): void
    {
        $params = SessionParams::build(Fixtures::order(), Fixtures::settings(['seller_phone' => '08031234567']), 'site-1');

        self::assertSame(['email' => 'shop@example.com', 'phone' => '+2348031234567'], $params['seller']);
    }

    public function testReleaseCodeHasNoAutoRelease(): void
    {
        $params = SessionParams::build(Fixtures::order(), Fixtures::settings(['release_method' => 'release_code']), 'site-1');

        self::assertSame(['method' => 'release_code'], $params['release'] ?? null);
    }

    public function testNeverAutoReleaseSendsNull(): void
    {
        $params = SessionParams::build(Fixtures::order(), Fixtures::settings(['auto_release_hours' => '']), 'site-1');

        self::assertSame(['method' => 'buyer_confirms', 'auto_release_hours' => null], $params['release'] ?? null);
    }

    public function testOnlyPublicHttpsImagesAreSentAtMostTen(): void
    {
        $urls = ['http://localhost/a.jpg', 'https://cdn.example.com/a.jpg', 'https://cdn.example.com/a.jpg', 'not a url'];
        for ($i = 0; $i < 12; $i++) {
            $urls[] = "https://cdn.example.com/$i.jpg";
        }

        $images = SessionParams::build(Fixtures::order(imageUrls: $urls), Fixtures::settings(), 'site-1')['images'] ?? [];

        self::assertCount(10, $images);
        self::assertSame(['url' => 'https://cdn.example.com/a.jpg'], $images[0]);
        self::assertSame(['url' => 'https://cdn.example.com/0.jpg'], $images[1]);
    }

    public function testImagesCanBeTurnedOff(): void
    {
        $params = SessionParams::build(
            Fixtures::order(imageUrls: ['https://cdn.example.com/a.jpg']),
            Fixtures::settings(['send_images' => 'no']),
            'site-1',
        );

        self::assertArrayNotHasKey('images', $params);
    }

    public function testRefusesOtherCurrencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('naira');

        SessionParams::build(Fixtures::order(currency: 'USD'), Fixtures::settings(), 'site-1');
    }
}
