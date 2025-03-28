<?php declare(strict_types = 1);

namespace LastDragon_ru\LaraASP\Dev\PhpStan\Larastan;

use Composer\InstalledVersions;
use Exception;
use LastDragon_ru\LaraASP\Core\Path\DirectoryPath;
use LastDragon_ru\LaraASP\Core\Path\FilePath;
use Nette\Neon\Neon;

use function array_filter;
use function array_keys;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

use const PHP_EOL;

class Extension {
    /**
     * Removes unwanted/conflicting services from `larastan/extension.neon` and
     * dump remaining into `phpstan-larastan.neon` (that should be used instead
     * of the original file).
     */
    public static function dump(): void {
        // Larastan?
        if (!self::hasLarastan()) {
            return;
        }

        // Prepare
        $origin = self::getLarastanPath()->getFilePath('extension.neon');
        $target = self::getRootPath()->getFilePath('phpstan-larastan.neon');

        // Load
        $extension = Neon::decode((string) file_get_contents((string) $origin));

        if (!is_array($extension)) {
            throw new Exception('The `$extension` expected to be an array.');
        }

        // Process
        $extension = self::updateBootstrapFiles($target, $extension);
        $extension = self::updateServices($target, $extension);

        // Save
        $neon = Neon::encode($extension, true, '    ');

        file_put_contents((string) $target, $neon);

        echo "  Updated {$target}".PHP_EOL;
    }

    /**
     * @param array<array-key, mixed> $extension
     *
     * @return array<array-key, mixed>
     */
    private static function updateBootstrapFiles(FilePath $path, array $extension): array {
        // Valid?
        if (
            !isset($extension['parameters'])
            || !is_array($extension['parameters'])
            || !isset($extension['parameters']['bootstrapFiles'])
            || !is_array($extension['parameters']['bootstrapFiles'])
        ) {
            throw new Exception('The `$extension[\'parameters\'][\'bootstrapFiles\'])` expected to be an array.');
        }

        // Update
        $source = self::getLarastanPath();

        foreach ($extension['parameters']['bootstrapFiles'] as $index => $file) {
            if (!is_string($file)) {
                throw new Exception(
                    sprintf(
                        'The `$extension[\'parameters\'][\'bootstrapFiles\'][%s]` expected to be a string.',
                        $index,
                    ),
                );
            }

            $file                                              = $source->getFilePath($file);
            $extension['parameters']['bootstrapFiles'][$index] = (string) $path->getRelativePath($file);
        }

        // Return
        return $extension;
    }

    /**
     * @param array<array-key, mixed> $extension
     *
     * @return array<array-key, mixed>
     */
    private static function updateServices(FilePath $path, array $extension): array {
        // Valid?
        if (!isset($extension['services']) || !is_array($extension['services'])) {
            throw new Exception('The `$extension[\'services\'])` expected to be an array.');
        }

        // Remove
        $disabled = [
            'Larastan\\Larastan\\ReturnTypes\\ApplicationMakeDynamicReturnTypeExtension'            => true,
            'Larastan\\Larastan\\ReturnTypes\\AppMakeDynamicReturnTypeExtension'                    => true,
            'Larastan\\Larastan\\ReturnTypes\\ContainerArrayAccessDynamicMethodReturnTypeExtension' => true,
            'Larastan\\Larastan\\ReturnTypes\\ContainerMakeDynamicReturnTypeExtension'              => true,
            'Larastan\\Larastan\\Methods\\Extension'                                                => true,
        ];

        foreach ($extension['services'] as $index => $service) {
            $class = is_array($service) && isset($service['class']) ? $service['class'] : '';

            if (isset($disabled[$class])) {
                unset($extension['services'][$index]);

                $disabled[$class] = false;
            }
        }

        // Reindex
        $extension['services'] = array_values($extension['services']);

        // Unused?
        $unused = array_keys(array_filter($disabled));

        if ($unused !== []) {
            throw new Exception(
                sprintf(
                    'The following services is unknown: `%s`',
                    implode('`, `', $unused),
                ),
            );
        }

        // Return
        return $extension;
    }

    private static function getRootPath(): DirectoryPath {
        return new DirectoryPath((string) getcwd());
    }

    private static function hasLarastan(): bool {
        return InstalledVersions::isInstalled('larastan/larastan');
    }

    private static function getLarastanPath(): DirectoryPath {
        return self::getPackagePath('larastan/larastan');
    }

    private static function getPackagePath(string $package): DirectoryPath {
        $path = InstalledVersions::getInstallPath($package)
            ?? throw new Exception(sprintf('The `%s` package is not found/installed.', $package));
        $path = new DirectoryPath($path);

        return $path;
    }
}
