<?php

declare(strict_types=1);

namespace App;

use Faker\Generator;

/**
 * Builds a single product-card record for an online store.
 */
final class ProductCardFactory
{
    private const CATEGORIES = [
        'Electronics', 'Home & Kitchen', 'Clothing', 'Sports & Outdoors',
        'Beauty', 'Toys & Games', 'Books', 'Automotive', 'Garden', 'Pet Supplies',
    ];

    private const CONDITIONS = ['new', 'refurbished', 'used'];

    private const IMAGE_BASE_URL = 'https://cdn.example.com/products/';

    public function __construct(private readonly Generator $faker)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $id = $this->uuid();
        $price = $this->faker->randomFloat(2, 1, 5000);
        $hasDiscount = $this->faker->boolean(30);

        return [
            'id' => $id,
            'sku' => strtoupper($this->faker->bothify('???-########')),
            'title' => ucwords($this->faker->words(3, true)),
            'brand' => $this->faker->company(),
            'category' => $this->faker->randomElement(self::CATEGORIES),
            'description' => $this->faker->sentence(12),
            'price' => $price,
            'sale_price' => $hasDiscount
                ? round($price * $this->faker->randomFloat(2, 0.5, 0.95), 2)
                : null,
            'currency' => 'USD',
            'color' => $this->faker->safeColorName(),
            'condition' => $this->faker->randomElement(self::CONDITIONS),
            'in_stock' => $this->faker->boolean(85),
            'stock_quantity' => $this->faker->numberBetween(0, 1000),
            'rating' => $this->faker->randomFloat(1, 1, 5),
            'review_count' => $this->faker->numberBetween(0, 10000),
            'image_url' => self::IMAGE_BASE_URL . $id . '.jpg',
            'created_at' => $this->faker->dateTimeThisDecade()->format(DATE_ATOM),
        ];
    }

    /**
     * Fast RFC-4122 v4 UUID built straight from random bytes, avoiding the
     * hashing overhead of Faker's generator at tens of millions of rows.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
