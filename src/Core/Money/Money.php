<?php

namespace EzEcommerce\Core\Money;

use Brick\Money\Money as BrickMoney;
use EzEcommerce\Core\Exceptions\CurrencyMismatchException;
use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $minorAmount,
        public string $currency,
    ) {
        if ($this->minorAmount < 0) {
            throw new InvalidArgumentException('Minor amount cannot be negative.');
        }
    }

    public static function fromMinor(int $minorAmount, string $currency): self
    {
        return new self($minorAmount, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    public function isZero(): bool
    {
        return $this->minorAmount === 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorAmount + $other->minorAmount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorAmount - $other->minorAmount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minorAmount * $factor, $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorAmount > $other->minorAmount;
    }

    public function isLessThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorAmount <= $other->minorAmount;
    }

    /**
     * @param  list<self>  $amounts
     * @return list<self>
     */
    public static function allocate(self $total, array $amounts): array
    {
        if ($amounts === []) {
            return [];
        }

        $brick = BrickMoney::ofMinor($total->minorAmount, $total->currency);
        $ratios = array_map(fn (self $m) => $m->minorAmount, $amounts);
        $allocated = $brick->allocate(...$ratios);

        return array_map(
            fn (BrickMoney $m) => self::fromMinor($m->getMinorAmount()->toInt(), $m->getCurrency()->getCurrencyCode()),
            $allocated
        );
    }

    public function toBrick(): BrickMoney
    {
        return BrickMoney::ofMinor($this->minorAmount, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }
}
