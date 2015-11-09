<?php

namespace EsteIt\ShippingCalculator\Calculator\Asendia;

use EsteIt\ShippingCalculator\Exception\InvalidConfigurationException;
use EsteIt\ShippingCalculator\Exception\InvalidRecipientAddressException;
use EsteIt\ShippingCalculator\Exception\InvalidSenderAddressException;
use EsteIt\ShippingCalculator\Package\PackageInterface;
use Moriony\Trivial\Math\MathInterface;
use Moriony\Trivial\Math\NativeMath;

/**
 * Class Tariff
 */
class Tariff
{
    /**
     * @var PriceGroup[]
     */
    protected $priceGroups;
    /**
     * @var string|float|int
     */
    protected $fuelSubcharge;
    /**
     * @var \DateTime
     */
    protected $date;
    /**
     * @var RecipientCountry[]
     */
    protected $recipientCountries;
    /**
     * @var string[]
     */
    protected $senderCountries;
    /**
     * @var MathInterface
     */
    protected $math;

    /**
     * Tariff constructor.
     */
    public function __construct()
    {
        $this->senderCountries = ['USA'];
        $this->recipientCountries = [];
    }

    /**
     * @param PriceGroup $priceGroup
     * @return $this
     */
    public function addPriceGroup(PriceGroup $priceGroup)
    {
        $this->priceGroups[$priceGroup->getName()] = $priceGroup;

        return $this;
    }

    /**
     * @param PriceGroup[] $priceGroups
     * @return $this
     */
    public function addPriceGroups(array $priceGroups)
    {
        foreach ($priceGroups as $priceGroup) {
            $this->addPriceGroup($priceGroup);
        }

        return $this;
    }

    /**
     * @param PackageInterface $package
     * @return mixed
     */
    public function calculate(PackageInterface $package)
    {
        $isValidSenderCountry = in_array($package->getSenderAddress()->getCountryCode(), $this->senderCountries);
        if (!$isValidSenderCountry) {
            throw new InvalidSenderAddressException();
        }

        $country = $this->getRecipientCountry($package->getRecipientAddress()->getCountryCode());
        $priceGroup = $this->getPriceGroup($country->getPriceGroup());

        $math = $this->getMath();
        
        $cost = $priceGroup->getPrice($package->getWeight());
        $pounds = $math->roundDown($package->getWeight());
        $fuelCost = $math->mul($pounds, $this->getFuelSubcharge());
        $total = $math->sum($cost, $fuelCost);
        $total = $math->roundUp($total, 2);
        $result = number_format($total, 2, '.', '');

        return $result;
    }

    /**
     * @param string $number
     * @return PriceGroup
     */
    public function getPriceGroup($number)
    {
        if (!array_key_exists($number, $this->priceGroups)) {
            throw new InvalidConfigurationException('Price group does not exist.');
        }

        return $this->priceGroups[$number];
    }

    /**
     * @return \DateTime
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * @param \DateTime $date
     * @return $this
     */
    public function setDate(\DateTime $date)
    {
        $this->date = $date;

        return $this;
    }

    /**
     * @return float|int|string
     */
    public function getFuelSubcharge()
    {
        return $this->fuelSubcharge;
    }

    /**
     * @param float|int|string $fuelSubcharge
     * @return $this
     */
    public function setFuelSubcharge($fuelSubcharge)
    {
        $this->fuelSubcharge = $fuelSubcharge;

        return $this;
    }

    /**
     * @return RecipientCountry[]
     */
    public function getRecipientCountries()
    {
        return $this->recipientCountries;
    }

    /**
     * @param RecipientCountry $country
     * @return $this
     */
    public function addRecipientCountry(RecipientCountry $country)
    {
        $this->recipientCountries[$country->getCode()] = $country;

        return $this;
    }

    /**
     * @param RecipientCountry[] $countries
     * @return $this
     */
    public function addRecipientCountries(array $countries)
    {
        foreach ($countries as $country) {
            $this->addRecipientCountry($country);
        }

        return $this;
    }

    /**
     * @param string $code
     * @return RecipientCountry
     */
    public function getRecipientCountry($code)
    {
        if (!array_key_exists($code, $this->recipientCountries)) {
            throw new InvalidRecipientAddressException();
        }

        return $this->recipientCountries[$code];
    }

    /**
     * @return MathInterface
     */
    public function getMath()
    {
        if (!$this->math) {
            $this->math = new NativeMath();
        }

        return $this->math;
    }

    /**
     * @param MathInterface $math
     * @return $this
     */
    public function setMath(MathInterface $math)
    {
        $this->math = $math;

        return $this;
    }
}
