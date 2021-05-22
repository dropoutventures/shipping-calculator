<?php

namespace EsteIt\ShippingCalculator\Handler;

use EsteIt\ShippingCalculator\Address;
use EsteIt\ShippingCalculator\Dimensions;
use EsteIt\ShippingCalculator\Exception\ViolationException;
use EsteIt\ShippingCalculator\Handler\Dhl\ZoneCalculator;
use EsteIt\ShippingCalculator\Configuration\DhlConfiguration;
use EsteIt\ShippingCalculator\Exception\InvalidArgumentException;
use EsteIt\ShippingCalculator\Model\ExportCountry;
use EsteIt\ShippingCalculator\Model\ImportCountry;
use EsteIt\ShippingCalculator\Package;
use EsteIt\ShippingCalculator\Result;
use EsteIt\ShippingCalculator\Tool\DimensionsNormalizer;
use EsteIt\ShippingCalculator\Violation;
use EsteIt\ShippingCalculator\VolumetricWeightCalculator\DhlVolumetricWeightCalculator;
use Moriony\Trivial\Converter\LengthConverter;
use Moriony\Trivial\Converter\UnitConverterInterface;
use Moriony\Trivial\Converter\WeightConverter;
use Moriony\Trivial\Math\MathInterface;
use Moriony\Trivial\Math\NativeMath;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DhlHandler implements HandlerInterface, ValidationHandlerInterface
{
    /**
     * @var array
     */
    protected $options;

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
                'dimensions_normalizer' => new DimensionsNormalizer($math),
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
            ]);
        
        $resolve->setAllowedTypes('export_countries', 'array');
        $resolve->setAllowedTypes('import_countries', 'array');
        $resolve->setAllowedTypes('zone_calculators', 'array');
        $resolve->setAllowedTypes('currency', 'string');
        $resolve->setAllowedTypes('math', MathInterface::class);
        $resolve->setAllowedTypes('weight_converted', UnitConverterInterface::class);
        $resolve->setAllowedTypes('length_converted', UnitConverterInterface::class);
        $resolve->setAllowedTypes('volumetric_weight_calculator', DhlVolumetricWeightCalculator::class);
        $resolve->setAllowedTypes('dimensions_normalizer', DimensionsNormalizer::class);
        

        $resolver->setNormalizer('import_countries', $this->createImportCountriesNormalizer());
        $resolver->setNormalizer('export_countries', $this->createExportCountriesNormalizer());
        $resolver->setNormalizer('zone_calculators', $this->createZoneCalculatorsNormalizer());
        $resolver->setNormalizer('maximum_dimensions', $this->createDimensionsNormalizer());

        $this->options = $resolver->resolve($options);
    }

    /**
     * @param Result $result
     * @param Package $package
     */
    public function validate(Result $result, Package $package)
    {
        try {
            $this->validateSenderAddress($package->getSenderAddress());
        } catch (ViolationException $e) {
            $result->addViolation(new Violation($e->getMessage()));
        }

        try {
            $this->validateRecipientAddress($package->getRecipientAddress());
        } catch (ViolationException $e) {
            $result->addViolation(new Violation($e->getMessage()));
        }

        try {
            $this->validateDimensions($package);
        } catch (ViolationException $e) {
            $result->addViolation(new Violation($e->getMessage()));
        }

        try {
            $this->validateWeight($package);
        } catch (ViolationException $e) {
            $result->addViolation(new Violation($e->getMessage()));
        }
    }

    /**
     * @param Result $result
     * @param Package $package
     * @return mixed
     */
    public function calculate(Result $result, Package $package)
    {
        $weight = $package->getWeight();
        $volumetricWeight = $this->getVolumetricWeightCalculator()->calculate($package->getDimensions());

        $weight = $this->getWeightConverter()->convert($weight->getValue(), $weight->getUnit(), $this->get('mass_unit'));
        $volumetricWeight = $this->getWeightConverter()->convert($volumetricWeight->getValue(), $volumetricWeight->getUnit(), $this->get('mass_unit'));

        $math = $this->getMath();
        if ($math->greaterThan($volumetricWeight, $weight)) {
            $weight = $volumetricWeight;
        }

        $zoneCalculator = $this->getZoneCalculator($package);
        $total = $zoneCalculator->calculate($weight);

        $result->set('shipping_cost', $total);
    }


    /**
     * @param Address $address
     */
    public function validateSenderAddress(Address $address)
    {
        try {
            $this->getExportCountry($address->getCountryCode());
        } catch (InvalidArgumentException $e) {
            throw new ViolationException('Can not send a package from this country.');
        }
    }

    /**
     * @param Address $address
     */
    public function validateRecipientAddress(Address $address)
    {
        try {
            $importCountry = $this->getImportCountry($address->getCountryCode());
        } catch (InvalidArgumentException $e) {
            throw new ViolationException('Can not send a package to this country.');
        }

        if (!array_key_exists($importCountry->getZone(), $this->get('zone_calculators'))) {
            throw new ViolationException('Can not send a package to this country.');
        }
    }

    /**
     * @param Package $package
     */
    public function validateDimensions(Package $package)
    {
        $math = $this->getMath();
        $converter = $this->getLengthConverter();
        $dimensions = $package->getDimensions();

        $invalidDimensions = $math->lessOrEqualThan($dimensions->getHeight(), 0)
            || $math->lessOrEqualThan($dimensions->getLength(), 0)
            || $math->lessOrEqualThan($dimensions->getWidth(), 0);

        if ($invalidDimensions) {
            throw new ViolationException('Dimensions must be greater than zero.');
        }

        $maxDimensions = $this->getDimensionsNormalizer()->normalize($this->get('maximum_dimensions'));
        $dimensions = $this->getDimensionsNormalizer()->normalize($package->getDimensions());

        $maxLength = $converter->convert($maxDimensions->getLength(), $this->get('dimensions_unit'), $dimensions->getUnit());
        if ($math->greaterThan($dimensions->getLength(), $maxLength)) {
            throw new ViolationException('Dimensions limit is exceeded.');
        }

        $maxWidth = $converter->convert($maxDimensions->getWidth(), $this->get('dimensions_unit'), $dimensions->getUnit());
        if ($math->greaterThan($dimensions->getWidth(), $maxWidth)) {
            throw new ViolationException('Dimensions limit is exceeded.');
        }

        $maxHeight = $converter->convert($maxDimensions->getHeight(), $this->get('dimensions_unit'), $dimensions->getUnit());
        if ($math->greaterThan($dimensions->getHeight(), $maxHeight)) {
            throw new ViolationException('Dimensions limit is exceeded.');
        }
    }

    public function validateWeight(Package $package)
    {
        $math = $this->getMath();
        $converter = $this->getWeightConverter();

        $maxWeight = $converter->convert($this->get('maximum_weight'), $this->get('mass_unit'), $package->getWeight()->getUnit());
        if ($math->greaterThan($package->getWeight()->getValue(), $maxWeight)) {
            throw new ViolationException('Sender country weight limit is exceeded.');
        }
    }

    /**
     * @param Package $package
     * @return ZoneCalculator
     */
    public function getZoneCalculator($package)
    {
        $country = $this->getImportCountry($package->getRecipientAddress()->getCountryCode());

        $calculators = $this->get('zone_calculators');
        if (!array_key_exists($country->getZone(), $calculators)) {
            throw new ViolationException('Price group does not exist.');
        }

        return $calculators[$country->getZone()];
    }

    /**
     * @param string $code
     * @return ExportCountry
     */
    public function getExportCountry($code)
    {
        $countries = $this->get('export_countries');
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
        $countries = $this->get('import_countries');
        if (!array_key_exists($code, $countries)) {
            throw new InvalidArgumentException();
        }

        return $countries[$code];
    }

    public static function create(array $config)
    {
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration(new DhlConfiguration(), [$config]);
        return new static($processedConfig);
    }
    
    /**
     * @param mixed $name
     * @return mixed null
     */
    public function get($name)
    {
        return $this->options && array_key_exists($name, $this->options) ? $this->options[$name] : null;
    }

    /**
     * @return MathInterface
     */
    protected function getMath()
    {
        return $this->get('math');
    }

    /**
     * @return UnitConverterInterface
     */
    protected function getLengthConverter()
    {
        return $this->get('length_converter');
    }

    /**
     * @return UnitConverterInterface
     */
    protected function getWeightConverter()
    {
        return $this->get('weight_converter');
    }

    /**
     * @return DhlVolumetricWeightCalculator
     */
    protected function getVolumetricWeightCalculator()
    {
        return $this->get('volumetric_weight_calculator');
    }

    /**
     * @return DimensionsNormalizer
     */
    protected function getDimensionsNormalizer()
    {
        return $this->get('dimensions_normalizer');
    }

    /**
     * @return \Closure
     */
    protected function createImportCountriesNormalizer()
    {
        return function (Options $options, $value) {
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
    }

    /**
     * @return \Closure
     */
    protected function createExportCountriesNormalizer()
    {
        return function (Options $options, $value) {
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
    }

    /**
     * @return \Closure
     */
    protected function createZoneCalculatorsNormalizer()
    {
        return function (Options $options, $calculators) {
            $normalized = [];
            foreach ($calculators as $calculator) {
                if (!$calculator instanceof ZoneCalculator) {
                    $calculator = new ZoneCalculator($calculator);
                }
                $normalized[$calculator->getName()] = $calculator;
            }
            return $normalized;
        };
    }

    /**
     * @return \Closure
     */
    protected function createDimensionsNormalizer()
    {
        return function (Options $options, $value) {
            if (!$value instanceof Dimensions) {
                $config = $value;
                $value = new Dimensions();
                $value->setLength(reset($config));
                $value->setWidth(next($config));
                $value->setHeight(next($config));
            }
            return $value;
        };
    }
}
