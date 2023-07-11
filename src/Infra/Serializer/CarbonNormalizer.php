<?php

namespace Lingoda\DomainEventsBundle\Infra\Serializer;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonTimeZone;
use DateTimeInterface;
use Exception;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webmozart\Assert\Assert;

class CarbonNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public const FORMAT_KEY = 'datetime_format';
    public const TIMEZONE_KEY = 'datetime_timezone';

    private const SUPPORTED_TYPES = [
        Carbon::class => true,
        CarbonImmutable::class => true,
    ];

    /**
     * @var array<string, string|null>
     */
    private array $defaultContext = [
        self::FORMAT_KEY => DateTimeInterface::RFC3339,
        self::TIMEZONE_KEY => null,
    ];

    /**
     * @param CarbonInterface $object
     * @param array<string, mixed> $context
     */
    public function normalize($object, ?string $format = null, array $context = []): string
    {
        $dateTimeFormat = $context[self::FORMAT_KEY] ?? $this->defaultContext[self::FORMAT_KEY];
        Assert::string($dateTimeFormat);

        $timezone = $this->getTimezone($context);

        if (null !== $timezone) {
            $object = clone $object;
            $object = $object->setTimezone($timezone);
        }

        return $object->format($dateTimeFormat);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CarbonInterface;
    }

    /**
     * @param string|null $data
     * @param array<string, mixed> $context
     * @throws NotNormalizableValueException
     */
    public function denormalize($data, string $type, ?string $format = null, array $context = []): CarbonInterface
    {
        $dateTimeFormat = $context[self::FORMAT_KEY] ?? null;
        Assert::nullOrString($dateTimeFormat);

        $timezone = $this->getTimezone($context);

        if ('' === $data || null === $data) {
            throw new NotNormalizableValueException(
                'The data is either an empty string or null, you should pass a string that can be parsed with the passed format or a valid DateTime string.',
            );
        }

        if (null !== $dateTimeFormat) {
            $object = Carbon::class === $type ? Carbon::createFromFormat(
                $dateTimeFormat,
                $data,
                $timezone,
            ) : CarbonImmutable::createFromFormat($dateTimeFormat, $data, $timezone);

            if (false !== $object) {
                return $object;
            }

            /** @var array{errors: array<int, string>, error_count: int} $dateTimeErrors */
            $dateTimeErrors = Carbon::class === $type ? Carbon::getLastErrors() : CarbonImmutable::getLastErrors();

            throw new NotNormalizableValueException(sprintf(
                    'Parsing datetime string "%s" using format "%s" resulted in %d errors: ',
                    $data,
                    $dateTimeFormat,
                    $dateTimeErrors['error_count'],
                ) . "\n" . implode("\n", $this->formatDateTimeErrors($dateTimeErrors['errors'])));
        }

        try {
            return Carbon::class === $type ? new Carbon($data, $timezone) : new CarbonImmutable($data, $timezone);
        } catch (Exception $e) {
            throw new NotNormalizableValueException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(
        mixed   $data,
        string  $type,
        ?string $format = null,
        array   $context = [],
    ): bool
    {
        return isset(self::SUPPORTED_TYPES[$type]);
    }

    /**
     * Formats datetime errors.
     *
     * @param array<int, string> $errors
     *
     * @return array<int, string>
     */
    private function formatDateTimeErrors(array $errors): array
    {
        $formattedErrors = [];

        foreach ($errors as $pos => $message) {
            $formattedErrors[] = sprintf('at position %d: %s', $pos, $message);
        }

        return $formattedErrors;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function getTimezone(array $context): ?CarbonTimeZone
    {
        $dateTimeZone = $context[self::TIMEZONE_KEY] ?? $this->defaultContext[self::TIMEZONE_KEY];

        if (null === $dateTimeZone) {
            return null;
        }

        return $dateTimeZone instanceof CarbonTimeZone ? $dateTimeZone : new CarbonTimeZone($dateTimeZone);
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        $isCacheable = __CLASS__ === static::class;

        return
            [
                CarbonImmutable::class => $isCacheable,
            ];
    }
}