<?php

/** @var string $_EXTKEY */
$EM_CONF[$_EXTKEY] = [
    'title' => '+anders und sehr: Redirects Exporter',
    'description' => 'Exports your redirects at a glace',
    'category' => 'service',
    'author' => 'Stefan Lamm',
    'author_email' => 's.lamm@andersundsehr.com',
    'state' => 'stable',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' =>[
        'depends' => [
            'typo3' => '10.00.00',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
