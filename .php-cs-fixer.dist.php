<?php
$config = new \PhpCsFixer\Config();
return $config
    ->setRules([
        '@PSR12' => true,
        '@PHP81Migration' => true,
        'no_unused_imports' => true,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->exclude('vendor')
        ->in(__DIR__)
    )
;