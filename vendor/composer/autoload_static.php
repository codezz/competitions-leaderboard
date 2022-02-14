<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitb2487d401450ed5913aa89173346ab96
{
    public static $prefixLengthsPsr4 = array (
        'C' => 
        array (
            'Carbon_Fields\\' => 14,
            'CLead\\' => 6,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Carbon_Fields\\' => 
        array (
            0 => __DIR__ . '/..' . '/htmlburger/carbon-fields/core',
        ),
        'CLead\\' => 
        array (
            0 => __DIR__ . '/../..' . '/inc',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitb2487d401450ed5913aa89173346ab96::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitb2487d401450ed5913aa89173346ab96::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitb2487d401450ed5913aa89173346ab96::$classMap;

        }, null, ClassLoader::class);
    }
}
