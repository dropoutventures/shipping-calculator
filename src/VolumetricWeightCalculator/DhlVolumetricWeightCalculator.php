<?php

namespace EsteIt\ShippingCalculator\VolumetricWeightCalculator;

use EsteIt\ShippingCalculator\Model\DimensionsInterface;
use Moriony\Trivial\Converter\LengthConverter;
use Moriony\Trivial\Converter\WeightConverter;
use Moriony\Trivial\Math\MathInterface;
use Moriony\Trivial\Unit\LengthUnits;
use Moriony\Trivial\Unit\WeightUnits;

/**
 * Class DhlVolumetricWeightCalculator
 */
class DhlVolumetricWeightCalculator implements VolumetricWeightCalculatorInterface
{
    /**
     * @var MathInterface
     */
    protected $math;
    protected $weightConverter;
    protected $lengthConverter;
    protected $factor;

    public function __construct(MathInterface $math, WeightConverter $weightConverter, LengthConverter $lengthConverter)
    {
        $this->math = $math;
        $this->lengthConverter = $lengthConverter;
        $this->weightConverter = $weightConverter;
        $this->factor = 5000;
    }

    public function setFactor($factor)
    {
        $this->factor = $factor;

        return $this;
    }

    public function getFactor()
    {
        return $this->factor;
    }

    /**
     * @param DimensionsInterface $dimensions
     * @param string $toWeightUnit
     * @return mixed
     */
    public function calculate(DimensionsInterface $dimensions, $toWeightUnit)
    {
        $length = $this->lengthConverter->convert($dimensions->getLength(), $dimensions->getUnit(), LengthUnits::CM);
        $width = $this->lengthConverter->convert($dimensions->getWidth(), $dimensions->getUnit(), LengthUnits::CM);
        $height = $this->lengthConverter->convert($dimensions->getHeight(), $dimensions->getUnit(), LengthUnits::CM);

        $volume = $length;
        $volume = $this->math->mul($volume, $width);
        $volume = $this->math->mul($volume, $height);

        $value = $this->math->div($volume, $this->getFactor());
        $value = $this->weightConverter->convert($value, WeightUnits::KG, $toWeightUnit);
        $value = $this->math->roundUp($value, 3);

        return $value;
    }
}