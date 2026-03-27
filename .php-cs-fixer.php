<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        '@PHP81Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'declare_strict_types' => true,
        'void_return' => true,
        'strict_param' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
