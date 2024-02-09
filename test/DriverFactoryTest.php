<?php

declare(strict_types=1);

namespace RoaveTest\PsrContainerDoctrine;

use Doctrine\ORM\Mapping\Driver;
use Doctrine\Persistence\Mapping\Driver\FileDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Roave\PsrContainerDoctrine\DriverFactory;
use Roave\PsrContainerDoctrine\Exception\InvalidArgumentException;
use Roave\PsrContainerDoctrine\Exception\OutOfBoundsException;

final class DriverFactoryTest extends TestCase
{
    public function testMissingClassKeyWillReturnOutOfBoundException(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $factory   = new DriverFactory();

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Missing "doctrine.driver.orm_default.class" config key');

        $factory($container);
    }

    public function testNonObjectClassKeyWillReturnInvalidArgumentException(): void
    {
        $container = $this->createContainerMockWithConfig(
            [
                'doctrine' => [
                    'driver' => [
                        'orm_default' => ['class' => 'not_a_class'],
                    ],
                ],
            ],
        );
        $factory   = new DriverFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured class "not_a_class" on key "doctrine.driver.orm_default.class" does not exists');

        $factory($container);
    }

    public function testItSupportsGlobalBasenameOptionOnFileDrivers(): void
    {
        $globalBasename = 'foobar';

        $container = $this->createContainerMockWithConfig(
            [
                'doctrine' => [
                    'driver' => [
                        'orm_default' => [
                            'class' => TestAsset\StubFileDriver::class,
                            'global_basename' => $globalBasename,
                        ],
                    ],
                ],
            ],
        );

        $driver = (new DriverFactory())->__invoke($container);
        self::assertInstanceOf(FileDriver::class, $driver);
        self::assertSame($globalBasename, $driver->getGlobalBasename());
    }

    /**
     * @psalm-param class-string<FileDriver> $driverClass
     *
     * @dataProvider simplifiedDriverClassProvider
     */
    public function testItSupportsSettingExtensionInDriversUsingSymfonyFileLocator(string $driverClass): void
    {
        $extension = '.foo.bar';

        $container = $this->createContainerMockWithConfig(
            [
                'doctrine' => [
                    'driver' => [
                        'orm_default' => [
                            'class' => $driverClass,
                            'extension' => $extension,
                        ],
                    ],
                ],
            ],
        );

        $driver = (new DriverFactory())->__invoke($container);
        self::assertInstanceOf(FileDriver::class, $driver);
        self::assertSame($extension, $driver->getLocator()->getFileExtension());
    }

    /**
     * @return string[][]
     * @psalm-return list<array{class-string<FileDriver>}>
     */
    public static function simplifiedDriverClassProvider(): array
    {
        return [
            [ Driver\SimplifiedXmlDriver::class ],
        ];
    }

    public function testMappingDriverChainIsCreatedWithNoDefaultDriverWhenDefaultDriverNotSpecified(): void
    {
        $container = $this->createContainerMockWithConfig(
            [
                'doctrine' => [
                    'driver' => [
                        'orm_default' => [
                            'class' => MappingDriverChain::class,
                        ],
                    ],
                ],
            ],
            1,
        );

        $driver = (new DriverFactory())->__invoke($container);
        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertNull($driver->getDefaultDriver());
    }

    public function testItSupportsSettingDefaultDriverUsingMappingDriverChain(): void
    {
        $container = $this->createContainerMockWithConfig(
            [
                'doctrine' => [
                    'driver' => [
                        'orm_default' => [
                            'class' => MappingDriverChain::class,
                            'default_driver' => 'orm_stub',
                        ],
                        'orm_stub' => [
                            'class' => TestAsset\StubFileDriver::class,
                        ],
                    ],
                ],
            ],
            2,
        );

        $driver = (new DriverFactory())->__invoke($container);
        self::assertInstanceOf(MappingDriverChain::class, $driver);
        self::assertInstanceOf(TestAsset\StubFileDriver::class, $driver->getDefaultDriver());
    }

    /**
     * @return string[][]
     * @psalm-return list<array{class-string<MappingDriver>}>
     */
    public static function annotationDriverClassProvider(): array
    {
        return [
            [ Driver\AttributeDriver::class ],
        ];
    }

    /**
     * @psalm-param class-string<MappingDriver> $driverClass
     *
     * @dataProvider annotationDriverClassProvider
     */
    public function testItSupportsAnnotationDrivers(string $driverClass): void
    {
        $services  = [
            'config' => [
                'doctrine' => [
                    'driver' => [
                        'orm_default' => [
                            'class' => $driverClass,
                            'cache' => 'default',
                        ],
                    ],
                ],
            ],
            'doctrine.cache.default' => $this->createMock(CacheItemPoolInterface::class),
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static function (string $id) use ($services): bool {
                return isset($services[$id]);
            },
        );
        $container->method('get')->willReturnCallback(
            /** @return object|array */
            static function (string $id) use ($services) {
                return $services[$id];
            },
        );

        $driver = (new DriverFactory())->__invoke($container);
        self::assertInstanceOf($driverClass, $driver);
    }

    /** @param array<string, mixed> $config */
    private function createContainerMockWithConfig(array $config, int $expectedCalls = 1): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly($expectedCalls))->method('has')->with('config')->willReturn(true);
        $container->expects($this->exactly($expectedCalls))->method('get')->with('config')->willReturn($config);

        return $container;
    }
}
