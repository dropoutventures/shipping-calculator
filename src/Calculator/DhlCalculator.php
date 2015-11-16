<?php

namespace EsteIt\ShippingCalculator\Calculator;

use EsteIt\ShippingCalculator\Calculator\Dhl\ZoneCalculator;
use EsteIt\ShippingCalculator\Configuration\DhlConfiguration;
use EsteIt\ShippingCalculator\Exception\InvalidConfigurationException;
use EsteIt\ShippingCalculator\Exception\InvalidDimensionsException;
use EsteIt\ShippingCalculator\Exception\InvalidRecipientAddressException;
use EsteIt\ShippingCalculator\Exception\InvalidSenderAddressException;
use EsteIt\ShippingCalculator\Exception\InvalidArgumentException;
use EsteIt\ShippingCalculator\Exception\InvalidWeightException;
use EsteIt\ShippingCalculator\Model\AddressInterface;
use EsteIt\ShippingCalculator\Model\CalculationResultInterface;
use EsteIt\ShippingCalculator\Model\Dimensions;
use EsteIt\ShippingCalculator\Model\DimensionsInterface;
use EsteIt\ShippingCalculator\Model\ExportCountry;
use EsteIt\ShippingCalculator\Model\ImportCountry;
use EsteIt\ShippingCalculator\Model\PackageInterface;
use EsteIt\ShippingCalculator\VolumetricWeightCalculator\DhlVolumetricWeightCalculator;
use Moriony\Trivial\Converter\LengthConverter;
use Moriony\Trivial\Converter\UnitConverterInterface;
use Moriony\Trivial\Converter\WeightConverter;
use Moriony\Trivial\Math\MathInterface;
use Moriony\Trivial\Math\NativeMath;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DhlCalculator extends AbstractCalculator
{
    public function __construct(array $options)
    {
        $math = new NativeMath();
        $resolver = new OptionsResolver();
        $weightConverter = new WeightConverter($math);
        $lengthConverter = new LengthConverter($math);
        $resolver
            ->setDefined([
                'extra_data'
            ])
            ->setDefaults([
                'currency' => 'USD',
                'math' => $math,
                'weight_converter' => $weightConverter,
                'length_converter' => $lengthConverter,
                'volumetric_weight_calculator' => new DhlVolumetricWeightCalculator($math, $weightConverter, $lengthConverter),
                'extra_data' => null,
            ])
            ->setRequired([
                'export_countries',
                'import_countries',
                'zone_calculators',
                'currency',
                'math',
                'weight_converter',
                'length_converter',
                'mass_unit',
                'dimensions_unit',
                'maximum_weight',
                'maximum_dimensions',
                'volumetric_calculation_factor',
            ])
            ->setAllowedTypes([
                'export_countries' => 'array',
                'import_countries' => 'array',
                'zone_calculators' => 'array',
                'currency' => 'string',
                'math' => 'Moriony\Trivial\Math\MathInterface',
                'weight_converter' => 'Moriony\Trivial\Converter\UnitConverterInterface',
                'length_converter' => 'Moriony\Trivial\Converter\UnitConverterInterface',
                'volumetric_weight_calculator' => 'EsteIt\ShippingCalculator\VolumetricWeightCalculator\DhlVolumetricWeightCalculator',
            ]);

        $exportCountriesNormalizer = function (Options $options, $value) {
            $normalized = [];
            foreach ($value as $country) {
                if (!$country instanceof ExportCountry) {
                    $config = $country;
                    $country = new ExportCountry();
                    $country->setCode($config['code']);
                }
                $normalized[$country->getCode()] = $country;
            }
            return $normalized;
        };

        $importCountriesNormalizer = function (Options $options, $value) {
            $normalized = [];
            foreach ($value as $country) {
                if (!$country instanceof ImportCountry) {
                    $config = $country;
                    $country = new ImportCountry();
                    $country->setCode($config['code']);
                    $country->setZone($config['zone']);
                }
                $normalized[$country->getCode()] = $country;
            }
            return $normalized;
        };

        $zoneCalculatorsNormalizer = function (Options $options, $value) {
            $normalized = [];
            foreach ($value as $calculator) {
                if (!$calculator instanceof ZoneCalculator) {
                    $calculator = new ZoneCalculator($calculator);
                }
                $normalized[$calculator->getName()] = $calculator;
            }
            return $normalized;
        };

        $dimensionsNormalizer = function (Options $options, $value) {
            if (!$value instanceof DimensionsInterface) {
                $config = $value;
                $value = new Dimensions();
                $value->setLength($config['length']);
                $value->setWidth($config['width']);
                $value->setHeight($config['height']);
            }
            return $value;
        };

        $resolver->setNormalizer('export_countries', $exportCountriesNormalizer);
        $resolver->setNormalizer('import_countries', $importCountriesNormalizer);
        $resolver->setNormalizer('zone_calculators', $zoneCalculatorsNormalizer);
        $resolver->setNormalizer('maximum_dimensions', $dimensionsNormalizer);

        $this->options = $resolver->resolve($options);
    }

    /**
     * @param CalculationResultInterface $result
     * @param PackageInterface $package
     * @return mixed
     */
    public function visit(CalculationResultInterface $result, PackageInterface $package)
    {
        $this->validateSenderAddress($package->getSenderAddress());
        $this->validateRecipientAddress($package->getRecipientAddress());
        $this->validateDimensions($package);
        $this->validateWeight($package);

        $zoneCalculator = $this->getZoneCalculator($package);

        $weight = $package->getWeight();
        $weight = $this->getWeightConverter()->convert($weight->getValue(), $weight->getUnit(), $this->getOption('mass_unit'));
        $volumetricWeight = $this->getVolumetricWeightCalculator()->calculate($package->getDimensions(), $this->getOption('mass_unit'));

        $math = $this->getMath();
        if ($math->greaterThan($volumetricWeight, $weight)) {
            $weight = $volumetricWeight;
        }

        $math = $this->getMath();
        $total = $zoneCalculator->calculate($weight);
        $total = $math->roundUp($total, 2);
        $total = number_format($total, 2, '.', '');

        $result->setTotalCost($total);
        $result->setCurrency($this->getOption('currency'));
    }

    /**
     * @param AddressInterface $address
     */
    public function validateSenderAddress(AddressInterface $address)
    {
        try {
            $this->getExportCountry($address->getCountryCode());
        } catch (InvalidArgumentException $e) {
            throw new InvalidSenderAddressException('Can not send a package from this country.');
        }
    }

    /**
     * @param AddressInterface $address
     */
    public function validateRecipientAddress(AddressInterface $address)
    {
        try {
            $importCountry = $this->getImportCountry($address->getCountryCode());
        } catch (InvalidArgumentException $e) {
            throw new InvalidRecipientAddressException('Can not send a package to this country.');
        }

        if (!array_key_exists($importCountry->getZone(), $this->getOption('zone_calculators'))) {
            throw new InvalidRecipientAddressException('Can not send a package to this country.');
        }
    }

    /**
     * @param PackageInterface $package
     */
    public function validateDimensions(PackageInterface $package)
    {
        $math = $this->getMath();
        $converter = $this->getLengthConverter();

        $maxDimensions = $this->normalizeDimensions($this->getOption('maximum_dimensions'));
        $dimensions = $this->normalizeDimensions($package->getDimensions());

        $maxLength = $converter->convert($maxDimensions->getLength(), $this->getOption('dimensions_unit'), $dimensions->getUnit());
        if ($math->greaterThan($dimensions->getLength(), $maxLength)) {
            throw new InvalidDimensionsException('Dimensions limit is exceeded.');
        }

        $maxWidth = $converter->convert($maxDimensions->getWidth(), $this->getOption('dimensions_unit'), $dimensions->getUnit());
        if ($math->greaterThan($dimensions->getWidth(), $maxWidth)) {
            throw new InvalidDimensionsException('Dimensions limit is exceeded.');
        }

        $maxHeight = $converter->convert($maxDimensions->getHeight(), $this->getOption('dimensions_unit'), $dimensions->getUnit());
        if ($math->greaterThan($dimensions->getHeight(), $maxHeight)) {
            throw new InvalidDimensionsException('Dimensions limit is exceeded.');
        }
    }

    public function validateWeight(PackageInterface $package)
    {
        $math = $this->getMath();
        $converter = $this->getWeightConverter();

        $maxWeight = $converter->convert($this->getOption('maximum_weight'), $this->getOption('mass_unit'), $package->getWeight()->getUnit());
        if ($math->greaterThan($package->getWeight()->getValue(), $maxWeight)) {
            throw new InvalidWeightException('Sender country weight limit is exceeded.');
        }
    }

    /**
     * @param PackageInterface $package
     * @return ZoneCalculator
     */
    public function getZoneCalculator($package)
    {
        $country = $this->getImportCountry($package->getRecipientAddress()->getCountryCode());

        $calculators = $this->getOption('zone_calculators');
        if (!array_key_exists($country->getZone(), $calculators)) {
            throw new InvalidConfigurationException('Price group does not exist.');
        }

        return $calculators[$country->getZone()];
    }

    /**
     * @param string $code
     * @return ExportCountry
     */
    public function getExportCountry($code)
    {
        $countries = $this->getOption('export_countries');
        if (!array_key_exists($code, $countries)) {
            throw new InvalidArgumentException();
        }

        return $countries[$code];
    }

    /**
     * @param string $code
     * @return ImportCountry
     */
    public function getImportCountry($code)
    {
        $countries = $this->getOption('import_countries');
        if (!array_key_exists($code, $countries)) {
            throw new InvalidArgumentException();
        }

        return $countries[$code];
    }

    /**
     * @param DimensionsInterface $dimensions
     * @return Dimensions
     */
    public function normalizeDimensions(DimensionsInterface $dimensions)
    {
        $math = $this->getMath();
        $values = [$dimensions->getLength()];

        if ($math->greaterThan($dimensions->getWidth(), reset($values))) {
            array_unshift($values, $dimensions->getWidth());
        } else {
            $values[] = $dimensions->getWidth();
        }

        if ($math->greaterThan($dimensions->getHeight(), reset($values))) {
            array_unshift($values, $dimensions->getHeight());
        } else {
            $values[] = $dimensions->getHeight();
        }

        $normalized = new Dimensions();
        $normalized->setUnit($dimensions->getUnit());
        $normalized->setLength(reset($values));
        $normalized->setWidth(next($values));
        $normalized->setHeight(next($values));

        return $normalized;
    }

    public static function create(array $config)
    {
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration(new DhlConfiguration(), [$config]);
        return new static($processedConfig);
    }

    /**
     * @return mixed
     */
    public function getExtraData()
    {
        return $this->getOption('extra_data');
    }

    /**
     * @return MathInterface
     */
    protected function getMath()
    {
        return $this->getOption('math');
    }

    /**
     * @return UnitConverterInterface
     */
    protected function getLengthConverter()
    {
        return $this->getOption('length_converter');
    }

    /**
     * @return UnitConverterInterface
     */
    protected function getWeightConverter()
    {
        return $this->getOption('weight_converter');
    }


    /**
     * @return DhlVolumetricWeightCalculator
     */
    protected function getVolumetricWeightCalculator()
    {
        return $this->getOption('volumetric_weight_calculator');
    }
}